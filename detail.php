<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/volunteer.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// 增加浏览量
$db->prepare("UPDATE messages SET views = views + 1 WHERE id = ?")->execute([$id]);

// 获取详情
$stmt = $db->prepare("SELECT * FROM messages WHERE id = ? AND status = 1");
$stmt->execute([$id]);
$msg = $stmt->fetch();

if (!$msg) {
    header('Location: index.php');
    exit;
}

// 志愿调度状态（详情页与后台列表同一套口径）
$volunteerState = null;
$isOwner = false;
if ($msg['type'] === 'help') {
    $currentVisitorId = getVisitorId();
    $isOwner = !empty($msg['visitor_id']) && $msg['visitor_id'] === $currentVisitorId;
    $volunteerState = getVolunteerState($db, $id, $currentVisitorId, $isOwner, false);
}

$pageTitle = cleanInput($msg['title']) . ' - 社区便民留言板';
$currentPage = '';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

include __DIR__ . '/includes/header.php';
?>

<section class="detail-section">
    <div class="container">
        <div class="detail-card">
            <div class="detail-header">
                <span class="card-type type-<?= $msg['type'] ?>"><?= getTypeIcon($msg['type']) ?> <?= getTypeLabel($msg['type']) ?></span>
                <div class="detail-meta">
                    <span>👤 <?= cleanInput($msg['nickname']) ?></span>
                    <span>🕐 <?= $msg['created_at'] ?></span>
                    <span>👁 <?= $msg['views'] ?> 次浏览</span>
                </div>
            </div>

            <h1 class="detail-title"><?= cleanInput($msg['title']) ?></h1>

            <div class="detail-content">
                <?= nl2br(cleanInput($msg['content'])) ?>
            </div>

            <?php if ($msg['image']): ?>
            <div class="detail-image">
                <img src="<?= cleanInput($msg['image']) ?>" alt="留言图片" onclick="window.open(this.src)">
            </div>
            <?php endif; ?>

            <?php if ($msg['phone']): ?>
            <div class="detail-contact">
                <span>📞 联系方式：<?= cleanInput($msg['phone']) ?></span>
            </div>
            <?php endif; ?>

            <div class="detail-actions">
                <a href="index.php" class="btn btn-secondary">← 返回列表</a>
                <?php $isFav = isFavorited($msg['id']); ?>
                <button class="btn favorite-detail-btn <?= $isFav ? 'btn-warning' : 'btn-secondary' ?>" data-message-id="<?= $msg['id'] ?>" onclick="toggleFavorite(event, this)">
                    <span class="favorite-icon"><?= $isFav ? '⭐' : '☆' ?></span>
                    <span class="favorite-text"><?= $isFav ? '已收藏' : '收藏' ?></span>
                </button>
                <?php $hasReported = hasReported($msg['id']); ?>
                <button class="btn <?= $hasReported ? 'btn-secondary' : 'btn-danger' ?> report-btn" data-message-id="<?= $msg['id'] ?>" onclick="openReportModal(<?= $msg['id'] ?>)" <?= $hasReported ? 'disabled' : '' ?>>
                    <span>🚩</span>
                    <span class="report-text"><?= $hasReported ? '已举报' : '举报' ?></span>
                </button>
                <a href="submit.php" class="btn btn-primary">发布留言</a>
            </div>
        </div>

        <?php if ($volunteerState): ?>
        <!-- 志愿响应调度面板：详情页与后台共用 getVolunteerState 数据口径 -->
        <div class="volunteer-panel" id="volunteerPanel" data-message-id="<?= $msg['id'] ?>" data-owner="<?= $isOwner ? 1 : 0 ?>">
            <div class="volunteer-panel-header">
                <h3>🤝 志愿服务调度</h3>
                <span class="volunteer-summary" id="volunteerSummary"><?= cleanInput($volunteerState['summary_text']) ?></span>
            </div>

            <!-- 逐条操作结果提示区 -->
            <div class="volunteer-results" id="volunteerResults" style="display:none;"></div>

            <!-- 发起人：确认/拒绝管理区 -->
            <?php if ($isOwner): ?>
            <div class="volunteer-owner-bar">
                <p class="volunteer-tip">您是本需求发起人，请确认志愿者的响应；确认后名额被占用，取消会自动从候补递补。</p>
                <div class="volunteer-batch-actions">
                    <button type="button" class="btn btn-success btn-sm" id="ownerConfirmBtn">✓ 确认所选</button>
                    <button type="button" class="btn btn-warning btn-sm" id="ownerRejectBtn">✕ 未采纳所选</button>
                </div>
            </div>
            <?php endif; ?>

            <div class="volunteer-slots" id="volunteerSlots">
                <?php foreach ($volunteerState['slots'] as $slot): ?>
                <div class="volunteer-slot" data-slot-id="<?= $slot['id'] ?>" data-full="<?= $slot['full'] ? 1 : 0 ?>">
                    <div class="slot-info">
                        <div class="slot-time">🕐 <?= cleanInput(formatSlotLabel($slot)) ?></div>
                        <div class="slot-quota">
                            名额 <strong><?= (int)$slot['confirmed_count'] ?>/<?= (int)$slot['quota'] ?></strong>
                            <?php if ($slot['full']): ?><span class="vr-badge vr-confirmed">已满员</span><?php else: ?><span class="vr-badge vr-pending">剩余 <?= $slot['remaining'] ?></span><?php endif; ?>
                            <?php if ($slot['pending_count'] > 0): ?><span class="vr-badge vr-pending">待确认 <?= $slot['pending_count'] ?></span><?php endif; ?>
                            <?php if ($slot['waiting_count'] > 0): ?><span class="vr-badge vr-waiting">候补 <?= $slot['waiting_count'] ?></span><?php endif; ?>
                        </div>
                    </div>

                    <?php
                    $my = $slot['my_response'];
                    if ($my):
                        $myStatus = (int)$my['status'];
                    ?>
                    <div class="slot-my">
                        <span class="vr-badge <?= getVolunteerResponseStatusClass($myStatus) ?>">
                            我的响应：<?= getVolunteerResponseStatusLabel($myStatus) ?>
                        </span>
                        <?php if (in_array($myStatus, [VR_PENDING, VR_CONFIRMED, VR_WAITING], true)): ?>
                        <button type="button" class="btn btn-xs btn-danger" data-cancel="<?= (int)$my['id'] ?>">取消响应</button>
                        <?php endif; ?>
                    </div>
                    <?php elseif (!$isOwner): ?>
                    <label class="slot-pick"><input type="checkbox" class="slot-checkbox" value="<?= $slot['id'] ?>"> 我要响应此时段</label>
                    <?php endif; ?>

                    <?php if ($isOwner && !empty($slot['responses'])): ?>
                    <ul class="response-list">
                        <?php foreach ($slot['responses'] as $r): $rs = (int)$r['status']; ?>
                        <li class="response-item response-status-<?= $rs ?>">
                            <?php if (in_array($rs, [VR_PENDING, VR_WAITING], true)): ?>
                            <input type="checkbox" class="response-checkbox" value="<?= (int)$r['id'] ?>">
                            <?php endif; ?>
                            <span class="vr-badge <?= getVolunteerResponseStatusClass($rs) ?>"><?= getVolunteerResponseStatusLabel($rs) ?></span>
                            <span class="response-name">👤 <?= cleanInput($r['nickname']) ?></span>
                            <?php if ($r['phone']): ?><span class="response-phone">📞 <?= cleanInput($r['phone']) ?></span><?php endif; ?>
                            <span class="response-time"><?= date('m-d H:i', strtotime($r['created_at'])) ?></span>
                            <?php if ($rs === VR_WAITING): ?><span class="response-order">候补第 <?= getWaitingOrder($db, $r['id'], $slot['id']) ?> 位</span><?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!$isOwner): ?>
            <div class="volunteer-respond-bar" id="volunteerRespondBar">
                <div class="respond-inputs">
                    <input type="text" id="volunteerNickname" placeholder="您的昵称 *" maxlength="50">
                    <input type="tel" id="volunteerPhone" placeholder="联系电话（选填）" maxlength="20">
                </div>
                <button type="button" class="btn btn-primary" id="volunteerRespondBtn">提交响应</button>
                <p class="volunteer-tip">勾选一个或多个服务时段提交；满员时段会自动进入候补；时间冲突或重复响应会逐条提示。</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- 举报弹窗 -->
