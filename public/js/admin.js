/* Admin pages (/admin/*): queue actions, counts, section index, user search and suspend, financial charts. */
(function () {
    var root = document.querySelector('.adm');
    if (!root) { return; }
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function confirmAction(opts) {
        if (window.Swal) {
            return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, focusCancel: true,
                confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' }, opts)).then(function (res) { return res.isConfirmed; });
        }
        return Promise.resolve(window.confirm(opts.title || opts.titleText || 'Are you sure?'));
    }

    /* ---- Counts: a resolved item lowers its panel count and the tab pill ---- */
    function bump(group, delta) {
        var total = 0;
        document.querySelectorAll('[data-count]').forEach(function (el) {
            if (el.getAttribute('data-count') === group) { el.textContent = Math.max(0, parseInt(el.textContent, 10) + delta); }
            total += parseInt(el.textContent, 10) || 0;
        });
        var rail = document.querySelector('.adm-nav__a.is-on .adm-nav__n');
        if (rail) { var n = Math.max(0, parseInt(rail.textContent, 10) + delta); if (n > 0) { rail.textContent = n; } else { rail.parentNode.removeChild(rail); } }
    }
    window.admBump = bump;

    /* ---- Age checks: status switch + reset ---- */
    var ageTabs = document.getElementById('admAgeTabs');
    if (ageTabs) {
        ageTabs.addEventListener('click', function (e) {
            var b = e.target.closest('[data-age]'); if (!b) { return; }
            var f = b.getAttribute('data-age'), shown = 0;
            ageTabs.querySelectorAll('[data-age]').forEach(function (x) { var on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
            document.querySelectorAll('#admAge .adm-agerow').forEach(function (r) {
                var st = r.getAttribute('data-status');
                var on = f === 'all' || st === f || (f === 'open' && st !== 'verified');
                r.hidden = !on; if (on) { shown++; }
            });
            var none = document.getElementById('admAgeNone'); if (none) { none.hidden = shown > 0; none.textContent = f === 'open' ? 'No age checks need a look.' : 'Nothing here.'; }
        });
    }
    var ageBody = document.getElementById('admAge');
    if (ageBody) {
        ageBody.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-age-reset]'); if (!btn) { return; }
            var row = btn.closest('.adm-agerow'), uid = parseInt(row.getAttribute('data-age-row'), 10);
            var who = row.querySelector('.adm-t__main a'); who = who ? who.textContent.trim() : 'this account';
            confirmAction({ title: 'Reset age verification for ' + who + '?', text: 'The verification is removed and adult content is hidden for this account again. The next time they turn it on, or publish an adult post, they verify again.', icon: 'warning', confirmButtonText: 'Reset', confirmButtonColor: '#CD4C00' })
                .then(function (ok) {
                    if (!ok) { return; }
                    ApiDataSvc.apiCall('post', 'admin_reset_age_verification', { user_id: uid }, function (r) {
                        var o = parse(r);
                        if (!o || !o.success) { toastr.error((o && o.message) || 'Could not reset'); return; }
                        var was = row.getAttribute('data-status');
                        row.parentNode.removeChild(row);
                        toastr.success('Age verification reset');
                        if (was === 'pending') { bump('verification', -1); }
                        ageTabs.querySelectorAll('[data-age]').forEach(function (x) {
                            var k = x.getAttribute('data-age'), b = x.querySelector('b');
                            if (b && (k === 'all' || k === was || (k === 'open' && was !== 'verified'))) { b.textContent = Math.max(0, parseInt(b.textContent, 10) - 1); }
                        });
                        if (!ageBody.querySelectorAll('.adm-agerow:not([hidden])').length) { var none = document.getElementById('admAgeNone'); if (none) { none.hidden = false; } }
                    });
                });
        });
    }

    /* ---- Tables: search box, filter buttons, sortable headers, row click opens the detail page ---- */
    function table_rows(t) { return Array.prototype.slice.call(t.tBodies.length ? t.tBodies[0].rows : []); }
    function table_state(t) { if (!t.__adm) { t.__adm = { q: '', f: 'all', attr: '', page: 1, size: parseInt(t.getAttribute('data-page-size') || '25', 10) }; } return t.__adm; }
    var PAGER_LABELS = { admActivity: 'events', admUsers: 'users', admSales: 'sales', admBilling: 'accounts', admAudit: 'actions', admLeads: 'leads', admFounding: 'claims', admPublished: 'articles', admKeywordsT: 'keywords' };
    function table_pager(t, total, from, to) {
        if (!t.hasAttribute('data-pager')) { return; }
        var bar = t.nextElementSibling && t.nextElementSibling.classList && t.nextElementSibling.classList.contains('adm-pager') ? t.nextElementSibling : null;
        if (!bar) { bar = document.createElement('div'); bar.className = 'adm-pager'; t.parentNode.insertBefore(bar, t.nextSibling); }
        var st = table_state(t), pages = Math.max(1, Math.ceil(total / st.size)), what = PAGER_LABELS[t.id] || 'rows';
        bar.innerHTML = '<span class="adm-pager__n">' + (total ? (from + '–' + to + ' of ' + total) : '0') + ' ' + what + '</span>' +
            '<span class="adm-pager__b"><button type="button" class="adm-btn adm-btn--sm" data-page="prev"' + (st.page <= 1 ? ' disabled' : '') + '>Previous</button><button type="button" class="adm-btn adm-btn--sm" data-page="next"' + (st.page >= pages ? ' disabled' : '') + '>Next</button></span>';
        if (!bar.__bound) { bar.__bound = true; bar.addEventListener('click', function (e) { var b = e.target.closest('[data-page]'); if (!b || b.disabled) { return; } st.page += b.getAttribute('data-page') === 'next' ? 1 : -1; table_apply(t, true); t.scrollIntoView({ block: 'start', behavior: 'smooth' }); }); }
        bar.hidden = total <= st.size && st.page === 1;
    }
    function table_apply(t, keepPage) {
        var st = table_state(t), match = [];
        if (!keepPage) { st.page = 1; }
        table_rows(t).forEach(function (r) {
            if (r.classList.contains('adm-t__total')) { return; }
            var okq = st.q === '' || ((r.getAttribute('data-search') || '') + ' ' + r.textContent).toLowerCase().indexOf(st.q) !== -1;
            var okf = st.f === 'all' || st.attr === '' || (' ' + (r.getAttribute(st.attr) || '') + ' ').indexOf(' ' + st.f + ' ') !== -1;
            var ok = okq && okf; r.hidden = !ok; if (ok) { match.push(r); }
        });
        if (t.hasAttribute('data-pager')) {
            var pages = Math.max(1, Math.ceil(match.length / st.size)); if (st.page > pages) { st.page = pages; }
            var from = (st.page - 1) * st.size;
            match.forEach(function (r, i) { if (i < from || i >= from + st.size) { r.hidden = true; } });
            table_pager(t, match.length, match.length ? from + 1 : 0, Math.min(match.length, from + st.size));
        }
        var none = document.getElementById(t.id + 'None'); if (none) { none.hidden = match.length > 0; }
    }
    document.querySelectorAll('table[data-pager]').forEach(function (t) { table_apply(t); });
    document.querySelectorAll('[data-search-for]').forEach(function (inp) {
        var t = document.getElementById(inp.getAttribute('data-search-for')); if (!t) { return; }
        inp.addEventListener('input', function () { table_state(t).q = inp.value.trim().toLowerCase(); table_apply(t); });
        if (inp.form) { inp.form.addEventListener('submit', function (e) { if (inp.value.trim() === '' && !inp.form.querySelector('.adm-search__clear')) { e.preventDefault(); } }); }
    });
    document.querySelectorAll('[data-filter-for]').forEach(function (bar) {
        var t = document.getElementById(bar.getAttribute('data-filter-for')); if (!t) { return; }
        bar.addEventListener('click', function (e) {
            var b = e.target.closest('[data-filter]'); if (!b) { return; }
            bar.querySelectorAll('[data-filter]').forEach(function (x) { var on = x === b; x.classList.toggle('is-on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
            var st = table_state(t); st.f = b.getAttribute('data-filter'); st.attr = bar.getAttribute('data-filter-attr') || ''; table_apply(t);
        });
    });
    document.querySelectorAll('table[data-sortable]').forEach(function (t) {
        var ths = t.tHead ? t.tHead.querySelectorAll('th[data-sort]') : [];
        ths.forEach(function (th) {
            th.tabIndex = 0; th.setAttribute('role', 'button');
            function go() {
                var idx = th.cellIndex, kind = th.getAttribute('data-sort'), dir = th.getAttribute('data-sorted') === 'asc' ? 'desc' : 'asc';
                ths.forEach(function (x) { x.removeAttribute('data-sorted'); });
                th.setAttribute('data-sorted', dir);
                var rows = table_rows(t), total = rows.filter(function (r) { return r.classList.contains('adm-t__total'); });
                rows = rows.filter(function (r) { return !r.classList.contains('adm-t__total'); });
                function key(r) { var c = r.cells[idx]; if (!c) { return ''; } var v = c.getAttribute('data-value'); if (v === null) { v = c.textContent.trim(); } return kind === 'num' ? (parseFloat(v.replace(/[^0-9.\-]/g, '')) || 0) : v.toLowerCase(); }
                rows.sort(function (a, b) { var ka = key(a), kb = key(b); var c = ka < kb ? -1 : (ka > kb ? 1 : 0); return dir === 'asc' ? c : -c; });
                var body = t.tBodies[0]; rows.concat(total).forEach(function (r) { body.appendChild(r); });
                if (t.hasAttribute('data-pager')) { table_apply(t, true); }
            }
            th.addEventListener('click', go);
            th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
        });
    });
    document.addEventListener('click', function (e) {
        var row = e.target.closest('tr.adm-t__link[data-href]'); if (!row) { return; }
        if (e.target.closest('a, button, input, select, label, .dropdown')) { return; }
        if (window.getSelection && String(window.getSelection()).length) { return; }
        var href = row.getAttribute('data-href');
        if (e.metaKey || e.ctrlKey) { window.open(href, '_blank'); } else { window.location.href = href; }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        var row = e.target.closest ? e.target.closest('tr.adm-t__link[data-href]') : null;
        if (row && e.target === row) { window.location.href = row.getAttribute('data-href'); }
    });
    document.querySelectorAll('tr.adm-t__link[data-href]').forEach(function (r) { if (!r.hasAttribute('tabindex')) { r.tabIndex = 0; } });
    var wantTab = (new URLSearchParams(location.search).get('tab') || '').replace(/[^a-z]/g, '');
    if (wantTab && document.getElementById(wantTab) && document.querySelector('[data-admin-user]')) { setTimeout(function () { document.getElementById(wantTab).scrollIntoView({ block: 'start', behavior: 'smooth' }); }, 50); }

    /* ---- Support queue sub-tabs ---- */
    var supPanel = document.querySelector('.adm-panel[data-panel="support"]');
    if (supPanel) {
        supPanel.querySelectorAll('[data-sup]').forEach(function (b) {
            b.addEventListener('click', function () {
                var f = b.getAttribute('data-sup'), shown = 0;
                supPanel.querySelectorAll('[data-sup]').forEach(function (x) { var on = x === b; x.classList.toggle('is-active', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
                supPanel.querySelectorAll('.adm-suprow').forEach(function (r) { var on = f === 'all' || r.getAttribute('data-status') === f; r.hidden = !on; if (on) { shown++; } });
                document.getElementById('admSupportNone').hidden = shown > 0;
            });
        });
    }

    /* ---- Moderation queue: click a card image to see it large (same viewer as the influencer photos) ---- */
    var zoom = { list: [], at: 0, from: null };
    function zoom_list() { return $('#admMod .adm-card__img[data-full]').map(function () { return $(this).attr('data-full'); }).get(); }
    function zoom_show(i) {
        zoom.list = zoom_list();
        if (!zoom.list.length) { zoom_close(); return; }
        zoom.at = (i + zoom.list.length) % zoom.list.length;
        $('#adm_zoom_img').attr('src', zoom.list[zoom.at]);
        $('#adm_zoom_n').text((zoom.at + 1) + ' of ' + zoom.list.length);
        $('#adm_zoom .adm-zoom__nav, #adm_zoom_n').prop('hidden', zoom.list.length < 2);
        $('#adm_zoom').prop('hidden', false);
        $('#adm_zoom_close').trigger('focus');
    }
    function zoom_close() { $('#adm_zoom').prop('hidden', true); $('#adm_zoom_img').attr('src', ''); if (zoom.from) { $(zoom.from).trigger('focus'); } }
    $(document).on('click keydown', '#admMod .adm-card__img[data-full]', function (e) {
        if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') { return; }
        e.preventDefault();
        if (!$('#adm_zoom').length) {
            $('body').append('<div class="adm-zoom" id="adm_zoom" role="dialog" aria-modal="true" aria-label="Photo" hidden>' +
                '<button type="button" class="adm-zoom__btn adm-zoom__close" id="adm_zoom_close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>' +
                '<button type="button" class="adm-zoom__btn adm-zoom__nav adm-zoom__nav--prev" data-step="-1" aria-label="Previous photo"><i class="fa-solid fa-chevron-left"></i></button>' +
                '<img id="adm_zoom_img" alt="">' +
                '<button type="button" class="adm-zoom__btn adm-zoom__nav adm-zoom__nav--next" data-step="1" aria-label="Next photo"><i class="fa-solid fa-chevron-right"></i></button>' +
                '<span class="adm-zoom__n" id="adm_zoom_n"></span></div>');
            $('#adm_zoom').on('click', function (ev) { if (ev.target === this) { zoom_close(); } });
            $('#adm_zoom_close').on('click', zoom_close);
            $('#adm_zoom').on('click', '[data-step]', function () { zoom_show(zoom.at + parseInt($(this).data('step'), 10)); });
            $(document).on('keydown', function (ev) {
                if ($('#adm_zoom').prop('hidden')) { return; }
                if (ev.key === 'Escape') { zoom_close(); } else if (ev.key === 'ArrowLeft') { zoom_show(zoom.at - 1); } else if (ev.key === 'ArrowRight') { zoom_show(zoom.at + 1); }
            });
        }
        zoom.from = this;
        zoom_show(zoom_list().indexOf($(this).attr('data-full')));
    });

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
                bump('moderation', -1);
                var remaining = mod.querySelectorAll('.adm-card').length;
                if (!remaining) {
                    var wrap = document.getElementById('admMod');
                    wrap.outerHTML = '<p class="adm-quiet">Nothing to review.</p>';
                }
            });
        });
    }

    /* ---- Reports ---- */
    var reports = document.getElementById('admReports');
    if (reports) {
        reports.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-report-action]');
            if (!btn) { return; }
            var row = btn.closest('.adm-rrow');
            var rid = parseInt(row.getAttribute('data-report'), 10);
            var action = btn.getAttribute('data-report-action');
            var proceed;
            if (action === 'remove') {
                proceed = confirmAction({ title: 'Remove this content?', text: 'The post is blocked and hidden everywhere. This resolves the report.', icon: 'warning', confirmButtonText: 'Remove' });
            } else if (action === 'suspend') {
                proceed = confirmAction({ title: 'Suspend this account?', text: 'They\'re blocked from signing in. This resolves the report.', icon: 'warning', confirmButtonText: 'Suspend' });
            } else {
                proceed = Promise.resolve(true);
            }
            proceed.then(function (ok) {
                if (!ok) { return; }
                row.querySelectorAll('.adm-btn').forEach(function (b) { b.disabled = true; });
                ApiDataSvc.apiCall('post', 'report_resolve', { report_id: rid, action: action }, function (r) {
                    var o = parse(r);
                    if (!o || !o.success) {
                        row.querySelectorAll('.adm-btn').forEach(function (b) { b.disabled = false; });
                        if (window.toastr) { toastr.error((o && o.message) || 'Could not resolve report'); }
                        return;
                    }
                    row.parentNode.removeChild(row);
                    if (window.toastr) { toastr.success(action === 'dismiss' ? 'Report dismissed' : (action === 'remove' ? 'Content removed' : 'Account suspended')); }
                    bump('reports', -1);
                    var remaining = reports.querySelectorAll('.adm-rrow').length;
                    if (!remaining) { var n = document.getElementById('admReportsNone'); if (n) { n.hidden = false; } }
                });
            });
        });
    }

    /* ---- Verification ---- */
    var verif = document.getElementById('admVerif');
    if (verif) {
        verif.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-verif-action]');
            if (!btn) { return; }
            var row = btn.closest('.adm-vrow');
            var vid = parseInt(row.getAttribute('data-verif'), 10);
            var action = btn.getAttribute('data-verif-action');
            var proceed = action === 'reject'
                ? confirmAction({ title: 'Reject this request?', text: "The creator won't be verified. They can re-apply later.", icon: 'warning', confirmButtonText: 'Reject' })
                : Promise.resolve(true);
            proceed.then(function (ok) {
                if (!ok) { return; }
                row.querySelectorAll('.adm-btn').forEach(function (b) { b.disabled = true; });
                ApiDataSvc.apiCall('post', 'verification_resolve', { verification_id: vid, action: action }, function (r) {
                    var o = parse(r);
                    if (!o || !o.success) { row.querySelectorAll('.adm-btn').forEach(function (b) { b.disabled = false; }); if (window.toastr) { toastr.error((o && o.message) || 'Could not update'); } return; }
                    row.parentNode.removeChild(row);
                    if (window.toastr) { toastr.success(action === 'approve' ? 'Creator verified' : 'Request rejected'); }
                    bump('verification', -1);
                    var remaining = verif.querySelectorAll('.adm-vrow').length;
                    if (!remaining) { var n = document.getElementById('admVerifNone'); if (n) { n.hidden = false; } }
                });
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

    var usersBody = document.getElementById('admUsers');
    /* ---- Suspend / reactivate ---- */
    if (usersBody) {
        usersBody.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-status]');
            if (!btn) { return; }
            var row = btn.closest('.adm-urow');
            var uid = parseInt(row.getAttribute('data-uid'), 10);
            var status = btn.getAttribute('data-status');
            var nameEl = row.querySelector('.adm-t__main a');
            var name = nameEl ? nameEl.textContent.trim() : 'this account';
            var proceed = (status === 'Disabled')
                ? confirmAction({ titleText: 'Suspend ' + name + '?', text: 'They will be blocked from signing in until reactivated.', icon: 'warning', confirmButtonText: 'Suspend' })
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
                    var statusCell = row.querySelector('[data-status-cell]') || (row.cells ? row.cells[2] : row.children[2]);
                    statusCell.innerHTML = '<span class="adm-pill adm-pill--' + (disabled ? 'bad' : 'ok') + '">' + (disabled ? 'Suspended' : 'Active') + '</span>';
                    row.setAttribute('data-status-f', (row.getAttribute('data-status-f') || '').replace(/\b(active|suspended)\b/, disabled ? 'suspended' : 'active'));
                    btn.disabled = false;   // the same menu item now does the opposite
                    btn.setAttribute('data-status', disabled ? 'Active' : 'Disabled');
                    btn.textContent = disabled ? 'Reactivate' : 'Suspend…';
                    btn.classList.toggle('adm-menu__danger', !disabled);
                    if (window.toastr) { toastr.success(disabled ? 'Account suspended' : 'Account reactivated'); }
                });
            });
        });
    }
})();

