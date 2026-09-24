<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/volunteer.php';
requireAdmin();

$pageTitle = '后台管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();

// 筛选参数
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

$where = "WHERE 1=1";
$params = [];

if ($status !== '' && in_array($status, ['0', '1', '2'])) {
    $where .= " AND status = ?";
    $params[] = intval($status);
}
if ($type && in_array($type, ['help', 'suggest', 'lost'])) {
    $where .= " AND type = ?";
    $params[] = $type;
}
if ($keyword) {
    $where .= " AND (title LIKE ? OR content LIKE ? OR nickname LIKE ?)";
    $kw = "%$keyword%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

$sql = "SELECT * FROM messages $where ORDER BY created_at DESC LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 志愿调度汇总（仅求助类型需要，批量查询，口径与需求详情完全一致）
$helpIds = array_column(array_filter($messages, function ($m) {
    return $m['type'] === 'help';
}), 'id');
$volunteerSummaries = getVolunteerSummariesByMessages($db, $helpIds);

// 统计
$pendingCount = $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link active">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="reports.php" class="sidebar-link">🚩 举报管理</a>
            <?php $pendingReportCount = getPendingReportCount(); ?>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $pendingReportCount > 0 ? "($pendingReportCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>留言管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <!-- 筛选栏 -->
        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <select name="status">
                    <option value="">全部状态</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>待审核</option>
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>已通过</option>
                    <option value="2" <?= $status === '2' ? 'selected' : '' ?>>已拒绝</option>
                </select>
                <select name="type">
                    <option value="">全部类型</option>
                    <option value="help" <?= $type === 'help' ? 'selected' : '' ?>>居民求助</option>
                    <option value="suggest" <?= $type === 'suggest' ? 'selected' : '' ?>>意见建议</option>
                    <option value="lost" <?= $type === 'lost' ? 'selected' : '' ?>>失物招领</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索关键词..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="index.php" class="btn btn-secondary btn-sm">重置</a>
            </form>
        </div>

        <!-- 留言表格 -->
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>类型</th>
                        <th>标题</th>
                        <th>昵称</th>
                        <th>状态</th>
                        <th>志愿调度</th>
                        <th>浏览</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="9" class="text-center">暂无数据</td></tr>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr>
                        <td><?= $msg['id'] ?></td>
                        <td><span class="badge badge-<?= $msg['type'] ?>"><?= getTypeLabel($msg['type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($msg['title']) ?>"><?= cleanInput(mb_substr($msg['title'], 0, 20)) ?></td>
                        <td><?= cleanInput($msg['nickname']) ?></td>
                        <td><span class="status-badge status-<?= getStatusClass($msg['status']) ?>"><?= getStatusLabel($msg['status']) ?></span></td>
                        <td class="td-volunteer">
                            <?php if ($msg['type'] === 'help' && isset($volunteerSummaries[$msg['id']])):
                                $vs = $volunteerSummaries[$msg['id']]; ?>
                                <button type="button" class="volunteer-summary-btn" onclick="viewVolunteer(<?= $msg['id'] ?>)">
                                    <span class="vr-badge <?= $vs['full'] ? 'vr-confirmed' : 'vr-pending' ?>">
                                        <?= $vs['full'] ? '已满员' : '招募中' ?>
                                    </span>
                                    <span class="volunteer-summary-text"><?= cleanInput($vs['summary_text']) ?></span>
                                </button>
                            <?php elseif ($msg['type'] === 'help'): ?>
                                <span class="text-muted">未发布时段</span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $msg['views'] ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($msg['created_at'])) ?></td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info" onclick="viewMessage(<?= $msg['id'] ?>)">查看</button>
                            <?php if ($msg['status'] != 1): ?>
                            <button class="btn btn-xs btn-success" onclick="auditMessage(<?= $msg['id'] ?>, 1)">通过</button>
                            <?php endif; ?>
                            <?php if ($msg['status'] != 2): ?>
                            <button class="btn btn-xs btn-warning" onclick="auditMessage(<?= $msg['id'] ?>, 2)">拒绝</button>
                            <?php endif; ?>
                            <button class="btn btn-xs btn-danger" onclick="deleteMessage(<?= $msg['id'] ?>)">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="index.php?page=<?= $page - 1 ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="index.php?page=<?= $i ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="index.php?page=<?= $page + 1 ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 查看弹窗 -->
<div class="modal" id="viewModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>留言详情</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">加载中...</div>
    </div>
</div>

<!-- 志愿调度管理弹窗（与需求详情页同源状态） -->
<div class="modal" id="volunteerModal" style="display:none;">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>🤝 志愿调度管理</h3>
            <button class="modal-close" onclick="closeVolunteerModal()">&times;</button>
        </div>
        <div class="modal-body" id="volunteerModalBody">加载中...</div>
    </div>
</div>

<script>
function auditMessage(id, status) {
    const action = status === 1 ? '通过' : '拒绝';
    if (!confirm('确定要' + action + '这条留言吗？')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=audit&id=' + id + '&status=' + status
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('操作成功');
            location.reload();
        } else {
            alert(data.msg);
        }
    });
}

