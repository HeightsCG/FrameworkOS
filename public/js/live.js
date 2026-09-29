/* CLS Video (views/live/_room.php). Lobby: preview camera + mic, pick devices. Join: ask /api/live_join for a pass,
   connect to our LiveKit server, show everyone as tiles. Host extras: people list (remove) and Mute Everyone.
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
    var room = null, started = 0, clock = null, leaving = false;

    el('lvJoin').addEventListener('click', join);
    el('lvRejoin').addEventListener('click', function () { show('lvLobby'); start_preview().then(lobby_ready); });

    function field_error(input, msg) {
        input.classList.toggle('is-invalid', !!msg);
        var err = input.parentNode.querySelector('.invalid-feedback');
        if (!err) { err = document.createElement('div'); err.className = 'invalid-feedback'; input.parentNode.appendChild(err); }
        err.textContent = msg || '';
        if (msg) { input.focus(); }
    }
    function join() {
        var body = { kind: kind, id: id };
        if (is_guest) {
            var nm = el('lvName').value.trim();
            if (!nm) { field_error(el('lvName'), 'Enter the name others will see.'); return; }
            field_error(el('lvName'), ''); body.name = nm; my_name = nm;
        }
        if (needs_pw) {
            var pw = el('lvPw').value.trim();
            if (!pw) { field_error(el('lvPw'), 'Enter the call password from the host.'); return; }
            field_error(el('lvPw'), ''); body.password = pw;
        }
        var btn = el('lvJoin'); btn.disabled = true; btn.textContent = 'Joining…';
        ApiDataSvc.apiCall('post', 'live_join', body, function (data) {
            var r = parse(data);
            if (!r.success) {
                btn.textContent = 'Join Call';
                if (r.need_login) { window.location.href = '/?auth=login&next=' + encodeURIComponent(location.pathname); return; }
                if (r.opens_at) { root.setAttribute('data-opens', r.opens_at); lobby_ready(); return; }
                btn.disabled = false;
                if (r.need_password && el('lvPw')) { field_error(el('lvPw'), r.message); return; }
                if (r.need_name && el('lvName')) { field_error(el('lvName'), r.message); return; }
                note(r.message || 'Could not join the call.', 'error'); return;
            }
            var want_mic = mic_on && !!preview.audio, want_cam = cam_on && !!preview.video;
            var mic_id = el('lvMicSel').value, cam_id = el('lvCamSel').value;
            stop_preview();
            room = new LK.Room({ adaptiveStream: true, dynacast: true });
            wire(room);
            room.connect(r.url, r.token).then(function () {
                show('lvCall'); leaving = false;
                started = Date.now(); el('lvTimer').hidden = false; tick(); clock = setInterval(tick, 1000);
                room.startAudio();
                var lp = room.localParticipant;
                return Promise.all([
                    lp.setMicrophoneEnabled(want_mic, mic_id ? { deviceId: mic_id } : undefined).catch(function () {}),
                    lp.setCameraEnabled(want_cam, cam_id ? { deviceId: cam_id } : undefined).catch(function () {}),
                ]);
            }).then(function () { btn.textContent = 'Join Call'; render(); }).catch(function (e) {
                btn.textContent = 'Join Call'; btn.disabled = false;
                note('Could not connect to the call. Check your connection and try again.', 'error');
                start_preview();
            });
        });
    }

    function tick() {
        var s = Math.floor((Date.now() - started) / 1000), h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
        el('lvTimer').textContent = (h ? h + ':' + String(m).padStart(2, '0') : String(m).padStart(2, '0')) + ':' + String(x).padStart(2, '0');
    }

    function wire(r) {
        var E = LK.RoomEvent;
        [E.ParticipantConnected, E.ParticipantDisconnected, E.TrackSubscribed, E.TrackUnsubscribed, E.TrackMuted, E.TrackUnmuted,
         E.LocalTrackPublished, E.LocalTrackUnpublished].forEach(function (ev) { r.on(ev, render); });
        r.on(E.ActiveSpeakersChanged, function (speakers) {
            var ids = speakers.map(function (p) { return p.identity; });
            document.querySelectorAll('.lv-tile').forEach(function (t) { t.classList.toggle('is-speaking', ids.indexOf(t.getAttribute('data-who')) >= 0 && !t.classList.contains('is-screen')); });
        });
        r.on(E.Reconnecting, function () { banner('Reconnecting…'); });
        r.on(E.Reconnected, function () { banner(''); });
        r.on(E.Disconnected, function (reason) {
            clearInterval(clock); el('lvTimer').hidden = true; banner('');
            var D = LK.DisconnectReason || {};
            var removed = reason === D.PARTICIPANT_REMOVED, closed = reason === D.ROOM_DELETED;
            el('lvEndTitle').textContent = removed ? 'You were removed from the call' : (closed ? 'The call has ended' : (leaving ? 'You left the call' : 'You were disconnected'));
            el('lvEndText').textContent = removed ? 'The host took you out of this call.' : '';
            el('lvRejoin').hidden = removed || closed;
            Object.keys(tiles).forEach(drop_tile); el('lvGrid').innerHTML = ''; el('lvFeature').innerHTML = '';
            document.querySelectorAll('body > audio').forEach(function (a) { a.remove(); });
            if (is_host) { el('lvPeopleList').removeAttribute('data-sig'); }
            show('lvEnd');
        });
    }
    function banner(t) { var b = el('lvBanner'); b.hidden = !t; b.textContent = t; }

    /* One tile per person (camera or initials), plus one per shared screen, which takes the big spot. Tiles are kept
       and reused while nothing about them changes, so video elements aren't torn down on every room event. */
    var tiles = {};   // key -> { node, sig, track, video }
    function tile_sig(p, pub, is_screen) {
        var mic = p.getTrackPublication(LK.Track.Source.Microphone);
        return [p.name || p.identity, pub && pub.track && !pub.isMuted ? pub.trackSid : '-', is_screen ? 's' : 'c', (!mic || mic.isMuted) ? 'm' : 'u'].join('|');
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
        tiles[key] = { node: t, sig: sig, track: track, video: video };
        return t;
    }
    function drop_tile(key) {
        var t = tiles[key]; if (!t) { return; }
        if (t.track && t.video) { t.track.detach(t.video); }
        if (t.node.parentNode) { t.node.parentNode.removeChild(t.node); }
        delete tiles[key];
    }
    function render() {
        if (!room) { return; }
        var people = [room.localParticipant].concat(Array.from(room.remoteParticipants.values()));
        var grid = el('lvGrid'), feature = el('lvFeature'), stage = el('lvStage');
        var keep = {}, share = null, order = [];
        people.forEach(function (p) {
            var sc = p.getTrackPublication(LK.Track.Source.ScreenShare);
            if (sc && sc.track && !share) { share = tile_for(p, sc, true); keep[p.identity + '#screen'] = 1; }
            order.push(tile_for(p, p.getTrackPublication(LK.Track.Source.Camera), false)); keep[p.identity + '#cam'] = 1;
        });
        Object.keys(tiles).forEach(function (k) { if (!keep[k]) { drop_tile(k); } });
        order.forEach(function (node, i) { if (grid.children[i] !== node) { grid.insertBefore(node, grid.children[i] || null); } });
        if (share && share.parentNode !== feature) { feature.innerHTML = ''; feature.appendChild(share); }
        if (!share) { feature.innerHTML = ''; }
        feature.hidden = !share; stage.classList.toggle('has-feature', !!share);
        var n = people.length; grid.setAttribute('data-count', n > 9 ? 'many' : String(n));
        // Remote audio plays through hidden elements (camera tiles are muted video).
        people.forEach(function (p) {
            if (p === room.localParticipant) { return; }
            p.audioTrackPublications.forEach(function (pub) { if (pub.track && !pub.track.attachedElements.length) { var a = pub.track.attach(); a.hidden = true; document.body.appendChild(a); } });
        });
        var lp = room.localParticipant;
        set_toggle(el('lvMic'), lp.isMicrophoneEnabled, 'fa-microphone', 'fa-microphone-slash');
        set_toggle(el('lvCam'), lp.isCameraEnabled, 'fa-video', 'fa-video-slash');
        if (el('lvShare')) { el('lvShare').setAttribute('aria-pressed', lp.isScreenShareEnabled ? 'true' : 'false'); }   // no button for guests
        if (is_host) { el('lvCount').textContent = n; render_people(people); }
    }

    el('lvMic').addEventListener('click', function () { var lp = room.localParticipant; lp.setMicrophoneEnabled(!lp.isMicrophoneEnabled).then(render).catch(device_error); });
    el('lvCam').addEventListener('click', function () { var lp = room.localParticipant; lp.setCameraEnabled(!lp.isCameraEnabled).then(render).catch(device_error); });
    if (el('lvShare')) el('lvShare').addEventListener('click', function () { var lp = room.localParticipant; lp.setScreenShareEnabled(!lp.isScreenShareEnabled).then(render).catch(function () { render(); }); });
    el('lvLeave').addEventListener('click', function () { leaving = true; if (room) { room.disconnect(); } });
    function device_error() { toastr.error('Your browser blocked the camera or microphone. Allow it in the address bar and try again.'); render(); }
    window.addEventListener('beforeunload', function () { if (room) { room.disconnect(); } });

    /* ---------------- Host tools ---------------- */
    if (is_host) {
        var panel = el('lvPeoplePanel');
        el('lvPeople').addEventListener('click', function () { panel.hidden = !panel.hidden; el('lvPeople').setAttribute('aria-pressed', panel.hidden ? 'false' : 'true'); });
        el('lvPeopleClose').addEventListener('click', function () { panel.hidden = true; el('lvPeople').setAttribute('aria-pressed', 'false'); });
        el('lvMuteAll').addEventListener('click', function () {
            var b = el('lvMuteAll'); b.disabled = true;
            ApiDataSvc.apiCall('post', 'live_mute_all', { kind: kind, id: id }, function (data) {
                b.disabled = false; var r = parse(data);
                r.success ? toastr.success(r.message) : toastr.error(r.message || 'Could not mute everyone.');
            });
        });
        el('lvPeopleList').addEventListener('click', function (e) {
            var rm = e.target.closest('[data-remove]');
            if (!rm) { return; }
            var who = rm.getAttribute('data-remove'), name = rm.getAttribute('data-name');
            Swal.fire({ title: 'Remove ' + name + '?', text: 'They will be taken out of this call.', showCancelButton: true, confirmButtonText: 'Remove', cancelButtonText: 'Cancel', reverseButtons: true })
                .then(function (res) {
                    if (!res.isConfirmed) { return; }
                    ApiDataSvc.apiCall('post', 'live_remove', { kind: kind, id: id, identity: who }, function (data) {
                        var r = parse(data); r.success ? toastr.success(name + ' was removed') : toastr.error(r.message || 'Could not remove them.');
                    });
                });
        });
    }
    function render_people(people) {
        var list = el('lvPeopleList');
        var sig = people.map(function (p) { var m = p.getTrackPublication(LK.Track.Source.Microphone); return p.identity + ':' + (p.name || '') + ':' + (!m || m.isMuted ? 0 : 1); }).join(',');
        if (list.getAttribute('data-sig') === sig) { return; }
        list.setAttribute('data-sig', sig);
        list.innerHTML = people.map(function (p) {
            var me = p === room.localParticipant, name = p.name || p.identity;
            var mic = p.getTrackPublication(LK.Track.Source.Microphone), muted = !mic || mic.isMuted;
            var host = false; try { host = JSON.parse(p.metadata || '{}').host === true; } catch (e) {}
            return '<li class="lv-person"><span class="lv-person__av">' + esc(initials(name)) + '</span>'
                + '<span class="lv-person__name">' + esc(name) + (me ? ' <span class="lv-person__tag">(You)</span>' : (host ? ' <span class="lv-person__tag">Host</span>' : '')) + '</span>'
                + '<i class="lv-person__mic fa-solid ' + (muted ? 'fa-microphone-slash' : 'fa-microphone') + '" aria-label="' + (muted ? 'Muted' : 'Mic on') + '"></i>'
                + (me || host ? '' : '<div class="dropdown"><button type="button" class="lv-person__more" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More"><i class="fa-solid fa-ellipsis"></i></button>'
                    + '<ul class="dropdown-menu dropdown-menu-end"><li><button type="button" class="dropdown-item text-danger" data-remove="' + esc(p.identity) + '" data-name="' + esc(name) + '">Remove From Call</button></li></ul></div>')
                + '</li>';
        }).join('');
    }

    show('lvLobby');
    start_preview().then(lobby_ready);
})();
