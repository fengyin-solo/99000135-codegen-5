/**
 * 志愿响应调度 - 需求详情页脚本
 *
 * 关键原则：
 * 1. 不做乐观占位 —— 任何按钮操作在请求进行中禁用，网络失败不改变界面，不留下占位；
 * 2. 网络恢复/页面切回前台时，主动向服务端同步一次最新状态；
 * 3. 每次操作成功后用服务端返回的最新 state 整体重渲染面板，状态流转与后台列表完全同源。
 */
(function () {
    const panel = document.getElementById('volunteerPanel');
    if (!panel) return;

    const messageId = parseInt(panel.dataset.messageId, 10);
    let state = window.__VOLUNTEER_STATE__ || null;
    let busy = false;

    function esc(v) {
        if (v === null || v === undefined) return '';
        const d = document.createElement('div');
        d.textContent = String(v);
        return d.innerHTML;
    }

    function statusLabel(s) {
        return {0: '待确认', 1: '已确认', 2: '候补中', 3: '未采纳', 4: '已取消'}[s] || '未知';
    }
    function statusClass(s) {
        return {0: 'vr-pending', 1: 'vr-confirmed', 2: 'vr-waiting', 3: 'vr-rejected', 4: 'vr-canceled'}[s] || '';
    }

    /**
     * 根据服务端 state 整体重渲染面板（与 PHP 首屏结构保持一致）
     */
    function render() {
        if (!state) return;

        document.getElementById('volunteerSummary').textContent = state.summary_text;

        const isOwner = state.is_owner;
        const slotsWrap = document.getElementById('volunteerSlots');
        let html = '';

        (state.slots || []).forEach(function (slot) {
            const badges = [];
            badges.push('名额 <strong>' + slot.confirmed_count + '/' + slot.quota + '</strong>');
            if (slot.full) {
                badges.push('<span class="vr-badge vr-confirmed">已满员</span>');
            } else {
                badges.push('<span class="vr-badge vr-pending">剩余 ' + slot.remaining + '</span>');
            }
            if (slot.pending_count > 0) badges.push('<span class="vr-badge vr-pending">待确认 ' + slot.pending_count + '</span>');
            if (slot.waiting_count > 0) badges.push('<span class="vr-badge vr-waiting">候补 ' + slot.waiting_count + '</span>');

            let myHtml = '';
            if (slot.my_response) {
                const my = slot.my_response;
                let cancelBtn = '';
                if ([0, 1, 2].indexOf(parseInt(my.status, 10)) >= 0) {
                    cancelBtn = '<button type="button" class="btn btn-xs btn-danger" data-cancel="' + my.id + '">取消响应</button>';
                }
                myHtml = '<div class="slot-my">'
                    + '<span class="vr-badge ' + my.status_class + '">我的响应：' + esc(my.status_label) + '</span>'
                    + cancelBtn + '</div>';
            } else if (!isOwner) {
                myHtml = '<label class="slot-pick"><input type="checkbox" class="slot-checkbox" value="' + slot.id + '"> 我要响应此时段</label>';
            }

            let listHtml = '';
            if (isOwner && slot.responses && slot.responses.length) {
                listHtml = '<ul class="response-list">';
                let waitingOrder = 0;
                slot.responses.forEach(function (r) {
                    const st = parseInt(r.status, 10);
                    let check = '';
                    if (st === 0 || st === 2) {
                        check = '<input type="checkbox" class="response-checkbox" value="' + r.id + '">';
                    }
                    let order = '';
                    if (st === 2) {
                        waitingOrder += 1;
                        order = '<span class="response-order">候补第 ' + waitingOrder + ' 位</span>';
                    }
                    const phone = r.phone ? '<span class="response-phone">📞 ' + esc(r.phone) + '</span>' : '';
                    listHtml += '<li class="response-item response-status-' + st + '">'
                        + check
                        + '<span class="vr-badge ' + r.status_class + '">' + esc(r.status_label) + '</span>'
                        + '<span class="response-name">👤 ' + esc(r.nickname) + '</span>'
                        + phone
                        + '<span class="response-time">' + esc(String(r.created_at).slice(5, 16)) + '</span>'
                        + order + '</li>';
                });
                listHtml += '</ul>';
            }

            html += '<div class="volunteer-slot" data-slot-id="' + slot.id + '" data-full="' + (slot.full ? 1 : 0) + '">'
                + '<div class="slot-info">'
                + '<div class="slot-time">🕐 ' + esc(slot.slot_label) + '</div>'
                + '<div class="slot-quota">' + badges.join(' ') + '</div>'
                + '</div>'
                + myHtml + listHtml + '</div>';
        });
        slotsWrap.innerHTML = html;
    }

    /**
     * 逐条展示结果（重复响应/冲突/候补/递补等）
     */
    function showResults(results) {
        const box = document.getElementById('volunteerResults');
        if (!results || !results.length) {
            box.style.display = 'none';
            return;
        }
        let html = '';
        let allOk = true;
        results.forEach(function (r) {
            if (!r.ok) allOk = false;
            html += '<div class="vr-result-item ' + (r.ok ? 'is-ok' : 'is-fail') + '">'
                + '<span class="vr-result-icon">' + (r.ok ? '✓' : '✕') + '</span>'
                + '<span>' + esc(r.msg) + '</span></div>';
        });
        box.className = 'volunteer-results ' + (allOk ? 'all-ok' : 'has-fail');
        box.innerHTML = html;
        box.style.display = 'block';
    }

    function setBusy(v) {
        busy = v;
        panel.querySelectorAll('button').forEach(function (b) { b.disabled = v; });
        panel.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.disabled = v; });
    }

    /**
     * 与服务端同步最新状态 —— 页面加载、操作后、网络恢复、页面重新可见时都会调用
     * 网络失败静默处理（界面保持原状，绝不留下本地占位）
     */
    function syncState(opts) {
        opts = opts || {};
        const fd = new FormData();
        fd.append('action', 'state');
        fd.append('message_id', messageId);
        return fetch('api/volunteer.php?action=state', {
            method: 'POST',
            body: fd,
            headers: {'X-Requested-With': 'fetch'}
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.code === 0 && res.data) {
                state = res.data;
                render();
            } else if (opts.showError) {
                showToast(res.msg || '同步失败', 'error');
            }
        })
        .catch(function () {
            // 网络中断：不改变任何状态，恢复后会再次同步
            if (opts.showError) showToast('网络错误，请稍后重试', 'error');
        });
    }

    function postAction(formData) {
        setBusy(true);
        return fetch('api/volunteer.php', {method: 'POST', body: formData})
        .then(function (r) { return r.json(); })
        .finally(function () { setBusy(false); });
    }

    /* ---- 志愿者提交响应 ---- */
    document.getElementById('volunteerRespondBtn').addEventListener('click', function () {
        if (busy) return;
        const checked = Array.prototype.slice.call(panel.querySelectorAll('.slot-checkbox:checked'));
        if (!checked.length) {
            showToast('请至少勾选一个服务时段', 'warning');
            return;
        }
        const nickname = document.getElementById('volunteerNickname').value.trim();
        if (!nickname) {
            showToast('请填写您的昵称', 'warning');
            return;
        }
        const phone = document.getElementById('volunteerPhone').value.trim();

        const fd = new FormData();
        fd.append('action', 'respond');
        fd.append('message_id', messageId);
        fd.append('nickname', nickname);
        fd.append('phone', phone);
        checked.forEach(function (cb, i) {
            fd.append('slot_ids[]', cb.value);
            fd.append('client_keys[]', 'slot_' + cb.value + '_' + Date.now() + '_' + i);
        });

        postAction(fd).then(function (res) {
            if (res.code === 0) {
                showResults(res.data.results);
                if (res.data.state) {
                    state = res.data.state;
                    render();
                }
                const failCount = (res.data.results || []).filter(function (r) { return !r.ok; }).length;
                showToast(failCount
                    ? '部分时段响应失败，请查看逐条结果'
                    : '响应已提交', failCount ? 'warning' : 'success');
            } else {
                showToast(res.msg || '响应失败', 'error');
            }
        }).catch(function () {
            // 网络中断：请求可能未到达服务端，未渲染任何占位；恢复后 syncState 校准
            showToast('网络错误，响应未提交，请稍后重试', 'error');
        });
    });

    /* ---- 事件委托：取消响应 ---- */
    panel.addEventListener('click', function (e) {
        const cancelBtn = e.target.closest('[data-cancel]');
        if (!cancelBtn || busy) return;
        const responseId = cancelBtn.getAttribute('data-cancel');
        if (!confirm('确定取消该时段的响应吗？已确认的名额将立即释放。')) return;

        const fd = new FormData();
        fd.append('action', 'cancel');
        fd.append('response_id', responseId);

        postAction(fd).then(function (res) {
            if (res.code === 0) {
                showToast(res.msg, 'success');
                if (res.data && res.data.state) {
                    state = res.data.state;
                    render();
                }
            } else {
                showToast(res.msg || '取消失败', 'error');
            }
        }).catch(function () {
            showToast('网络错误，取消未生效，请稍后重试', 'error');
        });
    });

    /* ---- 发起人批量确认 / 拒绝 ---- */
    function ownerDecide(action, btn) {
        if (busy) return;
        const checked = Array.prototype.slice.call(panel.querySelectorAll('.response-checkbox:checked'));
        if (!checked.length) {
            showToast('请先勾选要处理的响应', 'warning');
            return;
        }
        const verb = action === 'confirm' ? '确认' : '标记为未采纳';
        if (!confirm('确定将所选 ' + checked.length + ' 条响应' + verb + '吗？')) return;

        const fd = new FormData();
        fd.append('action', action);
        fd.append('message_id', messageId);
        checked.forEach(function (cb, i) {
            fd.append('response_ids[]', cb.value);
            fd.append('client_keys[]', 'r_' + cb.value + '_' + Date.now() + '_' + i);
        });

        postAction(fd).then(function (res) {
            if (res.code === 0) {
                showResults(res.data.results);
                if (res.data.state) {
                    state = res.data.state;
                    render();
                }
                const failCount = (res.data.results || []).filter(function (r) { return !r.ok; }).length;
                showToast(failCount ? '部分条目未处理成功，请查看结果' : '操作完成', failCount ? 'warning' : 'success');
            } else {
                showToast(res.msg || '操作失败', 'error');
            }
        }).catch(function () {
            showToast('网络错误，操作未生效，请稍后重试', 'error');
        });
    }

    const confirmBtn = document.getElementById('ownerConfirmBtn');
    const rejectBtn = document.getElementById('ownerRejectBtn');
    if (confirmBtn) confirmBtn.addEventListener('click', function () { ownerDecide('confirm'); });
    if (rejectBtn) rejectBtn.addEventListener('click', function () { ownerDecide('reject'); });

    /* ---- 恢复与变更时保持同步 ----
       1. 网络从离线恢复：重新拉取，确认中断期间没有留下/丢失状态；
       2. 页面重新可见（从后台切回）：重新拉取，拿到他人操作（确认、取消、名额变化）后的最新状态。 */
    window.addEventListener('online', function () {
        syncState({showError: false});
        showToast('网络已恢复，调度状态已同步', 'success');
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) syncState({showError: false});
    });

    // 首屏用 PHP 渲染；此处立即拉一次校准（展示他人刚刚发生的状态流转）
    render();
    syncState({showError: false});
})();
