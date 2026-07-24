/* Creator service management: create/edit (modal), delete. */
(function () {
    var root = document.querySelector('.sv');
    if (!root) { return; }
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function el(id) { return document.getElementById(id); }

    var modalEl = el('serviceModal');
    var modal = (window.bootstrap && modalEl) ? new bootstrap.Modal(modalEl) : null;

    function openModal(d) {
        el('sv_id').value        = d && d.id ? d.id : '';
        el('sv_name').value      = d ? (d.name || '') : '';
        el('sv_desc').value      = d ? (d.description || '') : '';
        el('sv_price').value     = d ? (d.price || '') : '';
        el('sv_duration').value  = d ? (d.duration_min || '') : '';
        el('sv_capacity').value  = d ? (d.capacity || 0) : 0;
        el('sv_method').value    = d ? (d.delivery_method || 'custom') : 'custom';
        el('sv_url').value       = d ? (d.scheduling_url || '') : '';
        el('sv_details').value   = d ? (d.delivery_details || '') : '';
        el('sv_category').value  = d ? (d.category || '') : '';
        el('sv_refund').value    = d ? (d.refund_policy || '') : '';
        el('sv_status').value    = d ? (d.status || 'draft') : 'draft';
        el('svModalTitle').textContent = (d && d.id) ? 'Edit service' : 'New service';
        if (modal) { modal.show(); }
    }

    if (el('svCreate')) { el('svCreate').addEventListener('click', function () { openModal(null); }); }

    var body = el('svBody');
    if (body) {
        body.addEventListener('click', function (e) {
            var row = e.target.closest('.sv-row');
            if (!row) { return; }
            if (e.target.closest('[data-edit]')) {
                var data = null; try { data = JSON.parse(row.getAttribute('data-sv')); } catch (x) {}
                openModal(data);
                return;
            }
            if (e.target.closest('[data-delete]')) {
                var d = null; try { d = JSON.parse(row.getAttribute('data-sv')); } catch (x) {}
                var proceed = window.Swal
                    ? Swal.fire({ title: 'Delete this service?', text: "Buyers keep their booking details, but the listing is removed. This can't be undone.", icon: 'warning', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Delete', confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' }).then(function (r) { return r.isConfirmed; })
                    : Promise.resolve(window.confirm('Delete this service?'));
                proceed.then(function (ok) {
                    if (!ok || !d) { return; }
                    ApiDataSvc.apiCall('post', 'service_delete', { id: d.id }, function (r) {
                        var o = parse(r);
                        if (o && o.success) { row.parentNode.removeChild(row); if (window.toastr) { toastr.success('Service deleted'); } setTimeout(function () { location.reload(); }, 500); }
                        else if (window.toastr) { toastr.error((o && o.message) || 'Could not delete'); }
                    });
                });
            }
        });
    }

    el('sv_save').addEventListener('click', function () {
        var name = (el('sv_name').value || '').trim();
        if (name === '') { if (window.toastr) { toastr.error('Enter a name'); } return; }
        var btn = this; btn.disabled = true;
        ApiDataSvc.apiCall('post', 'service_save', {
            id: el('sv_id').value || 0,
            name: name,
            description: el('sv_desc').value,
            price: el('sv_price').value || 0,
            duration_min: el('sv_duration').value || 0,
            delivery_method: el('sv_method').value,
            scheduling_url: el('sv_url').value,
            delivery_details: el('sv_details').value,
            capacity: el('sv_capacity').value || 0,
            category: el('sv_category').value,
            refund_policy: el('sv_refund').value,
            status: el('sv_status').value
        }, function (r) {
            btn.disabled = false;
            var o = parse(r);
            if (!o || !o.success) { if (window.toastr) { toastr.error((o && o.message) || 'Could not save service'); } return; }
            if (modal) { modal.hide(); }
            if (window.toastr) { toastr.success('Service saved'); }
            setTimeout(function () { location.reload(); }, 500);
        });
    });
})();
