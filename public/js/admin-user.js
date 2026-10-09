/* Admin > user page: support tools (password reset, two-step, credits, refunds, memberships, plan, status). */
(function () {
    var root = document.querySelector('[data-admin-user]');   // the admin user page, or the requester panel on a support request
    if (!root) { return; }
    var user_id = root.getAttribute('data-admin-user');

    function parse(r) { try { return typeof r === 'string' ? JSON.parse(r) : r; } catch (e) { return null; } }
    function confirmAction(opts) {
        if (window.Swal) {
            return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, focusCancel: true,
                confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' }, opts)).then(function (res) { return res.isConfirmed ? (res.value === undefined ? true : res.value) : false; });
        }
        return Promise.resolve(window.confirm(opts.title || 'Are you sure?'));
    }
    function call(action, data, btn, reload) {
        if (btn) { btn.disabled = true; }
        ApiDataSvc.apiCall('post', action, data, function (r) {
            var o = parse(r);
            if (btn) { btn.disabled = false; }
            if (!o || !o.success) { toastr.error((o && o.message) || 'Something went wrong'); return; }
            toastr.success(o.message || 'Done');
            if (reload !== false) { setTimeout(function () { window.location.reload(); }, 600); }
        });
    }

    /* Adjust balance (modal) */
    var modalEl = document.getElementById('admAdjustModal');
    var modal = (window.bootstrap && modalEl) ? new bootstrap.Modal(modalEl) : null;
    if (modalEl) document.getElementById('adjSave').addEventListener('click', function () {
        var amount = parseInt(document.getElementById('adj_amount').value, 10);
        var reason = (document.getElementById('adj_reason').value || '').trim();
        if (!amount) { document.getElementById('adj_amount').classList.add('is-invalid'); toastr.error('Enter an amount, for example 100 or -100'); return; }
        if (reason === '') { document.getElementById('adj_reason').classList.add('is-invalid'); toastr.error('Add a reason'); return; }
        call('admin_adjust_credits', { user_id: user_id, wallet: (document.querySelector('input[name="adj_wallet"]:checked') || {}).value || 'credits', amount: amount, reason: reason }, this);
    });
    if (modalEl) modalEl.addEventListener('input', function (e) { e.target.classList.remove('is-invalid'); });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-admin-user], #admAdjustModal')) { return; }
        var b = e.target.closest('[data-act]'); if (!b) { return; }
        var act = b.getAttribute('data-act');
        if (act === 'adjust') {
            document.getElementById('adj_amount').value = ''; document.getElementById('adj_reason').value = '';
            if (modal) { modal.show(); }
        } else if (act === 'password') {
            confirmAction({ title: 'Send a password reset email?', text: 'The user gets a link to choose a new password. It expires in one hour.', confirmButtonText: 'Send Email' })
                .then(function (ok) { if (ok) { call('admin_send_password_reset', { user_id: user_id }, b, false); } });
        } else if (act === 'mfa_reset') {
            confirmAction({ title: 'Reset two-step sign-in?', text: 'This turns off the authenticator app, email codes and backup codes, so the user can sign in with just their password.', icon: 'warning', confirmButtonText: 'Reset', confirmButtonColor: '#e5484d' })
                .then(function (ok) { if (ok) { call('admin_reset_mfa', { user_id: user_id }, b); } });
        } else if (act === 'age_reset') {
            confirmAction({ title: 'Reset age verification?', text: 'The verification is removed and adult content is hidden for this account again. The next time they turn it on, or publish an adult post, they go through the age check once more.', icon: 'warning', confirmButtonText: 'Reset', confirmButtonColor: '#e5484d' })
                .then(function (ok) { if (ok) { call('admin_reset_age_verification', { user_id: user_id }, b); } });
        } else if (act === 'mfa_email') {
            call('admin_set_mfa_email', { user_id: user_id, enabled: b.getAttribute('data-enabled') }, b);
        } else if (act === 'verify') {
            call('admin_verify_email', { user_id: user_id }, b);
        } else if (act === 'resend') {
            call('admin_resend_verification', { user_id: user_id }, b, false);
        } else if (act === 'status') {
            var s = b.getAttribute('data-status');
            confirmAction({ title: s === 'Disabled' ? 'Suspend this account?' : 'Reactivate this account?', text: s === 'Disabled' ? 'They are signed out and blocked from signing in.' : 'They can sign in again.', icon: s === 'Disabled' ? 'warning' : 'question', confirmButtonText: s === 'Disabled' ? 'Suspend' : 'Reactivate', confirmButtonColor: s === 'Disabled' ? '#e5484d' : '#FF6A13' })
                .then(function (ok) { if (ok) { call('admin_set_user_status', { user_id: user_id, status: s }, b); } });
        } else if (act === 'demo') {
            var d = b.getAttribute('data-demo');
            confirmAction({ title: d === '1' ? 'Mark this account as demo?' : 'Remove the demo flag?', text: d === '1' ? 'It is hidden from the creator directory, sitemap, home feed and search, and its page is not indexed.' : 'It shows in public listings again.', icon: 'question', confirmButtonText: d === '1' ? 'Mark as Demo' : 'Unmark Demo' })
                .then(function (ok) { if (ok) { call('admin_set_demo', { user_id: user_id, is_demo: d }, b); } });
        } else if (act === 'plan') {
            var c = b.getAttribute('data-cancel');
            confirmAction({ title: c === '1' ? 'Cancel this plan at the end of the period?' : 'Resume this plan?', text: c === '1' ? 'The plan stays active until the current period ends, then stops renewing.' : 'The plan will keep renewing.', confirmButtonText: c === '1' ? 'Cancel Plan' : 'Resume Plan', confirmButtonColor: c === '1' ? '#e5484d' : '#FF6A13' })
                .then(function (ok) { if (ok) { call('admin_set_plan_cancel', { user_id: user_id, cancel: c }, b); } });
        } else if (act === 'membership') {
            var row = b.closest('[data-membership]');
            confirmAction({ title: 'Cancel this membership?', text: 'Paid memberships end at the end of the paid period. Free memberships end now.', icon: 'warning', confirmButtonText: 'Cancel Membership', confirmButtonColor: '#e5484d' })
                .then(function (ok) { if (ok) { call('admin_cancel_membership', { membership_id: row.getAttribute('data-membership') }, b); } });
        } else if (act === 'refund') {
            var r = b.closest('[data-kind]');
            confirmAction({ title: 'Refund this purchase?', text: 'The credits go back to the user, the creator\'s earnings are reversed and access is removed.', input: 'text', inputPlaceholder: 'Reason', icon: 'warning', confirmButtonText: 'Refund', confirmButtonColor: '#e5484d',
                inputValidator: function (v) { return (v || '').trim() === '' ? 'Add a reason' : undefined; } })
                .then(function (reason) { if (reason) { call('admin_refund', { kind: r.getAttribute('data-kind'), ref_id: r.getAttribute('data-ref'), fan_id: user_id, reason: reason }, b); } });
        }
    });
})();
