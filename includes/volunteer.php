<?php
/**
 * 志愿响应调度
 *
 * 状态流转:
 *   响应   -> pending(待确认，不占名额)；名额满则 waitlist(候补)
 *   发起人确认 pending -> confirmed(占名额)，若名额已满 -> waitlist
 *   发起人拒绝 pending/waitlist -> rejected
 *   志愿者取消(任意活跃状态) -> cancelled
 *   confirmed 释放名额后，waitlist 队首按顺序提升为 pending
 *   名额增加时，waitlist 队首逐条提升
 */

/**
 * 响应状态文字
 */
function getVolunteerStatusLabel($status) {
    $map = [
        'pending'   => '待确认',
        'confirmed' => '已确认',
        'waitlist'  => '候补中',
        'rejected'  => '已拒绝',
        'cancelled' => '已取消',
    ];
    return $map[$status] ?? '未知';
}

/**
 * 响应状态样式类
 */
function getVolunteerStatusClass($status) {
    $map = [
        'pending'   => 'vol-pending',
        'confirmed' => 'vol-confirmed',
        'waitlist'  => 'vol-waitlist',
        'rejected'  => 'vol-rejected',
        'cancelled' => 'vol-cancelled',
    ];
    return $map[$status] ?? '';
}

/**
 * 校验并规范化服务时段提交
 * 输入格式: [['start' => 'YYYY-MM-DD HH:MM', 'end' => ..., 'quota' => n], ...]
 * 返回规范化数组；数据非法时抛出异常
 */
function normalizeSlotsInput($rawSlots) {
    if (!is_array($rawSlots) || empty($rawSlots)) {
        throw new Exception('请至少添加一个服务时段');
    }

    $slots = [];
    foreach ($rawSlots as $i => $row) {
        $start = trim($row['start'] ?? '');
        $end = trim($row['end'] ?? '');
        $quota = intval($row['quota'] ?? 0);

        if ($start === '' || $end === '') {
            throw new Exception('第' . ($i + 1) . '个时段的起止时间不能为空');
        }
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false) {
            throw new Exception('第' . ($i + 1) . '个时段的时间格式不正确');
        }
        if ($endTs <= $startTs) {
            throw new Exception('第' . ($i + 1) . '个时段的结束时间必须晚于开始时间');
        }
        if ($startTs < time() - 60) {
            throw new Exception('第' . ($i + 1) . '个时段的开始时间不能早于当前时间');
        }
        if ($quota < 1 || $quota > 999) {
            throw new Exception('第' . ($i + 1) . '个时段的名额必须在 1-999 之间');
        }

        $slots[] = [
            'start' => date('Y-m-d H:i:s', $startTs),
            'end'   => date('Y-m-d H:i:s', $endTs),
            'quota' => $quota,
        ];
    }

    // 同一需求内时段不能重叠
    for ($i = 0; $i < count($slots); $i++) {
        for ($j = $i + 1; $j < count($slots); $j++) {
            if (slotsOverlap($slots[$i]['start'], $slots[$i]['end'], $slots[$j]['start'], $slots[$j]['end'])) {
                throw new Exception('第' . ($i + 1) . '个与第' . ($j + 1) . '个服务时段存在时间重叠');
            }
        }
    }

    return $slots;
}

/**
 * 判断两个时段是否时间重叠
 */
function slotsOverlap($start1, $end1, $start2, $end2) {
    return strtotime($start1) < strtotime($end2) && strtotime($start2) < strtotime($end1);
}

/**
 * 手机号脱敏（非发起人查看他人号码时使用）
 */
function maskPhonePlain($phone) {
    $len = mb_strlen($phone);
    if ($len < 7) {
        return '***';
    }
    return mb_substr($phone, 0, 3) . '****' . mb_substr($phone, -4);
}

/**
 * 为留言创建服务时段（在已开启事务的连接上调用）
 */
function createSlotsForMessage($db, $messageId, array $slots) {
    $stmt = $db->prepare("INSERT INTO volunteer_slots (message_id, start_at, end_at, quota) VALUES (?, ?, ?, ?)");
    foreach ($slots as $slot) {
        $stmt->execute([$messageId, $slot['start'], $slot['end'], $slot['quota']]);
    }
}

/**
 * 获取一条已审核通过的求助留言（含发布者校验信息）
 */
function getVolunteerMessage($db, $messageId) {
    $stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND status = 1 AND type = 'help'");
    $stmt->execute([$messageId]);
    return $stmt->fetch();
}

