/**
 * 志愿响应调度 - 需求详情前端逻辑
 *
 * 要点：
 *  - 页面初始状态来自服务端渲染的 JSON，之后每次操作都用服务端返回的 state 整体重绘，
 *    保证页面所见与服务端（后台列表同源数据）一致；
 *  - 不在前端预留名额：请求失败/网络中断不改动界面，不产生占位；
 *  - client_token 幂等：请求可能已到达服务端但响应丢失时，重试不会产生第二个占位；
 *  - 网络恢复 online / 页面重新可见时，主动拉取最新状态覆盖本地视图。
 */
(function () {
    const panel = document.getElementById('volunteerPanel');
    if (!panel) return;

    let state = JSON.parse(panel.getAttribute('data-state') || '{}');

    const apiUrl = 'api/volunteer.php';

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtRange(slot) {
        const sameDay = slot.start_at.substring(0, 10) === slot.end_at.substring(0, 10);
        // "YYYY-MM-DD HH:MM:SS"
        const md = function (s) { return s.substring(5, 10).replace('-', '月') + '日'; };
        if (sameDay) {
            return esc(md(slot.start_at) + ' ' + slot.start_at.substring(11, 16)
                + ' ~ ' + slot.end_at.substring(11, 16));
        }
        return esc(md(slot.start_at) + ' ' + slot.start_at.substring(11, 16)
            + ' ~ ' + md(slot.end_at) + ' ' + slot.end_at.substring(11, 16));
    }

    function newToken() {
        return 'vt_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 12);
    }

    function statusBadge(status, label) {
        return '<span class="vol-badge ' + esc(status) + '">' + esc(label) + '</span>';
    }

    function render() {
        if (!state || !state.enabled) {
            panel.innerHTML = '';
            return;
        }

        const t = state.totals;
        let html = '<div class="vol-panel">';
        html += '<div class="vol-panel-header">';
        html += '<h3>🤝 志愿响应调度</h3>';
        html += '<div class="vol-summary">';
        html += '<span class="vol-sum-item">已确认 <strong>' + t.confirmed + '</strong>/' + t.quota + '</span>';
        html += '<span class="vol-sum-item">待确认 <strong>' + t.pending + '</strong></span>';
        html += '<span class="vol-sum-item">候补 <strong>' + t.waitlist + '</strong></span>';
        html += '</div></div>';

        if (state.is_publisher) {
            html += '<div class="vol-publisher-tip">📌 您是本需求发起人，可确认或拒绝志愿者的响应。</div>';
        }

        state.slots.forEach(function (slot) {
            html += renderSlot(slot);
        });

        if (state.is_publisher) {
            html += renderAddSlots();
        }

        html += '</div>';
        panel.innerHTML = html;
    }

    function renderSlot(slot) {
        let html = '<div class="vol-slot" data-slot-id="' + slot.id + '">';
        html += '<div class="vol-slot-head">';
        html += '<span class="vol-slot-time">🕐 ' + fmtRange(slot) + '</span>';
        html += '<span class="vol-slot-quota">名额 ' + slot.confirmed + '/' + slot.quota + '</span>';
        if (slot.ended) {
            html += statusBadge('ended', '已结束');
        } else if (slot.full) {
            html += statusBadge('full', '已满额');
        } else {
            html += statusBadge('open', '招募中');
        }
        html += '</div>';

        html += '<div class="vol-progress"><div class="vol-progress-bar" style="width:'
            + Math.min(100, Math.round(slot.confirmed / slot.quota * 100)) + '%"></div></div>';

        if (slot.pending > 0 || slot.waitlist > 0) {
            html += '<div class="vol-slot-sub">待确认 ' + slot.pending + ' 人 · 候补 ' + slot.waitlist + ' 人</div>';
        }

        // 响应名册
        html += '<ul class="vol-response-list">';
        if (slot.responses.length === 0) {
            html += '<li class="vol-empty">暂无响应，快来抢第一个名额吧</li>';
        } else {
            slot.responses.forEach(function (r) {
                html += renderResponse(slot, r);
            });
        }
        html += '</ul>';

        // 志愿者响应表单（非发起人、时段未结束）；自己的活跃响应已在名册中显示并可取消
        if (!state.is_publisher && !slot.ended) {
            const activeMine = slot.responses.filter(function (r) {
                return r.mine && ['pending', 'confirmed', 'waitlist'].indexOf(r.status) >= 0;
            });
            if (activeMine.length === 0) {
                html += renderRespondForm(slot);
            }
        }

        // 发起人调整名额
        if (state.is_publisher && !slot.ended) {
            html += '<div class="vol-quota-form">';
            html += '<label>调整名额：</label>';
            html += '<input type="number" min="1" max="999" value="' + slot.quota + '" class="vol-quota-input">';
            html += '<button type="button" class="btn btn-xs btn-primary vol-quota-btn" data-slot-id="'
                + slot.id + '">保存名额</button>';
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    function renderResponse(slot, r) {
        const active = ['pending', 'confirmed', 'waitlist'].indexOf(r.status) >= 0;
        let html = '<li class="vol-response-item" data-response-id="' + r.id + '">';
        html += '<span class="vol-resp-name">👤 ' + esc(r.volunteer_name) + (r.mine ? '（我）' : '') + '</span>';
        if (r.volunteer_phone) {
            // 号码脱敏由服务端完成（避免源码泄露）；发起人可看到完整号码
            html += '<span class="vol-resp-phone">📞 ' + esc(r.volunteer_phone) + '</span>';
        }
        html += statusBadge(r.status, r.status_label);
        html += '<span class="vol-resp-time">' + esc(r.created_at.substring(5, 16)) + '</span>';

        if (active) {
            if (state.is_publisher) {
                if (r.status === 'pending' || r.status === 'waitlist') {
                    html += '<button type="button" class="btn btn-xs btn-success vol-confirm-btn" data-response-id="'
                        + r.id + '">确认</button>';
                    html += '<button type="button" class="btn btn-xs btn-warning vol-reject-btn" data-response-id="'
                        + r.id + '">拒绝</button>';
                }
                html += '<button type="button" class="btn btn-xs btn-danger vol-cancel-btn" data-response-id="'
                    + r.id + '">取消响应</button>';
            } else if (r.mine) {
                html += '<button type="button" class="btn btn-xs btn-danger vol-cancel-mine" data-response-id="'
                    + r.id + '">取消</button>';
            }
        }
        html += '</li>';
        return html;
    }

    function renderRespondForm(slot) {
        let html = '<form class="vol-respond-form" data-slot-id="' + slot.id + '">';
        html += '<input type="text" class="vol-name-input" maxlength="50" placeholder="您的称呼（必填）">';
        html += '<input type="tel" class="vol-phone-input" maxlength="20" placeholder="联系电话（选填）">';
        const label = slot.full ? '加入候补' : '我要响应';
        html += '<button type="submit" class="btn btn-sm btn-primary vol-respond-btn">' + label + '</button>';
        html += '</form>';
        return html;
    }

    function renderAddSlots() {
        let html = '<div class="vol-add-slots">';
        html += '<h4>➕ 追加服务时段</h4>';
        html += '<form id="volAddSlotsForm" class="vol-add-form">';
        html += '<div class="vol-add-row">';
        html += '<input type="datetime-local" class="vol-add-start" required>';
        html += '<span>至</span>';
        html += '<input type="datetime-local" class="vol-add-end" required>';
        html += '<input type="number" class="vol-add-quota" min="1" max="999" value="1" style="width:90px">';
        html += '<button type="submit" class="btn btn-sm btn-primary">添加时段</button>';
        html += '</div></form></div>';
        return html;
    }

    /**
     * 提交到调度 API
     * 失败时不更新界面、不留占位；携带同一 token 重试保证幂等
     */
    function callApi(payload, opts) {
        opts = opts || {};
        const fd = new FormData();
        Object.keys(payload).forEach(function (k) {
            if (k === '_slots') return;
            if (payload[k] !== undefined && payload[k] !== null) fd.append(k, payload[k]);
        });
        // slots 数组字段
        if (payload._slots) {
            payload._slots.forEach(function (s, i) {
                fd.append('slots[' + i + '][start]', s.start);
                fd.append('slots[' + i + '][end]', s.end);
                fd.append('slots[' + i + '][quota]', s.quota);
            });
        }

        // respond 复用调用方提供的 token（表单 dataset）：请求已到达服务端但响应丢失（断网）时，
        // 重试命中幂等记录而不是再占一个名额
        if (payload.action === 'respond') {
            fd.set('client_token', opts.token || newToken());
        }

        return fetch(apiUrl, { method: 'POST', body: fd })
            .then(function (resp) {
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                return resp.json();
            })
            .then(function (res) {
                if (res.code !== 0) throw new Error(res.msg || '操作失败');
                if (res.data && res.data.state) state = res.data.state;
                render();
                if (res.msg) showToast(res.msg, res.data && res.data.released ? 'success' : 'info');
                return res;
            })
            .catch(function (err) {
                // 网络中断或服务端报错：界面保持服务端上一次确认的状态，不本地占位
                const offline = (err instanceof TypeError) || /network|failed to fetch/i.test(err.message || '');
                const msg = offline
                    ? '网络中断，操作未确认；恢复后将自动同步，请用同一按钮重试（不会重复占位）'
                    : err.message;
                showToast(msg, 'error');
                throw err;
            });
    }

    panel.addEventListener('click', function (e) {
        const btn = e.target.closest('button');
        if (!btn || btn.disabled) return;
        const rid = btn.getAttribute('data-response-id');

        if (btn.classList.contains('vol-confirm-btn')) {
            if (!confirm('确认该志愿者占用一个名额？')) return;
            callApi({ action: 'confirm', response_id: rid });
        } else if (btn.classList.contains('vol-reject-btn')) {
            if (!confirm('拒绝该响应？（不占用名额）')) return;
            callApi({ action: 'reject', response_id: rid });
        } else if (btn.classList.contains('vol-cancel-btn') || btn.classList.contains('vol-cancel-mine')) {
            if (!confirm('确定取消该响应？已确认的名额将立即释放并按候补顺序递补。')) return;
            callApi({ action: 'cancel', response_id: rid });
        } else if (btn.classList.contains('vol-quota-btn')) {
            const wrap = btn.closest('.vol-slot');
            const quota = parseInt(wrap.querySelector('.vol-quota-input').value, 10);
            const sid = btn.getAttribute('data-slot-id');
            callApi({ action: 'quota', slot_id: sid, quota: quota });
        }
    });

    panel.addEventListener('submit', function (e) {
        const form = e.target;
        e.preventDefault();

        if (form.classList.contains('vol-respond-form')) {
            const sid = form.getAttribute('data-slot-id');
            const name = form.querySelector('.vol-name-input').value.trim();
            const phone = form.querySelector('.vol-phone-input').value.trim();
            if (!name) { showToast('请填写您的称呼', 'warning'); return; }

            // 首次提交生成 token 并挂在表单上；失败（断网）后重试沿用同一 token
            if (!form.dataset.token) form.dataset.token = newToken();

            const btn = form.querySelector('.vol-respond-btn');
            btn.disabled = true;
            const original = btn.textContent;
            btn.textContent = '提交中...';
            callApi(
                { action: 'respond', slot_id: sid, volunteer_name: name, volunteer_phone: phone },
                { token: form.dataset.token }
            ).catch(function () {
                btn.disabled = false;
                btn.textContent = original;
            });
        }

        if (form.id === 'volAddSlotsForm') {
            const start = form.querySelector('.vol-add-start').value;
            const end = form.querySelector('.vol-add-end').value;
            const quota = parseInt(form.querySelector('.vol-add-quota').value, 10);
            if (!start || !end) { showToast('请选择完整的起止时间', 'warning'); return; }
            // datetime-local -> "Y-m-d H:i:s"
            const norm = function (v) { return v.replace('T', ' ') + ':00'; };
            const payload = {
                action: 'add_slots',
                message_id: panel.getAttribute('data-message-id'),
                _slots: [{ start: norm(start), end: norm(end), quota: quota }]
            };
            const btn = form.querySelector('button');
            btn.disabled = true;
            callApi(payload).catch(function () { btn.disabled = false; });
        }
    });

    /**
     * 从服务端重新同步状态（网络恢复、页面重新可见时调用）
     */
    function syncState() {
        const messageId = panel.getAttribute('data-message-id');
        fetch(apiUrl + '?action=state&message_id=' + encodeURIComponent(messageId), {
            headers: { 'Cache-Control': 'no-cache' }
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.code === 0 && res.data) {
                    state = res.data;
                    render();
                }
            })
            .catch(function () { /* 仍离线，忽略，等待下次恢复 */ });
    }

    window.addEventListener('online', syncState);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') syncState();
    });

    render();
})();