/* ---- Financials: period switch drives the cards and the sales-by-type table; one small chart per money flow, each on its own scale ---- */
(function () {
    var cards = document.getElementById('fzCards');
    if (!cards) { return; }
    var months = [];
    try { months = JSON.parse(cards.getAttribute('data-series') || '[]'); } catch (e) { return; }
    if (!months.length) { return; }
    var TYPES = [['ppv', 'Pay-per-view'], ['bundle', 'Bundles'], ['message', 'Paid messages'], ['service', 'Services'], ['event', 'Events'], ['tip', 'Tips'], ['replay', 'Replays']];
    var MN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    function money(c) { return (c < 0 ? '−$' : '$') + (Math.abs(c) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function mname(k) { return MN[parseInt(k.slice(5, 7), 10) - 1] + ' ' + k.slice(0, 4); }
    /* period -> [slice of months, previous slice of equal length] */
    function window_for(p) {
        var n = months.length;
        if (p === 'last') { return [months.slice(n - 2, n - 1), months.slice(n - 3, n - 2)]; }
        var len = parseInt(p, 10);
        return [months.slice(n - len), months.slice(Math.max(0, n - 2 * len), n - len)];
    }
    function sum(list, key) { return list.reduce(function (a, m) { return a + (m[key] || 0); }, 0); }
    function delta(now, before) {
        if (before === 0) { return now === 0 ? ['flat', 'No change vs previous period'] : ['up', 'New vs previous period']; }
        var pct = Math.round((now - before) / Math.abs(before) * 100);
        return [pct > 0 ? 'up' : (pct < 0 ? 'down' : 'flat'), (pct > 0 ? '+' : '') + pct + '% vs previous period'];
    }
    function render(p) {
        var w = window_for(p), cur = w[0], prev = w[1];
        var label = cur.length === 1 ? mname(cur[0].k) : mname(cur[0].k) + ' to ' + mname(cur[cur.length - 1].k);
        document.getElementById('fzRange').textContent = label;
        cards.querySelectorAll('[data-m]').forEach(function (c) {
            var m = c.getAttribute('data-m'), now = sum(cur, m), before = sum(prev, m), d = delta(now, before);
            c.querySelector('[data-v]').textContent = money(now);
            var dEl = c.querySelector('[data-d]'); if (dEl) { dEl.className = 'fz-delta fz-delta--' + (m === 'payouts' || m === 'refunds' ? 'flat' : d[0]); dEl.textContent = d[1]; }
        });
        /* sales by type */
        var body = document.getElementById('fzTypes'), html = '', tot = { sales: 0, gross: 0, refunded: 0, creator: 0, platform: 0 };
        TYPES.forEach(function (t) {
            var r = { sales: 0, gross: 0, refunded: 0, creator: 0, platform: 0 };
            cur.forEach(function (m) { var x = (m.types || {})[t[0]] || {}; for (var k in r) { r[k] += x[k] || 0; } });
            for (var k in tot) { tot[k] += r[k]; }
            html += '<tr><td>' + t[1] + '</td><td class="adm-r adm-t__num">' + r.sales + '</td><td class="adm-r adm-t__num">' + money(r.gross) + '</td><td class="adm-r adm-t__num adm-t__muted">' + money(r.refunded) + '</td><td class="adm-r adm-t__num">' + money(r.creator) + '</td><td class="adm-r adm-t__num adm-t__strong">' + money(r.platform) + '</td></tr>';
        });
        html += '<tr class="adm-t__total"><td>Total</td><td class="adm-r adm-t__num">' + tot.sales + '</td><td class="adm-r adm-t__num">' + money(tot.gross) + '</td><td class="adm-r adm-t__num">' + money(tot.refunded) + '</td><td class="adm-r adm-t__num">' + money(tot.creator) + '</td><td class="adm-r adm-t__num">' + money(tot.platform) + '</td></tr>';
        body.innerHTML = html;
    }
    /* Revenue by month: one series (platform revenue), bars with the total on top and month-over-month change under each month */
    var last12 = months.slice(-12), ns = 'http://www.w3.org/2000/svg';
    function draw_rev() {
        var svg = document.querySelector('.fz-rev__svg'), tip = document.querySelector('#fzRev .fz-tip');
        if (!svg) { return; }
        function el(t, a, txt) { var n = document.createElementNS(ns, t); for (var k in a) { n.setAttribute(k, a[k]); } if (txt !== undefined) { n.textContent = txt; } return n; }
        function short(c) { var d = c / 100; if (Math.abs(d) >= 1000) { return '$' + (d / 1000).toFixed(Math.abs(d) >= 10000 ? 0 : 1) + 'k'; } return '$' + (d % 1 === 0 ? d : d.toFixed(2)); }
        var W = svg.clientWidth || 900, H = 300, L = 56, R = 12, T = 28, B = 48;
        svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
        while (svg.firstChild) { svg.removeChild(svg.firstChild); }
        var vals = last12.map(function (m) { return m.revenue || 0; });
        var top = Math.max.apply(null, vals.concat([0])), p10 = Math.pow(10, Math.floor(Math.log10(Math.max(top, 100)))), n0 = top / p10;
        var max = top <= 0 ? 10000 : (n0 <= 1 ? 1 : n0 <= 2 ? 2 : n0 <= 5 ? 5 : 10) * p10;
        var pw = W - L - R, ph = H - T - B, slot = pw / last12.length, bw = Math.min(56, slot * 0.56);
        var y = function (v) { return T + ph - (Math.max(0, v) / max) * ph; };
        for (var g = 0; g <= 4; g++) { var gv = max * g / 4; svg.appendChild(el('line', { 'class': 'grid', x1: L, x2: W - R, y1: y(gv), y2: y(gv) })); svg.appendChild(el('text', { 'class': 'axis', x: L - 8, y: y(gv) + 4, 'text-anchor': 'end' }, short(gv))); }
        last12.forEach(function (m, i) {
            var v = vals[i], cx = L + slot * i + slot / 2, prev = i > 0 ? vals[i - 1] : (months.length > 12 ? months[months.length - 13].revenue || 0 : 0);
            var cur = (i === last12.length - 1);
            svg.appendChild(el('rect', { x: cx - bw / 2, y: y(v), width: bw, height: Math.max(v > 0 ? 2 : 0, T + ph - y(v)), rx: 4, fill: cur ? '#FF6A13' : '#FFC29E' }));
            if (v > 0) { svg.appendChild(el('text', { 'class': 'fz-rev__val', x: cx, y: y(v) - 8, 'text-anchor': 'middle' }, short(v))); }
            if (slot >= 40 || i % 2 === last12.length % 2) { svg.appendChild(el('text', { 'class': 'axis', x: cx, y: H - 26, 'text-anchor': 'middle' }, m.label)); }
            var chg = '', cls = 'fz-rev__chg';
            if (prev > 0) { var pct = Math.round((v - prev) / prev * 100); chg = (pct > 0 ? '+' : '') + pct + '%'; cls += pct > 0 ? ' is-up' : (pct < 0 ? ' is-down' : ''); }
            else if (v > 0) { chg = 'New'; cls += ' is-up'; }
            if (chg && slot >= 40) { svg.appendChild(el('text', { 'class': cls, x: cx, y: H - 8, 'text-anchor': 'middle' }, chg)); }
            var hit = el('rect', { 'class': 'hit', x: L + slot * i, y: T - 20, width: slot, height: ph + 20 });
            hit.addEventListener('mouseenter', function () {
                tip.innerHTML = '<b>' + mname(m.k) + '</b><span>Plan payments <em>' + money(m.plans || 0) + '</em></span><span>Our fee on sales <em>' + money(m.fee || 0) + '</em></span><span>Our fee on memberships <em>' + money(m.members || 0) + '</em></span><span class="fz-tip__tot">Revenue <em>' + money(v) + '</em></span>' + (chg ? '<span>vs previous month <em>' + chg + '</em></span>' : '');
                tip.style.left = Math.min(88, Math.max(12, cx / W * 100)) + '%'; tip.hidden = false;
            });
            hit.addEventListener('mouseleave', function () { tip.hidden = true; });
            svg.appendChild(hit);
        });
        document.getElementById('fzRevTotal').textContent = money(vals.reduce(function (a, b) { return a + b; }, 0));
    }
    draw_rev();
    var rt2; window.addEventListener('resize', function () { clearTimeout(rt2); rt2 = setTimeout(draw_rev, 150); });

    document.querySelectorAll('.fz-period button').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.fz-period button').forEach(function (x) { var o = x === b; x.classList.toggle('is-on', o); x.setAttribute('aria-selected', o ? 'true' : 'false'); });
            render(b.getAttribute('data-p'));
        });
    });
    render('1');
})();