<div class="modal" id="reportModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🚩 举报留言</h3>
            <button class="modal-close" onclick="closeReportModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="reportForm">
                <input type="hidden" id="reportMessageId" name="message_id">
                <div class="form-group">
                    <label>举报类型 <span class="required">*</span></label>
                    <div class="report-type-options">
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="spam" required>
                            <span>🗑️ 垃圾信息</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="abuse">
                            <span>😡 辱骂攻击</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="illegal">
                            <span>⚖️ 违法违规</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="porn">
                            <span>🔞 色情低俗</span>
                        </label>
                        <label class="report-type-option">
                            <input type="radio" name="report_type" value="other">
                            <span>📝 其他</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="reportDescription">补充说明 <span class="text-muted">(可选，最多500字)</span></label>
                    <textarea id="reportDescription" name="description" rows="4" maxlength="500" placeholder="请描述具体的违规内容，帮助我们更好地处理..."></textarea>
                    <span class="char-count"><span id="reportDescCount">0</span>/500</span>
                </div>
                <div class="form-tip">
                    <p>⚠️ 恶意举报将被限制功能使用，请如实填写举报内容。</p>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeReportModal()">取消</button>
                    <button type="submit" class="btn btn-danger" id="reportSubmitBtn">提交举报</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php if ($volunteerState): ?>
<script>
// 首屏调度状态（与后台列表同源的 getVolunteerState 计算结果）
window.__VOLUNTEER_STATE__ = <?= json_encode(
    buildVolunteerStateJson($db, $volunteerState, $id, getVisitorId()),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;
</script>
<script src="assets/js/volunteer.js"></script>
<?php endif; ?>
