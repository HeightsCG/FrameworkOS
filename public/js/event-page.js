/* One event's page (/events/manage/<id>): attendee refund/remove, message attendees, inline settings, cancel, delete. */
(function () {
    var root = document.querySelector('.evm');
    if (!root) { return; }
    var event_id = root.getAttribute('data-event-id');
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function el(id) { return document.getElementById(id); }
    function toast(ok, msg) { if (window.toastr) { toastr[ok ? 'success' : 'error'](msg); } }
    function confirm_action(o) {
        if (!window.Swal) { return Promise.resolve(window.confirm(o.title)); }
        return Swal.fire({ title: o.title, text: o.text, icon: 'warning', showCancelButton: true, reverseButtons: true, focusCancel: true,
            confirmButtonText: o.button, cancelButtonText: o.cancel || 'Keep It', confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' })
            .then(function (r) { return r.isConfirmed; });
    }
    function call(action, data, done) {
        data.event_id = event_id;
        ApiDataSvc.apiCall('post', action, data, function (r) {
            var o = parse(r);
            if (!o || !o.success) { toast(false, (o && o.message) || 'Something went wrong. Try again.'); if (done) { done(null); } return; }
            if (done) { done(o); }
        });
    }

    /* ---- header ---- */
    var copy = el('evmCopyLink');
    if (copy) {
        copy.addEventListener('click', function () {
            var link = copy.getAttribute('data-link');
            (navigator.clipboard ? navigator.clipboard.writeText(link) : Promise.reject()).then(function () { toast(true, 'Event link copied'); }, function () { window.prompt('Copy the event link', link); });
        });
    }

    /* ---- attendees ---- */
    var list = el('evmAttendees');
    if (list) {
        list.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-refund], [data-remove]');
            if (!btn) { return; }
            var row = btn.closest('.evm-row'), name = row.getAttribute('data-name'), reg = row.getAttribute('data-reg');
            var refund = btn.hasAttribute('data-refund'), paid = (parseInt(row.getAttribute('data-paid'), 10) / 10).toFixed(2);
            confirm_action(refund
                ? { title: 'Refund ' + name + '?', text: '$' + paid + ' goes back to their wallet and your earnings from this ticket are reversed. They are no longer registered.', button: 'Refund $' + paid }
                : { title: 'Remove ' + name + '?', text: 'Their spot is freed and they are told. No money moves.', button: 'Remove' })
            .then(function (ok) {
                if (!ok) { return; }
                call(refund ? 'event_refund_attendee' : 'event_remove_attendee', { registration_id: reg }, function (o) {
                    if (o) { toast(true, refund ? 'Refunded' : 'Removed'); setTimeout(function () { location.reload(); }, 400); }
                });
            });
        });
    }

    /* ---- messages ---- */
    var body = el('evmMsgBody'), send = el('evmMsgSend');
    if (body && send) {
        var count = parseInt(send.getAttribute('data-count'), 10) || 0;
        body.addEventListener('input', function () { send.disabled = count === 0 || body.value.trim() === ''; });
        send.addEventListener('click', function () {
            var text = body.value.trim();
            if (text === '' || count === 0 || send.disabled) { return; }
            confirm_action({ title: 'Send to ' + count + (count === 1 ? ' attendee?' : ' attendees?'), text: 'It lands in their Inbox, and by email if they are offline.', button: 'Send', cancel: 'Cancel' })
            .then(function (ok) {
                if (!ok) { return; }
                send.disabled = true;
                call('event_message_send', { body: text }, function (o) {
                    if (!o) { send.disabled = false; return; }
                    toast(true, o.message); body.value = '';
                    setTimeout(function () { location.reload(); }, 500);
                });
            });
        });
    }

    /* ---- settings (inline editor) ---- */
    var settings = el('evmSettings');
    if (settings && window.EventEditor) {
        var editor = window.EventEditor(settings, {
            onSaved: function () { toast(true, 'Changes saved'); setTimeout(function () { location.reload(); }, 500); }   // header + list reflect the change
        });
        var data = null; try { data = JSON.parse(settings.getAttribute('data-ev')); } catch (x) {}
        editor.load(data);
        if (settings.classList.contains('is-readonly')) {
            settings.querySelectorAll('input, textarea, select, .cs-seg__opt').forEach(function (i) { if (!i.classList.contains('cs-ae__navselect')) { i.disabled = true; } });
        }
        var save = el('ev_save');
        if (save) { save.addEventListener('click', function () { editor.save(save); }); }
    }

    /* ---- danger zone ---- */
    var cancel_btn = el('evmCancelEvent');
    if (cancel_btn) {
        cancel_btn.addEventListener('click', function () {
            var going = parseInt(cancel_btn.getAttribute('data-going'), 10) || 0;
            confirm_action({ title: 'Cancel this event?', text: going > 0 ? 'Registration closes, paid tickets are refunded, and ' + (going === 1 ? 'the attendee is' : 'all ' + going + ' attendees are') + " told. This can't be undone." : "Registration closes. This can't be undone.", button: 'Cancel Event' })
            .then(function (ok) {
                if (!ok) { return; }
                cancel_btn.disabled = true;
                call('event_cancel_all', {}, function (o) {
                    if (!o) { cancel_btn.disabled = false; return; }
                    toast(true, 'Event canceled'); setTimeout(function () { location.href = '/events/manage/' + event_id + '?tab=attendees'; }, 500);
                });
            });
        });
    }
    var delete_btn = el('evmDeleteEvent');
    if (delete_btn) {
        delete_btn.addEventListener('click', function () {
            confirm_action({ title: 'Delete this event?', text: "The event and its registrations are removed. This can't be undone.", button: 'Delete Event' })
            .then(function (ok) {
                if (!ok) { return; }
                delete_btn.disabled = true;
                ApiDataSvc.apiCall('post', 'event_delete', { id: event_id }, function (r) {
                    var o = parse(r);
                    if (!o || !o.success) { delete_btn.disabled = false; toast(false, (o && o.message) || 'Could not delete the event'); return; }
                    location.href = '/events';
                });
            });
        });
    }
})();
