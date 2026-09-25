/* Creator service management: click a row to edit it in the section editor; delete from the editor. */
(function () {
    var root = document.querySelector('.sv');
    if (!root) { return; }
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function el(id) { return document.getElementById(id); }

    var modalEl = el('serviceModal');
    var modal = (window.bootstrap && modalEl) ? new bootstrap.Modal(modalEl) : null;
    var ed = window.SectionEditor(modalEl);
    var opener = null;

    var setStatus = ed.seg(el('svStatusSeg'), el('sv_status'), 'data-status', function () { refresh(); });
    el('sv_group').addEventListener('change', function () { ed.reveal(el('sv_capacity_wrap'), this.checked); refresh(); });

    function refresh() {
        var name = (el('sv_name').value || '').trim();
        el('svModalTitle').textContent = name !== '' ? name : (el('sv_id').value ? 'Untitled Service' : 'New Service');
        ed.summary('details', name !== '' ? name : 'Untitled');
        var price = parseFloat(el('sv_price').value), mins = parseInt(el('sv_duration').value, 10), parts = [];
        parts.push(price > 0 ? '$' + price.toFixed(2) : 'Free');
        if (mins > 0) { parts.push(mins + ' min'); }
        if (el('sv_group').checked && parseInt(el('sv_capacity').value, 10) > 0) { parts.push(parseInt(el('sv_capacity').value, 10) + ' seats'); }
        ed.summary('pricing', parts.join(' · '));
        var m = el('sv_method').selectedOptions[0];
        ed.summary('delivery', (m ? m.textContent : 'Other') + ((el('sv_url').value || '').trim() !== '' ? ' · Booking link' : ''));
        var st = el('sv_status').value;
        ed.summary('publishing', st === 'published' ? 'Published' : 'Draft');
        var badge = el('svStatus');
        badge.hidden = !el('sv_id').value;
        badge.classList.toggle('is-active', st === 'published');
        el('svStatusText').textContent = st === 'published' ? 'Published' : 'Draft';
    }
    ['sv_name', 'sv_price', 'sv_duration', 'sv_capacity', 'sv_url'].forEach(function (id) { el(id).addEventListener('input', refresh); });
    el('sv_method').addEventListener('change', refresh);

    function openModal(d) {
        ed.clearErrors();
        el('sv_id').value        = d && d.id ? d.id : '';
        el('sv_name').value      = d ? (d.name || '') : '';
        el('sv_desc').value      = d ? (d.description || '') : '';
        el('sv_category').value  = d ? (d.category || '') : '';
        el('sv_price').value     = d ? (d.price || '') : '';
        el('sv_duration').value  = d && parseInt(d.duration_min, 10) > 0 ? d.duration_min : '';
        el('sv_refund').value    = d ? (d.refund_policy || '') : '';
        var cap = d ? parseInt(d.capacity || 0, 10) : 0;
        el('sv_group').checked   = cap > 0;
        el('sv_capacity').value  = cap > 0 ? cap : '';
        ed.reveal(el('sv_capacity_wrap'), cap > 0);
        el('sv_method').value    = d ? (d.delivery_method || 'custom') : 'custom';
        el('sv_url').value       = d ? (d.scheduling_url || '') : '';
        el('sv_details').value   = d ? (d.delivery_details || '') : '';
        setStatus(d ? (d.status === 'published' ? 'published' : 'draft') : 'draft');
        el('sv_delete').hidden = !(d && d.id);
        el('sv_save').textContent = (d && d.id) ? 'Save Changes' : 'Create Service';
        ed.show(ed.first, false);
        refresh();
        if (modal) { modal.show(); }
    }
    modalEl.addEventListener('shown.bs.modal', function () { el('sv_name').focus(); });
    modalEl.addEventListener('hidden.bs.modal', function () { if (opener && document.body.contains(opener)) { opener.focus(); } opener = null; });

    if (el('svCreate')) { el('svCreate').addEventListener('click', function () { opener = this; openModal(null); }); }

    var body = el('svBody');
    function openRow(row) {
        var data = null; try { data = JSON.parse(row.getAttribute('data-sv')); } catch (x) {}
        if (data) { opener = row; openModal(data); }
    }
    if (body) {
        body.addEventListener('click', function (e) { var row = e.target.closest('.sv-row'); if (row) { openRow(row); } });
        body.addEventListener('keydown', function (e) {
            var row = e.target.closest('.sv-row');
            if (row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openRow(row); }
        });
    }

    function validate() {
        var errs = [], price = el('sv_price').value, url = (el('sv_url').value || '').trim();
        if ((el('sv_name').value || '').trim() === '') { errs.push({ section: 'details', input: 'sv_name', err: 'svErr_name', msg: 'Enter a name.' }); }
        if (price !== '' && !(parseFloat(price) >= 0)) { errs.push({ section: 'pricing', input: 'sv_price', err: 'svErr_price', msg: 'Enter a price, or 0 for free.' }); }
        if (el('sv_group').checked && !(parseInt(el('sv_capacity').value, 10) >= 2)) { errs.push({ section: 'pricing', input: 'sv_capacity', err: 'svErr_capacity', msg: 'A group session needs at least 2 seats.' }); }
        if (url !== '' && !/^https?:\/\//i.test(url)) { errs.push({ section: 'delivery', input: 'sv_url', err: 'svErr_url', msg: 'Enter a link that starts with https://' }); }
        ed.showErrors(errs);
        return errs.length === 0;
    }

    el('sv_save').addEventListener('click', function () {
        if (!validate()) { return; }
        var btn = this; if (btn.disabled) { return; } btn.disabled = true;
        ApiDataSvc.apiCall('post', 'service_save', {
            id: el('sv_id').value || 0,
            name: el('sv_name').value.trim(),
            description: el('sv_desc').value,
            price: el('sv_price').value || 0,
            duration_min: el('sv_duration').value || 0,
            delivery_method: el('sv_method').value,
            scheduling_url: el('sv_url').value.trim(),
            delivery_details: el('sv_details').value,
            capacity: el('sv_group').checked ? (el('sv_capacity').value || 0) : 0,
            category: el('sv_category').value,
            refund_policy: el('sv_refund').value,
            status: el('sv_status').value
        }, function (r) {
            btn.disabled = false;
            var o = parse(r);
            if (!o || !o.success) { if (window.toastr) { toastr.error((o && o.message) || 'Could not save the service'); } return; }
            if (modal) { modal.hide(); }
            if (window.toastr) { toastr.success(el('sv_id').value ? 'Changes saved' : 'Service created'); }
            setTimeout(function () { location.reload(); }, 500);
        });
    });

    el('sv_delete').addEventListener('click', function () {
        var id = el('sv_id').value;
        if (!id) { return; }
        var btn = this;
        var proceed = window.Swal
            ? Swal.fire({ title: 'Delete this service?', text: "Buyers keep their booking details, but the listing is removed. This can't be undone.", icon: 'warning', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Delete Service', confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' }).then(function (r) { return r.isConfirmed; })
            : Promise.resolve(window.confirm('Delete this service?'));
        proceed.then(function (ok) {
            if (!ok) { return; }
            btn.disabled = true;
            ApiDataSvc.apiCall('post', 'service_delete', { id: id }, function (r) {
                btn.disabled = false;
                var o = parse(r);
                if (o && o.success) { if (modal) { modal.hide(); } if (window.toastr) { toastr.success('Service deleted'); } setTimeout(function () { location.reload(); }, 500); }
                else if (window.toastr) { toastr.error((o && o.message) || 'Could not delete the service'); }
            });
        });
    });
})();
