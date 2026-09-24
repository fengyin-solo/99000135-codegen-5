<?php
/**
 * 志愿响应调度 - 核心业务逻辑
 *
 * 状态机：
 *   0 待确认  —— 志愿者已响应，等待发起人确认（不占用名额）
 *   1 已确认  —— 发起人已确认（占用名额）
 *   2 候补中  —— 确认名额已满，自动进入候补
 *   3 未采纳  —— 发起人拒绝
 *   4 已取消  —— 志愿者主动取消
 *
 * 名额流转（均在事务内对时段行加 FOR UPDATE 锁）：
 *   响应：已确认+待确认 < 名额 => 0待确认；否则 => 2候补
 *   确认：名额未满 => 1已确认；已满 => 保持 0待确认
 *   取消已确认 => 立即释放名额，候补队首(FIFO)按确认容量自动递补为 0待确认
 *   拒绝待确认 => 释放确认容量，候补按容量自动递补
 *   名额调大 => 按新增确认容量自动递补；名额调小 => 不踢人，仅状态保持
 *
 * 所有展示（需求详情、后台列表）统一通过本文件的函数计算，保证口径一致。
 */

// ---- 响应状态常量 ----
const VR_PENDING   = 0; // 待确认
const VR_CONFIRMED = 1; // 已确认
const VR_WAITING   = 2; // 候补中
const VR_REJECTED  = 3; // 未采纳
const VR_CANCELED  = 4; // 已取消

function getVolunteerResponseStatusLabel($status) {
    $map = [
        VR_PENDING   => '待确认',
        VR_CONFIRMED => '已确认',
        VR_WAITING   => '候补中',
        VR_REJECTED  => '未采纳',
        VR_CANCELED  => '已取消',
    ];
    return $map[$status] ?? '未知';
}

function getVolunteerResponseStatusClass($status) {
    $map = [
        VR_PENDING   => 'vr-pending',
        VR_CONFIRMED => 'vr-confirmed',
        VR_WAITING   => 'vr-waiting',
        VR_REJECTED  => 'vr-rejected',
        VR_CANCELED  => 'vr-canceled',
    ];
    return $map[$status] ?? '';
}

/**
 * 两个服务时段是否时间重叠（含跨时段冲突判断）
 */
function slotsOverlap($d1, $s1, $e1, $d2, $s2, $e2) {
    if ($d1 !== $d2) return false;
    return $s1 < $e2 && $s2 < $e1;
}

/**
 * 校验并规范化提交的服务时段
 * 输入格式：slot_date[]/start_time[]/end_time[]/quota[]（键对齐的数组）
 * 返回 [['date'=>'Y-m-d','start'=>'HH:MM','end'=>'HH:MM','quota'=>int], ...]
 * 校验失败抛出 Exception
 */
function normalizeSubmittedSlots($post) {
    $dates  = $post['slot_date'] ?? [];
    $starts = $post['start_time'] ?? [];
    $ends   = $post['end_time'] ?? [];
    $quotas = $post['quota'] ?? [];

    if (!is_array($dates) || empty($dates)) {
        throw new Exception('请至少添加一个服务时段');
    }
    if (count($dates) > 10) {
        throw new Exception('最多发布 10 个服务时段');
    }

    $slots = [];
    foreach ($dates as $i => $date) {
        $date  = trim($date ?? '');
        $start = trim($starts[$i] ?? '');
        $end   = trim($ends[$i] ?? '');
        $quotaRaw = trim((string)($quotas[$i] ?? ''));
        $quota = $quotaRaw === '' ? 0 : (int)$quotaRaw;

        // 日期/起止时间全空的行视为空白行忽略（名额字段不参与判定）
        if ($date === '' && $start === '' && $end === '') continue; // 跳过完全空白行

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
            !preg_match('/^\d{2}:\d{2}$/', $start) ||
            !preg_match('/^\d{2}:\d{2}$/', $end)) {
            throw new Exception('存在不完整的服务时段');
        }
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new Exception('服务日期不合法');
        }
        if ($start >= $end) {
            throw new Exception('服务时段的开始时间必须早于结束时间');
        }
        if ($quota < 1 || $quota > 999) {
            throw new Exception('每个时段名额需在 1-999 之间');
        }
        $slots[] = ['date' => $date, 'start' => $start, 'end' => $end, 'quota' => $quota];
    }

    if (empty($slots)) {
        throw new Exception('请至少添加一个服务时段');
    }

    // 同一求助内时段不可互相重叠
    for ($i = 0; $i < count($slots); $i++) {
        for ($j = $i + 1; $j < count($slots); $j++) {
            if (slotsOverlap($slots[$i]['date'], $slots[$i]['start'], $slots[$i]['end'],
                             $slots[$j]['date'], $slots[$j]['start'], $slots[$j]['end'])) {
                throw new Exception('服务时段之间存在时间冲突，请调整');
            }
        }
    }

    // 按时间排序
    usort($slots, function ($a, $b) {
        return strcmp($a['date'] . $a['start'], $b['date'] . $b['start']);
    });

    return $slots;
}

