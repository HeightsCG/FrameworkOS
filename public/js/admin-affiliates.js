/* /admin, Affiliates tab: switch the three lists, approve / reject / disable affiliates, settle payout requests. Every action confirms first. */
(function () {
    "use strict";
    var panel = document.querySelector('.adm-panel[data-panel="affiliates"]');
    if (!panel) { return; }
    var $ = window.jQuery;

    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function confirm_box(opts) {
        if (!window.Swal) { return Promise.resolve(window.confirm(opts.title) ? { isConfirmed: true, value: '' } : { isConfirmed: false }); }
        return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' }, opts));
    }
    function done(r) {
        var o = parse(r);
        if (!o || !o.success) { toastr.error((o && o.message) || 'Something went wrong. Please try again.'); return; }
        toastr.success(o.message);
        setTimeout(function () { window.location = '/admin?tab=affiliates'; }, 700);
    }

    $(panel).on('click', '[data-aff-tab]', function () {
        var tab = $(this).attr('data-aff-tab');
        $(panel).find('[data-aff-tab]').removeClass('is-active').attr('aria-selected', 'false');
        $(this).addClass('is-active').attr('aria-selected', 'true');
        $(panel).find('[data-aff-pane]').each(function () { this.hidden = $(this).attr('data-aff-pane') !== tab; });
    });

    $(document).on('click', '[data-aff-set]', function () {
        var id = $(this).closest('.adm-afrow').attr('data-affiliate'), to = $(this).attr('data-aff-set');
        if (!id) { return; }
        var copy = {
            approved: { title: 'Approve this affiliate?', text: 'They get their link by email and in their notifications, and start earning on new referrals.', confirmButtonText: 'Approve' },
            rejected: { title: 'Reject this application?', text: 'They are told by email and can apply again later.', confirmButtonText: 'Reject' },
            disabled: { title: 'Disable this affiliate?', text: 'Their link stops counting and new payments earn nothing. Earned commissions stay.', confirmButtonText: 'Disable' }
        }[to];
        if (!copy) { return; }
        confirm_box(copy).then(function (r) {
            if (r.isConfirmed) { ApiDataSvc.apiCall('post', 'admin_affiliate_set', { id: id, status: to }, done); }
        });
    });

    $(document).on('click', '[data-aff-payout]', function () {
        var id = $(this).closest('.adm-afpay').attr('data-payout'), to = $(this).attr('data-aff-payout');
        if (!id) { return; }
        var paid = to === 'paid';
        confirm_box(paid
            ? { title: 'Mark this payout paid?', text: 'Only after the bank transfer is sent. The affiliate is told it is on its way.', confirmButtonText: 'Mark Paid' }
            : { title: 'Reject this payout request?', text: 'The amount goes back to their balance. The reason is sent to them.', input: 'text', inputPlaceholder: 'Reason', confirmButtonText: 'Reject' }
        ).then(function (r) {
            if (r.isConfirmed) { ApiDataSvc.apiCall('post', 'admin_affiliate_payout', { id: id, status: to, note: paid ? '' : (r.value || '') }, done); }
        });
    });
})();
