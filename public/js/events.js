/* Creator event management: create/edit (modal), delete. */
(function () {
    var root = document.querySelector('.ev');
    if (!root) { return; }
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function el(id) { return document.getElementById(id); }

    var modalEl = el('eventModal');
    var modal = (window.bootstrap && modalEl) ? new bootstrap.Modal(modalEl) : null;

    function toggleAccessFields() {
        var a = el('ev_access').value;
        el('ev_price_wrap').hidden = (a !== 'paid');
        el('ev_tier_wrap').hidden  = (a !== 'tier');
    }
    el('ev_access').addEventListener('change', toggleAccessFields);

    function openModal(d) {
        el('ev_id').value           = d && d.id ? d.id : '';
        el('ev_title').value        = d ? (d.title || '') : '';
        el('ev_desc').value         = d ? (d.description || '') : '';
        el('ev_start').value        = d ? (d.start_at || '') : '';
        el('ev_end').value          = d ? (d.end_at || '') : '';
        el('ev_access').value       = d ? (d.access_type || 'free') : 'free';
        el('ev_price').value        = (d && d.access_type === 'paid') ? (d.price || '') : '';
        if (d && d.tier_id && el('ev_tier')) { el('ev_tier').value = d.tier_id; }
        el('ev_capacity').value     = d ? (d.capacity || 0) : 0;
        el('ev_location').value     = d ? (d.location || '') : '';
        el('ev_url').value          = d ? (d.external_url || '') : '';
        el('ev_instructions').value = d ? (d.access_instructions || '') : '';
        el('ev_status').value       = d ? (d.status || 'draft') : 'draft';
        el('evModalTitle').textContent = (d && d.id) ? 'Edit event' : 'New event';
        toggleAccessFields();
        if (modal) { modal.show(); }
    }

    if (el('evCreate')) { el('evCreate').addEventListener('click', function () { openModal(null); }); }

    var body = el('evBody');
    if (body) {
        body.addEventListener('click', function (e) {
            var row = e.target.closest('.ev-row');
            if (!row) { return; }
            if (e.target.closest('[data-edit]')) {
                var data = null; try { data = JSON.parse(row.getAttribute('data-ev')); } catch (x) {}
                openModal(data);
                return;
            }
            if (e.target.closest('[data-delete]')) {
                var d = null; try { d = JSON.parse(row.getAttribute('data-ev')); } catch (x) {}
                var proceed = window.Swal
                    ? Swal.fire({ title: 'Delete this event?', text: "Registrations will be removed. This can't be undone.", icon: 'warning', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Delete', confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' }).then(function (r) { return r.isConfirmed; })
                    : Promise.resolve(window.confirm('Delete this event?'));
                proceed.then(function (ok) {
                    if (!ok || !d) { return; }
                    ApiDataSvc.apiCall('post', 'event_delete', { id: d.id }, function (r) {
                        var o = parse(r);
                        if (o && o.success) { row.parentNode.removeChild(row); if (window.toastr) { toastr.success('Event deleted'); } setTimeout(function () { location.reload(); }, 500); }
                        else if (window.toastr) { toastr.error((o && o.message) || 'Could not delete'); }
                    });
                });
            }
        });
    }

    el('ev_save').addEventListener('click', function () {
        var title = (el('ev_title').value || '').trim();
        if (title === '') { if (window.toastr) { toastr.error('Enter a title'); } return; }
        if ((el('ev_start').value || '') === '') { if (window.toastr) { toastr.error('Enter a start date & time'); } return; }
        var btn = this; btn.disabled = true;
        ApiDataSvc.apiCall('post', 'event_save', {
            id: el('ev_id').value || 0,
            title: title,
            description: el('ev_desc').value,
            start_at: el('ev_start').value,
            end_at: el('ev_end').value,
            access_type: el('ev_access').value,
            price: el('ev_price').value || 0,
            tier_id: el('ev_tier') ? (el('ev_tier').value || 0) : 0,
            capacity: el('ev_capacity').value || 0,
            location: el('ev_location').value,
            external_url: el('ev_url').value,
            access_instructions: el('ev_instructions').value,
            status: el('ev_status').value
        }, function (r) {
            btn.disabled = false;
            var o = parse(r);
            if (!o || !o.success) { if (window.toastr) { toastr.error((o && o.message) || 'Could not save event'); } return; }
            if (modal) { modal.hide(); }
            if (window.toastr) { toastr.success('Event saved'); }
            setTimeout(function () { location.reload(); }, 500);
        });
    });
})();
