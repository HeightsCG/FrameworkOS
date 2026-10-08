/* Cross-promotion (/promote): tabs, the Find Creators filter, the Request Swap modal and the row menus. */
(function () {
    function call(action, data, done) {
        ApiDataSvc.apiCall('post', action, data, function (r) {
            var o = null; try { o = JSON.parse(r); } catch (e) {}
            if (!o || !o.success) { toastr.error((o && o.message) || 'Something went wrong. Please try again.'); done(null); return; }
            done(o);
        });
    }
    function reload_after(o, tab) {
        toastr.success(o.message);
        setTimeout(function () { history.replaceState(null, '', '/promote' + (tab ? '#' + tab : '')); window.location.reload(); }, 400);
    }

    /* ---- opt in from the empty state ---- */
    var opt = document.getElementById('prOptIn');
    if (opt) {
        opt.addEventListener('click', function () {
            opt.disabled = true;
            call('promo_opt_in', { on: 1 }, function (o) { if (!o) { opt.disabled = false; return; } reload_after(o); });
        });
    }

    /* ---- tabs ---- */
    var tabs = document.querySelectorAll('[data-pr-tab]');
    function show(name) {
        var found = false;
        tabs.forEach(function (t) { if (t.getAttribute('data-pr-tab') === name) { found = true; } });
        if (!found) { return; }
        tabs.forEach(function (t) { t.setAttribute('aria-selected', t.getAttribute('data-pr-tab') === name ? 'true' : 'false'); });
        document.querySelectorAll('[data-pr-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-pr-panel') !== name; });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-pr-tab')); history.replaceState(null, '', '#' + t.getAttribute('data-pr-tab')); }); });
    if (location.hash) { show(location.hash.slice(1)); }

    /* ---- Find Creators: niche + name / handle filter ---- */
    var search = document.getElementById('prSearch'), niche = document.getElementById('prNiche'), none = document.getElementById('prFindNone');
    function filter() {
        var q = (search.value || '').trim().toLowerCase().replace(/^@/, ''), cat = niche.value, n = 0;
        document.querySelectorAll('#prFindRows .pr-row').forEach(function (r) {
            var hit = (cat === '' || r.getAttribute('data-niche') === cat)
                && (q === '' || r.getAttribute('data-name').toLowerCase().indexOf(q) !== -1 || r.getAttribute('data-handle').toLowerCase().indexOf(q) !== -1);
            r.hidden = !hit; if (hit) { n++; }
        });
        if (none && document.querySelectorAll('#prFindRows .pr-row').length) { none.hidden = n > 0; }
    }
    if (search && niche) {
        search.addEventListener('input', filter);
        niche.addEventListener('change', filter);
        document.addEventListener('click', function (ev) {
            if (ev.target.closest('#prClear')) { niche.value = ''; search.value = ''; filter(); }
        });
        filter();
    }

    /* ---- Request Swap modal ---- */
    var modal_el = document.getElementById('prModal'), modal = null, partner = 0;
    function open_request(row) {
        if (!modal_el) { return; }
        partner = parseInt(row.getAttribute('data-id'), 10) || 0;
        document.getElementById('prModalWho').textContent = row.getAttribute('data-name') + ' (@' + row.getAttribute('data-handle') + ')';
        document.getElementById('prNote').value = '';
        pick_days(document.querySelector('#prDays [data-days]'));
        modal = modal || new bootstrap.Modal(modal_el);
        modal.show();
    }
    function pick_days(btn) {
        document.querySelectorAll('#prDays [data-days]').forEach(function (b) { var on = b === btn; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    }
    document.querySelectorAll('#prDays [data-days]').forEach(function (b) { b.addEventListener('click', function () { pick_days(b); }); });
    var send = document.getElementById('prSend');
    if (send) {
        send.addEventListener('click', function () {
            var on = document.querySelector('#prDays .is-on');
            send.disabled = true;
            call('promo_request', { partner_id: partner, days: on ? on.getAttribute('data-days') : 7, note: document.getElementById('prNote').value }, function (o) {
                send.disabled = false;
                if (!o) { return; }
                modal.hide();
                reload_after(o, 'requests');
            });
        });
    }

    /* ---- rows and row menus ---- */
    document.addEventListener('click', function (ev) {
        var t = ev.target;
        var req = t.closest('[data-pr-request]');
        if (req) { open_request(req.closest('tr')); return; }
        var row = t.closest('#prFindRows .pr-row.is-open');
        if (row && !t.closest('.dropdown, a, button')) { open_request(row); return; }

        var resp = t.closest('[data-pr-respond]');
        if (resp) {
            var tr = resp.closest('tr'), id = tr.getAttribute('data-swap'), answer = resp.getAttribute('data-pr-respond');
            var go = function () { call('promo_respond', { id: id, answer: answer }, function (o) { if (o) { reload_after(o, answer === 'accept' ? 'active' : 'requests'); } }); };
            if (answer === 'accept') { go(); return; }
            Swal.fire({ title: 'Decline this request?', text: tr.getAttribute('data-name') + ' will be told you declined.', showCancelButton: true, reverseButtons: true,
                confirmButtonText: 'Decline', confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' })
                .then(function (r) { if (r.isConfirmed) { go(); } });
            return;
        }
        var end = t.closest('[data-pr-end]');
        if (end) {
            var er = end.closest('tr'), active = !!er.closest('[data-pr-panel="active"]');
            Swal.fire({ title: active ? 'End this swap?' : 'Withdraw this request?',
                text: active ? 'You both stop featuring each other now, and ' + er.getAttribute('data-name') + ' is told.' : er.getAttribute('data-name') + ' is told you withdrew it.',
                showCancelButton: true, reverseButtons: true, confirmButtonText: active ? 'End Swap' : 'Withdraw', confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' })
                .then(function (r) { if (r.isConfirmed) { call('promo_end', { id: er.getAttribute('data-swap') }, function (o) { if (o) { reload_after(o, active ? 'past' : 'requests'); } }); } });
        }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter') { return; }
        var row = ev.target.closest && ev.target.closest('#prFindRows .pr-row.is-open');
        if (row && ev.target === row) { open_request(row); }
    });
})();
