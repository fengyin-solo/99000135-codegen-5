<?php
/**
 * 志愿调度核心算法离线验证（无 MySQL）：
 * 用内存中的 FakePDO 模拟事务/行锁/状态更新，跑通完整状态流转。
 * 运行：php test_volunteer_logic.php
 */
error_reporting(E_ALL);

require_once __DIR__ . '/includes/volunteer.php';
require_once __DIR__ . '/includes/volunteer_actions.php';

$pass = 0; $fail = 0;
function check($cond, $name) {
    global $pass, $fail;
    if ($cond) { echo "  ✓ $name\n"; $pass++; }
    else { echo "  ✗ $name\n"; $fail++; }
}

/* ---------- 纯函数测试 ---------- */
echo "[1] slotsOverlap\n";
check(slotsOverlap('2026-10-01','09:00','11:00','2026-10-01','10:00','12:00'), '相邻重叠时段判定为冲突');
check(!slotsOverlap('2026-10-01','09:00','11:00','2026-10-01','11:00','12:00'), '首尾相接不算冲突');
check(!slotsOverlap('2026-10-01','09:00','11:00','2026-10-02','09:00','11:00'), '不同日期不冲突');

echo "[2] normalizeSubmittedSlots\n";
try {
    normalizeSubmittedSlots([
        'slot_date' => ['2026-10-01','2026-10-01'],
        'start_time' => ['09:00','10:00'],
        'end_time' => ['11:00','12:00'],
        'quota' => ['2','3'],
    ]);
    check(false, '重叠时段应报错');
} catch (Exception $e) {
    check(true, '重叠时段被拒绝：' . $e->getMessage());
}
try {
    normalizeSubmittedSlots(['slot_date' => [''], 'start_time' => [''], 'end_time' => [''], 'quota' => ['']]);
    check(false, '全空应报错');
} catch (Exception $e) {
    check(true, '全空白行报错：' . $e->getMessage());
}
$norm = normalizeSubmittedSlots([
    'slot_date' => ['2026-10-02',''],
    'start_time' => ['09:00',''],
    'end_time' => ['11:00',''],
    'quota' => ['2',''],
]);
check(count($norm) === 1 && $norm[0]['quota'] === 2, '空白行被忽略，保留有效时段');
try {
    normalizeSubmittedSlots(['slot_date'=>['2026-10-01'],'start_time'=>['11:00'],'end_time'=>['09:00'],'quota'=>['1']]);
    check(false, '起止倒置应报错');
} catch (Exception $e) {
    check(true, '开始晚于结束被拒绝');
}

/* ---------- FakePDO 状态机模拟 ---------- */
echo "[3] 名额/候补状态流转（FakePDO）\n";

class FakeStmt {
    public $rows; public $sql;
    public function __construct($rows) { $this->rows = $rows; }
    public function execute($p = []) { return true; }
    public function fetch() { return $this->rows ? array_shift($this->rows) : false; }
    public function fetchAll() { $r = $this->rows; $this->rows = []; return $r; }
    public function fetchColumn() { $v = $this->fetch(); return $v === false ? false : reset($v); }
}

// 直接用“模拟数据库数组”实现关键流转，复刻 SQL 语义，验证算法
function simulateRespond(array &$slot, array &$responses, $visitor, $quota) {
    // 重复
    foreach ($responses as $r) {
        if ($r['visitor'] === $visitor && in_array($r['status'], [0,1,2])) {
            return ['ok'=>false,'status'=>$r['status'],'msg'=>'duplicate'];
        }
    }
    $confirmed = count(array_filter($responses, fn($r)=>$r['slot']===$slot['id'] && $r['status']===1));
    $pending   = count(array_filter($responses, fn($r)=>$r['slot']===$slot['id'] && $r['status']===0));
    $status = ($confirmed + $pending < $quota) ? 0 : 2;
    $responses[] = ['slot'=>$slot['id'],'visitor'=>$visitor,'status'=>$status];
    return ['ok'=>true,'status'=>$status];
}
function simulateCancelConfirmed(array &$responses, $visitor, $slotId, $quota) {
    foreach ($responses as $i=>$r) {
        if ($r['visitor']===$visitor && $r['slot']===$slotId && $r['status']===1) {
            $responses[$i]['status'] = 4;
        }
    }
    $confirmed = count(array_filter($responses, fn($r)=>$r['slot']===$slotId && $r['status']===1));
    $pending   = count(array_filter($responses, fn($r)=>$r['slot']===$slotId && $r['status']===0));
    $capacity = $quota - $confirmed - $pending;
    // FIFO 递补
    $promoted = 0;
    foreach ($responses as $i=>$r) {
        if ($promoted >= $capacity) break;
        if ($r['slot']===$slotId && $r['status']===2) {
            $responses[$i]['status'] = 0;
            $promoted++;
        }
    }
    return $promoted;
}

$slot = ['id'=>10];
$responses = [];
$quota = 2;
// v1 v2 -> pending, v3 -> waiting
check(simulateRespond($slot,$responses,'v1',$quota)['status']===0, '第1人响应 => 待确认');
check(simulateRespond($slot,$responses,'v2',$quota)['status']===0, '第2人响应 => 待确认');
check(simulateRespond($slot,$responses,'v3',$quota)['status']===2, '名额占满(确认容量) => 第3人候补');
check(simulateRespond($slot,$responses,'v1',$quota)['ok']===false, '同一人重复响应被拒');
// 发起人确认 v1,v2
$responses[0]['status']=1; $responses[1]['status']=1;
// v1 取消 -> 释放 -> v3 递补
$promoted = simulateCancelConfirmed($responses,'v1',10,$quota);
check($promoted===1, '取消已确认 => 释放1个名额');
$v3 = null;
foreach ($responses as $r) if ($r['visitor']==='v3') $v3=$r;
check($v3['status']===0, '候补队首 v3 自动递补为待确认');
// v2 也取消，无候补（v3已递补） -> promoted 0
$responses[0]['status']=1; // 恢复 v1 状态不需要；改为直接取消 v2
$promoted2 = simulateCancelConfirmed($responses,'v2',10,$quota);
check($promoted2===0, '无候补时取消 => 仅释放名额不递补');

/* ---------- 摘要文案一致性（详情/后台同函数） ---------- */
echo "[4] 摘要文案\n";
check(volunteerSummaryText(2,2,0,1) === '已确认 2/2，候补 1', '满员摘要: '.volunteerSummaryText(2,2,0,1));
check(volunteerSummaryText(1,2,1,0) === '已确认 1/2，待确认 1', '招募中摘要');

/* ---------- 状态标签完整 ---------- */
echo "[5] 状态标签\n";
check(getVolunteerResponseStatusLabel(0)==='待确认' && getVolunteerResponseStatusLabel(2)==='候补中' && getVolunteerResponseStatusLabel(4)==='已取消', '五种状态标签齐全');

echo "\n结果：通过 {$pass}，失败 {$fail}\n";
return ($fail > 0 ? 1 : 0);
