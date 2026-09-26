/* Inbox page (/inbox): conversations, thread with media + paid unlocks, compose, people picker, broadcast. */
(function () {
    if (typeof window.jQuery === 'undefined' || typeof window.ApiDataSvc === 'undefined') { return; }
    var $ = window.jQuery;
    var $root = $('#ibx'); if (!$root.length) { return; }
    var CFG = {}; try { CFG = JSON.parse($root.attr('data-init') || '{}'); } catch (e) { CFG = {}; }
    var ME = parseInt(CFG.me, 10) || 0, IS_CREATOR = !!CFG.is_creator;
    var SEG_LABELS = CFG.segment_labels || {}, SEGMENTS = CFG.segments || ['all', 'followers', 'subscribers'];
    var WALLET = CFG.wallet_url || '/account/settings?section=wallet';

    var $convs = $('#ibxConvs'), $scroll = $('#ibxScroll'), $pane = $('#ibxPane'), $blank = $('#ibxBlank'), $input = $('#ibxInput');
    var convs = [], filter = 'all', search = '', active = 0, activePeer = null, iAmCreator = false, viewerCredits = 0, unlocking = 0, threadReq = 0;

    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null) ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function utc(iso) { if (!iso) { return null; } var d = new Date(String(iso).replace(' ', 'T') + 'Z'); return isNaN(d) ? null : d; }
    function ftime(iso) { var d = utc(iso); if (!d) { return ''; } var now = new Date(); var sameDay = d.toDateString() === now.toDateString(); return sameDay ? d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }); }
    function fclock(iso) { var d = utc(iso); return d ? d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : ''; }
    function fday(iso) { var d = utc(iso); if (!d) { return ''; } var now = new Date(), y = new Date(now); y.setDate(now.getDate() - 1); if (d.toDateString() === now.toDateString()) { return 'Today'; } if (d.toDateString() === y.toDateString()) { return 'Yesterday'; } return d.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' }); }
    function fdate(iso) { var d = utc(iso); return d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : ''; }
    function ini(n) { return (String(n || '?').trim().charAt(0) || '?').toUpperCase(); }
    function av(a, n) { return a ? '<span class="ibx__av" style="background-image:url(\'' + esc(a) + '\')"></span>' : '<span class="ibx__av">' + esc(ini(n)) + '</span>'; }
    function money(credits) { var d = (credits || 0) / 10; return '$' + (d % 1 === 0 ? d.toFixed(0) : d.toFixed(2)); }
    function cr(credits) { credits = credits || 0; return credits + (credits === 1 ? ' credit' : ' credits'); }
    function crT(credits) { credits = credits || 0; return credits + (credits === 1 ? ' Credit' : ' Credits'); }   // button text is Title Case
    function toastErr(m) { if (window.toastr) { toastr.error(m); } }
    function api(action, data, cb) { ApiDataSvc.apiCall('post', action, data, function (r) { var o = null; try { o = JSON.parse(r); } catch (e) { o = null; } if (o && o.need_login) { window.location = '/'; return; } cb(o); }); }
    function modal(id) { var el = document.getElementById(id); return el ? bootstrap.Modal.getOrCreateInstance(el) : null; }

    /* ---- attachments (thread composer and broadcast composer each own one) ---- */
    function makeAttach($wrap) {
        var a = { list: [], price: 0, onchange: null };
        a.render = function () {
            if (!a.list.length) { $wrap.prop('hidden', true).empty(); return; }
            var h = '<div class="ibx__attach-list">';
            a.list.forEach(function (m) { h += '<span class="ibx__attach-item" style="background-image:url(\'' + esc(m.thumb) + '\')" title="' + esc(m.name || '') + '">' + (m.type === 'video' ? '<i class="fa-solid fa-play"></i>' : '') + '<button type="button" class="ibx__attach-rm" data-rm="' + m.id + '" aria-label="Remove"><i class="fa-solid fa-xmark"></i></button></span>'; });
            var paid = a.price > 0;
            h += '</div><div class="ibx__pricing" role="group" aria-label="Pricing">'
               + '<button type="button" class="ibx__pill' + (paid ? '' : ' is-on') + '" data-mode="free" aria-pressed="' + (paid ? 'false' : 'true') + '">Free</button>'
               + '<button type="button" class="ibx__pill' + (paid ? ' is-on' : '') + '" data-mode="paid" aria-pressed="' + (paid ? 'true' : 'false') + '">Paid</button>'
               + '<label class="ibx__price"' + (paid ? '' : ' hidden') + '><input type="number" class="ibx__price-in" min="3" max="500" step="1" placeholder="5" value="' + (paid ? a.price : '') + '" aria-label="Price in dollars"></label></div>';
            $wrap.html(h).prop('hidden', false);
            if (a.onchange) { a.onchange(); }
        };
        a.set = function (items) { a.list = items.slice(0, 10); a.render(); if (a.onchange) { a.onchange(); } };
        a.clear = function () { a.list = []; a.price = 0; a.render(); if (a.onchange) { a.onchange(); } };
        a.ids = function () { return a.list.map(function (m) { return m.id; }); };
        $wrap.on('click', '[data-rm]', function () { var id = parseInt($(this).data('rm'), 10); a.list = a.list.filter(function (m) { return m.id !== id; }); a.render(); if (a.onchange) { a.onchange(); } });
        $wrap.on('click', '.ibx__pill', function () {
            var paid = $(this).data('mode') === 'paid';
            $wrap.find('.ibx__pill').each(function () { var on = ($(this).data('mode') === 'paid') === paid; $(this).toggleClass('is-on', on).attr('aria-pressed', on ? 'true' : 'false'); });
            var $in = $wrap.find('.ibx__price-in');
            $wrap.find('.ibx__price').prop('hidden', !paid);
            if (paid) { if (!(parseInt($in.val(), 10) > 0)) { $in.val(5); } a.price = Math.min(500, Math.max(3, parseInt($in.val(), 10) || 5)); $in.trigger('focus').trigger('select'); }
            else { a.price = 0; }
            if (a.onchange) { a.onchange(); }
        });
        $wrap.on('input change', '.ibx__price-in', function (e) {
            var v = parseInt(this.value, 10); a.price = isNaN(v) || v <= 0 ? 0 : Math.min(500, v);
            if (e.type === 'change') { if (this.value === '' || a.price < 3) { a.price = 3; this.value = 3; } if (a.price > 500) { this.value = 500; } }
            if (a.onchange) { a.onchange(); }
        });
        return a;
    }
    var attachD = makeAttach($('#ibxAttach')), attachB = makeAttach($('#ibxBcastAttach'));
    function updateSendLabel() { $('#ibxSendLabel').text(attachD.price > 0 ? 'Send for ' + cr(attachD.price * 10).replace('credits', 'Credits').replace('credit', 'Credit') : 'Send'); }
    attachD.onchange = updateSendLabel;

    /* ---- library picker (creators) ---- */
    var pickTarget = null, pickSel = {}, pickItems = [], pickT = null;
    var pickerFromBcast = false;
    function openPicker(target) {
        pickTarget = target; pickSel = {}; target.list.forEach(function (m) { pickSel[m.id] = m; });
        $('#ibxPickSearch').val(''); loadPick('');
        // one modal at a time: the broadcast window steps aside while the library is open and comes back after
        pickerFromBcast = (target === attachB);
        if (pickerFromBcast) { var b = modal('ibxBcastModal'); if (b) { b.hide(); } }
        var m = modal('ibxPickerModal'); if (m) { m.show(); }
    }
    $('#ibxPickerModal').on('hidden.bs.modal', function () { if (pickerFromBcast) { pickerFromBcast = false; var b = modal('ibxBcastModal'); if (b) { b.show(); } } });
    function loadPick(q) {
        var $g = $('#ibxPickGrid').html('<p class="ibx__pickempty">Loading…</p>');
        api('message_media_list', { q: q || '' }, function (o) {
            if (!o || !o.success) { $g.html('<p class="ibx__pickempty">' + esc(o ? o.message : 'Could not load your library') + '</p>'); return; }
            pickItems = o.assets || []; renderPick();
        });
    }
    function renderPick() {
        var $g = $('#ibxPickGrid').empty();
        if (!pickItems.length) { $g.html('<p class="ibx__pickempty">Nothing in your library matches.</p>'); }
        pickItems.forEach(function (m) {
            $g.append('<button type="button" class="ibx__pick' + (pickSel[m.id] ? ' is-on' : '') + '" data-pick="' + m.id + '" style="background-image:url(\'' + esc(m.thumb) + '\')" aria-pressed="' + (pickSel[m.id] ? 'true' : 'false') + '" title="' + esc(m.name || '') + '">' + (m.type === 'video' ? '<i class="fa-solid fa-play"></i>' : '') + '<span class="ibx__pick-chk"><i class="fa-solid fa-check"></i></span></button>');
        });
        updatePickCount();
    }
    function updatePickCount() { var n = Object.keys(pickSel).length; $('#ibxPickCount').text(n ? (n + ' selected') : 'Nothing selected'); $('#ibxPickDone').text(n ? ('Attach ' + n) : 'Done'); }
    $('#ibxPickGrid').on('click', '.ibx__pick', function () {
        var id = parseInt($(this).data('pick'), 10), m = null; pickItems.forEach(function (x) { if (x.id === id) { m = x; } });
        if (pickSel[id]) { delete pickSel[id]; } else { if (Object.keys(pickSel).length >= 10) { toastErr('Up to 10 files per message'); return; } pickSel[id] = m; }
        $(this).toggleClass('is-on', !!pickSel[id]).attr('aria-pressed', pickSel[id] ? 'true' : 'false'); updatePickCount();
    });
    $('#ibxPickSearch').on('input', function () { var v = (this.value || '').trim(); clearTimeout(pickT); pickT = setTimeout(function () { loadPick(v); }, 250); });
    $('#ibxPickDone').on('click', function () {
        if (pickTarget) {
            var items = [];
            pickItems.forEach(function (m) { if (pickSel[m.id]) { items.push(m); } });
            Object.keys(pickSel).forEach(function (k) { if (!items.some(function (x) { return x.id === parseInt(k, 10); })) { items.push(pickSel[k]); } });
            pickTarget.set(items);
        }
        var m = modal('ibxPickerModal'); if (m) { m.hide(); }
        if (pickTarget === attachD) { setTimeout(function () { $input.trigger('focus'); }, 200); }
    });
    $('#ibxAttachBtn').on('click', function () { openPicker(attachD); });
    $('#ibxBcastAttachBtn').on('click', function () { openPicker(attachB); });

    /* ---- conversation list ---- */
    function loadConvs(cb) {
        api('message_inbox', {}, function (o) { if (o && o.success) { convs = o.conversations || []; renderConvs(); } if (cb) { cb(); } });
    }
    function convMatches(c) {
        if (filter === 'unread' && !(c.unread > 0)) { return false; }
        if (search) { var hay = (c.other_name + ' @' + c.other_handle + ' ' + (c.preview || '')).toLowerCase(); if (hay.indexOf(search) < 0) { return false; } }
        return true;
    }
    function renderConvs() {
        var $c = $convs.empty(), unread = 0, shown = 0;
        convs.forEach(function (c) { if (c.unread > 0) { unread++; } });
        $('#ibxUnreadN').text(unread ? unread : '');
        convs.forEach(function (c) {
            if (!convMatches(c)) { return; }
            shown++;
            var cb = c.other_is_creator ? ' <i class="fa-solid fa-circle-check ibx-badge" title="Creator"></i>' : '';
            var pv = (c.last_mine ? 'You: ' : '') + (c.preview || '');
            var un = c.unread > 0 ? '<span class="ibx-conv__unread">' + c.unread + '</span>' : '';
            $c.append('<button type="button" class="ibx-conv' + (c.unread > 0 ? ' is-unread' : '') + (c.id === active ? ' is-active' : '') + '" data-id="' + c.id + '">'
                + av(c.other_avatar, c.other_name)
                + '<span class="ibx-conv__body"><span class="ibx-conv__top"><span class="ibx-conv__name">' + esc(c.other_name) + cb + '</span><span class="ibx-conv__time">' + ftime(c.last_at) + '</span></span>'
                + '<span class="ibx-conv__prev">' + esc(pv) + '</span></span>' + un + '</button>');
        });
        if (!shown) {
            $c.html('<p class="ibx__note">' + (convs.length ? (filter === 'unread' && !search ? 'Nothing unread.' : 'No conversations match.') : 'No messages yet. Start one with the compose button.') + '</p>');
        }
    }
    $convs.on('click', '.ibx-conv', function () { var $b = $(this), id = parseInt($b.data('id'), 10); var c = convs.filter(function (x) { return x.id === id; })[0]; openThread(id, c ? { id: c.other_id, name: c.other_name, handle: c.other_handle, avatar: c.other_avatar, is_creator: c.other_is_creator } : null, true); });
    $('.ibx__tab').on('click', function () { filter = $(this).data('filter'); $('.ibx__tab').removeClass('is-on'); $(this).addClass('is-on'); renderConvs(); });
    var searchT = null;
    $('#ibxSearch').on('input', function () { var v = (this.value || '').trim().toLowerCase(); clearTimeout(searchT); searchT = setTimeout(function () { search = v; renderConvs(); }, 150); });

    /* ---- thread ---- */
    function setPeer(p) {
        activePeer = p || activePeer;
        if (!activePeer) { return; }
        var p2 = activePeer;
        $('#ibxPeerAv').replaceWith(av(p2.avatar, p2.name).replace('class="ibx__av"', 'class="ibx__av" id="ibxPeerAv"'));
        $('#ibxPeerName').html(esc(p2.name || '') + (p2.is_creator ? ' <i class="fa-solid fa-circle-check ibx-badge" title="Creator"></i>' : ''));
        $('#ibxPeerSub').text((p2.handle ? '@' + p2.handle + ' · ' : '') + (p2.is_creator ? 'Creator' : 'Member'));
        var $prof = $('#ibxPeerProfile');
        if (p2.is_creator && p2.handle) { $prof.attr('href', '/@' + encodeURIComponent(p2.handle)).prop('hidden', false); } else { $prof.prop('hidden', true); }
        var pid = parseInt(p2.id, 10) || 0;
        $('#ibxPeerBlock').attr('data-block-user', pid).attr('data-block-name', p2.handle ? '@' + p2.handle : (p2.name || 'this account')).prop('hidden', !pid);
    }
    // After a block (button in this header, or anywhere else on the page) the thread and its row disappear.
    $(document).on('cls:blocked', function (e, id) {
        if (activePeer && (parseInt(activePeer.id, 10) || 0) === id) { showList(); }
        loadConvs();
    });
    function showPane() { $blank.prop('hidden', true); $pane.prop('hidden', false); $root.addClass('is-thread'); }
    function showList() { active = 0; $root.removeClass('is-thread'); $pane.prop('hidden', true); $blank.prop('hidden', false); closeCtx(); renderConvs(); history.replaceState(null, '', '/inbox'); }
    function applyThread(o, fromUser) {
        active = o.conversation_id; iAmCreator = !!o.i_am_creator; viewerCredits = o.viewer_credits || 0;
        setPeer(o.other); renderMsgs(o.messages);
        $('#ibxAttachBtn').prop('hidden', !(IS_CREATOR && iAmCreator));
        $('#ibxCtxToggle').prop('hidden', !(IS_CREATOR && iAmCreator));
        if (!(IS_CREATOR && iAmCreator)) { attachD.clear(); closeCtx(); } else { loadCtx(); }
        // the list's unread count for this thread is now 0
        convs.forEach(function (c) { if (c.id === active) { c.unread = 0; } });
        renderConvs();
        if (window.CLSMessenger && window.CLSMessenger.badge) { window.CLSMessenger.badge(); }
        if (fromUser) { setTimeout(function () { $input.trigger('focus'); }, 50); }
    }
    function openThread(id, peer, fromUser) {
        active = id; showPane(); setPeer(peer); attachD.clear(); $scroll.html('<div class="ibx__hint">Loading…</div>');
        history.replaceState(null, '', '/inbox/thread/' + id);
        var req = ++threadReq;
        api('message_thread', { conversation_id: id }, function (o) { if (req !== threadReq) { return; } if (!o || !o.success) { toastErr(o ? o.message : 'Could not open conversation'); showList(); return; } applyThread(o, fromUser); });
    }
    function openWith(uid, preset) {
        showPane(); setPeer({ id: uid, name: '', handle: '', avatar: '', is_creator: false }); attachD.clear(); $scroll.html('<div class="ibx__hint">Loading…</div>');
        var req = ++threadReq;
        api('message_open', { to_creator: uid }, function (o) {
            if (req !== threadReq) { return; }
            if (!o || !o.success) { toastErr(o ? o.message : 'Could not open conversation'); showList(); return; }
            history.replaceState(null, '', '/inbox/thread/' + o.conversation_id);
            applyThread(o, true);
            if (preset && preset.assets && IS_CREATOR && iAmCreator) { if (preset.price > 0) { attachD.price = preset.price; } attachD.set(preset.assets); }
            if (!convs.some(function (c) { return c.id === o.conversation_id; })) { loadConvs(); }
        });
    }
    function mediaLabel(m) { var n = m.media_count || (m.assets || []).length, v = (m.assets || []).filter(function (a) { return a.type === 'video'; }).length; if (n === 1) { return v ? '1 video' : '1 photo'; } if (v === n) { return n + ' videos'; } if (!v) { return n + ' photos'; } return n + ' items'; }
    function bubble(m) {
        var cls = 'ibx-bubble ' + (m.mine ? 'ibx-bubble--mine' : 'ibx-bubble--theirs') + ((m.assets && m.assets.length) ? ' ibx-bubble--media' : '') + (m.auto ? ' ibx-bubble--auto' : '');
        var h = '<div class="' + cls + '" data-mid="' + m.id + '">';
        if (m.auto && m.mine) { h += '<span class="ibx-bubble__auto">Automatic</span>'; }
        if (m.assets && m.assets.length) {
            if (m.mine && m.price_credits > 0 && !m.revealed) {
                // the creator's own paid message is rendered exactly as the fan gets it (same cover, same button)
                var cover0 = m.assets[0].blurred || m.assets[0].thumb || '';
                h += '<div class="ibx-lock" style="background-image:url(\'' + esc(cover0) + '\')"><i class="fa-solid fa-lock ibx-lock__ico"></i><span class="ibx-lock__meta">' + esc(mediaLabel(m)) + '</span>'
                   + '<button type="button" class="ibx-lock__btn" data-preview="' + m.id + '">Unlock for ' + crT(m.price_credits) + '</button></div>';
            } else if (m.locked) {
                var cover = m.assets[0].locked_url || '';
                h += '<div class="ibx-lock" style="background-image:url(\'' + esc(cover) + '\')"><i class="fa-solid fa-lock ibx-lock__ico"></i><span class="ibx-lock__meta">' + esc(mediaLabel(m)) + '</span>'
                   + '<button type="button" class="ibx-lock__btn" data-unlock="' + m.id + '">Unlock for ' + crT(m.price_credits) + '</button>'
                   + (viewerCredits < m.price_credits ? '<span class="ibx-lock__note">You have ' + cr(viewerCredits) + '</span><a class="ibx-lock__btn ibx-lock__btn--buy" href="' + esc(WALLET) + '">Buy Credits</a>' : '')
                   + '</div>';
            } else {
                var n = m.assets.length, show = m.assets.slice(0, 4);
                h += '<div class="ibx-media ' + (n === 1 ? 'ibx-media--1' : 'ibx-media--n') + '">';
                show.forEach(function (a, i) {
                    var extra = (i === 3 && n > 4) ? '<span class="ibx-media__more">+' + (n - 4) + '</span>' : (a.type === 'video' ? '<span class="ibx-media__play"><i class="fa-solid fa-circle-play"></i></span>' : '');
                    h += '<button type="button" class="ibx-media__item" style="background-image:url(\'' + esc(a.thumb || a.poster || a.url) + '\')" data-view="' + m.id + '" data-idx="' + i + '" aria-label="' + (a.type === 'video' ? 'Play video' : 'View photo') + '">' + extra + '</button>';
                });
                h += '</div>';
            }
        }
        if (m.body) { h += '<span class="ibx-bubble__body">' + esc(m.body).replace(/\n/g, '<br>') + '</span>'; }
        if (m.mine && m.price_credits > 0) { h += '<span class="ibx-bubble__sale">' + cr(m.price_credits) + ' · ' + (m.unlocks === 1 ? 'Unlocked by 1 fan' : 'Unlocked by ' + (m.unlocks || 0) + ' fans') + '</span>'; }
        else if (!m.mine && m.unlocked) { h += '<span class="ibx-bubble__sale">Unlocked · ' + cr(m.price_credits) + '</span>'; }
        h += '<span class="ibx-bubble__time">' + fclock(m.created_at) + '</span>';
        if (m.mine && !(m.price_credits > 0 && m.unlocks > 0)) { h += '<button type="button" class="ibx-bubble__del" data-del="' + m.id + '" aria-label="Delete message" title="Delete"><i class="fa-regular fa-trash-can"></i></button>'; }
        h += '</div>';
        var $b = $(h); $b.data('msg', m); return $b;
    }
    function renderMsgs(list) {
        var $s = $scroll.empty();
        if (!list.length) { $s.html('<div class="ibx__hint">No messages yet. Say hello.</div>'); return; }
        var lastDay = '';
        list.forEach(function (m) { var d = fday(m.created_at); if (d !== lastDay) { $s.append('<span class="ibx__day">' + esc(d) + '</span>'); lastDay = d; } $s.append(bubble(m)); });
        $s.scrollTop($s[0].scrollHeight);
    }
    function addMsg(m) { $scroll.find('.ibx__hint').remove(); if (!$scroll.find('.ibx__day').length) { $scroll.append('<span class="ibx__day">' + esc(fday(m.created_at)) + '</span>'); } $scroll.append(bubble(m)); $scroll.scrollTop($scroll[0].scrollHeight); }
    function replaceBubble(m) { var $old = $scroll.find('.ibx-bubble[data-mid="' + m.id + '"]'); if ($old.length) { $old.replaceWith(bubble(m)); } }
    $('#ibxBack').on('click', showList);

    /* ---- delete: a message you sent (for both people), or a whole conversation (for you) ---- */
    function confirmDelete(opts) {
        if (typeof Swal === 'undefined') { return Promise.resolve(window.confirm(opts.title)); }
        return Swal.fire({ title: opts.title, text: opts.text, showCancelButton: true, reverseButtons: true, focusCancel: true, confirmButtonText: opts.button, cancelButtonText: 'Cancel', customClass: { popup: 'ibx-swal ibx-swal--danger' } })
            .then(function (r) { return r.isConfirmed; });
    }
    $scroll.on('click', '[data-del]', function () {
        var $b = $(this), mid = parseInt($b.attr('data-del'), 10), $bub = $b.closest('.ibx-bubble');
        confirmDelete({ title: 'Delete this message?', text: 'It is removed for you and ' + (activePeer && activePeer.name ? activePeer.name : 'the other person') + '. This can\'t be undone.', button: 'Delete' }).then(function (yes) {
            if (!yes) { return; }
            $b.prop('disabled', true);
            api('message_delete', { message_id: mid }, function (o) {
                if (!o || !o.success) { $b.prop('disabled', false); toastErr((o && o.message) || 'Could not delete the message'); return; }
                $bub.remove();
                // drop a day label left with nothing under it
                $scroll.find('.ibx__day').each(function () { var $n = $(this).next(); if (!$n.length || $n.hasClass('ibx__day')) { $(this).remove(); } });
                if (!$scroll.find('.ibx-bubble').length) { $scroll.html('<div class="ibx__hint">No messages yet. Say hello.</div>'); }
                lastSig = ''; loadConvs();
                if (window.toastr) { toastr.success('Message deleted'); }
            });
        });
    });
    $('#ibxConvDelete').on('click', function () {
        if (!active) { return; }
        var id = active, who = activePeer && activePeer.name ? activePeer.name : 'this person';
        confirmDelete({ title: 'Delete this conversation?', text: 'It is removed from your inbox and cleared for you. ' + who + ' keeps their copy.', button: 'Delete Conversation' }).then(function (yes) {
            if (!yes) { return; }
            api('conversation_delete', { conversation_id: id }, function (o) {
                if (!o || !o.success) { toastErr((o && o.message) || 'Could not delete the conversation'); return; }
                convs = convs.filter(function (c) { return c.id !== id; });
                showList(); loadConvs();
                if (window.CLSMessenger && window.CLSMessenger.badge) { window.CLSMessenger.badge(); }
                if (window.toastr) { toastr.success('Conversation deleted'); }
            });
        });
    });

    /* lightbox */
    $scroll.on('click', '[data-view]', function () {
        var m = $(this).closest('.ibx-bubble').data('msg'); if (!m) { return; }
        var a = (m.assets || [])[parseInt($(this).data('idx'), 10)]; if (!a || !a.url) { return; }
        $('#ibxLightboxBody').html(a.type === 'video' ? '<video controls autoplay playsinline src="' + esc(a.url) + '" poster="' + esc(a.poster || '') + '"></video>' : '<img src="' + esc(a.url) + '" alt="">');
        $('#ibxLightbox').prop('hidden', false); $('#ibxLightboxClose').trigger('focus');
    });
    function closeLightbox() { $('#ibxLightboxBody').empty(); $('#ibxLightbox').prop('hidden', true); }
    $('#ibxLightboxClose').on('click', closeLightbox);
    $('#ibxLightbox').on('click', function (e) { if (e.target === this) { closeLightbox(); } });
    $(document).on('keydown', function (e) { if (e.key === 'Escape' && !$('#ibxLightbox').prop('hidden')) { closeLightbox(); } });

    /* confirm before any charge. Short balance → offer to buy credits instead. */
    function confirmUnlock(m, balance, onYes) {
        var price = m.price_credits;
        if (typeof Swal === 'undefined') { onYes(); return; }
        if (balance < price) {
            Swal.fire({ title: 'Not enough credits', html: 'This unlock is <b>' + cr(price) + '</b>. You have ' + cr(balance) + ', so you need ' + cr(price - balance) + ' more.', showCancelButton: true, confirmButtonText: 'Buy Credits', cancelButtonText: 'Not Now', customClass: { popup: 'ibx-swal' } })
                .then(function (r) { if (r.isConfirmed) { window.location.href = WALLET; } });
            return;
        }
        Swal.fire({ title: 'Unlock for ' + cr(price) + '?', html: esc(mediaLabel(m)) + '. You have ' + cr(balance) + ' · ' + cr(balance - price) + ' left after.', showCancelButton: true, confirmButtonText: 'Pay ' + crT(price), cancelButtonText: 'Cancel', customClass: { popup: 'ibx-swal' } })
            .then(function (r) { if (r.isConfirmed) { onYes(); } });
    }
    // creator side: the same button does what it does for the fan — confirmation, then the cover swaps to the opened media
    $scroll.on('click', '[data-preview]', function () { var m = $(this).closest('.ibx-bubble').data('msg'); if (!m) { return; } confirmUnlock(m, m.price_credits, function () { m.revealed = true; replaceBubble(m); }); });

    /* unlock in place */
    $scroll.on('click', '[data-unlock]', function () {
        var $btn = $(this), m0 = $btn.closest('.ibx-bubble').data('msg'); if (!m0) { return; }
        confirmUnlock(m0, viewerCredits, function () { doUnlock($btn); });
    });
    function doUnlock($b) {
        $b.prop('disabled', true).text('Unlocking…'); var mid = parseInt($b.data('unlock'), 10);
        unlocking++;
        api('message_unlock', { message_id: mid }, function (o) {
            unlocking--;
            if (!o || !o.success) {
                var mm = $b.closest('.ibx-bubble').data('msg'); $b.prop('disabled', false).text('Unlock for ' + crT(mm ? mm.price_credits : 0));
                if (o && o.need_credits) { viewerCredits = o.balance || 0; toastErr(o.message || 'Not enough credits'); var m = $b.closest('.ibx-bubble').data('msg'); if (m) { replaceBubble(m); } return; }
                toastErr(o ? o.message : 'Could not unlock'); return;
            }
            if (o.balance != null) { viewerCredits = o.balance; }
            if (o.message) { replaceBubble(o.message); }
            if (window.toastr && !o.already) { toastr.success('Unlocked · ' + cr(o.message ? o.message.price_credits : 0) + ' · ' + cr(viewerCredits) + ' left'); }
        });
    }

    /* compose */
    var sending = false;   // one message in flight at a time (a held Enter key must not send it twice)
    // A failed request never reaches the api() callback; release the composer when any message_send finishes.
    $(document).ajaxComplete(function (e, xhr, s) { if (s && /\/api\/message_send/.test(s.url || '') && (xhr.status < 200 || xhr.status >= 300)) { sending = false; $('#ibxSend').prop('disabled', false); } });
    $('#ibxCompose').on('submit', function (e) {
        e.preventDefault();
        if (sending) { return; }
        var body = ($input.val() || '').trim(); if ((body === '' && !attachD.list.length) || !active) { return; }
        sending = true;
        $('#ibxSend').prop('disabled', true);
        api('message_send', { conversation_id: active, body: body, asset_ids: attachD.ids(), price: attachD.price }, function (o) {
            sending = false;
            $('#ibxSend').prop('disabled', false);
            if (!o || !o.success) { toastErr(o ? o.message : 'Could not send'); return; }
            $input.val('').css('height', 'auto'); attachD.clear(); if (o.sent) { addMsg(o.sent); }
            convs.forEach(function (c) { if (c.id === active) { c.preview = o.sent ? (o.sent.body || 'Sent media') : body; c.last_mine = true; c.last_at = o.sent ? o.sent.created_at : c.last_at; } });
            convs.sort(function (a, b) { return String(b.last_at).localeCompare(String(a.last_at)); }); renderConvs();
            $input.trigger('focus');
        });
    });
    $input.on('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (!e.originalEvent || !e.originalEvent.repeat) { $('#ibxCompose').trigger('submit'); } } })
          .on('input', function () { this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 160) + 'px'; });

    /* ---- about this fan (creator side) ---- */
    function loadCtx() {
        if (!IS_CREATOR || !active) { return; }
        var id = active;
        api('message_peer_info', { conversation_id: id }, function (o) {
            if (!o || !o.success || id !== active) { return; }
            var i = o.info || {}, h = '';
            var rel = i.subscription && i.subscription.status === 'active' ? ((i.subscription.free ? 'Free member' : 'Subscriber') + (i.subscription.plan ? '<small>' + esc(i.subscription.plan) + ' · since ' + esc(fdate(i.subscription.since)) + '</small>' : '')) : (i.subscription ? 'Former subscriber<small>' + esc(i.subscription.plan || '') + '</small>' : (i.follows ? 'Follower<small>since ' + esc(fdate(i.followed_at)) + '</small>' : 'No follow or membership'));
            h += '<div class="ibx__fact"><span class="ibx__fact-k">Relationship</span><span class="ibx__fact-v">' + rel + '</span></div>';
            h += '<div class="ibx__fact"><span class="ibx__fact-k">Spent with you</span><span class="ibx__fact-v">' + money(i.spent_credits) + '<small>' + (i.purchases === 1 ? '1 purchase' : (i.purchases || 0) + ' purchases') + '</small></span></div>';
            h += '<div class="ibx__fact"><span class="ibx__fact-k">Member since</span><span class="ibx__fact-v">' + esc(fdate(i.member_since)) + (i.last_active ? '<small>last seen ' + esc(fdate(i.last_active)) + '</small>' : '') + '</span></div>';
            h += '<a class="ibx__ctxlink" href="/audience?q=' + encodeURIComponent(activePeer && activePeer.handle ? activePeer.handle : '') + '"><i class="fa-solid fa-users"></i> Open in Audience</a>';
            $('#ibxCtxBody').html(h);
            if (window.innerWidth > 1180) { $('#ibxCtx').prop('hidden', false); $root.addClass('has-ctx'); }
        });
    }
    function closeCtx() { $('#ibxCtx').prop('hidden', true); $root.removeClass('has-ctx'); }
    $('#ibxCtxToggle').on('click', function () { var $c = $('#ibxCtx'); var open = $c.prop('hidden'); $c.prop('hidden', !open); $root.toggleClass('has-ctx', open); });
    $('#ibxCtxClose').on('click', closeCtx);

    /* ---- people picker ---- */
    var peopleT = null, composePreset = null;
    function loadPeople(q) {
        api('message_people', { q: q || '' }, function (o) {
            var h = $('#ibxPeople').empty();
            if (!o || !o.success) { h.html('<p class="ibx__note">' + esc(o ? o.message : 'Could not load people') + '</p>'); return; }
            var list = o.people || [];
            if (!list.length) { h.html('<p class="ibx__note">' + (o.is_search ? 'No one matches that search.' : 'Follow or subscribe to a creator, or gain a follower, to start a conversation.') + '</p>'); return; }
            list.forEach(function (c) {
                var cb = c.is_creator ? ' <i class="fa-solid fa-circle-check ibx-badge" title="Creator"></i>' : '';
                h.append('<button type="button" class="ibx-conv" data-pid="' + c.id + '" data-name="' + esc(c.name) + '" data-handle="' + esc(c.handle) + '" data-avatar="' + esc(c.avatar) + '" data-creator="' + (c.is_creator ? 1 : 0) + '">'
                    + av(c.avatar, c.name)
                    + '<span class="ibx-conv__body"><span class="ibx-conv__top"><span class="ibx-conv__name">' + esc(c.name) + cb + '</span></span>'
                    + '<span class="ibx-conv__prev">@' + esc(c.handle) + '</span></span></button>');
            });
        });
    }
    function openPeople(preset) {
        composePreset = preset || null;
        $('#ibxPeopleSearch').val(''); $('#ibxPeople').html('<p class="ibx__note">Loading…</p>'); loadPeople('');
        var m = modal('ibxPeopleModal'); if (m) { m.show(); }
        setTimeout(function () { $('#ibxPeopleSearch').trigger('focus'); }, 300);
    }
    $('#ibxNew').on('click', function () { openPeople(null); });
    $('#ibxPeopleSearch').on('input', function () { var v = (this.value || '').trim(); clearTimeout(peopleT); peopleT = setTimeout(function () { loadPeople(v); }, 250); });
    $('#ibxPeople').on('click', '.ibx-conv', function () {
        var $b = $(this), pr = composePreset; composePreset = null;
        var m = modal('ibxPeopleModal'); if (m) { m.hide(); }
        openWith(parseInt($b.data('pid'), 10), pr);
    });

    /* ---- broadcast ---- */
    var bsegs = { all: true }, bcounts = {};
    function renderSegs() {
        var $s = $('#ibxSegs').empty();
        SEGMENTS.forEach(function (k) { $s.append('<button type="button" class="ibx__seg' + (bsegs[k] ? ' is-on' : '') + '" data-seg="' + k + '" aria-pressed="' + (bsegs[k] ? 'true' : 'false') + '">' + esc(SEG_LABELS[k] || k) + ' <b data-n="' + k + '">' + (bcounts[k] != null ? bcounts[k] : '') + '</b></button>'); });
    }
    function segList() { return Object.keys(bsegs).filter(function (k) { return bsegs[k]; }); }
    function updateBcastSend() {
        var segs = segList(), n = 0; segs.forEach(function (k) { n += (bcounts[k] || 0); });
        var has = ($('#ibxBcastBody').val() || '').trim() !== '' || attachB.list.length > 0;
        var label = segs.length === 1 && bcounts[segs[0]] != null ? 'Send to ' + bcounts[segs[0]] : 'Send';
        if (attachB.price > 0) { label += ' for ' + crT(attachB.price * 10) + ' Each'; }
        $('#ibxBcastSend').text(label).prop('disabled', !segs.length || n <= 0 || !has);
    }
    attachB.onchange = updateBcastSend;
    $('#ibxBroadcast').on('click', function () {
        renderSegs(); updateBcastSend();
        api('broadcast_info', {}, function (o) { if (o && o.success) { bcounts = o.counts || {}; Object.keys(bcounts).forEach(function (k) { $('#ibxSegs [data-n="' + k + '"]').text(bcounts[k]); }); updateBcastSend(); } });
        var m = modal('ibxBcastModal'); if (m) { m.show(); }
        setTimeout(function () { $('#ibxBcastBody').trigger('focus'); }, 300);
    });
    $('#ibxSegs').on('click', '.ibx__seg', function () { var k = $(this).data('seg'); bsegs[k] = !bsegs[k]; $(this).toggleClass('is-on', !!bsegs[k]).attr('aria-pressed', bsegs[k] ? 'true' : 'false'); updateBcastSend(); });
    $('#ibxBcastBody').on('input', updateBcastSend);
    $('#ibxBcastForm').on('submit', function (e) {
        e.preventDefault();
        var body = ($('#ibxBcastBody').val() || '').trim(), segs = segList(); if ((body === '' && !attachB.list.length) || !segs.length) { return; }
        $('#ibxBcastSend').prop('disabled', true);
        api('broadcast_send', { segments: segs, body: body, asset_ids: attachB.ids(), price: attachB.price }, function (o) {
            if (!o || !o.success) { $('#ibxBcastSend').prop('disabled', false); toastErr(o ? o.message : 'Could not send broadcast'); return; }
            $('#ibxBcastBody').val(''); attachB.clear();
            var m = modal('ibxBcastModal'); if (m) { m.hide(); }
            if (window.toastr) { toastr.success(o.message || ('Sent to ' + o.count)); }
            loadConvs();
        });
    });

    /* ---- polling: list every 15 s, open thread every 8 s (render only when something changed) ---- */
    var lastSig = '';
    setInterval(function () {
        if (document.hidden) { return; }
        loadConvs();
        if (active && !unlocking) {
            var id = active;
            api('message_thread', { conversation_id: id }, function (o) {
                if (!o || !o.success || o.conversation_id !== active || unlocking) { return; }
                viewerCredits = o.viewer_credits || 0;
                var sig = (o.messages || []).map(function (m) { return m.id + ':' + (m.locked ? 'l' : 'o') + ':' + (m.unlocks || 0); }).join(',');
                if (sig === lastSig) { return; }
                lastSig = sig;
                var atBottom = ($scroll[0].scrollHeight - $scroll.scrollTop() - $scroll.height()) < 60, top = $scroll.scrollTop();
                renderMsgs(o.messages); if (!atBottom) { $scroll.scrollTop(top); }
            });
        }
    }, 8000);

    /* ---- initial state: deep links and hand-offs from other pages ---- */
    var preset = null; try { var raw = sessionStorage.getItem('cls_inbox_preset'); if (raw) { preset = JSON.parse(raw); sessionStorage.removeItem('cls_inbox_preset'); } } catch (e) {}
    loadConvs(function () {
        if (CFG.conversation_id) {
            var c = convs.filter(function (x) { return x.id === CFG.conversation_id; })[0];
            openThread(CFG.conversation_id, c ? { id: c.other_id, name: c.other_name, handle: c.other_handle, avatar: c.other_avatar, is_creator: c.other_is_creator } : null, false);
        } else if (CFG.to_user) {
            openWith(CFG.to_user, preset);
        } else if (CFG.compose) {
            openPeople(preset);
        }
    });
})();