/**
 * 判断当前访客是否为需求发起人
 */
function isVolunteerPublisher($msg) {
    return !empty($msg['publisher_id']) && $msg['publisher_id'] === getVisitorId();
}

/**
 * 提升候补队首：名额释放/增加后调用。
 * 必须在已对 slot 行加 FOR UPDATE 锁的事务内调用。
 * 按名额缺口把最早候补(WAITING -> PENDING)逐条提升为待确认，返回提升数量。
 */
function promoteWaitlist($db, $slot) {
    $confirmed = $db->prepare(
        "SELECT COUNT(*) FROM volunteer_responses WHERE slot_id = ? AND status = 'confirmed'"
    );
    $confirmed->execute([$slot['id']]);
    $used = intval($confirmed->fetchColumn());

    $free = intval($slot['quota']) - $used;
    if ($free <= 0) {
        return 0;
    }

    $promoteStmt = $db->prepare(
        "UPDATE volunteer_responses SET status = 'pending'
         WHERE id = (
             SELECT id FROM (
                 SELECT id FROM volunteer_responses
                 WHERE slot_id = ? AND status = 'waitlist'
                 ORDER BY created_at ASC, id ASC
                 LIMIT 1
             ) AS t
         )"
    );

    $promoted = 0;
    for ($i = 0; $i < $free; $i++) {
        $promoteStmt->execute([$slot['id']]);
        if ($promoteStmt->rowCount() < 1) {
            break;
        }
        $promoted++;
    }
    return $promoted;
}

/**
 * 志愿者提交响应
 * 返回 ['status' => 'pending'|'waitlist', 'response_id' => n, 'msg' => ...]
 *
 * - client_token 幂等：网络中断后重试不会留下第二个占位
 * - 重复响应：同一志愿者对同一时段仅允许一条活跃响应
 * - 时段冲突：同一需求内已有活跃响应的时段与本次时段重叠则拒绝
 * - 名额满：不占名额，直接进入候补
 */
