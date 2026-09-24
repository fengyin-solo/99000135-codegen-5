<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '发布留言 - 社区便民留言板';
$currentPage = 'submit';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

include __DIR__ . '/includes/header.php';
?>

<section class="submit-section">
    <div class="container">
        <div class="submit-card">
            <h2 class="submit-title">📝 发布留言</h2>
            <form id="submitForm" class="submit-form" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="nickname">昵称 <span class="required">*</span></label>
                    <input type="text" id="nickname" name="nickname" placeholder="请输入您的昵称" maxlength="50" required>
                </div>

                <div class="form-group">
                    <label for="phone">联系电话</label>
                    <input type="tel" id="phone" name="phone" placeholder="选填，方便联系" maxlength="20">
                </div>

                <div class="form-group">
                    <label for="type">留言类型 <span class="required">*</span></label>
                    <div class="type-selector">
                        <label class="type-option">
                            <input type="radio" name="type" value="help" checked>
                            <span class="type-btn type-help">🆘 居民求助</span>
                        </label>
                        <label class="type-option">
                            <input type="radio" name="type" value="suggest">
                            <span class="type-btn type-suggest">💡 意见建议</span>
                        </label>
                        <label class="type-option">
                            <input type="radio" name="type" value="lost">
                            <span class="type-btn type-lost">🔍 失物招领</span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="title">标题 <span class="required">*</span></label>
                    <input type="text" id="title" name="title" placeholder="请简要描述您的留言主题" maxlength="100" required>
                </div>

                <div class="form-group">
                    <label for="content">详细内容 <span class="required">*</span></label>
                    <textarea id="content" name="content" rows="6" placeholder="请详细描述您的留言内容..." maxlength="2000" required></textarea>
                    <span class="char-count"><span id="charCount">0</span>/2000</span>
                </div>

                <!-- 志愿需求服务时段（仅居民求助显示） -->
                <div class="form-group volunteer-slots-group" id="volunteerSlotsGroup" style="display:none;">
                    <label>🤝 志愿服务时段 <span class="text-muted">(选填，添加后志愿者可按时段响应名额)</span></label>
                    <div id="slotList" class="slot-list"></div>
                    <button type="button" class="btn btn-secondary btn-sm" id="addSlotBtn">➕ 添加服务时段</button>
                    <div class="form-tip">
                        <p>💡 志愿者提交响应后需由您确认；名额满后自动进入候补，取消响应名额立即释放。</p>
                    </div>
                </div>

                <div class="form-group">
                    <label for="image">上传图片</label>
                    <div class="upload-area" id="uploadArea">
                        <input type="file" id="image" name="image" accept="image/*" hidden>
                        <div class="upload-placeholder" id="uploadPlaceholder" onclick="document.getElementById('image').click()">
                            <div class="upload-icon">📷</div>
                            <p>点击上传图片</p>
                            <span class="upload-hint">支持 JPG、PNG、GIF，最大 5MB</span>
                        </div>
                        <div class="upload-preview" id="uploadPreview" style="display:none;">
                            <img id="previewImg" src="" alt="预览">
                            <button type="button" class="remove-image" onclick="removeImage()">✕ 移除</button>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn">提交留言</button>
                    <a href="index.php" class="btn btn-secondary btn-lg">返回首页</a>
                </div>
            </form>
        </div>
    </div>
</section>

<script>
// 字数统计
document.getElementById('content').addEventListener('input', function() {
    document.getElementById('charCount').textContent = this.value.length;
});

// 志愿需求服务时段动态表单
(function() {
    const typeRadios = document.querySelectorAll('input[name="type"]');
    const slotsGroup = document.getElementById('volunteerSlotsGroup');
    const slotList = document.getElementById('slotList');
    const addSlotBtn = document.getElementById('addSlotBtn');
    let slotIndex = 0;

    function toggleSlotsGroup() {
        const isHelp = document.querySelector('input[name="type"]:checked').value === 'help';
        slotsGroup.style.display = isHelp ? 'block' : 'none';
    }
    typeRadios.forEach(r => r.addEventListener('change', toggleSlotsGroup));
    toggleSlotsGroup();

    function addSlotRow() {
        const i = slotIndex++;
        const row = document.createElement('div');
        row.className = 'slot-row form-row-inline';
        row.innerHTML =
            '<input type="datetime-local" name="slots[' + i + '][start]" class="slot-start" required>' +
            '<span>至</span>' +
            '<input type="datetime-local" name="slots[' + i + '][end]" class="slot-end" required>' +
            '<input type="number" name="slots[' + i + '][quota]" class="slot-quota" min="1" max="999" value="1" title="名额" style="width:90px">' +
            '<span>人</span>' +
            '<button type="button" class="btn btn-xs btn-danger slot-remove">✕</button>';
        slotList.appendChild(row);
        row.querySelector('.slot-remove').addEventListener('click', function() { row.remove(); });
    }
    addSlotBtn.addEventListener('click', addSlotRow);
})();

// 图片预览
document.getElementById('image').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
        alert('图片大小不能超过5MB');
        this.value = '';
        return;
    }
    const reader = new FileReader();
    reader.onload = function(ev) {
        document.getElementById('previewImg').src = ev.target.result;
        document.getElementById('uploadPlaceholder').style.display = 'none';
        document.getElementById('uploadPreview').style.display = 'flex';
    };
    reader.readAsDataURL(file);
});

function removeImage() {
    document.getElementById('image').value = '';
    document.getElementById('uploadPlaceholder').style.display = 'flex';
    document.getElementById('uploadPreview').style.display = 'none';
    document.getElementById('previewImg').src = '';
}

// 表单提交
document.getElementById('submitForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = '提交中...';

    const formData = new FormData(this);
    fetch('api/submit.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('留言提交成功，等待审核！');
            window.location.href = 'index.php';
        } else {
            alert(data.msg || '提交失败');
            btn.disabled = false;
            btn.textContent = '提交留言';
        }
    })
    .catch(() => {
        alert('网络错误，请重试');
        btn.disabled = false;
        btn.textContent = '提交留言';
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
