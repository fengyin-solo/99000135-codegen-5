<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/volunteer.php';
require_once __DIR__ . '/../includes/volunteer_actions.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在');
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
        $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        jsonResponse(0, '操作成功');
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        // 删除关联图片
        $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if ($msg && $msg['image']) {
            $imgFile = __DIR__ . '/../' . $msg['image'];
            if (file_exists($imgFile)) unlink($imgFile);
        }
        $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
        jsonResponse(0, '删除成功');
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, m.content as message_content, m.image as message_image, a.username as admin_name FROM reports r LEFT JOIN messages m ON r.message_id = m.id LEFT JOIN admins a ON r.processed_by = a.id WHERE r.id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) jsonResponse(1, '举报不存在');

        $report['report_type_label'] = getReportTypeLabel($report['report_type']);
        $report['status_label'] = getReportStatusLabel($report['status']);
        $report['status_class'] = getReportStatusClass($report['status']);
        $report['message_exists'] = !empty($report['message_title']);
        $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
        $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
        $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
        $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
        $report['description'] = $report['description'] ? nl2br(cleanInput($report['description'])) : '';
        $report['process_note'] = $report['process_note'] ? nl2br(cleanInput($report['process_note'])) : '';
        $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';

        jsonResponse(0, 'ok', $report);
        break;

    case 'process_report':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        $note = cleanInput($_POST['note'] ?? '');

        if (!in_array($status, [1, 2, 3])) jsonResponse(1, '无效状态');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0 FOR UPDATE");
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report) jsonResponse(1, '举报不存在或已处理');

            if ($status === 1) {
                $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
                $stmt->execute([$report['message_id']]);
                $msg = $stmt->fetch();
                if ($msg && $msg['image']) {
                    $imgFile = __DIR__ . '/../' . $msg['image'];
                    if (file_exists($imgFile)) unlink($imgFile);
                }
                $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
            }

            $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ? WHERE id = ?");
            $stmt->execute([$status, $_SESSION['admin_id'], $note, $id]);

            $db->commit();

            $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
            jsonResponse(0, $statusMsg[$status] . '成功');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    case 'volunteer_detail':
        // 后台查看某条求助的完整调度情况（与需求详情页同一数据口径）
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '需求不存在');
        if ($msg['type'] !== 'help') jsonResponse(1, '仅居民求助有志愿服务');

        // 管理员视角：可看全部响应明细和联系方式
        $state = getVolunteerState($db, $id, '', false, true);
        if ($state === null) jsonResponse(1, '该需求未发布志愿服务时段');

        $state = buildVolunteerStateJson($db, $state, $id, '');
        jsonResponse(0, 'ok', [
            'message' => [
                'id' => (int)$msg['id'],
                'title' => cleanInput($msg['title']),
                'nickname' => cleanInput($msg['nickname']),
                'status' => (int)$msg['status'],
            ],
            'state' => $state,
        ]);
        break;

    case 'slot_quota':
        // 后台调整名额（逐条返回；扩名额自动递补候补，缩名额不清退已确认）
        $results = [];
        $slotIds = $_POST['slot_ids'] ?? [];
        $quotas = $_POST['quotas'] ?? [];
        if (!is_array($slotIds) || !is_array($quotas)) jsonResponse(1, '参数不合法');

        foreach ($slotIds as $i => $sid) {
            $sid = (int)$sid;
            if ($sid <= 0 || !isset($quotas[$i])) continue;
            $results[] = adminUpdateSlotQuota($db, $sid, (int)$quotas[$i]);
        }
        if (empty($results)) jsonResponse(1, '没有需要调整的时段');

        $messageId = (int)($results[0]['message_id'] ?? 0);
        $state = null;
        $message = null;
        if ($messageId > 0) {
            $mStmt = $db->prepare("SELECT id, title, nickname, status FROM messages WHERE id = ?");
            $mStmt->execute([$messageId]);
            $m = $mStmt->fetch();
            if ($m) {
                $message = [
                    'id' => (int)$m['id'],
                    'title' => cleanInput($m['title']),
                    'nickname' => cleanInput($m['nickname']),
                    'status' => (int)$m['status'],
                ];
            }
            $state = buildVolunteerStateJson($db, getVolunteerState($db, $messageId, '', false, true), $messageId, '');
        }
        jsonResponse(0, '名额调整完成', ['results' => $results, 'state' => $state, 'message' => $message, 'message_id' => $messageId]);
        break;

    default:
        jsonResponse(1, '未知操作');
}