function submitVolunteerResponse($db, $slotId, $visitorId, $name, $phone, $clientToken) {
    $name = trim($name);
    $phone = trim($phone);
    $clientToken = preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $clientToken ?? '') ? $clientToken : null;
    if ($clientToken === null) {
        throw new Exception('无效的请求令牌，请刷新页面后重试');
    }
    if ($name === '') {
        throw new Exception('请填写志愿者称呼');
    }
    if (mb_strlen($name) > 50) {
        throw new Exception('志愿者称呼不能超过50个字符');
    }
    if ($phone !== '' && mb_strlen($phone) > 20) {
        throw new Exception('联系电话不能超过20个字符');
    }

    $db->beginTransaction();
    try {
        // 幂等：网络重试/断网恢复后重发，返回第一次的结果，不新增占位
        $idStmt = $db->prepare("SELECT * FROM volunteer_responses WHERE client_token = ?");
        $idStmt->execute([$clientToken]);
        $existing = $idStmt->fetch();
        if ($existing) {
            if (intval($existing['slot_id']) !== intval($slotId) || $existing['visitor_id'] !== $visitorId) {
                throw new Exception('该请求已用于其他响应，请刷新页面后重试');
            }
            $db->commit();
            return [
                'status'      => $existing['status'],
                'response_id' => intval($existing['id']),
                'msg'         => '请求已处理，状态为「' . getVolunteerStatusLabel($existing['status']) . '」，未重复占位',
                'idempotent'  => true,
            ];
        }

        // 锁定时段行，串行化名额判断
        $slotStmt = $db->prepare("SELECT * FROM volunteer_slots WHERE id = ? FOR UPDATE");
        $slotStmt->execute([$slotId]);
        $slot = $slotStmt->fetch();
        if (!$slot) {
            throw new Exception('服务时段不存在');
        }

        $msg = getVolunteerMessage($db, $slot['message_id']);
        if (!$msg) {
            throw new Exception('需求不存在或未通过审核');
        }
        if (isVolunteerPublisher($msg)) {
            throw new Exception('发起人不能响应自己发布的志愿需求');
        }
        if (strtotime($slot['end_at']) < time()) {
            throw new Exception('该服务时段已结束');
        }

        // 重复响应：同一时段已有活跃响应
        $dupStmt = $db->prepare(
            "SELECT id, status FROM volunteer_responses
             WHERE slot_id = ? AND visitor_id = ? AND status IN ('pending','confirmed','waitlist')
             LIMIT 1"
        );
        $dupStmt->execute([$slotId, $visitorId]);
        if ($dup = $dupStmt->fetch()) {
            throw new Exception('您已响应过该时段（当前状态：' . getVolunteerStatusLabel($dup['status']) . '），请勿重复响应');
        }

        // 时段冲突：同一需求内其他活跃响应的时段与本次重叠
        $conflictStmt = $db->prepare(
            "SELECT r.id, r.status, s.start_at, s.end_at
             FROM volunteer_responses r
             JOIN volunteer_slots s ON s.id = r.slot_id
             WHERE r.message_id = ? AND r.visitor_id = ?
               AND r.status IN ('pending','confirmed','waitlist')
             ORDER BY s.start_at ASC"
        );
        $conflictStmt->execute([$slot['message_id'], $visitorId]);
        foreach ($conflictStmt->fetchAll() as $other) {
            if (slotsOverlap($slot['start_at'], $slot['end_at'], $other['start_at'], $other['end_at'])) {
                throw new Exception(
                    '与您已响应的时段冲突（'
                    . date('m-d H:i', strtotime($other['start_at'])) . '~'
                    . date('H:i', strtotime($other['end_at'])) . '，'
                    . getVolunteerStatusLabel($other['status']) . '），请先取消原响应'
                );
            }
        }

        // 名额判断：待确认不占名额，仅按已确认人数判定满额
        $confirmedStmt = $db->prepare(
            "SELECT COUNT(*) FROM volunteer_responses WHERE slot_id = ? AND status = 'confirmed'"
        );
        $confirmedStmt->execute([$slotId]);
        $used = intval($confirmedStmt->fetchColumn());

        if ($used >= intval($slot['quota'])) {
            $status = 'waitlist';
            $tip = '当前名额已满，已进入候补队列，有名额释放时将自动转为待确认';
        } else {
            $status = 'pending';
            $tip = '响应成功，等待发起人确认';
        }

        $insert = $db->prepare(
            "INSERT INTO volunteer_responses
                (slot_id, message_id, visitor_id, volunteer_name, volunteer_phone, status, client_token)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $insert->execute([
            $slotId, $slot['message_id'], $visitorId, $name, $phone ?: null, $status, $clientToken,
        ]);

        $db->commit();
        return ['status' => $status, 'response_id' => intval($db->lastInsertId()), 'msg' => $tip];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        // 并发重试撞 client_token 唯一键：回退为幂等查询，不留下第二个占位
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) == 1062) {
            $dupToken = $db->prepare("SELECT * FROM volunteer_responses WHERE client_token = ?");
            $dupToken->execute([$clientToken]);
            if ($row = $dupToken->fetch()) {
                if (intval($row['slot_id']) === intval($slotId) && $row['visitor_id'] === $visitorId) {
                    return [
                        'status'      => $row['status'],
                        'response_id' => intval($row['id']),
                        'msg'         => '请求已处理，状态为「' . getVolunteerStatusLabel($row['status']) . '」，未重复占位',
                        'idempotent'  => true,
                    ];
                }
            }
        }
        throw $e;
    }
}

/**
 * 发起人确认响应
 * - 有名额：pending -> confirmed
 * - 无名额：pending 自动 -> waitlist（满额自动回到候补状态）
 */
