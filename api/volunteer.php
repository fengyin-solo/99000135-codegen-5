<?php
/**
 * 志愿响应调度 API（前台）
 *
 * action:
 *   state    获取某条求助的完整调度状态（详情页渲染/每次变更后同步都调用它）
 *   respond  志愿者批量响应（逐条返回：重复响应/时段冲突/已满候补）
 *   cancel   志愿者取消响应（立即释放名额并自动递补）
 *   confirm  发起人批量确认
 *   reject   发起人批量拒绝
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/volunteer.php';
require_once __DIR__ . '/../includes/volunteer_actions.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();
$visitorId = getVisitorId();

try {
    switch ($action) {

        case 'state':
            $messageId = (int)($_GET['message_id'] ?? $_POST['message_id'] ?? 0);
            if ($messageId <= 0) jsonResponse(1, '无效的需求ID');

            $msgStmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND status = 1 AND type = 'help'");
            $msgStmt->execute([$messageId]);
            $msg = $msgStmt->fetch();
            if (!$msg) jsonResponse(1, '需求不存在或未通过审核');

            $isOwner = !empty($msg['visitor_id']) && $msg['visitor_id'] === $visitorId;
            $state = getVolunteerState($db, $messageId, $visitorId, $isOwner, false);
            if ($state === null) jsonResponse(1, '该需求暂未发布志愿服务时段');

            jsonResponse(0, 'ok', buildVolunteerStateJson($db, $state, $messageId, $visitorId));
            break;

        case 'respond':
            requirePost();
            $messageId = (int)($_POST['message_id'] ?? 0);
            $nickname = trim($_POST['nickname'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $slotIds = $_POST['slot_ids'] ?? [];
            $clientKeys = $_POST['client_keys'] ?? [];
            if (!is_array($slotIds)) $slotIds = [];

            // 先确认需求属于该留言
            requireSchedulableMessage($db, $messageId);
            $items = [];
            foreach ($slotIds as $i => $sid) {
                $items[] = [
                    'slot_id' => (int)$sid,
                    'client_key' => (string)($clientKeys[$i] ?? ('slot_' . $sid . '_' . $i)),
                ];
            }

            $out = volunteerRespond($db, $visitorId, $nickname, $phone, $items);
            $state = getVolunteerState($db, $messageId, $visitorId,
                isMessageOwnerForState($db, $messageId, $visitorId), false);
            jsonResponse(0, '响应提交完成', [
                'results' => $out['results'],
                'state' => buildVolunteerStateJson($db, $state, $messageId, $visitorId),
            ]);
            break;

        case 'cancel':
            requirePost();
            $out = volunteerCancel($db, $visitorId, (int)($_POST['response_id'] ?? 0));
            $messageId = $out['message_id'];
            $state = getVolunteerState($db, $messageId, $visitorId,
                isMessageOwnerForState($db, $messageId, $visitorId), false);
            jsonResponse(0, $out['msg'], [
                'promoted' => $out['promoted'],
                'state' => buildVolunteerStateJson($db, $state, $messageId, $visitorId),
            ]);
            break;

        case 'confirm':
        case 'reject':
            requirePost();
            $messageId = (int)($_POST['message_id'] ?? 0);
            $responseIds = $_POST['response_ids'] ?? [];
            $clientKeys = $_POST['client_keys'] ?? [];
            if (!is_array($responseIds)) $responseIds = [];
            $items = [];
            foreach ($responseIds as $i => $rid) {
                $items[] = [
                    'response_id' => (int)$rid,
                    'client_key' => (string)($clientKeys[$i] ?? ('r_' . $rid . '_' . $i)),
                ];
            }
            $out = $action === 'confirm'
                ? ownerConfirmResponses($db, $visitorId, $items)
                : ownerRejectResponses($db, $visitorId, $items);
            $messageId = $out['message_id'];
            $state = getVolunteerState($db, $messageId, $visitorId, true, false);
            jsonResponse(0, $action === 'confirm' ? '确认提交完成' : '拒绝提交完成', [
                'results' => $out['results'],
                'state' => buildVolunteerStateJson($db, $state, $messageId, $visitorId),
            ]);
            break;

        default:
            jsonResponse(1, '未知操作');
    }
} catch (Exception $e) {
    jsonResponse(1, $e->getMessage());
}

function isMessageOwnerForState(PDO $db, $messageId, $visitorId) {
    $stmt = $db->prepare("SELECT visitor_id FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    $vid = $stmt->fetchColumn();
    return $vid && $vid === $visitorId;
}

function requirePost() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, '不支持的请求方式');
    }
}
