/* Influencer voice pages: Voice (/influencers/voice: saved voices, Voice Design, text to speech) and Talking
   (/influencers/talking: a close-up lip-synced to a script or an audio file). One init per page keyed on
   #inf[data-page]; shared pieces come from AiTools (ai-tools.js). snake_case throughout. */
jQuery(function ($) {
    "use strict";

    var $root = $('#inf');
    if (!$root.length || !window.AiTools) { return; }

    var CFG  = window.INF_CONFIG || {};
    var C    = CFG.config || {};
    var V    = C.voice || {};
    var inf  = CFG.influencer || {};
    var page = $root.data('page');
    var T    = window.AiTools;
    var esc  = T.esc, api = T.api, err = T.err;

    if (page !== 'voice' && page !== 'talking') { return; }
    T.set_balance(C.ai_credits);
    if (typeof toastr !== 'undefined') { toastr.options = $.extend(toastr.options || {}, { positionClass: 'toast-bottom-right', timeOut: 3200, preventDuplicates: true }); }

    $('#inf_who').on('change', function () { window.location = '/influencers/' + page + '/' + this.value; });

    function val(sel) { return String($(sel).val() || '').trim(); }
    function seg_pick(id, on_change) {
        $('#' + id).on('click', '.inf-seg__opt', function () {
            if ($(this).prop('disabled')) { return; }
            $('#' + id + ' .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false');
            $(this).addClass('is-on').attr('aria-pressed', 'true');
            if (on_change) { on_change($(this).data('value')); }
        });
    }
    function seg_val(id) { return $('#' + id + ' .inf-seg__opt.is-on').data('value'); }
    function secs(n) { n = Math.max(1, Math.round(n)); return n + (n === 1 ? ' second' : ' seconds'); }
    function refused(o) { return o && (o.need_plan || o.need_credits || o.need_upgrade); }
    function confirm_box(title, text, confirm_text, cb) {
        Swal.fire({ title: title, text: text, showCancelButton: true, confirmButtonText: confirm_text,
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }, buttonsStyling: false, reverseButtons: true })
            .then(function (r) { if (r.isConfirmed) { cb(); } });
    }

    /* =====================================================================
     * Voice: saved voices, Voice Design, text to speech
     * =================================================================== */
    function init_voice() {
        var voices = [], cap = 0, saved = 0, takes_n = 2, designing = false, design = null, speaking = null, price_timer = null, speech_price = 0, design_price = 0, pricing = 0;

        /* ---- saved voices ---- */
        function view(which) {
            $('#inf_vo_loading').prop('hidden', which !== 'loading');
            $('#inf_vo_loaderr').prop('hidden', which !== 'error');
            $('#inf_vo_empty').prop('hidden', which !== 'empty');
            $('#inf_vo_list').prop('hidden', which !== 'list');
        }
        /* ---- the two panels: Her Voices, then Text To Speech (which needs a voice) ---- */
        var tab_chosen = false;
        function show_tab(name) {
            if (name === 'speech' && !voices.length) { name = 'voices'; }
            $('#inf_vo_tabs .inf-sub__item').each(function () { var on = $(this).data('tab') === name; $(this).toggleClass('is-on', on).attr('aria-selected', on ? 'true' : 'false'); });
            $('#inf_vo_panel_voices').prop('hidden', name !== 'voices'); $('#inf_vo_panel_speech').prop('hidden', name !== 'speech');
            $(window).trigger('resize');   // the panel just shown takes the space left on the page
        }
        function sync_tabs() {
            var none = !voices.length;
            $('#inf_vo_tab_speech').prop('disabled', none).attr('title', none ? 'Design a voice first' : null);
            // First paint: straight to speaking when she has a voice, to designing one when she does not.
            if (!tab_chosen) { tab_chosen = true; show_tab(none ? 'voices' : 'speech'); } else if (none) { show_tab('voices'); }
        }
        $('#inf_vo_tabs').on('click', '.inf-sub__item:not(:disabled)', function () { tab_chosen = true; show_tab($(this).data('tab')); });

        /* ---- Design A Voice fills itself in from her persona; every field stays editable ---- */
        var DEFAULT_PREVIEW = $('#inf_vd_text').val(), filled = false, filling = false;
        function design_untouched() {
            return $('#inf_vd_age').val().trim() == '' && $('#inf_vd_tone').val().trim() == '' && $('#inf_vd_city').val().trim() == '' && $('#inf_vd_country').val().trim() == '' && $('#inf_vd_text').val() === DEFAULT_PREVIEW;
        }
        function fill_design(force) {
            if (filling || (!force && (filled || !design_untouched()))) { return; }
            filling = true; filled = true;
            var $f = $('#inf_vd_age, #inf_vd_keyword, #inf_vd_tone, #inf_vd_city, #inf_vd_country, #inf_vd_text').prop('disabled', true);
            $('#inf_vd_filling').prop('hidden', false); $('#inf_vd_refill').prop('hidden', true); $('#inf_vd_go').prop('disabled', true);
            api('influencer_voice_suggest', { id: inf.id }, function (o) {
                filling = false; $f.prop('disabled', false);
                $('#inf_vd_filling').prop('hidden', true); $('#inf_vd_refill').prop('hidden', false); $('#inf_vd_go').prop('disabled', false);
                if (!o || !o.success) { return; }   // the form simply stays as it was, ready to fill by hand
                $('#inf_vd_age').val(o.age_vibe || ''); $('#inf_vd_tone').val(o.tone || ''); $('#inf_vd_city').val(o.city || ''); $('#inf_vd_country').val(o.country || '');
                if (o.keyword && $('#inf_vd_keyword option[value="' + o.keyword + '"]').length) { $('#inf_vd_keyword').val(o.keyword); }
                if (o.preview_text) { $('#inf_vd_text').val(o.preview_text); }
                $('#inf_vd_age, #inf_vd_text').removeClass('is-invalid').trigger('input');
            });
        }
        $('#inf_vd_refill').on('click', function () { fill_design(true); });

        function render_voices() {
            sync_tabs();
            $('#inf_vo_count').text(saved + ' of ' + cap + ' saved');
            $('#inf_vo_voice').prop('disabled', !voices.length).html(voices.length
                ? voices.map(function (v) { return '<option value="' + v.id + '"' + (v.is_active ? ' selected' : '') + '>' + esc(v.name) + (v.is_active ? ' (active)' : '') + '</option>'; }).join('')
                : '<option value="">No voice yet</option>');
            if (!voices.length) { view('empty'); speech_cost(); return; }
            $('#inf_vo_list').html(voices.map(function (v) {
                var s = v.settings || {}, sub = [s.age_vibe, s.keyword, [s.city, s.country].filter(Boolean).join(', '), s.tone].filter(Boolean).join(' · ');
                return '<li class="inf-voice" data-voice="' + v.id + '"><div class="inf-voice__body"><span class="inf-voice__name">' + esc(v.name) + (v.is_active ? '<span class="inf-voice__badge">Active</span>' : '') + '</span>' +
                    '<span class="inf-voice__sub" title="' + esc(v.prompt) + '">' + esc(sub || v.prompt) + '</span></div>' +
                    '<div class="inf-voice__act">' + (v.is_active ? '' : '<button type="button" class="btn btn-secondary btn-sm" data-activate>Set Active</button>') +
                    '<button type="button" class="inf-link inf-link--danger" data-delete>Remove</button></div></li>';
            }).join(''));
            view('list'); speech_cost();
        }
        function load_voices(quiet) {
            if (!quiet) { view('loading'); }
            api('influencer_voices', { id: inf.id }, function (o) {
                if (!o || !o.success) { if (!quiet) { view('error'); } return; }
                voices = o.voices || []; cap = o.cap; saved = o.saved; takes_n = o.takes || 2;
                if (!o.configured) { $('#inf_vd_go, #inf_vo_go').prop('disabled', true); $('#inf_vd_err').text('Voice is not set up on this site yet.').prop('hidden', false); }
                render_voices(); design_cost();
            });
        }
        $('#inf_vo_reload').on('click', function () { load_voices(); });
        $('#inf_vo_list').on('click', '[data-activate]', function () {
            var $b = $(this).prop('disabled', true), id = $b.closest('.inf-voice').data('voice');
            api('influencer_voice_activate', { id: inf.id, voice_id: id }, function (o) { if (!o || !o.success) { $b.prop('disabled', false); err(o, 'Could not set that voice active.'); return; } load_voices(true); });
        });
        $('#inf_vo_list').on('click', '[data-delete]', function () {
            var id = $(this).closest('.inf-voice').data('voice'), v = voices.filter(function (x) { return x.id === id; })[0];
            confirm_box('Remove This Voice?', (v ? v.name : 'This voice') + ' will no longer be available for speech or talking videos. Audio already made with it stays in your Library.', 'Remove', function () {
                api('influencer_voice_delete', { id: inf.id, voice_id: id }, function (o) { if (!o || !o.success) { err(o, 'Could not remove that voice.'); return; } toastr.success('Voice removed'); load_voices(true); });
            });
        });

        /* ---- prices follow what is typed (asked from the server, which owns the formula) ---- */
        function ask_prices() {
            clearTimeout(price_timer);
            price_timer = setTimeout(function () {
                var my = ++pricing;
                api('influencer_speech_price', { text: $('#inf_vo_text').val(), preview_text: $('#inf_vd_text').val() }, function (o) {
                    if (my !== pricing || !o || !o.success) { return; }
                    speech_price = o.price; design_price = o.design_price;
                    speech_cost(); design_cost();
                });
            }, 350);
        }
        function speech_cost() {
            var has_text = val('#inf_vo_text') !== '';
            $('#inf_vo_cost').html(has_text && voices.length ? T.credits_html(speech_price) : '');
            $('#inf_vo_go').prop('disabled', !voices.length || !has_text || !!speaking || !T.can_afford(speech_price));
        }
        function design_cost() {
            var n = $('#inf_vd_text').val().length, min = V.preview_min || 100, max = V.preview_max || 1000;
            $('#inf_vd_count').text(n + ' of ' + min + ' to ' + max + ' characters').toggleClass('inf-err', n < min || n > max);
            $('#inf_vd_cost').html(T.credits_html(design_price));
            $('#inf_vd_go').prop('disabled', designing || !T.can_afford(design_price));
        }

        /* ---- Voice Design ---- */
        function design_errors(field, msg) {
            $('#inf_vo_design_card [data-err]').prop('hidden', true).text('');
            $('#inf_vo_design_card .is-invalid').removeClass('is-invalid');
            $('#inf_vd_err').prop('hidden', true).text('');
            if (!msg) { return; }
            var map = { age_vibe: '#inf_vd_age', preview_text: '#inf_vd_text' };
            if (field && map[field]) { $(map[field]).addClass('is-invalid').trigger('focus'); $('#inf_vo_design_card [data-err="' + field + '"]').text(msg).prop('hidden', false); }
            else { $('#inf_vd_err').text(msg).prop('hidden', false); }
        }
        function render_cands() {
            if (!design) { $('#inf_vd_cands').prop('hidden', true).empty(); return; }
            var at_cap = saved >= cap;
            $('#inf_vd_cands').prop('hidden', false).html(design.candidates.map(function (c, i) {
                return '<li class="inf-cand" data-index="' + c.index + '"><span class="inf-cand__n">Candidate ' + (i + 1) + '</span>' +
                    '<audio controls preload="none" src="' + esc(c.url) + '"></audio>' +
                    '<input type="text" class="form-control" maxlength="120" placeholder="Voice name" aria-label="Name for candidate ' + (i + 1) + '" value="' + esc(c.saved ? c.name : '') + '"' + (c.saved ? ' disabled' : '') + '>' +
                    (c.saved ? '<span class="inf-voice__badge">Saved</span>' : '<button type="button" class="btn btn-secondary btn-sm" data-save' + (at_cap ? ' disabled title="Remove a voice to save another"' : '') + '>Save Voice</button>') + '</li>';
            }).join(''));
        }
        $('#inf_vd_go').on('click', function () {
            design_errors();
            if (val('#inf_vd_age') === '') { design_errors('age_vibe', 'Describe the age and vibe.'); return; }
            designing = true; design_cost();
            $('#inf_vd_go').html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Designing');
            api('influencer_voice_design', { id: inf.id, age_vibe: $('#inf_vd_age').val(), keyword: $('#inf_vd_keyword').val(), city: $('#inf_vd_city').val(), country: $('#inf_vd_country').val(),
                tone: $('#inf_vd_tone').val(), preview_text: $('#inf_vd_text').val() }, function (o) {
                designing = false;
                $('#inf_vd_go').html('<i class="fa-solid fa-sliders"></i> Design Voice');
                if (!o || !o.success) { if (refused(o)) { err(o); } else { design_errors(o && o.field, (o && o.message) || 'The voice could not be designed. Try again.'); } design_cost(); return; }
                design = o; render_cands(); design_cost(); speech_cost();
            });
        });
        $('#inf_vd_cands').on('click', '[data-save]', function () {
            var $li = $(this).closest('.inf-cand'), $b = $(this).prop('disabled', true), index = parseInt($li.data('index'), 10), name = String($li.find('input').val() || '').trim();
            api('influencer_voice_save', { id: inf.id, token: design.token, index: index, name: name }, function (o) {
                if (!o || !o.success) { $b.prop('disabled', false); err(o, 'That voice could not be saved.'); return; }
                $.each(design.candidates, function (i, c) { if (c.index === index) { c.saved = true; c.name = o.voice.name; } });
                toastr.success('Voice saved');
                api('influencer_voices', { id: inf.id }, function (r) { if (r && r.success) { voices = r.voices || []; cap = r.cap; saved = r.saved; render_voices(); } render_cands(); });
            });
        });
        $('#inf_vd_age, #inf_vd_text').on('input', function () { $(this).removeClass('is-invalid'); });
        $('#inf_vd_text').on('input', function () { design_cost(); ask_prices(); });

        /* ---- text to speech ---- */
        function fail(msg) { $('#inf_vo_err').text(msg || '').prop('hidden', !msg); }
        $('#inf_vo_tags').on('click', '[data-tag]', function () {
            var el = document.getElementById('inf_vo_text'), tag = '[' + $(this).data('tag') + '] ';
            var a = el.selectionStart, b = el.selectionEnd, v = el.value;
            if (v.length + tag.length > (V.speech_max || 3000)) { return; }
            el.value = v.slice(0, a) + tag + v.slice(b);
            el.focus(); el.selectionStart = el.selectionEnd = a + tag.length;
            fail(''); speech_cost(); ask_prices();
        });
        $('#inf_vo_text').on('input', function () { fail(''); speech_cost(); ask_prices(); });
        $('#inf_vo_enhance').on('click', function () {
            var text = val('#inf_vo_text'), $b = $(this);
            if (text === '') { fail('Write the script first.'); $('#inf_vo_text').trigger('focus'); return; }
            $b.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Enhancing');
            api('influencer_speech_enhance', { id: inf.id, text: text }, function (o) {
                $b.prop('disabled', false).html('<i class="fa-solid fa-wand-magic-sparkles"></i> Enhance');
                if (!o || !o.success) { if (refused(o)) { err(o); } else { fail((o && o.message) || 'Could not enhance the script.'); } return; }
                $('#inf_vo_text').val(o.text); fail(''); speech_cost(); ask_prices();
            });
        });
        function render_takes(job) {
            var list = (job && job.takes) || [];
            if (!list.length) { $('#inf_vo_takes').prop('hidden', true).empty(); return; }
            // Every take is already in the Library; the row's one menu downloads or deletes it.
            $('#inf_vo_takes').prop('hidden', false).html(list.map(function (t) {
                return '<div class="inf-take" data-job="' + job.id + '" data-take="' + t.index + '" data-asset="' + (t.asset_id || 0) + '"><span class="inf-take__n">Take ' + (t.index + 1) + ' · ' + secs(t.duration) + '</span>' +
                    '<audio controls controlslist="nodownload noplaybackrate" preload="none" src="' + esc(t.url) + '"></audio>' +
                    (t.asset_id ? '<div class="dropdown"><button type="button" class="inf-take__menu" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More"><i class="fa-solid fa-ellipsis"></i></button>' +
                        '<ul class="dropdown-menu dropdown-menu-end"><li><button type="button" class="dropdown-item" data-take-download>Download</button></li>' +
                        '<li><button type="button" class="dropdown-item text-danger" data-take-delete>Delete</button></li></ul></div>' : '<span></span>') + '</div>';
            }).join(''));
        }
        function watch(job_id) {
            $('#inf_vo_takes').prop('hidden', false).html('<div class="inf-takes__busy"><span class="spinner-border spinner-border-sm text-primary" role="status"></span><span id="inf_vo_busy">Queued</span></div>');
            speaking = T.poll_job(job_id, function (j) { $('#inf_vo_busy').text(T.status_text(j) + ' ' + takes_n + ' takes'); }, function (j) {
                speaking = null;
                api('media_edit_options', {}, function (r) { if (r && r.success) { T.set_balance(r.ai_credits); } speech_cost(); design_cost(); });
                if (j.status !== 'done') { $('#inf_vo_takes').prop('hidden', true).empty(); fail((j.error || 'The speech failed.') + ' Your AI credits were returned.'); speech_cost(); return; }
                render_takes(j); speech_cost();
            });
            speech_cost();
        }
        $('#inf_vo_go').on('click', function () {
            var text = val('#inf_vo_text');
            if (text === '' || !voices.length) { return; }
            fail(''); $('#inf_vo_go').prop('disabled', true);
            // The last run's takes make way for this one straight away, so nobody saves an old take by mistake.
            $('#inf_vo_takes').prop('hidden', false).html('<div class="inf-takes__busy"><span class="spinner-border spinner-border-sm text-primary" role="status"></span><span id="inf_vo_busy">Sending</span></div>');
            api('influencer_speech_start', { id: inf.id, text: text, voice_id: $('#inf_vo_voice').val() }, function (o) {
                if (!o || !o.success) { $('#inf_vo_takes').prop('hidden', true).empty(); if (refused(o)) { err(o); } else { fail((o && o.message) || 'Could not start the speech.'); } speech_cost(); return; }
                watch(o.job_id);
            });
        });
        $('#inf_vo_takes').on('click', '[data-take-download]', function () {
            var id = $(this).closest('.inf-take').data('asset');
            api('media_download', { id: id }, function (o) { if (o && o.success && o.url) { window.location = o.url; } else { err(o, 'Could not download that take.'); } });
        });
        $('#inf_vo_takes').on('click', '[data-take-delete]', function () {
            var $t = $(this).closest('.inf-take'), id = $t.data('asset');
            confirm_box('Delete This Take?', 'It is removed from your Library as well.', 'Delete', function () {
                api('media_delete', { id: id }, function (o) {
                    if (!o || !o.success) { err(o, 'Could not delete that take.'); return; }
                    $t.remove(); if (!$('#inf_vo_takes .inf-take').length) { $('#inf_vo_takes').prop('hidden', true); }
                    toastr.success('Take deleted');
                });
            });
        });

        load_voices(); ask_prices();
        // The latest run's takes stay available until they expire, so a reload does not lose them.
        api('influencer_jobs_list', { id: inf.id, type: 'speech', limit: 1 }, function (o) {
            var j = (o && o.success && o.jobs && o.jobs[0]) || null;
            if (!j) { return; }
            if (j.status === 'done') { render_takes(j); } else if (j.status !== 'failed' && j.status !== 'cancelled') { watch(j.id); }
        });
    }

    /* =====================================================================
     * Talking video
     * =================================================================== */
    function init_talking() {
        var image = null, audio = null, est = null, estimating = 0, sending = false, group = '', timer = null, current = null, history = [], typing = null;

        function source() { return String(seg_val('inf_tk_source') || 'script'); }
        function fail(msg) { $('#inf_tk_err').text(msg || '').prop('hidden', !msg); }
        function has_speech() { return source() === 'audio' ? !!audio : val('#inf_tk_script') !== ''; }
        function cost() {
            var ok = est && est.seconds > 0 && !est.too_long && (source() === 'audio' || est.has_voice);
            $('#inf_tk_cost').html(est && est.seconds > 0 ? (est.exact ? '' : 'About ') + T.credits_html(est.total) : '');
            $('#inf_tk_go').prop('disabled', !image || !has_speech() || !ok || sending || !!group || !T.can_afford(est ? est.total : 0));
        }
        function estimate() {
            fail('');
            if (!has_speech()) { est = null; cost(); return; }
            var my = ++estimating;
            api('influencer_talking_estimate', { id: inf.id, script: source() === 'script' ? $('#inf_tk_script').val() : '', audio_asset_id: source() === 'audio' && audio ? audio.id : 0 }, function (o) {
                if (my !== estimating) { return; }
                if (!o || !o.success) { est = null; fail((o && o.message) || 'Could not price that.'); cost(); return; }
                est = o;
                if (source() === 'script') {
                    // With a voice: just say which one speaks (plain text, nothing to click). Without one: the one link that is needed.
                    var $v = $('#inf_tk_voice').prop('hidden', false);
                    if (o.has_voice) { $v.text('Spoken In ' + o.voice_name).removeAttr('href').addClass('is-text'); }
                    else { $v.text('Design A Voice First').attr('href', $v.data('href')).removeClass('is-text'); }
                }
                if (o.too_long) { fail('That runs about ' + o.seconds + ' seconds. A talking video can be up to ' + o.max_seconds + ' seconds.'); }
                else if (source() === 'script' && !o.has_voice) { fail(inf.name + ' has no voice yet. Design one first.'); }
                cost();
            });
        }
        $('#inf_tk_image').on('click', function () {
            T.pick_image({ title: 'Choose an Image Where the Face Is Clear', influencer: inf.id }, function (a) {
                image = a;
                $('#inf_tk_image').html('<img src="' + esc(a.thumb_url || a.display_url) + '" alt=""><span class="inf-source__change">Change Image</span>').addClass('has-img');
                cost();
            });
        });
        $('#inf_tk_audio').on('click', function () {
            T.pick_image({ type: 'audio', title: 'Choose an Audio File' }, function (a) {
                audio = a;
                $('#inf_tk_audio').html('<span class="inf-source__file"><i class="fa-solid fa-music" aria-hidden="true"></i><span>' + esc(a.name) + '</span><em>' + T.clock(a.duration) + '</em></span>');
                estimate();
            });
        });
        seg_pick('inf_tk_source', function (v) {
            $('#inf_tk_script_wrap').prop('hidden', v !== 'script');
            $('#inf_tk_audio_wrap').prop('hidden', v !== 'audio');
            est = null; estimate();
        });
        $('#inf_tk_script').on('input', function () { clearTimeout(typing); typing = setTimeout(estimate, 500); cost(); });

        /* results */
        function stage(which, text) {
            $('#inf_idle').prop('hidden', which !== 'idle');
            $('#inf_busy').prop('hidden', which !== 'busy');
            $('#inf_video').prop('hidden', which !== 'video');
            if (which !== 'video') { document.getElementById('inf_video').pause(); }
            if (text) { $('#inf_busy_text').text(text); }
        }
        function strip() {
            $('#inf_strip').html(history.map(function (a, i) {
                return '<button type="button" class="inf-strip__item' + (current && current.id === a.id ? ' is-on' : '') + '" data-i="' + i + '" aria-label="Open video"><img src="' + esc(a.thumb_url) + '" alt=""></button>';
            }).join(''));
        }
        function select(a) {
            current = a;
            var v = document.getElementById('inf_video');
            v.poster = a.display_url || a.thumb_url || ''; v.src = a.video_url || ''; v.load();
            stage('video'); $('#inf_result').prop('hidden', false); strip();
        }
        function poll() {
            clearTimeout(timer);
            api('influencer_talking_status', { group_key: group }, function (o) {
                var s = (o && o.success) ? o.status : null;
                if (!s) { timer = setTimeout(poll, 5000); return; }
                if (s.state === 'working') {
                    $('#inf_busy_text').text(s.parts > 1 ? (s.done >= s.parts ? 'Joining the parts' : 'Rendering part ' + Math.min(s.parts, s.done + 1) + ' of ' + s.parts) : 'Generating');
                    timer = setTimeout(poll, 5000); return;
                }
                group = '';
                T.set_balance(o.ai_credits);
                if (s.state === 'done' && s.asset) { history.unshift(s.asset); select(s.asset); }
                else { stage(current ? 'video' : 'idle'); if (current) { $('#inf_result').prop('hidden', false); } toastr.error((s.error || 'The video failed.') + ' Your AI credits were returned.', '', { timeOut: 9000 }); }
                cost();
            });
        }
        $('#inf_strip').on('click', '.inf-strip__item', function () { var a = history[parseInt($(this).data('i'), 10)]; if (a && !group) { select(a); } });
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
            var $b = $(this).prop('disabled', true);
            T.export_frame(current.id, document.getElementById('inf_video').currentTime || 0, function (frame) { $b.prop('disabled', false); if (frame) { toastr.success('Frame saved to your Library'); } });
        });

        $('#inf_tk_go').on('click', function () {
            if (!image || !has_speech()) { return; }
            fail(''); sending = true; cost();
            stage('busy', source() === 'script' ? 'Recording the speech' : 'Preparing the audio'); $('#inf_result').prop('hidden', true);
            $('#inf_tk_go').html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Starting');
            api('influencer_talking_start', { id: inf.id, image_asset_id: image.id, script: source() === 'script' ? $('#inf_tk_script').val() : '', audio_asset_id: source() === 'audio' && audio ? audio.id : 0 }, function (o) {
                sending = false;
                $('#inf_tk_go').html('<i class="fa-solid fa-wand-magic-sparkles"></i> Generate Video');
                if (!o || !o.success) {
                    stage(current ? 'video' : 'idle'); if (current) { $('#inf_result').prop('hidden', false); }
                    if (refused(o)) { err(o); } else { fail((o && o.message) || 'Could not start the video.'); }
                    cost(); return;
                }
                group = o.group_key; T.set_balance(o.ai_credits);
                stage('busy', o.parts > 1 ? 'Rendering part 1 of ' + o.parts : 'Generating');
                cost(); timer = setTimeout(poll, 4000);
            });
        });

        // Earlier talking videos of hers, and one that is still rendering.
        api('influencer_jobs_list', { id: inf.id, type: 'talking', limit: 12 }, function (o) {
            if (!o || !o.success) { return; }
            var seen = {};
            $.each(o.jobs || [], function (i, j) {
                $.each(j.assets || [], function (k, a) { if (!seen[a.id]) { seen[a.id] = true; history.push(a); } });
                if (!group && j.group_key && j.status !== 'done' && j.status !== 'failed' && j.status !== 'cancelled') { group = j.group_key; }
            });
            strip();
            if (group) { stage('busy', 'Generating'); cost(); poll(); }
        });
        estimate(); cost();
    }

    if (page === 'voice')   { init_voice(); }
    if (page === 'talking') { init_talking(); }
});
