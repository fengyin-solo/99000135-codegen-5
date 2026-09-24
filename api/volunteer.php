<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/volunteer.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

try {
    switch ($action) {
        // 状态同步：需求详情与后台共用同一份数据，网络恢复后以此为准
        case 'state':
            $messageId = intval($_GET['message_id'] ?? 0);
            if (!getVolunteerMessage($db, $messageId)) {
                jsonResponse(1, '需求不存在或未通过审核');
            }
            jsonResponse(0, 'ok', getVolunteerState($db, $messageId));
            break;

        // 志愿者提交响应
        case 'respond':
            if ($method !== 'POST') jsonResponse(405, '不支持的请求方式');
            $slotId = intval($_POST['slot_id'] ?? 0);
            $name = $_POST['volunteer_name'] ?? '';
            $phone = $_POST['volunteer_phone'] ?? '';
            $token = $_POST['client_token'] ?? '';
            if ($slotId <= 0) jsonResponse(1, '无效的时段ID');

            $result = submitVolunteerResponse($db, $slotId, getVisitorId(), $name, $phone, $token);
            $slotMsgStmt = $db->prepare("SELECT message_id FROM volunteer_slots WHERE id = ?");
            $slotMsgStmt->execute([$slotId]);
            $messageId = intval($slotMsgStmt->fetchColumn());
            jsonResponse(0, $result['msg'], [
                'result' => $result['status'],
                'response_id' => $result['response_id'],
                'state' => getVolunteerState($db, $messageId),
            ]);
            break;

        // 发起人确认
        case 'confirm':
            if ($method !== 'POST') jsonResponse(405, '不支持的请求方式');
            $responseId = intval($_POST['response_id'] ?? 0);
            if ($responseId <= 0) jsonResponse(1, '无效的响应ID');

            $r = confirmVolunteerResponse($db, $responseId, getVisitorId());
            $messageId = responseMessageId($db, $responseId);
            jsonResponse(0, $r['msg'], ['result' => $r['status'], 'state' => getVolunteerState($db, $messageId)]);
            break;

        // 发起人拒绝
        case 'reject':
            if ($method !== 'POST') jsonResponse(405, '不支持的请求方式');
            $responseId = intval($_POST['response_id'] ?? 0);
            if ($responseId <= 0) jsonResponse(1, '无效的响应ID');

            $r = rejectVolunteerResponse($db, $responseId, getVisitorId());
            $messageId = responseMessageId($db, $responseId);
            jsonResponse(0, $r['msg'], ['state' => getVolunteerState($db, $messageId)]);
            break;

        // 志愿者本人/发起人取消；名额立即释放
        case 'cancel':
            if ($method !== 'POST') jsonResponse(405, '不支持的请求方式');
            $responseId = intval($_POST['response_id'] ?? 0);
            if ($responseId <= 0) jsonResponse(1, '无效的响应ID');

            $r = cancelVolunteerResponse($db, $responseId, getVisitorId());
            $messageId = responseMessageId($db, $responseId);
            jsonResponse(0, $r['msg'], [
                'released' => $r['released'],
                'promoted' => $r['promoted'],
                'state' => getVolunteerState($db, $messageId),
            ]);
            break;

        // 发起人调整名额（名额变化逐条处理候补）
        case 'quota':
            if ($method !== 'POST') jsonResponse(405, '不支持的请求方式');
            $slotId = intval($_POST['slot_id'] ?? 0);
            $quota = intval($_POST['quota'] ?? 0);
            if ($slotId <= 0) jsonResponse(1, '无效的时段ID');

            $r = updateSlotQuota($db, $slotId, getVisitorId(), $quota);
            $slotMsgStmt = $db->prepare("SELECT message_id FROM volunteer_slots WHERE id = ?");
            $slotMsgStmt->execute([$slotId]);
            $messageId = intval($slotMsgStmt->fetchColumn());
            jsonResponse(0, $r['msg'], [
                'quota' => $r['quota'],
                'promoted' => $r['promoted'],
                'state' => getVolunteerState($db, $messageId),
            ]);
            break;

        // 发起人追加服务时段
        case 'add_slots':
            if ($method !== 'POST') jsonResponse(405, '不支持的请求方式');
            $messageId = intval($_POST['message_id'] ?? 0);
            if ($messageId <= 0) jsonResponse(1, '无效的需求ID');
            $rawSlots = $_POST['slots'] ?? [];
            try {
                $slots = normalizeSlotsInput($rawSlots);
            } catch (Exception $e) {
                jsonResponse(1, $e->getMessage());
            }
            $r = appendVolunteerSlots($db, $messageId, getVisitorId(), $slots);
            jsonResponse(0, $r['msg'], ['state' => getVolunteerState($db, $messageId)]);
            break;

        default:
            jsonResponse(1, '未知操作');
    }
} catch (Exception $e) {
    jsonResponse(1, $e->getMessage());
}

/**
 * 取响应记录所属需求ID
 */
function responseMessageId($db, $responseId) {
    $stmt = $db->prepare("SELECT message_id FROM volunteer_responses WHERE id = ?");
    $stmt->execute([$responseId]);
    return intval($stmt->fetchColumn());
}
