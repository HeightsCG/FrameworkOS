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

    /* ---- Tabs ---- */
    var tabs = document.getElementById('admTabs');
    if (tabs) {
        tabs.addEventListener('click', function (e) {
            var btn = e.target.closest('.adm-tab');
            if (!btn) { return; }
            var panel = btn.getAttribute('data-panel');
            tabs.querySelectorAll('.adm-tab').forEach(function (t) { t.classList.toggle('is-active', t === btn); });
            document.querySelectorAll('.adm-panel').forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-panel') === panel); });
        });
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
                // Update counts: the Moderation tab badge and the "Needs review" card (both count the queue)
                var remaining = mod.querySelectorAll('.adm-card').length;
                var tabBadge = document.querySelector('.adm-tab[data-panel="moderation"] .adm-tab__badge');
                if (tabBadge) { if (remaining > 0) { tabBadge.textContent = remaining; } else { tabBadge.parentNode.removeChild(tabBadge); } }
                var kpiBox = document.getElementById('admKpiReview');
                if (kpiBox) {
                    kpiBox.querySelector('.adm-kpi__val').textContent = remaining;
                    if (!remaining) { kpiBox.classList.remove('adm-kpi--alert'); }
                }
                if (!remaining) {
                    var wrap = document.getElementById('admMod');
                    wrap.outerHTML = '<div class="adm-empty"><span class="adm-empty__ic"><i class="fa-solid fa-circle-check"></i></span><p class="adm-empty__t">Nothing to Review</p></div>';
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
                    var remaining = reports.querySelectorAll('.adm-rrow').length;
                    document.querySelectorAll('.adm-sec__meta').forEach(function (m) { if (/\bopen$/.test(m.textContent.trim())) { m.textContent = remaining + ' open'; } });
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
                    var remaining = verif.querySelectorAll('.adm-vrow').length;
                    document.querySelectorAll('.adm-sec__meta').forEach(function (m) { if (/\bpending$/.test(m.textContent.trim())) { m.textContent = remaining + ' pending'; } });
                    var tabBadge = document.querySelector('.adm-tab[data-panel="verification"] .adm-tab__badge');
                    if (tabBadge) { if (remaining > 0) { tabBadge.textContent = remaining; } else { tabBadge.parentNode.removeChild(tabBadge); } }
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

/* ---- Financials: period switch drives the cards and the sales-by-type table; one small chart per money flow, each on its own scale ---- */
(function () {
    var cards = document.getElementById('fzCards');
    if (!cards) { return; }
    var months = [];
    try { months = JSON.parse(cards.getAttribute('data-series') || '[]'); } catch (e) { return; }
    if (!months.length) { return; }
    var TYPES = [['ppv', 'Pay-per-view'], ['bundle', 'Bundles'], ['message', 'Paid messages'], ['service', 'Services'], ['event', 'Events']];
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
        cards.querySelectorAll('.fz-card[data-m]').forEach(function (c) {
            var m = c.getAttribute('data-m'), now = sum(cur, m), before = sum(prev, m), d = delta(now, before);
            c.querySelector('[data-v]').textContent = money(now);
            var dEl = c.querySelector('[data-d]'); dEl.className = 'fz-delta fz-delta--' + (m === 'payouts' || m === 'refunds' ? 'flat' : d[0]); dEl.textContent = d[1];
            c.querySelectorAll('[data-f]').forEach(function (f) { f.textContent = money(sum(cur, f.getAttribute('data-f'))); });
        });
        /* sales by type */
        var body = document.getElementById('fzTypes'), html = '', tot = { sales: 0, gross: 0, refunded: 0, creator: 0, platform: 0 };
        TYPES.forEach(function (t) {
            var r = { sales: 0, gross: 0, refunded: 0, creator: 0, platform: 0 };
            cur.forEach(function (m) { var x = (m.types || {})[t[0]] || {}; for (var k in r) { r[k] += x[k] || 0; } });
            for (var k in tot) { tot[k] += r[k]; }
            html += '<div class="adm-frow"><span class="adm-ucell">' + t[1] + '</span><span class="adm-ucell adm-r">' + r.sales + '</span><span class="adm-ucell adm-r">' + money(r.gross) + '</span><span class="adm-ucell adm-r adm-ucell--muted">' + money(r.refunded) + '</span><span class="adm-ucell adm-r">' + money(r.creator) + '</span><span class="adm-ucell adm-r"><b>' + money(r.platform) + '</b></span></div>';
        });
        html += '<div class="adm-frow adm-frow--total"><span class="adm-ucell"><b>Total</b></span><span class="adm-ucell adm-r"><b>' + tot.sales + '</b></span><span class="adm-ucell adm-r"><b>' + money(tot.gross) + '</b></span><span class="adm-ucell adm-r"><b>' + money(tot.refunded) + '</b></span><span class="adm-ucell adm-r"><b>' + money(tot.creator) + '</b></span><span class="adm-ucell adm-r"><b>' + money(tot.platform) + '</b></span></div>';
        body.innerHTML = html;
        /* highlight the selected months in the small charts */
        var keys = cur.map(function (m) { return m.k; });
        document.querySelectorAll('.fz-mini__svg .sel').forEach(function (r) { r.setAttribute('opacity', keys.indexOf(r.getAttribute('data-k')) >= 0 ? '1' : '0'); });
    }
    /* small multiples: last 12 months, each chart scaled to its own max */
    var ns = 'http://www.w3.org/2000/svg', last12 = months.slice(-12);
    document.querySelectorAll('.fz-mini').forEach(function (box) {
        var m = box.getAttribute('data-m'), col = box.getAttribute('data-c'), svg = box.querySelector('svg');
        var vals = last12.map(function (x) { return x[m] || 0; });
        var max = Math.max.apply(null, vals.map(Math.abs).concat([1])), W = 300, H = 90, pad = 6, step = W / vals.length;
        function el(t, a) { var n = document.createElementNS(ns, t); for (var k in a) { n.setAttribute(k, a[k]); } return n; }
        last12.forEach(function (x, i) { svg.appendChild(el('rect', { 'class': 'sel', 'data-k': x.k, x: i * step, y: 0, width: step, height: H, fill: col, 'fill-opacity': '.08', opacity: '0' })); });
        var pts = vals.map(function (v, i) { return [i * step + step / 2, H - pad - (Math.max(0, v) / max) * (H - 2 * pad)]; });
        var line = 'M' + pts.map(function (p) { return p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' L');
        svg.appendChild(el('path', { d: line + ' L' + pts[pts.length - 1][0].toFixed(1) + ' ' + H + ' L' + pts[0][0].toFixed(1) + ' ' + H + ' Z', fill: col, 'fill-opacity': '.14' }));
        svg.appendChild(el('path', { d: line, fill: 'none', stroke: col, 'stroke-width': '2', 'vector-effect': 'non-scaling-stroke', 'stroke-linejoin': 'round' }));
        box.querySelector('[data-t]').textContent = money(vals.reduce(function (a, b) { return a + b; }, 0));
        box.querySelector('[data-a]').textContent = last12[0].label + ' ' + last12[0].k.slice(0, 4);
        box.querySelector('[data-z]').textContent = 'Best ' + money(Math.max.apply(null, vals));
    });
    document.querySelectorAll('.fz-period button').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.fz-period button').forEach(function (x) { var o = x === b; x.classList.toggle('is-on', o); x.setAttribute('aria-selected', o ? 'true' : 'false'); });
            render(b.getAttribute('data-p'));
        });
    });
    render('1');
})();
