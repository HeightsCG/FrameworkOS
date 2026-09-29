/* One event's page (/events/manage/<id>): live switch, edit (modal), copy link, export, cancel/delete from the ⋯ menu,
   and the Attendees | Messages workspace. On desktop the page is fitted to the window; the workspace body scrolls,
   never the page. Attendees and messages come a bounded page at a time (event_attendees / event_messages). */
(function () {
    var root = document.querySelector('.evm');
    if (!root) { return; }
    var event_id = root.getAttribute('data-event-id');
    var canceled = root.getAttribute('data-canceled') === '1';
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function el(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function toast(ok, msg) { if (window.toastr) { toastr[ok ? 'success' : 'error'](msg); } }
    function confirm_action(o) {
        if (!window.Swal) { return Promise.resolve(window.confirm(o.title)); }
        return Swal.fire({ title: o.title, text: o.text, showCancelButton: true, reverseButtons: true, focusCancel: true,
            confirmButtonText: o.button, cancelButtonText: o.cancel || 'Keep It', confirmButtonColor: o.color || '#e5484d', cancelButtonColor: '#6b6779' })
            .then(function (r) { return r.isConfirmed; });
    }
    function call(action, data, done, quiet) {
        data.event_id = event_id;
        ApiDataSvc.apiCall('post', action, data, function (r) {
            var o = parse(r);
            if (!o || !o.success) { if (!quiet) { toast(false, (o && o.message) || 'Something went wrong. Try again.'); } if (done) { done(null, o); } return; }
            if (done) { done(o); }
        });
    }

    /* ---- fit the page to the window (desktop) ---- */
    var desktop = function () { return window.innerWidth >= 900 && window.innerHeight >= 600; };
    function fit() {
        if (!desktop()) { root.style.height = ''; return; }
        var content = root.parentElement, pb = content ? parseFloat(getComputedStyle(content).paddingBottom) || 0 : 0;
        root.style.height = Math.max(560, window.innerHeight - root.getBoundingClientRect().top - window.scrollY - pb) + 'px';
    }
    fit();

    /* ---- copy link: straight to the clipboard, no dialog ---- */
    function copy_link() {
        var link = root.getAttribute('data-link');
        var done = function () { toast(true, 'Link copied'); };
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(link).then(done, fallback); return; }
        fallback();
        function fallback() {
            var t = document.createElement('textarea');
            t.value = link; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
            document.body.appendChild(t); t.select();
            try { document.execCommand('copy'); done(); } catch (e) { toast(false, 'Could not copy the link'); }
            document.body.removeChild(t);
        }
    }
    root.addEventListener('click', function (e) { if (e.target.closest('[data-copy-link]')) { copy_link(); } });

    /* ---- live switch ---- */
    var live = el('evmLive');
    if (live) {
        live.addEventListener('change', function () {
            var on = live.checked;
            live.disabled = true;
            call('event_set_live', { live: on ? 1 : 0 }, function (o) {
                live.disabled = false;
                if (!o) { live.checked = !on; return; }
                toast(true, on ? 'Event is live' : 'Event is hidden');
                setTimeout(function () { location.reload(); }, 400);
            });
        });
    }

    /* ---- workspace tabs: Attendees | Messages. New Message only on the Messages history view. ---- */
    var tabs = [el('evmTabAtt'), el('evmTabMsg')];
    var new_msg = el('evmNewMsg'), history = el('evmHistory'), compose = el('evmCompose');
    var current = tabs[1].getAttribute('aria-selected') === 'true' ? 'messages' : 'attendees';
    function sync_new_msg() { if (new_msg) { new_msg.hidden = !(current === 'messages' && (!compose || compose.hidden)); } }
    function choose(tab, focus) {
        current = tab;
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === tab;
            t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1;
            el(t.getAttribute('aria-controls')).hidden = !on;
            if (on && focus) { t.focus(); }
        });
        sync_new_msg();
        try { var u = new URL(location.href); if (tab === 'messages') { u.searchParams.set('tab', 'messages'); } else { u.searchParams.delete('tab'); } history_replace(u); } catch (x) {}
        if (tab === 'attendees') { att_resize(); att_load(); } else if (!msg_loaded) { msg_load(1); }
    }
    function history_replace(u) { window.history.replaceState(null, '', u.pathname + u.search); }
    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { choose(t.getAttribute('data-tab'), false); });
        t.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }
            e.preventDefault(); choose(tabs[1 - i].getAttribute('data-tab'), true);
        });
    });

    /* ---- attendees: one page at a time, sized to the table region ---- */
    var rows_el = el('evmRows'), search = el('evmSearch');
    var att = { page: 1, per: 10, q: '', loaded_key: '', seq: 0 };
    var box_el = el('evmAttendees');
    function per_fit() {
        if (!box_el || !desktop()) { return 10; }
        var head = box_el.querySelector('thead'), h = box_el.clientHeight - (head ? head.offsetHeight : 0);
        return h > 0 ? Math.max(3, Math.min(50, Math.floor(h / 56))) : att.per;   // hidden panel: keep the current size
    }
    function msg_row(html) { return '<tr class="evm-tbl__msg"><td colspan="5">' + html + '</td></tr>'; }
    function att_row(a) {
        var name = a.name || a.handle || 'Attendee', initial = esc(name.charAt(0).toUpperCase());
        var av = a.avatar ? '<img class="evm-person__av" src="' + esc(a.avatar) + '" alt="">' : '<span class="evm-person__av evm-person__av--init" aria-hidden="true">' + initial + '</span>';
        var acts = '';
        if (!canceled && a.status === 'registered') {
            acts = '<div class="dropdown"><button type="button" class="evm-more" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="Actions for ' + esc(name) + '"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>'
                + '<ul class="dropdown-menu dropdown-menu-end evm-menu">'
                + (a.paid_credits > 0 ? '<li><button type="button" class="dropdown-item" data-refund>Refund ' + esc(a.paid) + '…</button></li>' : '')
                + '<li><button type="button" class="dropdown-item" data-remove>Remove…</button></li></ul></div>';
        }
        return '<tr class="evm-row" data-reg="' + a.id + '" data-name="' + esc(name) + '" data-paid="' + a.paid_credits + '">'
            + '<td><div class="evm-person">' + av + '<span class="evm-person__text"><span class="evm-person__name">' + esc(name) + '</span>'
            + (a.handle ? '<span class="evm-person__handle">@' + esc(a.handle) + '</span>' : '') + '</span></div></td>'
            + '<td class="evm-col-reg">' + esc(a.registered) + '</td>'
            + '<td class="evm-col-paid">' + esc(a.paid) + '</td>'
            + '<td><span class="evm-pill evm-pill--' + esc(a.status) + '">' + esc(a.status_label) + '</span></td>'
            + '<td class="evm-col-act">' + acts + '</td></tr>';
    }
    function att_resize() {   // page size follows the table region; keep the first visible row on screen
        var per = per_fit();
        if (per === att.per) { return false; }
        att.page = Math.floor(((att.page - 1) * att.per) / per) + 1; att.per = per;
        return true;
    }
    function att_load(force) {
        if (!rows_el) { return; }
        var key = att.page + '|' + att.per + '|' + att.q;
        if (!force && key === att.loaded_key) { return; }
        var seq = ++att.seq;
        rows_el.setAttribute('aria-busy', 'true');
        call('event_attendees', { page: att.page, per: att.per, q: att.q }, function (o) {
            if (seq !== att.seq) { return; }   // a newer search/page already went out
            rows_el.setAttribute('aria-busy', 'false');
            if (!o) { rows_el.innerHTML = msg_row('Could not load attendees. <button type="button" class="evm-link" data-att-retry>Try again</button>'); return; }
            att.page = o.page; att.loaded_key = o.page + '|' + att.per + '|' + att.q;
            if (!o.attendees.length) {
                rows_el.innerHTML = att.q !== '' ? msg_row('No attendees match “' + esc(att.q) + '”. <button type="button" class="evm-link" data-clear-search>Clear Search</button>')
                                                 : msg_row('No one is registered right now.');
            } else {
                rows_el.innerHTML = o.attendees.map(att_row).join('');
                box_el.scrollTop = 0;
            }
            var start = o.total ? (o.page - 1) * o.per + 1 : 0, end = Math.min(o.total, o.page * o.per);
            el('evmRange').textContent = o.total ? start + '–' + end + ' of ' + o.total + (o.total === 1 ? ' attendee' : ' attendees') : '0 attendees';
            el('evmPrev').disabled = o.page <= 1;
            el('evmNext').disabled = o.page >= o.pages;
        });
    }
    if (rows_el) {
        el('evmPrev').addEventListener('click', function () { if (att.page > 1) { att.page--; att_load(); } });
        el('evmNext').addEventListener('click', function () { att.page++; att_load(); });
        var t_search = null;
        search.addEventListener('input', function () {
            clearTimeout(t_search);
            t_search = setTimeout(function () { att.q = search.value.trim(); att.page = 1; att_load(); }, 250);
        });
        rows_el.addEventListener('click', function (e) {
            if (e.target.closest('[data-clear-search]')) { search.value = ''; att.q = ''; att.page = 1; att_load(); search.focus(); return; }
            if (e.target.closest('[data-att-retry]')) { att_load(true); return; }
            var btn = e.target.closest('[data-refund], [data-remove]');
            if (!btn) { return; }
            var row = btn.closest('.evm-row'), name = row.getAttribute('data-name'), reg = row.getAttribute('data-reg');
            var refund = btn.hasAttribute('data-refund'), paid = (parseInt(row.getAttribute('data-paid'), 10) / 10).toFixed(2);
            confirm_action(refund
                ? { title: 'Refund ' + name + '?', text: '$' + paid + ' goes back to their wallet and they are no longer registered.', button: 'Refund $' + paid }
                : { title: 'Remove ' + name + '?', text: 'Their spot is freed. No money moves.', button: 'Remove' })
            .then(function (ok) {
                if (!ok) { return; }
                call(refund ? 'event_refund_attendee' : 'event_remove_attendee', { registration_id: reg }, function (o) {
                    if (o) { toast(true, refund ? 'Refunded' : 'Removed'); setTimeout(function () { location.reload(); }, 400); }   // counts, earnings and the band all change
                });
            });
        });
    }

    /* ---- messages: history (default) and a focused composer ---- */
    var list_el = el('evmHistoryList'), msg_loaded = false, msg_page = 1;
    function msg_load(page) {
        msg_page = page;
        call('event_messages', { page: page, per: 10 }, function (o) {
            if (!o) { list_el.innerHTML = '<p class="evm-note">Could not load messages. <button type="button" class="evm-link" data-msg-retry>Try again</button></p>'; return; }
            msg_loaded = true; msg_page = o.page;
            if (!o.messages.length) { list_el.innerHTML = '<p class="evm-note">' + esc(root.getAttribute('data-msg-empty')) + '</p>'; el('evmMsgPager').hidden = true; return; }
            list_el.innerHTML = o.messages.map(function (m) {
                return '<article class="evm-msg"><time class="evm-msg__time">' + esc(m.sent_at) + '</time>'
                    + '<p class="evm-msg__body">' + esc(m.body) + '</p></article>';
            }).join('');
            list_el.scrollTop = 0;
            var pager = el('evmMsgPager'); pager.hidden = o.pages <= 1;
            el('evmMsgRange').textContent = 'Page ' + o.page + ' of ' + o.pages;
            el('evmMsgPrev').disabled = o.page <= 1;
            el('evmMsgNext').disabled = o.page >= o.pages;
        });
    }
    list_el.addEventListener('click', function (e) { if (e.target.closest('[data-msg-retry]')) { msg_load(msg_page); } });
    el('evmMsgPrev').addEventListener('click', function () { msg_load(msg_page - 1); });
    el('evmMsgNext').addEventListener('click', function () { msg_load(msg_page + 1); });

    var body = el('evmMsgBody'), send = el('evmMsgSend');
    function open_composer() { history.hidden = true; compose.hidden = false; sync_new_msg(); body.focus(); }
    function close_composer() { compose.hidden = true; history.hidden = false; sync_new_msg(); if (new_msg) { new_msg.focus(); } }   // the draft stays in the box
    if (new_msg && compose) {
        new_msg.addEventListener('click', open_composer);
        el('evmBack').addEventListener('click', close_composer);
        el('evmMsgCancel').addEventListener('click', close_composer);
        var count = parseInt(send.getAttribute('data-count'), 10) || 0, send_label = send.textContent;
        body.addEventListener('input', function () { send.disabled = count === 0 || body.value.trim() === ''; });
        send.addEventListener('click', function () {
            var text = body.value.trim();
            if (text === '' || count === 0 || send.disabled) { return; }
            send.disabled = true; send.textContent = 'Sending…';
            call('event_message_send', { body: text }, function (o) {
                send.textContent = send_label;
                if (!o) { send.disabled = false; return; }   // the error toast says why; the draft is kept
                toast(true, o.message); body.value = '';
                close_composer(); msg_load(1);
            });
        });
    }

    /* ---- Edit Event (modal) ---- */
    var modal_el = el('eventModal'), edit_btn = el('evmEdit');
    if (modal_el && edit_btn && window.EventEditor) {
        var modal = window.bootstrap ? new bootstrap.Modal(modal_el) : null;
        var editor = window.EventEditor(modal_el, {
            onTitle: function (t) { el('evModalSub').textContent = t !== '' ? t : 'Untitled event'; },
            onSaved: function () { toast(true, 'Changes saved'); setTimeout(function () { location.reload(); }, 400); }
        });
        edit_btn.addEventListener('click', function () {
            var data = null; try { data = JSON.parse(root.getAttribute('data-ev')); } catch (x) {}
            editor.load(data);
            if (modal) { modal.show(); }
        });
        modal_el.addEventListener('shown.bs.modal', function () { el('ev_title').focus(); });
        modal_el.addEventListener('hidden.bs.modal', function () { edit_btn.focus(); });
        el('ev_save').addEventListener('click', function () { editor.save(this); });
    }

    /* ---- export ---- */
    root.addEventListener('click', function (e) { if (e.target.closest('[data-export]')) { el('evmCsv').submit(); } });

    /* ---- ⋯ menu: cancel / delete ---- */
    var cancel_btn = el('evmCancelEvent');
    if (cancel_btn) {
        cancel_btn.addEventListener('click', function () {
            var going = parseInt(cancel_btn.getAttribute('data-going'), 10) || 0, paid = cancel_btn.getAttribute('data-paid') === '1';
            var text = going === 0 ? "Registration closes. This can't be undone."
                : (paid ? 'Paid tickets are refunded and ' : '') + (going === 1 ? 'the attendee is' : 'all ' + going + ' attendees are') + " told. This can't be undone.";
            confirm_action({ title: 'Cancel this event?', text: text, button: 'Cancel Event' }).then(function (ok) {
                if (!ok) { return; }
                call('event_cancel_all', {}, function (o) {
                    if (o) { toast(true, 'Event canceled'); setTimeout(function () { location.href = '/events/manage/' + event_id; }, 500); }
                });
            });
        });
    }
    var delete_btn = el('evmDeleteEvent');
    if (delete_btn) {
        delete_btn.addEventListener('click', function () {
            confirm_action({ title: 'Delete this event?', text: "The event and its registrations are removed. This can't be undone.", button: 'Delete Event' }).then(function (ok) {
                if (!ok) { return; }
                ApiDataSvc.apiCall('post', 'event_delete', { id: event_id }, function (r) {
                    var o = parse(r);
                    if (!o || !o.success) { toast(false, (o && o.message) || 'Could not delete the event'); return; }
                    location.href = '/events';
                });
            });
        });
    }

    /* ---- start ---- */
    var t_resize = null;
    window.addEventListener('resize', function () { fit(); clearTimeout(t_resize); t_resize = setTimeout(function () { if (current === 'attendees' && att_resize()) { att_load(); } }, 200); });
    sync_new_msg();
    var start = function () { fit(); att_resize(); if (current === 'attendees') { att_load(); } else { msg_load(1); } };
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(start); } else { start(); }

    /* ---- CLS Video: who is in the call (every 5 s while it's open and the page is visible) ---- */
    var stat = el('evmCallStat'), stat_timer = null;
    function call_status() {
        if (!stat || document.hidden) { return; }
        ApiDataSvc.apiCall('post', 'live_status', { id: stat.getAttribute('data-call') }, function (r) {
            var o = parse(r);
            var was = stat.hidden;
            if (!o || !o.success) { stat.hidden = true; if (!was) { fit(); } return; }
            if (!o.open) { stat.hidden = true; clearInterval(stat_timer); if (!was) { fit(); } return; }
            var n = o.people || 0, word = n === 1 ? 'person' : 'people';
            stat.hidden = false;
            if (was) { fit(); }   // the banner takes room: re-fit the page to the window
            stat.classList.toggle('is-active', n > 0);
            stat.textContent = n === 0 ? (o.host_in ? 'You’re in the call. No one else has joined yet.' : 'The call is open. No one has joined yet.')
                : (o.host_in ? n + ' ' + word + ' in the call with you.' : n + ' ' + word + (n === 1 ? ' is' : ' are') + ' waiting in the call.');
        });
    }
    if (stat) {
        call_status(); stat_timer = setInterval(call_status, 5000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { call_status(); } });
    }
})();
