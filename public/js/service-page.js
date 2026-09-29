/* One service's page (/services/manage/<id>): live switch, edit (modal), copy link, export, delete (only before any
   booking), and the Buyers table — a bounded page at a time (service_buyers), with Message and Refund per row.
   On desktop the page is fitted to the window; the table scrolls, never the page. */
(function () {
    var root = document.querySelector('.evm[data-service-id]');
    if (!root) { return; }
    var service_id = root.getAttribute('data-service-id');
    var is_video = root.getAttribute('data-video') === '1';   // CLS Video: each booking has its own call
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
    function call(action, data, done) {
        data.service_id = service_id;
        ApiDataSvc.apiCall('post', action, data, function (r) {
            var o = parse(r);
            if (!o || !o.success) { toast(false, (o && o.message) || 'Something went wrong. Try again.'); if (done) { done(null); } return; }
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
    var live = el('svmLive');
    live.addEventListener('change', function () {
        var on = live.checked; live.disabled = true;
        call('service_set_live', { live: on ? 1 : 0 }, function (o) {
            live.disabled = false;
            if (!o) { live.checked = !on; return; }
            toast(true, on ? 'Service is live' : 'Service is hidden');
            setTimeout(function () { location.reload(); }, 400);
        });
    });

    /* ---- buyers: one page at a time, sized to the table region ---- */
    var box_el = el('svmBuyers'), rows_el = el('svmRows'), search = el('svmSearch');
    var st = { page: 1, per: 10, q: '', loaded_key: '', seq: 0 };
    function per_fit() {
        if (!box_el || !desktop()) { return 10; }
        var head = box_el.querySelector('thead'), h = box_el.clientHeight - (head ? head.offsetHeight : 0);
        return h > 0 ? Math.max(3, Math.min(50, Math.floor(h / 56))) : st.per;
    }
    function resize() {
        var per = per_fit(); if (per === st.per) { return false; }
        st.page = Math.floor(((st.page - 1) * st.per) / per) + 1; st.per = per; return true;
    }
    function msg_row(html) { return '<tr class="evm-tbl__msg"><td colspan="5">' + html + '</td></tr>'; }
    function row(b) {
        var name = b.name || b.handle || 'Buyer', initial = esc(name.charAt(0).toUpperCase());
        var av = b.avatar ? '<img class="evm-person__av" src="' + esc(b.avatar) + '" alt="">' : '<span class="evm-person__av evm-person__av--init" aria-hidden="true">' + initial + '</span>';
        var acts = '<div class="dropdown"><button type="button" class="evm-more" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="Actions for ' + esc(name) + '"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>'
            + '<ul class="dropdown-menu dropdown-menu-end evm-menu">'
            + (!b.delivered ? '<li><button type="button" class="dropdown-item" data-deliver>Mark Delivered</button></li>' : '')
            + (is_video ? '<li><a class="dropdown-item" href="/live/booking/' + b.id + '">Join Call</a></li>' : '')
            + '<li><a class="dropdown-item" href="/inbox/with/' + b.user_id + '">Message</a></li>'
            + (b.paid_credits > 0 ? '<li><button type="button" class="dropdown-item" data-refund>Refund ' + esc(b.paid) + '…</button></li>' : '') + '</ul></div>';
        return '<tr class="evm-row" data-purchase="' + b.id + '" data-name="' + esc(name) + '" data-paid="' + b.paid_credits + '">'
            + '<td><div class="evm-person">' + av + '<span class="evm-person__text"><span class="evm-person__name">' + esc(name) + '</span>'
            + (b.handle ? '<span class="evm-person__handle">@' + esc(b.handle) + '</span>' : '') + '</span></div></td>'
            + '<td class="evm-col-reg">' + esc(b.booked) + '</td><td class="evm-col-paid">' + esc(b.paid) + '</td>'
            + '<td><span class="evm-pill evm-pill--registered">' + esc(b.status_label) + '</span></td><td class="evm-col-act">' + acts + '</td></tr>';
    }
    function load(force) {
        if (!rows_el) { return; }
        var key = st.page + '|' + st.per + '|' + st.q;
        if (!force && key === st.loaded_key) { return; }
        var seq = ++st.seq;
        call('service_buyers', { page: st.page, per: st.per, q: st.q }, function (o) {
            if (seq !== st.seq) { return; }
            if (!o) { rows_el.innerHTML = msg_row('Could not load buyers. <button type="button" class="evm-link" data-retry>Try again</button>'); return; }
            st.page = o.page; st.loaded_key = o.page + '|' + st.per + '|' + st.q;
            rows_el.innerHTML = o.buyers.length ? o.buyers.map(row).join('')
                : (st.q !== '' ? msg_row('No buyers match “' + esc(st.q) + '”. <button type="button" class="evm-link" data-clear-search>Clear Search</button>') : msg_row('No active bookings right now.'));
            box_el.scrollTop = 0;
            var start = o.total ? (o.page - 1) * o.per + 1 : 0, end = Math.min(o.total, o.page * o.per);
            el('svmRange').textContent = o.total ? start + '–' + end + ' of ' + o.total + (o.total === 1 ? ' buyer' : ' buyers') : '0 buyers';
            el('svmPrev').disabled = o.page <= 1; el('svmNext').disabled = o.page >= o.pages;
        });
    }
    if (rows_el) {
        el('svmPrev').addEventListener('click', function () { if (st.page > 1) { st.page--; load(); } });
        el('svmNext').addEventListener('click', function () { st.page++; load(); });
        var t_search = null;
        search.addEventListener('input', function () { clearTimeout(t_search); t_search = setTimeout(function () { st.q = search.value.trim(); st.page = 1; load(); }, 250); });
        rows_el.addEventListener('click', function (e) {
            if (e.target.closest('[data-clear-search]')) { search.value = ''; st.q = ''; st.page = 1; load(); search.focus(); return; }
            if (e.target.closest('[data-retry]')) { load(true); return; }
            var del = e.target.closest('[data-deliver]');
            if (del) {   // pays the creator their share of this booking
                var dr = del.closest('.evm-row');
                confirm_action({ title: 'Mark ' + dr.getAttribute('data-name') + '’s booking delivered?', text: 'Your share of this booking is added to your balance.', button: 'Mark Delivered', color: '#CD4C00', cancel: 'Not Yet' })
                    .then(function (ok) {
                        if (!ok) { return; }
                        call('service_mark_delivered', { purchase_id: dr.getAttribute('data-purchase') }, function (o) { if (o) { toast(true, o.message); load(true); } });
                    });
                return;
            }
            var btn = e.target.closest('[data-refund]'); if (!btn) { return; }
            var r = btn.closest('.evm-row'), name = r.getAttribute('data-name'), paid = (parseInt(r.getAttribute('data-paid'), 10) / 10).toFixed(2);
            confirm_action({ title: 'Refund ' + name + '?', text: '$' + paid + ' goes back to their wallet and the booking is canceled.', button: 'Refund $' + paid })
                .then(function (ok) {
                    if (!ok) { return; }
                    call('service_refund_buyer', { purchase_id: r.getAttribute('data-purchase') }, function (o) { if (o) { toast(true, 'Refunded'); setTimeout(function () { location.reload(); }, 400); } });
                });
        });
    }

    /* ---- Edit Service (modal) ---- */
    var modal_el = el('serviceModal'), edit_btn = el('svmEdit');
    if (modal_el && window.ServiceEditor) {
        var modal = window.bootstrap ? new bootstrap.Modal(modal_el) : null;
        var editor = window.ServiceEditor(modal_el, {
            onTitle: function (t) { el('svModalSub').textContent = t !== '' ? t : 'Untitled service'; },
            onSaved: function () { toast(true, 'Changes saved'); setTimeout(function () { location.reload(); }, 400); }
        });
        edit_btn.addEventListener('click', function () {
            var data = null; try { data = JSON.parse(root.getAttribute('data-sv')); } catch (x) {}
            editor.load(data); if (modal) { modal.show(); }
        });
        modal_el.addEventListener('shown.bs.modal', function () { el('sv_name').focus(); });
        modal_el.addEventListener('hidden.bs.modal', function () { edit_btn.focus(); });
        el('sv_save').addEventListener('click', function () { editor.save(this); });
    }

    /* ---- export / delete ---- */
    root.addEventListener('click', function (e) { if (e.target.closest('[data-export]')) { el('svmCsv').submit(); } });
    var del = el('svmDelete');
    if (del) {
        del.addEventListener('click', function () {
            confirm_action({ title: 'Delete this service?', text: "It's removed from your profile and can't be restored.", button: 'Delete Service' }).then(function (ok) {
                if (!ok) { return; }
                ApiDataSvc.apiCall('post', 'service_delete', { id: service_id }, function (r) {
                    var o = parse(r);
                    if (!o || !o.success) { toast(false, (o && o.message) || 'Could not delete the service'); return; }
                    location.href = '/services';
                });
            });
        });
    }

    /* ---- start ---- */
    var t_resize = null;
    window.addEventListener('resize', function () { fit(); clearTimeout(t_resize); t_resize = setTimeout(function () { if (resize()) { load(); } }, 200); });
    var start = function () { fit(); resize(); load(); };
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(start); } else { start(); }
})();