function confirmVolunteerResponse($db, $responseId, $visitorId) {
    $db->beginTransaction();
    try {
        $resp = lockResponseAndCheckPublisher($db, $responseId, $visitorId);

        if ($resp['status'] === 'confirmed') {
            $db->commit();
            return ['status' => 'confirmed', 'msg' => '该志愿者已是已确认状态，无需重复确认'];
        }
        if ($resp['status'] === 'rejected' || $resp['status'] === 'cancelled') {
            throw new Exception('该响应已' . getVolunteerStatusLabel($resp['status']) . '，无法确认');
        }

        $slotStmt = $db->prepare("SELECT * FROM volunteer_slots WHERE id = ? FOR UPDATE");
        $slotStmt->execute([$resp['slot_id']]);
        $slot = $slotStmt->fetch();

        $confirmedStmt = $db->prepare(
            "SELECT COUNT(*) FROM volunteer_responses WHERE slot_id = ? AND status = 'confirmed'"
        );
        $confirmedStmt->execute([$slot['id']]);
        $used = intval($confirmedStmt->fetchColumn());

        if ($used < intval($slot['quota'])) {
            $update = $db->prepare(
                "UPDATE volunteer_responses SET status = 'confirmed', decided_at = NOW() WHERE id = ?"
            );
            $update->execute([$responseId]);
            $db->commit();
            return ['status' => 'confirmed', 'msg' => '已确认，占用 1 个名额'];
        }

        // 名额已满：自动回到候补
        $update = $db->prepare(
            "UPDATE volunteer_responses SET status = 'waitlist' WHERE id = ?"
        );
        $update->execute([$responseId]);
        $db->commit();
        return ['status' => 'waitlist', 'msg' => '名额已满，该响应已自动转入候补队列'];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 发起人拒绝响应（不占名额，rejected）
 */
function rejectVolunteerResponse($db, $responseId, $visitorId) {
    $db->beginTransaction();
    try {
        $resp = lockResponseAndCheckPublisher($db, $responseId, $visitorId);

        if ($resp['status'] === 'rejected') {
            $db->commit();
            return ['msg' => '该响应已是已拒绝状态'];
        }
        if ($resp['status'] === 'cancelled') {
            throw new Exception('该响应已被志愿者取消，无法拒绝');
        }

        $wasConfirmed = $resp['status'] === 'confirmed';

        $update = $db->prepare(
            "UPDATE volunteer_responses SET status = 'rejected', decided_at = NOW() WHERE id = ?"
        );
        $update->execute([$responseId]);

        $promoted = 0;
        if ($wasConfirmed) {
            $slotStmt = $db->prepare("SELECT * FROM volunteer_slots WHERE id = ? FOR UPDATE");
            $slotStmt->execute([$resp['slot_id']]);
            $promoted = promoteWaitlist($db, $slotStmt->fetch());
        }

        $db->commit();
        return ['msg' => '已拒绝该响应' . ($promoted > 0 ? "，$promoted 名候补志愿者已自动转为待确认" : '')];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 取消响应（志愿者本人或发起人均可）
 * 已确认者取消后名额立即释放，候补队首逐条提升
 */
function cancelVolunteerResponse($db, $responseId, $visitorId) {
    $db->beginTransaction();
    try {
        $respStmt = $db->prepare("SELECT * FROM volunteer_responses WHERE id = ? FOR UPDATE");
        $respStmt->execute([$responseId]);
        $resp = $respStmt->fetch();
        if (!$resp) {
            throw new Exception('响应记录不存在');
        }

        $msgStmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $msgStmt->execute([$resp['message_id']]);
        $msg = $msgStmt->fetch();
        if (!$msg) {
            throw new Exception('需求不存在');
        }

        $isOwner = $resp['visitor_id'] === $visitorId;
        $isPublisher = isVolunteerPublisher($msg);
        if (!$isOwner && !$isPublisher) {
            throw new Exception('无权取消该响应');
        }

        if ($resp['status'] === 'cancelled') {
            $db->commit();
            return ['msg' => '该响应已是取消状态，名额未被占用'];
        }
        if ($resp['status'] === 'rejected') {
            throw new Exception('该响应已被拒绝，无需取消');
        }

        $wasConfirmed = $resp['status'] === 'confirmed';

        $update = $db->prepare("UPDATE volunteer_responses SET status = 'cancelled' WHERE id = ?");
        $update->execute([$responseId]);

        $promoted = 0;
        if ($wasConfirmed) {
            $slotStmt = $db->prepare("SELECT * FROM volunteer_slots WHERE id = ? FOR UPDATE");
            $slotStmt->execute([$resp['slot_id']]);
            $promoted = promoteWaitlist($db, $slotStmt->fetch());
        }

        $db->commit();
        return [
            'msg'      => '响应已取消' . ($wasConfirmed ? '，名额已立即释放' : '')
                          . ($promoted > 0 ? "，$promoted 名候补志愿者已自动转为待确认" : ''),
            'released' => $wasConfirmed,
            'promoted' => $promoted,
        ];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 发起人调整名额
 * - 新名额不得小于已确认人数
 * - 名额增加时，候补队首逐条转为待确认
 */
function updateSlotQuota($db, $slotId, $visitorId, $newQuota) {
    $newQuota = intval($newQuota);
    if ($newQuota < 1 || $newQuota > 999) {
        throw new Exception('名额必须在 1-999 之间');
    }

    $db->beginTransaction();
    try {
        $slotStmt = $db->prepare("SELECT * FROM volunteer_slots WHERE id = ? FOR UPDATE");
        $slotStmt->execute([$slotId]);
        $slot = $slotStmt->fetch();
        if (!$slot) {
            throw new Exception('服务时段不存在');
        }

        $msgStmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $msgStmt->execute([$slot['message_id']]);
        $msg = $msgStmt->fetch();
        if (!isVolunteerPublisher($msg)) {
            throw new Exception('只有发起人可以调整名额');
        }

        $confirmedStmt = $db->prepare(
            "SELECT COUNT(*) FROM volunteer_responses WHERE slot_id = ? AND status = 'confirmed'"
        );
        $confirmedStmt->execute([$slotId]);
        $used = intval($confirmedStmt->fetchColumn());

        if ($newQuota < $used) {
            throw new Exception("名额不能小于已确认人数（当前已确认 $used 人），请先取消相应确认");
        }

        $oldQuota = intval($slot['quota']);
        $db->prepare("UPDATE volunteer_slots SET quota = ? WHERE id = ?")
           ->execute([$newQuota, $slotId]);

        $promoted = 0;
        if ($newQuota > $oldQuota) {
            $slot['quota'] = $newQuota;
            $promoted = promoteWaitlist($db, $slot);
        }

        $db->commit();
        return [
            'quota'    => $newQuota,
            'promoted' => $promoted,
            'msg'      => $newQuota > $oldQuota
                ? "名额已增加至 $newQuota" . ($promoted > 0 ? "，$promoted 名候补志愿者已自动转为待确认" : '')
                : "名额已调整为 $newQuota",
        ];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 发起人追加服务时段（名额变化之外的新增）
 */
function appendVolunteerSlots($db, $messageId, $visitorId, array $slots) {
    $db->beginTransaction();
    try {
        $msgStmt = $db->prepare("SELECT * FROM messages WHERE id = ? FOR UPDATE");
        $msgStmt->execute([$messageId]);
        $msg = $msgStmt->fetch();
        if (!$msg) {
            throw new Exception('需求不存在');
        }
        if (!isVolunteerPublisher($msg)) {
            throw new Exception('只有发起人可以添加服务时段');
        }

        // 与已有时段不能重叠
        $existStmt = $db->prepare("SELECT start_at, end_at FROM volunteer_slots WHERE message_id = ?");
        $existStmt->execute([$messageId]);
        foreach ($existStmt->fetchAll() as $exist) {
            foreach ($slots as $i => $slot) {
                if (slotsOverlap($exist['start_at'], $exist['end_at'], $slot['start'], $slot['end'])) {
                    throw new Exception('第' . ($i + 1) . '个新时段与已有服务时段重叠');
                }
            }
        }

        createSlotsForMessage($db, $messageId, $slots);
        $db->commit();
        return ['msg' => '已添加 ' . count($slots) . ' 个服务时段'];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 锁定响应记录并校验当前访客为发起人
 */
function lockResponseAndCheckPublisher($db, $responseId, $visitorId) {
    $respStmt = $db->prepare("SELECT * FROM volunteer_responses WHERE id = ? FOR UPDATE");
    $respStmt->execute([$responseId]);
    $resp = $respStmt->fetch();
    if (!$resp) {
        throw new Exception('响应记录不存在');
    }

    $msgStmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
    $msgStmt->execute([$resp['message_id']]);
    $msg = $msgStmt->fetch();
    if (!isVolunteerPublisher($msg)) {
        throw new Exception('只有发起人可以执行该操作');
    }
    return $resp;
}

/**
 * 需求详情/后台共用的统一状态视图，保证两处数据一致
 *
 * 返回:
 *   enabled: 是否志愿需求
 *   is_publisher: 当前访客是否发起人
 *   slots: [{id, start_at, end_at, quota, confirmed, pending, waitlist,
 *            rejected, cancelled, full, responses: [...]}]
 *   totals: 各状态合计
 */
function getVolunteerState($db, $messageId, $includeInactive = true) {
    $msgStmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
    $msgStmt->execute([$messageId]);
    $msg = $msgStmt->fetch();
    if (!$msg) {
        return null;
    }

    $slotStmt = $db->prepare("SELECT * FROM volunteer_slots WHERE message_id = ? ORDER BY start_at ASC, id ASC");
    $slotStmt->execute([$messageId]);
    $slots = $slotStmt->fetchAll();

    $visitorId = getVisitorId();
    $state = [
        'enabled'       => !empty($slots),
        'is_publisher'  => isVolunteerPublisher($msg),
        'slots'         => [],
        'totals'        => [
            'quota' => 0, 'confirmed' => 0, 'pending' => 0,
            'waitlist' => 0, 'rejected' => 0, 'cancelled' => 0,
        ],
    ];

    if (empty($slots)) {
        return $state;
    }

    $respStmt = $db->prepare(
        "SELECT * FROM volunteer_responses
         WHERE message_id = ?
         ORDER BY FIELD(status,'confirmed','pending','waitlist','rejected','cancelled'),
                  created_at ASC, id ASC"
    );
    $respStmt->execute([$messageId]);
    $responsesBySlot = [];
    $isPublisher = isVolunteerPublisher($msg);
    foreach ($respStmt->fetchAll() as $r) {
        $r['mine'] = $r['visitor_id'] === $visitorId;
        $r['status_label'] = getVolunteerStatusLabel($r['status']);
        $r['status_class'] = getVolunteerStatusClass($r['status']);

        // 非发起人只能看到活跃响应以及自己的记录（他人的拒绝/取消历史不公开）
        $isActive = in_array($r['status'], ['confirmed', 'pending', 'waitlist']);
        if (!$isPublisher && !$isActive && !$r['mine']) {
            continue;
        }

        // 手机号脱敏：非发起人看不到他人的完整号码（避免页面源码泄露）
        if (!$isPublisher && !$r['mine'] && $r['volunteer_phone'] !== null) {
            $r['volunteer_phone'] = maskPhonePlain($r['volunteer_phone']);
        }

        $responsesBySlot[$r['slot_id']][] = $r;
        if (in_array($r['status'], ['confirmed', 'pending', 'waitlist', 'rejected', 'cancelled'])) {
            $state['totals'][$r['status']]++;
        }
    }

    foreach ($slots as $slot) {
        $list = $responsesBySlot[$slot['id']] ?? [];
        $counts = ['confirmed' => 0, 'pending' => 0, 'waitlist' => 0, 'rejected' => 0, 'cancelled' => 0];
        foreach ($list as $r) {
            $counts[$r['status']]++;
        }

        $quota = intval($slot['quota']);
        $state['totals']['quota'] += $quota;

        $visible = $includeInactive
            ? $list
            : array_values(array_filter($list, function ($r) {
                return in_array($r['status'], ['confirmed', 'pending', 'waitlist']);
            }));

        $state['slots'][] = [
            'id'         => intval($slot['id']),
            'start_at'   => $slot['start_at'],
            'end_at'     => $slot['end_at'],
            'quota'      => $quota,
            'confirmed'  => $counts['confirmed'],
            'pending'    => $counts['pending'],
            'waitlist'   => $counts['waitlist'],
            'rejected'   => $counts['rejected'],
            'cancelled'  => $counts['cancelled'],
            'full'       => $counts['confirmed'] >= $quota,
            'ended'      => strtotime($slot['end_at']) < time(),
            'responses'  => $visible,
        ];
    }

    return $state;
}

/**
 * 后台列表批量统计志愿调度汇总（一次查询，避免 N+1）
 * 以 message_id 为 key 返回: [id => ['slots'=>n,'quota'=>n,'confirmed'=>n,'pending'=>n,'waitlist'=>n]]
 */
function getVolunteerSummaries($db, array $messageIds) {
    $result = [];
    if (empty($messageIds)) {
        return $result;
    }

    $ids = array_map('intval', $messageIds);
    $in = implode(',', array_fill(0, count($ids), '?'));

    $slotStmt = $db->prepare(
        "SELECT message_id, COUNT(*) AS slot_count, COALESCE(SUM(quota),0) AS quota_total
         FROM volunteer_slots WHERE message_id IN ($in) GROUP BY message_id"
    );
    $slotStmt->execute($ids);
    foreach ($slotStmt->fetchAll() as $row) {
        $result[intval($row['message_id'])] = [
            'slots'     => intval($row['slot_count']),
            'quota'     => intval($row['quota_total']),
            'confirmed' => 0,
            'pending'   => 0,
            'waitlist'  => 0,
        ];
    }

    $respStmt = $db->prepare(
        "SELECT slot.message_id AS message_id, r.status AS status, COUNT(*) AS cnt
         FROM volunteer_responses r
         JOIN volunteer_slots slot ON slot.id = r.slot_id
         WHERE slot.message_id IN ($in) AND r.status IN ('confirmed','pending','waitlist')
         GROUP BY slot.message_id, r.status"
    );
    $respStmt->execute($ids);
    foreach ($respStmt->fetchAll() as $row) {
        $mid = intval($row['message_id']);
        if (isset($result[$mid])) {
            $result[$mid][$row['status']] = intval($row['cnt']);
        }
    }

    return $result;
}
