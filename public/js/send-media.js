/* Send a photo or video to one person without leaving the page: pick who gets it, click Send.
 * Opened by CLSMessenger.compose({ assets: [...] }) (site_footer.php). Only people you message as their
 * creator can receive media, so the list is your followers and subscribers (message_people media=1). */
(function ($) {
    if (!$ || typeof window.ApiDataSvc === 'undefined') { return; }
    var state = { assets: [], to: 0, name: '', busy: false }, timer = null, modal = null;

    function esc(s) { return $('<div>').text(s == null ? '' : s).html().replace(/"/g, '&quot;'); }
    function api(action, data, cb) { ApiDataSvc.apiCall('post', action, data, function (r) { var o = null; try { o = JSON.parse(r); } catch (e) {} cb(o); }); }

    function build() {
        if (modal) { return modal; }
        $('body').append(
            '<div class="modal fade cls-send" id="clsSendModal" tabindex="-1" aria-labelledby="clsSendTitle" aria-hidden="true">'
          + '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
          + '<div class="modal-header"><span class="cls-send__thumb" id="clsSendThumb"></span><h2 class="modal-title" id="clsSendTitle">Send</h2>'
          + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
          + '<div class="modal-body">'
          + '<div class="cls-send__search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="clsSendSearch" placeholder="Search people" autocomplete="off" aria-label="Search people"></div>'
          + '<div class="cls-send__people" id="clsSendPeople" role="listbox" aria-label="People"></div>'
          + '</div>'
          + '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
          + '<button type="button" class="btn btn-primary" id="clsSendGo" disabled>Send</button></div>'
          + '</div></div></div>');
        var el = document.getElementById('clsSendModal');
        modal = bootstrap.Modal.getOrCreateInstance(el);
        el.addEventListener('shown.bs.modal', function () { $('#clsSendSearch').trigger('focus'); });
        $('#clsSendSearch').on('input', function () { var v = $.trim(this.value); clearTimeout(timer); timer = setTimeout(function () { load(v); }, 250); });
        $('#clsSendPeople').on('click', '.cls-send__person', function () { pick($(this)); });
        $('#clsSendPeople').on('dblclick', '.cls-send__person', function () { pick($(this)); send(); });
        $('#clsSendPeople').on('keydown', '.cls-send__person', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); var $n = e.key === 'ArrowDown' ? $(this).next() : $(this).prev(); if ($n.length) { $n.trigger('focus'); pick($n); } }
            else if (e.key === 'Enter') { e.preventDefault(); pick($(this)); send(); }
        });
        $('#clsSendGo').on('click', send);
        return modal;
    }

    function pick($b) {
        state.to = parseInt($b.data('id'), 10) || 0; state.name = String($b.data('name') || '');
        $('#clsSendPeople .cls-send__person').removeClass('is-on').attr('aria-selected', 'false');
        $b.addClass('is-on').attr('aria-selected', 'true');
        $('#clsSendGo').prop('disabled', state.to <= 0 || state.busy);
    }

    function load(q) {
        var $p = $('#clsSendPeople').html('<p class="cls-send__note">Loading…</p>');
        api('message_people', { q: q || '', media: 1 }, function (o) {
            $p.empty();
            if (!o || !o.success) { $p.html('<p class="cls-send__note">' + esc(o && o.message ? o.message : 'Could not load people.') + '</p>'); return; }
            var list = o.people || [];
            if (!list.length) { $p.html('<p class="cls-send__note">' + (o.is_search ? 'No one matches that search.' : 'Media can be sent to your followers and subscribers. You have none yet.') + '</p>'); return; }
            list.forEach(function (c) {
                var av = c.avatar ? '<span class="cls-send__av" style="background-image:url(\'' + esc(c.avatar) + '\')"></span>'
                                  : '<span class="cls-send__av">' + esc((c.name || c.handle || '?').replace(/^@/, '').charAt(0).toUpperCase()) + '</span>';
                $p.append('<button type="button" class="cls-send__person' + (c.id === state.to ? ' is-on' : '') + '" role="option" aria-selected="' + (c.id === state.to ? 'true' : 'false') + '" data-id="' + c.id + '" data-name="' + esc(c.name) + '">'
                    + av + '<span class="cls-send__who"><span class="cls-send__name">' + esc(c.name) + '</span><span class="cls-send__handle">@' + esc(c.handle) + '</span></span>'
                    + '<i class="fa-solid fa-check cls-send__check" aria-hidden="true"></i></button>');
            });
        });
    }

    function send() {
        if (state.busy || state.to <= 0 || !state.assets.length) { return; }
        state.busy = true;
        var $go = $('#clsSendGo').prop('disabled', true).html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Sending…');
        api('message_send', { to_creator: state.to, body: '', asset_ids: state.assets.map(function (a) { return a.id; }) }, function (o) {
            state.busy = false; $go.text('Send').prop('disabled', state.to <= 0);
            if (!o || !o.success) {
                if (window.cls_need_plan && window.cls_need_plan(o)) { return; }
                if (window.toastr) { toastr.error((o && o.message) || 'Could not send it.'); }
                return;
            }
            modal.hide();
            if (window.toastr) { toastr.success('Sent to ' + state.name); }
        });
    }

    window.CLSSendMedia = {
        open: function (assets) {
            state = { assets: (assets || []).filter(function (a) { return a && a.id; }), to: 0, name: '', busy: false };
            if (!state.assets.length) { return; }
            build();
            var a = state.assets[0], video = state.assets.every(function (x) { return x.type === 'video'; });
            $('#clsSendTitle').text(state.assets.length > 1 ? 'Send ' + state.assets.length + ' items' : (video ? 'Send video' : 'Send photo'));
            $('#clsSendThumb').css('background-image', a.thumb ? 'url("' + String(a.thumb).replace(/"/g, '%22') + '")' : 'none');
            $('#clsSendSearch').val(''); $('#clsSendGo').text('Send').prop('disabled', true);
            load('');
            modal.show();
        }
    };
})(window.jQuery);
