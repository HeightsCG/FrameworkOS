/* Services: ServiceEditor wires the service form (services/_form.php) inside the Create / Edit modal and turns it into
   the service_save payload; the preview updates from the same fields. Live is outside the form (opts.status()). */
(function () {
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function el(id) { return document.getElementById(id); }
    var METHODS = { zoom: 'Zoom', teams: 'Microsoft Teams', meet: 'Google Meet', webex: 'Webex', discord: 'Discord', phone: 'Phone', in_person: 'In person', custom: 'Other' };

    window.ServiceEditor = function (container, opts) {
        opts = opts || {};
        var ed = window.SectionEditor(container);   // approved shell: left nav, one section at a time
        var status = 'draft';
        function price() { var v = parseFloat(el('sv_price').value); return isNaN(v) ? 0 : v; }   // empty = free

        /* Narrow screens: the preview replaces the form while it's open (never stacked below it). */
        var pv_toggle = el('svPvToggle');
        function set_preview(on) {
            container.classList.toggle('is-previewing', on);
            pv_toggle.setAttribute('aria-expanded', on ? 'true' : 'false');
            pv_toggle.textContent = on ? 'Back to Editing' : 'Preview';
        }
        pv_toggle.addEventListener('click', function () { set_preview(!container.classList.contains('is-previewing')); });
        container.querySelectorAll('.cs-ae__navitem').forEach(function (b) { b.addEventListener('click', function () { set_preview(false); }); });
        el('svNavSelect').addEventListener('change', function () { set_preview(false); });

        ['sv_name', 'sv_desc', 'sv_category', 'sv_price', 'sv_duration', 'sv_capacity', 'sv_refund', 'sv_details'].forEach(function (id) { el(id).addEventListener('input', refresh); });
        el('sv_method').addEventListener('change', refresh);

        function session_line() {
            var mins = parseInt(el('sv_duration').value, 10);
            return (mins > 0 ? mins + ' minutes · ' : '') + (METHODS[el('sv_method').value] || 'Other');
        }
        function refresh() {
            var name = el('sv_name').value.trim(), cat = el('sv_category').value.trim(), cap = parseInt(el('sv_capacity').value, 10);
            if (opts.onTitle) { opts.onTitle(name); }
            ed.summary('details', name !== '' ? name : 'Untitled');
            ed.summary('pricing', (price() >= 1 ? '$' + price().toFixed(2) : 'Free') + (parseInt(el('sv_duration').value, 10) > 0 ? ' · ' + parseInt(el('sv_duration').value, 10) + ' min' : '') + (cap > 0 ? ' · ' + cap + ' spots' : ''));
            ed.summary('delivery', METHODS[el('sv_method').value] || 'Other');
            // preview
            el('svPv_title').textContent = name !== '' ? name : 'Untitled service';
            el('svPv_desc').textContent = el('sv_desc').value.trim();
            el('svPv_session').textContent = session_line() + (cat !== '' ? ' · ' + cat : '');
            var refund = el('sv_refund').value.trim();
            el('svPv_refund').textContent = refund; el('svPv_refund_row').hidden = refund === '';
            el('svPv_price').textContent = price() >= 1 ? '$' + price().toFixed(2) : 'Free';
            el('svPv_per').textContent = price() >= 1 ? 'per booking' : '';
            el('svPv_spots').textContent = cap > 0 ? cap + (cap === 1 ? ' spot' : ' spots') : 'No spot limit';
        }

        function load(d) {
            ed.clearErrors();
            el('sv_id').value       = d && d.id ? d.id : '';
            el('sv_name').value     = d ? (d.name || '') : '';
            el('sv_desc').value     = d ? (d.description || '') : '';
            el('sv_category').value = d ? (d.category || '') : '';
            var p = d ? parseFloat(d.price) : 0;
            el('sv_price').value    = p > 0 ? p.toFixed(2) : '';
            el('sv_duration').value = d && parseInt(d.duration_min, 10) > 0 ? parseInt(d.duration_min, 10) : '';
            el('sv_capacity').value = d && parseInt(d.capacity, 10) > 0 ? parseInt(d.capacity, 10) : '';
            el('sv_refund').value   = d ? (d.refund_policy || '') : '';
            el('sv_method').value   = d && d.delivery_method && el('sv_method').querySelector('option[value="' + d.delivery_method + '"]') ? d.delivery_method : 'zoom';
            el('sv_details').value  = d ? (d.delivery_details || '') : '';
            status = d && d.status ? d.status : 'draft';
            set_preview(false);
            ed.show(ed.first, false);
            refresh();
        }

        function validate() {
            var errs = [];
            if (el('sv_name').value.trim() === '') { errs.push({ section: 'details', input: 'sv_name', err: 'svErr_name', msg: 'Give the service a name.' }); }
            if (el('sv_price').value.trim() !== '' && price() > 0 && price() < 1) { errs.push({ section: 'pricing', input: 'sv_price', err: 'svErr_price', msg: 'Paid services start at $1.00. Leave it empty for a free one.' }); }
            else if (price() > 500 || Math.round(price() * 100) % 10 !== 0) { errs.push({ section: 'pricing', input: 'sv_price', err: 'svErr_price', msg: 'Enter a price up to $500.00, in 10¢ steps.' }); }
            var cap = el('sv_capacity').value.trim();
            if (cap !== '' && !(parseInt(cap, 10) >= 1)) { errs.push({ section: 'pricing', input: 'sv_capacity', err: 'svErr_capacity', msg: 'Enter 1 or more, or leave it empty for unlimited.' }); }
            ed.showErrors(errs);
            return errs.length === 0;
        }

        function save(btn) {
            if (btn.disabled || !validate()) { return; }
            var label = btn.textContent; btn.disabled = true; btn.textContent = 'Saving…';
            ApiDataSvc.apiCall('post', 'service_save', {
                id: el('sv_id').value || 0,
                name: el('sv_name').value.trim(),
                description: el('sv_desc').value,
                category: el('sv_category').value.trim(),
                price: price() >= 1 ? price().toFixed(2) : 0,
                duration_min: el('sv_duration').value.trim() !== '' ? el('sv_duration').value : 0,
                capacity: el('sv_capacity').value.trim() !== '' ? el('sv_capacity').value : 0,
                refund_policy: el('sv_refund').value.trim(),
                delivery_method: el('sv_method').value,
                delivery_details: el('sv_details').value,
                status: opts.status ? opts.status() : status
            }, function (r) {
                btn.disabled = false; btn.textContent = label;
                var o = parse(r);
                if (!o || !o.success) { if (window.toastr) { toastr.error((o && o.message) || 'Could not save the service'); } return; }
                if (opts.onSaved) { opts.onSaved(o); }
            });
        }

        return { load: load, save: save };
    };

    /* ---- /services list: Create modal ---- */
    var modalEl = el('serviceModal');
    if (!modalEl || !document.querySelector('.ev') || !el('svCreate')) { return; }
    var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;
    var editor = window.ServiceEditor(modalEl, {
        onTitle: function (t) { el('svModalTitle').textContent = t !== '' ? t : 'New Service'; },
        status: function () { return el('svCreateLive').checked ? 'published' : 'draft'; },
        onSaved: function (o) { window.location.href = '/services/manage/' + o.id; }
    });
    var opener = null;
    modalEl.addEventListener('shown.bs.modal', function () { el('sv_name').focus(); });
    modalEl.addEventListener('hidden.bs.modal', function () { if (opener) { opener.focus(); } opener = null; });
    el('svCreate').addEventListener('click', function () { opener = this; editor.load(null); if (modal) { modal.show(); } });
    el('sv_save').addEventListener('click', function () { editor.save(this); });
})();
