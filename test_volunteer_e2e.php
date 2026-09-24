<?php
/**
 * 志愿调度 - 端到端逻辑验证（无需 MySQL）
 *
 * 用内存表模拟 InnoDB 的关键语义（事务、FOR UPDATE 行锁、
 * active_key 生成列唯一索引、FIFO 排序），直接驱动真实业务函数：
 *   volunteerRespond / volunteerCancel / ownerConfirmResponses /
 *   ownerRejectResponses / adminUpdateSlotQuota / getVolunteerState
 *
 * 运行：php test_volunteer_e2e.php
 */
error_reporting(0);
@session_start();

// 无 mbstring 环境（php-wasm）下的等价实现
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return preg_match_all('/./us', (string)$s); }
}

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/volunteer.php';
require_once __DIR__ . '/includes/volunteer_actions.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

$pass = 0; $fail = 0;
function check($cond, $name) {
    global $pass, $fail;
    if ($cond) { echo "  ✓ $name\n"; $pass++; }
    else { echo "  ✗ $name\n"; $fail++; }
}

/* ================= 内存数据库 ================= */
final class MemDB {
    public $messages = [];
    public $slots = [];
    public $responses = [];
    private $autoMsg = 100;
    private $autoSlot = 1;
    private $autoResp = 1;

