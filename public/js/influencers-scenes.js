/* Scenes (/influencers/scenes/<id>): the creator's own scenes (a new account starts with its own copies of the starter
   scenes). A card opens the editor: title, base prompt, shape, thumbnail. Save keeps the scene; Generate saves it and
   runs it with the page's influencer as the subject (four variants to rate); Delete removes it. Shared pieces (api,
   credit line, job polling) come from AiTools (ai-tools.js). The list, save and run requests go through a local wrapper
   that also reports HTTP and parse failures, so a server error never leaves a spinner up. snake_case throughout. */
jQuery(function ($) {
    "use strict";

    var $root = $('#inf');
    if (!$root.length || !window.AiTools || $root.data('page') !== 'scenes') { return; }

    var CFG = window.INF_CONFIG || {}, C = CFG.config || {}, inf = CFG.influencer || null, T = window.AiTools;
    var esc = T.esc, err = T.err;
    if (!inf) { return; }
    T.set_balance(C.ai_credits);

    $('#inf_who').on('change', function () { window.location = '/influencers/scenes/' + this.value; });

    /* Like AiTools.api, but a request that fails outright (a 5xx, a non-JSON body) still reaches the callback, as
       { success:false, message } with http:true, so every caller can show its error state. */
    function api(endpoint, body, cb) {
        body = $.extend({}, body || {});
        var csrf = document.querySelector('meta[name="csrf-token"]');
        if (csrf) { body.csrf_token = csrf.getAttribute('content'); }
        $.ajax({ url: ApiDataSvc.baseUrl + endpoint, method: 'POST', data: body, dataType: 'json' })
            .done(function (o) {
                if (o && typeof o.ai_credits !== 'undefined') { T.set_balance(o.ai_credits); }
                cb(o);
            })
            .fail(function (xhr) {
                var o = null;
                try { o = JSON.parse(xhr.responseText || ''); } catch (e) {}
                if (!o || typeof o !== 'object') { o = { success: false, message: xhr.status === 0 ? 'Check your connection and try again.' : 'The server could not answer (' + (xhr.status || 'error') + ').' }; }
                o.success = false; o.http = true;
                cb(o);
            });
    }

    var scenes = null;
    var ASPECT = C.aspect || { keys: [], names: {}, default_image: '3:4' };
    var edit_modal = null, thumb_file = null, run_stop = null, run_assets = [], votes = {};

    function confirm_box(opts) {
        if (!window.Swal) { return Promise.resolve(window.confirm(opts.title)); }
        return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' }, opts)).then(function (r) { return r.isConfirmed; });
    }

    /* ---- the grid ---- */
    function scene_view(which) {
        if (which === 'empty') { which = 'grid'; }   // an empty grid still shows the New Scene card
        $('#infSceneLoading').prop('hidden', which !== 'loading');
        $('#infSceneError').prop('hidden', which !== 'error');
        $('#infScenes').prop('hidden', which !== 'grid');
        $('#infSceneBar').toggleClass('is-empty', which !== 'grid');
    }
    function scene_find(id) {
        var t = null;
        $.each((scenes && scenes.templates) || [], function (i, x) { if (x.id === id) { t = x; } });
        return t;
    }
    function scenes_load() {
        scene_view('loading');
        api('scenes_list', {}, function (o) {
            if (!o || !o.success) { scene_view('error'); return; }
            scenes = o;
            scenes_show();
        });
    }
    /* the grid from what is in memory (no reload after a save or delete) */
    function scenes_show() {
        scenes.templates = scenes.templates || [];
        scenes_render(); scene_view(scenes.templates.length ? 'grid' : 'empty');
    }
    /* a saved scene replaces its old row, or goes first */
    function scene_put(s) {
        var found = false;
        $.each(scenes.templates, function (i, x) { if (x.id === s.id) { scenes.templates[i] = s; found = true; } });
        if (!found) { scenes.templates.unshift(s); }
        scenes_show();
    }
    function scene_drop(id) {
        scenes.templates = scenes.templates.filter(function (x) { return x.id !== id; });
        scenes_show();
    }
    function scenes_render() {
        $('#infScenes').html(scenes.templates.map(function (t) {
            return '<div class="inf-scene__cell"><button type="button" class="inf-scene" data-scene="' + t.id + '">' +
                '<span class="inf-scene__img">' + (t.thumb_url ? '<img src="' + esc(t.thumb_url) + '" alt="" loading="lazy">' : '<i class="fa-solid fa-panorama" aria-hidden="true"></i>') +
                (t.is_adult ? '<span class="inf-scene__adult">18+</span>' : '') + '</span>' +
                '<span class="inf-scene__body"><strong>' + esc(t.title) + '</strong></span></button></div>';
        }).join('') +
            // New Scene is a card in the grid, like New Influencer
            '<div class="inf-scene__cell"><button type="button" class="inf-scene inf-scene--new" id="infSceneNew"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>New Scene</span></button></div>');
    }
    $('#infSceneRetry').on('click', scenes_load);
    $('#infScenes').on('click', '.inf-scene', function () {
        if ($(this).is('.inf-scene--new')) { edit_open(null); return; }
        var t = scene_find(parseInt($(this).data('scene'), 10));
        if (t) { edit_open(t); }
    });

    /* ---- the editor ---- */
    function edit_errors_clear() { $('#infSceneEditForm [data-err]').prop('hidden', true).text(''); $('#infSceneEditForm .is-invalid').removeClass('is-invalid'); $('#infSceneErr').prop('hidden', true); }
    function edit_thumb(url) {
        $('#infSceneEditThumbImg').attr('src', url || '').prop('hidden', !url);
        $('#infSceneEditThumbBtn').toggleClass('has-img', !!url);
    }
    function run_price() { return ((C.ai_prices || {}).image || 0) * 4; }
    /* busy = saving or generating: the buttons wait, the Generate button says what is happening */
    function edit_busy(on, text) {
        $('#infSceneEditSave, #infSceneEditDelete').prop('disabled', !!on);
        $('#infSceneEditAspect .inf-seg__opt').each(function () { $(this).prop('disabled', !!on || $(this).data('ok') === 0); });
        $('#infSceneGo').prop('disabled', !!on || !T.can_afford(run_price()))
            .html(on ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + esc(text || 'Saving') : (run_assets.length ? 'Generate 4 More' : 'Generate 4 Variants'));
        $('#infSceneCost').html(on ? '' : T.credits_html(run_price()));
    }
    /* the shape boxes: every shape, the ones this image model cannot make greyed out */
    function aspect_build(chosen) {
        var models = (C.pickers || {}).image || [], ok = (models[0] && models[0].aspects && models[0].aspects.length) ? models[0].aspects : ASPECT.keys;
        if (ok.indexOf(chosen) < 0) { chosen = ok.indexOf(ASPECT.default_image) >= 0 ? ASPECT.default_image : ok[0]; }
        $('#infSceneEditAspect').html(ASPECT.keys.map(function (k) {
            var parts = k.split(':'), can = ok.indexOf(k) >= 0;
            return '<button type="button" class="inf-seg__opt' + (k === chosen ? ' is-on' : '') + '" aria-pressed="' + (k === chosen ? 'true' : 'false') + '" data-value="' + k + '" data-ok="' + (can ? 1 : 0) + '" title="' + esc(ASPECT.names[k] || k) + '"' + (can ? '' : ' disabled') + '>' +
                '<i class="cs-ratio" style="--rw:' + parseInt(parts[0], 10) + ';--rh:' + parseInt(parts[1], 10) + '" aria-hidden="true"></i><span>' + k + '</span></button>';
        }).join(''));
    }
    function aspect_value() { return String($('#infSceneEditAspect .inf-seg__opt.is-on').data('value') || ''); }
    $('#infSceneEditAspect').on('click', '.inf-seg__opt', function () {
        if ($(this).prop('disabled')) { return; }
        $('#infSceneEditAspect .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true');
        $('#infSceneEditForm [data-err="default_aspect"]').prop('hidden', true);
    });
    function edit_open(s) {
        if (!$('#infSceneEdit').length) { return; }
        edit_modal = edit_modal || bootstrap.Modal.getOrCreateInstance($('#infSceneEdit')[0]);
        if (run_stop) { run_stop(); run_stop = null; }
        edit_errors_clear(); thumb_file = null; run_assets = []; votes = {};
        $('#infSceneEditTitle').text(s ? s.title : 'New Scene');
        $('#infSceneEditId').val(s ? s.id : 0);
        $('#infSceneEditName').val(s ? s.title : '');
        $('#infSceneEditPrompt').val(s ? s.base_prompt : '');
        aspect_build(s ? s.default_aspect : (ASPECT.default_image || '3:4'));
        $('#infSceneEditAdult').prop('checked', s ? !!s.is_adult : false);
        $('#infSceneEditThumb').val('');
        edit_thumb(s ? s.thumb_url : '');
        $('#infSceneEditDelete').prop('hidden', !s);
        run_render(); edit_busy(false);
        edit_modal.show();
    }
    $('#infSceneEdit').on('hidden.bs.modal', function () { if (run_stop) { run_stop(); run_stop = null; } });
    $('#infSceneEditThumbBtn').on('click', function () { $('#infSceneEditThumb').trigger('click'); });
    $('#infSceneEditThumb').on('change', function () {
        var f = this.files && this.files[0];
        if (!f) { return; }
        if (f.size > 15 * 1048576) { toastr.error('That image is too large. Images can be up to 15 MB.'); this.value = ''; return; }
        thumb_file = f;
        edit_thumb(URL.createObjectURL(f));
    });
    $('#infSceneEditForm').on('input change', '.form-control', function () { $(this).removeClass('is-invalid').closest('.ai-field').find('[data-err]').prop('hidden', true); });
    function edit_upload_thumb(id, done) {
        if (!thumb_file) { done(null); return; }
        var fd = new FormData(); fd.append('file', thumb_file); fd.append('id', id);
        edit_busy(true, 'Uploading the thumbnail');
        $.ajax({ url: ApiDataSvc.baseUrl + 'scene_thumb', method: 'POST', data: fd, dataType: 'json', processData: false, contentType: false })
            .done(function (o) { if (o && o.success) { done(o.scene); } else { toastr.error((o && o.message) || 'The thumbnail could not be saved.'); done(false); } })
            .fail(function () { toastr.error('The thumbnail could not be uploaded. Check your connection.'); done(false); });
    }
    /* Save the fields (and a chosen thumbnail). done(id) when it saved, done(0) when it did not (the errors are shown). */
    function edit_save(done) {
        edit_errors_clear(); edit_busy(true, 'Saving');
        var id = parseInt($('#infSceneEditId').val(), 10) || 0;
        var body = { id: id, title: $('#infSceneEditName').val(), default_aspect: aspect_value(), base_prompt: $('#infSceneEditPrompt').val(), is_adult: $('#infSceneEditAdult').prop('checked') ? 1 : 0 };
        api('scene_save', body, function (o) {
            if (!o || !o.success) {
                edit_busy(false);
                var fields = { title: '#infSceneEditName', base_prompt: '#infSceneEditPrompt', default_aspect: '#infSceneEditAspect' }, shown = false;
                ((o && o.errors) || []).forEach(function (er) {
                    if (!fields[er.input]) { return; }
                    $(fields[er.input]).addClass('is-invalid'); $('#infSceneEditForm [data-err="' + er.input + '"]').text(er.msg).prop('hidden', false);
                    if (!shown) { $(fields[er.input]).trigger('focus'); shown = true; }
                });
                if (!shown) { err(o, 'Could not save the scene.'); }
                done(0);
                return;
            }
            $('#infSceneEditId').val(o.id); $('#infSceneEditTitle').text(o.scene.title); $('#infSceneEditDelete').prop('hidden', false);
            scene_put(o.scene);
            edit_upload_thumb(o.id, function (with_thumb) {
                if (with_thumb === false) { edit_busy(false); thumb_file = null; done(0); return; }   // the fields saved; the thumbnail can be tried again
                if (with_thumb) { scene_put(with_thumb); }
                thumb_file = null;
                done(o.id);
            });
        });
    }
    $('#infSceneEditSave').on('click', function () {
        var was_new = !(parseInt($('#infSceneEditId').val(), 10) > 0);
        edit_save(function (id) {
            if (!id) { return; }
            edit_busy(false); edit_modal.hide();
            toastr.success(was_new ? 'Scene saved' : 'Scene updated');
        });
    });
    $('#infSceneEditDelete').on('click', function () {
        var id = parseInt($('#infSceneEditId').val(), 10) || 0, t = scene_find(id);
        if (!t) { return; }
        confirm_box({ title: 'Delete this scene?', text: t.title + ' is removed from your scenes. Images already made from it stay in your library.', confirmButtonText: 'Delete' }).then(function (yes) {
            if (!yes) { return; }
            api('scene_delete', { id: id }, function (o) {
                if (!o || !o.success) { err(o, 'Could not delete the scene.'); return; }
                scene_drop(id); edit_modal.hide(); toastr.success('Scene deleted');
            });
        });
    });

    /* ---- Generate: save, then run the scene with this influencer (four variants to rate) ---- */
    function run_fail(msg) { $('#infSceneErr').text(msg).prop('hidden', false); }
    function run_render() {
        $('#infSceneGrid').prop('hidden', !run_assets.length).html(run_assets.map(function (a) {
            var v = votes[a.id] || 0;
            return '<figure class="inf-srun__item" data-asset="' + a.id + '"><img src="' + esc(a.display_url || a.thumb_url) + '" alt="">' +
                '<figcaption><button type="button" class="inf-srun__vote' + (v === 1 ? ' is-on' : '') + '" data-vote="1" aria-pressed="' + (v === 1 ? 'true' : 'false') + '" aria-label="Thumbs up" title="Thumbs Up"><i class="fa-' + (v === 1 ? 'solid' : 'regular') + ' fa-thumbs-up" aria-hidden="true"></i></button>' +
                '<button type="button" class="inf-srun__vote' + (v === -1 ? ' is-on' : '') + '" data-vote="-1" aria-pressed="' + (v === -1 ? 'true' : 'false') + '" aria-label="Thumbs down" title="Thumbs Down"><i class="fa-' + (v === -1 ? 'solid' : 'regular') + ' fa-thumbs-down" aria-hidden="true"></i></button>' +
                '<button type="button" class="btn btn-secondary btn-sm inf-srun__use" data-use>Use In Post</button></figcaption></figure>';
        }).join(''));
    }
    $('#infSceneEdit').on('click', '[data-vote]', function () {
        var $b = $(this), id = parseInt($b.closest('.inf-srun__item').data('asset'), 10), v = parseInt($b.data('vote'), 10);
        var next = (votes[id] === v) ? 0 : v;
        api('scene_vote', { asset_id: id, vote: next }, function (o) {
            if (!o || !o.success) { err(o, 'Could not save your rating.'); return; }
            votes[id] = o.vote; run_render();
        });
    });
    $('#infSceneEdit').on('click', '[data-use]', function () {
        var id = parseInt($(this).closest('.inf-srun__item').data('asset'), 10);
        try { sessionStorage.setItem('cs_open_asset', String(id)); } catch (e) {}   // the Studio opens a composer with it (same hand-off as Generate Images)
        window.location = '/studio';
    });
    $('#infSceneGo').on('click', function () {
        edit_save(function (id) {
            if (!id) { return; }
            edit_busy(true, 'Sending');
            api('scene_run', { template_id: id, id: inf.id, aspect: aspect_value() }, function (o) {
                if (!o || !o.success) {
                    edit_busy(false);
                    if (o && (o.need_credits || o.need_plan || o.need_upgrade)) { err(o); } else { run_fail((o && o.message) || 'Could not start the scene. Try again.'); }
                    return;
                }
                run_stop = T.poll_job(o.job_id, function (j) { edit_busy(true, T.status_text(j)); }, function (j) {
                    run_stop = null;
                    T.api('media_edit_options', {}, function (r) { if (r && r.success) { T.set_balance(r.ai_credits); } edit_busy(false); });   // a failed run refunds: show the balance as it is now
                    if (j.status !== 'done' || !j.assets || !j.assets.length) { edit_busy(false); run_fail((j.error || 'The scene failed.') + ' Your AI credits were returned.'); return; }
                    run_assets = j.assets.concat(run_assets);
                    run_render(); edit_busy(false);
                });
            });
        });
    });

    scenes_load();
});
