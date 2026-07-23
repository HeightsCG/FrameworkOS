/* Admin dashboard: moderation approve/block + user search + suspend/reactivate. */
(function () {
    var root = document.querySelector('.adm');
    if (!root) { return; }
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function confirmAction(opts) {
        if (window.Swal) {
            return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, focusCancel: true,
                confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' }, opts)).then(function (res) { return res.isConfirmed; });
        }
        return Promise.resolve(window.confirm(opts.title || 'Are you sure?'));
    }

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

    /* ---- Refunds (SweetAlert confirm) ---- */
    var sales = document.getElementById('admSales');
    if (sales) {
        sales.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-refund]');
            if (!btn) { return; }
            var row = btn.closest('.adm-srow');
            var amtEl = row.querySelector('.adm-scell--amt');
            var amt = amtEl ? amtEl.textContent.trim() : 'this purchase';
            confirmAction({
                title: 'Refund ' + amt + '?',
                text: "The buyer is credited back and loses access. This can't be undone.",
                icon: 'warning',
                confirmButtonText: 'Refund'
            }).then(function (ok) {
                if (!ok) { return; }
                btn.disabled = true; btn.textContent = 'Refunding…';
                ApiDataSvc.apiCall('post', 'admin_refund', {
                    kind: row.getAttribute('data-kind'),
                    ref_id: parseInt(row.getAttribute('data-ref'), 10),
                    fan_id: parseInt(row.getAttribute('data-fan'), 10)
                }, function (r) {
                    var o = parse(r);
                    if (!o || !o.success) {
                        btn.disabled = false; btn.textContent = 'Refund';
                        if (window.toastr) { toastr.error((o && o.message) || 'Refund failed'); }
                        return;
                    }
                    row.parentNode.removeChild(row);
                    if (window.toastr) {
                        var msg = 'Refunded $' + (o.amount / 10).toFixed(2);
                        if (o.clawback_ok === false) { toastr.warning(msg + " — buyer refunded, but the creator's earning couldn't be clawed back (already spent)."); }
                        else { toastr.success(msg + ' to the buyer'); }
                    }
                    if (!sales.querySelectorAll('.adm-srow').length) {
                        var n = document.getElementById('admSalesNone'); if (n) { n.hidden = false; }
                    }
                });
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
            var nameEl = row.querySelector('.adm-uinfo__name');
            var name = nameEl ? nameEl.textContent.trim() : 'this account';
            var proceed = (status === 'Disabled')
                ? confirmAction({ title: 'Suspend ' + name + '?', text: 'They will be blocked from signing in until reactivated.', icon: 'warning', confirmButtonText: 'Suspend' })
                : Promise.resolve(true);
            proceed.then(function (ok) {
                if (!ok) { return; }
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
        });
    }
})();
