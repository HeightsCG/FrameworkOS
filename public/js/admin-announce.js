/* /admin/announcements: pick an audience, write the message, confirm the count, send. */
(function () {
    var root = document.getElementById('admAnnounce');
    if (!root) { return; }
    function parse(r) { try { return typeof r === 'string' ? JSON.parse(r) : r; } catch (e) { return null; } }
    var body_el = document.getElementById('admAnnBody'), send_el = document.getElementById('admAnnSend'), reach_el = document.getElementById('admAnnReach');
    var segs = root.querySelectorAll('[data-segment]');

    function picked() { return root.querySelector('[data-segment].is-on'); }
    function count() { var b = picked(); return b ? parseInt(b.getAttribute('data-count'), 10) || 0 : 0; }
    function people(n) { return n.toLocaleString() + (n === 1 ? ' user' : ' users'); }
    function refresh() {
        var n = count();
        reach_el.textContent = n === 0 ? 'No one is in this audience' : 'Sends to ' + people(n);
        send_el.disabled = n === 0 || body_el.value.trim() === '';
    }

    segs.forEach(function (b) {
        b.addEventListener('click', function () {
            segs.forEach(function (x) { x.classList.toggle('is-on', x === b); x.setAttribute('aria-checked', x === b ? 'true' : 'false'); });
            refresh();
            // the count on the page can be minutes old: check it again
            ApiDataSvc.apiCall('post', 'admin_announce_count', { segment: b.getAttribute('data-segment') }, function (r) {
                var o = parse(r);
                if (!o || !o.success) { return; }
                b.setAttribute('data-count', o.count);
                b.querySelector('b').textContent = Number(o.count).toLocaleString();
                if (b === picked()) { refresh(); }
            });
        });
    });
    body_el.addEventListener('input', refresh);

    send_el.addEventListener('click', function () {
        var b = picked(), text = body_el.value.trim(), n = count();
        if (!b || text === '' || n === 0) { return; }
        var label = b.firstChild.textContent.trim();
        Swal.fire({ title: 'Send to ' + people(n) + '?', text: label + ' get it in their Inbox, and by email unless they turned off Broadcasts emails. This can\'t be undone.',
                    showCancelButton: true, reverseButtons: true, focusCancel: true, confirmButtonText: 'Send Announcement', cancelButtonText: 'Cancel',
                    confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' })
            .then(function (res) {
                if (!res.isConfirmed) { return; }
                send_el.disabled = true;
                ApiDataSvc.apiCall('post', 'admin_announce_send', { segment: b.getAttribute('data-segment'), body: text }, function (r) {
                    var o = parse(r);
                    if (!o || !o.success) { send_el.disabled = false; toastr.error((o && o.message) || 'Could not reach the server. Try again.'); return; }
                    toastr.success(o.message);
                    setTimeout(function () { window.location.reload(); }, 900);
                });
            });
    });
    refresh();
})();
