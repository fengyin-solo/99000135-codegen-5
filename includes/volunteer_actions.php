<?php
/**
 * 志愿响应调度 - 写操作（全部为事务 + 行锁，保证名额与候补的一致性）
 *
 * 每个批量操作均“逐条”返回结果，重复响应、时段冲突、名额变化等情况
 * 都会单独给出该条的成功与否和原因。
 */

require_once __DIR__ . '/volunteer.php';

/**
 * 校验求助留言可参与调度：已通过审核 + 居民求助类型
 */
function requireSchedulableMessage(PDO $db, $messageId) {
    $stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    $msg = $stmt->fetch();
    if (!$msg) {
        throw new Exception('需求不存在或未通过审核');
    }
    if ($msg['type'] !== 'help') {
        throw new Exception('仅居民求助支持志愿响应');
    }
    return $msg;
}

/**
 * 以访客为单位加串行锁，避免并发提交在不同需求上制造时段冲突
 * 返回是否拿到锁
 */
function acquireVisitorLock(PDO $db, $visitorId, $timeout = 5) {
    $stmt = $db->prepare("SELECT GET_LOCK(CONCAT('volunteer_visitor_', ?), ?)");
    $stmt->execute([$visitorId, $timeout]);
    return ((int)$stmt->fetchColumn()) === 1;
}

function releaseVisitorLock(PDO $db, $visitorId) {
    $stmt = $db->prepare("SELECT RELEASE_LOCK(CONCAT('volunteer_visitor_', ?))");
    $stmt->execute([$visitorId]);
}

/**
 * 志愿者批量响应（可一次勾选多个时段）
 *
 * @param array $items [['slot_id'=>int,'client_key'=>string], ...]
 * @return array ['results' => 逐条结果, 'state' => 调度最新状态]
 */
