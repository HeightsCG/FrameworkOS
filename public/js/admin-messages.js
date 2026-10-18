/* Admin messaging as Creator Link Studio: the Message dialog ([data-message-user] on the user page and the Users row menu)
   and the team inbox at /admin/messages (conversation list, thread, reply). */
(function () {
    function parse(r) { try { return typeof r === 'string' ? JSON.parse(r) : r; } catch (e) { return null; } }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function call(action, data, done) {
        ApiDataSvc.apiCall('post', action, data, function (r) {
            var o = parse(r);
            if (!o || !o.success) { toastr.error((o && o.message) || 'Could not reach the server. Try again.'); done(null); return; }
            done(o);
        });
    }

    /* ---- Message dialog ---- */
    var modal_el = document.getElementById('admMsgModal');
    if (modal_el) {
        var modal = window.bootstrap ? new bootstrap.Modal(modal_el) : null;
        var body_el = document.getElementById('admMsgBody'), send_el = document.getElementById('admMsgSend'), to_user = 0;
        document.addEventListener('click', function (e) {
            var b = e.target.closest('[data-message-user]'); if (!b) { return; }
            to_user = b.getAttribute('data-message-user');
            document.getElementById('admMsgTo').textContent = b.getAttribute('data-name') || 'this user';
            document.getElementById('admMsgTitle').textContent = 'Message ' + (b.getAttribute('data-name') || '');
            body_el.value = ''; body_el.classList.remove('is-invalid');
            if (modal) { modal.show(); }
        });
        modal_el.addEventListener('shown.bs.modal', function () { body_el.focus(); });
        body_el.addEventListener('input', function () { body_el.classList.remove('is-invalid'); });
        send_el.addEventListener('click', function () {
            var text = body_el.value.trim();
            if (text === '') { body_el.classList.add('is-invalid'); body_el.focus(); return; }
            send_el.disabled = true;
            call('admin_message_send', { user_id: to_user, body: text }, function (o) {
                send_el.disabled = false;
                if (!o) { return; }
                if (modal) { modal.hide(); }
                toastr.success('Message sent. <a href="/admin/messages?c=' + o.conversation_id + '">Open conversation</a>', '', { escapeHtml: false });
            });
        });
    }

    /* ---- Team inbox ---- */
    var root = document.getElementById('admInbox');
    if (!root) { return; }
    var items_el = document.getElementById('admInboxItems'), thread_el = document.getElementById('admInboxThread'), none_el = document.getElementById('admInboxNone');
    var msgs_el = document.getElementById('admInboxMsgs'), reply_el = document.getElementById('admInboxBody'), reply_btn = document.getElementById('admInboxSend');
    var current = parseInt(root.getAttribute('data-open'), 10) || 0, convs = [];

    function when(iso, full) {
        if (!iso) { return ''; }
        var d = new Date(iso), now = new Date();
        if (!full && d.toDateString() === now.toDateString()) { return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
        return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' })
            + (full ? ', ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '');
    }
    function avatar(name, url, size) {
        var s = 'width:' + size + 'px;height:' + size + 'px';
        if (url) { return '<span class="adm-av adm-av--img" style="' + s + ';background-image:url(\'' + esc(url) + '\')"></span>'; }
        var init = String(name || '?').replace(/^@/, '').charAt(0).toUpperCase() || '?';
        return '<span class="adm-av adm-av--t5" style="' + s + '">' + esc(init) + '</span>';
    }

    // The Users tab and Messages sub-tab badges were counted when the page loaded: keep them in step with what's unread now.
    function sync_badges() {
        var n = convs.reduce(function (t, c) { return t + (c.unread > 0 ? c.unread : 0); }, 0);
        [document.querySelector('.adm-nav__a[href="/admin/users"]'), document.querySelector('.adm-subnav__a[href="/admin/messages"]')].forEach(function (a, i) {
            if (!a) { return; }
            var b = a.querySelector('b');
            if (n === 0) { if (b) { b.remove(); } return; }
            if (!b) { a.insertAdjacentHTML('beforeend', ' <b' + (i === 0 ? ' class="adm-nav__n"' : '') + '></b>'); b = a.querySelector('b'); }
            b.textContent = n;
        });
    }
    function render_list() {
        sync_badges();
        items_el.setAttribute('aria-busy', 'false');
        if (!convs.length) {
            items_el.innerHTML = '<div class="adm-empty"><i class="fa-regular fa-comments" aria-hidden="true"></i><p>No messages yet</p><p class="adm-inbox__hint">Message a user from their account page or the Users list.</p></div>';
            return;
        }
        items_el.innerHTML = convs.map(function (c) {
            return '<button type="button" class="adm-inbox__item' + (c.id === current ? ' is-on' : '') + (c.unread > 0 ? ' is-unread' : '') + '" data-conv="' + c.id + '"'
                + (c.id === current ? ' aria-current="true"' : '') + '>'
                + avatar(c.name, c.avatar, 36)
                + '<span class="adm-inbox__item-t"><span class="adm-inbox__item-top"><b>' + esc(c.name) + '</b><time>' + esc(when(c.last_at)) + '</time></span>'
                + '<span class="adm-inbox__item-p">' + (c.last_mine ? 'You: ' : '') + esc(c.preview) + '</span></span>'
                + (c.unread > 0 ? '<span class="adm-inbox__dot" aria-label="' + c.unread + ' unread">' + c.unread + '</span>' : '')
                + '</button>';
        }).join('');
    }
    function load_list(then) {
        call('admin_message_inbox', {}, function (o) {
            if (!o) { if (!convs.length) { items_el.innerHTML = '<p class="adm-quiet adm-inbox__err">Could not load conversations. <button type="button" class="adm-link" data-inbox-retry>Try Again</button></p>'; } return; }
            convs = o.conversations || [];
            render_list();
            if (then) { then(); }
        });
    }

    function bubble(m) {
        return '<div class="adm-inbox__msg' + (m.mine ? ' is-mine' : '') + '"><p>' + esc(m.body).replace(/\n/g, '<br>')
            + (m.media > 0 ? '<span class="adm-inbox__media">' + m.media + (m.media === 1 ? ' attachment' : ' attachments') + '</span>' : '')
            + '</p><time>' + esc(when(m.at, true)) + '</time></div>';
    }
    function open(id, quiet) {
        current = id;
        none_el.hidden = true; thread_el.hidden = false; root.classList.add('is-open');
        if (!quiet) { msgs_el.innerHTML = '<p class="adm-quiet">Loading…</p>'; }
        if (history.replaceState) { history.replaceState(null, '', '/admin/messages?c=' + id); }
        render_list();
        call('admin_message_thread', { conversation_id: id }, function (o) {
            if (id !== current) { return; }
            if (!o) { msgs_el.innerHTML = '<p class="adm-quiet">Could not load this conversation.</p>'; return; }
            var u = o.user;
            document.getElementById('admInboxWho').innerHTML = '<span class="adm-who">' + avatar(u.name, u.avatar, 32) + '<span class="adm-who__t"><span class="adm-who__n">'
                + esc(u.name) + '</span>' + (u.handle ? '<span class="adm-who__s">@' + esc(u.handle) + '</span>' : '') + '</span></span>';
            document.getElementById('admInboxAccount').href = '/admin/user/' + u.id;
            var near_bottom = msgs_el.scrollHeight - msgs_el.scrollTop - msgs_el.clientHeight < 80;
            msgs_el.innerHTML = o.messages.length ? o.messages.map(bubble).join('') : '<p class="adm-quiet">No messages in this conversation.</p>';
            if (!quiet || near_bottom) { msgs_el.scrollTop = msgs_el.scrollHeight; }
            convs.forEach(function (c) { if (c.id === id) { c.unread = 0; } });
            render_list();
            if (!quiet) { reply_el.focus(); }
        });
    }

    items_el.addEventListener('click', function (e) {
        if (e.target.closest('[data-inbox-retry]')) { load_list(); return; }
        var b = e.target.closest('[data-conv]'); if (b) { open(parseInt(b.getAttribute('data-conv'), 10)); }
    });
    document.getElementById('admInboxBack').addEventListener('click', function () { root.classList.remove('is-open'); });

    reply_el.addEventListener('input', function () { reply_btn.disabled = reply_el.value.trim() === ''; });
    reply_el.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); document.getElementById('admInboxCompose').requestSubmit(); } });
    document.getElementById('admInboxCompose').addEventListener('submit', function (e) {
        e.preventDefault();
        var text = reply_el.value.trim();
        if (text === '' || !current) { return; }
        reply_btn.disabled = true; reply_el.disabled = true;
        call('admin_message_send', { conversation_id: current, body: text }, function (o) {
            reply_el.disabled = false;
            if (!o) { reply_btn.disabled = false; return; }
            reply_el.value = '';
            if (o.sent) {
                var empty = msgs_el.querySelector('.adm-quiet'); if (empty) { empty.remove(); }
                msgs_el.insertAdjacentHTML('beforeend', bubble(o.sent)); msgs_el.scrollTop = msgs_el.scrollHeight;
            }
            reply_el.focus();
            load_list();
        });
    });

    load_list(function () {
        if (current && convs.some(function (c) { return c.id === current; })) { open(current); }
        else { current = 0; render_list(); }
    });
    // New replies show up without a reload while the page is open and visible.
    setInterval(function () {
        if (document.hidden) { return; }
        load_list(function () { if (current) { var c = convs.filter(function (x) { return x.id === current; })[0]; if (c && c.unread > 0) { open(current, true); } } });
    }, 20000);
})();
