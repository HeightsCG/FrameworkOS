/* Team page: invite collaborators, change role, suspend/reactivate, remove. */
(function () {
    var root = document.querySelector('.team');
    if (!root) { return; }
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null) ? '' : String(s); return d.innerHTML; }
    function confirmAction(opts) {
        if (window.Swal) {
            return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, focusCancel: true,
                confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' }, opts)).then(function (res) { return res.isConfirmed; });
        }
        return Promise.resolve(window.confirm(opts.title || 'Are you sure?'));
    }

    /* ---- Invite (modal) ---- */
    var toggle = document.getElementById('teamInviteBtn');
    if (toggle) { toggle.addEventListener('click', openInviteModal); }

    function openInviteModal() {
        if (!window.Swal) { return; }
        Swal.fire({
            title: 'Invite a collaborator',
            width: 460,
            html: '<div class="team-swal">'
                + '<label for="swiName">Name</label><input id="swiName" type="text" placeholder="Alex Rivera" maxlength="120">'
                + '<label for="swiEmail">Email</label><input id="swiEmail" type="email" placeholder="alex@example.com" maxlength="190">'
                + '<label for="swiRole">Role</label><select id="swiRole">'
                + '<option value="manager">Manager — full access</option>'
                + '<option value="editor" selected>Editor — create &amp; edit content</option>'
                + '<option value="viewer">Viewer — read-only</option></select>'
                + '<p class="team-swal__hint">We\'ll email them a link to set their password.</p>'
                + '</div>',
            focusConfirm: false,
            showCancelButton: true,
            reverseButtons: true,
            confirmButtonText: 'Send invite',
            confirmButtonColor: '#5b4be0',
            cancelButtonColor: '#6b6779',
            didOpen: function () { var n = document.getElementById('swiName'); if (n) { n.focus(); } },
            preConfirm: function () {
                var name = (document.getElementById('swiName').value || '').trim();
                var email = (document.getElementById('swiEmail').value || '').trim();
                if (name === '') { Swal.showValidationMessage('Enter a name'); return false; }
                if (email === '') { Swal.showValidationMessage('Enter an email address'); return false; }
                return { name: name, email: email, role: document.getElementById('swiRole').value };
            }
        }).then(function (result) {
            if (!result.isConfirmed || !result.value) { return; }
            ApiDataSvc.apiCall('post', 'team_invite', result.value, function (r) {
                var o = parse(r);
                if (!o || !o.success) { if (window.toastr) { toastr.error((o && o.message) || 'Could not send invite'); } return; }
                Swal.fire({
                    icon: 'success',
                    title: 'Invitation sent',
                    html: 'We emailed <b>' + esc(o.member.email) + '</b> a link to set their password.<br><br>'
                        + '<span style="font-size:.8rem;color:#8a8797;">Or copy the link and share it directly:</span>'
                        + '<input readonly value="' + esc(o.invite_link) + '" onclick="this.select()" style="width:100%;margin-top:.45rem;padding:.5rem .6rem;border:1px solid #e2e0ea;border-radius:8px;font-size:.76rem;color:#4b4757;">',
                    confirmButtonText: 'Done',
                    confirmButtonColor: '#5b4be0'
                }).then(function () { window.location.reload(); });
            });
        });
    }

    /* ---- Role change ---- */
    var body = document.getElementById('teamBody');
    if (body) {
        body.addEventListener('change', function (e) {
            var sel = e.target.closest('[data-role]');
            if (!sel) { return; }
            var mid = parseInt(sel.closest('.team-row').getAttribute('data-mid'), 10);
            ApiDataSvc.apiCall('post', 'team_set_role', { member_id: mid, role: sel.value }, function (r) {
                var o = parse(r);
                if (o && o.success) { if (window.toastr) { toastr.success('Role updated'); } }
                else if (window.toastr) { toastr.error((o && o.message) || 'Could not update role'); }
            });
        });

        /* ---- Suspend / reactivate / remove ---- */
        body.addEventListener('click', function (e) {
            var statusBtn = e.target.closest('[data-status]');
            if (statusBtn) {
                var row = statusBtn.closest('.team-row');
                var mid = parseInt(row.getAttribute('data-mid'), 10);
                var status = statusBtn.getAttribute('data-status');
                var nameEl = row.querySelector('.team-info__name');
                var name = nameEl ? nameEl.textContent.trim() : 'this member';
                var proceed = (status === 'Disabled')
                    ? confirmAction({ title: 'Suspend ' + name + '?', text: 'They will be blocked from signing in until reactivated.', icon: 'warning', confirmButtonText: 'Suspend' })
                    : Promise.resolve(true);
                proceed.then(function (ok) {
                    if (!ok) { return; }
                    statusBtn.disabled = true;
                    ApiDataSvc.apiCall('post', 'team_set_status', { member_id: mid, status: status }, function (r) {
                        var o = parse(r);
                        if (!o || !o.success) { statusBtn.disabled = false; if (window.toastr) { toastr.error((o && o.message) || 'Could not update'); } return; }
                        var disabled = (o.status === 'Disabled');
                        row.querySelector('[data-status-cell]').innerHTML = '<span class="team-status team-status--' + (disabled ? 'off' : 'on') + '"><span class="team-status__dot"></span>' + (disabled ? 'Suspended' : 'Active') + '</span>';
                        row.querySelector('[data-act-cell]').innerHTML = (disabled
                            ? '<button type="button" class="team-btn team-btn--sm" data-status="Active">Reactivate</button>'
                            : '<button type="button" class="team-btn team-btn--sm" data-status="Disabled">Suspend</button>')
                            + '<button type="button" class="team-btn team-btn--sm team-btn--danger" data-remove>Remove</button>';
                        if (window.toastr) { toastr.success(disabled ? 'Member suspended' : 'Member reactivated'); }
                    });
                });
                return;
            }
            var rm = e.target.closest('[data-remove]');
            if (rm) {
                var row2 = rm.closest('.team-row');
                var mid2 = parseInt(row2.getAttribute('data-mid'), 10);
                var nameEl2 = row2.querySelector('.team-info__name');
                var name2 = nameEl2 ? nameEl2.textContent.trim() : 'this member';
                confirmAction({ title: 'Remove ' + name2 + '?', text: 'They lose access to your account and their seat is freed.', icon: 'warning', confirmButtonText: 'Remove' }).then(function (ok) {
                    if (!ok) { return; }
                    ApiDataSvc.apiCall('post', 'team_remove', { member_id: mid2 }, function (r) {
                        var o = parse(r);
                        if (!o || !o.success) { if (window.toastr) { toastr.error((o && o.message) || 'Could not remove'); } return; }
                        row2.parentNode.removeChild(row2);
                        if (window.toastr) { toastr.success('Member removed'); }
                        setTimeout(function () { window.location.reload(); }, 700);   // refresh seat meter
                    });
                });
            }
        });
    }
})();
