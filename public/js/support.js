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
        var conv = document.getElementById('supConv');
        if (conv) { conv.scrollTop = conv.scrollHeight; }

        $('#supReply').on('submit', function (e) {
            e.preventDefault();
            var body = ($('#sup_reply').val() || '').trim();
            if (body === '') { $('#sup_reply').addClass('is-invalid').focus(); toastr.error('Write a reply first'); return; }
            var $btn = $('#supReplySend').prop('disabled', true).text('Sending…');
            ApiDataSvc.apiCall('post', 'support_reply', { ticket_id: ticket_id, body: body }, function (r) {
                var o = parse(r);
                if (!o || !o.success) { $btn.prop('disabled', false).text('Send Reply'); toastr.error((o && o.message) || 'Could not send your reply'); return; }
                toastr.success(o.message);
                window.location.reload();
            });
        });
        $('#sup_reply').on('input', function () { $(this).removeClass('is-invalid'); });

        $thread.on('click', '[data-close]', function () {
            var $b = $(this).prop('disabled', true);
            ApiDataSvc.apiCall('post', 'support_close', { ticket_id: ticket_id, closed: $b.attr('data-close') }, function (r) {
                var o = parse(r);
                if (!o || !o.success) { $b.prop('disabled', false); toastr.error((o && o.message) || 'Could not update the request'); return; }
                toastr.success(o.message);
                window.location.reload();
            });
        });
    }
});