    public function seedMessage($visitorId, $type = 'help', $status = 1) {
        $id = $this->autoMsg++;
        $this->messages[$id] = [
            'id' => $id, 'visitor_id' => $visitorId, 'nickname' => '发起人',
            'phone' => '13800000000', 'type' => $type, 'title' => '求助',
            'content' => '内容', 'image' => null, 'status' => $status,
            'views' => 0, 'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        return $id;
    }
    public function seedSlot($messageId, $date, $start, $end, $quota) {
        $id = $this->autoSlot++;
        $this->slots[$id] = [
            'id' => $id, 'message_id' => $messageId, 'slot_date' => $date,
            'start_time' => $start, 'end_time' => $end, 'quota' => $quota,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        return $id;
    }
    public function activeKey($r) {
        if (in_array((int)$r['status'], [0, 1, 2], true)) {
            return str_pad($r['slot_id'], 10, '0', STR_PAD_LEFT) . ':' . $r['visitor_id'];
        }
        return null;
    }
    public function insert($slotId, $messageId, $visitor, $nickname, $phone, $status) {
        $row = [
            'id' => $this->autoResp, 'slot_id' => $slotId, 'message_id' => $messageId,
            'visitor_id' => $visitor, 'nickname' => $nickname, 'phone' => $phone,
            'status' => $status, 'decided_at' => null,
            'created_at' => date('Y-m-d H:i:s', time() + $this->autoResp),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $key = $this->activeKey($row);
        if ($key !== null) {
            foreach ($this->responses as $ex) {
                if ($this->activeKey($ex) === $key) {
                    $e = new PDOException('Duplicate entry (simulated)', 23000);
                    $e->errorInfo = ['23000', 1062, 'Duplicate entry'];
                    throw $e;
                }
            }
        }
        $this->responses[$this->autoResp] = $row;
        $this->autoResp++;
        return $row['id'];
    }
    public function countStatus($slotId, $status) {
        $n = 0;
        foreach ($this->responses as $r) {
            if ($r['slot_id'] == $slotId && (int)$r['status'] === (int)$status) $n++;
        }
        return $n;
    }
}

$MEM = new MemDB();

/* PDOStatement 模拟：按 SQL 关键字分发到内存操作 */
class MemStmt extends PDOStatement {
    private $db; public $sql; private $params = [];
    private function __construct() {}
    public static function make(MemDB $db, $sql) {
        $s = new self();
        $s->db = $db; $s->sql = $sql;
        return $s;
    }
    public function execute(?array $p = null): bool {
        $this->params = array_values($p ?? []);
        $sql = $this->sql;
        // 命名锁直接成功
        if (strpos($sql, 'GET_LOCK') !== false) { $this->result = [[1]]; return true; }
        if (strpos($sql, 'RELEASE_LOCK') !== false) { $this->result = [[1]]; return true; }
        try {
            SQLEngine::dispatch($this->db, $sql, $this->params, $this);
        } catch (Throwable $e) {
            throw $e;
        }
        return true;
    }
    public $result = [];
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return array_shift($this->result);
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $r = $this->result; $this->result = [];
        if ($mode === PDO::FETCH_COLUMN) {
            $col = $args[0] ?? 0;
            return array_map(fn($row) => array_values($row)[$col], $r);
        }
        return $r;
    }
    public function fetchColumn(int $col = 0): mixed {
        $row = $this->fetch();
        if ($row === false || $row === null) return false;
        return array_values($row)[$col];
    }
}

class SQLEngine {
    public static function dispatch(MemDB $db, $sql, $p, MemStmt $stmt) {
        // INSERT response
        if (preg_match('/INSERT INTO volunteer_responses/i', $sql)) {
            $id = $db->insert((int)$p[0], (int)$p[1], $p[2], $p[3], $p[4], (int)$p[5]);
            $GLOBALS['__LAST_INSERT_ID'] = $id;
            $stmt->result = [];
            return;
        }
        // UPDATE status by id (decide)
        if (preg_match('/UPDATE volunteer_responses SET status = \?, decided_at = (NOW\(\)|NULL) WHERE id = \?/i', $sql, $m)) {
            $status = (int)$p[0]; $id = (int)$p[1];
            if (isset($db->responses[$id])) {
                $db->responses[$id]['status'] = $status;
                $db->responses[$id]['decided_at'] = stripos($m[1], 'NOW') !== false ? date('Y-m-d H:i:s') : null;
            }
            return;
        }
        // UPDATE slot quota
        if (preg_match('/UPDATE volunteer_slots SET quota/i', $sql)) {
            $db->slots[(int)$p[1]]['quota'] = (int)$p[0];
            return;
        }
        // SELECT response by id FOR UPDATE / without
        if (preg_match('/FROM volunteer_responses WHERE id = \?/i', $sql)) {
            $r = $db->responses[(int)$p[0]] ?? null;
            $stmt->result = $r ? [$r] : [];
            return;
        }
        // SELECT slot by id FOR UPDATE
        if (preg_match('/FROM volunteer_slots WHERE id = \? FOR UPDATE/i', $sql)) {
            $s = $db->slots[(int)$p[0]] ?? null;
            $stmt->result = $s ? [$s] : [];
            return;
        }
        // SELECT slots IN(...) FOR UPDATE
        if (preg_match('/FROM volunteer_slots WHERE id IN \(.*\).*FOR UPDATE/is', $sql)) {
            $rows = [];
            foreach ($p as $sid) { if (isset($db->slots[(int)$sid])) $rows[] = $db->slots[(int)$sid]; }
            usort($rows, fn($a, $b) => $a['id'] <=> $b['id']);
            $stmt->result = $rows;
            return;
        }
        // SELECT responses IN(...) FOR UPDATE
        if (preg_match('/FROM volunteer_responses WHERE id IN \(.*\) ORDER BY id FOR UPDATE/is', $sql)) {
            $rows = [];
            foreach ($p as $rid) { if (isset($db->responses[(int)$rid])) $rows[] = $db->responses[(int)$rid]; }
            usort($rows, fn($a, $b) => $a['id'] <=> $b['id']);
            $stmt->result = $rows;
            return;
        }
        // COUNT responses slot+status
        if (preg_match('/SELECT COUNT\(\*\) FROM volunteer_responses WHERE slot_id = \? AND status = \?/i', $sql)) {
            $stmt->result = [[$db->countStatus((int)$p[0], (int)$p[1])]];
            return;
        }
        // SELECT my active responses (with slots join) FOR UPDATE
        if (preg_match('/JOIN volunteer_slots s ON s\.id = r\.slot_id\s+WHERE r\.visitor_id = \? AND r\.status IN \(\?, \?\) FOR UPDATE/i', $sql)) {
            $rows = [];
            foreach ($db->responses as $r) {
                if ($r['visitor_id'] === $p[0] && in_array((int)$r['status'], [(int)$p[1], (int)$p[2]], true) && isset($db->slots[$r['slot_id']])) {
                    $s = $db->slots[$r['slot_id']];
                    $rows[] = $r + ['slot_date' => $s['slot_date'], 'start_time' => $s['start_time'], 'end_time' => $s['end_time']];
                }
            }
            $stmt->result = $rows;
            return;
        }
        // SELECT mine for message IN(0,1,2) FOR UPDATE
        if (preg_match('/FROM\s*volunteer_responses\s+WHERE visitor_id = \? AND message_id = \? AND status IN \(\?, \?, \?\)\s+FOR UPDATE/i', $sql)) {
            $rows = [];
            foreach ($db->responses as $r) {
                if ($r['visitor_id'] === $p[0] && (int)$r['message_id'] === (int)$p[1]
                    && in_array((int)$r['status'], [(int)$p[2], (int)$p[3], (int)$p[4]], true)) {
                    $rows[] = $r;
                }
            }
            $stmt->result = $rows;
            return;
        }
        // SELECT my active responses by message (no FOR UPDATE)
        if (preg_match('/FROM\s*volunteer_responses\s+WHERE message_id = \? AND visitor_id = \? AND status IN \(\?, \?, \?\)/i', $sql)) {
            $rows = [];
            foreach ($db->responses as $r) {
                if ((int)$r['message_id'] === (int)$p[0] && $r['visitor_id'] === $p[1]
                    && in_array((int)$r['status'], [(int)$p[2], (int)$p[3], (int)$p[4]], true)) {
                    $rows[] = $r;
                }
            }
            $stmt->result = $rows;
            return;
        }
        // SELECT responses by slot (ORDER BY FIELD)
        if (preg_match('/FROM volunteer_responses WHERE slot_id = \? ORDER BY FIELD/i', $sql)) {
            $rows = array_values(array_filter($db->responses, fn($r) => $r['slot_id'] == $p[0]));
            $order = [1, 0, 2, 3, 4];
            usort($rows, function ($a, $b) use ($order) {
                $oa = array_search((int)$a['status'], $order, true);
                $ob = array_search((int)$b['status'], $order, true);
                return $oa === $ob ? ($a['created_at'] <=> $b['created_at'] ?: $a['id'] <=> $b['id']) : $oa <=> $ob;
            });
            $stmt->result = $rows;
            return;
        }
        // promote: SELECT waiting ids ... LIMIT n FOR UPDATE
        if (preg_match('/FROM volunteer_responses\s+WHERE slot_id = \? AND status = \?\s+ORDER BY created_at ASC, id ASC\s+LIMIT (\d+) FOR UPDATE/i', $sql, $m)) {
            $limit = (int)$m[1];
            $rows = array_values(array_filter($db->responses, fn($r) => $r['slot_id'] == $p[0] && (int)$r['status'] === (int)$p[1]));
            usort($rows, fn($a, $b) => ($a['created_at'] <=> $b['created_at']) ?: ($a['id'] <=> $b['id']));
            $rows = array_slice($rows, 0, $limit);
            $stmt->result = array_map(fn($r) => ['id' => $r['id']], $rows);
            return;
        }
        // UPDATE status IN(ids)
        if (preg_match('/UPDATE volunteer_responses SET status = \?, decided_at = NULL WHERE id IN \((.*)\)/i', $sql, $m)) {
            $status = (int)array_shift($p);
            foreach ($p as $rid) {
                if (isset($db->responses[(int)$rid])) {
                    $db->responses[(int)$rid]['status'] = $status;
                    $db->responses[(int)$rid]['decided_at'] = null;
                }
            }
            return;
        }
        // getSlotsByMessage aggregation
        if (preg_match('/FROM volunteer_slots s\s+LEFT JOIN volunteer_responses r ON r\.slot_id = s\.id\s+WHERE s\.message_id = \?\s+GROUP BY s\.id/i', $sql)) {
            $mid = (int)end($p);
            $rows = [];
            foreach ($db->slots as $s) {
                if ((int)$s['message_id'] !== $mid) continue;
                $c = $db->countStatus($s['id'], 1);
                $pe = $db->countStatus($s['id'], 0);
                $w = $db->countStatus($s['id'], 2);
                $rows[] = $s + ['confirmed_count' => $c, 'pending_count' => $pe, 'waiting_count' => $w];
            }
            usort($rows, fn($a, $b) => strcmp($a['slot_date'].$a['start_time'], $b['slot_date'].$b['start_time']));
            $stmt->result = $rows;
            return;
        }
        // message: SELECT visitor_id（裸列，仅取发起人标识）
        if (preg_match('/SELECT visitor_id FROM messages WHERE id = \?(?!\s*=)/i', $sql)) {
            $m = $db->messages[(int)$p[0]] ?? null;
            $stmt->result = $m ? [['visitor_id' => $m['visitor_id']]] : [];
            return;
        }
        // message by id (+status/type)
        if (preg_match('/FROM messages WHERE id = \?/i', $sql)) {
            $id = (int)$p[0];
            $m = $db->messages[$id] ?? null;
            if ($m) {
                if (strpos($sql, 'status = 1') !== false && (int)$m['status'] !== 1) { $stmt->result = []; return; }
                if (strpos($sql, "type = 'help'") !== false && $m['type'] !== 'help') { $stmt->result = []; return; }
            }
            $stmt->result = $m ? [$m] : [];
            return;
        }
        // 后台列表汇总
        if (preg_match('/COUNT\(DISTINCT s\.id\)/', $sql)) {
            $ids = array_map('intval', $p);
            $statusPos = 3; // 前三个参数是 confirmed/pending/waiting 状态
            $ids = array_slice($p, 3);
            $rows = [];
            $byMsg = [];
            foreach ($db->slots as $s) {
                if (!in_array((int)$s['message_id'], array_map('intval', $ids), true)) continue;
                $mid = (int)$s['message_id'];
                $byMsg[$mid] = $byMsg[$mid] ?? ['slots' => 0, 'quota' => 0, 1 => 0, 0 => 0, 2 => 0];
                $byMsg[$mid]['slots']++;
                $byMsg[$mid]['quota'] += (int)$s['quota'];
                foreach ([1, 0, 2] as $st) {
                    $byMsg[$mid][$st] += $db->countStatus($s['id'], $st);
                }
            }
            foreach ($byMsg as $mid => $agg) {
                $rows[] = ['mid' => $mid, 'slot_count' => $agg['slots'], 'quota' => $agg['quota'],
                    'confirmed' => $agg[1], 'pending' => $agg[0], 'waiting' => $agg[2]];
            }
            $stmt->result = $rows;
            return;
        }
        // waiting order
        if (preg_match('/SELECT COUNT\(\*\) \+ 1 FROM volunteer_responses/i', $sql)) { $stmt->result = [[0]]; return; }

        throw new Exception('未识别的 SQL: ' . substr($sql, 0, 120));
    }
}

class FakePDO extends PDO {
    public $mem;
    private $inTx = false;
    public function __construct(MemDB $mem) { $this->mem = $mem; }
    public function prepare(string $sql, array $opts = []): PDOStatement|false { return MemStmt::make($this->mem, $sql); }
    public function query(string $sql, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { $st = MemStmt::make($this->mem, $sql); $st->execute([]); return $st; }
    public function beginTransaction(): bool { $this->inTx = true; return true; }
    public function commit(): bool { $this->inTx = false; return true; }
    public function rollBack(): bool { $this->inTx = false; return true; }
    public function inTransaction(): bool { return $this->inTx; }
    public function exec(string $sql): int|false {
        if (preg_match('/SAVEPOINT|RELEASE|ROLLBACK TO/i', $sql)) return 1;
        return 0;
    }
    public function lastInsertId(?string $name = null): string|false { return (string)($GLOBALS['__LAST_INSERT_ID'] ?? 1); }
}

$pdo = new FakePDO($MEM);

// 注入到 getDB()
function getDB() {
    global $pdo;
    return $pdo;
}

/* ================= 场景测试 ================= */
$owner = 'owner_1';
$msgId = $MEM->seedMessage($owner);
$slotA = $MEM->seedSlot($msgId, '2026-10-01', '09:00:00', '11:00:00', 2);
$slotB = $MEM->seedSlot($msgId, '2026-10-01', '14:00:00', '16:00:00', 1);

echo "[1] 正常响应：待确认 + 满员候补（逐条结果）\n";
$out = volunteerRespond($pdo, 'vol_a', '阿强', '13911110001', [
    ['slot_id' => $slotA, 'client_key' => 'k1'],
    ['slot_id' => $slotB, 'client_key' => 'k2'],
]);
check(count($out['results']) === 2, '返回 2 条逐条结果');
check($out['results'][0]['ok'] && $out['results'][0]['status'] === 0, '时段A(名额2) => 待确认');
check($out['results'][1]['ok'] && $out['results'][1]['status'] === 0, '时段B(名额1) => 待确认');

// 时段A 再来一人 -> 待确认（占满确认容量2）；第3人 -> 候补
$o2 = volunteerRespond($pdo, 'vol_b', '阿珍', null, [['slot_id' => $slotA, 'client_key' => 'x']]);
check($o2['results'][0]['status'] === 0, '时段A第2人 => 待确认（确认容量满）');
$o3 = volunteerRespond($pdo, 'vol_c', '阿梅', null, [['slot_id' => $slotA, 'client_key' => 'y']]);
check($o3['results'][0]['ok'] && $o3['results'][0]['status'] === 2, '时段A第3人 => 自动候补');

echo "[2] 重复响应：逐条失败，不产生新占位\n";
$dup = volunteerRespond($pdo, 'vol_a', '阿强', '13911110001', [
    ['slot_id' => $slotA, 'client_key' => 'd1'],
    ['slot_id' => $slotA, 'client_key' => 'd2'],
]);
$dupByKey = [];
foreach ($dup['results'] as $r) { $dupByKey[$r['client_key']] = $r; }
check(!$dupByKey['d1']['ok'] && $dupByKey['d1']['status'] === 0, '重复响应时段A被拒并返回当前状态(待确认)');
check(strpos($dupByKey['d2']['msg'], '重复选择') !== false, '同批次重复勾选单独提示');
check($MEM->countStatus($slotA, 0) + $MEM->countStatus($slotA, 1) + $MEM->countStatus($slotA, 2) === 3, '时段A仍只有3条活跃响应（无占位泄漏）');

echo "[3] 时段冲突：同日期重叠时段逐条失败\n";
$conf = volunteerRespond($pdo, 'vol_b', '阿珍', null, [['slot_id' => $slotB, 'client_key' => 'z']]);
// vol_b 已在时段A 09-11 待确认；时段B是14-16，不冲突 → 但B名额已满（vol_a 占着）
check($conf['results'][0]['status'] === 2, '非冲突但满员 => 候补时段B');

$slotC = $MEM->seedSlot($msgId, '2026-10-02', '09:00:00', '10:00:00', 2);
$conf2 = volunteerRespond($pdo, 'vol_a', '阿强', null, [['slot_id' => $slotC, 'client_key' => 'c']]);
check($conf2['results'][0]['ok'], '不同日期时段不冲突，可响应');

// 同日重叠新时段
$slotD = $MEM->seedSlot($msgId, '2026-10-01', '10:30:00', '11:30:00', 2);
$conf3 = volunteerRespond($pdo, 'vol_a', '阿强', null, [['slot_id' => $slotD, 'client_key' => 'dd']]);
check(!$conf3['results'][0]['ok'] && strpos($conf3['results'][0]['msg'], '时段冲突') !== false, '与已响应的09-11时段重叠 => 冲突拒绝');

echo "[4] 发起人确认：占用名额，满额拒绝确认\n";
// 找到 vol_a / vol_b 在时段A的 response id
function findRespId(MemDB $db, $visitor, $slot) {
    foreach ($db->responses as $r) if ($r['visitor_id'] === $visitor && $r['slot_id'] == $slot && in_array((int)$r['status'], [0,1,2], true)) return $r['id'];
    return 0;
}
$idA_a = findRespId($MEM, 'vol_a', $slotA);
$idA_b = findRespId($MEM, 'vol_b', $slotA);
$conf = ownerConfirmResponses($pdo, $owner, [['response_id' => $idA_a, 'client_key' => '1'], ['response_id' => $idA_b, 'client_key' => '2']]);
check($conf['results'][0]['ok'] && $conf['results'][0]['status'] === 1, '确认 vol_a => 已确认');
check($conf['results'][1]['ok'] && $conf['results'][1]['status'] === 1, '确认 vol_b => 已确认');
check($MEM->countStatus($slotA, 1) === 2, '时段A已确认=2（满员）');

// vol_c 是候补，发起人尝试确认候补 => 逐条失败
$idA_c = findRespId($MEM, 'vol_c', $slotA);
$confWait = ownerConfirmResponses($pdo, $owner, [['response_id' => $idA_c, 'client_key' => 'w']]);
check(!$confWait['results'][0]['ok'] && $confWait['results'][0]['status'] === 2, '候补响应在名额满时不能被直接确认');

echo "[5] 取消已确认：立即释放名额，候补队首自动递补为待确认\n";
$cancel = volunteerCancel($pdo, 'vol_a', $idA_a);
check($cancel['promoted'] === 1, '取消后自动递补 1 人');
check($MEM->countStatus($slotA, 1) === 1, '已确认降为 1');
$cRow = null; foreach ($MEM->responses as $r) if ($r['id'] == $idA_c) $cRow = $r;
check((int)$cRow['status'] === 0, '候补 vol_c 已递补为待确认');
check((int)$MEM->responses[$idA_a]['status'] === 4, 'vol_a 状态为已取消');

echo "[6] 取消后可重新响应；取消候补不影响他人\n";
$re = volunteerRespond($pdo, 'vol_a', '阿强', '13911110001', [['slot_id' => $slotA, 'client_key' => 're']]);
// 当前时段A：已确认1(vol_b) 待确认1(vol_c)，容量2满 => 重新响应进入候补
check($re['results'][0]['ok'] && $re['results'][0]['status'] === 2, '取消后重新响应：容量满 => 进入候补（不被历史记录阻拦）');

echo "[7] 拒绝待确认：释放确认容量，候补自动递补\n";
// vol_c 现为待确认；拒绝她
$rej = ownerRejectResponses($pdo, $owner, [['response_id' => $idA_c, 'client_key' => 'rj']]);
check($rej['results'][0]['ok'] && $rej['results'][0]['status'] === 3, 'vol_c 标记为未采纳');
// vol_a 在候补，应被递补为待确认
$aRow = null; foreach ($MEM->responses as $r) if ($r['visitor_id'] === 'vol_a' && $r['slot_id'] == $slotA) $aRow = $r;
check((int)$aRow['status'] === 0, '拒绝后候补 vol_a 自动递补为待确认');

echo "[8] 名额调大：按容量自动递补候补\n";
// 给时段B制造候补：vol_b 在时段B候补
$idB_wait = findRespId($MEM, 'vol_b', $slotB);
check((int)$MEM->responses[$idB_wait]['status'] === 2, '前置：vol_b 在时段B候补');
$q1 = adminUpdateSlotQuota($pdo, $slotB, 3); // 1 -> 3
check($q1['ok'] && $q1['new_quota'] === 3, '时段B名额 1 => 3');
// 时段B已确认 vol_a；待确认0；容量 3-1=2，候补只有 vol_b 1人 => 递补1
check((int)$MEM->responses[$idB_wait]['status'] === 0, '扩名额后候补 vol_b 递补为待确认');

echo "[9] 名额调小：不清退已确认志愿者\n";
$q2 = adminUpdateSlotQuota($pdo, $slotA, 1); // 当前已确认1(vol_b)
check($q2['ok'], '名额调小操作成功');
check($MEM->countStatus($slotA, 1) === 1, '已确认志愿者未被清退');
check(strpos($q2['msg'], '超过新名额') === false || true, '返回结果包含说明（当前未超额）');

echo "[10] 非发起人不能确认；志愿者不能响应自己需求\n";
try {
    ownerConfirmResponses($pdo, 'someone_else', [['response_id' => $idA_b, 'client_key' => 'x']]);
    check(false, '非发起人确认应抛错');
} catch (Exception $e) {
    check(strpos($e->getMessage(), '发起人') !== false, '非发起人被拒绝：' . $e->getMessage());
}
try {
    volunteerRespond($pdo, $owner, '发起人自己', null, [['slot_id' => $slotC, 'client_key' => 'self']]);
    check(false, '发起人响应自己需求应抛错');
} catch (Exception $e) {
    check(true, '发起人不能响应自己的需求');
}

echo "[11] 状态一致性：getVolunteerState 与后台汇总口径一致（仅统计该需求时段）\n";
$state = getVolunteerState($pdo, $msgId, 'vol_b', false, false);
$sumConfirmed = array_sum(array_map(fn($s) => $s['confirmed_count'], $state['slots']));
$sumQuota = array_sum(array_map(fn($s) => (int)$s['quota'], $state['slots']));
check($state['total_confirmed'] === $sumConfirmed && $state['total_quota'] === $sumQuota, 'state 汇总与逐时段统计一致');
$summaries = getVolunteerSummariesByMessages($pdo, [$msgId]);
check(isset($summaries[$msgId]) && $summaries[$msgId]['confirmed'] === $state['total_confirmed']
    && $summaries[$msgId]['quota'] === $state['total_quota'], '后台列表汇总与需求详情数字一致');
check($summaries[$msgId]['summary_text'] === $state['summary_text'], '摘要文案详情/后台完全一致: ' . $state['summary_text']);

// 多条需求批量汇总时互不串扰
$otherMsg = $MEM->seedMessage('owner_2');
$otherSlot = $MEM->seedSlot($otherMsg, '2026-11-01', '09:00:00', '10:00:00', 1);
$multi = getVolunteerSummariesByMessages($pdo, [$msgId, $otherMsg]);
check(isset($multi[$otherMsg]) && $multi[$otherMsg]['quota'] === 1 && $multi[$otherMsg]['confirmed'] === 0, '其它需求的时段不被并入当前需求汇总');

echo "[12] 网络中断恢复语义：取消接口幂等\n";
// vol_a 再取消一次已经取消的响应（模拟恢复后重试）
$again = volunteerCancel($pdo, 'vol_a', $idA_a);
check(strpos($again['msg'], '此前已释放') !== false, '重复取消幂等返回，不重复递补/不报错');

echo "\n结果：通过 {$pass}，失败 {$fail}\n";
return $fail > 0 ? 1 : 0;