/**
 * 为某条求助写入服务时段（与留言插入处于同一事务）
 */
function insertSlots(PDO $db, $messageId, array $slots) {
    $stmt = $db->prepare(
        "INSERT INTO volunteer_slots (message_id, slot_date, start_time, end_time, quota) VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($slots as $s) {
        $stmt->execute([$messageId, $s['date'], $s['start'] . ':00', $s['end'] . ':00', $s['quota']]);
    }
}

/**
 * 取某条求助的全部时段（含实时统计）
 * 统计口径与页面展示、后台列表完全一致：
 *   confirmed_count 已确认（占用名额）
 *   waiting_count   候补中
 *   pending_count   待确认
 */
function getSlotsByMessage(PDO $db, $messageId) {
    $stmt = $db->prepare(
        "SELECT s.*,
                SUM(CASE WHEN r.status = ? THEN 1 ELSE 0 END) AS confirmed_count,
                SUM(CASE WHEN r.status = ? THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN r.status = ? THEN 1 ELSE 0 END) AS waiting_count
         FROM volunteer_slots s
         LEFT JOIN volunteer_responses r ON r.slot_id = s.id
         WHERE s.message_id = ?
         GROUP BY s.id
         ORDER BY s.slot_date, s.start_time"
    );
    $stmt->execute([VR_CONFIRMED, VR_PENDING, VR_WAITING, $messageId]);
    $slots = $stmt->fetchAll();
    foreach ($slots as &$s) {
        $s['confirmed_count'] = (int)$s['confirmed_count'];
        $s['pending_count']   = (int)$s['pending_count'];
        $s['waiting_count']   = (int)$s['waiting_count'];
        $s['remaining']       = max(0, (int)$s['quota'] - $s['confirmed_count']);
        $s['full']            = $s['confirmed_count'] >= (int)$s['quota'];
    }
    unset($s);
    return $slots;
}

/**
 * 查询某访客在某条求助下各时段的活跃响应（待确认/已确认/候补）
 * 返回 slot_id => response 行
 */
function getMyActiveResponsesByMessage(PDO $db, $messageId, $visitorId) {
    $stmt = $db->prepare(
        "SELECT * FROM volunteer_responses
         WHERE message_id = ? AND visitor_id = ? AND status IN (?, ?, ?)"
    );
    $stmt->execute([$messageId, $visitorId, VR_PENDING, VR_CONFIRMED, VR_WAITING]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[$r['slot_id']] = $r;
    }
    return $map;
}

/**
 * 查询某访客全部活跃响应（用于时段冲突校验，跨求助）
 */
function getMyActiveResponsesAll(PDO $db, $visitorId) {
    $stmt = $db->prepare(
        "SELECT r.*, s.slot_date, s.start_time, s.end_time
         FROM volunteer_responses r
         JOIN volunteer_slots s ON s.id = r.slot_id
         WHERE r.visitor_id = ? AND r.status IN (?, ?)
         ORDER BY s.slot_date, s.start_time"
    );
    $stmt->execute([$visitorId, VR_PENDING, VR_CONFIRMED]);
    return $stmt->fetchAll();
}

/**
 * 取某时段的响应明细列表
 * @param int|null $status 可选状态过滤
 */
