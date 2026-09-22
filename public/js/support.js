/* Support / help desk: new request, reply, close/reopen. AJAX via ApiDataSvc; toastr feedback. */
$(document).ready(function () {

    function parse(r) { try { return typeof r === 'string' ? JSON.parse(r) : r; } catch (e) { return null; } }

    /* ---- New request (modal, same pattern as Services) ---- */
    var modalEl = document.getElementById('supportModal');
    var modal = (window.bootstrap && modalEl) ? new bootstrap.Modal(modalEl) : null;
    $('#supCreate').on('click', function () {
        $('#sup_category').val(''); $('#sup_subject').val(''); $('#sup_body').val('');
        $('#supportModal .is-invalid').removeClass('is-invalid');
        $('#supSend').prop('disabled', false).text('Send Request');
        if (modal) { modal.show(); }
    });
    if (modalEl) { modalEl.addEventListener('shown.bs.modal', function () { $('#sup_category').trigger('focus'); }); }
    $('#supportModal').on('input change', '.is-invalid', function () { $(this).removeClass('is-invalid'); });
    $('#supSend').on('click', function () {
        var category = $('#sup_category').val() || '';
        var subject = ($('#sup_subject').val() || '').trim();
        var body = ($('#sup_body').val() || '').trim();
        if (category === '') { $('#sup_category').addClass('is-invalid').focus(); toastr.error('Choose a topic'); return; }
        if (subject === '') { $('#sup_subject').addClass('is-invalid').focus(); toastr.error('Add a subject'); return; }
        if (body.length < 10) { $('#sup_body').addClass('is-invalid').focus(); toastr.error('Describe the problem in a few words'); return; }
        var $btn = $(this).prop('disabled', true).text('Sending…');
        ApiDataSvc.apiCall('post', 'support_create', { category: category, subject: subject, body: body }, function (r) {
            var o = parse(r);
            if (!o || !o.success) { $btn.prop('disabled', false).text('Send Request'); toastr.error((o && o.message) || 'Could not send your request'); return; }
            toastr.success(o.message);
            window.location.href = '/support/ticket/' + o.ticket_id;
        });
    });

    /* ---- Conversation ---- */
    var $thread = $('.sup--thread');
    if ($thread.length) {
        var ticket_id = $thread.data('ticket');
        /* Requester sidebar tabs */
        $('.sup-stab').on('click', function () {
            var k = $(this).attr('data-stab');
            $('.sup-stab').each(function () { var on = $(this).attr('data-stab') === k; $(this).toggleClass('is-on', on).attr('aria-selected', on ? 'true' : 'false'); });
            $('.sup-spanel').each(function () { this.hidden = $(this).attr('data-stab') !== k; });
        });
        var conv = document.getElementById('supConv');
        if (conv) { conv.scrollTop = conv.scrollHeight; }

        function send_reply(and_close) {
            var body = ($('#sup_reply').val() || '').trim();
            if (body === '') { $('#sup_reply').addClass('is-invalid').focus(); toastr.error('Write a reply first'); return; }
            var $btns = $('#supReplySend, #supReplyClose').prop('disabled', true);
            ApiDataSvc.apiCall('post', 'support_reply', { ticket_id: ticket_id, body: body }, function (r) {
                var o = parse(r);
                if (!o || !o.success) { $btns.prop('disabled', false); toastr.error((o && o.message) || 'Could not send your reply'); return; }
                if (!and_close) { toastr.success(o.message); window.location.reload(); return; }
                ApiDataSvc.apiCall('post', 'support_close', { ticket_id: ticket_id, closed: '1' }, function () { toastr.success('Reply sent and request closed'); window.location.reload(); });
            });
        }
        $('#supReply').on('submit', function (e) { e.preventDefault(); send_reply(false); });
        $('#supReplyClose').on('click', function () { confirm_close().then(function (ok) { if (ok) { send_reply(true); } }); });
        $('#sup_reply').on('input', function () { $(this).removeClass('is-invalid'); });

        function confirm_close() {
            if (!window.Swal) { return Promise.resolve(window.confirm('Close this request?')); }
            return Swal.fire({ title: 'Close this request?', text: 'It moves to Closed. Anyone can reopen it by replying or with Reopen.', icon: 'question',
                showCancelButton: true, reverseButtons: true, focusCancel: true, confirmButtonText: 'Close Request', confirmButtonColor: '#5b4be0', cancelButtonColor: '#6b6779' })
                .then(function (r) { return r.isConfirmed; });
        }
        $thread.on('click', '[data-close]', function () {
            var $b = $(this);
            if ($b.attr('data-close') === '1') { confirm_close().then(function (ok) { if (ok) { set_closed($b); } }); } else { set_closed($b); }
        });
        function set_closed($b) {
            $b.prop('disabled', true);
            ApiDataSvc.apiCall('post', 'support_close', { ticket_id: ticket_id, closed: $b.attr('data-close') }, function (r) {
                var o = parse(r);
                if (!o || !o.success) { $b.prop('disabled', false); toastr.error((o && o.message) || 'Could not update the request'); return; }
                toastr.success(o.message);
                window.location.reload();
            });
        }
    }
});
