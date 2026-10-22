/* Shared pieces for the AI image and video tools (Replicate, Edit, Carousel, Scenes, angle references).
   window.AiTools: api + error handling, the AI credit line, job polling, a Library image picker and the
   Edit By Instruction window. Loaded on /studio and /influencers pages after api.data.js.
   AJAX goes through ApiDataSvc.apiCall (string responses, JSON.parse here). snake_case throughout. */
window.AiTools = (function ($) {
    "use strict";

    var balance = 0;

    /**
     * Put a list of tiles into $el without rebuilding the ones that have not changed. Pages that check progress
     * every few seconds would otherwise reload every finished image on each check (signed image links change
     * each time), which shows as a flash. A tile is "the same" when its markup matches once link signatures are ignored.
     */
    function patch_children($el, html) {
        var $new = $('<div>').html(html).children(), $old = $el.children();
        $new.each(function (i) {
            var sig = this.outerHTML.replace(/\?[^"'\s>]*/g, ''), old = $old.get(i);
            if (old && old._sig === sig) { return; }
            this._sig = sig;
            if (old) { $(old).replaceWith(this); } else { $el.append(this); }
        });
        $old.slice($new.length).remove();
    }

    function esc(s) { return $('<div>').text(s == null ? '' : s).html().replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

    function api(endpoint, body, cb) {
        ApiDataSvc.apiCall('post', endpoint, body || {}, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) {}
            if (o && typeof o.ai_credits !== 'undefined') { balance = parseInt(o.ai_credits, 10) || 0; }
            cb(o);
        });
    }

    /* Plan and credit refusals link to Billing; everything else is a plain toast. */
    function err(o, fallback) {
        if (window.cls_need_plan && window.cls_need_plan(o)) { return; }   // the upgrade prompt (site.js)
        if (o && (o.need_credits || o.need_upgrade || o.need_plan)) {
            if (o.need_credits && typeof o.balance !== 'undefined') { balance = parseInt(o.balance, 10) || 0; }
            var held = parseInt(window.CLS_TRIAL_HELD, 10) || 0;   // a no-card trial: the fix is a card, not a purchase
            toastr.error(o.message + (o.need_credits && held > 0 ? ' Add a card to unlock ' + held.toLocaleString() + ' more AI credits. Nothing is charged today.' : ''), o.need_plan ? 'Choose a plan' : (o.need_credits ? (held > 0 ? 'Add a card' : 'Buy AI credits') : 'Upgrade your plan'),
                { timeOut: 8000, extendedTimeOut: 4000, onclick: function () { window.location.href = o.need_credits ? (held > 0 ? '/account/billing?add_card=1' : '/account/billing?tab=credits') : (o.need_plan && window.cls_plan_url ? window.cls_plan_url(o) : '/account/billing'); } });
            return;
        }
        toastr.error((o && o.message) || fallback || 'Something went wrong. Please try again.');
    }

    /* The balance is shown once, in the account menu at the top right: keep that number current. */
    function show_balance() { $('#app_ai_credits').text(Number(balance).toLocaleString()); }
    /* "110 AI credits": what this action costs, plus a Buy link once the balance cannot cover two more runs. */
    function credits_html(n) {
        n = parseInt(n, 10) || 0;
        var low = balance < n * 2;
        show_balance();
        return esc(Number(n).toLocaleString() + ' AI credits') +
            (low ? ' ' + ((window.cls_credits_link && window.cls_credits_link(balance < n)) || '<a class="ai-buy" href="/account/billing?tab=credits">' + (balance < n ? 'Not enough left. Buy AI Credits' : 'Running low. Buy AI Credits') + '</a>') : '');
    }
    function can_afford(n) { return balance >= (parseInt(n, 10) || 0); }

    /* Poll one job until it is terminal. Every tick has a watchdog: no answer within 20s means ask again. */
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
        if (j.status === 'landing') { return 'Saving'; }
        return 'Generating';
    }

    /* =====================================================================
     * Library image picker: pick_image({ title, influencer }, function (asset) {})
     * =================================================================== */
    var $picker = null, picker_cb = null, picker_assets = [], picker_filter = { search: '', influencer: 0, type: 'image' };
    function clock(secs) { secs = parseInt(secs, 10) || 0; return Math.floor(secs / 60) + ':' + ('0' + (secs % 60)).slice(-2); }

    function picker_build() {
        if ($picker) { return; }
        $picker = $(
            '<div class="modal fade ai-modal" id="aiPicker" tabindex="-1" aria-labelledby="aiPickerTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">' +
            '<div class="modal-header"><h2 class="modal-title ai-modal__title" id="aiPickerTitle">Choose an Image</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
            '<div class="modal-body">' +
              '<div class="ai-picker__bar"><input type="search" class="form-control" id="aiPickerSearch" placeholder="Search your library" aria-label="Search your library">' +
              '<button type="button" class="btn btn-secondary" id="aiPickerUpload"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Upload</button>' +
              '<input type="file" id="aiPickerFile" accept="image/jpeg,image/png,image/webp" hidden></div>' +
              '<p class="ai-error" id="aiPickerNote" hidden></p>' +
              '<div class="ai-picker__state" id="aiPickerState" role="status"></div>' +
              '<div class="ai-picker__grid" id="aiPickerGrid"></div>' +
            '</div></div></div></div>').appendTo('body');
        var typing = null;
        $picker.on('input', '#aiPickerSearch', function () { var v = $(this).val(); clearTimeout(typing); typing = setTimeout(function () { picker_filter.search = v; picker_load(); }, 300); });
        $picker.on('click', '.ai-picker__item', function () {
            var id = parseInt($(this).data('id'), 10), a = null;
            $.each(picker_assets, function (i, x) { if (x.id === id) { a = x; } });
            if (!a) { return; }
            bootstrap.Modal.getOrCreateInstance($picker[0]).hide();
            if (picker_cb) { picker_cb(a); }
        });
        $picker.on('click', '#aiPickerUpload', function () { $('#aiPickerFile').val('').trigger('click'); });
        $picker.on('change', '#aiPickerFile', function () {
            var file = this.files && this.files[0];
            if (!file) { return; }
            if (String(file.type).indexOf('video') === 0) {   // videos go up in parts (resumable), like Content Studio's uploads
                picker_state('<span class="spinner-border spinner-border-sm text-primary"></span> <span id="aiPickerPct">Uploading</span>');
                $('#aiPickerUpload').prop('disabled', true);
                upload_video(file, function (pct) { $('#aiPickerPct').text('Uploading ' + pct + '%'); }, function (asset, msg) {
                    $('#aiPickerUpload').prop('disabled', false);
                    if (!asset) { picker_state(''); toastr.error(msg || 'Upload failed. Try again.'); return; }
                    bootstrap.Modal.getOrCreateInstance($picker[0]).hide();
                    if (picker_cb) { picker_cb(asset); }
                });
                return;
            }
            var audio = picker_filter.type === 'audio';
            if (file.size > (audio ? 30 : 15) * 1048576) { toastr.error(audio ? 'That audio file is too large. Audio can be up to 30 MB.' : 'That image is too large. Images can be up to 15 MB.'); return; }
            var fd = new FormData(); fd.append('file', file);
            picker_state('<span class="spinner-border spinner-border-sm text-primary"></span> Uploading');
            $('#aiPickerUpload').prop('disabled', true);
            $.ajax({ url: '/api/media_upload', method: 'POST', data: fd, dataType: 'json', processData: false, contentType: false })
                .done(function (o) {
                    if (o && o.success && o.asset) { bootstrap.Modal.getOrCreateInstance($picker[0]).hide(); if (picker_cb) { picker_cb(o.asset); } }
                    else { picker_state(''); err(o, 'Upload failed. Try again.'); }
                })
                .fail(function () { picker_state(''); toastr.error('Upload failed. Check your connection.'); })
                .always(function () { $('#aiPickerUpload').prop('disabled', false); });
        });
    }
    function picker_state(html) { $('#aiPickerState').html(html).prop('hidden', html === ''); }
    function picker_load() {
        picker_state('<span class="spinner-border spinner-border-sm text-primary"></span> Loading your library');
        $('#aiPickerGrid').empty();
        var video = picker_filter.type === 'video', audio = picker_filter.type === 'audio';
        var body = { type: picker_filter.type === 'media' ? '' : picker_filter.type, search: picker_filter.search };
        if (picker_filter.influencer) { body.influencer = picker_filter.influencer; }
        api('media_list', body, function (o) {
            if (!o || !o.success) { picker_state('<span class="ai-picker__err">Could not load your library. <button type="button" class="btn btn-link p-0" id="aiPickerRetry">Try Again</button></span>'); $('#aiPickerRetry').on('click', picker_load); return; }
            picker_assets = (o.assets || []).filter(function (a) { return a.status === 'ready' && (a.thumb_url || a.type === 'audio') && a.moderation !== 'blocked' && (picker_filter.type !== 'media' || a.type !== 'audio'); });
            if (!picker_assets.length) {
                picker_state(picker_filter.search ? 'Nothing matches that search.' : (video ? 'No videos in your library yet. Upload one to start.' : (audio ? 'No audio in your library yet. Upload a file to start.' : 'No images in your library yet. Upload one to start.')));
                return;
            }
            picker_state('');
            $('#aiPickerGrid').toggleClass('ai-picker__grid--list', audio);
            if (audio) {
                $('#aiPickerGrid').html(picker_assets.map(function (a) {
                    return '<button type="button" class="ai-picker__item ai-picker__row" data-id="' + a.id + '"><i class="fa-solid fa-music" aria-hidden="true"></i><span>' + esc(a.name) + '</span><em>' + clock(a.duration) + '</em></button>';
                }).join(''));
                return;
            }
            $('#aiPickerGrid').html(picker_assets.map(function (a) {
                return '<button type="button" class="ai-picker__item" data-id="' + a.id + '" title="' + esc(a.name) + '"><img src="' + esc(a.thumb_url) + '" alt="' + esc(a.name) + '" loading="lazy">' +
                    ((video || a.type === 'video') ? '<span class="ai-picker__len"><i class="fa-solid fa-play" aria-hidden="true"></i> ' + clock(a.duration) + '</span>' : '') + '</button>';
            }).join(''));
        });
    }
    /* opts: { title, influencer, type: 'image' (default) | 'video' | 'audio' | 'media' }. Every type can be uploaded right here. */
    function pick_image(opts, cb) {
        picker_build();
        opts = opts || {};
        picker_cb = cb;
        picker_filter = { search: '', influencer: parseInt(opts.influencer, 10) || 0, type: (opts.type === 'video' || opts.type === 'audio' || opts.type === 'media') ? opts.type : 'image' };
        $('#aiPickerTitle').text(opts.title || (picker_filter.type === 'video' ? 'Choose a Video' : (picker_filter.type === 'audio' ? 'Choose an Audio File' : (picker_filter.type === 'media' ? 'Choose a Clip Or Image' : 'Choose an Image'))));
        $('#aiPickerUpload').prop('hidden', false);
        $('#aiPickerFile').attr('accept', { audio: 'audio/mpeg,audio/wav,audio/x-wav,audio/mp4,.mp3,.wav,.m4a', video: 'video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm',
            media: 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm' }[picker_filter.type] || 'image/jpeg,image/png,image/webp');
        $('#aiPickerSearch').val('');
        bootstrap.Modal.getOrCreateInstance($picker[0]).show();
        picker_load();
    }

    /* =====================================================================
     * Edit By Instruction: edit({ id, thumb_url, name }, function (new_asset, job) {})
     * =================================================================== */
    var $edit = null, edit_asset = null, edit_models = [], edit_cb = null, edit_stop = null, edit_can = true;

    function edit_build() {
        if ($edit) { return; }
        $edit = $(
            '<div class="modal fade ai-modal" id="aiEdit" tabindex="-1" aria-labelledby="aiEditTitle" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">' +
            '<div class="modal-header"><h2 class="modal-title ai-modal__title" id="aiEditTitle">Edit Image</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
            '<div class="modal-body"><div class="ai-edit">' +
              '<figure class="ai-edit__img"><img id="aiEditImg" alt=""><div class="ai-edit__busy" id="aiEditBusy" hidden><span class="spinner-border text-primary" role="status"></span><p id="aiEditBusyText">Generating</p></div></figure>' +
              '<div class="ai-edit__form">' +
                '<div class="ai-field"><label class="ai-label" for="aiEditInstruction">Change</label>' +
                '<textarea class="form-control" id="aiEditInstruction" rows="4" placeholder="Make the dress red"></textarea>' +
                '<p class="ai-error" id="aiEditError" role="alert" hidden></p></div>' +
                '<div class="ai-field"><div class="ai-label" id="aiEditModelLabel">Model</div><div class="ai-opts" id="aiEditModels" role="group" aria-labelledby="aiEditModelLabel"></div></div>' +
              '</div>' +
            '</div></div>' +
            '<div class="modal-footer"><span class="ai-cost" id="aiEditCost"></span>' +
              '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="aiEditCancel">Cancel</button>' +
              '<button type="button" class="btn btn-primary" id="aiEditGo">Apply Edit</button></div>' +
            '</div></div></div>').appendTo('body');
        $edit.on('click', '.ai-opt', function () { if ($(this).prop('disabled')) { return; } $('#aiEditModels .ai-opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true'); edit_cost(); });
        $edit.on('input', '#aiEditInstruction', function () { $('#aiEditError').prop('hidden', true); $(this).removeClass('is-invalid'); });
        $edit.on('click', '#aiEditGo', edit_run);
        $edit.on('hidden.bs.modal', function () { if (edit_stop) { edit_stop(); edit_stop = null; } });
    }
    function edit_model() {
        var key = $('#aiEditModels .ai-opt.is-on').data('key'), m = null;
        $.each(edit_models, function (i, x) { if (x.key === key) { m = x; } });
        return m;
    }
    function edit_cost() {
        var m = edit_model(), price = m ? m.credits : 0;
        if (!edit_can) { $('#aiEditCost').html('<a class="ai-buy" href="' + esc(window.cls_plan_url ? window.cls_plan_url({}) : '/account/billing') + '">Choose a Plan to Edit</a>'); $('#aiEditGo').prop('disabled', true); return; }
        $('#aiEditCost').html(m ? credits_html(price) : '');
        $('#aiEditGo').prop('disabled', !m || !can_afford(price));
    }
    function edit_busy(on, text) {
        $('#aiEditBusy').prop('hidden', !on);
        if (text) { $('#aiEditBusyText').text(text); }
        $('#aiEditInstruction, #aiEditModels .ai-opt').prop('disabled', on);
        $('#aiEditGo').prop('disabled', on).html(on ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Editing' : 'Apply Edit');
        if (!on) { edit_cost(); }
    }
    function edit_fail(msg) { $('#aiEditError').text(msg).prop('hidden', false); }
    function edit_run() {
        var text = String($('#aiEditInstruction').val() || '').trim(), m = edit_model();
        if (text === '') { $('#aiEditInstruction').addClass('is-invalid').trigger('focus'); edit_fail('Describe the change you want.'); return; }
        if (!m) { return; }
        $('#aiEditError').prop('hidden', true);
        edit_busy(true, 'Sending');
        api('media_edit', { asset_id: edit_asset.id, instruction: text, model_key: m.key }, function (o) {
            if (!o || !o.success) {
                edit_busy(false);
                if (o && (o.need_credits || o.need_plan || o.need_upgrade)) { err(o); edit_cost(); } else { edit_fail((o && o.message) || 'Could not start the edit. Try again.'); }
                return;
            }
            edit_cost();
            edit_stop = poll_job(o.job_id, function (j) { $('#aiEditBusyText').text(status_text(j)); }, function (j) {
                edit_stop = null;
                api('media_edit_options', {}, function (r) { if (r && r.success) { balance = parseInt(r.ai_credits, 10) || 0; } edit_busy(false); });
                if (j.status !== 'done' || !j.assets || !j.assets.length) { edit_busy(false); edit_fail((j.error || 'The edit failed.') + ' Your AI credits were returned.'); return; }
                var made = j.assets[0];
                // The result becomes the image being edited, so the next change builds on it.
                edit_asset = { id: made.id, thumb_url: made.display_url || made.thumb_url, name: edit_asset.name };
                $('#aiEditImg').attr('src', edit_asset.thumb_url);
                $('#aiEditInstruction').val('');
                $('#aiEditCancel').text('Done');
                toastr.success('Saved to your Library as a new version');
                if (edit_cb) { edit_cb(made, j); }
            });
        });
    }
    function edit(asset, cb) {
        edit_build();
        edit_asset = asset; edit_cb = cb || null;
        $('#aiEditImg').attr('src', asset.display_url || asset.thumb_url || '').attr('alt', asset.name || '');
        $('#aiEditInstruction').val('').removeClass('is-invalid');
        $('#aiEditError').prop('hidden', true);
        $('#aiEditCancel').text('Cancel');
        $('#aiEditModels').html('<span class="ai-cost"><span class="spinner-border spinner-border-sm text-primary"></span> Loading</span>');
        $('#aiEditCost').empty(); $('#aiEditGo').prop('disabled', true).text('Apply Edit');
        $('#aiEditBusy').prop('hidden', true);
        bootstrap.Modal.getOrCreateInstance($edit[0]).show();
        api('media_edit_options', { asset_id: asset.id }, function (o) {
            if (!o || !o.success) { $('#aiEditModels').html('<span class="ai-error">Could not load the edit models. Close and try again.</span>'); return; }
            edit_models = o.models || []; edit_can = !!o.can_ai; balance = parseInt(o.ai_credits, 10) || 0;
            $('#aiEditModels').html(edit_models.map(function (m, i) {
                return '<button type="button" class="ai-opt' + (i === 0 ? ' is-on' : '') + '" aria-pressed="' + (i === 0 ? 'true' : 'false') + '" data-key="' + esc(m.key) + '">' +
                    '<span class="ai-opt__t">' + esc(m.label) + '</span><span class="ai-opt__p">' + esc(m.purpose) + ' · ' + Number(m.credits).toLocaleString() + ' AI credits' + (m.keeps_shape ? '' : ' · Reshapes to ' + esc(m.reshapes_to)) + '</span></button>';
            }).join(''));
            edit_cost();
            setTimeout(function () { $('#aiEditInstruction').trigger('focus'); }, 150);
        });
    }

    /* =====================================================================
     * Enhance Video: enhance_video({ id, video_url, thumb_url, name }, function (new_asset, job) {})
     * The models with this video's price on each (it follows the video's length); the result is a new Library video.
     * =================================================================== */
    var $env = null, env_asset = null, env_models = [], env_cb = null, env_stop = null, env_can = true;
    function env_build() {
        if ($env) { return; }
        $env = $(
            '<div class="modal fade ai-modal" id="aiEnv" tabindex="-1" aria-labelledby="aiEnvTitle" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">' +
            '<div class="modal-header"><h2 class="modal-title ai-modal__title" id="aiEnvTitle">Enhance Video</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
            '<div class="modal-body"><div class="ai-edit">' +
              '<figure class="ai-edit__img"><video id="aiEnvVideo" controls playsinline preload="metadata"></video><div class="ai-edit__busy" id="aiEnvBusy" hidden><span class="spinner-border text-primary" role="status"></span><p id="aiEnvBusyText">Enhancing</p></div></figure>' +
              '<div class="ai-edit__form">' +
                '<div class="ai-field"><div class="ai-label" id="aiEnvModelLabel">Model</div><div class="ai-opts" id="aiEnvModels" role="group" aria-labelledby="aiEnvModelLabel"></div>' +
                '<p class="ai-error" id="aiEnvError" role="alert" hidden></p></div>' +
              '</div>' +
            '</div></div>' +
            '<div class="modal-footer"><span class="ai-cost" id="aiEnvCost"></span>' +
              '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="aiEnvCancel">Cancel</button>' +
              '<button type="button" class="btn btn-primary" id="aiEnvGo">Enhance Video</button></div>' +
            '</div></div></div>').appendTo('body');
        $env.on('click', '.ai-opt', function () { if ($(this).prop('disabled')) { return; } $('#aiEnvModels .ai-opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true'); env_cost(); });
        $env.on('click', '#aiEnvGo', env_run);
        $env.on('hidden.bs.modal', function () { if (env_stop) { env_stop(); env_stop = null; } var v = $('#aiEnvVideo')[0]; if (v) { v.pause(); } });
    }
    function env_model() {
        var key = $('#aiEnvModels .ai-opt.is-on').data('key'), m = null;
        $.each(env_models, function (i, x) { if (x.key === key) { m = x; } });
        return m;
    }
    function env_cost() {
        var m = env_model();
        if (!env_can) { $('#aiEnvCost').html('<a class="ai-buy" href="' + esc(window.cls_plan_url ? window.cls_plan_url({}) : '/account/billing') + '">Choose a Plan to Enhance</a>'); $('#aiEnvGo').prop('disabled', true); return; }
        if (m && m.credits == null) { $('#aiEnvCost').text('Videos up to ' + m.max_seconds + ' seconds'); $('#aiEnvGo').prop('disabled', true); return; }
        $('#aiEnvCost').html(m ? credits_html(m.credits) : '');
        $('#aiEnvGo').prop('disabled', !m || !can_afford(m.credits));
    }
    function env_busy(on, text) {
        $('#aiEnvBusy').prop('hidden', !on);
        if (text) { $('#aiEnvBusyText').text(text); }
        $('#aiEnvModels .ai-opt').prop('disabled', on);
        $('#aiEnvGo').prop('disabled', on).html(on ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Enhancing' : 'Enhance Video');
        if (!on) { env_cost(); }
    }
    function env_fail(msg) { $('#aiEnvError').text(msg).prop('hidden', false); }
    function env_run() {
        var m = env_model();
        if (!m) { return; }
        $('#aiEnvError').prop('hidden', true);
        env_busy(true, 'Sending');
        api('media_enhance_video', { asset_id: env_asset.id, model_key: m.key }, function (o) {
            if (!o || !o.success) {
                env_busy(false);
                if (o && (o.need_credits || o.need_plan || o.need_upgrade)) { err(o); env_cost(); } else { env_fail((o && o.message) || 'Could not start the enhancement. Try again.'); }
                return;
            }
            balance = Math.max(0, balance - (parseInt(m.credits, 10) || 0)); show_balance();
            env_stop = poll_job(o.job_id, function (j) { $('#aiEnvBusyText').text(j.status === 'running' ? 'Enhancing' : status_text(j)); }, function (j) {
                env_stop = null;
                api('media_enhance_video_options', { asset_id: env_asset.id }, function (r) { if (r && r.success) { balance = parseInt(r.ai_credits, 10) || 0; } env_busy(false); });
                if (j.status !== 'done' || !j.assets || !j.assets.length) { env_busy(false); env_fail((j.error || 'The enhancement failed.') + ' Your AI credits were returned.'); return; }
                var made = j.assets[0];
                $('#aiEnvCancel').text('Done');
                toastr.success('Saved to your Library as a new version');
                if (env_cb) { env_cb(made, j); }
            });
        });
    }
    function enhance_video(asset, cb) {
        env_build();
        env_asset = asset; env_cb = cb || null;
        $('#aiEnvVideo').attr('src', asset.video_url || '').attr('poster', asset.thumb_url || '');
        $('#aiEnvError').prop('hidden', true);
        $('#aiEnvCancel').text('Cancel');
        $('#aiEnvModels').html('<span class="ai-cost"><span class="spinner-border spinner-border-sm text-primary"></span> Loading</span>');
        $('#aiEnvCost').empty(); $('#aiEnvGo').prop('disabled', true).text('Enhance Video');
        $('#aiEnvBusy').prop('hidden', true);
        bootstrap.Modal.getOrCreateInstance($env[0]).show();
        api('media_enhance_video_options', { asset_id: asset.id }, function (o) {
            if (!o || !o.success) { $('#aiEnvModels').html('<span class="ai-error">Could not load the enhance models. Close and try again.</span>'); return; }
            env_models = o.models || []; env_can = !!o.can_ai; balance = parseInt(o.ai_credits, 10) || 0;
            $('#aiEnvModels').html(env_models.map(function (m, i) {
                var price = m.credits == null ? 'Videos up to ' + m.max_seconds + ' seconds' : Number(m.credits).toLocaleString() + ' AI credits';
                return '<button type="button" class="ai-opt' + (i === 0 ? ' is-on' : '') + '" aria-pressed="' + (i === 0 ? 'true' : 'false') + '" data-key="' + esc(m.key) + '">' +
                    '<span class="ai-opt__t">' + esc(m.label) + '</span><span class="ai-opt__p">' + esc(m.purpose) + ' · ' + esc(price) + '</span></button>';
            }).join(''));
            env_cost();
        });
    }

    /* =====================================================================
     * Video upload in parts (media_upload_init / _chunk / _complete, the same flow as Content Studio's tray):
     * upload_video(file, on_progress(percent), cb(asset | null, message))
     * =================================================================== */
    function api_form(ep, fd) {
        var csrf = document.querySelector('meta[name="csrf-token"]');
        if (csrf && !fd.has('csrf_token')) { fd.append('csrf_token', csrf.getAttribute('content')); }
        return $.ajax({ url: '/api/' + ep, method: 'POST', data: fd, dataType: 'json', processData: false, contentType: false });
    }
    // One frame from the file and its size, so the server has a poster even when it cannot decode the video.
    function poster_frame(file, done) {
        var url = null, meta = { duration: 0, width: 0, height: 0 }, over = false;
        function finish(blob) { if (over) { return; } over = true; if (url) { URL.revokeObjectURL(url); } done(blob || null, meta); }
        try {
            url = URL.createObjectURL(file);
            var v = document.createElement('video'), timer = setTimeout(function () { finish(null); }, 8000);
            v.muted = true; v.playsInline = true; v.preload = 'auto'; v.src = url;
            v.addEventListener('error', function () { clearTimeout(timer); finish(null); });
            v.addEventListener('loadedmetadata', function () {
                meta = { duration: Math.round(v.duration || 0), width: v.videoWidth || 0, height: v.videoHeight || 0 };
                try { v.currentTime = Math.min(1, Math.max(0, (v.duration || 0) / 2)); } catch (e) { clearTimeout(timer); finish(null); }
            });
            v.addEventListener('seeked', function () {
                try {
                    var s = Math.min(1, 1280 / (v.videoWidth || 1)), c = document.createElement('canvas');
                    c.width = Math.round(v.videoWidth * s); c.height = Math.round(v.videoHeight * s);
                    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
                    c.toBlob(function (b) { clearTimeout(timer); finish(b); }, 'image/jpeg', 0.86);
                } catch (e) { clearTimeout(timer); finish(null); }
            });
        } catch (e) { finish(null); }
    }
    function upload_video(file, on_progress, cb) {
        api('media_upload_init', { filename: file.name, mime: file.type, bytes_total: file.size, client_token: file.name + '|' + file.size + '|' + file.lastModified }, function (init) {
            if (!init || !init.success) { cb(null, (init && init.message) || 'Could not start the upload.'); return; }
            var part = init.part_size || 8388608, total = Math.ceil(file.size / part), had = {};
            (init.uploaded_parts || []).forEach(function (p) { had[p] = true; });
            function send(p) {
                if (p > total) { return finish(); }
                if (had[p]) { return send(p + 1); }
                var fd = new FormData(); fd.append('session_id', init.session_id); fd.append('part_number', p); fd.append('chunk', file.slice((p - 1) * part, Math.min(p * part, file.size)));
                var tries = 0;
                (function attempt() {
                    tries++;
                    api_form('media_upload_chunk', fd).done(function (o) {
                        if (o && o.success) { on_progress(Math.min(96, Math.round(p / total * 96))); send(p + 1); }
                        else if (tries < 3) { setTimeout(attempt, 800 * tries); }
                        else { cb(null, (o && o.message) || 'Part of the upload failed. Try again.'); }
                    }).fail(function () { if (tries < 4) { setTimeout(attempt, 1000 * tries); } else { cb(null, 'The connection dropped. Try again to resume.'); } });
                })();
            }
            function finish() {
                on_progress(98);
                poster_frame(file, function (blob, meta) {
                    var fd = new FormData();
                    fd.append('session_id', init.session_id);
                    fd.append('client_duration', meta.duration); fd.append('client_width', meta.width); fd.append('client_height', meta.height);
                    if (blob) { fd.append('poster', blob, 'poster.jpg'); }
                    api_form('media_upload_complete', fd).done(function (o) {
                        if (o && o.success && o.asset) { on_progress(100); cb(o.asset, ''); } else { cb(null, (o && o.message) || 'Could not finish the upload.'); }
                    }).fail(function () { cb(null, 'Could not finish the upload. Try again.'); });
                });
            }
            send(1);
        });
    }

    /* Export the frame at `seconds` of a Library video as a new image: cb(asset) on success, cb(null) on failure (already reported). */
    function export_frame(asset_id, seconds, cb) {
        api('media_extract_frame', { asset_id: asset_id, seconds: Math.max(0, Number(seconds) || 0).toFixed(2) }, function (o) {
            if (!o || !o.success) { err(o, 'Could not export that frame.'); cb(null); return; }
            cb(o.asset);
        });
    }

    return {
        export_frame: export_frame, clock: clock,
        esc: esc, api: api, err: err, patch_children: patch_children, credits_html: credits_html, can_afford: can_afford, poll_job: poll_job, status_text: status_text,
        pick_image: pick_image, edit: edit, enhance_video: enhance_video, upload_video: upload_video,
        set_balance: function (n) { balance = parseInt(n, 10) || 0; },
        get_balance: function () { return balance; }
    };
})(jQuery);
