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
        /* ?tab=<panel> opens that tab (e.g. back from a support request) */
        var want = (new URLSearchParams(location.search).get('tab') || '').replace(/[^a-z]/g, '');
        var wantBtn = want ? tabs.querySelector('.adm-tab[data-panel="' + want + '"]') : null;
        if (wantBtn) { wantBtn.click(); }
    }

    /* ---- Audit log search ---- */
    var auSearch = document.getElementById('admAuditSearch');
    if (auSearch) {
        auSearch.addEventListener('input', function () {
            var q = auSearch.value.trim().toLowerCase(), shown = 0;
            document.querySelectorAll('#admAudit .adm-aurow').forEach(function (r) { var on = q === '' || r.getAttribute('data-search').indexOf(q) !== -1; r.hidden = !on; if (on) { shown++; } });
            document.getElementById('admAuditNone').hidden = shown > 0;
        });
    }

    /* ---- Support queue rows open the request; the user's name opens their admin page ---- */
    document.querySelectorAll('.adm-suprow[data-href]').forEach(function (r) {
        r.addEventListener('click', function (e) { if (e.target.closest('a')) { return; } window.location.href = r.getAttribute('data-href'); });
        r.addEventListener('keydown', function (e) { if (e.key === 'Enter') { window.location.href = r.getAttribute('data-href'); } });
    });

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
        var keys = cur.map(function (m) { return m.k; });
        document.querySelectorAll('.fz-mini__svg .sel').forEach(function (r) { r.setAttribute('opacity', keys.indexOf(r.getAttribute('data-k')) >= 0 ? '1' : '0'); });
        /* highlight the selected months in the small charts */
        var keys = cur.map(function (m) { return m.k; });
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
            svg.appendChild(el('rect', { x: cx - bw / 2, y: y(v), width: bw, height: Math.max(v > 0 ? 2 : 0, T + ph - y(v)), rx: 4, fill: cur ? '#5b4be0' : '#b9b1f6' }));
            if (v > 0) { svg.appendChild(el('text', { 'class': 'fz-rev__val', x: cx, y: y(v) - 8, 'text-anchor': 'middle' }, short(v))); }
            svg.appendChild(el('text', { 'class': 'axis', x: cx, y: H - 26, 'text-anchor': 'middle' }, m.label));
            var chg = '', cls = 'fz-rev__chg';
            if (prev > 0) { var pct = Math.round((v - prev) / prev * 100); chg = (pct > 0 ? '+' : '') + pct + '%'; cls += pct > 0 ? ' is-up' : (pct < 0 ? ' is-down' : ''); }
            else if (v > 0) { chg = 'New'; cls += ' is-up'; }
            if (chg) { svg.appendChild(el('text', { 'class': cls, x: cx, y: H - 8, 'text-anchor': 'middle' }, chg)); }
            var hit = el('rect', { 'class': 'hit', x: L + slot * i, y: T - 20, width: slot, height: ph + 20 });
            hit.addEventListener('mouseenter', function () {
                tip.innerHTML = '<b>' + mname(m.k) + '</b><span>Plan payments <em>' + money(m.plans || 0) + '</em></span><span>Our fee on sales <em>' + money(m.fee || 0) + '</em></span><span class="fz-tip__tot">Revenue <em>' + money(v) + '</em></span>' + (chg ? '<span>vs previous month <em>' + chg + '</em></span>' : '');
                tip.style.left = Math.min(88, Math.max(12, cx / W * 100)) + '%'; tip.hidden = false;
            });
            hit.addEventListener('mouseleave', function () { tip.hidden = true; });
            svg.appendChild(hit);
        });
        document.getElementById('fzRevTotal').textContent = money(vals.reduce(function (a, b) { return a + b; }, 0));
    }
    draw_rev();
    /* small multiples: last 12 months, each chart scaled to its own max */
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
    var rt2; window.addEventListener('resize', function () { clearTimeout(rt2); rt2 = setTimeout(draw_rev, 150); });
    document.querySelectorAll('.adm-tab[data-panel="financials"]').forEach(function (t) { t.addEventListener('click', function () { setTimeout(draw_rev, 0); }); });

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
