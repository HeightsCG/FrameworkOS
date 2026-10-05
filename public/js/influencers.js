/* AI influencers pages (/influencers*). One file, one init per page keyed on #inf[data-page].
   AJAX goes through ApiDataSvc.apiCall (string responses, JSON.parse here); uploads use
   $.ajax FormData for progress. snake_case throughout. */
jQuery(function ($) {
    "use strict";

    var $root = $('#inf');
    if (!$root.length) { return; }

    var CFG   = window.INF_CONFIG || {};
    var C     = CFG.config || {};
    var LIM   = C.limits || { min_photos: 10, max_photos: 50, set_size: 10 };
    var page  = $root.data('page');

    if (typeof toastr !== 'undefined') {
        toastr.options = $.extend(toastr.options || {}, { positionClass: 'toast-bottom-right', timeOut: 3200, preventDuplicates: true });
    }

    function esc(s) { return $('<div>').text(s == null ? '' : s).html().replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    /* Hand a generated asset to the messenger: the creator picks who gets it (media attached, price optional). */
    function send_in_message(a) {
        if (!a || !window.CLSMessenger) { toastr.error('Messages are not available on this page'); return; }
        window.CLSMessenger.compose({ assets: [{ id: a.id, type: a.type || 'image', thumb: a.thumb_url || a.poster_url || a.display_url || '', name: a.name || '' }], price: 0 });
    }
    function err(o, fallback) {
        if (o && (o.need_credits || o.need_upgrade || o.need_plan)) {   // plan / credit refusals link to Billing
            toastr.error(o.message, o.need_plan ? 'Choose a plan' : (o.need_credits ? 'Buy AI credits' : 'Upgrade your plan'),
                { timeOut: 8000, extendedTimeOut: 4000, onclick: function () { window.location.href = o.need_credits ? '/account/billing?tab=credits' : '/account/billing'; } });
            return;
        }
        toastr.error((o && o.message) || fallback || 'Something went wrong. Please try again.');
    }
    function api(endpoint, body, cb) {
        ApiDataSvc.apiCall('post', endpoint, body, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) {}
            cb(o);
        });
    }
    function money(n) { return '$' + (Math.round((n || 0) * 100) / 100).toFixed(2); }
    /* "50 AI credits · 3,750 left" for a run that costs n; the balance comes from the page config and is kept current after each run */
    function credits_text(n) {
        var left = parseInt(C.ai_credits, 10) || 0;
        return Number(n).toLocaleString() + ' AI credits \u00b7 ' + left.toLocaleString() + ' left';
    }
    /* The cost line, plus a Buy AI Credits link once the balance can't cover two more runs (same rule as the Studio). */
    function credits_html(n) {
        var left = parseInt(C.ai_credits, 10) || 0;
        var low  = left < n * 2;
        return esc(credits_text(n)) + (low ? ' <a class="inf-buy-credits" href="/account/billing?tab=credits">' + (left < n ? 'Buy AI Credits to Generate' : 'Running low. Buy AI Credits') + '</a>' : '');
    }
    function spend_credits(n) { C.ai_credits = Math.max(0, (parseInt(C.ai_credits, 10) || 0) - n); }
    function price_of(type, n) { var p = (C.ai_prices || {})[type] || 0; return p * (n || 1); }

    /* shared: poll one job until it is terminal; human status text */
    // Polls a job until it is terminal. A request that never answers (network blip, a 5xx while the
    // worker is busy landing the file) used to end the loop and leave the spinner up for good, so
    // every tick has a watchdog: no answer within 20s means ask again.
    function poll_job(job_id, on_update, on_done) {
        var t = null, dog = null, stopped = false, seq = 0;
        function schedule(ms) { clearTimeout(t); t = setTimeout(tick, ms); }
        function tick() {
            if (stopped) { return; }
            var my = ++seq;
            clearTimeout(dog); dog = setTimeout(function () { if (!stopped && my === seq) { schedule(0); } }, 20000);
            api('influencer_job_get', { job_id: job_id }, function (o) {
                if (stopped || my !== seq) { return; }
                clearTimeout(dog);
                if (!o || !o.success) { schedule(4000); return; }
                var j = o.job;
                if (on_update) { on_update(j); }
                if (j.status === 'done' || j.status === 'failed' || j.status === 'cancelled') { stopped = true; on_done(j); return; }
                schedule(3000);
            });
        }
        schedule(1500);
        return function () { stopped = true; clearTimeout(t); clearTimeout(dog); };
    }
    function status_text(j) {
        if (j.status === 'queued' && j.wait_reason) { return 'Waiting for a slot'; }
        if (j.status === 'queued') { return 'Queued'; }
        if (j.status === 'submitting') { return 'Sending'; }
        if (j.status === 'running') { return 'Generating'; }
        if (j.status === 'landing') { return 'Saving'; }
        return j.status;
    }

    /* shared: delete one of her files (library soft delete) after a confirm */
    function confirm_delete(what, cb) {
        Swal.fire({ title: 'Delete this ' + what + '?', text: 'It is removed from your library and from any posts or collections that use it.', icon: 'warning', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Delete', confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' })
            .then(function (r) { if (r.isConfirmed) { cb(); } });
    }
    function delete_asset(influencer_id, asset_id, cb) {
        api('influencer_asset_delete', { id: influencer_id, asset_id: asset_id }, function (o) { if (o && o.success) { toastr.success(o.message || 'File removed'); cb(); } else { err(o); } });
    }
    /* shared: a thumbnail that no longer loads (deleted elsewhere) drops out of the strip */
    function drop_broken(sel) { $(sel).find('img').on('error', function () { $(this).closest('.inf-strip__item, .inf-tile, .inf-photo').remove(); }); }

    var STATE_LABEL = { draft: 'Draft', awaiting_reference: 'Awaiting approval', training: 'Training', ready: 'Trained', failed: 'Failed' };
    function state_pill(inf) {
        var retrain = inf.status === 'ready' && inf.pending_model_id > 0;
        var key = retrain ? 'training' : inf.status;
        var label = retrain ? 'Retraining' : (STATE_LABEL[key] || key);
        var busy = (key === 'training');
        return '<span class="inf-state inf-state--' + esc(key) + '">' + (busy ? '<span class="spinner-border" role="status"></span> ' : (key === 'ready' ? '<i class="fa-solid fa-check"></i> ' : '')) + esc(label) + '</span>';
    }
    function is_busy(inf) { return inf.status === 'training' || inf.status === 'awaiting_reference' || inf.pending_model_id > 0; }

    /* =====================================================================
     * Your Influencers (gallery)
     * =================================================================== */
    function init_index() {
        var $cards = $('#inf_cards'), poll_timer = null, first = true;

        function load(quiet) {
            if (!quiet) { $('#inf_loading').prop('hidden', false); $('#inf_error, #inf_empty, #inf_cards').prop('hidden', true); }
            api('influencer_list', {}, function (o) {
                $('#inf_loading').prop('hidden', true);
                if (!o || !o.success) { if (!quiet) { $('#inf_error').prop('hidden', false); } return; }
                if (quiet) { patch(o.influencers || []); } else { render(o.influencers || []); }
                schedule(o.influencers || []);
            });
        }
        /* What a card shows, minus the signed image URL (its signature changes on every request even when the image does not). */
        var seen = {};
        function sig(inf) {
            return JSON.stringify([inf.name, inf.status, inf.pending_model_id, inf.active_model_id, inf.locked, inf.last_error, String(inf.cover_url || '').split('?')[0]]);
        }
        function remember(list) { seen = {}; list.forEach(function (inf) { seen[inf.id] = { sig: sig(inf), inf: inf }; }); }
        /* A background check: touch only the cards whose state changed, and say when a retrain finished. */
        function patch(list) {
            var same_set = list.length === Object.keys(seen).length && list.every(function (inf) { return seen[inf.id]; });
            if (!same_set) { render(list); return; }
            list.forEach(function (inf) {
                var was = seen[inf.id];
                if (was.sig === sig(inf)) { return; }
                var $old = $cards.find('.inf-card[data-id="' + inf.id + '"]');
                if ($old.find('[aria-expanded="true"]').length) { return; }   // leave an open menu alone; the next check catches up
                $old.replaceWith(card(inf, 0).addClass('inf-card--static'));
                if (was.inf.pending_model_id > 0 && !(inf.pending_model_id > 0)) {
                    if (inf.last_error) { toastr.error(inf.name + ' could not be retrained and still uses the previous model.'); }
                    else { toastr.success(inf.name + ' is retrained and now uses the new model'); }
                } else if (was.inf.status === 'training' && inf.status === 'ready') { toastr.success(inf.name + ' is trained'); }
                seen[inf.id] = { sig: sig(inf), inf: inf };
            });
        }
        function schedule(list) {
            clearTimeout(poll_timer);
            var busy = list.some(is_busy);
            if (busy) { poll_timer = setTimeout(function () { if (!document.hidden) { load(true); } else { schedule(list); } }, 5000); }
        }
        var LIM = CFG.limit || {};
        /* At the plan's limit: offer a paid slot (Creator) or an upgrade instead of opening the wizard. */
        function limit_prompt() {
            var slot = !!LIM.addon;
            Swal.fire({
                title: slot ? 'Add an AI influencer slot?' : 'Upgrade for more AI influencers',
                text: LIM.message || 'Your plan includes no more AI influencers.',
                showCancelButton: true, showDenyButton: slot, reverseButtons: true,
                confirmButtonText: slot ? 'Add Slot ($' + LIM.addon_price + '/mo)' : 'See Plans',
                denyButtonText: 'Upgrade to Studio', cancelButtonText: 'Not Now',
                customClass: { confirmButton: 'btn btn-primary', denyButton: 'btn btn-secondary', cancelButton: 'btn btn-secondary' }, buttonsStyling: false
            }).then(function (r) {
                if (r.isDenied || (r.isConfirmed && !slot)) { window.location.href = '/account/billing'; return; }
                if (!r.isConfirmed) { return; }
                window.location.href = '/account/billing?add=slot';   // the charge is confirmed there, with its disclosure
            });
        }
        $(document).on('click', '#inf_new_btn, .inf-card--new, #inf_empty .btn', function (e) {
            if (LIM.can_create !== false) { return; }
            e.preventDefault();
            limit_prompt();
        });
        function render(list) {
            $('#inf_upgrade').prop('hidden', LIM.included !== false);   // no AI influencers on this plan: prompt above any locked ones
            if (LIM.included === false && !list.length) { $('#inf_empty, #inf_cards, #inf_new_btn').prop('hidden', true); return; }
            if (!list.length) { $('#inf_empty').prop('hidden', false); $('#inf_cards').prop('hidden', true); $('#inf_new_btn').prop('hidden', true); return; }
            $('#inf_empty').prop('hidden', true); $('#inf_new_btn').prop('hidden', LIM.included === false);
            $cards.empty().prop('hidden', false);
            list.forEach(function (inf, i) { $cards.append(card(inf, first ? i : 0)); });
            if (LIM.included !== false) { $cards.append('<a class="inf-card inf-card--new" href="/influencers/create" style="animation-delay:' + Math.min(list.length * 18, 360) + 'ms"><i class="fa-solid fa-plus"></i><span>New Influencer</span></a>'); }
            first = false;
            remember(list);
        }
        function target_for(inf) {
            if (inf.status === 'ready' && inf.active_model_id > 0) { return '/influencers/images/' + inf.id; }
            return '/influencers/create/' + inf.id;
        }
        function card(inf, i) {
            var ready = inf.status === 'ready' && inf.active_model_id > 0 && !inf.locked;
            var $c = $('<div class="inf-card' + (inf.locked ? ' inf-card--locked' : '') + '" tabindex="0">').attr('data-id', inf.id).css('animation-delay', Math.min(i * 18, 360) + 'ms');
            var media = inf.cover_url ? '<img src="' + esc(inf.cover_url) + '" alt="' + esc(inf.name) + '" loading="lazy">' : '<i class="fa-regular fa-user"></i>';
            $c.append('<div class="inf-card__media">' + media + '</div>');
            $c.append('<div class="inf-card__body"><span class="inf-card__name">' + esc(inf.name) + '</span>' + (inf.locked ? '<span class="inf-state inf-state--locked"><i class="fa-solid fa-lock"></i> Locked</span>' : state_pill(inf)) + '</div>');
            var menu = '<div class="dropdown">' +
                '<button type="button" class="inf-card__menu" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="More"><i class="fa-solid fa-ellipsis"></i></button>' +
                '<ul class="dropdown-menu dropdown-menu-end">' +
                (ready ? '<li><a class="dropdown-item" href="/influencers/images/' + inf.id + '"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate images</a></li>' +
                         '<li><a class="dropdown-item" href="/influencers/videos/' + inf.id + '"><i class="fa-solid fa-clapperboard"></i> Generate Video</a></li>' +
                         '<li><a class="dropdown-item" href="/influencers/gallery/' + inf.id + '"><i class="fa-solid fa-images"></i> Gallery</a></li>' +
                         '<li><a class="dropdown-item" href="/influencers/create/' + inf.id + '"><i class="fa-solid fa-sliders"></i> Settings</a></li>' +
                         (inf.pending_model_id > 0 ? '' : '<li><a class="dropdown-item" href="/influencers/create/' + inf.id + '/retrain" data-act="retrain"><i class="fa-solid fa-rotate"></i> Retrain</a></li>')
                       : (inf.locked ? '' : '<li><a class="dropdown-item" href="/influencers/create/' + inf.id + '"><i class="fa-solid fa-arrow-right"></i> Continue setup</a></li>')) +
                '<li><hr class="dropdown-divider"></li>' +
                '<li><button type="button" class="dropdown-item text-danger" data-act="delete"><i class="fa-solid fa-trash"></i> Delete</button></li>' +
                '</ul></div>';
            $c.append(menu);
            function open_card() {
                if (!inf.locked) { window.location = target_for(inf); return; }
                Swal.fire({ titleText: inf.name + ' is locked', text: 'Your plan includes fewer AI influencers than you have, so the newest are locked. Nothing is deleted. Upgrade or add a slot to use them again.',
                    showCancelButton: true, reverseButtons: true, confirmButtonText: 'See Plans', cancelButtonText: 'Not Now',
                    customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }, buttonsStyling: false })
                    .then(function (r) { if (r.isConfirmed) { window.location.href = '/account/billing'; } });
            }
            $c.on('click', function (e) {
                if ($(e.target).closest('.dropdown').length) { return; }
                open_card();
            });
            $c.on('keydown', function (e) { if (e.key === 'Enter' && !$(e.target).closest('.dropdown').length) { open_card(); } });
            $c.find('[data-act="delete"]').on('click', function () {
                Swal.fire({ titleText: 'Delete ' + inf.name + '?', text: 'The trained model is removed. Images already in your library stay there.', icon: 'warning', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Delete', confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' })
                    .then(function (r) {
                        if (!r.isConfirmed) { return; }
                        api('influencer_delete', { id: inf.id }, function (o) { if (o && o.success) { toastr.success(o.message); load(true); } else { err(o); } });
                    });
            });
            return $c;
        }
        $('#inf_retry').on('click', function () { load(false); });
        load(false);
    }

    /* =====================================================================
     * Create wizard
     * =================================================================== */
    function init_create() {
        var inf   = CFG.influencer || null;
        var path  = inf ? inf.path : '';
        var STEP_LABELS = { name: 'Setup', photos: 'Photos', input: 'Setup', reference: 'Reference', set: 'Training Set', review: 'Review', training: 'Training', done: 'Done' };
        var poll_timer = null;

        function steps_for(p) { return (C.steps && C.steps[p]) ? C.steps[p] : ['name']; }
        function visible_steps(p) { return steps_for(p).filter(function (s) { return s !== 'training' && s !== 'done'; }); }
        function step_index(p, s) { var v = visible_steps(p); var i = v.indexOf(s); return i < 0 ? v.length : i; }

        function render_steps(p, current) {
            var $ol = $('#inf_steps').empty();
            var cur = step_index(p, current);
            visible_steps(p).forEach(function (s, i) {
                var cls = i < cur ? 'is-done' : (i === cur ? 'is-on' : '');
                $ol.append('<li class="' + cls + '"><span class="inf-steps__n">' + (i < cur ? '<i class="fa-solid fa-check"></i>' : (i + 1)) + '</span>' + esc(STEP_LABELS[s] || s) + '</li>');
            });
        }

        function set_actions(html) { $('#inf_wiz_actions').html(html || ''); }

        /* --- Setup: name, gender (picks the name ideas and every prompt suggestion), what she is built from, gallery --- */
        var SOURCES = [
            { value: 'photos',     path: 'photos',    icon: 'fa-images',         label: 'Your Photos',       note: LIM.min_photos + ' to ' + LIM.max_photos + ' photos, highest likeness' },
            { value: 'text',       path: 'reference', icon: 'fa-pen-nib',        label: 'Describe The Face', note: 'A written description, fastest start' },
            { value: 'face_photo', path: 'reference', icon: 'fa-image-portrait', label: 'One Face Photo',    note: 'A single front-facing photo' }
        ];
        function source_of(v) { return SOURCES.filter(function (s) { return s.value === v; })[0] || SOURCES[0]; }
        function can_switch() { return !inf || (['draft', 'awaiting_reference', 'failed'].indexOf(inf.status) >= 0 && inf.pending_model_id <= 0); }
        function render_name() {
            var gender = inf ? inf.gender : '';
            var source = !inf ? 'photos' : (inf.path === 'photos' ? 'photos' : (inf.input_method || 'text'));
            var locked = !can_switch();
            var html = '<div class="inf-setup">' +
                '<div class="inf-setup__col">' +
                '<div class="inf-field"><label class="inf-label" for="inf_name">Name</label>' +
                '<input type="text" class="form-control" id="inf_name" maxlength="120" value="' + esc(inf ? inf.name : '') + '">' +
                '<div class="inf-chips" id="inf_name_chips" hidden></div></div>' +
                '<div class="inf-field"><div class="inf-label">Gender</div>' + seg_html('inf_gender', [{ value: 'woman', label: 'Woman' }, { value: 'man', label: 'Man' }], gender) + '</div>' +
                '<div class="inf-field"><div class="inf-label">Public Gallery</div>' + seg_html('inf_public_seg', [{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }], String(inf ? (inf.is_public || 0) : 0)) + '</div>' +
                '</div>' +
                '<div class="inf-setup__col">' +
                '<div class="inf-field"><div class="inf-label">Build From</div><div class="inf-srcs" id="inf_source" role="radiogroup">' +
                SOURCES.map(function (s) {
                    var on = s.value === source;
                    return '<button type="button" role="radio" class="inf-src' + (on ? ' is-on' : '') + '" data-value="' + s.value + '" aria-checked="' + on + '"' + (locked && !on ? ' disabled' : '') + '>' +
                        '<span class="inf-src__ic"><i class="fa-solid ' + s.icon + '"></i></span>' +
                        '<span class="inf-src__body"><span class="inf-src__t">' + esc(s.label) + '</span><span class="inf-src__n">' + esc(s.note) + '</span></span>' +
                        '<span class="inf-src__mark"><i class="fa-solid fa-check"></i></span></button>';
                }).join('') + '</div></div>' +
                '</div></div>' +
                '<div class="inf-wiz__foot inf-wiz__foot--end">' +
                '<button type="button" class="btn btn-primary" id="inf_name_next">Continue <i class="fa-solid fa-arrow-right"></i></button></div>';
            $('#inf_panel').html(html);
            function show_names(g) {
                var names = ((CFG.names || {})[g]) || [];
                $('#inf_name').attr('placeholder', names[0] || '');
                $('#inf_name_chips').html(names.map(function (n) { return '<button type="button" class="inf-chip" data-name="' + esc(n) + '">' + esc(n) + '</button>'; }).join('')).prop('hidden', !names.length);
            }
            seg_bind('inf_gender', function (g) { gender = g; show_names(g); });
            seg_bind('inf_public_seg');
            if (gender) { show_names(gender); }
            $('#inf_name_chips').on('click', '.inf-chip', function () { $('#inf_name').val($(this).data('name')).trigger('focus'); $('#inf_name_chips .inf-chip').removeClass('is-on'); $(this).addClass('is-on'); });
            $('#inf_source').on('click', '.inf-src:not(:disabled)', function () {
                source = $(this).data('value');
                $('#inf_source .inf-src').removeClass('is-on').attr('aria-checked', 'false'); $(this).addClass('is-on').attr('aria-checked', 'true');
                render_steps(source_of(source).path, 'name');   // the steps ahead depend on the source
            });
            $('#inf_name').on('keydown', function (e) { if (e.key === 'Enter') { $('#inf_name_next').trigger('click'); } });
            $('#inf_name_next').on('click', function () {
                var name = $('#inf_name').val().trim(), src = source_of(source);
                if (name == '') { toastr.error('Give your influencer a name'); $('#inf_name').trigger('focus'); return; }
                if (!gender) { toastr.error('Choose Woman or Man'); return; }
                var $b = $(this).prop('disabled', true);
                var body = { name: name, gender: gender, is_public: seg_value('inf_public_seg') };
                if (src.path === 'reference') { body.input_method = src.value; }
                if (!inf) {
                    body.path = src.path;
                    api('influencer_create', body, function (o) {
                        if (o && o.success) { window.location = '/influencers/create/' + o.influencer.id; } else { err(o); $b.prop('disabled', false); }
                    });
                    return;
                }
                body.id = inf.id; body.step = steps_for(src.path)[1];
                if (src.path !== inf.path) { body.path = src.path; }
                api('influencer_save_step', body, function (o) {
                    $b.prop('disabled', false);
                    if (o && o.success) { inf = o.influencer; path = inf.path; $('#inf_wiz_title').text(inf.name); show(inf.wizard_step); } else { err(o); }
                });
            });
        }

        /* --- Training in progress --- */
        function render_training() {
            var retrain = inf.active_model_id > 0;
            var html = '<div class="inf-progress">' +
                '<div class="inf-progress__ic"><span class="spinner-border" role="status"></span></div>' +
                '<h2 class="inf-progress__t">' + (retrain ? 'Retraining ' : 'Training ') + esc(inf.name) + '</h2>' +
                '<p class="inf-progress__x">' + (retrain
                    ? 'This usually takes a few minutes. Until it finishes, ' + esc(inf.name) + ' keeps generating with the current model, then switches to the new one on her own. You can leave this page.'
                    : 'This usually takes a few minutes. You can leave this page; the card shows the progress and the influencer is ready to use as soon as it finishes.') + '</p>' +
                '<div class="inf-progress__actions"><a href="/influencers" class="btn btn-secondary">Back to influencers</a></div>' +
                '</div>';
            $('#inf_panel').html(html);
            watch();
        }
        function watch() {
            clearTimeout(poll_timer);
            poll_timer = setTimeout(function () {
                api('influencer_get', { id: inf.id }, function (o) {
                    if (!o || !o.success) { watch(); return; }
                    var was_retrain = inf.active_model_id > 0;
                    inf = o.influencer;
                    if (inf.wizard_step === 'training' || inf.pending_model_id > 0) { watch(); return; }
                    if (was_retrain) {
                        if (inf.last_error) { toastr.error('Retraining failed: ' + inf.last_error + ' ' + inf.name + ' still uses the previous model.'); }
                        else { toastr.success(inf.name + ' is retrained and now uses the new model'); }
                    }
                    show(inf.wizard_step);
                });
            }, 5000);
        }

        /* --- Done / failed --- */
        function render_done() {
            var failed = inf.status === 'failed' || (inf.last_error && inf.active_model_id <= 0);
            var html;
            if (failed) {
                html = '<div class="inf-progress">' +
                    '<div class="inf-progress__ic inf-progress__ic--bad"><i class="fa-solid fa-triangle-exclamation"></i></div>' +
                    '<h2 class="inf-progress__t">Training failed</h2>' +
                    '<p class="inf-progress__x">Nothing was lost. Fix the issue below or try again.</p>' +
                    (inf.last_error ? '<div class="inf-progress__err">' + esc(inf.last_error) + '</div>' : '') +
                    '<div class="inf-progress__actions"><a href="/influencers" class="btn btn-secondary">Back</a><button type="button" class="btn btn-primary" id="inf_retry_train"><i class="fa-solid fa-rotate"></i> Try Again</button></div>' +
                    '</div>';
            } else {
                html = '';
            }
            $('#inf_panel').html(html);
            $('#inf_retry_train').on('click', function () { show(path === 'photos' ? 'photos' : 'review'); });
            $('[data-copy]').on('click', function () { var t = $(this).data('copy'); if (navigator.clipboard) { navigator.clipboard.writeText(t); toastr.success('Copied'); } });
            if (!failed) { render_training_photos(); render_settings(); }
        }

        /* --- Trained: the photos her model learned from, and retraining from them --- */
        function render_training_photos() {
            var busy = inf.pending_model_id > 0, photos_path = path === 'photos';
            var html = '<section class="inf-sec">' +
                '<div class="inf-sec__head"><h2 class="inf-sec__h">Training Photos</h2>' + (busy ? state_pill(inf) : '') + '</div>' +
                (!busy && inf.last_error ? '<div class="inf-progress__err inf-sec__err">The last retrain failed: ' + esc(inf.last_error) + ' ' + esc(inf.name) + ' still uses the previous model.</div>' : '');
            if (busy) {
                html += '<p class="inf-wiz__meta">' + esc(inf.name) + ' keeps generating with the current model until the new one is ready.</p>' +
                        '<div class="inf-photos inf-photos--sm" id="inf_photos"></div></section>';
                $('#inf_panel').append(html);
                photos_load(function () { photos.forEach(function (img) { $('#inf_photos').append('<div class="inf-photo"><img src="' + esc(img.thumb_url) + '" alt="" loading="lazy"></div>'); }); });
                return;
            }
            if (!photos_path) {   // built from a reference: its training set is managed on the review step
                html += '<div class="inf-photos inf-photos--sm" id="inf_set_view"></div>' +
                        '<div class="inf-wiz__foot inf-wiz__foot--end"><button type="button" class="btn btn-secondary" id="inf_ref_retrain"><i class="fa-solid fa-rotate"></i> Retrain</button></div></section>';
                $('#inf_panel').append(html);
                api('influencer_images', { id: inf.id, role: 'training' }, function (o) {
                    ((o && o.success) ? (o.images || []) : []).forEach(function (img) { $('#inf_set_view').append('<div class="inf-photo"><img src="' + esc(img.thumb_url) + '" alt="" loading="lazy"></div>'); });
                });
                $('#inf_ref_retrain').on('click', function () { show('review'); });
                return;
            }
            html += photo_manager_html() +
                '<div class="inf-wiz__foot"><span class="inf-wiz__meta" id="inf_photos_cost"></span>' +
                '<button type="button" class="btn btn-secondary" id="inf_photos_train" disabled><i class="fa-solid fa-rotate"></i> Retrain</button></div></section>';
            $('#inf_panel').append(html);
            photo_manager_bind();
        }

        /* --- Trained: her defaults, share targets and automations (kept separate per influencer) --- */
        // Who she is, for every AI writer (captions, DMs, automations): one field per C.persona entry.
        function persona_fields() {
            var her = (inf.gender === 'man') ? 'Him' : 'Her', html = '';
            $.each(C.persona || {}, function (col, def) {
                var id = 'inf_' + col, label = String(def.label).replace('{Her}', her), ph = String(def.placeholder || '');
                if (inf.gender === 'man') { ph = ph.replace(/\bher\b/g, 'his'); }
                html += '<div class="inf-field"><label class="inf-label" for="' + id + '">' + esc(label) + '</label>' +
                    (def.rows > 1
                        ? '<textarea class="form-control" id="' + id + '" data-persona="' + col + '" rows="' + def.rows + '" maxlength="' + def.max + '" placeholder="' + esc(ph) + '">' + esc(inf[col] || '') + '</textarea>'
                        : '<input type="text" class="form-control" id="' + id + '" data-persona="' + col + '" maxlength="' + def.max + '" placeholder="' + esc(ph) + '" value="' + esc(inf[col] || '') + '">') +
                    '</div>';
            });
            return html;
        }
        function render_settings() {
            var she = (inf.gender === 'man') ? 'he' : 'she', her = (inf.gender === 'man') ? 'him' : 'her';
            var html = '<div class="inf-about">' +
                '<section class="inf-sec inf-about__col"><div class="inf-sec__head"><div><h2 class="inf-sec__h">Image Defaults</h2><p class="inf-sec__sub">Applied to every image generated with ' + her + '.</p></div></div>' +
                '<div class="inf-about__fields">' +
                '<div class="inf-field"><div class="inf-label">Gender</div>' + seg_html('inf_set_gender', [{ value: 'woman', label: 'Woman' }, { value: 'man', label: 'Man' }], inf.gender) + '</div>' +
                '<div class="inf-field"><label class="inf-label" for="inf_set_defaults">Always Add To Prompts</label><textarea class="form-control" id="inf_set_defaults" rows="3" maxlength="2000" placeholder="film grain, natural light">' + esc(inf.prompt_defaults) + '</textarea></div>' +
                '<div class="inf-field"><label class="inf-label" for="inf_set_negative">Never Include</label><textarea class="form-control" id="inf_set_negative" rows="3" maxlength="2000" placeholder="blurry, extra fingers">' + esc(inf.negative_prompt) + '</textarea></div>' +
                '</div></section>' +
                '<section class="inf-sec inf-about__col"><div class="inf-sec__head"><div><h2 class="inf-sec__h">Persona</h2><p class="inf-sec__sub">Who ' + she + ' is when AI writes captions, replies and campaign posts for ' + her + '.</p></div></div>' +
                '<div class="inf-about__fields inf-persona">' + persona_fields() + '</div>' +
                '</section></div>' +
                '<div class="inf-wiz__foot inf-wiz__foot--end"><button type="button" class="btn btn-primary" id="inf_set_save">Save Changes</button></div>';
            $('#inf_panel').append(html);
            seg_bind('inf_set_gender');
            $('#inf_set_save').on('click', function () {
                var body = { id: inf.id, gender: seg_value('inf_set_gender') || inf.gender, prompt_defaults: $('#inf_set_defaults').val(), negative_prompt: $('#inf_set_negative').val() };
                $('#inf_panel [data-persona]').each(function () { body[$(this).data('persona')] = $(this).val(); });
                api('influencer_save_step', body, function (o) {
                    if (o && o.success) { inf = o.influencer; toastr.success('Saved'); } else { err(o); }
                });
            });
        }

        /* --- placeholder for steps built in later stages --- */
        function render_pending(step) {
            $('#inf_panel').html('<h2 class="inf-wiz__h">' + esc(STEP_LABELS[step] || step) + '</h2><p class="inf-wiz__p">This step is not available yet.</p>' +
                '<div class="inf-wiz__foot"><button type="button" class="btn btn-secondary" id="inf_back">Back</button></div>');
            $('#inf_back').on('click', function () { go_back(step); });
        }
        function go_back(step) {
            var v = visible_steps(path), i = v.indexOf(step);
            var prev = i > 0 ? v[i - 1] : 'name';
            api('influencer_save_step', { id: inf.id, step: prev }, function (o) { if (o && o.success) { inf = o.influencer; show(prev); } else { err(o); } });
        }

        /* --- Path A: Photos (10 to 50 uploads) --- */
        var photos = [];              // images_json rows for role upload
        var upload_queue = [], upload_active = 0, UPLOAD_PAR = 3;

        function photos_load(cb) {
            api('influencer_images', { id: inf.id, role: 'upload' }, function (o) { photos = (o && o.success) ? (o.images || []) : []; if (cb) { cb(); } });
        }
        function photo_tile(img) {
            var $t = $('<div class="inf-photo">').attr('data-id', img.id);
            $t.append('<img src="' + esc(img.thumb_url) + '" alt="" loading="lazy">');
            $t.append('<button type="button" class="inf-photo__rm" title="Remove"><i class="fa-solid fa-xmark"></i></button>');
            $t.find('.inf-photo__rm').on('click', function () {
                api('influencer_image_remove', { id: inf.id, asset_id: img.id }, function (o) {
                    if (!o || !o.success) { err(o); return; }
                    photos = photos.filter(function (p) { return p.id !== img.id; });
                    $t.remove(); photos_counter();
                });
            });
            return $t;
        }
        function photos_counter() {
            var n = photos.length, min = LIM.min_photos, max = LIM.max_photos;
            $('#inf_count').text(n);
            $('#inf_count_bar').css('width', Math.min(100, n / min * 100) + '%').toggleClass('is-met', n >= min);
            $('#inf_count_txt').text(n >= min ? (n + ' of ' + max + ' max') : (n + ' of ' + min + ' minimum'));
            $('#inf_photos_train').prop('disabled', n < min || upload_active > 0 || !C.enabled);
            $('#inf_photos_cost').text(n >= min ? 'Training is included in your plan' : '');
            $('#inf_drop').toggleClass('is-full', n >= max);
        }
        function upload_start(file) {
            if (photos.length + upload_queue.length + upload_active >= LIM.max_photos) { toastr.info('Up to ' + LIM.max_photos + ' photos'); return; }
            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { toastr.error(esc(file.name) + ': use JPG, PNG or WebP'); return; }
            var $t = $('<div class="inf-photo inf-photo--up">').append('<div class="inf-photo__bar"><div class="inf-photo__fill"></div></div><span class="inf-photo__st">Waiting</span>');
            $('#inf_photos').append($t);
            upload_queue.push({ file: file, $t: $t });
            upload_pump();
        }
        function upload_pump() {
            while (upload_active < UPLOAD_PAR && upload_queue.length) {
                var job = upload_queue.shift();
                upload_active++;
                upload_one(job.file, job.$t);
            }
        }
        function upload_one(file, $t) {
            var fd = new FormData(); fd.append('file', file); fd.append('id', inf.id); fd.append('role', 'upload');
            $t.find('.inf-photo__st').text('Uploading');
            $.ajax({ url: '/api/influencer_upload', method: 'POST', data: fd, dataType: 'json', processData: false, contentType: false,
                xhr: function () { var x = $.ajaxSettings.xhr(); if (x.upload) { x.upload.addEventListener('progress', function (e) { if (e.lengthComputable) { $t.find('.inf-photo__fill').css('width', (e.loaded / e.total * 90) + '%'); } }); } return x; } })
                .done(function (o) {
                    if (o && o.success && o.image) { photos.push(o.image); $t.replaceWith(photo_tile(o.image)); }
                    else { $t.addClass('is-failed').find('.inf-photo__st').text((o && o.message) || 'Failed'); $t.on('click', function () { $t.remove(); }); }
                })
                .fail(function () { $t.addClass('is-failed').find('.inf-photo__st').text('Upload failed'); $t.on('click', function () { $t.remove(); }); })
                .always(function () { upload_active--; photos_counter(); upload_pump(); });
        }
        /* Drop zone + counter + grid, shared by the wizard's photos step and the trained view. */
        function photo_manager_html() {
            var gallery = inf.active_model_id > 0;   // a trained influencer has a gallery to pick from
            return '<div class="inf-drop" id="inf_drop"><i class="fa-solid fa-cloud-arrow-up"></i><span><strong>Drop photos here</strong> or <button type="button" class="inf-link" id="inf_pick">choose files</button>' +
                (gallery ? ' or <button type="button" class="inf-link" id="inf_gal_open">choose from the gallery</button>' : '') + '</span><small>JPG, PNG or WebP, up to 15 MB each</small></div>' +
                '<input type="file" id="inf_files" accept="image/jpeg,image/png,image/webp" multiple hidden>' +
                '<div class="inf-counter"><div class="inf-counter__row"><span class="inf-counter__n" id="inf_count">0</span><span class="inf-counter__t" id="inf_count_txt"></span></div><div class="inf-counter__bar"><div class="inf-counter__fill" id="inf_count_bar"></div></div></div>' +
                '<div class="inf-photos" id="inf_photos"></div>';
        }
        function photo_manager_bind() {
            photos_load(function () { photos.forEach(function (img) { $('#inf_photos').append(photo_tile(img)); }); photos_counter(); });
            $('#inf_pick').on('click', function () { $('#inf_files').trigger('click'); });
            $('#inf_files').on('change', function () { Array.prototype.slice.call(this.files).forEach(upload_start); this.value = ''; });
            $('#inf_gal_open').on('click', gallery_pick);
            var $drop = $('#inf_drop');
            $drop.on('dragover', function (e) { e.preventDefault(); $drop.addClass('is-dragover'); })
                 .on('dragleave drop', function () { $drop.removeClass('is-dragover'); })
                 .on('drop', function (e) { e.preventDefault(); var f = e.originalEvent.dataTransfer.files; Array.prototype.slice.call(f).forEach(upload_start); });
            $('#inf_photos_train').on('click', function () {
                var $b = $(this).prop('disabled', true);
                api('influencer_train', { id: inf.id }, function (o) {
                    if (o && o.success) { inf = o.influencer; toastr.success('Training started'); show('training'); }
                    else { err(o); $b.prop('disabled', false); }
                });
            });
        }
        /* Choose From Gallery: her generated images, copied into her training photos one at a time. */
        function gallery_pick() {
            var $m = $('#inf_gal_modal');
            if (!$m.length) {
                $m = $('<div class="modal fade" id="inf_gal_modal" tabindex="-1" aria-labelledby="inf_gal_title" aria-hidden="true">' +
                    '<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">' +
                    '<div class="modal-header"><h5 class="modal-title" id="inf_gal_title">Choose From Gallery</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
                    '<div class="modal-body"><div class="inf-photos inf-stills inf-gal-grid" id="inf_gal_grid"></div></div>' +
                    '<div class="modal-footer"><span class="inf-wiz__meta" id="inf_gal_n"></span><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="inf_gal_add" disabled>Add Photos</button></div>' +
                    '</div></div></div>').appendTo('body');
                $m.on('click', '.inf-photo--pick', function () {
                    var room = LIM.max_photos - photos.length - upload_queue.length - upload_active;
                    if (!$(this).hasClass('is-on') && $m.find('.inf-photo--pick.is-on').length >= room) { toastr.info('Up to ' + LIM.max_photos + ' photos'); return; }
                    $(this).toggleClass('is-on').attr('aria-pressed', $(this).hasClass('is-on'));
                    var n = $m.find('.inf-photo--pick.is-on').length;
                    $('#inf_gal_n').text(n ? n + ' selected' : '');
                    $('#inf_gal_add').prop('disabled', n === 0).text(n > 1 ? 'Add ' + n + ' Photos' : 'Add Photo');
                });
                $m.on('click', '#inf_gal_add', function () {
                    var ids = $m.find('.inf-photo--pick.is-on').map(function () { return $(this).data('id'); }).get();
                    bootstrap.Modal.getOrCreateInstance($m[0]).hide();
                    ids.forEach(gallery_add);
                });
            }
            $('#inf_gal_n').text(''); $('#inf_gal_add').prop('disabled', true).text('Add Photos');
            $('#inf_gal_grid').html('<span class="inf-wiz__meta">Loading…</span>');
            bootstrap.Modal.getOrCreateInstance($m[0]).show();
            api('influencer_images', { id: inf.id, role: '' }, function (o) {
                var imgs = ((o && o.success) ? (o.images || []) : []).filter(function (x) { return x.type === 'image' && x.status === 'ready' && ['generated', 'enhanced', 'reference'].indexOf(x.role) >= 0; });
                if (!imgs.length) { $('#inf_gal_grid').html('<span class="inf-wiz__meta">No images in ' + esc(inf.name) + '\'s gallery yet.</span>'); return; }
                $('#inf_gal_grid').html(imgs.map(function (x) {
                    return '<button type="button" class="inf-photo inf-photo--pick" data-id="' + x.id + '" aria-pressed="false"><img src="' + esc(x.display_url || x.thumb_url) + '" alt="" loading="lazy"></button>';
                }).join(''));
            });
        }
        function gallery_add(asset_id) {
            var $t = $('<div class="inf-photo inf-photo--up">').append('<div class="inf-photo__bar"><div class="inf-photo__fill" style="width:50%"></div></div><span class="inf-photo__st">Adding</span>');
            $('#inf_photos').append($t);
            upload_active++; photos_counter();
            api('influencer_photo_from_gallery', { id: inf.id, asset_id: asset_id }, function (o) {
                if (o && o.success && o.image) { photos.push(o.image); $t.replaceWith(photo_tile(o.image)); }
                else { $t.addClass('is-failed').find('.inf-photo__st').text((o && o.message) || 'Failed'); $t.on('click', function () { $t.remove(); }); }
                upload_active--; photos_counter();
            });
        }
        function render_photos() {
            var html = '<h2 class="inf-wiz__h">Add Photos</h2>' +
                '<p class="inf-wiz__p">Different angles, expressions and lighting, sharp and well lit, only this person in frame.</p>' +
                photo_manager_html() +
                '<div class="inf-wiz__foot"><button type="button" class="btn btn-secondary" id="inf_photos_back">Back</button>' +
                '<span class="inf-wiz__meta" id="inf_photos_cost"></span>' +
                '<button type="button" class="btn btn-primary" id="inf_photos_train" disabled><i class="fa-solid fa-bolt"></i> Train</button></div>';
            $('#inf_panel').html(html);
            photo_manager_bind();
            $('#inf_photos_back').on('click', function () { go_back('photos'); });
        }
        /* --- shared: option cards for a picker purpose --- */
        function opts_html(id, options, selected) {
            return '<div class="inf-opts" id="' + id + '">' + options.map(function (o, i) {
                var on = selected ? (o.key === selected) : (i === 0);
                return '<button type="button" class="inf-opt' + (on ? ' is-on' : '') + '" data-key="' + esc(o.key) + '"><span class="inf-opt__t">' + esc(o.label) + '</span><span class="inf-opt__p">' + esc(o.purpose) + '</span></button>';
            }).join('') + '</div>';
        }
        function opts_bind(id) { $('#' + id).on('click', '.inf-opt', function () { $('#' + id + ' .inf-opt').removeClass('is-on'); $(this).addClass('is-on'); }); }
        function opts_value(id) { return $('#' + id + ' .inf-opt.is-on').data('key') || ''; }
        function seg_html(id, items, value) {
            return '<div class="inf-seg" id="' + id + '" role="group">' + items.map(function (it) {
                return '<button type="button" class="inf-seg__opt' + (it.value === value ? ' is-on' : '') + '" data-value="' + esc(it.value) + '" aria-pressed="' + (it.value === value) + '">' + (it.icon ? '<i class="fa-solid ' + it.icon + '"></i>' : '') + '<span>' + esc(it.label) + '</span></button>';
            }).join('') + '</div>';
        }
        function seg_bind(id, on_change) { $('#' + id).on('click', '.inf-seg__opt', function () { $('#' + id + ' .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true'); if (on_change) { on_change($(this).data('value')); } }); }
        function seg_value(id) { return $('#' + id + ' .inf-seg__opt.is-on').data('value'); }
        /* --- Path B: Reference (text -> generate, or face photo upload) --- */
        var ref_stop = null;
        function render_reference() {
            if (ref_stop) { ref_stop(); ref_stop = null; }
            if (inf.input_method === 'face_photo') { return render_reference_photo(); }
            var faces = ((C.prompts || {})[inf.gender] || {}).face || [];
            var html = '<div class="inf-wiz__split">' +
                '<div class="inf-wiz__form">' +
                '<div class="inf-field"><div class="inf-label">Render With</div>' + opts_html('inf_ref_model', C.pickers.reference || [], inf.reference_model_key) + '</div>' +
                '<div class="inf-field"><label class="inf-label" for="inf_ref_desc">Face Description</label><textarea class="form-control inf-wiz__desc" id="inf_ref_desc" maxlength="2000" placeholder="' + esc(faces[0] || '') + '">' + esc(inf.source_description) + '</textarea></div>' +
                (faces.length ? '<div class="inf-field"><div class="inf-label">Prebuilt</div><div class="inf-chips">' + faces.map(function (t, i) { return '<button type="button" class="inf-chip inf-chip--text" data-i="' + i + '" title="' + esc(t) + '">' + esc(t) + '</button>'; }).join('') + '</div></div>' : '') +
                '</div>' +
                '<div class="inf-wiz__view">' +
                '<div class="inf-gen__stage inf-wiz__stage" id="inf_ref_stage"><div class="inf-gen__idle"><i class="fa-regular fa-image"></i><p>The reference appears here.</p></div></div>' +
                '<div class="inf-ref__row" id="inf_ref_row" hidden></div>' +
                '</div></div>' +
                '<div class="inf-wiz__foot"><button type="button" class="btn btn-secondary" id="inf_ref_back">Back</button>' +
                '<span class="inf-wiz__meta" id="inf_ref_status"></span>' +
                '<button type="button" class="btn btn-secondary" id="inf_ref_gen" hidden><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Another</button>' +
                '<button type="button" class="btn btn-primary" id="inf_ref_gen_primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Reference</button>' +
                '<button type="button" class="btn btn-primary" id="inf_ref_use" hidden><i class="fa-solid fa-check"></i> Use This Reference</button></div>';
            $('#inf_panel').html(html);
            opts_bind('inf_ref_model');
            $('#inf_panel').off('click.infpre').on('click.infpre', '.inf-chip--text', function () { $('#inf_ref_desc').val(faces[$(this).data('i')]).trigger('focus'); });
            $('#inf_ref_back').on('click', function () { go_back('reference'); });
            var selected = inf.reference_asset_id || 0;
            var IDLE = $('#inf_ref_stage').html();
            function stage(img) { $('#inf_ref_stage').html(img ? '<figure class="inf-gen__main"><img src="' + esc(img.display_url || img.thumb_url) + '" alt=""></figure>' : IDLE); }
            function busy(text) { $('#inf_ref_stage').html('<div class="inf-gen__busy"><span class="spinner-border" role="status"></span><p>' + esc(text) + '</p></div>'); }
            function paint(images) {
                var $row = $('#inf_ref_row').empty().prop('hidden', images.length < 2);
                if (!images.length) { stage(null); return; }
                if (!selected || !images.some(function (im) { return im.id === selected; })) { selected = images[images.length - 1].id; }
                images.forEach(function (img) {
                    var $t = $('<button type="button" class="inf-photo inf-photo--pick' + (img.id === selected ? ' is-on' : '') + '">').attr('data-id', img.id).append('<img src="' + esc(img.thumb_url) + '" alt="">');
                    $t.on('click', function () { selected = img.id; $('#inf_ref_row .inf-photo').removeClass('is-on'); $t.addClass('is-on'); stage(img); });
                    $row.append($t);
                    if (img.id === selected) { stage(img); }
                });
                $('#inf_ref_use').prop('hidden', false); $('#inf_ref_gen').prop('hidden', false); $('#inf_ref_gen_primary').prop('hidden', true);
            }
            api('influencer_images', { id: inf.id, role: 'reference' }, function (o) { if (o && o.success && o.images.length) { paint(o.images); } });
            function generate() {
                var desc = $('#inf_ref_desc').val().trim();
                if (desc == '') { toastr.error('Describe the face first'); return; }
                if (!C.enabled) { toastr.info('Rendering is not configured yet'); return; }
                $('#inf_ref_gen, #inf_ref_gen_primary, #inf_ref_use').prop('disabled', true);
                busy('Generating');
                api('influencer_reference_generate', { id: inf.id, source_description: desc, reference_model_key: opts_value('inf_ref_model') }, function (o) {
                    if (!o || !o.success) { err(o); $('#inf_ref_gen, #inf_ref_gen_primary, #inf_ref_use').prop('disabled', false); api('influencer_images', { id: inf.id, role: 'reference' }, function (o2) { paint((o2 && o2.success) ? o2.images : []); }); return; }
                    ref_stop = poll_job(o.job_id, function (j) { busy(status_text(j)); }, function (j) {
                        $('#inf_ref_gen, #inf_ref_gen_primary, #inf_ref_use').prop('disabled', false); $('#inf_ref_status').text('');
                        if (j.status === 'done') { selected = 0; } else { toastr.error(j.error || 'Generation failed'); }
                        api('influencer_images', { id: inf.id, role: 'reference' }, function (o2) { paint((o2 && o2.success) ? o2.images : []); });
                    });
                });
            }
            $('#inf_ref_gen, #inf_ref_gen_primary').on('click', generate);
            $('#inf_ref_use').on('click', function () {
                if (!selected) { return; }
                api('influencer_reference_pick', { id: inf.id, asset_id: selected }, function (o) { if (o && o.success) { inf = o.influencer; show('set'); } else { err(o); } });
            });
        }
        function render_reference_photo() {
            var html = '<h2 class="inf-wiz__h">Face Photo</h2>' +
                '<p class="inf-wiz__p">One clear, front-facing photo of the face. It becomes the reference for the training set.</p>' +
                '<div class="inf-ref" id="inf_face_out" ' + (inf.face_asset_id ? '' : 'hidden') + '><div class="inf-ref__row" id="inf_face_row"></div></div>' +
                '<div class="inf-drop" id="inf_face_drop"><i class="fa-solid fa-cloud-arrow-up"></i><span><strong>Drop a photo here</strong> or <button type="button" class="inf-link" id="inf_face_pick">choose a file</button></span><small>JPG, PNG or WebP, up to 15 MB</small></div>' +
                '<input type="file" id="inf_face_file" accept="image/jpeg,image/png,image/webp" hidden>' +
                '<div class="inf-wiz__foot"><button type="button" class="btn btn-secondary" id="inf_face_back">Back</button><span class="inf-wiz__meta" id="inf_face_status"></span>' +
                '<button type="button" class="btn btn-primary" id="inf_face_next" ' + (inf.face_asset_id ? '' : 'disabled') + '>Continue <i class="fa-solid fa-arrow-right"></i></button></div>';
            $('#inf_panel').html(html);
            if (inf.face_asset_id) { api('influencer_images', { id: inf.id, role: 'face' }, function (o) { if (o && o.success && o.images.length) { $('#inf_face_row').html('<div class="inf-photo is-on"><img src="' + esc(o.images[0].thumb_url) + '" alt=""></div>'); } }); }
            function send(file) {
                if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { toastr.error('Use JPG, PNG or WebP'); return; }
                var fd = new FormData(); fd.append('file', file); fd.append('id', inf.id); fd.append('role', 'face');
                $('#inf_face_status').html('<span class="spinner-border spinner-border-sm"></span> Uploading');
                $.ajax({ url: '/api/influencer_upload', method: 'POST', data: fd, dataType: 'json', processData: false, contentType: false })
                    .done(function (o) {
                        $('#inf_face_status').text('');
                        if (!o || !o.success) { err(o); return; }
                        inf.face_asset_id = o.image.id; inf.reference_asset_id = o.image.id;
                        $('#inf_face_out').prop('hidden', false); $('#inf_face_row').html('<div class="inf-photo is-on"><img src="' + esc(o.image.thumb_url) + '" alt=""></div>');
                        $('#inf_face_next').prop('disabled', false);
                    })
                    .fail(function () { $('#inf_face_status').text(''); toastr.error('Upload failed'); });
            }
            $('#inf_face_pick').on('click', function () { $('#inf_face_file').trigger('click'); });
            $('#inf_face_file').on('change', function () { if (this.files[0]) { send(this.files[0]); } this.value = ''; });
            var $drop = $('#inf_face_drop');
            $drop.on('dragover', function (e) { e.preventDefault(); $drop.addClass('is-dragover'); }).on('dragleave drop', function () { $drop.removeClass('is-dragover'); })
                 .on('drop', function (e) { e.preventDefault(); var f = e.originalEvent.dataTransfer.files[0]; if (f) { send(f); } });
            $('#inf_face_back').on('click', function () { go_back('reference'); });
            $('#inf_face_next').on('click', function () {
                api('influencer_reference_pick', { id: inf.id, asset_id: inf.face_asset_id }, function (o) { if (o && o.success) { inf = o.influencer; show('set'); } else { err(o); } });
            });
        }

        /* --- Path B: Training set (10 images from the reference) --- */
        var set_timer = null;
        function render_set() {
            clearTimeout(set_timer);
            var size = LIM.set_size;
            var html = '<h2 class="inf-wiz__h">Training Set</h2>' +
                '<p class="inf-wiz__p">' + size + ' square images from the reference, different angles, expressions and light.</p>' +
                '<div class="inf-grid" id="inf_set_form"><div class="inf-field inf-field--full"><label class="inf-label" for="inf_steer">Steer the set (optional)</label><input type="text" class="form-control" id="inf_steer" maxlength="1000" placeholder="soft natural light, minimal makeup" value="' + esc(inf.steer_text) + '"></div></div>' +
                '<div class="inf-setprog" id="inf_set_prog" hidden><span class="inf-setprog__n"><strong id="inf_set_done">0</strong> of ' + size + '</span><div class="inf-counter__bar"><div class="inf-counter__fill" id="inf_set_bar"></div></div></div>' +
                '<div class="inf-photos" id="inf_set_grid" hidden></div>' +
                '<div class="inf-wiz__foot"><button type="button" class="btn btn-secondary" id="inf_set_back">Back</button><span class="inf-wiz__meta" id="inf_set_status"></span>' +
                '<button type="button" class="btn btn-secondary" id="inf_set_regen" hidden><i class="fa-solid fa-rotate"></i> Start over</button>' +
                '<button type="button" class="btn btn-primary" id="inf_set_go"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate ' + size + ' images</button>' +
                '<button type="button" class="btn btn-primary" id="inf_set_next" hidden>Continue <i class="fa-solid fa-arrow-right"></i></button></div>';
            $('#inf_panel').html(html);
            $('#inf_set_back').on('click', function () { go_back('set'); });
            function paint(st) {
                var has = st.group_key !== '';
                $('#inf_set_form').prop('hidden', has); $('#inf_set_prog, #inf_set_grid').prop('hidden', !has);
                $('#inf_set_go').prop('hidden', has); $('#inf_set_regen').prop('hidden', !has);
                $('#inf_set_next').prop('hidden', !st.complete);
                if (!has) { return; }
                $('#inf_set_done').text(st.done); $('#inf_set_bar').css('width', (st.done / st.size * 100) + '%').toggleClass('is-met', st.complete);
                $('#inf_set_status').text(st.active > 0 ? (st.active + ' generating') : (st.failed > 0 ? st.failed + ' failed' : ''));
                var $g = $('#inf_set_grid').empty();
                st.slots.forEach(function (sl) {
                    var $t = $('<div class="inf-photo inf-slot">').attr('data-job', sl.job_id);
                    if (sl.status === 'done' && sl.thumb_url) {
                        $t.append('<img src="' + esc(sl.thumb_url) + '" alt="">');
                        $t.append('<button type="button" class="inf-photo__rm" title="Regenerate"><i class="fa-solid fa-rotate"></i></button>');
                    } else if (sl.status === 'failed' || sl.status === 'cancelled') {
                        $t.addClass('is-failed').append('<span class="inf-slot__st"><i class="fa-solid fa-circle-exclamation"></i> Failed</span><button type="button" class="inf-link inf-slot__retry">Retry</button>').attr('title', sl.error || '');
                    } else {
                        $t.addClass('inf-photo--up').append('<span class="spinner-border spinner-border-sm"></span><span class="inf-photo__st">' + esc(status_text(sl)) + '</span>');
                    }
                    $t.find('.inf-photo__rm, .inf-slot__retry').on('click', function () {
                        api('influencer_training_set_retry', { id: inf.id, job_id: sl.job_id }, function (o) { if (o && o.success) { refresh(); } else { err(o); } });
                    });
                    $g.append($t);
                });
                if (st.active > 0) { set_timer = setTimeout(refresh, 3000); }
            }
            function refresh() { api('influencer_training_set_status', { id: inf.id }, function (o) { if (o && o.success) { paint(o); } }); }
            refresh();
            $('#inf_set_go').on('click', function () {
                if (!C.enabled) { toastr.info('Rendering is not configured yet'); return; }
                var $b = $(this).prop('disabled', true);
                api('influencer_training_set_start', { id: inf.id, steer_text: $('#inf_steer').val() }, function (o) {
                    $b.prop('disabled', false);
                    if (o && o.success) { inf.steer_text = $('#inf_steer').val(); inf.training_set_group = o.group_key; refresh(); } else { err(o); }
                });
            });
            $('#inf_set_regen').on('click', function () {
                Swal.fire({ title: 'Start the set over?', text: 'The current images are set aside and ' + size + ' new ones are generated.', icon: 'question', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Start over', confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' })
                    .then(function (r) { if (r.isConfirmed) { inf.training_set_group = ''; clearTimeout(set_timer); paint({ group_key: '', complete: false, slots: [] }); } });
            });
            $('#inf_set_next').on('click', function () {
                api('influencer_save_step', { id: inf.id, step: 'review' }, function (o) { if (o && o.success) { inf = o.influencer; show('review'); } else { err(o); } });
            });
        }

        /* --- Path B: Review & train --- */
        function render_review() {
            var retrain = inf.active_model_id > 0;
            var html = '<h2 class="inf-wiz__h">' + (retrain ? 'Retrain ' : 'Train ') + esc(inf.name) + '</h2>' +
                '<p class="inf-wiz__p">' + (retrain ? 'The current model keeps working until the new one finishes. ' : '') + 'Training takes a few minutes and runs once; afterwards it generates on demand.</p>' +
                '<div class="inf-summary"><span><strong id="inf_rev_n">…</strong> images</span><span><strong>Included</strong> in your plan</span><span><strong>' + esc(LIM.steps) + '</strong> steps</span></div>' +
                '<div class="inf-photos inf-photos--sm" id="inf_rev_grid"></div>' +
                '<div class="inf-wiz__foot"><button type="button" class="btn btn-secondary" id="inf_rev_back">Back</button>' +
                '<button type="button" class="btn btn-primary" id="inf_rev_go" disabled><i class="fa-solid fa-bolt"></i> ' + (retrain ? 'Retrain' : 'Train') + '</button></div>';
            $('#inf_panel').html(html);
            api('influencer_images', { id: inf.id, role: 'training' }, function (o) {
                var imgs = (o && o.success) ? o.images : [];
                $('#inf_rev_n').text(imgs.length);
                imgs.forEach(function (img) { $('#inf_rev_grid').append('<div class="inf-photo"><img src="' + esc(img.thumb_url) + '" alt="" loading="lazy"></div>'); });
                $('#inf_rev_go').prop('disabled', imgs.length < LIM.set_size || !C.enabled);
                if (!C.enabled) { toastr.info('Rendering is not configured yet'); }
            });
            $('#inf_rev_back').on('click', function () { if (retrain) { window.location = '/influencers'; } else { go_back('review'); } });
            $('#inf_rev_go').on('click', function () {
                var $b = $(this).prop('disabled', true);
                api('influencer_train', { id: inf.id }, function (o) {
                    if (o && o.success) { inf = o.influencer; toastr.success('Training started'); show('training'); } else { err(o); $b.prop('disabled', false); }
                });
            });
        }

        var RENDER = { name: render_name, photos: render_photos, input: render_name, reference: render_reference, set: render_set, review: render_review, training: render_training, done: render_done };
        window.INF_WIZ = { register: function (step, fn) { RENDER[step] = fn; }, get: function () { return inf; }, set: function (v) { inf = v; }, show: function (s) { show(s); }, back: go_back, steps: visible_steps, path: function () { return path; } };

        function show(step) {
            clearTimeout(poll_timer);
            render_steps(path, step);
            $('#inf_steps').prop('hidden', step === 'done');
            (RENDER[step] || function () { render_pending(step); })();
        }

        if (inf) {
            path = inf.path;
            if (!path) { path = 'photos'; }
            if (inf.status === 'ready' && inf.pending_model_id > 0) { show('training'); return; }
            if (CFG.retrain && inf.status === 'ready' && inf.pending_model_id <= 0) { show(path === 'photos' ? 'done' : 'review'); return; }
            show(inf.wizard_step || 'name');
        } else {
            path = 'photos';
            show('name');
        }
    }

    /* =====================================================================
     * Generate Images
     * =================================================================== */
    function init_images() {
        var inf = CFG.influencer;
        if (!inf) { return; }
        var jobs = [];            // recent image jobs (newest first)
        var current = null;       // { job, asset }
        var stop_poll = null;
        var prompts = ((C.prompts || {})[inf.gender] || {}).image || [];

        function seg_pick(id) { $('#' + id).on('click', '.inf-seg__opt', function () { $('#' + id + ' .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true'); cost(); }); }
        function seg_val(id) { return $('#' + id + ' .inf-seg__opt.is-on').data('value'); }
        function seg_set(id, v) { $('#' + id + ' .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false').filter('[data-value="' + v + '"]').addClass('is-on').attr('aria-pressed', 'true'); }
        function model_key() { return $('#inf_model .inf-opt.is-on').data('key') || ''; }
        function model_label(key) { var m = (C.pickers.image || []).filter(function (o) { return o.key === key; })[0]; return m ? m.label : key; }
        function cost() {
            var n = parseInt(seg_val('inf_n'), 10) || 1;
            $('#inf_gen_cost').html(credits_html(price_of('image', n)));
        }

        $('#inf_who').on('change', function () { window.location = '/influencers/images/' + this.value; });
        $('[data-copy]').on('click', function () { var t = $(this).data('copy'); if (navigator.clipboard) { navigator.clipboard.writeText(t); toastr.success('Copied'); } });
        // A shape key as stored now ('3:4'); runs made before the ratios carry square | portrait | landscape.
        function aspect_key(v) {
            var A = C.aspect || { keys: [], legacy: {}, default_image: '3:4' };
            v = String(v || '');
            if (A.legacy[v]) { return A.legacy[v]; }
            return A.keys.indexOf(v) >= 0 ? v : A.default_image;
        }
        // Shapes the chosen model cannot render are disabled; a selected one moves to the first it can.
        function sync_sizes() {
            var key = model_key(), m = null;
            $.each((C.pickers || {}).image || [], function (i, o) { if (o.key === key) { m = o; } });
            var ok = (m && m.aspects && m.aspects.length) ? m.aspects : null;
            $('#inf_size .inf-seg__opt').each(function () { $(this).prop('disabled', !!ok && ok.indexOf(String($(this).data('value'))) < 0); });
            if ($('#inf_size .inf-seg__opt.is-on').prop('disabled')) { seg_set('inf_size', ok[0]); }
        }
        $('#inf_model').on('click', '.inf-opt', function () { $('#inf_model .inf-opt').removeClass('is-on'); $(this).addClass('is-on'); sync_sizes(); cost(); });
        seg_pick('inf_size'); seg_pick('inf_n');
        $('#inf_prompt_chips').on('click', '.inf-chip--text', function () { $('#inf_prompt').val(prompts[$(this).data('i')]).trigger('focus'); });
        $('#inf_prompt_auto').on('click', function () {
            var $b = $(this), html = $b.html();
            $b.prop('disabled', true).addClass('is-busy').html('<span class="spinner-border spinner-border-sm"></span> Writing…');
            $('#inf_prompt').prop('disabled', true).attr('placeholder', 'Writing a prompt…');
            api('influencer_prompt_auto', { id: inf.id, hint: $('#inf_prompt').val().trim() }, function (o) {
                $b.prop('disabled', false).removeClass('is-busy').html(html);
                $('#inf_prompt').prop('disabled', false).attr('placeholder', prompts[0] || '');
                if (o && o.success) { $('#inf_prompt').val(o.prompt).trigger('focus'); } else { err(o); }
            });
        });
        cost();

        /* --- submit --- */
        function generate(overrides) {
            var prompt = $('#inf_prompt').val().trim();
            if (prompt == '') { toastr.error('Write a prompt first'); return; }
            if (!C.enabled) { toastr.info('Rendering is not configured yet'); return; }
            var body = $.extend({ id: inf.id, prompt: prompt, model_key: model_key(), image_size: seg_val('inf_size'), num_images: seg_val('inf_n'),
                seed: $('#inf_seed').val().trim(), lora_scale: $('#inf_lora').val().trim(), guidance: $('#inf_guidance').val().trim(), steps: $('#inf_steps').val().trim() }, overrides || {});
            $('#inf_gen_go, #inf_res_again').prop('disabled', true);
            busy('Sending');
            api('influencer_generate_image', body, function (o) {
                if (!o || !o.success) { err(o); $('#inf_gen_go, #inf_res_again').prop('disabled', false); show_current(); return; }
                spend_credits(price_of('image', parseInt(body.num_images, 10) || 1)); cost();
                jobs.unshift(o.job); render_strip();
                watch(o.job.id);
            });
        }
        $('#inf_gen_go').on('click', function () { generate(); });

        function watch(job_id) {
            if (stop_poll) { stop_poll(); }
            stop_poll = poll_job(job_id, function (j) { busy(status_text(j)); merge(j); }, function (j) {
                merge(j); $('#inf_gen_go, #inf_res_again').prop('disabled', false);
                if (j.status !== 'done' || !j.assets.length) { toastr.error(j.error || 'Generation failed'); show_current(); render_strip(); return; }
                if (j.quality_note) { toastr.warning('Check this one before posting. ' + j.quality_note, '', { timeOut: 9000 }); }
                select(j, j.assets[0]); render_strip();
            });
        }
        function merge(j) { var i = jobs.findIndex(function (x) { return x.id === j.id; }); if (i >= 0) { jobs[i] = j; } else { jobs.unshift(j); } }

        /* --- preview --- */
        function busy(text) { $('#inf_idle, #inf_main').prop('hidden', true); $('#inf_busy').prop('hidden', false); $('#inf_busy_text').text(text); }
        function show_current() {
            $('#inf_busy').prop('hidden', true);
            if (!current) { $('#inf_idle').prop('hidden', false); $('#inf_main, #inf_result').prop('hidden', true); return; }
            $('#inf_idle').prop('hidden', true); $('#inf_main, #inf_result').prop('hidden', false);
            $('#inf_main_img').attr('src', current.asset.display_url || current.asset.thumb_url);
            $('#inf_strip .inf-strip__item').removeClass('is-on').filter('[data-asset="' + current.asset.id + '"]').addClass('is-on');
        }
        function select(job, asset) { current = { job: job, asset: asset }; show_current(); }
        function render_strip() {
            var $s = $('#inf_strip').empty();
            jobs.forEach(function (j) {
                if (j.assets && j.assets.length) {
                    j.assets.forEach(function (a) {
                        if (a.status !== 'ready' || !a.thumb_url) { return; }
                        var $t = $('<button type="button" class="inf-strip__item' + (current && current.asset.id === a.id ? ' is-on' : '') + '">').attr('data-asset', a.id).append('<img src="' + esc(a.thumb_url) + '" alt="">');
                        $t.on('click', function () { select(j, a); });
                        $s.append($t);
                    });
                } else if (j.status === 'failed') {
                    var $f = $('<button type="button" class="inf-strip__item inf-strip__item--failed" title="' + esc(j.error) + '"><i class="fa-solid fa-circle-exclamation"></i></button>');
                    $f.on('click', function () { toastr.error(j.error || 'Generation failed'); });
                    $s.append($f);
                } else if (['queued', 'submitting', 'running', 'landing'].indexOf(j.status) >= 0) {
                    $s.append('<span class="inf-strip__item inf-strip__item--busy"><span class="spinner-border spinner-border-sm"></span></span>');
                }   // a finished job whose file was deleted shows nothing
            });
            drop_broken('#inf_strip');
        }
        api('influencer_jobs_list', { id: inf.id, type: 'image,enhance', limit: 24 }, function (o) {
            if (!o || !o.success) { return; }
            jobs = o.jobs || []; render_strip();
            var running = jobs.filter(function (j) { return ['queued', 'submitting', 'running', 'landing'].indexOf(j.status) >= 0; })[0];
            if (running) { busy(status_text(running)); watch(running.id); return; }
            // Earlier results live in the strip below; the stage stays empty until the creator generates or picks one.
        });

        /* --- result actions --- */
        $('#inf_res_again').on('click', function () {
            // Re-run the selected image: same prompt, pinned seed, same size and model. The form
            // takes those values too, so the next run can start from them.
            if (!current) { return; }
            var seed = String(current.job.result_seed || current.job.seed || '');
            $('#inf_prompt').val(current.job.prompt);
            $('#inf_seed').val(seed);
            seg_set('inf_size', aspect_key(current.job.params.image_size || 'square'));
            generate({ prompt: current.job.prompt, seed: seed, image_size: aspect_key(current.job.params.image_size || 'square'), model_key: current.job.model_key });
        });
        $('#inf_res_video').on('click', function () { if (current) { window.location = '/influencers/videos/' + inf.id + '/' + current.asset.id; } });
        $('#inf_res_download').on('click', function () {
            if (!current) { return; }
            api('influencer_asset_url', { asset_id: current.asset.id }, function (o) { if (o && o.success) { window.open(o.url, '_blank'); } else { err(o); } });
        });
        $('#inf_res_delete').on('click', function () {
            if (!current) { return; }
            var gone = current.asset.id;
            confirm_delete('image', function () {
                delete_asset(inf.id, gone, function () {
                    jobs.forEach(function (j) { j.assets = (j.assets || []).filter(function (a) { return a.id !== gone; }); });
                    current = null;
                    var next = jobs.filter(function (j) { return j.assets && j.assets.length; })[0];
                    if (next) { select(next, next.assets[0]); } else { show_current(); }
                    render_strip();
                });
            });
        });
        $('#inf_res_message').on('click', function () { if (!current) { return; } send_in_message(current.asset); });
        $('#inf_res_post').on('click', function () {
            if (!current) { return; }
            try { sessionStorage.setItem('cs_open_asset', String(current.asset.id)); } catch (e) {}
            window.location = '/studio';
        });
        $('#inf_res_enhance').on('click', function () {
            if (!current) { return; }
            if (!C.enabled) { toastr.info('Rendering is not configured yet'); return; }
            var $b = $(this).prop('disabled', true);
            api('influencer_enhance', { id: inf.id, asset_id: current.asset.id }, function (o) {
                $b.prop('disabled', false);
                if (!o || !o.success) { err(o); return; }
                spend_credits(price_of('enhance', 1)); cost();
                jobs.unshift(o.job); render_strip(); watch(o.job.id);
            });
        });
        $('#inf_expand').on('click', function () { if (current) { $('#inf_lightbox_img').attr('src', current.asset.display_url || current.asset.thumb_url); $('#inf_lightbox').prop('hidden', false); } });
        $('#inf_lightbox, #inf_lightbox_close').on('click', function () { $('#inf_lightbox').prop('hidden', true); });
        $(document).on('keydown', function (e) { if (e.key === 'Escape') { $('#inf_lightbox').prop('hidden', true); } });
    }

    /* =====================================================================
     * Generate Videos
     * =================================================================== */
    function init_videos() {
        var inf = CFG.influencer;
        if (!inf) { return; }
        var jobs = [], current = null, stop_poll = null;
        var still = parseInt($('#inf_gen').data('still'), 10) || 0;
        var vprompts = ((C.prompts || {})[inf.gender] || {}).video || [];
        $('#inf_vprompt_chips').on('click', '.inf-chip--text', function () { $('#inf_vprompt').val(vprompts[$(this).data('i')]).trigger('focus'); });
        $('#inf_vprompt_auto').on('click', function () {
            var $b = $(this), html = $b.html();
            $b.prop('disabled', true).addClass('is-busy').html('<span class="spinner-border spinner-border-sm"></span> Writing…');
            $('#inf_vprompt').prop('disabled', true).attr('placeholder', 'Writing a prompt…');
            api('influencer_prompt_auto', { id: inf.id, kind: 'video', hint: $('#inf_vprompt').val().trim() }, function (o) {
                $b.prop('disabled', false).removeClass('is-busy').html(html);
                $('#inf_vprompt').prop('disabled', false).attr('placeholder', vprompts[0] || '');
                if (o && o.success) { $('#inf_vprompt').val(o.prompt).trigger('focus'); } else { err(o); }
            });
        });

        function seg_val(id) { return $('#' + id + ' .inf-seg__opt.is-on').data('value'); }
        function model_key() { return $('#inf_vmodel .inf-opt.is-on').data('key') || ''; }
        function model_opt(key) { return (C.pickers.video || []).filter(function (o) { return o.key === key; })[0]; }
        function durations() {
            var m = model_opt(model_key()); var d = (m && m.durations.length) ? m.durations : ['5'];
            var cur = seg_val('inf_vdur');
            $('#inf_vdur').html(d.map(function (x) { return '<button type="button" class="inf-seg__opt' + (x === cur || (!d.includes(cur) && x === d[0]) ? ' is-on' : '') + '" data-value="' + esc(x) + '" aria-pressed="false"><span>' + esc(x) + 's</span></button>'; }).join(''));
            $('#inf_vdur .inf-seg__opt.is-on').attr('aria-pressed', 'true');
            cost();
        }
        // A video's price follows its length (server table credits_by_duration); fall back to the model, then the type default.
        function vprice() {
            var m = model_opt(model_key()), d = String(seg_val('inf_vdur') || ''), t = m && m.credits_by_duration;
            if (t && t[d] !== undefined) { return parseInt(t[d], 10) || 0; }
            return (m && m.credits) ? m.credits : price_of('video', 1);
        }
        function cost() { $('#inf_vcost').html(credits_html(vprice())); }
        $('#inf_who').on('change', function () { window.location = '/influencers/videos/' + this.value; });
        $('#inf_vmodel').on('click', '.inf-opt', function () { $('#inf_vmodel .inf-opt').removeClass('is-on'); $(this).addClass('is-on'); durations(); });
        $('#inf_vdur').on('click', '.inf-seg__opt', function () { $('#inf_vdur .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true'); cost(); });
        durations();

        /* stills: any ready image in the creator's Studio Library, newest first; a chip narrows it to hers */
        var her_ids = {};
        $('#inf_stills').on('click', '.inf-photo--pick', function () {
            var id = +$(this).attr('data-id');
            var was = (still === id);   // clicking the chosen image again clears the choice
            $('#inf_stills .inf-photo').removeClass('is-on').attr('aria-pressed', 'false');
            still = was ? 0 : id;
            if (!was) { $(this).addClass('is-on').attr('aria-pressed', 'true'); }
            $('#inf_vgo').prop('disabled', !still);
        });
        $('#inf_still_roles').on('click', '.inf-chip', function () {
            $('#inf_still_roles .inf-chip').removeClass('is-on').attr('aria-pressed', 'false');
            $(this).addClass('is-on').attr('aria-pressed', 'true');
            var mine = $(this).attr('data-scope') === 'mine';
            $('#inf_stills .inf-photo').each(function () { this.hidden = mine && !her_ids[+$(this).attr('data-id')]; });
            $('#inf_stills').scrollTop(0);
        });
        api('media_list', { type: 'image' }, function (o) {
            var $g = $('#inf_stills').empty();
            var imgs = (o && o.success) ? (o.assets || []).filter(function (x) { return x.status === 'ready' && x.thumb_url && x.moderation !== 'blocked'; }) : [];
            if (!imgs.length) { $g.html('<span class="inf-wiz__meta">No images in your Library yet. Generate or upload one first.</span>'); return; }
            imgs.forEach(function (img) {
                var $t = $('<button type="button" class="inf-photo inf-photo--pick' + (img.id === still ? ' is-on' : '') + '">').attr('data-id', img.id).append('<img src="' + esc(img.thumb_url) + '" alt="" loading="lazy">');
                $g.append($t.attr('aria-pressed', img.id === still ? 'true' : 'false').attr('aria-label', 'Use this image'));
            });
            // Only a still handed over from "Make Video" is preselected; otherwise the creator picks one.
            if (still && !$g.find('.is-on').length) { still = 0; }
            $('#inf_vgo').prop('disabled', !still);
            drop_broken('#inf_stills');
            api('media_list', { type: 'image', influencer: inf.id }, function (o2) {
                var mine = (o2 && o2.success) ? (o2.assets || []) : [];
                mine.forEach(function (x) { her_ids[x.id] = 1; });
                if (mine.length && mine.length < imgs.length) {
                    $('#inf_still_roles').empty().prop('hidden', false)
                        .append('<button type="button" class="inf-chip is-on" data-scope="all" aria-pressed="true">All Library</button>')
                        .append($('<button type="button" class="inf-chip" data-scope="mine" aria-pressed="false">').text(inf.name || 'This influencer'));
                }
            });
        });

        function busy(t) { $('#inf_vidle').prop('hidden', true); $('#inf_video').prop('hidden', true); $('#inf_vbusy').prop('hidden', false); $('#inf_vbusy_text').text(t); }
        function show_current() {
            $('#inf_vbusy').prop('hidden', true);
            if (!current) { $('#inf_vidle').prop('hidden', false); $('#inf_video, #inf_vresult').prop('hidden', true); return; }
            $('#inf_vidle').prop('hidden', true); $('#inf_video, #inf_vresult').prop('hidden', false);
            var v = document.getElementById('inf_video'); v.poster = current.asset.display_url || ''; v.src = current.asset.video_url || ''; v.load();
            var m = model_opt(current.job.model_key);
            $('#inf_vres_model').text(m ? m.label : current.job.model_key); $('#inf_vres_dur').text((current.job.params.duration || '') + 's'); $('#inf_vres_prompt').text(current.job.prompt || '');
            $('#inf_vstrip .inf-strip__item').removeClass('is-on').filter('[data-asset="' + current.asset.id + '"]').addClass('is-on');
        }
        function select(job, asset) { current = { job: job, asset: asset }; show_current(); }
        function merge(j) { var i = jobs.findIndex(function (x) { return x.id === j.id; }); if (i >= 0) { jobs[i] = j; } else { jobs.unshift(j); } }
        function render_strip() {
            var $s = $('#inf_vstrip').empty();
            jobs.forEach(function (j) {
                if (j.assets && j.assets.length) {
                    j.assets.forEach(function (a) {
                        if (a.status !== 'ready') { return; }
                        var $t = $('<button type="button" class="inf-strip__item' + (current && current.asset.id === a.id ? ' is-on' : '') + '">').attr('data-asset', a.id).append(a.thumb_url ? '<img src="' + esc(a.thumb_url) + '" alt="">' : '<i class="fa-solid fa-play"></i>');
                        $t.on('click', function () { select(j, a); });
                        $s.append($t);
                    });
                } else if (j.status === 'failed') {
                    var $f = $('<button type="button" class="inf-strip__item inf-strip__item--failed" title="' + esc(j.error) + '"><i class="fa-solid fa-circle-exclamation"></i></button>');
                    $f.on('click', function () { toastr.error(j.error || 'Generation failed'); }); $s.append($f);
                } else if (['queued', 'submitting', 'running', 'landing'].indexOf(j.status) >= 0) { $s.append('<span class="inf-strip__item inf-strip__item--busy"><span class="spinner-border spinner-border-sm"></span></span>'); }
            });
            drop_broken('#inf_vstrip');
        }
        function watch(job_id) {
            if (stop_poll) { stop_poll(); }
            stop_poll = poll_job(job_id, function (j) { busy(status_text(j)); merge(j); }, function (j) {
                merge(j); $('#inf_vgo').prop('disabled', false);
                if (j.status !== 'done' || !j.assets.length) { toastr.error(j.error || 'Generation failed'); show_current(); render_strip(); return; }
                select(j, j.assets[0]); render_strip();
            });
        }
        api('influencer_jobs_list', { id: inf.id, type: 'video', limit: 24 }, function (o) {
            if (!o || !o.success) { return; }
            jobs = o.jobs || []; render_strip();
            var running = jobs.filter(function (j) { return ['queued', 'submitting', 'running', 'landing'].indexOf(j.status) >= 0; })[0];
            if (running) { busy(status_text(running)); watch(running.id); return; }
            // Earlier videos live in the strip below; the stage stays empty until the creator generates or picks one.
        });
        $('#inf_vgo').on('click', function () {
            if (!still) { toastr.error('Pick an image first'); return; }
            if (!C.enabled) { toastr.info('Rendering is not configured yet'); return; }
            var $b = $(this).prop('disabled', true);
            busy('Sending');
            api('influencer_generate_video', { id: inf.id, asset_id: still, prompt: $('#inf_vprompt').val().trim(), model_key: model_key(), duration: seg_val('inf_vdur') }, function (o) {
                if (!o || !o.success) { err(o); $b.prop('disabled', false); show_current(); return; }
                spend_credits(vprice()); cost();
                jobs.unshift(o.job); render_strip(); watch(o.job.id);
            });
        });
        $('#inf_vres_download').on('click', function () { if (!current) { return; } api('influencer_asset_url', { asset_id: current.asset.id }, function (o) { if (o && o.success) { window.open(o.url, '_blank'); } else { err(o); } }); });
        $('#inf_vres_message').on('click', function () { if (!current) { return; } send_in_message($.extend({ type: 'video' }, current.asset)); });
        $('#inf_vres_post').on('click', function () { if (!current) { return; } try { sessionStorage.setItem('cs_open_asset', String(current.asset.id)); } catch (e) {} window.location = '/studio'; });
        $('#inf_vres_delete').on('click', function () {
            if (!current) { return; }
            var gone = current.asset.id;
            confirm_delete('video', function () {
                delete_asset(inf.id, gone, function () {
                    jobs.forEach(function (j) { j.assets = (j.assets || []).filter(function (a) { return a.id !== gone; }); });
                    current = null;
                    var next = jobs.filter(function (j) { return j.assets && j.assets.length; })[0];
                    if (next) { select(next, next.assets[0]); } else { show_current(); }
                    render_strip();
                });
            });
        });
    }

    /* =====================================================================
     * Gallery (one influencer, by role)
     * =================================================================== */
    function init_gallery() {
        var inf = CFG.influencer;
        if (!inf) { return; }
        var role = '', assets = [], current = null;
        $('#inf_who').on('change', function () { window.location = '/influencers/gallery/' + this.value; });
        function load() {
            $('#inf_gal_loading').prop('hidden', false); $('#inf_gal, #inf_gal_empty').prop('hidden', true);
            api('media_list', { influencer: inf.id, role: role }, function (o) {
                $('#inf_gal_loading').prop('hidden', true);
                assets = (o && o.success) ? (o.assets || []) : [];
                if (!assets.length) { $('#inf_gal_empty').prop('hidden', false); return; }
                var $g = $('#inf_gal').empty().prop('hidden', false);
                assets.forEach(function (a, i) {
                    var $t = $('<button type="button" class="inf-tile">').attr('data-id', a.id).css('animation-delay', Math.min(i * 14, 300) + 'ms');
                    if (a.status === 'ready' && a.thumb_url && a.moderation !== 'blocked') { $t.append('<img src="' + esc(a.thumb_url) + '" alt="" loading="lazy">'); }
                    else { $t.append('<span class="inf-tile__ph"><i class="fa-solid ' + (a.type === 'video' ? 'fa-play' : 'fa-image') + '"></i></span>'); }
                    if (a.type === 'video') { $t.append('<span class="inf-tile__badge"><i class="fa-solid fa-play"></i>' + (a.duration ? ' ' + Math.floor(a.duration / 60) + ':' + ('0' + (a.duration % 60)).slice(-2) : '') + '</span>'); }
                    if (a.status !== 'ready') { $t.append('<span class="inf-tile__state">' + (a.status === 'failed' ? 'Failed' : 'Processing') + '</span>'); }
                    $t.on('click', function () { open_asset(a); });
                    $g.append($t);
                });
                drop_broken('#inf_gal');
            });
        }
        function open_asset(a) {
            if (a.status !== 'ready') { return; }
            current = a;
            var v = document.getElementById('inf_lightbox_video');
            if (a.type === 'video') {
                $('#inf_lightbox_img').prop('hidden', true); $('#inf_lightbox_video').prop('hidden', false);
                v.poster = a.thumb_url || ''; v.src = a.video_url || ''; v.load();
            } else {
                v.pause(); v.removeAttribute('src'); $('#inf_lightbox_video').prop('hidden', true);
                $('#inf_lightbox_img').prop('hidden', false).attr('src', a.preview_url || a.display_url || a.thumb_url);
            }
            $('#inf_lightbox_meta').text((a.type === 'video' ? 'Video' : 'Image') + (a.width && a.height ? ' · ' + a.width + '×' + a.height : ''));
            $('#inf_lightbox_edit').prop('hidden', a.type !== 'image');   // Edit By Instruction is for images
            $('#inf_lightbox_frame').prop('hidden', a.type !== 'video');  // a video's current frame exports as an image
            $('#inf_lightbox').prop('hidden', false);
        }
        function close_lightbox() { $('#inf_lightbox').prop('hidden', true); var v = document.getElementById('inf_lightbox_video'); v.pause(); }
        $('#inf_gal_roles').on('click', '.inf-chip', function () { $('#inf_gal_roles .inf-chip').removeClass('is-on'); $(this).addClass('is-on'); role = $(this).data('role') || ''; load(); });
        $('#inf_lightbox_close').on('click', close_lightbox);
        $('#inf_lightbox').on('click', function (e) { if (e.target === this) { close_lightbox(); } });
        $(document).on('keydown', function (e) { if (e.key === 'Escape') { close_lightbox(); } });
        $('#inf_lightbox_download').on('click', function () { if (!current) { return; } api('influencer_asset_url', { asset_id: current.id }, function (o) { if (o && o.success) { window.open(o.url, '_blank'); } else { err(o); } }); });
        $('#inf_lightbox_post').on('click', function () { if (!current) { return; } try { sessionStorage.setItem('cs_open_asset', String(current.id)); } catch (e) {} window.location = '/studio'; });
        $('#inf_lightbox_message').on('click', function () { if (!current) { return; } send_in_message(current); });
        // Edit By Instruction (AiTools, ai-tools.js): the result is a new image in her gallery, so the grid reloads.
        $('#inf_lightbox_edit').on('click', function () {
            if (!current || !window.AiTools) { return; }
            var a = current;
            close_lightbox();
            AiTools.edit({ id: a.id, display_url: a.preview_url || a.display_url, thumb_url: a.thumb_url, name: a.name }, function () { load(); });
        });
        // Export the frame the player is paused on (AiTools); it can go straight into Replicate Photo as the source.
        $('#inf_lightbox_frame').on('click', function () {
            if (!current || !window.AiTools) { return; }
            var $b = $(this).prop('disabled', true), v = document.getElementById('inf_lightbox_video');
            AiTools.export_frame(current.id, v.currentTime || 0, function (frame) {
                $b.prop('disabled', false);
                if (!frame) { return; }
                v.pause();
                Swal.fire({ title: 'Frame Saved', text: 'The frame is in your Library.', imageUrl: frame.thumb_url, imageHeight: 180, showCancelButton: true, confirmButtonText: 'Use As Source', cancelButtonText: 'Done',
                    customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }, buttonsStyling: false, reverseButtons: true })
                    .then(function (r) { if (r.isConfirmed) { window.location = '/influencers/replicate/' + inf.id + '/' + frame.id; } });
            });
        });
        $('#inf_lightbox_delete').on('click', function () {
            if (!current) { return; }
            var gone = current.id;
            confirm_delete(current.type === 'video' ? 'video' : 'image', function () { delete_asset(inf.id, gone, function () { close_lightbox(); current = null; load(); }); });
        });
        load();
    }

    if (page === 'index')  { init_index(); }
    if (page === 'create') { init_create(); }
    if (page === 'images') { init_images(); }
    if (page === 'videos') { init_videos(); }
    if (page === 'gallery') { init_gallery(); }
});