function getResponsesBySlot(PDO $db, $slotId, $status = null) {
    $sql = "SELECT * FROM volunteer_responses WHERE slot_id = ?";
    $params = [$slotId];
    if ($status !== null) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY FIELD(status, ?, ?, ?, ?, ?), created_at ASC, id ASC";
    array_push($params, VR_CONFIRMED, VR_PENDING, VR_WAITING, VR_REJECTED, VR_CANCELED);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * 组装某条求助的完整调度状态（详情页与后台共用同一数据口径）
 *
 * @param bool $asOwner  是否为发起人本人（可看待确认清单及联系方式）
 * @param bool $asAdmin  是否为管理员（可看全部明细）
 * @return array|null 无时段返回 null
 */
function getVolunteerState(PDO $db, $messageId, $visitorId = '', $asOwner = false, $asAdmin = false) {
    $slots = getSlotsByMessage($db, $messageId);
    if (empty($slots)) return null;

    $myMap = $visitorId ? getMyActiveResponsesByMessage($db, $messageId, $visitorId) : [];

    $totalQuota = 0;
    $totalConfirmed = 0;
    $totalWaiting = 0;
    $totalPending = 0;

    $includeDetails = $asOwner || $asAdmin;

    foreach ($slots as &$s) {
        $totalQuota     += (int)$s['quota'];
        $totalConfirmed += $s['confirmed_count'];
        $totalWaiting   += $s['waiting_count'];
        $totalPending   += $s['pending_count'];

        $s['my_response'] = $myMap[$s['id']] ?? null;

        if ($includeDetails) {
            $s['responses'] = getResponsesBySlot($db, $s['id']);
            // 非发起人（管理员视角）也展示联系方式，方便后台调度
        }
    }
    unset($s);

    return [
        'slots'            => $slots,
        'is_owner'         => $asOwner,
        'is_admin'         => $asAdmin,
        'total_quota'      => $totalQuota,
        'total_confirmed'  => $totalConfirmed,
        'total_pending'    => $totalPending,
        'total_waiting'    => $totalWaiting,
        'full'             => $totalConfirmed >= $totalQuota,
        'summary_text'     => volunteerSummaryText($totalConfirmed, $totalQuota, $totalPending, $totalWaiting),
    ];
}

/**
 * 统一的调度摘要文案（详情页/后台列表共用）
 */
function volunteerSummaryText($confirmed, $quota, $pending, $waiting) {
    $text = "已确认 {$confirmed}/{$quota}";
    if ($pending > 0) $text .= "，待确认 {$pending}";
    if ($waiting > 0) $text .= "，候补 {$waiting}";
    return $text;
}

/**
 * 时段展示文案：2026-09-24(周三) 09:00-11:00
 */
function formatSlotLabel($s) {
    $week = ['日', '一', '二', '三', '四', '五', '六'];
    $w = $week[(int)date('w', strtotime($s['slot_date']))];
    return $s['slot_date'] . '(周' . $w . ') ' . substr($s['start_time'], 0, 5) . '-' . substr($s['end_time'], 0, 5);
}

/**
 * 时段行锁 + 实时统计（事务内调用）
 * @return array slot 行（含统计）
 */
function lockSlotForUpdate(PDO $db, $slotId) {
    $stmt = $db->prepare("SELECT * FROM volunteer_slots WHERE id = ? FOR UPDATE");
    $stmt->execute([$slotId]);
    return $stmt->fetch();
}

function countSlotStatus(PDO $db, $slotId, $status) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM volunteer_responses WHERE slot_id = ? AND status = ?");
    $stmt->execute([$slotId, $status]);
    return (int)$stmt->fetchColumn();
}

/**
 * 名额释放后自动递补：候补队首(FIFO) -> 待确认
 * 必须在持有该时段行锁的事务内调用。
 *
 * @return int 本次递补人数
 */
function promoteWaitingFIFO(PDO $db, $slotId, $limit = 1) {
    if ($limit <= 0) return 0;
    $stmt = $db->prepare(
        "SELECT id FROM volunteer_responses
         WHERE slot_id = ? AND status = ?
         ORDER BY created_at ASC, id ASC
         LIMIT $limit FOR UPDATE"
    );
    $stmt->execute([$slotId, VR_WAITING]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE volunteer_responses SET status = ?, decided_at = NULL WHERE id IN ($in)")
           ->execute(array_merge([VR_PENDING], $ids));
    }
    return count($ids);
}

/**
 * 计算某条候补响应在候补队列中的 FIFO 位次（从 1 开始）；非候补返回 0
 */
