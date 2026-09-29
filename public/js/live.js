/* CLS Video (views/live/_room.php). Lobby: preview camera + mic, pick devices. Join: ask /api/live_join for a pass
   (or wait there until the host lets them in), connect to our LiveKit server, show everyone as tiles. Everyone: chat,
   raise hand. Host: people (admit, mute, let talk, spotlight, remove) and settings (waiting room, screen sharing,
   watch-only, chat, lock), which reach every page through the room metadata (LiveControl).
   LiveKit browser SDK: https://docs.livekit.io (global LivekitClient). */
(function () {
    var root = document.getElementById('lv');
    if (!root || !window.LivekitClient) { return; }
    var LK = window.LivekitClient;
    var kind = root.getAttribute('data-kind'), id = parseInt(root.getAttribute('data-id'), 10);
    var is_host = root.getAttribute('data-host') === '1';
    var is_guest = root.getAttribute('data-guest') === '1', needs_pw = root.getAttribute('data-needs-pw') === '1';
    function el(x) { return document.getElementById(x); }
    function parse(r) { if (r && typeof r === 'object') { return r; } try { return JSON.parse(r); } catch (e) { return {}; } }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function initials(name) { return String(name || '?').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('') || '?'; }
    function show(section) { ['lvLobby', 'lvCall', 'lvEnd'].forEach(function (s) { el(s).hidden = s !== section; }); }
    function note(text, cls) { var n = el('lvNote'); n.hidden = !text; n.className = 'lv-note' + (cls ? ' lv-note--' + cls : ''); n.textContent = text || ''; }
    function time_label(ts) { return new Date(ts * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }

    /* ---------------- Lobby ---------------- */
    var mic_on = true, cam_on = true, preview = { audio: null, video: null }, meter = null;
    var my_name = root.getAttribute('data-me') || '';

    function set_toggle(btn, on, icon_on, icon_off) {
        if (btn.getAttribute('aria-pressed') === (on ? 'true' : 'false') && btn.getAttribute('data-icon')) { return; }
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.setAttribute('data-icon', '1');
        // The Font Awesome kit swaps <i> for <svg>, so redraw the icon rather than editing it.
        btn.innerHTML = '<i class="fa-solid ' + (on ? icon_on : icon_off) + '" aria-hidden="true"></i>';
    }
    function stop_preview() {
        if (meter) { clearInterval(meter.timer); try { meter.ctx.close(); } catch (e) {} meter = null; }
        ['audio', 'video'].forEach(function (k) { if (preview[k]) { preview[k].stop(); preview[k] = null; } });
        el('lvPreviewVideo').srcObject = null;
    }
    function start_meter(track) {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var src = ctx.createMediaStreamSource(new MediaStream([track.mediaStreamTrack]));
            var an = ctx.createAnalyser(); an.fftSize = 256; src.connect(an);
            var buf = new Uint8Array(an.frequencyBinCount);
            meter = { ctx: ctx, timer: setInterval(function () {
                an.getByteFrequencyData(buf); var s = 0; for (var i = 0; i < buf.length; i++) { s += buf[i]; }
                el('lvLevel').style.width = (mic_on ? Math.min(100, (s / buf.length) * 1.6) : 0) + '%';
            }, 80) };
        } catch (e) {}
    }
    function fill_devices(sel, list, current) {
        sel.innerHTML = list.map(function (d, i) { return '<option value="' + esc(d.deviceId) + '">' + esc(d.label || ('Device ' + (i + 1))) + '</option>'; }).join('');
        if (current) { sel.value = current; }
        sel.disabled = list.length === 0;
    }
    function start_preview() {
        stop_preview();
        el('lvRetryDevices').hidden = true;
        var opts = {};
        if (el('lvMicSel').value) { opts.audio = { deviceId: el('lvMicSel').value }; } else { opts.audio = true; }
        if (el('lvCamSel').value) { opts.video = { deviceId: el('lvCamSel').value }; } else { opts.video = true; }
        return LK.createLocalTracks(opts).then(function (tracks) {
            tracks.forEach(function (t) { preview[t.kind] = t; });
            if (preview.video) { preview.video.attach(el('lvPreviewVideo')); }
            if (preview.audio) { start_meter(preview.audio); }
            update_preview();
            return Promise.all([LK.Room.getLocalDevices('audioinput'), LK.Room.getLocalDevices('videoinput')]).then(function (d) {
                fill_devices(el('lvMicSel'), d[0], preview.audio && preview.audio.mediaStreamTrack.getSettings().deviceId);
                fill_devices(el('lvCamSel'), d[1], preview.video && preview.video.mediaStreamTrack.getSettings().deviceId);
            });
        }).catch(function (err) {
            // No permission, or no camera/mic: they can still join to watch and listen.
            mic_on = false; cam_on = false; update_preview();
            var denied = err && (err.name === 'NotAllowedError' || /denied|permission/i.test(String(err.message)));
            var insecure = !window.isSecureContext || !navigator.mediaDevices;   // browsers only allow cameras on https pages
            note(insecure ? 'Your browser only allows the camera and microphone on secure (https) pages. You can still join to watch and listen.'
                : (denied ? 'Your browser blocked the camera and microphone. Allow them in the address bar, or join to watch and listen.'
                          : 'No camera or microphone was found. You can still join to watch and listen.'), 'error');
            el('lvRetryDevices').hidden = insecure;   // trying again can't help on a page that isn't secure
            if (!insecure) { el('lvRetryDevices').hidden = false; }
            fill_devices(el('lvMicSel'), [], ''); fill_devices(el('lvCamSel'), [], '');
        });
    }
    function update_preview() {
        set_toggle(el('lvPvMic'), mic_on && !!preview.audio, 'fa-microphone', 'fa-microphone-slash');
        set_toggle(el('lvPvCam'), cam_on && !!preview.video, 'fa-video', 'fa-video-slash');
        el('lvPvMic').disabled = !preview.audio; el('lvPvCam').disabled = !preview.video;
        var cam_visible = cam_on && !!preview.video;
        el('lvPreviewVideo').hidden = !cam_visible; el('lvPreviewOff').hidden = cam_visible;
        el('lvPreviewAvatar').textContent = initials(my_name);
        if (preview.video) { cam_on ? preview.video.unmute() : preview.video.mute(); }
    }
    if (el('lvName')) {   // guests: the preview shows the initials of the name they type
        el('lvName').addEventListener('input', function () { my_name = el('lvName').value.trim(); el('lvPreviewAvatar').textContent = initials(my_name); });
    }
    el('lvPvMic').addEventListener('click', function () { mic_on = !mic_on; update_preview(); });
    el('lvPvCam').addEventListener('click', function () { cam_on = !cam_on; update_preview(); });
    el('lvMicSel').addEventListener('change', function () { mic_on = true; start_preview(); });
    el('lvCamSel').addEventListener('change', function () { cam_on = true; start_preview(); });
    el('lvRetryDevices').addEventListener('click', function () { mic_on = true; cam_on = true; note(''); start_preview(); });

    /* The call may not be open yet (events open 15 minutes before the start). */
    var wait_timer = null;
    function lobby_ready() {
        var opens = parseInt(root.getAttribute('data-opens'), 10) || 0, now = Date.now() / 1000;
        var wait = el('lvWait');
        if (!is_host && opens > now) {
            el('lvJoin').disabled = true;
            wait.hidden = false; wait.textContent = 'The call opens at ' + time_label(opens) + '. This page will let you in then.';
            clearTimeout(wait_timer); wait_timer = setTimeout(lobby_ready, Math.min(60000, (opens - now) * 1000 + 500));
            return;
        }
        wait.hidden = true;
        el('lvJoin').disabled = false;
    }

    /* ---------------- In the call ---------------- */
    var room = null, started = 0, clock = null, leaving = false, me_id = '';
    // The call's settings (LiveControl::meta): from the join reply, then live from the room metadata.
    var state = { waiting: false, share: 'host', watch: false, chat: true, locked: false, spotlight: '', speakers: [] };
    var SRC = { camera: 1, microphone: 2, screen_share: 3 };   // LiveKit TrackSource numbers in permissions

    el('lvJoin').addEventListener('click', join);
    el('lvRejoin').addEventListener('click', function () { show('lvLobby'); start_preview().then(lobby_ready); });

    function field_error(input, msg) {
        input.classList.toggle('is-invalid', !!msg);
        var err = input.parentNode.querySelector('.invalid-feedback');
        if (!err) { err = document.createElement('div'); err.className = 'invalid-feedback'; input.parentNode.appendChild(err); }
        err.textContent = msg || '';
        if (msg) { input.focus(); }
    }

    /* Waiting room: the page asks again every few seconds until the host lets them in (or turns them away). */
    var wait_poll = null;
    function stop_waiting() { clearTimeout(wait_poll); wait_poll = null; el('lvJoin').textContent = 'Join Call'; }
    function join_body() {
        var body = { kind: kind, id: id };
        if (is_guest) {
            var nm = el('lvName').value.trim();
            if (!nm) { field_error(el('lvName'), 'Enter the name others will see.'); return null; }
            field_error(el('lvName'), ''); body.name = nm; my_name = nm;
        }
        if (needs_pw) {
            var pw = el('lvPw').value.trim();
            if (!pw) { field_error(el('lvPw'), 'Enter the call password from the host.'); return null; }
            field_error(el('lvPw'), ''); body.password = pw;
        }
        return body;
    }
    function join() {
        if (wait_poll) { stop_waiting(); note(''); el('lvJoin').disabled = false; return; }   // "Stop Waiting"
        var body = join_body(); if (!body) { return; }
        var btn = el('lvJoin'); btn.disabled = true; btn.textContent = 'Joining…';
        ask(body);
    }
    function ask(body) {
        var btn = el('lvJoin');
        ApiDataSvc.apiCall('post', 'live_join', body, function (data) {
            var r = parse(data);
            if (r.success && r.waiting) {
                note('Waiting for the host to let you in. Keep this page open.', 'wait');
                btn.disabled = false; btn.textContent = 'Stop Waiting';
                wait_poll = setTimeout(function () { ask(body); }, 3000);
                return;
            }
            stop_waiting();
            if (!r.success) {
                if (r.need_login) { window.location.href = '/?auth=login&next=' + encodeURIComponent(location.pathname); return; }
                if (r.opens_at) { root.setAttribute('data-opens', r.opens_at); lobby_ready(); return; }
                btn.disabled = !!r.denied;
                if (r.need_password && el('lvPw')) { note(''); field_error(el('lvPw'), r.message); return; }
                if (r.need_name && el('lvName')) { note(''); field_error(el('lvName'), r.message); return; }
                note(r.message || 'Could not join the call.', 'error'); return;
            }
            note('');
            connect(r);
        });
    }
    function connect(r) {
        var btn = el('lvJoin');
        me_id = r.me || '';
        if (r.state) { state = r.state; }
        var may_talk = is_host || !state.watch || state.speakers.indexOf(me_id) >= 0;
        var want_mic = may_talk && mic_on && !!preview.audio, want_cam = may_talk && cam_on && !!preview.video;
        var mic_id = el('lvMicSel').value, cam_id = el('lvCamSel').value;
        stop_preview();
        room = new LK.Room({ adaptiveStream: true, dynacast: true });
        wire(room);
        room.connect(r.url, r.token).then(function () {
            show('lvCall'); leaving = false;
            started = Date.now(); el('lvTimer').hidden = false; tick(); clock = setInterval(tick, 1000);
            room.startAudio();
            apply_state(room.metadata || state);   // fills the host's settings too
            if (!may_talk) { toastr.info('This call is watch-only. Raise your hand to ask to talk.'); }
            if (is_host) { poll_waiting(); }
            var lp = room.localParticipant;
            return Promise.all([
                lp.setMicrophoneEnabled(want_mic, mic_id ? { deviceId: mic_id } : undefined).catch(function () {}),
                lp.setCameraEnabled(want_cam, cam_id ? { deviceId: cam_id } : undefined).catch(function () {}),
            ]);
        }).then(function () { btn.textContent = 'Join Call'; btn.disabled = false; render(); }).catch(function () {
            btn.textContent = 'Join Call'; btn.disabled = false;
            note('Could not connect to the call. Check your connection and try again.', 'error');
            start_preview();
        });
    }

    function tick() {
        var s = Math.floor((Date.now() - started) / 1000), h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
        el('lvTimer').textContent = (h ? h + ':' + String(m).padStart(2, '0') : String(m).padStart(2, '0')) + ':' + String(x).padStart(2, '0');
    }

    function wire(r) {
        var E = LK.RoomEvent;
        [E.ParticipantConnected, E.ParticipantDisconnected, E.TrackSubscribed, E.TrackUnsubscribed, E.TrackMuted, E.TrackUnmuted,
         E.LocalTrackPublished, E.LocalTrackUnpublished, E.ParticipantAttributesChanged].forEach(function (ev) { r.on(ev, render); });
        r.on(E.ActiveSpeakersChanged, function (speakers) {
            var ids = speakers.map(function (p) { return p.identity; });
            document.querySelectorAll('.lv-tile').forEach(function (t) { t.classList.toggle('is-speaking', ids.indexOf(t.getAttribute('data-who')) >= 0 && !t.classList.contains('is-screen')); });
        });
        r.on(E.RoomMetadataChanged, function (m) { apply_state(m); });
        r.on(E.ParticipantPermissionsChanged, function (prev, p) { if (p === r.localParticipant) { perms_changed(prev); } });
        r.on(E.DataReceived, function (payload, p, kind_, topic) { if (topic === 'chat') { chat_in(payload, p); } });
        r.on(E.Reconnecting, function () { banner('Reconnecting…'); });
        r.on(E.Reconnected, function () { banner(''); });
        r.on(E.Disconnected, function (reason) {
            clearInterval(clock); el('lvTimer').hidden = true; banner(''); clearTimeout(waiting_timer);
            var D = LK.DisconnectReason || {};
            var removed = reason === D.PARTICIPANT_REMOVED, closed = reason === D.ROOM_DELETED;
            el('lvEndTitle').textContent = removed ? 'You were removed from the call' : (closed ? 'The call has ended' : (leaving ? 'You left the call' : 'You were disconnected'));
            el('lvEndText').textContent = removed ? 'The host took you out of this call.' : '';
            el('lvRejoin').hidden = removed || closed;
            Object.keys(tiles).forEach(drop_tile); el('lvGrid').innerHTML = ''; el('lvFeature').innerHTML = '';
            document.querySelectorAll('body > audio').forEach(function (a) { a.remove(); });
            if (is_host) { el('lvPeopleList').removeAttribute('data-sig'); }
            el('lvChatList').innerHTML = ''; el('lvChatEmpty').hidden = false; unread = 0; el('lvChatDot').hidden = true;
            open_pane(null);
            show('lvEnd');
        });
    }
    function banner(t) { var b = el('lvBanner'); b.hidden = !t; b.textContent = t; }

    /* New settings from the host: update controls, spotlight and chat for everyone. */
    function apply_state(meta) {
        var s = null; try { s = typeof meta === 'string' ? JSON.parse(meta || '{}') : meta; } catch (e) { s = null; }
        if (!s || typeof s !== 'object' || !('share' in s)) { return; }
        state = s; state.speakers = state.speakers || [];
        if (is_host) { sync_settings(); poll_waiting(); }
        render(); chat_state();
    }
    /* What this attendee may do right now (LiveKit enforces it; this only mirrors it in the controls). */
    function may(src) {
        if (is_host || !room) { return true; }
        var p = room.localParticipant.permissions;
        if (!p) { return true; }
        if (src === 'data') { return p.canPublishData !== false; }
        if (!p.canPublish) { return false; }
        var list = p.canPublishSources || [];
        return !list.length || list.indexOf(SRC[src]) >= 0;
    }
    function perms_changed(prev) {
        var could = !prev || prev.canPublish, can = may('microphone');
        if (could && !can) { toastr.info('The host turned off attendee microphones and cameras.'); }
        if (!could && can) { toastr.success('The host lets you talk now. Turn on your microphone when you’re ready.'); }
        render(); chat_state();
    }

    /* One tile per person (camera or initials), plus one per shared screen, which takes the big spot (or, with no
       screen shared, the person the host put in the spotlight). Tiles are reused while nothing about them changes. */
    var tiles = {};   // key -> { node, sig, track, video }
    function hand_of(p) { return (p.attributes && p.attributes.hand) || ''; }
    function tile_sig(p, pub, is_screen) {
        var mic = p.getTrackPublication(LK.Track.Source.Microphone);
        return [p.name || p.identity, pub && pub.track && !pub.isMuted ? pub.trackSid : '-', is_screen ? 's' : 'c', (!mic || mic.isMuted) ? 'm' : 'u', hand_of(p) ? 'h' : ''].join('|');
    }
    function tile_for(p, pub, is_screen) {
        var key = p.identity + (is_screen ? '#screen' : '#cam'), sig = tile_sig(p, pub, is_screen), cur = tiles[key];
        if (cur && cur.sig === sig) { return cur.node; }
        if (cur) { drop_tile(key); }
        var name = p.name || p.identity, me = p === room.localParticipant;
        var t = document.createElement('div');
        t.className = 'lv-tile' + (me ? ' is-me' : '') + (is_screen ? ' is-screen' : '');
        t.setAttribute('data-who', p.identity);
        var track = pub && pub.track && !pub.isMuted ? pub.track : null, video = null;
        if (track) { video = track.attach(); video.muted = true; video.playsInline = true; t.appendChild(video); }
        else { t.innerHTML = '<div class="lv-tile__off"><span class="lv-avatar">' + esc(initials(name)) + '</span></div>'; }
        var mic = p.getTrackPublication(LK.Track.Source.Microphone), muted = !mic || mic.isMuted;
        var label = document.createElement('span'); label.className = 'lv-tile__name';
        label.innerHTML = (is_screen ? '<i class="fa-solid fa-display" aria-hidden="true"></i>' : (muted ? '<i class="fa-solid fa-microphone-slash" aria-hidden="true"></i>' : ''))
            + '<span>' + esc(is_screen ? name + '’s screen' : (me ? name + ' (You)' : name)) + '</span>';
        t.appendChild(label);
        if (!is_screen && hand_of(p)) { var h = document.createElement('span'); h.className = 'lv-tile__hand'; h.innerHTML = '<i class="fa-solid fa-hand" aria-label="Hand raised"></i>'; t.appendChild(h); }
        tiles[key] = { node: t, sig: sig, track: track, video: video };
        return t;
    }
    function drop_tile(key) {
        var t = tiles[key]; if (!t) { return; }
        if (t.track && t.video) { t.track.detach(t.video); }
        if (t.node.parentNode) { t.node.parentNode.removeChild(t.node); }
        delete tiles[key];
    }
    function people_now() { return [room.localParticipant].concat(Array.from(room.remoteParticipants.values())); }
    function render() {
        if (!room) { return; }
        var people = people_now();
        var grid = el('lvGrid'), feature = el('lvFeature'), stage = el('lvStage');
        var keep = {}, big = null, order = [];
        people.forEach(function (p) {
            var sc = p.getTrackPublication(LK.Track.Source.ScreenShare);
            if (sc && sc.track && !big) { big = tile_for(p, sc, true); keep[p.identity + '#screen'] = 1; }
        });
        people.forEach(function (p) {
            var cam = tile_for(p, p.getTrackPublication(LK.Track.Source.Camera), false); keep[p.identity + '#cam'] = 1;
            if (!big && state.spotlight && p.identity === state.spotlight) { big = cam; cam.classList.add('is-spot'); return; }
            cam.classList.remove('is-spot');
            order.push(cam);
        });
        Object.keys(tiles).forEach(function (k) { if (!keep[k]) { drop_tile(k); } });
        order.forEach(function (node, i) { if (grid.children[i] !== node) { grid.insertBefore(node, grid.children[i] || null); } });
        while (grid.children.length > order.length) { grid.removeChild(grid.lastChild); }
        if (big && big.parentNode !== feature) { feature.innerHTML = ''; feature.appendChild(big); }
        if (!big) { feature.innerHTML = ''; }
        feature.hidden = !big; stage.classList.toggle('has-feature', !!big);
        var n = order.length; grid.setAttribute('data-count', n > 9 ? 'many' : String(Math.max(n, 1)));
        // Remote audio plays through hidden elements (camera tiles are muted video).
        people.forEach(function (p) {
            if (p === room.localParticipant) { return; }
            p.audioTrackPublications.forEach(function (pub) { if (pub.track && !pub.track.attachedElements.length) { var a = pub.track.attach(); a.hidden = true; document.body.appendChild(a); } });
        });
        var lp = room.localParticipant, can_talk = may('microphone');
        set_toggle(el('lvMic'), lp.isMicrophoneEnabled, 'fa-microphone', 'fa-microphone-slash');
        set_toggle(el('lvCam'), lp.isCameraEnabled, 'fa-video', 'fa-video-slash');
        el('lvMic').disabled = !can_talk; el('lvCam').disabled = !may('camera');
        el('lvMic').title = can_talk ? 'Microphone' : 'The host hasn’t let you talk';
        if (el('lvShare')) {   // no button at all for guests
            el('lvShare').hidden = !may('screen_share') && !lp.isScreenShareEnabled;
            el('lvShare').setAttribute('aria-pressed', lp.isScreenShareEnabled ? 'true' : 'false');
        }
        if (el('lvHand')) {
            var up = !!hand_of(lp);
            el('lvHand').setAttribute('aria-pressed', up ? 'true' : 'false');
            el('lvHand').title = up ? 'Lower Hand' : 'Raise Hand'; el('lvHand').setAttribute('aria-label', el('lvHand').title);
        }
        if (is_host) { el('lvCount').textContent = people.length; render_people(people); }
    }

    el('lvMic').addEventListener('click', function () { var lp = room.localParticipant; lp.setMicrophoneEnabled(!lp.isMicrophoneEnabled).then(render).catch(device_error); });
    el('lvCam').addEventListener('click', function () { var lp = room.localParticipant; lp.setCameraEnabled(!lp.isCameraEnabled).then(render).catch(device_error); });
    if (el('lvShare')) el('lvShare').addEventListener('click', function () { var lp = room.localParticipant; lp.setScreenShareEnabled(!lp.isScreenShareEnabled).then(render).catch(function () { render(); }); });
    el('lvLeave').addEventListener('click', function () { leaving = true; if (room) { room.disconnect(); } });
    function device_error() { toastr.error('Your browser blocked the camera or microphone. Allow it in the address bar and try again.'); render(); }
    window.addEventListener('beforeunload', function () { if (room) { room.disconnect(); } });

    /* Raise hand: the server sets it on the person, so the host sees it even after joining late. */
    if (el('lvHand')) el('lvHand').addEventListener('click', function () {
        var b = el('lvHand'), up = b.getAttribute('aria-pressed') !== 'true';
        b.disabled = true;
        ApiDataSvc.apiCall('post', 'live_hand', { kind: kind, id: id, up: up ? 1 : 0 }, function (data) {
            b.disabled = false; var r = parse(data);
            if (!r.success) { toastr.error(r.message || 'Could not reach the host.'); }
        });
    });

    /* ---------------- Side panel: chat for everyone; people and settings for the host ---------------- */
    var pane = null, TITLES = { chat: 'Chat', people: 'People', settings: 'Host Settings' };
    function open_pane(name) {
        pane = name;
        el('lvSide').hidden = !name;
        document.querySelectorAll('.lv-pane').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== name; });
        document.querySelectorAll('.lv-bar [data-pane]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-pane') === name ? 'true' : 'false'); });
        if (name) { el('lvSideTitle').textContent = TITLES[name]; }
        if (name === 'chat') { unread = 0; el('lvChatDot').hidden = true; scroll_chat(); if (!el('lvChatInput').disabled) { el('lvChatInput').focus(); } }
    }
    document.querySelectorAll('.lv-bar [data-pane]').forEach(function (b) {
        b.addEventListener('click', function () { var n = b.getAttribute('data-pane'); open_pane(pane === n ? null : n); });
    });
    el('lvSideClose').addEventListener('click', function () { open_pane(null); });

    /* Chat: messages go straight between the people in the call and are gone when it ends. */
    var unread = 0;
    function chat_state() {
        var ok = may('data') && state.chat !== false || is_host;
        el('lvChatInput').disabled = !ok; el('lvChatSend').disabled = !ok;
        el('lvChatInput').placeholder = ok ? 'Message everyone' : 'The host turned chat off';
    }
    function scroll_chat() { var l = el('lvChatList'); l.scrollTop = l.scrollHeight; }
    function chat_add(name, text, mine, host) {
        var li = document.createElement('li'); li.className = 'lv-msg' + (mine ? ' is-mine' : '');
        var when = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        li.innerHTML = '<div class="lv-msg__meta"><b>' + esc(mine ? 'You' : name) + '</b>' + (host && !mine ? '<span class="lv-msg__tag">Host</span>' : '') + '<span>' + esc(when) + '</span></div>'
            + '<p class="lv-msg__text">' + esc(text) + '</p>';
        el('lvChatList').appendChild(li); el('lvChatEmpty').hidden = true;
        var list = el('lvChatList'); while (list.children.length > 300) { list.removeChild(list.firstChild); }
        scroll_chat();
        if (!mine && pane !== 'chat') { unread++; el('lvChatDot').hidden = false; }
    }
    function is_host_p(p) { try { return JSON.parse(p.metadata || '{}').host === true; } catch (e) { return false; } }
    function chat_in(payload, p) {
        var m = null; try { m = JSON.parse(new TextDecoder().decode(payload)); } catch (e) { return; }
        if (!m || typeof m.text !== 'string' || !p) { return; }
        chat_add(p.name || p.identity, m.text.slice(0, 500), false, is_host_p(p));
    }
    el('lvChatForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var t = el('lvChatInput').value.trim(); if (!t || !room) { return; }
        room.localParticipant.publishData(new TextEncoder().encode(JSON.stringify({ text: t.slice(0, 500) })), { reliable: true, topic: 'chat' })
            .then(function () { chat_add(my_name, t, true, is_host); el('lvChatInput').value = ''; })
            .catch(function () { toastr.error(state.chat ? 'Your message didn’t send. Try again.' : 'The host turned chat off.'); });
    });

    /* ---------------- Host tools ---------------- */
    var waiting_timer = null, waiting_list = [];
    function host_call(action, body, done) {
        ApiDataSvc.apiCall('post', action, Object.assign({ kind: kind, id: id }, body || {}), function (data) {
            var r = parse(data);
            if (!r.success) { toastr.error(r.message || 'Something went wrong. Try again.'); }
            if (r.state) { apply_state(r.state); }
            if (done) { done(r); }
        });
    }
    function sync_settings() {
        el('lvSetWaiting').checked = !!state.waiting;
        el('lvSetShare').checked = state.share === 'everyone';
        el('lvSetTalk').checked = !state.watch;
        el('lvSetChat').checked = state.chat !== false;
        el('lvSetLocked').checked = !!state.locked;
    }
    function poll_waiting() {
        clearTimeout(waiting_timer);
        if (!is_host || !room || room.state === 'disconnected') { return; }
        if (!state.waiting) { waiting_list = []; render_waiting(); return; }
        ApiDataSvc.apiCall('post', 'live_waiting', { kind: kind, id: id }, function (data) {
            var r = parse(data);
            if (r.success) { waiting_list = r.waiting || []; render_waiting(); }
            waiting_timer = setTimeout(poll_waiting, 3000);
        });
    }
    function render_waiting() {
        var n = waiting_list.length;
        el('lvWaitBar').hidden = n === 0;
        el('lvWaitBarText').textContent = n === 1 ? (waiting_list[0].name || 'Someone') + ' is waiting' : n + ' people are waiting';
        el('lvWaitGroup').hidden = n === 0;
        el('lvWaitTitle').textContent = 'Waiting (' + n + ')';
        el('lvWaitList').innerHTML = waiting_list.map(function (w) {
            return '<li class="lv-person"><span class="lv-person__av">' + esc(initials(w.name)) + '</span><span class="lv-person__name">' + esc(w.name || 'Guest') + '</span>'
                + '<button type="button" class="lv-person__admit" data-admit="' + esc(w.identity) + '">Admit</button>'
                + '<div class="dropdown"><button type="button" class="lv-person__more" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More"><i class="fa-solid fa-ellipsis"></i></button>'
                + '<ul class="dropdown-menu dropdown-menu-end"><li><button type="button" class="dropdown-item text-danger" data-deny="' + esc(w.identity) + '">Don’t Let In</button></li></ul></div></li>';
        }).join('');
    }
    if (is_host) {
        document.querySelectorAll('#lvPaneSettings [data-key]').forEach(function (sw) {
            sw.addEventListener('change', function () {
                var key = sw.getAttribute('data-key'), on = sw.checked, value = on ? '1' : '0';
                if (key === 'share') { value = on ? 'everyone' : 'host'; }
                if (key === 'watch') { value = on ? '0' : '1'; }   // the switch reads "Attendees Can Talk"
                sw.disabled = true;
                host_call('live_settings', { key: key, value: value }, function (r) {
                    sw.disabled = false;
                    if (!r.success) { sync_settings(); return; }
                    if (r.admitted > 0) { toastr.success(r.admitted === 1 ? '1 person was let in' : r.admitted + ' people were let in'); }
                });
            });
        });
        function admit_all() { host_call('live_admit', { all: 1 }, function (r) { if (r.success) { toastr.success(r.admitted === 1 ? '1 person was let in' : r.admitted + ' people were let in'); poll_waiting(); } }); }
        el('lvAdmitAll').addEventListener('click', admit_all);
        el('lvWaitBarAdmit').addEventListener('click', admit_all);
        el('lvWaitBarView').addEventListener('click', function () { open_pane('people'); });
        el('lvWaitList').addEventListener('click', function (e) {
            var a = e.target.closest('[data-admit]'), d = e.target.closest('[data-deny]');
            if (a) { a.disabled = true; host_call('live_admit', { identity: a.getAttribute('data-admit') }, function () { poll_waiting(); }); }
            if (d) { host_call('live_deny', { identity: d.getAttribute('data-deny') }, function () { poll_waiting(); }); }
        });
        el('lvMuteAll').addEventListener('click', function () {
            var b = el('lvMuteAll'); b.disabled = true;
            ApiDataSvc.apiCall('post', 'live_mute_all', { kind: kind, id: id }, function (data) {
                b.disabled = false; var r = parse(data);
                r.success ? toastr.success(r.message) : toastr.error(r.message || 'Could not mute everyone.');
            });
        });
        el('lvPeopleList').addEventListener('click', function (e) {
            var rm = e.target.closest('[data-remove]'), act = e.target.closest('[data-act]');
            if (act) {
                var who = act.getAttribute('data-who');
                host_call('live_person', { identity: who, action: act.getAttribute('data-act') });
                return;
            }
            if (!rm) { return; }
            var who2 = rm.getAttribute('data-remove'), name = rm.getAttribute('data-name');
            Swal.fire({ title: 'Remove ' + name + '?', text: 'They will be taken out of this call and can’t rejoin it.', showCancelButton: true, confirmButtonText: 'Remove', cancelButtonText: 'Cancel', reverseButtons: true })
                .then(function (res) {
                    if (!res.isConfirmed) { return; }
                    ApiDataSvc.apiCall('post', 'live_remove', { kind: kind, id: id, identity: who2 }, function (data) {
                        var r = parse(data); r.success ? toastr.success(name + ' was removed') : toastr.error(r.message || 'Could not remove them.');
                    });
                });
        });
    }
    function render_people(people) {
        var list = el('lvPeopleList');
        var sig = JSON.stringify([state.watch, state.spotlight, state.speakers]) + people.map(function (p) {
            var m = p.getTrackPublication(LK.Track.Source.Microphone), c = p.getTrackPublication(LK.Track.Source.Camera);
            return p.identity + ':' + (p.name || '') + ':' + (!m || m.isMuted ? 0 : 1) + (!c || c.isMuted ? 0 : 1) + ':' + hand_of(p);
        }).join(',');
        el('lvInTitle').textContent = 'In the Call (' + people.length + ')';
        if (list.getAttribute('data-sig') === sig) { return; }
        list.setAttribute('data-sig', sig);
        var sorted = people.slice().sort(function (a, b) { return (hand_of(b) ? 1 : 0) - (hand_of(a) ? 1 : 0) || (parseInt(hand_of(a), 10) || 0) - (parseInt(hand_of(b), 10) || 0); });
        list.innerHTML = sorted.map(function (p) {
            var me = p === room.localParticipant, name = p.name || p.identity, host = is_host_p(p);
            var mic = p.getTrackPublication(LK.Track.Source.Microphone), muted = !mic || mic.isMuted;
            var cam = p.getTrackPublication(LK.Track.Source.Camera), cam_on = cam && !cam.isMuted;
            var hand = !!hand_of(p), speaker = state.speakers.indexOf(p.identity) >= 0, spot = state.spotlight === p.identity;
            var items = [];
            if (!me && !host) {
                if (!muted) { items.push('<li><button type="button" class="dropdown-item" data-act="mute" data-who="' + esc(p.identity) + '">Mute</button></li>'); }
                if (cam_on) { items.push('<li><button type="button" class="dropdown-item" data-act="camera_off" data-who="' + esc(p.identity) + '">Turn Off Camera</button></li>'); }
                if (state.watch) { items.push('<li><button type="button" class="dropdown-item" data-act="' + (speaker ? 'stop_talk' : 'talk') + '" data-who="' + esc(p.identity) + '">' + (speaker ? 'Stop Letting Talk' : 'Let Talk') + '</button></li>'); }
                if (hand) { items.push('<li><button type="button" class="dropdown-item" data-act="lower_hand" data-who="' + esc(p.identity) + '">Lower Hand</button></li>'); }
            }
            items.push('<li><button type="button" class="dropdown-item" data-act="' + (spot ? 'unspotlight' : 'spotlight') + '" data-who="' + esc(p.identity) + '">' + (spot ? 'Remove Spotlight' : 'Spotlight for Everyone') + '</button></li>');
            if (!me && !host) { items.push('<li><hr class="dropdown-divider"></li><li><button type="button" class="dropdown-item text-danger" data-remove="' + esc(p.identity) + '" data-name="' + esc(name) + '">Remove From Call</button></li>'); }
            return '<li class="lv-person' + (hand ? ' has-hand' : '') + '"><span class="lv-person__av">' + esc(initials(name)) + '</span>'
                + '<span class="lv-person__name">' + esc(name) + (me ? ' <span class="lv-person__tag">(You)</span>' : (host ? ' <span class="lv-person__tag">Host</span>' : (speaker && state.watch ? ' <span class="lv-person__tag">Can talk</span>' : ''))) + '</span>'
                + (hand ? '<i class="lv-person__hand fa-solid fa-hand" aria-label="Hand raised"></i>' : '')
                + (spot ? '<i class="lv-person__spot fa-solid fa-star" aria-label="In the spotlight"></i>' : '')
                + '<i class="lv-person__mic fa-solid ' + (muted ? 'fa-microphone-slash' : 'fa-microphone') + '" aria-label="' + (muted ? 'Muted' : 'Mic on') + '"></i>'
                + '<div class="dropdown"><button type="button" class="lv-person__more" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More"><i class="fa-solid fa-ellipsis"></i></button>'
                + '<ul class="dropdown-menu dropdown-menu-end">' + items.join('') + '</ul></div></li>';
        }).join('');
    }

    show('lvLobby');
    start_preview().then(lobby_ready);
})();
