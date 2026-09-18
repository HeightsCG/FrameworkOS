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

    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
    function err(o, fallback) { toastr.error((o && o.message) || fallback || 'Something went wrong. Please try again.'); }
    function api(endpoint, body, cb) {
        ApiDataSvc.apiCall('post', endpoint, body, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) {}
            cb(o);
        });
    }
    function money(n) { return '$' + (Math.round((n || 0) * 100) / 100).toFixed(2); }

    /* shared: poll one job until it is terminal; human status text */
    function poll_job(job_id, on_update, on_done) {
        var t = setTimeout(function tick() {
            api('influencer_job_get', { job_id: job_id }, function (o) {
                if (!o || !o.success) { t = setTimeout(tick, 4000); return; }
                var j = o.job;
                if (on_update) { on_update(j); }
                if (j.status === 'done' || j.status === 'failed' || j.status === 'cancelled') { on_done(j); return; }
                t = setTimeout(tick, 3000);
            });
        }, 1500);
        return function () { clearTimeout(t); };
    }
    function status_text(j) {
        if (j.status === 'queued' && j.wait_reason) { return 'Waiting for a slot'; }
        if (j.status === 'queued') { return 'Queued'; }
        if (j.status === 'submitting') { return 'Sending'; }
        if (j.status === 'running') { return 'Generating'; }
        if (j.status === 'landing') { return 'Saving'; }
        return j.status;
    }

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
                render(o.influencers || []);
                schedule(o.influencers || []);
            });
        }
        function schedule(list) {
            clearTimeout(poll_timer);
            var busy = list.some(is_busy);
            if (busy) { poll_timer = setTimeout(function () { if (!document.hidden) { load(true); } else { schedule(list); } }, 5000); }
        }
        function render(list) {
            if (!list.length) { $('#inf_empty').prop('hidden', false); $('#inf_cards').prop('hidden', true); $('#inf_new_btn').prop('hidden', true); return; }
            $('#inf_empty').prop('hidden', true); $('#inf_new_btn').prop('hidden', false);
            $cards.empty().prop('hidden', false);
            list.forEach(function (inf, i) { $cards.append(card(inf, first ? i : 0)); });
            $cards.append('<a class="inf-card inf-card--new" href="/influencers/create" style="animation-delay:' + Math.min(list.length * 18, 360) + 'ms"><i class="fa-solid fa-plus"></i><span>New influencer</span></a>');
            first = false;
        }
        function target_for(inf) {
            if (inf.status === 'ready' && inf.active_model_id > 0) { return '/influencers/images/' + inf.id; }
            return '/influencers/create/' + inf.id;
        }
        function card(inf, i) {
            var ready = inf.status === 'ready' && inf.active_model_id > 0;
            var $c = $('<div class="inf-card" tabindex="0">').attr('data-id', inf.id).css('animation-delay', Math.min(i * 18, 360) + 'ms');
            var media = inf.cover_url ? '<img src="' + esc(inf.cover_url) + '" alt="' + esc(inf.name) + '" loading="lazy">' : '<i class="fa-regular fa-user"></i>';
            $c.append('<div class="inf-card__media">' + media + '</div>');
            $c.append('<div class="inf-card__body"><span class="inf-card__name">' + esc(inf.name) + '</span>' + state_pill(inf) + '</div>');
            var menu = '<div class="dropdown">' +
                '<button type="button" class="inf-card__menu" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More"><i class="fa-solid fa-ellipsis"></i></button>' +
                '<ul class="dropdown-menu dropdown-menu-end">' +
                (ready ? '<li><a class="dropdown-item" href="/influencers/images/' + inf.id + '"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate images</a></li>' +
                         '<li><a class="dropdown-item" href="/influencers/videos/' + inf.id + '"><i class="fa-solid fa-clapperboard"></i> Generate video</a></li>' +
                         '<li><a class="dropdown-item" href="/influencers/gallery/' + inf.id + '"><i class="fa-solid fa-images"></i> Gallery</a></li>' +
                         '<li><a class="dropdown-item" href="/influencers/create/' + inf.id + '"><i class="fa-solid fa-sliders"></i> Settings</a></li>' +
                         (inf.pending_model_id > 0 ? '' : '<li><a class="dropdown-item" href="/influencers/create/' + inf.id + '/retrain" data-act="retrain"><i class="fa-solid fa-rotate"></i> Retrain</a></li>')
                       : '<li><a class="dropdown-item" href="/influencers/create/' + inf.id + '"><i class="fa-solid fa-arrow-right"></i> Continue setup</a></li>') +
                '<li><hr class="dropdown-divider"></li>' +
                '<li><button type="button" class="dropdown-item text-danger" data-act="delete"><i class="fa-solid fa-trash"></i> Delete</button></li>' +
                '</ul></div>';
            $c.append(menu);
            $c.on('click', function (e) {
                if ($(e.target).closest('.dropdown').length) { return; }
                window.location = target_for(inf);
            });
            $c.on('keydown', function (e) { if (e.key === 'Enter' && !$(e.target).closest('.dropdown').length) { window.location = target_for(inf); } });
            $c.find('[data-act="delete"]').on('click', function () {
                Swal.fire({ title: 'Delete ' + inf.name + '?', text: 'Her trained model is removed. Images already in your library stay there.', icon: 'warning', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Delete', confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' })
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
        var STEP_LABELS = { name: 'Name', photos: 'Photos', input: 'Input', reference: 'Reference', set: 'Training set', review: 'Review', training: 'Training', done: 'Done' };
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

        /* --- chooser (no row yet): pick a path, then name her, then create --- */
        $('#inf_chooser .inf-path').on('click', function () {
            path = $(this).data('path');
            $('#inf_chooser').prop('hidden', true);
            $('#inf_wizard').prop('hidden', false);
            render_steps(path, 'name');
            render_name();
        });

        /* --- Name --- */
        function render_name() {
            var names = CFG.names || [];
            var html = '<h2 class="inf-wiz__h">Name her</h2>' +
                '<p class="inf-wiz__p">Unique across your influencers. You can rename her later.</p>' +
                '<div class="inf-grid"><div class="inf-field inf-field--full">' +
                '<label class="inf-label" for="inf_name">Name</label>' +
                '<input type="text" class="form-control" id="inf_name" maxlength="120" placeholder="Ava" value="' + esc(inf ? inf.name : '') + '">' +
                '</div>' +
                (names.length ? '<div class="inf-field inf-field--full"><div class="inf-label">Suggestions</div><div class="inf-chips" id="inf_name_chips">' +
                    names.map(function (n) { return '<button type="button" class="inf-chip" data-name="' + esc(n) + '">' + esc(n) + '</button>'; }).join('') + '</div></div>' : '') +
                '</div>' +
                '<div class="inf-wiz__foot inf-wiz__foot--end">' +
                (inf ? '' : '<button type="button" class="btn btn-secondary" id="inf_name_back">Back</button>') +
                '<button type="button" class="btn btn-primary" id="inf_name_next">Continue <i class="fa-solid fa-arrow-right"></i></button></div>';
            $('#inf_panel').html(html);
            $('#inf_name_chips').on('click', '.inf-chip', function () { $('#inf_name').val($(this).data('name')).trigger('focus'); $('#inf_name_chips .inf-chip').removeClass('is-on'); $(this).addClass('is-on'); });
            $('#inf_name_back').on('click', function () { $('#inf_wizard').prop('hidden', true); $('#inf_chooser').prop('hidden', false); });
            $('#inf_name').on('keydown', function (e) { if (e.key === 'Enter') { $('#inf_name_next').trigger('click'); } });
            $('#inf_name_next').on('click', function () {
                var name = $('#inf_name').val().trim();
                if (name == '') { toastr.error('Give her a name'); return; }
                if (!inf) {
                    api('influencer_create', { name: name, path: path }, function (o) {
                        if (o && o.success) { window.location = '/influencers/create/' + o.influencer.id; } else { err(o); }
                    });
                    return;
                }
                var next = steps_for(path)[1];
                api('influencer_save_step', { id: inf.id, name: name, step: next }, function (o) {
                    if (o && o.success) { inf = o.influencer; $('#inf_wiz_title').text(inf.name); show(inf.wizard_step); } else { err(o); }
                });
            });
            $('#inf_name').trigger('focus');
        }

        /* --- Training in progress --- */
        function render_training() {
            var html = '<div class="inf-progress">' +
                '<div class="inf-progress__ic"><span class="spinner-border" role="status"></span></div>' +
                '<h2 class="inf-progress__t">Training ' + esc(inf.name) + '</h2>' +
                '<p class="inf-progress__x">This usually takes a few minutes. You can leave this page; her card shows the progress and she becomes available as soon as it finishes.</p>' +
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
                    inf = o.influencer;
                    if (inf.wizard_step === 'training') { watch(); return; }
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
                    '<div class="inf-progress__actions"><a href="/influencers" class="btn btn-secondary">Back</a><button type="button" class="btn btn-primary" id="inf_retry_train"><i class="fa-solid fa-rotate"></i> Try again</button></div>' +
                    '</div>';
            } else {
                html = '<div class="inf-progress">' +
                    '<div class="inf-progress__ic inf-progress__ic--ok"><i class="fa-solid fa-check"></i></div>' +
                    '<h2 class="inf-progress__t">' + esc(inf.name) + ' is trained</h2>' +
                    '<p class="inf-progress__x">Include her trigger word in every prompt to get her.</p>' +
                    '<span class="inf-trigger" id="inf_trigger">' + esc(inf.trigger_word) + ' <button type="button" title="Copy" data-copy="' + esc(inf.trigger_word) + '"><i class="fa-regular fa-copy"></i></button></span>' +
                    '<div class="inf-progress__actions"><a href="/influencers" class="btn btn-secondary">Your influencers</a><a href="/influencers/images/' + inf.id + '" class="btn btn-primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate images</a></div>' +
                    '</div>';
            }
            $('#inf_panel').html(html);
            $('#inf_retry_train').on('click', function () { show(path === 'photos' ? 'photos' : 'review'); });
            $('[data-copy]').on('click', function () { var t = $(this).data('copy'); if (navigator.clipboard) { navigator.clipboard.writeText(t); toastr.success('Copied'); } });
            if (!failed) { render_settings(); }
        }

        /* --- Trained: her defaults, share targets and automations (kept separate per influencer) --- */
        function render_settings() {
            var soc = CFG.social || { accounts: [] };
            var sel = new Set((inf.share_accounts || []).map(String));
            var chips = (soc.accounts || []).map(function (a) {
                return '<label class="inf-social"><input class="form-check-input" type="checkbox" data-sacct="' + esc(a.id) + '"' + (sel.has(String(a.id)) ? ' checked' : '') + '> <span>' + esc(a.username || a.platform) + '</span></label>';
            }).join('');
            var html = '<div class="inf-settings">' +
                '<div class="inf-grid">' +
                '<div class="inf-field inf-field--full"><label class="inf-label" for="inf_set_defaults">Prompt defaults</label><input type="text" class="form-control" id="inf_set_defaults" maxlength="2000" placeholder="film grain, natural light" value="' + esc(inf.prompt_defaults) + '"></div>' +
                '<div class="inf-field inf-field--full"><label class="inf-label" for="inf_set_negative">Negative prompt</label><input type="text" class="form-control" id="inf_set_negative" maxlength="2000" placeholder="blurry, extra fingers" value="' + esc(inf.negative_prompt) + '"></div>' +
                '<div class="inf-field inf-field--full"><div class="inf-label">Share to</div><div class="inf-chips" id="inf_set_share">' + (chips || '<span class="inf-wiz__meta">No connected accounts yet.</span>') + '</div></div>' +
                '</div>' +
                '<div class="inf-wiz__foot inf-wiz__foot--end"><button type="button" class="btn btn-secondary" id="inf_set_save">Save</button></div>' +
                '<div class="inf-autos"><div class="inf-field__row"><div class="inf-label">Her automations</div><a class="inf-link" href="/studio#automation-new-' + inf.id + '"><i class="fa-solid fa-plus"></i> New automation</a></div><div id="inf_autos_list" class="inf-autos__list"><span class="inf-wiz__meta">Loading…</span></div></div>' +
                '</div>';
            $('#inf_panel').append(html);
            $('#inf_set_save').on('click', function () {
                var share = $('#inf_set_share input[data-sacct]:checked').map(function () { return String($(this).data('sacct')); }).get();
                api('influencer_save_step', { id: inf.id, prompt_defaults: $('#inf_set_defaults').val(), negative_prompt: $('#inf_set_negative').val(), share_accounts: share.join(',') }, function (o) {
                    if (o && o.success) { inf = o.influencer; toastr.success('Saved'); } else { err(o); }
                });
            });
            api('scheduler_list', {}, function (o) {
                var rules = ((o && o.success) ? (o.rules || []) : []).filter(function (r) { return r.image_source === 'influencer' && String(r.influencer_id) === String(inf.id); });
                var $l = $('#inf_autos_list').empty();
                if (!rules.length) { $l.html('<span class="inf-wiz__meta">None yet.</span>'); return; }
                rules.forEach(function (r) {
                    $l.append('<a class="inf-auto" href="/studio#scheduler"><span class="inf-auto__name">' + esc(r.name) + '</span><span class="inf-auto__meta">' + esc(r.cadence_summary || '') + (r.next_run ? ' · next ' + esc(r.next_run) : '') + '</span><span class="inf-state inf-state--' + (r.active ? 'ready' : 'draft') + '">' + (r.active ? 'On' : 'Off') + '</span></a>');
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
            $('#inf_photos_cost').text(n >= min ? money(C.training_cost_usd) + ' to train' : '');
            $('#inf_drop').toggleClass('is-full', n >= max);
        }
        function upload_start(file) {
            if (photos.length + upload_queue.length + upload_active >= LIM.max_photos) { toastr.info('Up to ' + LIM.max_photos + ' photos'); return; }
            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { toastr.error(file.name + ': use JPG, PNG or WebP'); return; }
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
        function render_photos() {
            var retrain = inf.active_model_id > 0;
            var html = '<h2 class="inf-wiz__h">' + (retrain ? 'Retrain ' + esc(inf.name) : 'Add her photos') + '</h2>' +
                '<p class="inf-wiz__p">Different angles, expressions and lighting, sharp and well lit, only her in frame.</p>' +
                '<div class="inf-drop" id="inf_drop"><i class="fa-solid fa-cloud-arrow-up"></i><span><strong>Drop photos here</strong> or <button type="button" class="inf-link" id="inf_pick">choose files</button></span><small>JPG, PNG or WebP, up to 15 MB each</small></div>' +
                '<input type="file" id="inf_files" accept="image/jpeg,image/png,image/webp" multiple hidden>' +
                '<div class="inf-counter"><div class="inf-counter__row"><span class="inf-counter__n" id="inf_count">0</span><span class="inf-counter__t" id="inf_count_txt"></span></div><div class="inf-counter__bar"><div class="inf-counter__fill" id="inf_count_bar"></div></div></div>' +
                '<div class="inf-photos" id="inf_photos"></div>' +
                '<div class="inf-wiz__foot"><button type="button" class="btn btn-secondary" id="inf_photos_back">Back</button>' +
                '<span class="inf-wiz__meta" id="inf_photos_cost"></span>' +
                '<button type="button" class="btn btn-primary" id="inf_photos_train" disabled><i class="fa-solid fa-bolt"></i> ' + (retrain ? 'Retrain' : 'Train') + '</button></div>';
            $('#inf_panel').html(html);
            photos_load(function () { photos.forEach(function (img) { $('#inf_photos').append(photo_tile(img)); }); photos_counter(); });
            $('#inf_pick').on('click', function () { $('#inf_files').trigger('click'); });
            $('#inf_files').on('change', function () { Array.prototype.slice.call(this.files).forEach(upload_start); this.value = ''; });
            var $drop = $('#inf_drop');
            $drop.on('dragover', function (e) { e.preventDefault(); $drop.addClass('is-dragover'); })
                 .on('dragleave drop', function () { $drop.removeClass('is-dragover'); })
                 .on('drop', function (e) { e.preventDefault(); var f = e.originalEvent.dataTransfer.files; Array.prototype.slice.call(f).forEach(upload_start); });
            $('#inf_photos_back').on('click', function () { if (retrain) { window.location = '/influencers'; } else { go_back('photos'); } });
            $('#inf_photos_train').on('click', function () {
                var $b = $(this).prop('disabled', true);
                api('influencer_train', { id: inf.id }, function (o) {
                    if (o && o.success) { inf = o.influencer; toastr.success('Training started'); show('training'); }
                    else { err(o); $b.prop('disabled', false); }
                });
            });
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
        /* --- Path B: Input --- */
        function render_input() {
            var html = '<h2 class="inf-wiz__h">How should her reference be made?</h2>' +
                '<p class="inf-wiz__p">The reference image is the face every training image is built from.</p>' +
                '<div class="inf-grid">' +
                '<div class="inf-field inf-field--full"><div class="inf-label">Input</div>' + seg_html('inf_input_seg', [{ value: 'text', label: 'Describe her', icon: 'fa-pen-nib' }, { value: 'face_photo', label: 'Upload a face photo', icon: 'fa-image-portrait' }], inf.input_method || 'text') + '</div>' +
                '<div class="inf-field"><div class="inf-label">Public gallery</div>' + seg_html('inf_public_seg', [{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }], String(inf.is_public || 0)) + '</div>' +
                '</div>' +
                '<div class="inf-wiz__foot"><button type="button" class="btn btn-secondary" id="inf_input_back">Back</button>' +
                '<button type="button" class="btn btn-primary" id="inf_input_next">Continue <i class="fa-solid fa-arrow-right"></i></button></div>';
            $('#inf_panel').html(html);
            seg_bind('inf_input_seg'); seg_bind('inf_public_seg');
            $('#inf_input_back').on('click', function () { go_back('input'); });
            $('#inf_input_next').on('click', function () {
                api('influencer_save_step', { id: inf.id, input_method: seg_value('inf_input_seg'), is_public: seg_value('inf_public_seg'), step: 'reference' }, function (o) {
                    if (o && o.success) { inf = o.influencer; show('reference'); } else { err(o); }
                });
            });
        }

        /* --- Path B: Reference (text -> generate, or face photo upload) --- */
        var ref_stop = null;
        function render_reference() {
            if (ref_stop) { ref_stop(); ref_stop = null; }
            if (inf.input_method === 'face_photo') { return render_reference_photo(); }
            var faces = (C.prompts && C.prompts.face) || [];
            var html = '<h2 class="inf-wiz__h">Her reference image</h2>' +
                '<p class="inf-wiz__p">This choice affects only the reference image, nothing generated later.</p>' +
                '<div class="inf-grid">' +
                '<div class="inf-field inf-field--full"><div class="inf-label">Render with</div>' + opts_html('inf_ref_model', C.pickers.reference || [], inf.reference_model_key) + '</div>' +
                '<div class="inf-field inf-field--full"><label class="inf-label" for="inf_ref_desc">Face description</label><textarea class="form-control" id="inf_ref_desc" maxlength="2000" placeholder="Portrait photo of a woman in her mid 20s, long dark wavy hair, brown eyes, soft freckles, natural makeup, neutral background">' + esc(inf.source_description) + '</textarea></div>' +
                (faces.length ? '<div class="inf-field inf-field--full"><div class="inf-label">Prebuilt</div><div class="inf-chips">' + faces.map(function (t, i) { return '<button type="button" class="inf-chip inf-chip--text" data-i="' + i + '" title="' + esc(t) + '">' + esc(t.slice(0, 70)) + (t.length > 70 ? '…' : '') + '</button>'; }).join('') + '</div></div>' : '') +
                '</div>' +
                '<div class="inf-ref" id="inf_ref_out" hidden><div class="inf-label">Reference</div><div class="inf-ref__row" id="inf_ref_row"></div></div>' +
                '<div class="inf-wiz__foot"><button type="button" class="btn btn-secondary" id="inf_ref_back">Back</button>' +
                '<span class="inf-wiz__meta" id="inf_ref_status"></span>' +
                '<button type="button" class="btn btn-secondary" id="inf_ref_gen" hidden><i class="fa-solid fa-wand-magic-sparkles"></i> Generate another</button>' +
                '<button type="button" class="btn btn-primary" id="inf_ref_gen_primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate reference</button>' +
                '<button type="button" class="btn btn-primary" id="inf_ref_use" hidden><i class="fa-solid fa-check"></i> Use this reference</button></div>';
            $('#inf_panel').html(html);
            opts_bind('inf_ref_model');
            $('#inf_panel').on('click', '.inf-chip--text', function () { $('#inf_ref_desc').val(faces[$(this).data('i')]).trigger('focus'); });
            $('#inf_ref_back').on('click', function () { go_back('reference'); });
            var selected = inf.reference_asset_id || 0;
            function paint(images) {
                var $row = $('#inf_ref_row').empty();
                if (!images.length) { $('#inf_ref_out').prop('hidden', true); return; }
                $('#inf_ref_out').prop('hidden', false);
                images.forEach(function (img) {
                    var $t = $('<button type="button" class="inf-photo inf-photo--pick' + (img.id === selected ? ' is-on' : '') + '">').attr('data-id', img.id).append('<img src="' + esc(img.thumb_url) + '" alt="">');
                    $t.on('click', function () { selected = img.id; $('#inf_ref_row .inf-photo').removeClass('is-on'); $t.addClass('is-on'); });
                    $row.append($t);
                });
                if (!selected) { selected = images[images.length - 1].id; $row.find('[data-id="' + selected + '"]').addClass('is-on'); }
                $('#inf_ref_use').prop('hidden', false); $('#inf_ref_gen').prop('hidden', false); $('#inf_ref_gen_primary').prop('hidden', true);
            }
            api('influencer_images', { id: inf.id, role: 'reference' }, function (o) { if (o && o.success && o.images.length) { paint(o.images); } });
            function generate() {
                var desc = $('#inf_ref_desc').val().trim();
                if (desc == '') { toastr.error('Describe her face first'); return; }
                if (!C.enabled) { toastr.info('Rendering is not configured yet'); return; }
                $('#inf_ref_gen, #inf_ref_gen_primary, #inf_ref_use').prop('disabled', true);
                $('#inf_ref_status').html('<span class="spinner-border spinner-border-sm"></span> Generating');
                api('influencer_reference_generate', { id: inf.id, source_description: desc, reference_model_key: opts_value('inf_ref_model') }, function (o) {
                    if (!o || !o.success) { err(o); $('#inf_ref_gen, #inf_ref_gen_primary, #inf_ref_use').prop('disabled', false); $('#inf_ref_status').text(''); return; }
                    ref_stop = poll_job(o.job_id, function (j) { $('#inf_ref_status').html('<span class="spinner-border spinner-border-sm"></span> ' + esc(status_text(j))); }, function (j) {
                        $('#inf_ref_gen, #inf_ref_gen_primary, #inf_ref_use').prop('disabled', false); $('#inf_ref_status').text('');
                        if (j.status !== 'done') { toastr.error(j.error || 'Generation failed'); return; }
                        selected = 0;
                        api('influencer_images', { id: inf.id, role: 'reference' }, function (o2) { if (o2 && o2.success) { paint(o2.images); } });
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
            var html = '<h2 class="inf-wiz__h">Her face photo</h2>' +
                '<p class="inf-wiz__p">One clear, front-facing photo of her face. It becomes the reference for the training set.</p>' +
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
            var html = '<h2 class="inf-wiz__h">Her training set</h2>' +
                '<p class="inf-wiz__p">' + size + ' square images of her from the reference, different angles, expressions and light.</p>' +
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
                Swal.fire({ title: 'Start the set over?', text: 'The current images are set aside and ' + size + ' new ones are generated.', icon: 'question', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Start over', confirmButtonColor: '#5b4be0', cancelButtonColor: '#6b6779' })
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
                '<p class="inf-wiz__p">' + (retrain ? 'Her current model keeps working until the new one finishes. ' : '') + 'Training takes a few minutes and runs once; afterwards she generates on demand.</p>' +
                '<div class="inf-summary"><span><strong id="inf_rev_n">…</strong> images</span><span><strong>' + money(C.training_cost_usd) + '</strong> estimated</span><span><strong>' + esc(LIM.steps) + '</strong> steps</span></div>' +
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

        var RENDER = { name: render_name, photos: render_photos, input: render_input, reference: render_reference, set: render_set, review: render_review, training: render_training, done: render_done };
        window.INF_WIZ = { register: function (step, fn) { RENDER[step] = fn; }, get: function () { return inf; }, set: function (v) { inf = v; }, show: function (s) { show(s); }, back: go_back, steps: visible_steps, path: function () { return path; } };

        function show(step) {
            clearTimeout(poll_timer);
            render_steps(path, step);
            (RENDER[step] || function () { render_pending(step); })();
        }

        if (inf) {
            path = inf.path;
            if (!path) { $('#inf_wizard').prop('hidden', true); return; }
            // Switch path (before training only).
            if (['draft', 'awaiting_reference', 'failed'].indexOf(inf.status) >= 0 && inf.pending_model_id <= 0) {
                set_actions('<button type="button" class="btn btn-outline-secondary btn-sm" id="inf_switch">Switch path</button>');
                $('#inf_switch').on('click', function () {
                    var other = path === 'photos' ? 'reference' : 'photos';
                    Swal.fire({ title: 'Switch to ' + (other === 'photos' ? 'Train from your photos' : 'Text or single image') + '?', text: 'Your name and anything already uploaded are kept.', icon: 'question', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Switch', confirmButtonColor: '#5b4be0', cancelButtonColor: '#6b6779' })
                        .then(function (r) { if (r.isConfirmed) { api('influencer_save_step', { id: inf.id, path: other, step: 'name' }, function (o) { if (o && o.success) { window.location.reload(); } else { err(o); } }); } });
                });
            }
            if (CFG.retrain && inf.status === 'ready' && inf.pending_model_id <= 0) { show(path === 'photos' ? 'photos' : 'review'); return; }
            show(inf.wizard_step || 'name');
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
        var prompts = (C.prompts && C.prompts.image) || [];

        function seg_pick(id) { $('#' + id).on('click', '.inf-seg__opt', function () { $('#' + id + ' .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true'); cost(); }); }
        function seg_val(id) { return $('#' + id + ' .inf-seg__opt.is-on').data('value'); }
        function seg_set(id, v) { $('#' + id + ' .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false').filter('[data-value="' + v + '"]').addClass('is-on').attr('aria-pressed', 'true'); }
        function model_key() { return $('#inf_model .inf-opt.is-on').data('key') || ''; }
        function model_label(key) { var m = (C.pickers.image || []).filter(function (o) { return o.key === key; })[0]; return m ? m.label : key; }
        function cost() {
            var m = (C.pickers.image || []).filter(function (o) { return o.key === model_key(); })[0];
            var n = parseInt(seg_val('inf_n'), 10) || 1;
            $('#inf_gen_cost').text(m ? money(m.price_usd * n) + ' estimated' : '');
        }

        $('#inf_who').on('change', function () { window.location = '/influencers/images/' + this.value; });
        $('[data-copy]').on('click', function () { var t = $(this).data('copy'); if (navigator.clipboard) { navigator.clipboard.writeText(t); toastr.success('Copied'); } });
        $('#inf_model').on('click', '.inf-opt', function () { $('#inf_model .inf-opt').removeClass('is-on'); $(this).addClass('is-on'); cost(); });
        seg_pick('inf_size'); seg_pick('inf_n'); seg_pick('inf_level');
        $('#inf_prompt_chips').on('click', '.inf-chip--text', function () { $('#inf_prompt').val(inf.trigger_word + ' ' + prompts[$(this).data('i')]).trigger('focus'); });
        $('#inf_prompt_auto').on('click', function () {
            var $b = $(this).prop('disabled', true);
            api('influencer_prompt_auto', { id: inf.id, hint: $('#inf_prompt').val().trim() }, function (o) {
                $b.prop('disabled', false);
                if (o && o.success) { $('#inf_prompt').val(o.prompt).trigger('focus'); } else { err(o); }
            });
        });
        cost();

        /* --- submit --- */
        function generate(overrides) {
            var prompt = $('#inf_prompt').val().trim();
            if (prompt == '') { toastr.error('Write a prompt first'); return; }
            if (inf.trigger_word && prompt.indexOf(inf.trigger_word) < 0) { toastr.info('Add her trigger word ' + inf.trigger_word + ' to get her'); }
            if (!C.enabled) { toastr.info('Rendering is not configured yet'); return; }
            var body = $.extend({ id: inf.id, prompt: prompt, model_key: model_key(), image_size: seg_val('inf_size'), num_images: seg_val('inf_n'), level: seg_val('inf_level'),
                seed: $('#inf_seed').val().trim(), lora_scale: $('#inf_lora').val().trim(), guidance: $('#inf_guidance').val().trim(), steps: $('#inf_steps').val().trim() }, overrides || {});
            $('#inf_gen_go, #inf_res_again').prop('disabled', true);
            busy('Sending');
            api('influencer_generate_image', body, function (o) {
                if (!o || !o.success) { err(o); $('#inf_gen_go, #inf_res_again').prop('disabled', false); show_current(); return; }
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
            $('#inf_res_seed').val(current.job.result_seed || current.job.seed);
            $('#inf_res_prompt').val(current.job.prompt);
            $('#inf_res_model').text(model_label(current.job.model_key));
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
                } else if (j.status !== 'cancelled') {
                    $s.append('<span class="inf-strip__item inf-strip__item--busy"><span class="spinner-border spinner-border-sm"></span></span>');
                }
            });
        }
        api('influencer_jobs_list', { id: inf.id, type: 'image,enhance', limit: 24 }, function (o) {
            if (!o || !o.success) { return; }
            jobs = o.jobs || []; render_strip();
            var running = jobs.filter(function (j) { return ['queued', 'submitting', 'running', 'landing'].indexOf(j.status) >= 0; })[0];
            if (running) { busy(status_text(running)); watch(running.id); return; }
            var last = jobs.filter(function (j) { return j.assets && j.assets.length; })[0];
            if (last) { select(last, last.assets[0]); }
        });

        /* --- result actions --- */
        $('#inf_res_again').on('click', function () {
            // Re-run with the edited prompt and pinned seed; other settings follow the form.
            $('#inf_prompt').val($('#inf_res_prompt').val());
            $('#inf_seed').val($('#inf_res_seed').val().trim());
            seg_set('inf_size', current.job.params.image_size || 'square');
            generate({ prompt: $('#inf_res_prompt').val().trim(), seed: $('#inf_res_seed').val().trim(), image_size: current.job.params.image_size || 'square', model_key: current.job.model_key });
        });
        $('#inf_res_video').on('click', function () { if (current) { window.location = '/influencers/videos/' + inf.id + '/' + current.asset.id; } });
        $('#inf_res_download').on('click', function () {
            if (!current) { return; }
            api('influencer_asset_url', { asset_id: current.asset.id }, function (o) { if (o && o.success) { window.open(o.url, '_blank'); } else { err(o); } });
        });
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
        function cost() { var m = model_opt(model_key()); var d = parseInt(seg_val('inf_vdur'), 10) || 5; $('#inf_vcost').text(m ? money(m.price_usd * d) + ' estimated' : ''); }
        $('#inf_who').on('change', function () { window.location = '/influencers/videos/' + this.value; });
        $('#inf_vmodel').on('click', '.inf-opt', function () { $('#inf_vmodel .inf-opt').removeClass('is-on'); $(this).addClass('is-on'); durations(); });
        $('#inf_vdur').on('click', '.inf-seg__opt', function () { $('#inf_vdur .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true'); cost(); });
        $('#inf_vlevel').on('click', '.inf-seg__opt', function () { $('#inf_vlevel .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true'); });
        durations();

        /* stills: every image of hers, newest generated first */
        api('influencer_images', { id: inf.id }, function (o) {
            var $g = $('#inf_stills').empty();
            var imgs = (o && o.success) ? o.images.filter(function (x) { return x.type === 'image' && x.status === 'ready' && x.thumb_url; }) : [];
            var order = { generated: 0, enhanced: 1, reference: 2, upload: 3, training: 4, face: 5 };
            imgs.sort(function (a, b) { return (order[a.role] - order[b.role]) || (b.id - a.id); });
            if (!imgs.length) { $g.html('<span class="inf-wiz__meta">No images of her yet. Generate one first.</span>'); return; }
            imgs.forEach(function (img) {
                var $t = $('<button type="button" class="inf-photo inf-photo--pick' + (img.id === still ? ' is-on' : '') + '">').attr('data-id', img.id).append('<img src="' + esc(img.thumb_url) + '" alt="">');
                $t.on('click', function () { still = img.id; $('#inf_stills .inf-photo').removeClass('is-on'); $t.addClass('is-on'); $('#inf_vgo').prop('disabled', false); });
                $g.append($t);
            });
            if (!still || !$g.find('.is-on').length) { still = imgs[0].id; $g.find('.inf-photo').first().addClass('is-on'); }
            $('#inf_vgo').prop('disabled', false);
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
                } else if (j.status !== 'cancelled') { $s.append('<span class="inf-strip__item inf-strip__item--busy"><span class="spinner-border spinner-border-sm"></span></span>'); }
            });
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
            var last = jobs.filter(function (j) { return j.assets && j.assets.length; })[0];
            if (last) { select(last, last.assets[0]); }
        });
        $('#inf_vgo').on('click', function () {
            if (!still) { toastr.error('Pick a still of her first'); return; }
            if (!C.enabled) { toastr.info('Rendering is not configured yet'); return; }
            var $b = $(this).prop('disabled', true);
            busy('Sending');
            api('influencer_generate_video', { id: inf.id, asset_id: still, prompt: $('#inf_vprompt').val().trim(), model_key: model_key(), duration: seg_val('inf_vdur'), level: seg_val('inf_vlevel') }, function (o) {
                if (!o || !o.success) { err(o); $b.prop('disabled', false); show_current(); return; }
                jobs.unshift(o.job); render_strip(); watch(o.job.id);
            });
        });
        $('#inf_vres_download').on('click', function () { if (!current) { return; } api('influencer_asset_url', { asset_id: current.asset.id }, function (o) { if (o && o.success) { window.open(o.url, '_blank'); } else { err(o); } }); });
        $('#inf_vres_post').on('click', function () { if (!current) { return; } try { sessionStorage.setItem('cs_open_asset', String(current.asset.id)); } catch (e) {} window.location = '/studio'; });
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
            $('#inf_lightbox').prop('hidden', false);
        }
        function close_lightbox() { $('#inf_lightbox').prop('hidden', true); var v = document.getElementById('inf_lightbox_video'); v.pause(); }
        $('#inf_gal_roles').on('click', '.inf-chip', function () { $('#inf_gal_roles .inf-chip').removeClass('is-on'); $(this).addClass('is-on'); role = $(this).data('role') || ''; load(); });
        $('#inf_lightbox_close').on('click', close_lightbox);
        $('#inf_lightbox').on('click', function (e) { if (e.target === this) { close_lightbox(); } });
        $(document).on('keydown', function (e) { if (e.key === 'Escape') { close_lightbox(); } });
        $('#inf_lightbox_download').on('click', function () { if (!current) { return; } api('influencer_asset_url', { asset_id: current.id }, function (o) { if (o && o.success) { window.open(o.url, '_blank'); } else { err(o); } }); });
        $('#inf_lightbox_post').on('click', function () { if (!current) { return; } try { sessionStorage.setItem('cs_open_asset', String(current.id)); } catch (e) {} window.location = '/studio'; });
        load();
    }

    if (page === 'index')  { init_index(); }
    if (page === 'create') { init_create(); }
    if (page === 'images') { init_images(); }
    if (page === 'videos') { init_videos(); }
    if (page === 'gallery') { init_gallery(); }
});