function volunteerRespond(PDO $db, $visitorId, $nickname, $phone, array $items) {
    if (empty($items)) {
        throw new Exception('请选择要响应的服务时段');
    }
    $nickname = trim($nickname);
    if ($nickname === '') throw new Exception('请填写您的昵称');
    if (mb_strlen($nickname) > 50) throw new Exception('昵称不能超过50个字符');
    $phone = trim($phone);
    if ($phone !== '' && !preg_match('/^[0-9\-+ ]{5,20}$/', $phone)) {
        throw new Exception('联系电话格式不正确');
    }

    // 规范化、去重本次入参
    $normalized = [];
    foreach ($items as $item) {
        $slotId = (int)($item['slot_id'] ?? 0);
        $clientKey = (string)($item['client_key'] ?? ('slot_' . $slotId));
        if ($slotId <= 0) continue;
        if (isset($normalized[$slotId])) {
            $normalized[$slotId]['_dupes'][] = $clientKey;
        } else {
            $normalized[$slotId] = ['slot_id' => $slotId, 'client_key' => $clientKey, '_dupes' => []];
        }
    }
    if (empty($normalized)) throw new Exception('请选择要响应的服务时段');

    if (!acquireVisitorLock($db, $visitorId)) {
        throw new Exception('当前操作排队中，请稍后重试');
    }

    $db->beginTransaction();
    try {
        $slotIds = array_keys($normalized);
        sort($slotIds);

        // 按 id 顺序加时段行锁，避免死锁
        $place = implode(',', array_fill(0, count($slotIds), '?'));
        $stmt = $db->prepare("SELECT * FROM volunteer_slots WHERE id IN ($place) ORDER BY id FOR UPDATE");
        $stmt->execute($slotIds);
        $slots = [];
        $messageId = null;
        foreach ($stmt->fetchAll() as $s) {
            $slots[(int)$s['id']] = $s;
            $messageId = $messageId ?? (int)$s['message_id'];
        }
        if (count($slots) !== count($slotIds)) {
            throw new Exception('部分服务时段不存在');
        }
        foreach ($slots as $s) {
            if ((int)$s['message_id'] !== $messageId) {
                throw new Exception('不能跨需求批量响应');
            }
        }
        requireSchedulableMessage($db, $messageId);

        // 志愿者本人不能响应自己发布的需求
        $msgStmt = $db->prepare("SELECT visitor_id FROM messages WHERE id = ?");
        $msgStmt->execute([$messageId]);
        $ownerVisitorId = $msgStmt->fetchColumn();
        if ($ownerVisitorId && $ownerVisitorId === $visitorId) {
            throw new Exception('不能响应自己发布的需求');
        }

        // 该访客已有的活跃响应
        // 重复响应判定：待确认/已确认/候补 都算“已响应过该时段”；未采纳/已取消的历史记录不限制重新响应
        $mineStmt = $db->prepare(
            "SELECT * FROM volunteer_responses
             WHERE visitor_id = ? AND message_id = ? AND status IN (?, ?, ?) FOR UPDATE"
        );
        $mineStmt->execute([$visitorId, $messageId, VR_PENDING, VR_CONFIRMED, VR_WAITING]);
        $mineBySlot = [];
        foreach ($mineStmt->fetchAll() as $m) {
            $mineBySlot[(int)$m['slot_id']] = $m;
        }
        // 唯一索引 active_key 是最终防线：即使高并发下两条请求同时通过检查，
        // 后提交者会在此处触发重复键错误，整个事务回滚，不会产生第二条占位。

        // 时段冲突判定：仅本人“待确认/已确认”的响应占用时间（跨需求），候补/历史记录不占用
        $existingStmt = $db->prepare(
            "SELECT r.id, r.status, r.slot_id, s.slot_date, s.start_time, s.end_time
             FROM volunteer_responses r
             JOIN volunteer_slots s ON s.id = r.slot_id
             WHERE r.visitor_id = ? AND r.status IN (?, ?) FOR UPDATE"
        );
        $existingStmt->execute([$visitorId, VR_PENDING, VR_CONFIRMED]);
        $occupied = $existingStmt->fetchAll(); // 待确认/已确认都占用志愿者本人的时间

        $insertStmt = $db->prepare(
            "INSERT INTO volunteer_responses (slot_id, message_id, visitor_id, nickname, phone, status)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $results = [];
        foreach ($normalized as $slotId => $item) {
            $slot = $slots[$slotId];
            $key = $item['client_key'];

            // 本批次内重复勾选：逐条给出结果
            foreach ($item['_dupes'] as $dupeKey) {
                $results[] = vrItemResult($dupeKey, $slotId, false, null, '该时段在本次提交中重复选择，已忽略');
            }

            // 1) 已存在活跃响应 => 重复响应（网络重试幂等：返回当前状态，不留新占位）
            //    同时检查 $occupied（本批次内刚成功插入的记录），防止批内换键绕过
            $already = $mineBySlot[$slotId] ?? null;
            if (!$already) {
                foreach ($occupied as $o) {
                    if ((int)$o['slot_id'] === $slotId) { $already = $o; break; }
                }
            }
            if ($already) {
                $results[] = vrItemResult(
                    $key, $slotId, false, (int)$already['status'],
                    '您已响应过该时段，请勿重复响应（当前状态：' . getVolunteerResponseStatusLabel((int)$already['status']) . '）'
                );
                continue;
            }

            // 2) 时段冲突：与本人其它待确认/已确认响应（含本批次已成功的）时间重叠
            $conflict = null;
            foreach ($occupied as $o) {
                $otherSlot = $slots[(int)$o['slot_id']] ?? null;
                if (!$otherSlot) {
                    // 跨需求的已占用时段，用查出的时间字段判断
                    $otherDate = $o['slot_date'];
                    $otherStart = substr($o['start_time'], 0, 5);
                    $otherEnd = substr($o['end_time'], 0, 5);
                } else {
                    $otherDate = $otherSlot['slot_date'];
                    $otherStart = substr($otherSlot['start_time'], 0, 5);
                    $otherEnd = substr($otherSlot['end_time'], 0, 5);
                }
                if (slotsOverlap(
                    $slot['slot_date'], substr($slot['start_time'], 0, 5), substr($slot['end_time'], 0, 5),
                    $otherDate, $otherStart, $otherEnd
                )) {
                    $conflict = $o;
                    break;
                }
            }
            if ($conflict) {
                $results[] = vrItemResult(
                    $key, $slotId, false, null,
                    '时段冲突：与您已响应的其它服务时段时间重叠'
                );
                continue;
            }

            // 3) 名额判定：已确认 + 待确认 占满确认容量 => 候补；否则 => 待确认
            $confirmed = countSlotStatus($db, $slotId, VR_CONFIRMED);
            $pendingCnt = countSlotStatus($db, $slotId, VR_PENDING);
            $newStatus = ($confirmed + $pendingCnt < (int)$slot['quota']) ? VR_PENDING : VR_WAITING;

            // 保存点：高并发或网络重试导致唯一索引(uk_active_slot_visitor)冲突时，
            // 仅回滚本条插入，不影响同批次其它条目，也绝不留下占位
            $savepoint = 'sp_respond_' . $slotId;
            $db->exec("SAVEPOINT `$savepoint`");
            try {
                $insertStmt->execute([$slotId, $messageId, $visitorId, $nickname, $phone ?: null, $newStatus]);
            } catch (PDOException $dup) {
                if (($dup->errorInfo[1] ?? 0) == 1062) {
                    $db->exec("ROLLBACK TO SAVEPOINT `$savepoint`");
                    $db->exec("RELEASE SAVEPOINT `$savepoint`");
                    $results[] = vrItemResult($key, $slotId, false, null, '您已响应过该时段（请求重复提交），请勿重复响应');
                    continue;
                }
                throw $dup;
            }
            $db->exec("RELEASE SAVEPOINT `$savepoint`");
            $newId = (int)$db->lastInsertId();

            $occupied[] = [
                'id' => $newId, 'status' => $newStatus, 'slot_id' => $slotId,
                'slot_date' => $slot['slot_date'],
                'start_time' => $slot['start_time'], 'end_time' => $slot['end_time'],
            ];

            if ($newStatus === VR_PENDING) {
                $results[] = vrItemResult($key, $slotId, true, VR_PENDING, '响应成功，等待发起人确认', ['response_id' => $newId]);
            } else {
                $results[] = vrItemResult($key, $slotId, true, VR_WAITING, '该时段名额已满，已自动进入候补', ['response_id' => $newId]);
            }
        }

        $db->commit();
        return ['results' => $results, 'message_id' => $messageId];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    } finally {
        releaseVisitorLock($db, $visitorId);
    }
}

/**
 * 志愿者取消响应：立即释放名额；若取消的是已确认，候补队首自动递补为待确认
 */
function volunteerCancel(PDO $db, $visitorId, $responseId) {
    $responseId = (int)$responseId;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM volunteer_responses WHERE id = ? FOR UPDATE");
        $stmt->execute([$responseId]);
        $response = $stmt->fetch();
        if (!$response) {
            throw new Exception('响应记录不存在');
        }
        if ($response['visitor_id'] !== $visitorId) {
            throw new Exception('只能取消自己的响应');
        }

        $status = (int)$response['status'];
        if ($status === VR_CANCELED) {
            $db->commit();
            return ['msg' => '该响应已取消，名额此前已释放', 'promoted' => 0, 'message_id' => (int)$response['message_id']];
        }
        if ($status === VR_REJECTED) {
            throw new Exception('未采纳的响应无需取消');
        }

        $promoted = 0;
        if ($status === VR_CONFIRMED) {
            // 占用名额的取消：锁时段 -> 取消 -> 立即释放并按剩余确认容量递补
            $slot = lockSlotForUpdate($db, $response['slot_id']);
            $db->prepare("UPDATE volunteer_responses SET status = ?, decided_at = NULL WHERE id = ?")
                ->execute([VR_CANCELED, $responseId]);

            $confirmedNow = countSlotStatus($db, $slot['id'], VR_CONFIRMED);
            $pendingNow = countSlotStatus($db, $slot['id'], VR_PENDING);
            $capacity = (int)$slot['quota'] - $confirmedNow - $pendingNow;
            $promoted = promoteWaitingFIFO($db, $slot['id'], max(0, $capacity));
        } else {
            // 待确认/候补不占名额，直接取消（候补退出不影响排队）
            $db->prepare("UPDATE volunteer_responses SET status = ? WHERE id = ?")
                ->execute([VR_CANCELED, $responseId]);
        }

        $db->commit();
        $msg = '已取消响应';
        if ($status === VR_CONFIRMED) {
            $msg = $promoted > 0
                ? '已取消响应，名额立即释放，候补第 1 位已自动递补为待确认'
                : '已取消响应，名额已立即释放';
        }
        return ['msg' => $msg, 'promoted' => $promoted, 'message_id' => (int)$response['message_id']];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 发起人权限校验
 */
function requireMessageOwner(PDO $db, $messageId, $visitorId) {
    $stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    $msg = $stmt->fetch();
    if (!$msg) throw new Exception('需求不存在或未通过审核');
    if (!$msg['visitor_id']) throw new Exception('该需求发布较早，暂不支持在线确认');
    if ($msg['visitor_id'] !== $visitorId) throw new Exception('只有需求发起人可以进行此操作');
    return $msg;
}

/**
 * 发起人批量确认
 *
 * @param array $items [['response_id'=>int,'client_key'=>string], ...]
 */
function ownerConfirmResponses(PDO $db, $ownerVisitorId, array $items) {
    return ownerDecideResponses($db, $ownerVisitorId, $items, true);
}

/**
 * 发起人批量拒绝（未采纳）
 */
function ownerRejectResponses(PDO $db, $ownerVisitorId, array $items) {
    return ownerDecideResponses($db, $ownerVisitorId, $items, false);
}

function ownerDecideResponses(PDO $db, $ownerVisitorId, array $items, $confirm) {
    if (empty($items)) throw new Exception('未选择任何响应');

    // 规范化入参并按 response_id 去重
    $normalized = [];
    foreach ($items as $item) {
        $rid = (int)($item['response_id'] ?? 0);
        $key = (string)($item['client_key'] ?? ('r_' . $rid));
        if ($rid <= 0) continue;
        if (isset($normalized[$rid])) {
            $normalized[$rid]['_dupes'][] = $key;
        } else {
            $normalized[$rid] = ['response_id' => $rid, 'client_key' => $key, '_dupes' => []];
        }
    }
    if (empty($normalized)) throw new Exception('未选择任何响应');

    $db->beginTransaction();
    try {
        $ids = array_keys($normalized);
        sort($ids);

        // 锁定全部目标响应
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM volunteer_responses WHERE id IN ($place) ORDER BY id FOR UPDATE");
        $stmt->execute($ids);
        $responses = [];
        $messageId = null;
        foreach ($stmt->fetchAll() as $r) {
            $responses[(int)$r['id']] = $r;
            $messageId = $messageId ?? (int)$r['message_id'];
        }
        if (!$messageId) throw new Exception('响应记录不存在');

        requireMessageOwner($db, $messageId, $ownerVisitorId);

        // 锁定涉及的时段（按 id 排序），用于名额判定
        $slotIds = array_values(array_unique(array_map(function ($r) { return (int)$r['slot_id']; }, $responses)));
        sort($slotIds);
        $lockedSlots = [];
        foreach ($slotIds as $sid) {
            $lockedSlots[$sid] = lockSlotForUpdate($db, $sid);
        }

        $results = [];
        foreach ($normalized as $rid => $item) {
            $key = $item['client_key'];
            foreach ($item['_dupes'] as $dupeKey) {
                $results[] = vrItemResult($dupeKey, 0, false, null, '该响应在本次提交中重复选择，已忽略');
            }

            if (!isset($responses[$rid])) {
                $results[] = vrItemResult($key, 0, false, null, '响应记录不存在');
                continue;
            }
            $r = $responses[$rid];
            $st = (int)$r['status'];

            if ($confirm) {
                if ($st === VR_CONFIRMED) {
                    // 重复确认幂等
                    $results[] = vrItemResult($key, $r['slot_id'], true, VR_CONFIRMED, '该志愿者此前已确认，无需重复操作');
                    continue;
                }
                if ($st === VR_CANCELED) {
                    $results[] = vrItemResult($key, $r['slot_id'], false, VR_CANCELED, '志愿者已取消响应，无法确认');
                    continue;
                }
                if ($st === VR_REJECTED) {
                    $results[] = vrItemResult($key, $r['slot_id'], false, VR_REJECTED, '该响应已标记为未采纳');
                    continue;
                }
                if ($st === VR_WAITING) {
                    $results[] = vrItemResult($key, $r['slot_id'], false, VR_WAITING, '该响应在候补中，需有名额释放后才能确认');
                    continue;
                }
                // VR_PENDING：名额判定
                $confirmed = countSlotStatus($db, $r['slot_id'], VR_CONFIRMED);
                $quota = (int)$lockedSlots[$r['slot_id']]['quota'];
                if ($confirmed >= $quota) {
                    $results[] = vrItemResult($key, $r['slot_id'], false, VR_PENDING, '名额已满，无法确认（仍保持待确认）');
                    continue;
                }
                $db->prepare("UPDATE volunteer_responses SET status = ?, decided_at = NOW() WHERE id = ?")
                    ->execute([VR_CONFIRMED, $rid]);
                $results[] = vrItemResult($key, $r['slot_id'], true, VR_CONFIRMED, '已确认，占用 1 个名额');
            } else {
                if ($st === VR_REJECTED) {
                    $results[] = vrItemResult($key, $r['slot_id'], true, VR_REJECTED, '该响应此前已标记为未采纳');
                    continue;
                }
                if ($st === VR_CANCELED) {
                    $results[] = vrItemResult($key, $r['slot_id'], false, VR_CANCELED, '志愿者已取消响应');
                    continue;
                }
                if ($st === VR_CONFIRMED) {
                    $results[] = vrItemResult($key, $r['slot_id'], false, VR_CONFIRMED, '已确认的响应不能直接拒绝');
                    continue;
                }
                // VR_PENDING / VR_WAITING -> 未采纳（均不占名额，无需递补）
                $db->prepare("UPDATE volunteer_responses SET status = ?, decided_at = NOW() WHERE id = ?")
                    ->execute([VR_REJECTED, $rid]);
                $results[] = vrItemResult($key, $r['slot_id'], true, VR_REJECTED, '已标记为未采纳');
            }
        }

        // 拒绝待确认后按释放的确认容量递补候补（确认流程不产生空位，无需递补）
        if (!$confirm) {
            foreach ($lockedSlots as $sid => $lockInfo) {
                $c = countSlotStatus($db, $sid, VR_CONFIRMED);
                $p = countSlotStatus($db, $sid, VR_PENDING);
                $capacity = (int)$lockInfo['quota'] - $c - $p;
                if ($capacity > 0) {
                    promoteWaitingFIFO($db, $sid, $capacity);
                }
            }
        }

        $db->commit();
        return ['results' => $results, 'message_id' => $messageId];
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 管理员调整单个时段名额
 * 调大：按可用确认容量自动把候补递补为待确认
 * 调小：不清退已确认志愿者，返回是否超额提示
 */
function adminUpdateSlotQuota(PDO $db, $slotId, $newQuota) {
    $slotId = (int)$slotId;
    $newQuota = (int)$newQuota;
    if ($newQuota < 1 || $newQuota > 999) {
        return vrItemResult('slot_' . $slotId, $slotId, false, null, '名额需在 1-999 之间');
    }

    $db->beginTransaction();
    try {
        $slot = lockSlotForUpdate($db, $slotId);
        if (!$slot) {
            $db->rollBack();
            return vrItemResult('slot_' . $slotId, $slotId, false, null, '时段不存在');
        }

        $oldQuota = (int)$slot['quota'];
        $confirmed = countSlotStatus($db, $slotId, VR_CONFIRMED);
        $pending   = countSlotStatus($db, $slotId, VR_PENDING);
        $waiting   = countSlotStatus($db, $slotId, VR_WAITING);

        $db->prepare("UPDATE volunteer_slots SET quota = ? WHERE id = ?")
            ->execute([$newQuota, $slotId]);

        $promoted = 0;
        $note = '';
        if ($newQuota > $oldQuota) {
            // 扩名额：待确认也占用“可确认容量”，避免超额递补
            $capacity = $newQuota - $confirmed - $pending;
            $promoted = promoteWaitingFIFO($db, $slotId, max(0, $capacity));
            $note = $promoted > 0 ? "，候补第 {$promoted} 位已自动递补为待确认" : '，候补暂无变化';
        } elseif ($newQuota < $oldQuota) {
            if ($newQuota < $confirmed) {
                $note = "（当前已确认 {$confirmed} 人，超过新名额，不清退已确认志愿者，请线下协调）";
            }
        }

        $db->commit();
        return vrItemResult(
            'slot_' . $slotId, $slotId, true, null,
            "名额已由 {$oldQuota} 调整为 {$newQuota}{$note}",
            [
                'old_quota' => $oldQuota, 'new_quota' => $newQuota,
                'promoted' => $promoted, 'confirmed' => $confirmed,
                'pending' => $pending, 'waiting' => $waiting,
                'message_id' => (int)$slot['message_id'],
            ]
        );
    } catch (Exception $e) {
        $db->rollBack();
        return vrItemResult('slot_' . $slotId, $slotId, false, null, '调整失败：' . $e->getMessage());
    }
}
