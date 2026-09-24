/* Support / help desk: new request, reply, close/reopen. AJAX via ApiDataSvc; toastr feedback. */
$(document).ready(function () {

    function parse(r) { try { return typeof r === 'string' ? JSON.parse(r) : r; } catch (e) { return null; } }

    /* ---- Page tabs (Requests / Tutorials / Quick Answers); #tutorials etc. opens a tab directly ---- */
    function show_tab(k, focus) {
        var $t = $('.sup-tab[data-tab="' + k + '"]');
        if (!$t.length) { return; }
        $('.sup-tab').each(function () { var on = $(this).attr('data-tab') === k; $(this).toggleClass('is-on', on).attr({ 'aria-selected': on ? 'true' : 'false', tabindex: on ? '0' : '-1' }); });
        $('.sup-panel').each(function () { this.hidden = $(this).attr('data-tab') !== k; });
        if (focus) { $t.trigger('focus'); }
        if (window.location.hash !== '#' + k) { history.replaceState(null, '', '#' + k); }
    }
    $('.sup-tab').on('click', function () { show_tab($(this).attr('data-tab')); });
    $('.sup-tabs').on('keydown', '.sup-tab', function (e) {
        var $all = $('.sup-tab'), i = $all.index(this);
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
            e.preventDefault();
            var n = $all.eq((i + (e.key === 'ArrowRight' ? 1 : -1) + $all.length) % $all.length);
            show_tab(n.attr('data-tab'), true);
        }
    });
    function tab_from_hash() { if (window.location.hash) { show_tab(window.location.hash.slice(1).replace(/[^a-z]/g, '')); } }
    $(window).on('hashchange', tab_from_hash);
    tab_from_hash();

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

        /* ---- AI Assist (staff): draft reply options or rework the typed reply; never sends ---- */
        var $ai = $('#swAi'), $ai_out = $('#swAiOut'), ai_busy = false, ai_last = null;
        function ai_open(open) {
            $ai.prop('hidden', !open); $('#swAiToggle').attr('aria-expanded', open ? 'true' : 'false');
            if (open) { ai_sync(); $('#swAiNote').trigger('focus'); }
        }
        function ai_sync() { $('.sw-chip[data-ai]').prop('disabled', ai_busy || ($('#sup_reply').val() || '').trim() === ''); $('#swAiDraft').prop('disabled', ai_busy); }
        function ai_esc(s) { return $('<div>').text(s).html(); }
        $('#swAiToggle').on('click', function () { ai_open($ai.prop('hidden')); });
        $('#swAiClose').on('click', function () { ai_open(false); $('#swAiToggle').trigger('focus'); });
        function grow() { var el = document.getElementById('sup_reply'); if (!el) { return; } el.style.height = 'auto'; el.style.height = Math.min(Math.max(el.scrollHeight + 2, 110), 420) + 'px'; }
        $('#sup_reply').on('input', function () { ai_sync(); grow(); });
        $('#swAiNote').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); ai_run('draft'); } });
        $ai.on('click', '[data-ai]', function () { ai_run($(this).attr('data-ai')); });
        $ai_out.on('click', '[data-ai-retry]', function () { if (ai_last) { ai_run(ai_last); } });
        $ai_out.on('click', '[data-ai-use]', function () {
            var text = $(this).closest('.sw-opt').find('.sw-opt__text').text();
            $('#sup_reply').val(text).removeClass('is-invalid').trigger('input').trigger('focus');
            $ai_out.empty(); toastr.success('Added to your reply. Edit it before sending.');
        });
        function ai_run(mode) {
            if (ai_busy) { return; }
            ai_last = mode; ai_busy = true; ai_sync();
            if (mode === 'draft') { $('#swAiDraft').text('Drafting…'); }
            var n = mode === 'draft' ? 3 : 1, sk = '';
            for (var i = 0; i < n; i++) { sk += '<div class="sw-skel" aria-hidden="true"><span></span><span></span><span></span></div>'; }
            $ai_out.html(sk + '<span class="sr-only">Writing…</span>');
            ApiDataSvc.apiCall('post', 'support_assist', { ticket_id: ticket_id, mode: mode, note: ($('#swAiNote').val() || '').trim(), current: ($('#sup_reply').val() || '').trim() }, function (r) {
                var o = parse(r);
                ai_busy = false; $('#swAiDraft').text('Draft Options'); ai_sync();
                if (!o || !o.success || !o.options || !o.options.length) {
                    $ai_out.html('<div class="sw-ai__msg"><i class="fa-solid fa-triangle-exclamation"></i><span>' + ai_esc((o && o.message) || 'AI Assist could not write a reply. Try again.') + '</span><button type="button" class="sw-btn sw-btn--sm" data-ai-retry>Try Again</button></div>');
                    return;
                }
                var h = '';
                $.each(o.options, function (i, opt) {
                    h += '<div class="sw-opt"><span class="sw-opt__label">' + ai_esc(opt.label || ('Option ' + (i + 1))) + '</span><div class="sw-opt__text">' + ai_esc(opt.text) + '</div><button type="button" class="sw-btn sw-btn--sm" data-ai-use>Use This</button></div>';
                });
                $ai_out.html(h);
            });
        }

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