function deleteMessage(id) {
    if (!confirm('确定要删除这条留言吗？此操作不可恢复！')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete&id=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('删除成功');
            location.reload();
        } else {
            alert(data.msg);
        }
    });
}

function viewMessage(id) {
    document.getElementById('viewModal').style.display = 'flex';
    document.getElementById('modalBody').innerHTML = '加载中...';
    fetch('api.php?action=detail&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            const d = data.data;
            let html = '<div class="detail-view">';
            html += '<p><strong>类型：</strong>' + d.type_label + '</p>';
            html += '<p><strong>标题：</strong>' + d.title + '</p>';
            html += '<p><strong>昵称：</strong>' + d.nickname + '</p>';
            html += '<p><strong>电话：</strong>' + (d.phone || '未填写') + '</p>';
            html += '<p><strong>内容：</strong></p><div class="detail-text">' + d.content + '</div>';
            if (d.image) html += '<p><strong>图片：</strong><br><img src="../' + d.image + '" style="max-width:100%;margin-top:8px;"></p>';
            html += '<p><strong>状态：</strong>' + d.status_label + '</p>';
            html += '<p><strong>浏览量：</strong>' + d.views + '</p>';
            html += '<p><strong>时间：</strong>' + d.created_at + '</p>';
            html += '</div>';
            document.getElementById('modalBody').innerHTML = html;
        } else {
            document.getElementById('modalBody').innerHTML = data.msg;
        }
    });
}

function closeModal() {
    document.getElementById('viewModal').style.display = 'none';
}

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

/* ================= 志愿响应调度 ================= */
function adminEsc(v) {
    const d = document.createElement('div');
    d.textContent = v === null || v === undefined ? '' : String(v);
    return d.innerHTML;
}

// 状态文案与前台、需求详情页完全一致
function adminVrStatusLabel(s) {
    return {0: '待确认', 1: '已确认', 2: '候补中', 3: '未采纳', 4: '已取消'}[s] || '未知';
}
function adminVrStatusClass(s) {
    return {0: 'vr-pending', 1: 'vr-confirmed', 2: 'vr-waiting', 3: 'vr-rejected', 4: 'vr-canceled'}[s] || '';
}

