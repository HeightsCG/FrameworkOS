/* Feedback dialog (#feedback_modal in site_footer.php). Any [data-feedback] element opens it; the note is sent with the
   page it came from (path + the page heading) to feedback_send, and staff read it on /admin/feedback. */
$(function () {
    var feedback_el = document.getElementById('feedback_modal');
    if (!feedback_el || typeof bootstrap === 'undefined') { return; }
    var feedback_modal = bootstrap.Modal.getOrCreateInstance(feedback_el);
    var feedback_kind = '';
    var feedback_sending = false;

    function feedback_pick(kind) {
        feedback_kind = kind;
        $('#feedback_modal .fb-kind').each(function () {
            var on = $(this).attr('data-kind') === kind;
            $(this).toggleClass('is-active', on).attr('aria-checked', on ? 'true' : 'false');
        });
    }

    function feedback_page_title() {
        var h = $('.app-content h1').first().text();
        return String(h || '').trim().substring(0, 190);
    }

    $(document).on('click', '[data-feedback]', function (e) {
        e.preventDefault();
        var kind = $(this).attr('data-feedback-kind') || '';
        if (kind !== '') { feedback_pick(kind); }
        feedback_modal.show();
    });

    // The note is kept if the dialog is closed without sending; it clears after a send.
    feedback_el.addEventListener('shown.bs.modal', function () {
        $('#feedback_message').trigger('focus');
    });

    $('#feedback_modal').on('click', '.fb-kind', function () {
        feedback_pick($(this).attr('data-kind'));
    });

    // Arrow keys move between the four types, like a radio group.
    $('#feedback_modal').on('keydown', '.fb-kind', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
        e.preventDefault();
        var kinds = $('#feedback_modal .fb-kind');
        var at = kinds.index(this) + (e.key === 'ArrowRight' ? 1 : -1);
        var next = kinds.eq((at + kinds.length) % kinds.length);
        feedback_pick(next.attr('data-kind'));
        next.trigger('focus');
    });

    $('#feedback_message').keydown(function (e) {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); $('#feedback_send').trigger('click'); }
    });

    $('#feedback_send').on('click', function () {
        if (feedback_sending) { return; }
        if (feedback_kind == '') {
            toastr.error('Choose a type of feedback');
            return;
        }
        if (String($('#feedback_message').val()).trim() == '') {
            toastr.error('Write your feedback first');
            $('#feedback_message').trigger('focus');
            return;
        }
        var btn = $(this);
        feedback_sending = true;
        btn.prop('disabled', true).text('Sending…');
        ApiDataSvc.apiCall('post', 'feedback_send', {
            kind: feedback_kind,
            message: $('#feedback_message').val(),
            page_url: window.location.pathname,
            page_title: feedback_page_title(),
            viewport: window.innerWidth + 'x' + window.innerHeight
        }, function (data) {
            feedback_sending = false;
            btn.prop('disabled', false).text('Send Feedback');
            var o = null;
            try { o = JSON.parse(data); } catch (err) { o = null; }
            if (o && o.success) {
                toastr.success(o.message);
                $('#feedback_message').val('');
                feedback_pick('');
                feedback_modal.hide();
            } else {
                toastr.error(o && o.message ? o.message : 'Could not send your feedback');
            }
        });
    });
});