function getWaitingOrder(PDO $db, $responseId, $slotId) {
    $stmt = $db->prepare(
        "SELECT COUNT(*) + 1 FROM volunteer_responses
         WHERE slot_id = ? AND status = ?
           AND (created_at < (SELECT created_at FROM volunteer_responses WHERE id = ?)
                OR (created_at = (SELECT created_at FROM volunteer_responses WHERE id = ?) AND id < ?))"
    );
    $stmt->execute([$slotId, VR_WAITING, $responseId, $responseId, $responseId]);
    return (int)$stmt->fetchColumn();
}

/**
 * 单条结果构造（逐条返回）
 */
function vrItemResult($clientKey, $slotId, $ok, $status, $message, $extra = []) {
    return array_merge([
        'client_key' => $clientKey,
        'slot_id'    => (int)$slotId,
        'ok'         => $ok,
        'status'     => $status,
        'status_label' => $status !== null ? getVolunteerResponseStatusLabel($status) : '',
        'msg'        => $message,
    ], $extra);
}

/**
 * 后台列表：批量取求助留言的调度汇总（口径与 getSlotsByMessage 完全一致）
 * 返回 message_id => [
 *   'slot_count', 'quota', 'confirmed', 'pending', 'waiting',
 *   'full', 'summary_text'
 * ]
 */
function getVolunteerSummariesByMessages(PDO $db, array $messageIds) {
    $result = [];
    $ids = array_filter(array_map('intval', $messageIds));
    if (empty($ids)) return $result;

    $place = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT s.message_id AS mid,
                   COUNT(DISTINCT s.id) AS slot_count,
                   COALESCE(SUM(s.quota), 0) AS quota,
                   COALESCE(SUM(CASE WHEN r.status = ? THEN 1 ELSE 0 END), 0) AS confirmed,
                   COALESCE(SUM(CASE WHEN r.status = ? THEN 1 ELSE 0 END), 0) AS pending,
                   COALESCE(SUM(CASE WHEN r.status = ? THEN 1 ELSE 0 END), 0) AS waiting
            FROM volunteer_slots s
            LEFT JOIN volunteer_responses r ON r.slot_id = s.id
            WHERE s.message_id IN ($place)
            GROUP BY s.message_id";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([VR_CONFIRMED, VR_PENDING, VR_WAITING], $ids));

    foreach ($stmt->fetchAll() as $row) {
        $confirmed = (int)$row['confirmed'];
        $quota = (int)$row['quota'];
        $pending = (int)$row['pending'];
        $waiting = (int)$row['waiting'];
        $mid = (int)$row['mid'];
        $result[$mid] = [
            'slot_count' => (int)$row['slot_count'],
            'quota'      => $quota,
            'confirmed'  => $confirmed,
            'pending'    => $pending,
            'waiting'    => $waiting,
            'full'       => $confirmed >= $quota && $quota > 0,
            'summary_text' => volunteerSummaryText($confirmed, $quota, $pending, $waiting),
        ];
    }
    return $result;
}

/**
 * 封装状态为可直接渲染的结构（手机号按身份脱敏；需求详情与后台同一口径）
 */
function buildVolunteerStateJson(PDO $db, $state, $messageId, $currentVisitorId) {
    if ($state === null) return null;
    $isOwner = !empty($state['is_owner']);
    $isAdmin = !empty($state['is_admin']);

    foreach ($state['slots'] as &$s) {
        $s['slot_label'] = formatSlotLabel($s);
        $s['quota'] = (int)$s['quota'];
        if (!empty($s['my_response'])) {
            $s['my_response']['status_label'] = getVolunteerResponseStatusLabel((int)$s['my_response']['status']);
            $s['my_response']['status_class'] = getVolunteerResponseStatusClass((int)$s['my_response']['status']);
        }
        if (isset($s['responses'])) {
            foreach ($s['responses'] as &$r) {
                $r['nickname_raw'] = $r['nickname'];
                $r['nickname'] = cleanInput($r['nickname']);
                $r['status_label'] = getVolunteerResponseStatusLabel((int)$r['status']);
                $r['status_class'] = getVolunteerResponseStatusClass((int)$r['status']);
                // 联系方式：仅发起人/管理员可见
                $r['phone'] = ($isOwner || $isAdmin) ? $r['phone'] : null;
                $r['is_self'] = ($r['visitor_id'] === $currentVisitorId);
            }
            unset($r);
        }
    }
    unset($s);
    return $state;
}
