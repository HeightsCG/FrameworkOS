/* Influencer video tools: Motion Control (/influencers/motion), Replace Character (/influencers/replace) and
   Scene (/influencers/scene). One init per page keyed on #inf[data-page]; shared pieces (api, credit line, job
   polling, Library picker, frame export) come from AiTools (ai-tools.js). snake_case throughout. */
jQuery(function ($) {
    "use strict";

    var $root = $('#inf');
    if (!$root.length || !window.AiTools) { return; }

    var CFG  = window.INF_CONFIG || {};
    var C    = CFG.config || {};
    var inf  = CFG.influencer || {};
    var page = $root.data('page');
    var T    = window.AiTools;
    var esc  = T.esc, api = T.api, err = T.err;

    if (page !== 'motion' && page !== 'replace' && page !== 'scene') { return; }
    T.set_balance(C.ai_credits);
    if (typeof toastr !== 'undefined') { toastr.options = $.extend(toastr.options || {}, { positionClass: 'toast-bottom-right', timeOut: 3200, preventDuplicates: true }); }

    $('#inf_who').on('change', function () { window.location = '/influencers/' + page + '/' + this.value; });

    /* ---- shared ---- */
    function seg_pick(id, on_change) {
        $('#' + id).on('click', '.inf-seg__opt', function () {
            if ($(this).prop('disabled')) { return; }
            $('#' + id + ' .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false');
            $(this).addClass('is-on').attr('aria-pressed', 'true');
            if (on_change) { on_change($(this).data('value')); }
        });
    }
    function seg_val(id) { return $('#' + id + ' .inf-seg__opt.is-on').data('value'); }
    function val(sel) { return String($(sel).val() || '').trim(); }
    function confirm_spend(title, credits, cb) {
        Swal.fire({ title: title, text: 'This uses ' + Number(credits).toLocaleString() + ' AI credits.', showCancelButton: true, confirmButtonText: 'Continue',
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }, buttonsStyling: false, reverseButtons: true })
            .then(function (r) { if (r.isConfirmed) { cb(); } });
    }
    function source_html(a, empty_icon, empty_text, change_text) {
        if (!a) { return '<span class="inf-source__empty"><i class="' + empty_icon + '" aria-hidden="true"></i><span>' + esc(empty_text) + '</span></span>'; }
        return '<img src="' + esc(a.thumb_url || a.display_url) + '" alt="">' + (a.duration ? '<span class="inf-source__dur">' + T.clock(a.duration) + '</span>' : '') +
            '<span class="inf-source__change">' + esc(change_text) + '</span>';
    }
    function notes($ul, items, bad) {
        $ul.toggleClass('inf-notes--bad', !!bad).prop('hidden', !items.length)
            .html(items.map(function (t) { return '<li><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>' + esc(t) + '</span></li>'; }).join(''));
    }
    function refresh_balance(cb) { api('media_edit_options', {}, function (r) { if (r && r.success) { T.set_balance(r.ai_credits); } if (cb) { cb(); } }); }

    /* The result column: finished videos of one job type, the one being rendered, and what to do with the selected one. */
    function results(type, on_idle) {
        var jobs = [], current = null, running = null;
        function stage(which, text) {
            $('#inf_idle').prop('hidden', which !== 'idle');
            $('#inf_busy').prop('hidden', which !== 'busy');
            $('#inf_video').prop('hidden', which !== 'video');
            if (which !== 'video') { var v = document.getElementById('inf_video'); v.pause(); }
            if (text) { $('#inf_busy_text').text(text); }
        }
        function tiles() {
            var out = [];
            $.each(jobs, function (i, j) {
                if (j.status === 'done') { $.each(j.assets || [], function (k, a) { out.push({ job: j, asset: a }); }); }
                else if (j.status === 'failed' || j.status === 'cancelled') { out.push({ job: j, asset: null }); }
            });
            return out;
        }
        function strip() {
            $('#inf_strip').html(tiles().map(function (t, i) {
                if (!t.asset) { return '<span class="inf-strip__item inf-strip__item--failed" title="' + esc(t.job.error) + '"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>'; }
                return '<button type="button" class="inf-strip__item' + (current && current.id === t.asset.id ? ' is-on' : '') + '" data-i="' + i + '" aria-label="Open video"><img src="' + esc(t.asset.thumb_url) + '" alt=""></button>';
            }).join(''));
        }
        function select(a) {
            current = a;
            var v = document.getElementById('inf_video');
            v.poster = a.display_url || a.thumb_url || ''; v.src = a.video_url || ''; v.load();
            stage('video'); $('#inf_result').prop('hidden', false);
            strip();
        }
        function watch(job_id) {
            stage('busy', 'Queued'); $('#inf_result').prop('hidden', true);
            running = T.poll_job(job_id, function (j) { $('#inf_busy_text').text(T.status_text(j)); }, function (j) {
                running = null;
                $.each(jobs, function (i, x) { if (x.id === j.id) { jobs[i] = j; } });
                if (j.status === 'done' && j.assets && j.assets.length) { select(j.assets[0]); }
                else {
                    stage(current ? 'video' : 'idle'); if (current) { $('#inf_result').prop('hidden', false); } strip();
                    toastr.error((j.error || 'The video failed.') + ' Your AI credits were returned.', '', { timeOut: 9000 });
                }
                refresh_balance(on_idle);
            });
            if (on_idle) { on_idle(); }
        }
        $('#inf_strip').on('click', '.inf-strip__item[data-i]', function () { var t = tiles()[parseInt($(this).data('i'), 10)]; if (t && t.asset && !running) { select(t.asset); } });
        $('#inf_res_download').on('click', function () {
            if (!current) { return; }
            api('influencer_asset_url', { asset_id: current.id, variant: 'original' }, function (o) {
                if (!o || !o.success) { err(o, 'Could not prepare the download.'); return; }
                var a = document.createElement('a'); a.href = o.url; a.download = ''; document.body.appendChild(a); a.click(); a.remove();
            });
        });
        $('#inf_res_post').on('click', function () { if (!current) { return; } try { sessionStorage.setItem('cs_open_asset', String(current.id)); } catch (e) {} window.location = '/studio'; });
        $('#inf_res_frame').on('click', function () {
            if (!current) { return; }
            var $b = $(this).prop('disabled', true), at = document.getElementById('inf_video').currentTime || 0;
            T.export_frame(current.id, at, function (frame) {
                $b.prop('disabled', false);
                if (!frame) { return; }
                Swal.fire({ title: 'Frame Saved', text: 'The frame is in your Library.', imageUrl: frame.thumb_url, imageHeight: 180, showCancelButton: true, confirmButtonText: 'Use As Source', cancelButtonText: 'Done',
                    customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }, buttonsStyling: false, reverseButtons: true })
                    .then(function (r) { if (r.isConfirmed) { window.location = '/influencers/replicate/' + inf.id + '/' + frame.id; } });
            });
        });
        api('influencer_jobs_list', { id: inf.id, type: type, limit: 12 }, function (o) {
            if (!o || !o.success) { return; }
            jobs = o.jobs || [];
            strip();
            var live = jobs.filter(function (j) { return j.status !== 'done' && j.status !== 'failed' && j.status !== 'cancelled'; })[0];
            if (live) { watch(live.id); }
        });
        return {
            busy: function () { return !!running; },
            sending: function (text) { stage('busy', text || 'Sending'); },
            idle: function () { stage(current ? 'video' : 'idle'); },
            started: function (job, job_id) { jobs.unshift(job); strip(); watch(job_id); }
        };
    }

    /* =====================================================================
     * Motion Control
     * =================================================================== */
    function init_motion() {
        var video = null, image = null, check = null, checking = false, making = null, sending = false;
        var R = results('motion', cost);

        function model_key() { return String(seg_val('inf_mo_quality') || ''); }
        function price() { return (check && check.prices && check.prices[model_key()]) ? check.prices[model_key()].credits : 0; }
        function cost() {
            var has_errors = !!(check && check.errors && check.errors.length);
            $('#inf_mo_cost').html(check && !has_errors ? T.credits_html(price()) : '');
            $('#inf_mo_go').prop('disabled', !video || !image || !check || checking || !!making || sending || R.busy() || has_errors || !T.can_afford(price()));
        }
        function run_check() {
            if (!video) { return; }
            checking = true; cost();
            var asked_v = video.id, asked_i = image ? image.id : 0;
            $('#inf_mo_len').prop('hidden', false).html('<span class="spinner-border spinner-border-sm text-primary"></span> ' + (image ? 'Comparing the first frame with the video' : 'Reading the video'));
            api('influencer_motion_check', { video_asset_id: asked_v, image_asset_id: asked_i }, function (o) {
                if (!video || video.id !== asked_v || (image ? image.id : 0) !== asked_i) { return; }
                checking = false;
                if (!o || !o.success) { check = null; $('#inf_mo_len').text(''); notes($('#inf_mo_notes'), [(o && o.message) || 'Could not read that video.'], true); cost(); return; }
                check = o;
                $('#inf_mo_len').text(o.seconds + ' seconds' + (o.aspect ? ' · ' + o.aspect : '') + ' · the result is the same length');
                notes($('#inf_mo_notes'), o.errors.length ? o.errors : o.warnings, o.errors.length > 0);
                cost();
            });
        }
        function set_video(a) {
            video = a; check = null;
            $('#inf_mo_video').html(source_html(a, 'fa-solid fa-film', 'Choose a Video From Your Library', 'Change Video')).toggleClass('has-img', !!a);
            $('#inf_mo_make').prop('hidden', !a);
            notes($('#inf_mo_notes'), [], false);
            run_check();
        }
        function set_image(a) {
            image = a;
            $('#inf_mo_image').html(source_html(a, 'fa-regular fa-image', 'Choose From Library Or Upload', 'Change Image')).toggleClass('has-img', !!a);
            run_check();
        }
        $('#inf_mo_video').on('click', function () { T.pick_image({ type: 'video', title: 'Choose a Motion Video' }, set_video); });
        $('#inf_mo_image').on('click', function () { if (!making) { T.pick_image({ title: 'Choose the First Frame' }, set_image); } });
        seg_pick('inf_mo_quality', cost);

        /* Make First Frame: frame 0 of the motion video, recreated with her in it */
        $('#inf_mo_make').on('click', function () {
            if (!video || making) { return; }
            var p = ((C.pickers || {}).replicate || [])[0];
            confirm_spend('Make the First Frame?', p ? p.credits : 0, function () {
                $('#inf_mo_make').prop('disabled', true);
                $('#inf_mo_image').html('<span class="inf-source__empty"><span class="spinner-border spinner-border-sm text-primary" role="status"></span><span id="inf_mo_making">Reading the first moment of the video</span></span>').removeClass('has-img');
                making = function () {}; cost();
                api('influencer_motion_first_frame', { id: inf.id, video_asset_id: video.id }, function (o) {
                    if (!o || !o.success) { making = null; $('#inf_mo_make').prop('disabled', false); set_image(image); err(o, 'Could not make the first frame.'); return; }
                    making = T.poll_job(o.job_id, function (j) { $('#inf_mo_making').text(T.status_text(j) + ' her first frame'); }, function (j) {
                        making = null; $('#inf_mo_make').prop('disabled', false);
                        refresh_balance(cost);
                        if (j.status !== 'done' || !j.assets || !j.assets.length) { set_image(image); toastr.error((j.error || 'The first frame failed.') + ' Your AI credits were returned.'); return; }
                        set_image(j.assets[0]);
                    });
                });
            });
        });

        $('#inf_mo_go').on('click', function () {
            if (!video || !image) { return; }
            sending = true; cost(); R.sending('Sending');
            api('influencer_motion_start', { id: inf.id, video_asset_id: video.id, image_asset_id: image.id, model_key: model_key(), prompt: $('#inf_mo_prompt').val() }, function (o) {
                sending = false;
                if (!o || !o.success) { R.idle(); err(o, 'Could not start the video.'); cost(); return; }
                R.started(o.job, o.job_id);
            });
        });
        cost();
    }

    /* =====================================================================
     * Replace Character
     * =================================================================== */
    function init_replace() {
        var video = null, prep = null, preparing = false, dirty = false, sending = false, typing = null;
        var R = results('replace', cost);

        function model_key() { return String($('#inf_rp_model .inf-opt.is-on').data('key') || ''); }
        function price() { return (prep && prep.prices) ? (prep.prices[model_key()] || 0) : 0; }
        function fail(msg) { $('#inf_rp_err').text(msg || '').prop('hidden', !msg); }
        function options() {
            return { id: inf.id, video_asset_id: video ? video.id : 0, subject: $('#inf_rp_subject').val(), outfit: seg_val('inf_rp_outfit'),
                lock_others: $('#inf_rp_lock').prop('checked') ? 1 : 0, remove_text: $('#inf_rp_text').prop('checked') ? 1 : 0, model_key: model_key() };
        }
        function cost() {
            var has_errors = !!(prep && prep.errors && prep.errors.length);
            $('#inf_rp_cost').html(prep && !has_errors ? T.credits_html(price()) : '');
            $('#inf_rp_go').prop('disabled', !video || !prep || preparing || sending || R.busy() || has_errors || val('#inf_rp_prompt') === '' || !T.can_afford(price()));
        }
        function prepare() {
            if (!video) { return; }
            preparing = true; dirty = false; fail('');
            $('#inf_rp_prompt').prop('disabled', true).attr('placeholder', 'Reading the video');
            $('#inf_rp_rewrite').prop('hidden', true);
            $('#inf_rp_len').prop('hidden', false).html('<span class="spinner-border spinner-border-sm text-primary"></span> Reading the video');
            cost();
            var body = options(), asked = JSON.stringify(body);
            api('influencer_replace_prepare', body, function (o) {
                if (!video || JSON.stringify(options()) !== asked) { return; }
                preparing = false;
                $('#inf_rp_prompt').prop('disabled', false).attr('placeholder', 'Describe the edit');
                $('#inf_rp_rewrite').prop('hidden', false);
                if (!o || !o.success) {
                    prep = null; $('#inf_rp_len').text('');
                    if (o && (o.need_plan || o.need_credits || o.need_upgrade)) { err(o); } else { fail((o && o.message) || 'Could not read that video.'); }
                    cost(); return;
                }
                prep = o;
                $('#inf_rp_prompt').val(o.prompt);
                $('#inf_rp_len').text(o.seconds + ' seconds' + (o.people > 1 ? ' · ' + o.people + ' people seen' : '') + ' · the result is the same length');
                $('#inf_rp_angles').text('Her reference image' + (o.angles > 0 ? ' and ' + o.angles + ' approved angle' + (o.angles === 1 ? '' : 's') : ', no approved angles yet'));
                fail(o.errors.length ? o.errors.join(' ') : '');
                cost();
            });
        }
        function changed() { if (video && !dirty) { prepare(); } else if (video) { cost(); } }
        $('#inf_rp_video').on('click', function () {
            T.pick_image({ type: 'video', title: 'Choose a Source Video' }, function (a) {
                video = a; prep = null;
                $('#inf_rp_video').html(source_html(a, 'fa-solid fa-film', 'Choose a Video From Your Library', 'Change Video')).addClass('has-img');
                prepare();
            });
        });
        $('#inf_rp_subject').on('input', function () { clearTimeout(typing); typing = setTimeout(changed, 700); });
        seg_pick('inf_rp_outfit', changed);
        $('#inf_rp_lock, #inf_rp_text').on('change', changed);
        $('#inf_rp_model').on('click', '.inf-opt', function () { $('#inf_rp_model .inf-opt').removeClass('is-on'); $(this).addClass('is-on'); changed(); });
        $('#inf_rp_prompt').on('input', function () { dirty = true; fail(''); cost(); });
        $('#inf_rp_rewrite').on('click', prepare);
        $('#inf_rp_attest').on('change', function () { $(this).closest('.inf-attest').removeClass('is-missing'); fail(''); });

        $('#inf_rp_go').on('click', function () {
            if (!video || !prep) { return; }
            if (!$('#inf_rp_attest').prop('checked')) {
                $('#inf_rp_attest').closest('.inf-attest').addClass('is-missing'); $('#inf_rp_attest').trigger('focus');
                fail('Confirm that you own this video or have the rights to use it.');
                return;
            }
            fail(''); sending = true; cost(); R.sending('Checking the source video');
            $('#inf_rp_go').html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Checking The Video');
            var body = $.extend(options(), { prompt: val('#inf_rp_prompt'), attested: 1 });
            api('influencer_replace_start', body, function (o) {
                sending = false;
                $('#inf_rp_go').html('<i class="fa-solid fa-wand-magic-sparkles"></i> Replace Character');
                if (!o || !o.success) {
                    R.idle();
                    if (o && (o.need_plan || o.need_credits || o.need_upgrade)) { err(o); } else { fail((o && o.message) || 'Could not start the replacement.'); }
                    cost(); return;
                }
                R.started(o.job, o.job_id);
            });
        });
        cost();
    }

    /* =====================================================================
     * Scene: dialogue in one take
     * =================================================================== */
    function init_scene() {
        var lines = [{ speaker: 1, text: '', cue: '', say: '' }], build = null, building = false, sending = false, typing = null, seq = 0;
        var MAX = parseInt(C.scene_max_lines, 10) || 20;
        var R = results('scene', cost);
        var word1 = (CFG.gender === 'man') ? 'GUY' : 'GIRL';

        function second() { return String(seg_val('inf_sc_second') || 'none'); }
        function labels() {
            if (build && build.cast && build.cast.length) { return build.cast.map(function (c) { return c.label; }); }
            var two = (second() === 'described' && seg_val('inf_sc_gender') === 'man') ? 'GUY' : 'GIRL';
            return second() === 'none' ? [word1 + ' 1'] : [word1 + ' 1', two + ' 2'];
        }
        function model_key() { return String($('#inf_sc_model .inf-opt.is-on').data('key') || ''); }
        function price() { return (build && build.prices) ? (build.prices[model_key()] || 0) : 0; }
        function fail(msg) { $('#inf_sc_err').text(msg || '').prop('hidden', !msg); }
        function has_text() { return lines.some(function (l) { return String(l.text).trim() !== ''; }); }
        function cost() {
            $('#inf_sc_cost').html(build ? T.credits_html(price()) : '');
            $('#inf_sc_go').prop('disabled', !build || building || sending || R.busy() || !has_text() || !T.can_afford(price()));
        }
        function render_lines() {
            var L = labels();
            $('#inf_sc_lines').html(lines.map(function (l, i) {
                var who = L.length > 1
                    ? '<select class="form-select inf-line__who" data-f="speaker" aria-label="Speaker">' + L.map(function (name, k) { return '<option value="' + (k + 1) + '"' + (l.speaker === k + 1 ? ' selected' : '') + '>' + esc(name) + '</option>'; }).join('') + '</select>'
                    : '<input type="text" class="form-control inf-line__who" value="' + esc(L[0]) + '" aria-label="Speaker" readonly tabindex="-1">';
                return '<li class="inf-line" data-i="' + i + '">' + who +
                    '<textarea class="form-control inf-line__text" data-f="text" rows="1" maxlength="500" placeholder="I have to tell you something" aria-label="Line ' + (i + 1) + '">' + esc(l.text) + '</textarea>' +
                    '<button type="button" class="inf-line__rm" data-rm title="Remove Line" aria-label="Remove line ' + (i + 1) + '"' + (lines.length === 1 ? ' disabled' : '') + '><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>' +
                    '<div class="inf-line__more"><input type="text" class="form-control" data-f="cue" maxlength="160" placeholder="Whispering, half smiling" aria-label="Acting cue" value="' + esc(l.cue) + '">' +
                    '<input type="text" class="form-control" data-f="say" maxlength="200" placeholder="Nova as NOH-vah" aria-label="Pronunciation" value="' + esc(l.say) + '"></div></li>';
            }).join(''));
            $('#inf_sc_add').prop('hidden', lines.length >= MAX);
        }
        function body() {
            var two = second() !== 'none';
            return { id: inf.id, lines: JSON.stringify(lines.map(function (l) { return { speaker: two ? l.speaker : 1, text: l.text, cue: l.cue, say: l.say }; })), setting: $('#inf_sc_setting').val(),
                second: second(), second_influencer_id: $('#inf_sc_other').val() || 0, second_description: $('#inf_sc_desc').val(), second_gender: seg_val('inf_sc_gender'),
                seconds: $('#inf_sc_secs').val(), aspect: seg_val('inf_sc_size'), model_key: model_key() };
        }
        function rebuild() {
            if (!has_text()) { build = null; fail(''); $('#inf_sc_secs option[value="0"]').text('Fit The Script'); cost(); return; }
            building = true; cost();
            var my = ++seq;
            api('influencer_scene_build', body(), function (o) {
                if (my !== seq) { return; }
                building = false;
                if (!o || !o.success) { build = null; fail((o && o.message) || 'Could not put the scene together.'); cost(); return; }
                var had = labels().join('|');
                build = o; fail('');
                $('#inf_sc_secs option[value="0"]').text('Fit The Script (' + o.suggested_seconds + ' seconds)');
                if (labels().join('|') !== had) { $('#inf_sc_lines .inf-line__who option').each(function () { $(this).text(labels()[parseInt(this.value, 10) - 1] || this.text); }); }
                cost();
            });
        }
        function soon() { clearTimeout(typing); typing = setTimeout(rebuild, 600); cost(); }

        $('#inf_sc_lines').on('input change', '[data-f]', function () {
            var i = parseInt($(this).closest('.inf-line').data('i'), 10), f = $(this).data('f');
            lines[i][f] = (f === 'speaker') ? (parseInt(this.value, 10) || 1) : this.value;
            if (this.tagName === 'TEXTAREA') { this.style.height = 'auto'; this.style.height = Math.min(160, this.scrollHeight + 2) + 'px'; }
            soon();
        });
        $('#inf_sc_lines').on('click', '[data-rm]', function () { if (lines.length < 2) { return; } lines.splice(parseInt($(this).closest('.inf-line').data('i'), 10), 1); render_lines(); soon(); });
        $('#inf_sc_add').on('click', function () {
            if (lines.length >= MAX) { return; }
            var last = lines[lines.length - 1], two = second() !== 'none';
            lines.push({ speaker: two ? (last.speaker === 1 ? 2 : 1) : 1, text: '', cue: '', say: '' });   // a conversation alternates
            render_lines();
            $('#inf_sc_lines .inf-line').last().find('textarea').trigger('focus');
        });
        seg_pick('inf_sc_second', function (v) {
            $('#inf_sc_other_wrap').prop('hidden', v !== 'influencer');
            $('#inf_sc_desc_wrap').prop('hidden', v !== 'described');
            if (v === 'none') { $.each(lines, function (i, l) { l.speaker = 1; }); }
            build = null; render_lines(); soon();
        });
        seg_pick('inf_sc_gender', function () { build = null; render_lines(); soon(); });
        seg_pick('inf_sc_size', soon);
        $('#inf_sc_other, #inf_sc_secs').on('change', function () { build = null; render_lines(); soon(); });
        $('#inf_sc_desc, #inf_sc_setting').on('input', soon);
        $('#inf_sc_model').on('click', '.inf-opt', function () { $('#inf_sc_model .inf-opt').removeClass('is-on'); $(this).addClass('is-on'); soon(); });

        $('#inf_sc_go').on('click', function () {
            if (!build) { return; }
            confirm_spend('Generate This Scene?', price(), function () {
                sending = true; cost(); R.sending('Sending');
                api('influencer_scene_start', body(), function (o) {
                    sending = false;
                    if (!o || !o.success) {
                        R.idle();
                        if (o && (o.need_plan || o.need_credits || o.need_upgrade)) { err(o); } else { fail((o && o.message) || 'Could not start the scene.'); }
                        cost(); return;
                    }
                    R.started(o.job, o.job_id);
                });
            });
        });
        render_lines(); cost();
    }

    if (page === 'motion')  { init_motion(); }
    if (page === 'replace') { init_replace(); }
    if (page === 'scene')   { init_scene(); }
});
