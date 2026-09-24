<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/volunteer.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, '不支持的请求方式');
}

$nickname = trim($_POST['nickname'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$type = $_POST['type'] ?? 'help';
$title = trim($_POST['title'] ?? '');
$content = trim($_POST['content'] ?? '');

// 验证
if (empty($nickname)) jsonResponse(1, '请输入昵称');
if (mb_strlen($nickname) > 50) jsonResponse(1, '昵称不能超过50个字符');
if (empty($title)) jsonResponse(1, '请输入标题');
if (mb_strlen($title) > 100) jsonResponse(1, '标题不能超过100个字符');
if (empty($content)) jsonResponse(1, '请输入内容');
if (mb_strlen($content) > 2000) jsonResponse(1, '内容不能超过2000个字符');
if (!in_array($type, ['help', 'suggest', 'lost'])) jsonResponse(1, '无效的留言类型');

// 志愿服务时段（仅居民求助，可选项）
$slots = [];
if ($type === 'help' && !empty($_POST['slot_date']) && is_array($_POST['slot_date'])) {
    try {
        $slots = normalizeSubmittedSlots($_POST);
    } catch (Exception $e) {
        jsonResponse(1, $e->getMessage());
    }
}

// 处理图片上传
$imagePath = null;
if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['image'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024;

    if (!in_array($file['type'], $allowedTypes)) {
        jsonResponse(1, '仅支持 JPG、PNG、GIF、WebP 格式的图片');
    }
    if ($file['size'] > $maxSize) {
        jsonResponse(1, '图片大小不能超过5MB');
    }

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $imagePath = 'uploads/' . $filename;
    } else {
        jsonResponse(1, '图片上传失败');
    }
}

// 入库
$db = null;
try {
    $db = getDB();
    $visitorId = getVisitorId();
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO messages (visitor_id, nickname, phone, type, title, content, image, status) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
    $stmt->execute([$visitorId, $nickname, $phone ?: null, $type, $title, $content, $imagePath]);
    $messageId = (int)$db->lastInsertId();

    if ($type === 'help' && $slots) {
        insertSlots($db, $messageId, $slots);
    }

    $db->commit();
    $slotMsg = $slots ? sprintf('，已发布 %d 个志愿服务时段', count($slots)) : '';
    jsonResponse(0, '留言提交成功，等待审核' . $slotMsg);
} catch (Exception $e) {
    if ($db && $db->inTransaction()) $db->rollBack();
    jsonResponse(500, '服务器错误，请稍后重试');
}
