/* Admin dashboard: moderation approve/block + user search + suspend/reactivate. */
(function () {
    var root = document.querySelector('.adm');
    if (!root) { return; }
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }

    /* ---- Moderation queue ---- */
    var mod = document.getElementById('admMod');
    if (mod) {
        mod.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-mod]');
            if (!btn) { return; }
            var card = btn.closest('.adm-card');
            var asset = parseInt(card.getAttribute('data-asset'), 10);
            var action = btn.getAttribute('data-mod');
            card.querySelectorAll('.adm-btn').forEach(function (b) { b.disabled = true; });
            ApiDataSvc.apiCall('post', 'admin_moderate', { asset_id: asset, action: action }, function (r) {
                var o = parse(r);
                if (!o || !o.success) {
                    card.querySelectorAll('.adm-btn').forEach(function (b) { b.disabled = false; });
                    if (window.toastr) { toastr.error((o && o.message) || 'Could not update'); }
                    return;
                }
                card.parentNode.removeChild(card);
                if (window.toastr) { toastr.success(action === 'approve' ? 'Approved' : 'Content blocked'); }
                // Update counts
                var meta = document.querySelector('.adm-sec__meta');
                var remaining = mod.querySelectorAll('.adm-card').length;
                if (meta) { meta.textContent = remaining + ' awaiting review'; }
                var kpi = document.querySelector('.adm-kpi--alert .adm-kpi__val') || document.querySelectorAll('.adm-kpi__val')[3];
                if (kpi) { var n = Math.max(0, (parseInt(kpi.textContent.replace(/[^0-9]/g, ''), 10) || 0) - 1); kpi.textContent = n; }
                if (!remaining) {
                    var wrap = document.getElementById('admMod');
                    wrap.outerHTML = '<div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-circle-check"></i></span><p class="adm-empty__t">Nothing to review</p><p class="adm-empty__x">Flagged and unscanned content will appear here for approval.</p></div>';
                }
            });
        });
    }

    /* ---- User search ---- */
    var search = document.getElementById('admUserSearch');
    var usersBody = document.getElementById('admUsers');
    var none = document.getElementById('admUsersNone');
    if (search && usersBody) {
        search.addEventListener('input', function () {
            var q = (this.value || '').trim().toLowerCase();
            var shown = 0;
            usersBody.querySelectorAll('.adm-urow').forEach(function (row) {
                var vis = q === '' || (row.getAttribute('data-search') || '').indexOf(q) >= 0;
                row.style.display = vis ? '' : 'none';
                if (vis) { shown++; }
            });
            if (none) { none.hidden = shown > 0; }
        });
    }

    /* ---- Suspend / reactivate ---- */
    if (usersBody) {
        usersBody.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-status]');
            if (!btn) { return; }
            var row = btn.closest('.adm-urow');
            var uid = parseInt(row.getAttribute('data-uid'), 10);
            var status = btn.getAttribute('data-status');
            btn.disabled = true;
            ApiDataSvc.apiCall('post', 'admin_set_user_status', { user_id: uid, status: status }, function (r) {
                var o = parse(r);
                if (!o || !o.success) {
                    btn.disabled = false;
                    if (window.toastr) { toastr.error((o && o.message) || 'Could not update'); }
                    return;
                }
                var disabled = (o.status === 'Disabled');
                var statusCell = row.children[2];
                statusCell.innerHTML = '<span class="adm-status adm-status--' + (disabled ? 'off' : 'on') + '"><span class="adm-status__dot"></span>' + (disabled ? 'Suspended' : 'Active') + '</span>';
                var actCell = row.children[5];
                actCell.innerHTML = disabled
                    ? '<button type="button" class="adm-btn adm-btn--ok" data-status="Active">Reactivate</button>'
                    : '<button type="button" class="adm-btn adm-btn--danger" data-status="Disabled">Suspend</button>';
                if (window.toastr) { toastr.success(disabled ? 'Account suspended' : 'Account reactivated'); }
            });
        });
    }
})();
