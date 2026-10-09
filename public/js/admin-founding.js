/* /admin, Founding tab: send the testimonial request now, or take a founding spot back. Both confirm first. */
(function () {
    "use strict";
    if (!document.querySelector('.adm-panel[data-panel="founding"]')) { return; }
    var $ = window.jQuery;

    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function confirm_box(opts) {
        if (!window.Swal) { return Promise.resolve(window.confirm(opts.title)); }
        return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' }, opts)).then(function (r) { return r.isConfirmed; });
    }

    $(document).on('click', '[data-founding-action]', function () {
        var id = $(this).closest('.adm-fdrow').attr('data-claim'), action = $(this).attr('data-founding-action');
        if (!id) { return; }
        var refuse = action === 'refuse';
        confirm_box(refuse
            ? { title: 'Mark this spot refused?', text: 'The founding fee lock ends and the spot opens again. Their plan is not changed.', confirmButtonText: 'Mark Refused' }
            : { title: 'Send the testimonial request?', text: 'They get the request by email and in their notifications. It goes out once.', confirmButtonText: 'Send Request' }
        ).then(function (ok) {
            if (!ok) { return; }
            ApiDataSvc.apiCall('post', refuse ? 'admin_founding_refuse' : 'admin_founding_testimonial', { id: id }, function (r) {
                var o = parse(r);
                if (!o || !o.success) { toastr.error((o && o.message) || 'Something went wrong. Please try again.'); return; }
                toastr.success(o.message);
                setTimeout(function () { window.location = '/admin/founding'; }, 700);
            });
        });
    });
})();
