/* Events: EventEditor wires the shared editor sections (events/_editor_sections.php) inside any
   .cs-ae-modal container — the Create modal on /events and the Settings tab on /events/manage/<id>.
   Save posts event_save; access_type is derived from "who can attend" + "price". */
(function () {
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function el(id) { return document.getElementById(id); }
    function money(v) { var n = parseFloat(v); return isNaN(n) ? '' : '$' + n.toFixed(2); }
    function when(v) {
        if (!v) { return 'Not set'; }
        var d = new Date(v);   // datetime-local value, already in the creator's timezone
        if (isNaN(d)) { return 'Not set'; }
        return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) + ' · ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    window.EventEditor = function (container, opts) {
        opts = opts || {};
        var ed = window.SectionEditor(container);

        function syncAccess() {
            var who = el('ev_who').value, pay = el('ev_pay').value;
            ed.reveal(el('ev_price_wrap'), pay === 'paid');
            ed.reveal(el('ev_tier_wrap'), who === 'subscribers');
            // anyone → free | paid; subscribers → subscribers (any plan) | tier (one plan). The price applies to any audience.
            el('ev_access').value = who === 'anyone' ? (pay === 'paid' ? 'paid' : 'free') : (parseInt(el('ev_tier').value, 10) > 0 ? 'tier' : 'subscribers');
            refresh();
        }
        function syncFormat() {
            var f = el('ev_format').value;
            ed.reveal(el('ev_url_wrap'), f === 'virtual');
            ed.reveal(el('ev_location_wrap'), f === 'in_person');
            refresh();
        }
        var setWho = ed.seg(el('evWho'), el('ev_who'), 'data-who', syncAccess);
        var setPay = ed.seg(el('evPay'), el('ev_pay'), 'data-pay', syncAccess);
        var setFormat = ed.seg(el('evFormat'), el('ev_format'), 'data-format', syncFormat);
        var setStatus = ed.seg(el('evStatusSeg'), el('ev_status'), 'data-status', function () { refresh(); });
        el('ev_tier').addEventListener('change', syncAccess);
        el('ev_limit').addEventListener('change', function () { ed.reveal(el('ev_capacity_wrap'), this.checked); refresh(); });
        ['ev_title', 'ev_start', 'ev_price', 'ev_capacity', 'ev_url', 'ev_location'].forEach(function (id) { el(id).addEventListener('input', refresh); });

        function refresh() {
            var title = (el('ev_title').value || '').trim();
            if (opts.onTitle) { opts.onTitle(title); }
            ed.summary('details', title !== '' ? title : 'Untitled');
            ed.summary('when', when(el('ev_start').value));
            var f = el('ev_format').value, addr = (el('ev_location').value || '').trim();
            ed.summary('location', f === 'in_person' ? (addr !== '' ? addr.split(',')[0] : 'In person') : 'Virtual');
            var o = el('ev_tier').selectedOptions[0];
            var who = el('ev_who').value === 'anyone' ? 'Anyone' : (parseInt(el('ev_tier').value, 10) > 0 && o ? o.textContent + ' subscribers' : 'Subscribers');
            var t = who + ' · ' + (el('ev_pay').value === 'paid' ? (money(el('ev_price').value) || 'Paid') : 'Free');
            if (el('ev_limit').checked && parseInt(el('ev_capacity').value, 10) > 0) { t += ' · ' + parseInt(el('ev_capacity').value, 10) + ' spots'; }
            ed.summary('tickets', t);
            ed.summary('publishing', el('ev_status').value === 'published' ? 'Published' : 'Draft');
        }

        function load(d) {
            ed.clearErrors();
            el('ev_id').value           = d && d.id ? d.id : '';
            el('ev_title').value        = d ? (d.title || '') : '';
            el('ev_desc').value         = d ? (d.description || '') : '';
            el('ev_start').value        = d ? (d.start_at || '') : '';
            el('ev_end').value          = d ? (d.end_at || '') : '';
            el('ev_url').value          = d ? (d.external_url || '') : '';
            el('ev_location').value     = d ? (d.location || '') : '';
            el('ev_instructions').value = d ? (d.access_instructions || '') : '';
            el('ev_price').value        = (d && parseFloat(d.price) > 0) ? d.price : '';
            var at = d ? (d.access_type || 'free') : 'free';
            el('ev_tier').value = (at === 'tier' && d.tier_id && el('ev_tier').querySelector('option[value="' + d.tier_id + '"]')) ? String(d.tier_id) : '0';
            var cap = d ? parseInt(d.capacity || 0, 10) : 0;
            el('ev_limit').checked  = cap > 0;
            el('ev_capacity').value = cap > 0 ? cap : '';
            ed.reveal(el('ev_capacity_wrap'), cap > 0);
            setFormat(d && d.format === 'in_person' ? 'in_person' : 'virtual');
            setPay(d && at !== 'free' && parseFloat(d.price) > 0 ? 'paid' : 'free');
            setWho(at === 'subscribers' || at === 'tier' ? 'subscribers' : 'anyone');
            setStatus(d && d.status === 'published' ? 'published' : 'draft');
            ed.show(ed.first, false);
            refresh();
        }

        function validate() {
            var errs = [], start = el('ev_start').value, end = el('ev_end').value, url = (el('ev_url').value || '').trim();
            if ((el('ev_title').value || '').trim() === '') { errs.push({ section: 'details', input: 'ev_title', err: 'evErr_title', msg: 'Enter a title.' }); }
            if (start === '') { errs.push({ section: 'when', input: 'ev_start', err: 'evErr_start', msg: 'Choose when the event starts.' }); }
            else if (end !== '' && end <= start) { errs.push({ section: 'when', input: 'ev_end', err: 'evErr_end', msg: 'The end must be after the start.' }); }
            if (el('ev_format').value === 'virtual' && !/^https?:\/\//i.test(url)) { errs.push({ section: 'location', input: 'ev_url', err: 'evErr_url', msg: 'Add the video link (it starts with https://).' }); }
            if (el('ev_format').value === 'in_person' && (el('ev_location').value || '').trim() === '') { errs.push({ section: 'location', input: 'ev_location', err: 'evErr_location', msg: 'Add the address where you are meeting.' }); }
            if (el('ev_pay').value === 'paid' && !(parseFloat(el('ev_price').value) >= 1)) { errs.push({ section: 'tickets', input: 'ev_price', err: 'evErr_price', msg: 'Enter a ticket price of at least $1.00.' }); }
            if (el('ev_limit').checked && !(parseInt(el('ev_capacity').value, 10) >= 1)) { errs.push({ section: 'tickets', input: 'ev_capacity', err: 'evErr_capacity', msg: 'Enter how many spots are available.' }); }
            ed.showErrors(errs);
            return errs.length === 0;
        }

        function save(btn) {
            if (!validate() || btn.disabled) { return; }
            btn.disabled = true;
            ApiDataSvc.apiCall('post', 'event_save', {
                id: el('ev_id').value || 0,
                title: el('ev_title').value.trim(),
                description: el('ev_desc').value,
                start_at: el('ev_start').value,
                end_at: el('ev_end').value,
                format: el('ev_format').value,
                external_url: el('ev_url').value.trim(),
                location: el('ev_location').value,
                access_instructions: el('ev_instructions').value,
                access_type: el('ev_access').value,
                tier_id: el('ev_tier').value || 0,
                price: el('ev_pay').value === 'paid' ? (el('ev_price').value || 0) : 0,
                capacity: el('ev_limit').checked ? (el('ev_capacity').value || 0) : 0,
                status: el('ev_status').value
            }, function (r) {
                btn.disabled = false;
                var o = parse(r);
                if (!o || !o.success) { if (window.toastr) { toastr.error((o && o.message) || 'Could not save the event'); } return; }
                if (opts.onSaved) { opts.onSaved(o); }
            });
        }

        return { load: load, save: save, refresh: refresh };
    };

    /* ---- /events list: Create modal ---- */
    var modalEl = el('eventModal');
    if (!modalEl || !document.querySelector('.ev')) { return; }
    var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;
    var editor = window.EventEditor(modalEl, {
        onTitle: function (t) { el('evModalTitle').textContent = t !== '' ? t : 'New Event'; },
        onSaved: function (o) { window.location.href = '/events/manage/' + o.id; }
    });
    var opener = null;
    modalEl.addEventListener('shown.bs.modal', function () { el('ev_title').focus(); });
    modalEl.addEventListener('hidden.bs.modal', function () { if (opener) { opener.focus(); } opener = null; });
    el('evCreate').addEventListener('click', function () { opener = this; editor.load(null); if (modal) { modal.show(); } });
    el('ev_save').addEventListener('click', function () { editor.save(this); });
})();
