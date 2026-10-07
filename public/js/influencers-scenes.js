/* Scenes (/influencers/scenes/<id>): the creator's own scenes (add, edit, turn off, delete; a new account starts with
   its own copies of the starter scenes), each run as four variants with the page's influencer as the subject. Shared pieces (api, credit line,
   job polling) come from AiTools (ai-tools.js). The list, save and run requests go through a local wrapper that also
   reports HTTP and parse failures, so a server error never leaves a spinner up. snake_case throughout. */
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

    var scenes = null, cat = '', $run = null, run_tpl = null, run_stop = null, run_assets = [], votes = {};
    var ASPECT = C.aspect || { keys: [], names: {}, default_image: '3:4' };
    var edit_modal = null, thumb_file = null;

    function confirm_box(opts) {
        if (!window.Swal) { return Promise.resolve(window.confirm(opts.title)); }
        return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' }, opts)).then(function (r) { return r.isConfirmed; });
    }
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
    /* the grid or the empty state, from what is in memory (no reload after a save or delete) */
    function scenes_show() {
        var cats = [];
        scenes.templates = scenes.templates || [];
        $.each(scenes.templates, function (i, t) { if (t.category !== '' && cats.indexOf(t.category) < 0) { cats.push(t.category); } });
        scenes.categories = cats;
        if (cat !== '' && cats.indexOf(cat) < 0) { cat = ''; }
        if (!scenes.templates.length) { scene_view('empty'); return; }
        scenes_render(); scene_view('grid');
    }
    /* a saved scene replaces its old row, or goes first (own scenes lead the list) */
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
        var cats = scenes.categories || [];
        $('#infSceneCats').html(cats.length > 1 ? ['<button type="button" class="inf-chip' + (cat === '' ? ' is-on' : '') + '" data-cat="" aria-pressed="' + (cat === '' ? 'true' : 'false') + '">All</button>'].concat(cats.map(function (c) {
            return '<button type="button" class="inf-chip' + (cat === c ? ' is-on' : '') + '" data-cat="' + esc(c) + '" aria-pressed="' + (cat === c ? 'true' : 'false') + '">' + esc(c) + '</button>';
        })).join('') : '');
        var list = scenes.templates.filter(function (t) { return cat === '' || t.category === cat; });
        $('#infScenes').html(list.map(function (t) {
            var off = !t.is_active;
            return '<div class="inf-scene__cell' + (off ? ' is-off' : '') + '">' +
                '<button type="button" class="inf-scene" data-scene="' + t.id + '">' +
                '<span class="inf-scene__img">' + (t.thumb_url ? '<img src="' + esc(t.thumb_url) + '" alt="" loading="lazy">' : '<i class="fa-solid fa-panorama" aria-hidden="true"></i>') +
                (t.is_adult ? '<span class="inf-scene__adult">18+</span>' : '') + (off ? '<span class="inf-scene__off">Off</span>' : '') + '</span>' +
                '<span class="inf-scene__body"><strong>' + esc(t.title) + '</strong><small>' + esc(t.category || 'Scene') + '</small></span></button>' +
                ('<div class="dropdown inf-scene__menu"><button type="button" class="inf-scene__more" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="Scene actions"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>' +
                    '<ul class="dropdown-menu dropdown-menu-end inf-scene__items">' +
                    '<li><button type="button" class="dropdown-item" data-scene-act="edit">Edit</button></li>' +
                    '<li><button type="button" class="dropdown-item" data-scene-act="toggle">' + (t.is_active ? 'Turn Off' : 'Turn On') + '</button></li>' +
                    '<li><button type="button" class="dropdown-item inf-scene__danger" data-scene-act="delete">Delete</button></li>' +
                    '</ul></div>') +
                '</div>';
        }).join('') +
            // New Scene is a card in the grid, like New Influencer
            '<div class="inf-scene__cell"><button type="button" class="inf-scene inf-scene--new" id="infSceneNew"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>New Scene</span></button></div>');
    }
    $('#infSceneRetry').on('click', scenes_load);
    $('#infSceneCats').on('click', '[data-cat]', function () { cat = String($(this).data('cat')); scenes_render(); });
    $('#infScenes').on('click', '.inf-scene', function () {
        if ($(this).is('.inf-scene--new')) { edit_open(null); return; }
        var t = scene_find(parseInt($(this).data('scene'), 10));
        if (!t) { return; }
        if (!t.is_active) { toastr.warning('Turn the scene on to run it.'); return; }
        run_open(t);
    });
    $('#infScenes').on('click', '[data-scene-act]', function () {
        var t = scene_find(parseInt($(this).closest('.inf-scene__cell').find('.inf-scene').data('scene'), 10)), act = $(this).attr('data-scene-act');
        if (!t) { return; }
        if (act === 'edit') { edit_open(t); return; }
        if (act === 'toggle') {
            api('scene_set_active', { id: t.id, active: t.is_active ? 0 : 1 }, function (o) {
                if (!o || !o.success) { err(o, 'Could not update the scene.'); return; }
                scene_put(o.scene); toastr.success(o.scene.is_active ? 'Scene turned on' : 'Scene turned off');
            });
            return;
        }
        if (act === 'delete') {
            confirm_box({ title: 'Delete this scene?', text: t.title + ' is removed from your scenes. Images already made from it stay in your library.', confirmButtonText: 'Delete' }).then(function (yes) {
                if (!yes) { return; }
                api('scene_delete', { id: t.id }, function (o) {
                    if (!o || !o.success) { err(o, 'Could not delete the scene.'); return; }
                    scene_drop(t.id); toastr.success('Scene deleted');
                });
            });
        }
    });

    /* the editor: one window for New Scene and Edit (the thumbnail is uploaded after the fields save) */
    function edit_errors_clear() { $('#infSceneEditForm [data-err]').prop('hidden', true).text(''); $('#infSceneEditForm .is-invalid').removeClass('is-invalid'); }
    function edit_thumb(url) {
        $('#infSceneEditThumbImg').attr('src', url || '').prop('hidden', !url);
        $('#infSceneEditThumbBtn').toggleClass('has-img', !!url);
        $('#infSceneEditThumbChoose').text(url ? 'Change Image' : 'Choose Image');
    }
    function edit_busy(on, text) {
        $('#infSceneEditSave').prop('disabled', on).html(on ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Saving' : 'Save Scene');
        $('#infSceneEditState').text(on ? (text || '') : '');
    }
    function edit_open(s) {
        if (!$('#infSceneEdit').length) { return; }
        edit_modal = edit_modal || bootstrap.Modal.getOrCreateInstance($('#infSceneEdit')[0]);
        edit_errors_clear(); thumb_file = null; edit_busy(false);
        $('#infSceneEditTitle').text(s ? 'Edit Scene' : 'New Scene');
        $('#infSceneEditId').val(s ? s.id : 0);
        $('#infSceneEditName').val(s ? s.title : '');
        $('#infSceneEditCat').val(s ? s.category : '');
        $('#infSceneEditCats').html(((scenes && scenes.categories) || []).map(function (c) { return '<option value="' + esc(c) + '">'; }).join(''));
        $('#infSceneEditAspect').val(s ? s.default_aspect : (ASPECT.default_image || '3:4'));
        $('#infSceneEditPrompt').val(s ? s.base_prompt : '');
        $('#infSceneEditAdult').prop('checked', s ? !!s.is_adult : false);
        $('#infSceneEditThumb').val('');
        edit_thumb(s ? s.thumb_url : '');
        edit_modal.show();
    }
    $('#infSceneEditThumbBtn, #infSceneEditThumbChoose').on('click', function () { $('#infSceneEditThumb').trigger('click'); });
    $('#infSceneEditThumb').on('change', function () {
        var f = this.files && this.files[0];
        if (!f) { return; }
        if (f.size > 15 * 1048576) { toastr.error('That image is too large. Images can be up to 15 MB.'); this.value = ''; return; }
        thumb_file = f;
        edit_thumb(URL.createObjectURL(f));
    });
    $('#infSceneEditForm').on('input change', '.form-control, .form-select', function () { $(this).removeClass('is-invalid').closest('.ai-field').find('[data-err]').prop('hidden', true); });
    function edit_upload_thumb(id, done) {
        if (!thumb_file) { done(null); return; }
        var fd = new FormData(); fd.append('file', thumb_file); fd.append('id', id);
        edit_busy(true, 'Uploading the thumbnail');
        $.ajax({ url: ApiDataSvc.baseUrl + 'scene_thumb', method: 'POST', data: fd, dataType: 'json', processData: false, contentType: false })
            .done(function (o) { if (o && o.success) { done(o.scene); } else { toastr.error((o && o.message) || 'The thumbnail could not be saved.'); done(false); } })
            .fail(function () { toastr.error('The thumbnail could not be uploaded. Check your connection.'); done(false); });
    }
    $('#infSceneEditSave').on('click', function () {
        edit_errors_clear(); edit_busy(true);
        var id = parseInt($('#infSceneEditId').val(), 10) || 0;
        var body = { id: id, title: $('#infSceneEditName').val(), category: $('#infSceneEditCat').val(), default_aspect: $('#infSceneEditAspect').val(),
            base_prompt: $('#infSceneEditPrompt').val(), is_adult: $('#infSceneEditAdult').prop('checked') ? 1 : 0 };
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
                return;
            }
            $('#infSceneEditId').val(o.id);
            scene_put(o.scene);
            edit_upload_thumb(o.id, function (with_thumb) {
                if (with_thumb === false) { edit_busy(false); thumb_file = null; return; }   // the fields saved; the thumbnail can be tried again
                if (with_thumb) { scene_put(with_thumb); }
                edit_busy(false); edit_modal.hide();
                toastr.success(id > 0 ? 'Scene updated' : 'Scene saved');
            });
        });
    });

    /* the run window: template + this influencer -> four variants to rate */
    function run_build() {
        if ($run) { return; }
        $run = $(
            '<div class="modal fade ai-modal" id="infSceneRun" tabindex="-1" aria-labelledby="infSceneRunTitle" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">' +
            '<div class="modal-header"><h2 class="modal-title ai-modal__title" id="infSceneRunTitle"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
            '<div class="modal-body">' +
              '<div class="inf-srun__form" id="infSceneForm">' +
                '<div class="ai-field"><div class="ai-label">Influencer</div><div class="inf-srun__who" id="infSceneWho"></div></div>' +
                '<div class="ai-field"><div class="ai-label" id="infSceneSizeLabel">Size</div><div class="inf-seg" id="infSceneSize" role="group" aria-labelledby="infSceneSizeLabel"></div></div>' +
              '</div>' +
              '<p class="ai-error" id="infSceneErr" role="alert" hidden></p>' +
              '<div class="inf-srun__grid" id="infSceneGrid" hidden></div>' +
            '</div>' +
            '<div class="modal-footer"><span class="ai-cost" id="infSceneCost"></span>' +
              '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="infSceneClose">Cancel</button>' +
              '<button type="button" class="btn btn-primary" id="infSceneGo">Generate 4 Variants</button></div>' +
            '</div></div></div>').appendTo('body');
        $run.on('click', '#infSceneSize .inf-seg__opt', function () {
            if ($(this).prop('disabled')) { return; }
            $('#infSceneSize .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true');
        });
        $run.on('click', '#infSceneGo', run_go);
        $run.on('click', '[data-vote]', function () {
            var $b = $(this), id = parseInt($b.closest('.inf-srun__item').data('asset'), 10), v = parseInt($b.data('vote'), 10);
            var next = (votes[id] === v) ? 0 : v;
            api('scene_vote', { asset_id: id, vote: next }, function (o) {
                if (!o || !o.success) { err(o, 'Could not save your rating.'); return; }
                votes[id] = o.vote; run_render();
            });
        });
        $run.on('click', '[data-use]', function () {
            var id = parseInt($(this).closest('.inf-srun__item').data('asset'), 10);
            try { sessionStorage.setItem('cs_open_asset', String(id)); } catch (e) {}   // the Studio opens a composer with it (same hand-off as Generate Images)
            window.location = '/studio';
        });
        $run.on('hidden.bs.modal', function () { if (run_stop) { run_stop(); run_stop = null; } });
    }
    function run_price() { return ((C.ai_prices || {}).image || 0) * 4; }
    function run_cost() {
        $('#infSceneCost').html(T.credits_html(run_price()));
        $('#infSceneGo').prop('disabled', !T.can_afford(run_price()));
    }
    function run_busy(on, text) {
        $('#infSceneSize .inf-seg__opt').prop('disabled', on);
        $('#infSceneGo').prop('disabled', on).html(on ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + esc(text || 'Generating') : (run_assets.length ? 'Generate 4 More' : 'Generate 4 Variants'));
        if (!on) { run_cost(); }
    }
    function run_render() {
        $('#infSceneGrid').prop('hidden', !run_assets.length).html(run_assets.map(function (a) {
            var v = votes[a.id] || 0;
            return '<figure class="inf-srun__item" data-asset="' + a.id + '"><img src="' + esc(a.display_url || a.thumb_url) + '" alt="">' +
                '<figcaption><button type="button" class="inf-srun__vote' + (v === 1 ? ' is-on' : '') + '" data-vote="1" aria-pressed="' + (v === 1 ? 'true' : 'false') + '" aria-label="Thumbs up" title="Thumbs Up"><i class="fa-' + (v === 1 ? 'solid' : 'regular') + ' fa-thumbs-up" aria-hidden="true"></i></button>' +
                '<button type="button" class="inf-srun__vote' + (v === -1 ? ' is-on' : '') + '" data-vote="-1" aria-pressed="' + (v === -1 ? 'true' : 'false') + '" aria-label="Thumbs down" title="Thumbs Down"><i class="fa-' + (v === -1 ? 'solid' : 'regular') + ' fa-thumbs-down" aria-hidden="true"></i></button>' +
                '<button type="button" class="btn btn-secondary btn-sm inf-srun__use" data-use>Use In Post</button></figcaption></figure>';
        }).join(''));
    }
    function run_open(t) {
        run_build();
        run_tpl = t; run_assets = []; votes = {};
        $('#infSceneRunTitle').text(t.title);
        $('#infSceneWho').html((inf.cover_url ? '<img src="' + esc(inf.cover_url) + '" alt="">' : '<i class="fa-regular fa-user" aria-hidden="true"></i>') + '<span>' + esc(inf.name) + '</span>');
        var models = (C.pickers || {}).image || [], ok = (models[0] && models[0].aspects && models[0].aspects.length) ? models[0].aspects : ASPECT.keys;
        var chosen = ok.indexOf(t.default_aspect) >= 0 ? t.default_aspect : ok[0];
        $('#infSceneSize').html(ASPECT.keys.map(function (k) {
            var parts = k.split(':');
            return '<button type="button" class="inf-seg__opt' + (k === chosen ? ' is-on' : '') + '" aria-pressed="' + (k === chosen ? 'true' : 'false') + '" data-value="' + k + '" title="' + esc(ASPECT.names[k] || k) + '"' + (ok.indexOf(k) < 0 ? ' disabled' : '') + '>' +
                '<i class="cs-ratio" style="--rw:' + parseInt(parts[0], 10) + ';--rh:' + parseInt(parts[1], 10) + '" aria-hidden="true"></i><span>' + k + '</span></button>';
        }).join(''));
        $('#infSceneErr').prop('hidden', true);
        $('#infSceneClose').text('Cancel');
        run_render(); run_busy(false);
        bootstrap.Modal.getOrCreateInstance($run[0]).show();
    }
    function run_fail(msg) { $('#infSceneErr').text(msg).prop('hidden', false); }
    function run_go() {
        $('#infSceneErr').prop('hidden', true);
        run_busy(true, 'Sending');
        api('scene_run', { template_id: run_tpl.id, id: inf.id, aspect: $('#infSceneSize .inf-seg__opt.is-on').data('value') }, function (o) {
            if (!o || !o.success) {
                run_busy(false);
                if (o && (o.need_credits || o.need_plan || o.need_upgrade)) { err(o); run_cost(); } else { run_fail((o && o.message) || 'Could not start the scene. Try again.'); }
                return;
            }
            run_cost();
            run_stop = T.poll_job(o.job_id, function (j) { run_busy(true, T.status_text(j)); }, function (j) {
                run_stop = null;
                T.api('media_edit_options', {}, function (r) { if (r && r.success) { T.set_balance(r.ai_credits); } run_busy(false); });   // a failed run refunds: show the balance as it is now
                if (j.status !== 'done' || !j.assets || !j.assets.length) { run_busy(false); run_fail((j.error || 'The scene failed.') + ' Your AI credits were returned.'); return; }
                run_assets = j.assets.concat(run_assets);
                $('#infSceneClose').text('Done');
                run_render();
            });
        });
    }

    scenes_load();
});