/* Billing tab: retry a past-due account's renewal now. */
(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-billing-retry]');
        if (!btn) { return; }
        var row = btn.closest('.adm-urow');
        btn.disabled = true;
        ApiDataSvc.apiCall('post', 'admin_billing_retry', { user_id: row.getAttribute('data-uid') }, function (r) {
            var o = null; try { o = JSON.parse(r); } catch (x) {}
            if (!o || !o.success) { btn.disabled = false; toastr.error((o && o.message) || 'Retry failed'); return; }
            toastr.success(o.message);
            setTimeout(function () { window.location.reload(); }, 900);
        });
    });
})();

/* Growth tab: the period switch shows the 7 / 30 / 90 day set (all rendered server-side). */
(function () {
    var panel = document.querySelector('.adm-panel[data-panel="growth"]');
    if (!panel) { return; }
    panel.querySelectorAll('.adm-gperiod button').forEach(function (b) {
        b.addEventListener('click', function () {
            var g = b.getAttribute('data-g');
            panel.querySelectorAll('.adm-gperiod button').forEach(function (x) { var o = x === b; x.classList.toggle('is-on', o); x.setAttribute('aria-selected', o ? 'true' : 'false'); });
            panel.querySelectorAll('.adm-gset, .fz-period__range').forEach(function (s) { s.hidden = s.getAttribute('data-g') !== g; });
        });
    });
})();

/* Admin > Leads: source subtabs filter the rows and set the CSV export's source */
document.addEventListener('DOMContentLoaded', function () {
    var tabs = document.getElementById('admLeadTabs');
    if (!tabs) { return; }
    tabs.querySelectorAll('[data-lead]').forEach(function (b) {
        b.addEventListener('click', function () {
            var f = b.getAttribute('data-lead'), shown = 0;
            tabs.querySelectorAll('[data-lead]').forEach(function (x) { var on = x === b; x.classList.toggle('is-active', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
            document.querySelectorAll('#admLeads .adm-leadrow').forEach(function (r) { var hit = f === '' || r.getAttribute('data-source') === f; r.hidden = !hit; if (hit) { shown++; } });
            document.getElementById('admLeadsNone').hidden = shown > 0;
            document.getElementById('admLeadsCsvSource').value = f;
        });
    });
});
