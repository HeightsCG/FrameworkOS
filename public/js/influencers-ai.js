/* Influencer image tools: References (/influencers/references), Replicate Photo (/influencers/replicate)
   and Generate Carousel (/influencers/carousel). One init per page keyed on #inf[data-page]; shared pieces
   (api, credit line, job polling, Library picker, Edit window) come from AiTools (ai-tools.js).
   snake_case throughout. */
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

    if (page !== 'references' && page !== 'replicate' && page !== 'carousel') { return; }
    T.set_balance(C.ai_credits);

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
    function seg_set(id, v) { $('#' + id + ' .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false').filter('[data-value="' + v + '"]').addClass('is-on').attr('aria-pressed', 'true'); }
    function model_of(list_id) {
        var key = $('#' + list_id + ' .inf-opt.is-on').data('key'), m = null;
        $.each((C.pickers || {}).replicate || [], function (i, o) { if (o.key === key) { m = o; } });
        return m;
    }
    /* Shapes the chosen model cannot render are disabled; a selected one moves to the first it can. */
    function sync_sizes(seg_id, m) {
        var ok = (m && m.aspects && m.aspects.length) ? m.aspects : null;
        $('#' + seg_id + ' .inf-seg__opt').each(function () { $(this).prop('disabled', !!ok && ok.indexOf(String($(this).data('value'))) < 0); });
        if ($('#' + seg_id + ' .inf-seg__opt.is-on').prop('disabled')) { seg_set(seg_id, ok[0]); }
    }
    function lightbox(url) { if (!url) { return; } $('#inf_lightbox_img').attr('src', url); $('#inf_lightbox').prop('hidden', false); }
    $('#inf_lightbox, #inf_lightbox_close').on('click', function () { $('#inf_lightbox').prop('hidden', true); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') { $('#inf_lightbox').prop('hidden', true); } });
    function confirm_spend(title, credits, cb) {
        Swal.fire({ title: title, text: 'This uses ' + Number(credits).toLocaleString() + ' AI credits.', showCancelButton: true, confirmButtonText: 'Continue',
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }, buttonsStyling: false, reverseButtons: true })
            .then(function (r) { if (r.isConfirmed) { cb(); } });
    }
    function download(asset_id) {
        api('influencer_asset_url', { asset_id: asset_id, variant: 'original' }, function (o) {
            if (!o || !o.success) { err(o, 'Could not prepare the download.'); return; }
            var a = document.createElement('a'); a.href = o.url; a.download = ''; document.body.appendChild(a); a.click(); a.remove();
        });
    }
    function use_in_post(asset_id) { try { sessionStorage.setItem('cs_open_asset', String(asset_id)); } catch (e) {} window.location = '/studio'; }

    /* =====================================================================
     * References: the multi-angle set
     * =================================================================== */
    function init_references() {
        var state = null, timer = null, busy_slots = {};

        function show(which) {
            $('#inf_ang_loading').prop('hidden', which !== 'loading');
            $('#inf_ang_error').prop('hidden', which !== 'error');
            $('#inf_ang_noref').prop('hidden', which !== 'noref');
            $('#inf_angles, #inf_ang_bar').prop('hidden', which !== 'grid');
        }
        function load(quiet) {
            if (!quiet) { show('loading'); }
            api('influencer_angle_status', { id: inf.id }, function (o) {
                if (!o || !o.success) { if (!quiet) { show('error'); } return; }
                state = o; busy_slots = {};
                if (!o.reference) { show('noref'); return; }
                render(); show('grid');
                clearTimeout(timer);
                if (o.active > 0) { timer = setTimeout(function () { load(true); }, 4000); }
            });
        }
        function card(s) {
            var img, st, act = '';
            if (s.status === 'ready') {
                img = '<button type="button" class="inf-angle__img" data-view="' + esc(s.display_url) + '" aria-label="View ' + esc(s.label) + '"><img src="' + esc(s.thumb_url) + '" alt="' + esc(s.label) + '" loading="lazy"></button>';
                st  = s.approved ? '<span class="inf-angle__st inf-angle__st--ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Approved</span>' : '<span class="inf-angle__st">Needs approval</span>';
                act = (s.approved ? '' : '<button type="button" class="btn btn-secondary btn-sm" data-approve="' + s.asset_id + '">Approve</button>') +
                      '<button type="button" class="inf-link" data-reroll="' + esc(s.slot) + '">Reroll</button>';
            } else if (s.status === 'working' || busy_slots[s.slot]) {
                img = '<span class="inf-angle__img inf-angle__img--ph"><span class="spinner-border spinner-border-sm text-primary" role="status"></span></span>';
                st  = '<span class="inf-angle__st">Generating</span>';
            } else if (s.status === 'failed') {
                img = '<span class="inf-angle__img inf-angle__img--ph inf-angle__img--bad"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>';
                st  = '<span class="inf-angle__st inf-angle__st--bad" title="' + esc(s.error) + '">' + esc(s.error || 'Generation failed') + '</span>';
                act = '<button type="button" class="btn btn-secondary btn-sm" data-make="' + esc(s.slot) + '">Try Again</button>';
            } else {
                img = '<span class="inf-angle__img inf-angle__img--ph"><i class="fa-regular fa-image" aria-hidden="true"></i></span>';
                st  = '<span class="inf-angle__st">Not generated</span>';
                act = '<button type="button" class="btn btn-secondary btn-sm" data-make="' + esc(s.slot) + '">Generate</button>';
            }
            return '<article class="inf-angle" data-slot="' + esc(s.slot) + '" data-shape="' + esc(s.aspect) + '">' + img +
                '<div class="inf-angle__body"><h3 class="inf-angle__t">' + esc(s.label) + '</h3>' + st + '<div class="inf-angle__act">' + act + '</div></div></article>';
        }
        function render() {
            var missing = state.slots.filter(function (s) { return s.status === 'empty' || s.status === 'failed'; }).length;
            $('#inf_ang_base').html('<img src="' + esc(state.reference.thumb_url) + '" alt="Her reference image">');
            $('#inf_ang_count').text(state.approved + ' of ' + state.total + ' angles approved');
            T.patch_children($('#inf_angles'), state.slots.map(card).join(''));
            var total = missing * state.price_each;
            $('#inf_ang_generate').prop('hidden', missing === 0).prop('disabled', missing === 0 || !T.can_afford(total))
                .html('<i class="fa-solid fa-wand-magic-sparkles"></i> ' + (missing === state.total ? 'Generate Angle Set' : 'Generate ' + missing + ' Missing'));
            $('#inf_ang_cost').html(missing === 0 ? esc(Number(state.price_each).toLocaleString() + ' AI credits per reroll · ' + T.get_balance().toLocaleString() + ' left') : T.credits_html(total));
        }
        function generate(slots) {
            $.each(slots.length ? slots : state.slots.filter(function (s) { return s.status === 'empty' || s.status === 'failed'; }).map(function (s) { return s.slot; }), function (i, k) { busy_slots[k] = true; });
            render();
            $('#inf_ang_generate').prop('disabled', true);
            api('influencer_angle_generate', { id: inf.id, slots: slots.join(',') }, function (o) {
                if (!o || !o.success) { err(o, 'Could not start the angle set.'); }
                load(true);
            });
        }
        $('#inf_ang_generate').on('click', function () { generate([]); });
        $('#inf_ang_retry').on('click', function () { load(); });
        $('#inf_angles').on('click', '[data-make]', function () { generate([String($(this).data('make'))]); });
        $('#inf_angles').on('click', '[data-reroll]', function () {
            var slot = String($(this).data('reroll'));
            confirm_spend('Reroll This Angle?', state.price_each, function () { generate([slot]); });
        });
        $('#inf_angles').on('click', '[data-approve]', function () {
            var $b = $(this).prop('disabled', true);
            api('influencer_angle_approve', { id: inf.id, asset_id: $b.data('approve'), approved: 1 }, function (o) {
                if (!o || !o.success) { $b.prop('disabled', false); err(o, 'Could not approve that image.'); return; }
                load(true);
            });
        });
        $('#inf_angles').on('click', '[data-view]', function () { lightbox($(this).data('view')); });
        load();
    }

    /* =====================================================================
     * Replicate Photo
     * =================================================================== */
    function init_replicate() {
        var source = null, prep = null, strokes = [], dirty = false, preparing = false, running = null;
        var jobs = [], current = null, painting = false;
        var canvas = document.getElementById('inf_rep_canvas'), ctx = canvas.getContext('2d');
        var BRUSH = 0.045, PAD = 0.22;

        function mask_mode() { return seg_val('inf_rep_maskmode') || 'auto'; }
        function price() { var m = model_of('inf_rep_model'); return (m ? m.credits : 0) * (parseInt(seg_val('inf_rep_n'), 10) || 1); }
        function cost() {
            $('#inf_rep_cost').html(T.credits_html(price()));
            $('#inf_rep_go').prop('disabled', !source || preparing || !!running || String($('#inf_rep_prompt').val() || '').trim() === '' || !T.can_afford(price()));
        }
        function fail(msg) { $('#inf_rep_err').text(msg || '').prop('hidden', !msg); }

        /* the mask preview: the source with the area that will be greyed out */
        function draw() {
            var w = canvas.width, h = canvas.height;
            ctx.clearRect(0, 0, w, h);
            ctx.fillStyle = 'rgba(128,128,128,.82)';
            var mode = mask_mode();
            if (mode === 'auto' && prep && prep.face) {
                var f = prep.face;
                ctx.beginPath();
                ctx.ellipse((f.x + f.w / 2) * w, (f.y + f.h / 2) * h, f.w * w * (1 + 2 * PAD) / 2, f.h * h * (1 + 2 * PAD) / 2, 0, 0, Math.PI * 2);
                ctx.fill();
            }
            if (mode === 'brush') {
                $.each(strokes, function (i, s) { ctx.beginPath(); ctx.arc(s.x * w, s.y * h, s.r * w, 0, Math.PI * 2); ctx.fill(); });
            }
        }
        function fit_canvas() {
            var img = document.getElementById('inf_rep_img');
            if (!img.clientWidth) { return; }
            canvas.width = img.clientWidth; canvas.height = img.clientHeight;
            draw();
        }
        function mask_state() {
            var mode = mask_mode(), text = '';
            if (mode === 'off') { text = 'The photo is sent as it is.'; }
            else if (mode === 'brush') { text = strokes.length ? 'Painted areas are hidden from the model.' : 'Paint over the face to hide it.'; }
            else if (preparing) { text = 'Finding the face'; }
            else if (prep && prep.face) { text = 'The face is hidden from the model.'; }
            else if (prep) { text = 'No face found. Use the brush.'; }
            $('#inf_rep_maskstate').text(text);
            $('#inf_rep_clear').prop('hidden', !(mode === 'brush' && strokes.length));
            $(canvas).toggleClass('is-brush', mode === 'brush');
        }
        $(window).on('resize', fit_canvas);
        $('#inf_rep_img').on('load', fit_canvas);
        seg_pick('inf_rep_maskmode', function () { draw(); mask_state(); });
        $('#inf_rep_clear').on('click', function () { strokes = []; draw(); mask_state(); });
        function paint(e) {
            var r = canvas.getBoundingClientRect();
            var x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height;
            if (x < 0 || x > 1 || y < 0 || y > 1 || strokes.length >= 4000) { return; }
            strokes.push({ x: Math.round(x * 1000) / 1000, y: Math.round(y * 1000) / 1000, r: BRUSH });
            draw();
        }
        canvas.addEventListener('pointerdown', function (e) { if (mask_mode() !== 'brush') { return; } painting = true; canvas.setPointerCapture(e.pointerId); paint(e); e.preventDefault(); });
        canvas.addEventListener('pointermove', function (e) { if (painting) { paint(e); } });
        canvas.addEventListener('pointerup', function () { if (painting) { painting = false; mask_state(); } });
        canvas.addEventListener('pointercancel', function () { painting = false; });

        /* Claude reads the photo: where the face is, and the Style or Exact prompt to edit */
        function prepare() {
            if (!source) { return; }
            preparing = true; prep = null; dirty = false;
            fail('');
            // Reading the photo and writing the prompt takes several seconds: say so on the photo itself, where the creator is looking.
            $('#inf_rep_stage').addClass('is-reading').attr('aria-busy', 'true');
            $('#inf_rep_reading').prop('hidden', false);
            $('#inf_rep_prompt').val('').prop('disabled', true).attr('placeholder', 'Writing the prompt from your photo');
            $('#inf_rep_rewrite').prop('hidden', true);
            draw(); mask_state(); cost();
            var asked = source.id;
            api('influencer_replicate_prepare', { id: inf.id, source_asset_id: source.id, mode: seg_val('inf_rep_mode'), instruction: $('#inf_rep_extra').val() }, function (o) {
                if (!source || source.id !== asked) { return; }
                preparing = false;
                $('#inf_rep_stage').removeClass('is-reading').removeAttr('aria-busy');
                $('#inf_rep_reading').prop('hidden', true);
                $('#inf_rep_prompt').prop('disabled', false).attr('placeholder', 'Describe what to recreate');
                $('#inf_rep_rewrite').prop('hidden', false);
                if (!o || !o.success) {
                    if (o && (o.need_plan || o.need_credits || o.need_upgrade)) { err(o); } else { fail((o && o.message) || 'Could not read the photo. Write the prompt yourself or try again.'); }
                } else {
                    prep = o; $('#inf_rep_prompt').val(o.prompt);
                    if (!o.face && mask_mode() === 'auto') { seg_set('inf_rep_maskmode', 'brush'); }
                }
                draw(); mask_state(); cost();
            });
        }
        function set_source(a) {
            source = { id: a.id, url: a.display_url || a.thumb_url };
            strokes = []; seg_set('inf_rep_maskmode', 'auto');
            $('#inf_rep_img').attr('src', source.url);
            $('#inf_rep_pick').prop('hidden', true);
            $('#inf_rep_mask').prop('hidden', false);
            prepare();
        }
        function pick() { T.pick_image({ title: 'Choose a Source Photo' }, set_source); }
        $('#inf_rep_pick, #inf_rep_change').on('click', pick);
        $('#inf_rep_rewrite').on('click', prepare);
        $('#inf_rep_prompt').on('input', function () { dirty = true; fail(''); cost(); });
        seg_pick('inf_rep_mode', function () { if (source && !dirty) { prepare(); } });
        $('#inf_rep_extra').on('change', function () { if (source && !dirty) { prepare(); } });
        $('#inf_rep_model').on('click', '.inf-opt', function () { $('#inf_rep_model .inf-opt').removeClass('is-on'); $(this).addClass('is-on'); sync_sizes('inf_rep_size', model_of('inf_rep_model')); cost(); });
        seg_pick('inf_rep_size'); seg_pick('inf_rep_n', cost);

        /* results */
        function stage(which, text) {
            $('#inf_idle').prop('hidden', which !== 'idle');
            $('#inf_busy').prop('hidden', which !== 'busy');
            $('#inf_main').prop('hidden', which !== 'main');
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
        function render_strip() {
            $('#inf_strip').html(tiles().map(function (t, i) {
                if (!t.asset) { return '<span class="inf-strip__item inf-strip__item--failed" title="' + esc(t.job.error) + '"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>'; }
                return '<button type="button" class="inf-strip__item' + (current && current.id === t.asset.id ? ' is-on' : '') + '" data-i="' + i + '"><img src="' + esc(t.asset.thumb_url) + '" alt=""></button>';
            }).join(''));
        }
        function select(a) {
            current = a;
            $('#inf_main_img').attr('src', a.display_url || a.thumb_url);
            stage('main'); $('#inf_result').prop('hidden', false);
            render_strip();
        }
        $('#inf_strip').on('click', '.inf-strip__item[data-i]', function () { var t = tiles()[parseInt($(this).data('i'), 10)]; if (t && t.asset && !running) { select(t.asset); } });
        $('#inf_expand').on('click', function () { if (current) { lightbox(current.display_url || current.thumb_url); } });
        $('#inf_res_download').on('click', function () { if (current) { download(current.id); } });
        $('#inf_res_post').on('click', function () { if (current) { use_in_post(current.id); } });
        $('#inf_res_video').on('click', function () { if (current) { window.location = '/influencers/videos/' + inf.id + '/' + current.id; } });
        $('#inf_res_edit').on('click', function () {
            if (!current) { return; }
            T.edit({ id: current.id, display_url: current.display_url, thumb_url: current.thumb_url }, function (made, job) { jobs.unshift(job); select(made); T.set_balance(T.get_balance()); cost(); });
        });

        function watch(job_id) {
            stage('busy', 'Queued'); $('#inf_result').prop('hidden', true);
            running = T.poll_job(job_id, function (j) { $('#inf_busy_text').text(T.status_text(j)); }, function (j) {
                running = null;
                $.each(jobs, function (i, x) { if (x.id === j.id) { jobs[i] = j; } });
                api('media_edit_options', {}, function (r) { if (r && r.success) { T.set_balance(r.ai_credits); } cost(); });
                if (j.status === 'done' && j.assets && j.assets.length) { select(j.assets[0]); }
                else { stage(current ? 'main' : 'idle'); if (current) { $('#inf_result').prop('hidden', false); } render_strip(); toastr.error((j.error || 'The replica failed.') + ' Your AI credits were returned.');
                    // A safety refusal is often specific to one model: line up the other one so trying again is one click.
                    if (j.error_code === 'content_policy') {
                        var $other = $('#inf_rep_model .inf-opt').not('.is-on').not(':disabled').first();
                        if ($other.length) { $other.trigger('click'); fail((j.error || 'This model refused the photo.') + ' ' + $other.find('.inf-opt__t').text() + ' is now selected: click Replicate Photo to try it.'); }
                        else { fail(j.error || 'This model refused the photo.'); }
                    } }
                cost();
            });
            cost();
        }
        $('#inf_rep_go').on('click', function () {
            var prompt = String($('#inf_rep_prompt').val() || '').trim(), mode = mask_mode(), m = model_of('inf_rep_model');
            if (!source || prompt === '' || !m) { return; }
            if (mode === 'brush' && !strokes.length) { fail('Paint over the face, or switch the mask off.'); return; }
            fail('');
            var body = { id: inf.id, source_asset_id: source.id, mode: seg_val('inf_rep_mode'), prompt: prompt, instruction: $('#inf_rep_extra').val(),
                aspect: seg_val('inf_rep_size'), num_images: seg_val('inf_rep_n'), model_key: m.key, mask: mode === 'off' ? 0 : 1,
                face: JSON.stringify(mode === 'auto' && prep ? (prep.face || null) : null), strokes: JSON.stringify(mode === 'brush' ? strokes : []) };
            $('#inf_rep_go').prop('disabled', true);
            stage('busy', 'Sending');
            api('influencer_replicate', body, function (o) {
                if (!o || !o.success) { stage(current ? 'main' : 'idle'); err(o, 'Could not start the replica.'); cost(); return; }
                jobs.unshift(o.job); render_strip();
                watch(o.job_id);
            });
        });

        sync_sizes('inf_rep_size', model_of('inf_rep_model'));
        cost();
        api('influencer_jobs_list', { id: inf.id, type: 'replicate', limit: 12 }, function (o) {
            if (!o || !o.success) { return; }
            jobs = o.jobs || [];
            render_strip();
            var live = jobs.filter(function (j) { return j.status !== 'done' && j.status !== 'failed' && j.status !== 'cancelled'; })[0];
            if (live) { watch(live.id); }
        });
        if (CFG.source) { set_source(CFG.source); }
    }

    /* =====================================================================
     * Generate Carousel
     * =================================================================== */
    function init_carousel() {
        var seed = null, set = null, slots = [], order = [], dropped = {}, timer = null, set_id = parseInt(CFG.set_id, 10) || 0, sortable = null, starting = false;
        var FOCUS = (C.carousel || {}).focus || {};

        function count() { return parseInt($('#inf_car_count').val(), 10) || 2; }
        function price() { var m = model_of('inf_car_model'); return (m ? m.credits : 0) * count(); }
        function cost() {
            $('#inf_car_cost').html(T.credits_html(price()));
            $('#inf_car_go').prop('disabled', starting || !T.can_afford(price()));
        }
        function fail(msg) { $('#inf_car_err').text(msg || '').prop('hidden', !msg); }

        function set_seed(a) {
            seed = a ? { id: a.id, url: a.thumb_url || a.display_url } : null;
            $('#inf_car_pick').html(seed ? '<img src="' + esc(seed.url) + '" alt="Seed image"><span class="inf-source__change">Change Image</span>'
                : '<span class="inf-source__empty"><i class="fa-regular fa-image" aria-hidden="true"></i><span>Choose From Library Or Upload</span></span>').toggleClass('has-img', !!seed);
            $('#inf_car_clear').prop('hidden', !seed);
            fail('');
        }
        $('#inf_car_pick').on('click', function () { T.pick_image({ title: 'Choose a Seed Image' }, set_seed); });
        $('#inf_car_clear').on('click', function () { set_seed(null); });
        $('#inf_car_model').on('click', '.inf-opt', function () { $('#inf_car_model .inf-opt').removeClass('is-on'); $(this).addClass('is-on'); sync_sizes('inf_car_size', model_of('inf_car_model')); cost(); });
        $('#inf_car_count').on('change', cost);
        $('#inf_car_text').on('input', function () { fail(''); });
        seg_pick('inf_car_size');

        /* the review grid */
        function view(which) {
            $('#inf_car_empty').prop('hidden', which !== 'empty');
            $('#inf_car_planning').prop('hidden', which !== 'planning');
            $('#inf_car_loaderr').prop('hidden', which !== 'error');
            $('#inf_car_grid, #inf_car_foot').prop('hidden', which !== 'grid');
        }
        function by_index(i) { var s = null; $.each(slots, function (k, x) { if (x.index === i) { s = x; } }); return s; }
        function kept() { return order.filter(function (i) { return !dropped[i]; }); }
        function kept_ready() { return kept().map(by_index).filter(function (s) { return s && s.asset_id; }); }
        function tile(s, pos, n) {
            var body, act = '';
            if (s.asset_id) {
                body = '<button type="button" class="inf-car__img" data-view="' + esc(s.display_url) + '" aria-label="View image ' + (pos + 1) + '"><img src="' + esc(s.thumb_url) + '" alt="' + esc(s.shot) + '" loading="lazy"></button>';
            } else if (s.status === 'failed') {
                body = '<span class="inf-car__img inf-car__img--bad" title="' + esc(s.error) + '"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>' + esc(s.error || 'Generation failed') + '</span></span>';
            } else {
                body = '<span class="inf-car__img inf-car__img--ph"><span class="spinner-border spinner-border-sm text-primary" role="status"></span><span>' + esc(s.status === 'queued' ? 'Queued' : 'Generating') + '</span></span>';
            }
            var working = !s.asset_id && s.status !== 'failed';
            act = '<button type="button" class="inf-car__btn" data-move="-1" title="Move Earlier" aria-label="Move earlier"' + (pos === 0 ? ' disabled' : '') + '><i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button>' +
                  '<button type="button" class="inf-car__btn" data-move="1" title="Move Later" aria-label="Move later"' + (pos === n - 1 ? ' disabled' : '') + '><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>' +
                  '<button type="button" class="inf-car__btn" data-regen title="Regenerate" aria-label="Regenerate"' + (working ? ' disabled' : '') + '><i class="fa-solid fa-rotate-right" aria-hidden="true"></i></button>' +
                  '<button type="button" class="inf-car__btn" data-drop title="Drop" aria-label="Drop from the carousel"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>';
            return '<li class="inf-car__item" data-index="' + s.index + '" data-shape="' + esc(set.aspect) + '"><span class="inf-car__n">' + (pos + 1) + '</span>' + body + '<div class="inf-car__act">' + act + '</div></li>';
        }
        function render() {
            var k = kept(), n_drop = order.length - k.length, ready = kept_ready().length, active = k.map(by_index).filter(function (s) { return s && !s.asset_id && s.status !== 'failed'; }).length;
            T.patch_children($('#inf_car_grid').attr('data-shape', set.aspect), k.map(function (i, pos) { return tile(by_index(i), pos, k.length); }).join(''));
            $('#inf_car_title').text((FOCUS[set.focus] || 'Carousel') + ' · ' + k.length + ' image' + (k.length === 1 ? '' : 's'));
            $('#inf_car_constants').text(set.constants || '');
            $('#inf_car_kept').html(esc(ready + ' of ' + k.length + ' ready') + (n_drop ? ' · <button type="button" class="inf-link" id="inf_car_restore">Restore ' + n_drop + ' Dropped</button>' : ''));
            $('#inf_car_post').prop('disabled', ready === 0 || active > 0);
            if (window.Sortable && !sortable) {
                sortable = Sortable.create(document.getElementById('inf_car_grid'), { animation: 150, filter: '.inf-car__btn', preventOnFilter: false,
                    onEnd: function () { var seen = $('#inf_car_grid .inf-car__item').map(function () { return parseInt($(this).data('index'), 10); }).get(); order = seen.concat(order.filter(function (i) { return dropped[i]; })); render(); } });
            }
        }
        function load_set(id, quiet) {
            if (!quiet) { view('planning'); $('#inf_car_planning').contents().last().replaceWith(' Loading the carousel…'); }
            api('influencer_carousel_status', { set_id: id }, function (o) {
                if (!o || !o.success) { if (!quiet) { view('error'); } return; }
                var fresh = !set || set.id !== o.set.id;
                set = o.set; slots = o.slots; set_id = set.id;
                if (fresh) { order = slots.map(function (s) { return s.index; }); dropped = {}; }
                T.set_balance(o.ai_credits); cost();
                render(); view('grid');
                clearTimeout(timer);
                if (o.active > 0) { timer = setTimeout(function () { load_set(id, true); }, 4000); }
            });
        }
        $('#inf_car_reload').on('click', function () { if (set_id) { load_set(set_id); } });
        $('#inf_car_grid').on('click', '[data-view]', function () { lightbox($(this).data('view')); });
        $('#inf_car_grid').on('click', '[data-move]', function () {
            var i = parseInt($(this).closest('.inf-car__item').data('index'), 10), k = kept(), at = k.indexOf(i), to = at + parseInt($(this).data('move'), 10);
            if (to < 0 || to >= k.length) { return; }
            k.splice(to, 0, k.splice(at, 1)[0]);
            order = k.concat(order.filter(function (x) { return dropped[x]; }));
            render();
            $('#inf_car_grid .inf-car__item[data-index="' + i + '"] [data-move="' + $(this).data('move') + '"]').trigger('focus');
        });
        $('#inf_car_grid').on('click', '[data-drop]', function () { dropped[parseInt($(this).closest('.inf-car__item').data('index'), 10)] = true; render(); });
        $('#inf_car_foot').on('click', '#inf_car_restore', function () { dropped = {}; render(); });
        $('#inf_car_grid').on('click', '[data-regen]', function () {
            var s = by_index(parseInt($(this).closest('.inf-car__item').data('index'), 10));
            if (!s) { return; }
            confirm_spend('Regenerate This Image?', (model_price(set.model_key)), function () {
                api('influencer_carousel_regenerate', { job_id: s.job_id }, function (o) {
                    if (!o || !o.success) { err(o, 'Could not regenerate that image.'); return; }
                    load_set(set.id, true);
                });
            });
        });
        function model_price(key) { var p = 0; $.each((C.pickers || {}).replicate || [], function (i, o) { if (o.key === key) { p = o.credits; } }); return p; }
        $('#inf_car_post').on('click', function () {
            var ids = kept_ready().map(function (s) { return s.asset_id; }), $b = $(this).prop('disabled', true);
            api('influencer_carousel_to_post', { asset_ids: ids.join(',') }, function (o) {
                if (!o || !o.success) { $b.prop('disabled', false); err(o, 'Could not create the post.'); return; }
                try { sessionStorage.setItem('cs_open_post', String(o.post_id)); } catch (e) {}
                window.location = '/studio';
            });
        });

        /* earlier sets */
        function load_recent() {
            api('influencer_carousel_list', { id: inf.id }, function (o) {
                var sets = (o && o.success) ? (o.sets || []) : [];
                $('#inf_car_recent_wrap').prop('hidden', sets.length < 2);
                $('#inf_car_recent').html(sets.map(function (s) {
                    var d = new Date(String(s.created_at).replace(' ', 'T') + 'Z');
                    return '<option value="' + s.id + '"' + (s.id === set_id ? ' selected' : '') + '>' + esc(d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' · ' + (FOCUS[s.focus] || s.focus) + ' · ' + s.count) + '</option>';
                }).join(''));
            });
        }
        $('#inf_car_recent').on('change', function () { var id = parseInt(this.value, 10); history.replaceState(null, '', '/influencers/carousel/' + inf.id + '/' + id); set = null; load_set(id); });

        $('#inf_car_go').on('click', function () {
            var text = String($('#inf_car_text').val() || '').trim(), m = model_of('inf_car_model'), focus = $('#inf_car_focus').val();
            if (!seed && text === '') { fail('Add a seed image or describe the scene.'); $('#inf_car_text').trigger('focus'); return; }
            if (focus === 'without_her' && !seed) { fail('Shots without her need a seed image.'); return; }
            if (!m) { return; }
            fail(''); starting = true; cost();
            $('#inf_car_go').html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Planning');
            clearTimeout(timer); view('planning'); $('#inf_car_planning').contents().last().replaceWith(' Planning the shots…');
            api('influencer_carousel_start', { id: inf.id, seed_asset_id: seed ? seed.id : 0, seed_text: text, count: count(), focus: focus, aspect: seg_val('inf_car_size'), model_key: m.key }, function (o) {
                starting = false;
                $('#inf_car_go').html('<i class="fa-solid fa-wand-magic-sparkles"></i> Generate Carousel');
                if (!o || !o.success) {
                    view(set ? 'grid' : 'empty');
                    if (o && (o.need_credits || o.need_plan || o.need_upgrade)) { err(o); } else { fail((o && o.message) || 'Could not start the carousel. Try again.'); }
                    if (o && o.set_id) { set = null; load_set(o.set_id); }
                    cost(); return;
                }
                set = null;
                history.replaceState(null, '', '/influencers/carousel/' + inf.id + '/' + o.set_id);
                load_set(o.set_id, true); load_recent(); cost();
            });
        });

        sync_sizes('inf_car_size', model_of('inf_car_model'));
        cost();
        if (set_id) { load_set(set_id); } else { view('empty'); }
        load_recent();
    }

    if (page === 'references') { init_references(); }
    if (page === 'replicate')  { init_replicate(); }
    if (page === 'carousel')   { init_carousel(); }
});