function renderVolunteerModal(payload) {
    const state = payload.state;
    let html = '<div class="admin-volunteer">';
    html += '<div class="av-head">';
    html += '<p class="av-title">#' + payload.message.id + ' ' + adminEsc(payload.message.title) + '</p>';
    html += '<p class="av-summary"><strong>' + adminEsc(state.summary_text) + '</strong></p>';
    html += '</div>';
    html += '<div class="av-results" id="avResults" style="display:none;"></div>';

    html += '<div class="av-quota-bar">';
    html += '<button type="button" class="btn btn-primary btn-sm" id="avSaveQuotaBtn">保存名额调整</button>';
    html += '<span class="text-muted" style="margin-left:8px;">调大名额后候补自动递补为待确认；调小不会清退已确认志愿者</span>';
    html += '</div>';

    state.slots.forEach(function(slot) {
        html += '<div class="av-slot">';
        html += '<div class="av-slot-head">';
        html += '<span class="av-slot-time">🕐 ' + adminEsc(slot.slot_label) + '</span>';
        html += '<label class="av-quota-input">名额 <input type="number" min="1" max="999" class="av-quota" data-slot-id="' + slot.id + '" value="' + slot.quota + '"></label>';
        html += '<span class="vr-badge ' + (slot.full ? 'vr-confirmed' : 'vr-pending') + '">' + slot.confirmed_count + '/' + slot.quota + (slot.full ? ' 已满员' : ' 剩余' + slot.remaining) + '</span>';
        if (slot.pending_count > 0) html += '<span class="vr-badge vr-pending">待确认 ' + slot.pending_count + '</span>';
        if (slot.waiting_count > 0) html += '<span class="vr-badge vr-waiting">候补 ' + slot.waiting_count + '</span>';
        html += '</div>';

        if (slot.responses && slot.responses.length) {
            html += '<ul class="response-list">';
            let waitingOrder = 0;
            slot.responses.forEach(function(r) {
                const st = parseInt(r.status, 10);
                let order = '';
                if (st === 2) { waitingOrder += 1; order = '<span class="response-order">候补第 ' + waitingOrder + ' 位</span>'; }
                const phone = r.phone ? '<span class="response-phone">📞 ' + adminEsc(r.phone) + '</span>' : '';
                html += '<li class="response-item response-status-' + st + '">'
                    + '<span class="vr-badge ' + r.status_class + '">' + adminEsc(r.status_label) + '</span>'
                    + '<span class="response-name">👤 ' + adminEsc(r.nickname) + '</span>'
                    + phone
                    + '<span class="response-time">' + adminEsc(String(r.created_at).slice(5, 16)) + '</span>'
                    + order + '</li>';
            });
            html += '</ul>';
        } else {
            html += '<p class="text-muted av-empty">暂无响应</p>';
        }
        html += '</div>';
    });

    html += '</div>';
    document.getElementById('volunteerModalBody').innerHTML = html;

    document.getElementById('avSaveQuotaBtn').addEventListener('click', saveSlotQuotas);
}

function showAdminVrResults(results) {
    const box = document.getElementById('avResults');
    if (!results || !results.length) { box.style.display = 'none'; return; }
    let h = '';
    results.forEach(function(r) {
        h += '<div class="vr-result-item ' + (r.ok ? 'is-ok' : 'is-fail') + '"><span>' + (r.ok ? '✓' : '✕') + '</span><span>' + adminEsc(r.msg) + '</span></div>';
    });
    box.innerHTML = h;
    box.style.display = 'block';
}

function viewVolunteer(id) {
    document.getElementById('volunteerModal').style.display = 'flex';
    document.getElementById('volunteerModalBody').innerHTML = '加载中...';
    fetch('api.php?action=volunteer_detail&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            renderVolunteerModal(data.data);
        } else {
            document.getElementById('volunteerModalBody').innerHTML = '<p class="text-center">' + data.msg + '</p>';
        }
    })
    .catch(() => {
        document.getElementById('volunteerModalBody').innerHTML = '<p class="text-center text-danger">网络错误，请稍后重试</p>';
    });
}

function closeVolunteerModal() {
    document.getElementById('volunteerModal').style.display = 'none';
}

function saveSlotQuotas() {
    const inputs = document.querySelectorAll('#volunteerModalBody .av-quota');
    const slotIds = [];
    const quotas = [];
    inputs.forEach(inp => {
        slotIds.push(inp.dataset.slotId);
        quotas.push(inp.value);
    });
    if (!slotIds.length) return;

    const fd = new FormData();
    fd.append('action', 'slot_quota');
    slotIds.forEach((sid, i) => {
        fd.append('slot_ids[]', sid);
        fd.append('quotas[]', quotas[i]);
    });

    const btn = document.getElementById('avSaveQuotaBtn');
    btn.disabled = true;
    btn.textContent = '保存中...';
    fetch('api.php', {method: 'POST', body: fd})
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            // 名额变化后，弹窗与后台列表一起更新（列表 reload 与详情同源）
            if (data.data.state && data.data.message) {
                renderVolunteerModal({message: data.data.message, state: data.data.state});
            }
            showAdminVrResults(data.data.results);
            setTimeout(() => location.reload(), 1200);
        } else {
            alert(data.msg);
            btn.disabled = false;
            btn.textContent = '保存名额调整';
        }
    })
    .catch(() => {
        alert('网络错误，名额调整未生效，请稍后重试');
        btn.disabled = false;
        btn.textContent = '保存名额调整';
    });
}

document.getElementById('volunteerModal').addEventListener('click', function(e) {
    if (e.target === this) closeVolunteerModal();
});
</script>
