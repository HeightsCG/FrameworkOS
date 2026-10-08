/* Events: EventEditor wires the event form (events/_form.php) inside the Create / Edit modal and turns
   it into the event_save payload. "Who can come" + "Price" become access_type (+ tier_id); Live is
   outside the form (opts.status()). */
(function () {
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function el(id) { return document.getElementById(id); }

    window.EventEditor = function (container, opts) {
        opts = opts || {};
        var ed = window.SectionEditor(container);   // approved shell: left nav, one section at a time
        var status = 'draft';
        function price() { var v = parseFloat(el('ev_price').value); return isNaN(v) ? 0 : v; }   // credits; empty = free
        function credits(n) { n = Math.round(Number(n) || 0); return n.toLocaleString('en-US') + (Math.abs(n) === 1 ? ' credit' : ' credits'); }   // everything inside is credits

        var setFormat = ed.seg(el('evFormat'), el('ev_format'), 'data-format', function (v) {
            ed.reveal(el('ev_url_wrap'), v === 'virtual');   // CLS Video needs no link: people join on the event page
            ed.reveal(el('ev_pw_wrap'), v === 'cls_video');
            ed.reveal(el('ev_call_wrap'), v === 'cls_video');
            el('ev_instructions').placeholder = v === 'in_person' ? 'Parking, entry code, what to bring'
                : (v === 'cls_video' ? 'What to prepare before the call' : 'Dial-in number, what to prepare');
            ed.reveal(el('ev_location_wrap'), v === 'in_person');
            refresh();
        });
        // CLS Video call settings: where the call starts (the host can change each one during the call).
        var setCall = {
            waiting:   ed.seg(el('evCallWaiting'), el('ev_call_waiting'), 'data-v', function () { refresh(); }),
            share:     ed.seg(el('evCallShare'), el('ev_call_share'), 'data-v', function () { refresh(); }),
            attendees: ed.seg(el('evCallAttendees'), el('ev_call_attendees'), 'data-v', function () { refresh(); }),
            chat:      ed.seg(el('evCallChat'), el('ev_call_chat'), 'data-v', function () { refresh(); })
        };
        ['ev_title', 'ev_desc', 'ev_date', 'ev_time', 'ev_time_end', 'ev_url', 'ev_pw', 'ev_venue', 'ev_street', 'ev_city', 'ev_region', 'ev_postal', 'ev_price', 'ev_capacity'].forEach(function (id) { el(id).addEventListener('input', refresh); });
        el('ev_who').addEventListener('change', refresh);
        el('ev_tz').addEventListener('change', refresh);
        var rem_btns = container.querySelectorAll('#evReminders .ev-chip');
        function set_reminders(csv) {
            var on = String(csv == null ? '' : csv).split(',');
            rem_btns.forEach(function (b) { b.setAttribute('aria-pressed', on.indexOf(b.getAttribute('data-rem')) >= 0 ? 'true' : 'false'); });
        }
        function reminders() { return [].filter.call(rem_btns, function (b) { return b.getAttribute('aria-pressed') === 'true'; }).map(function (b) { return b.getAttribute('data-rem'); }).join(','); }
        rem_btns.forEach(function (b) { b.addEventListener('click', function () { b.setAttribute('aria-pressed', b.getAttribute('aria-pressed') === 'true' ? 'false' : 'true'); refresh(); }); });

        /* Narrow screens: the preview replaces the form while it's open (never stacked below it). */
        var pv_toggle = el('evPvToggle');
        function set_preview(on) {
            container.classList.toggle('is-previewing', on);
            pv_toggle.setAttribute('aria-expanded', on ? 'true' : 'false');
            pv_toggle.textContent = on ? 'Back to Editing' : 'Preview';
        }
        pv_toggle.addEventListener('click', function () { set_preview(!container.classList.contains('is-previewing')); });
        container.querySelectorAll('.cs-ae__navitem').forEach(function (b) { b.addEventListener('click', function () { set_preview(false); }); });
        el('evNavSelect').addEventListener('change', function () { set_preview(false); });

        function fmt_time(t) { if (!t) { return ''; } var p = t.split(':'), h = parseInt(p[0], 10); return (h % 12 || 12) + ':' + p[1] + ' ' + (h >= 12 ? 'PM' : 'AM'); }
        function text_lines(node, lines) {   // textContent + <br>: user text never becomes HTML
            node.textContent = '';
            lines.filter(function (l) { return l !== ''; }).forEach(function (l, i) { if (i) { node.appendChild(document.createElement('br')); } node.appendChild(document.createTextNode(l)); });
        }
        function preview(name, who_label) {
            el('evPv_title').textContent = name !== '' ? name : 'Untitled event';
            el('evPv_desc').textContent = el('ev_desc').value.trim();
            var d = el('ev_date').value, t1 = el('ev_time').value, t2 = el('ev_time_end').value, dt = d ? new Date(d + 'T12:00:00') : null;
            text_lines(el('evPv_when'), dt && !isNaN(dt)
                ? [dt.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }), t1 ? fmt_time(t1) + (t2 ? ' – ' + fmt_time(t2) : '') + ' ' + tz_abbr(dt) : '']
                : ['Pick a date']);
            if (el('ev_format').value === 'in_person') {
                var city = [el('ev_city').value.trim(), (el('ev_region').value.trim() + ' ' + el('ev_postal').value.trim()).trim()].filter(function (x) { return x !== ''; }).join(', ');
                var lines = [el('ev_venue').value.trim(), el('ev_street').value.trim(), city];
                text_lines(el('evPv_where'), lines.join('') !== '' ? lines : ['In person']);
            } else if (el('ev_format').value === 'cls_video') {
                text_lines(el('evPv_where'), ['CLS Video', 'Join from the event page']);
            } else {
                text_lines(el('evPv_where'), ['Online', 'Link shared after registration']);
            }
            el('evPv_who').textContent = who_label;
            var paid = price() >= 10, cap = parseInt(el('ev_capacity').value, 10);
            el('evPv_price').textContent = paid ? credits(price()) : 'Free';
            el('evPv_per').textContent = paid ? 'per person' : '';
            el('evPv_spots').textContent = cap > 0 ? cap + (cap === 1 ? ' spot' : ' spots') : 'No spot limit';
        }
        function tz_abbr(d) {   // "EDT" for the chosen zone on that date
            try { return new Intl.DateTimeFormat('en-US', { timeZone: el('ev_tz').value, timeZoneName: 'short' }).formatToParts(d).filter(function (p) { return p.type === 'timeZoneName'; })[0].value; }
            catch (x) { return el('ev_tz').value; }
        }

        function fmt_when() {
            var d = el('ev_date').value, t = el('ev_time').value;
            if (!d) { return 'Not set'; }
            var dt = new Date(d + 'T' + (t || '00:00'));
            if (isNaN(dt)) { return 'Not set'; }
            var s = dt.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
            return t ? s + ' · ' + dt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) + ' ' + tz_abbr(dt) : s;
        }
        function refresh() {
            var name = el('ev_title').value.trim();
            if (opts.onTitle) { opts.onTitle(name); }
            ed.summary('details', name !== '' ? name : 'Untitled');
            var rn = reminders() === '' ? 0 : reminders().split(',').length;
            ed.summary('when', fmt_when() + (rn ? ' · ' + rn + (rn === 1 ? ' reminder' : ' reminders') : ''));
            if (el('ev_format').value === 'in_person') { var c = el('ev_city').value.trim(), v = el('ev_venue').value.trim(); ed.summary('where', 'In person' + (v || c ? ' · ' + (v || c) : '')); }
            else { ed.summary('where', el('ev_format').value === 'cls_video' ? 'CLS Video' + (el('ev_pw').value.trim() ? ' · Password' : '') + (el('ev_call_waiting').value === '1' ? ' · Waiting room' : '') : 'Online'); }
            var who = el('ev_who'), label = who.value === 'anyone' ? 'Anyone' : (who.value === 'subscribers' ? 'Subscribers' : who.selectedOptions[0].textContent);
            var cap = parseInt(el('ev_capacity').value, 10);
            ed.summary('tickets', (price() >= 10 ? credits(price()) : 'Free') + ' · ' + label + (cap > 0 ? ' · ' + cap + ' spots' : ''));
            preview(name, who.value === 'anyone' ? 'Anyone' : (who.value === 'subscribers' ? 'Any subscriber' : label + ' subscribers'));
        }

        /* New events: today, the next 5-minute mark (the time picker steps in 5 minutes), ending an hour later. The clock is
           read in the event's time zone. An end earlier than the start is the next day (end_date). */
        /* The end's date: the start's date, or the next day when the end time is earlier (11:30 PM to 12:30 AM). */
        function end_date(date, t1, t2) {
            if (!date || !t2 || t2 > t1) { return date; }
            var d = new Date(date + 'T12:00:00'); d.setDate(d.getDate() + 1);
            return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
        }
        function default_when() {
            var now = new Date(Date.now() + 5 * 60000 - (Date.now() % (5 * 60000)));
            var p = {};
            try {
                new Intl.DateTimeFormat('en-CA', { timeZone: el('ev_tz').value, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
                    .formatToParts(now).forEach(function (x) { p[x.type] = x.value; });
            } catch (e) {
                p = { year: String(now.getFullYear()), month: ('0' + (now.getMonth() + 1)).slice(-2), day: ('0' + now.getDate()).slice(-2), hour: ('0' + now.getHours()).slice(-2), minute: ('0' + now.getMinutes()).slice(-2) };
            }
            var h = parseInt(p.hour, 10), m = parseInt(p.minute, 10);
            el('ev_date').value = p.year + '-' + p.month + '-' + p.day;
            el('ev_time').value = ('0' + h).slice(-2) + ':' + ('0' + m).slice(-2);
            el('ev_time_end').value = ('0' + ((h + 1) % 24)).slice(-2) + ':' + ('0' + m).slice(-2);   // past midnight = the next day (see end_date)
        }

        function load(d) {
            ed.clearErrors();
            var start = d && d.start_at ? String(d.start_at) : '', end = d && d.end_at ? String(d.end_at) : '';
            el('ev_id').value       = d && d.id ? d.id : '';
            el('ev_title').value    = d ? (d.title || '') : '';
            el('ev_desc').value     = d ? (d.description || '') : '';
            el('ev_date').value     = start ? start.slice(0, 10) : '';
            el('ev_time').value     = start ? start.slice(11, 16) : '';
            el('ev_time_end').value = end ? end.slice(11, 16) : '';
            var tz = d && d.timezone ? d.timezone : el('ev_tz').getAttribute('data-default');
            el('ev_tz').value = el('ev_tz').querySelector('option[value="' + tz + '"]') ? tz : el('ev_tz').getAttribute('data-default');
            if (!start) { default_when(); }   // a new event starts now (next 5 minutes, in the event's time zone) and runs an hour
            set_reminders(d && d.reminders != null ? d.reminders : '1440');   // new events: a reminder the day before
            el('ev_url').value      = d ? (d.external_url || '') : '';
            el('ev_pw').value       = d ? (d.call_password || '') : '';
            setCall.waiting(d && parseInt(d.call_waiting_room, 10) === 1 ? '1' : '0');
            setCall.share(d && d.call_screen_share === 'everyone' ? 'everyone' : 'host');
            setCall.attendees(d && d.call_attendees === 'watch' ? 'watch' : 'talk');
            setCall.chat(d && parseInt(d.call_chat, 10) === 0 ? '0' : '1');
            el('ev_venue').value    = d ? (d.venue_name || '') : '';
            el('ev_street').value   = d ? (d.street || (d.venue_name ? '' : (d.location || ''))) : '';   // older events only have the one-line location
            el('ev_city').value     = d ? (d.city || '') : '';
            el('ev_region').value   = d ? (d.region || '') : '';
            el('ev_postal').value   = d ? (d.postal_code || '') : '';
            el('ev_instructions').value = d ? (d.access_instructions || '') : '';
            var at = d ? (d.access_type || 'free') : 'free', p = d ? parseFloat(d.price) : 0;
            el('ev_price').value = at !== 'free' && p > 0 ? Math.round(p) : '';
            var who = 'anyone';
            if (at === 'subscribers') { who = 'subscribers'; }
            if (at === 'tier' && d.tier_id && el('ev_who').querySelector('option[value="' + d.tier_id + '"]')) { who = String(d.tier_id); }
            el('ev_who').value = el('ev_who').querySelector('option[value="' + who + '"]') ? who : 'anyone';
            el('ev_capacity').value = d && parseInt(d.capacity, 10) > 0 ? parseInt(d.capacity, 10) : '';
            status = d && d.status ? d.status : 'draft';
            setFormat(d && (d.format === 'in_person' || d.format === 'cls_video') ? d.format : 'virtual');
            set_preview(false);
            ed.show(ed.first, false);
            refresh();
        }

        function validate() {
            var errs = [], url = el('ev_url').value.trim(), date = el('ev_date').value, t1 = el('ev_time').value, t2 = el('ev_time_end').value;
            if (el('ev_title').value.trim() === '') { errs.push({ section: 'details', input: 'ev_title', err: 'evErr_title', msg: 'Give the event a name.' }); }
            if (date === '' || t1 === '') { errs.push({ section: 'when', input: date === '' ? 'ev_date' : 'ev_time', err: 'evErr_date', msg: 'Pick the date and start time.' }); }
            else if (t2 !== '' && t2 === t1) { errs.push({ section: 'when', input: 'ev_time_end', err: 'evErr_date', msg: 'The end time must be after the start time.' }); }
            if (el('ev_format').value === 'virtual' && !/^https?:\/\//i.test(url)) { errs.push({ section: 'where', input: 'ev_url', err: 'evErr_url', msg: 'Add the meeting link (it starts with https://).' }); }
            if (el('ev_format').value === 'in_person' && (el('ev_street').value.trim() === '' || el('ev_city').value.trim() === '')) {
                errs.push({ section: 'where', input: el('ev_street').value.trim() === '' ? 'ev_street' : 'ev_city', err: 'evErr_location', msg: 'Add the street address and city.' });
            }
            if (el('ev_price').value.trim() !== '' && price() > 0 && price() < 10) { errs.push({ section: 'tickets', input: 'ev_price', err: 'evErr_price', msg: 'Paid tickets start at 10 credits. Leave it empty for a free event.' }); }
            else if (price() > 5000 || Math.floor(price()) !== price()) { errs.push({ section: 'tickets', input: 'ev_price', err: 'evErr_price', msg: 'Enter whole credits, up to 5,000.' }); }
            var cap = el('ev_capacity').value.trim();
            if (cap !== '' && !(parseInt(cap, 10) >= 1)) { errs.push({ section: 'tickets', input: 'ev_capacity', err: 'evErr_capacity', msg: 'Enter 1 or more, or leave it empty for unlimited.' }); }
            ed.showErrors(errs);
            return errs.length === 0;
        }

        function save(btn) {
            if (btn.disabled || !validate()) { return; }
            var who = el('ev_who').value, paid = price() >= 10, date = el('ev_date').value;
            var access = who === 'anyone' ? (paid ? 'paid' : 'free') : (who === 'subscribers' ? 'subscribers' : 'tier');
            var label = btn.textContent; btn.disabled = true; btn.textContent = 'Saving…';
            ApiDataSvc.apiCall('post', 'event_save', {
                id: el('ev_id').value || 0,
                title: el('ev_title').value.trim(),
                description: el('ev_desc').value,
                start_at: date + 'T' + el('ev_time').value,
                end_at: el('ev_time_end').value !== '' ? end_date(date, el('ev_time').value, el('ev_time_end').value) + 'T' + el('ev_time_end').value : '',
                timezone: el('ev_tz').value,
                reminders: reminders(),
                format: el('ev_format').value,
                external_url: el('ev_url').value.trim(),
                call_password: el('ev_pw').value.trim(),
                call_waiting_room: el('ev_call_waiting').value,
                call_screen_share: el('ev_call_share').value,
                call_attendees: el('ev_call_attendees').value,
                call_chat: el('ev_call_chat').value,
                venue_name: el('ev_venue').value.trim(),
                street: el('ev_street').value.trim(),
                city: el('ev_city').value.trim(),
                region: el('ev_region').value.trim(),
                postal_code: el('ev_postal').value.trim(),
                access_instructions: el('ev_instructions').value,
                access_type: access,
                tier_id: access === 'tier' ? who : 0,
                price: paid ? Math.round(price()) : 0,   // credits
                capacity: el('ev_capacity').value.trim() !== '' ? el('ev_capacity').value : 0,
                status: opts.status ? opts.status() : status
            }, function (r) {
                btn.disabled = false; btn.textContent = label;
                var o = parse(r);
                if (!o || !o.success) { if (window.cls_need_agreement(o)) { return; } if (window.toastr) { toastr.error((o && o.message) || 'Could not save the event'); } return; }
                if (opts.onSaved) { opts.onSaved(o); }
            });
        }

        return { load: load, save: save };
    };

    /* ---- /events list: Create modal ---- */
    var modalEl = el('eventModal');
    if (!modalEl || !document.querySelector('.ev')) { return; }
    var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;
    var editor = window.EventEditor(modalEl, {
        onTitle: function (t) { el('evModalTitle').textContent = t !== '' ? t : 'New Event'; },
        status: function () { return el('evCreateLive').checked ? 'published' : 'draft'; },
        onSaved: function (o) { window.location.href = '/events/manage/' + o.id; }
    });
    var opener = null;
    modalEl.addEventListener('shown.bs.modal', function () { el('ev_title').focus(); });
    modalEl.addEventListener('hidden.bs.modal', function () { if (opener) { opener.focus(); } opener = null; });
    el('evCreate').addEventListener('click', function () { opener = this; editor.load(null); if (modal) { modal.show(); } });
    el('ev_save').addEventListener('click', function () { editor.save(this); });
})();
