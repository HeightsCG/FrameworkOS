/* Content Studio AI additions, kept out of studio.js: Edit By Instruction and version history in the Library
   drawer, and the Scenes tab (the scene template library). Talks to studio.js only through document events
   (cs:detail in, cs:asset-added / cs:open-asset / cs:use-in-post out). Shared pieces come from AiTools. */
jQuery(function ($) {
    "use strict";

    var CFG = window.CS_CONFIG || {}, T = window.AiTools;
    if (!T || !$('#csTabs').length) { return; }
    var esc = T.esc, api = T.api, err = T.err;
    T.set_balance((CFG.ai || {}).balance);

    /* =====================================================================
     * Library drawer: Edit, Replicate, version history
     * =================================================================== */
    $(document).on('cs:detail', function (e, a) {
        var $box = $('#csDvAi').empty();
        if (a && a.type === 'video' && a.status === 'ready') {
            // Scrub the player above to a moment, then export it as an image (which opens here with Edit and Replicate).
            $box.html('<label class="form-label cs-dv__label">Frames</label><div class="cs-dv__ai cs-dv__ai--one">' +
                '<button type="button" class="btn btn-outline-secondary" id="csDvFrame"><i class="fa-regular fa-image" aria-hidden="true"></i> Export This Frame</button>' + ((window.TUT_BTN || {})['30'] || '') + '</div>');
            $('#csDvFrame').on('click', function () {
                var $b = $(this).prop('disabled', true), v = $('#csDetailBody video')[0];
                $b.html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Exporting');
                T.export_frame(a.id, v ? v.currentTime : 0, function (frame) {
                    $b.prop('disabled', false).html('<i class="fa-regular fa-image" aria-hidden="true"></i> Export This Frame');
                    if (!frame) { return; }
                    toastr.success('Frame saved to your Library');
                    $(document).trigger('cs:asset-added', [frame.id]);
                    $(document).trigger('cs:open-asset', [frame.id]);
                });
            });
            return;
        }
        if (!a || a.type !== 'image' || a.status !== 'ready' || !CFG.can_ai) { return; }
        var blocked = a.moderation === 'blocked';
        $box.html(
            '<label class="form-label cs-dv__label">AI Tools</label>' +
            '<div class="cs-dv__ai">' +
              '<button type="button" class="btn btn-outline-secondary" id="csDvEdit"' + (blocked ? ' disabled' : '') + '><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</button>' + ((window.TUT_BTN || {})['25'] || '') +
              '<a class="btn btn-outline-secondary' + (blocked ? ' disabled' : '') + '" href="/influencers/replicate/0/' + a.id + '" id="csDvReplicate"' + (blocked ? ' aria-disabled="true" tabindex="-1"' : '') + '><i class="fa-solid fa-clone" aria-hidden="true"></i> Replicate</a>' +
            '</div>' +
            '<div id="csDvVersions"></div>');
        var ready = ((CFG.influencers || {}).ready || []);
        if (ready.length) { $('#csDvReplicate').attr('href', '/influencers/replicate/' + ready[0].id + '/' + a.id); } else { $('#csDvReplicate').remove(); }
        $('#csDvEdit').on('click', function () {
            T.edit({ id: a.id, display_url: a.preview_url, thumb_url: a.thumb_url, name: a.name }, function (made) {
                $(document).trigger('cs:asset-added', [made.id]);
                $(document).trigger('cs:open-asset', [made.id]);   // the drawer moves to the new version
            });
        });
        versions(a.id);
    });
    function versions(asset_id) {
        api('media_versions', { asset_id: asset_id }, function (o) {
            var list = (o && o.success) ? (o.versions || []) : [];
            if (list.length < 2) { $('#csDvVersions').empty(); return; }
            $('#csDvVersions').html('<label class="form-label cs-dv__label">Versions</label><ol class="cs-dv__versions">' + list.map(function (v, i) {
                var label = v.change !== '' ? v.change : (i === 0 ? 'Original' : 'Version ' + (i + 1));
                return '<li><button type="button" class="cs-dv__version' + (v.current ? ' is-on' : '') + '" data-version="' + v.id + '"' + (v.current ? ' aria-current="true"' : '') + '>' +
                    (v.thumb_url ? '<img src="' + esc(v.thumb_url) + '" alt="">' : '<span class="cs-dv__vph"><i class="fa-regular fa-image" aria-hidden="true"></i></span>') +
                    '<span>' + esc(label) + '</span></button></li>';
            }).join('') + '</ol>');
        });
    }
    $(document).on('click', '.cs-dv__version', function () { if (!$(this).hasClass('is-on')) { $(document).trigger('cs:open-asset', [parseInt($(this).data('version'), 10)]); } });

    /* =====================================================================
     * Scenes tab: the creator's own scenes (add, edit, turn off, delete) beside the platform library
     * =================================================================== */
    var scenes = null, cat = '', $run = null, run_tpl = null, run_stop = null, run_assets = [], votes = {};
    var ASPECT = CFG.aspect || { keys: [], names: {} };
    var edit_modal = null, thumb_file = null;

    function confirm_box(opts) {
        if (!window.Swal) { return Promise.resolve(window.confirm(opts.title)); }
        return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' }, opts)).then(function (r) { return r.isConfirmed; });
    }
    function scene_view(which) {
        $('#csSceneLoading').prop('hidden', which !== 'loading');
        $('#csSceneError').prop('hidden', which !== 'error');
        $('#csSceneEmpty').prop('hidden', which !== 'empty');
        $('#csScenes, #csSceneBar').prop('hidden', which !== 'grid');
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
        $('#csSceneCats').html(cats.length > 1 ? ['<button type="button" class="cs-chip' + (cat === '' ? ' is-on' : '') + '" data-cat="" aria-pressed="' + (cat === '' ? 'true' : 'false') + '">All</button>'].concat(cats.map(function (c) {
            return '<button type="button" class="cs-chip' + (cat === c ? ' is-on' : '') + '" data-cat="' + esc(c) + '" aria-pressed="' + (cat === c ? 'true' : 'false') + '">' + esc(c) + '</button>';
        })).join('') : '');
        var list = scenes.templates.filter(function (t) { return cat === '' || t.category === cat; });
        $('#csScenes').html(list.map(function (t) {
            var off = t.mine && !t.is_active;
            return '<div class="cs-scene__cell' + (off ? ' is-off' : '') + '">' +
                '<button type="button" class="cs-scene" data-scene="' + t.id + '">' +
                '<span class="cs-scene__img">' + (t.thumb_url ? '<img src="' + esc(t.thumb_url) + '" alt="" loading="lazy">' : '<i class="fa-solid fa-panorama" aria-hidden="true"></i>') +
                (t.is_adult ? '<span class="cs-scene__adult">18+</span>' : '') + (off ? '<span class="cs-scene__off">Off</span>' : '') + '</span>' +
                '<span class="cs-scene__body"><strong>' + esc(t.title) + '</strong><small>' + esc(t.category || 'Scene') + '</small></span></button>' +
                (t.mine ? '<div class="dropdown cs-scene__menu"><button type="button" class="cs-scene__more" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="Scene actions"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></button>' +
                    '<ul class="dropdown-menu dropdown-menu-end cs-scene__items">' +
                    '<li><button type="button" class="dropdown-item" data-scene-act="edit">Edit</button></li>' +
                    '<li><button type="button" class="dropdown-item" data-scene-act="toggle">' + (t.is_active ? 'Turn Off' : 'Turn On') + '</button></li>' +
                    '<li><button type="button" class="dropdown-item cs-scene__danger" data-scene-act="delete">Delete</button></li></ul></div>' : '') +
                '</div>';
        }).join(''));
    }
    $('#csTabScenes').on('shown.bs.tab', function () { if (!scenes) { scenes_load(); } });
    $('#csSceneRetry').on('click', scenes_load);
    $('#csSceneCats').on('click', '[data-cat]', function () { cat = String($(this).data('cat')); scenes_render(); });
    $('#csScenes').on('click', '.cs-scene', function () {
        var t = scene_find(parseInt($(this).data('scene'), 10));
        if (!t) { return; }
        if (t.mine && !t.is_active) { toastr.warning('Turn the scene on to run it.'); return; }
        run_open(t);
    });
    $('#csSceneNew, #csSceneNewEmpty').on('click', function () { edit_open(null); });
    $('#csScenes').on('click', '[data-scene-act]', function () {
        var t = scene_find(parseInt($(this).closest('.cs-scene__cell').find('.cs-scene').data('scene'), 10)), act = $(this).attr('data-scene-act');
        if (!t || !t.mine) { return; }
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
    function edit_errors_clear() { $('#csSceneEditForm [data-err]').prop('hidden', true).text(''); $('#csSceneEditForm .is-invalid').removeClass('is-invalid'); }
    function edit_thumb(url) {
        $('#csSceneEditThumbImg').attr('src', url || '').prop('hidden', !url);
        $('#csSceneEditThumbBtn').toggleClass('has-img', !!url);
        $('#csSceneEditThumbChoose').text(url ? 'Change Image' : 'Choose Image');
    }
    function edit_busy(on, text) {
        $('#csSceneEditSave').prop('disabled', on).html(on ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Saving' : 'Save Scene');
        $('#csSceneEditState').text(on ? (text || '') : '');
    }
    function edit_open(s) {
        if (!$('#csSceneEdit').length) { return; }
        edit_modal = edit_modal || bootstrap.Modal.getOrCreateInstance($('#csSceneEdit')[0]);
        edit_errors_clear(); thumb_file = null; edit_busy(false);
        $('#csSceneEditTitle').text(s ? 'Edit Scene' : 'New Scene');
        $('#csSceneEditId').val(s ? s.id : 0);
        $('#csSceneEditName').val(s ? s.title : '');
        $('#csSceneEditCat').val(s ? s.category : '');
        $('#csSceneEditCats').html(((scenes && scenes.categories) || []).map(function (c) { return '<option value="' + esc(c) + '">'; }).join(''));
        $('#csSceneEditAspect').val(s ? s.default_aspect : (ASPECT.default_image || '3:4'));
        $('#csSceneEditPrompt').val(s ? s.base_prompt : '');
        $('#csSceneEditAdult').prop('checked', s ? !!s.is_adult : false);
        $('#csSceneEditThumb').val('');
        edit_thumb(s ? s.thumb_url : '');
        edit_modal.show();
    }
    $('#csSceneEditThumbBtn, #csSceneEditThumbChoose').on('click', function () { $('#csSceneEditThumb').trigger('click'); });
    $('#csSceneEditThumb').on('change', function () {
        var f = this.files && this.files[0];
        if (!f) { return; }
        if (f.size > 15 * 1048576) { toastr.error('That image is too large. Images can be up to 15 MB.'); this.value = ''; return; }
        thumb_file = f;
        edit_thumb(URL.createObjectURL(f));
    });
    $('#csSceneEditForm').on('input change', '.form-control, .form-select', function () { $(this).removeClass('is-invalid').closest('.ai-field').find('[data-err]').prop('hidden', true); });
    function edit_upload_thumb(id, done) {
        if (!thumb_file) { done(null); return; }
        var fd = new FormData(); fd.append('file', thumb_file); fd.append('id', id);
        edit_busy(true, 'Uploading the thumbnail');
        $.ajax({ url: '/api/scene_thumb', method: 'POST', data: fd, dataType: 'json', processData: false, contentType: false })
            .done(function (o) { if (o && o.success) { done(o.scene); } else { toastr.error((o && o.message) || 'The thumbnail could not be saved.'); done(false); } })
            .fail(function () { toastr.error('The thumbnail could not be uploaded. Check your connection.'); done(false); });
    }
    $('#csSceneEditSave').on('click', function () {
        edit_errors_clear(); edit_busy(true);
        var id = parseInt($('#csSceneEditId').val(), 10) || 0;
        var body = { id: id, title: $('#csSceneEditName').val(), category: $('#csSceneEditCat').val(), default_aspect: $('#csSceneEditAspect').val(),
            base_prompt: $('#csSceneEditPrompt').val(), is_adult: $('#csSceneEditAdult').prop('checked') ? 1 : 0 };
        api('scene_save', body, function (o) {
            if (!o || !o.success) {
                edit_busy(false);
                var fields = { title: '#csSceneEditName', base_prompt: '#csSceneEditPrompt', default_aspect: '#csSceneEditAspect' }, shown = false;
                ((o && o.errors) || []).forEach(function (er) {
                    if (!fields[er.input]) { return; }
                    $(fields[er.input]).addClass('is-invalid'); $('#csSceneEditForm [data-err="' + er.input + '"]').text(er.msg).prop('hidden', false);
                    if (!shown) { $(fields[er.input]).trigger('focus'); shown = true; }
                });
                if (!shown) { err(o, 'Could not save the scene.'); }
                return;
            }
            $('#csSceneEditId').val(o.id);
            scene_put(o.scene);
            edit_upload_thumb(o.id, function (with_thumb) {
                if (with_thumb === false) { edit_busy(false); thumb_file = null; return; }   // the fields saved; the thumbnail can be tried again
                if (with_thumb) { scene_put(with_thumb); }
                edit_busy(false); edit_modal.hide();
                toastr.success(id > 0 ? 'Scene updated' : 'Scene saved');
            });
        });
    });

    /* the run window: template + influencer -> four variants to rate */
    function run_build() {
        if ($run) { return; }
        $run = $(
            '<div class="modal fade ai-modal" id="csSceneRun" tabindex="-1" aria-labelledby="csSceneRunTitle" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">' +
            '<div class="modal-header"><h2 class="modal-title ai-modal__title" id="csSceneRunTitle"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
            '<div class="modal-body">' +
              '<div class="cs-srun__form" id="csSceneForm">' +
                '<div class="ai-field"><label class="ai-label" for="csSceneWho">Influencer</label><select class="form-select" id="csSceneWho"></select></div>' +
                '<div class="ai-field"><div class="ai-label" id="csSceneSizeLabel">Size</div><div class="cs-seg cs-srun__seg" id="csSceneSize" role="group" aria-labelledby="csSceneSizeLabel"></div></div>' +
              '</div>' +
              '<div class="cs-empty cs-srun__none" id="csSceneNoInf" hidden><div class="cs-empty__icon"><i class="fa-regular fa-user"></i></div><h2 class="cs-empty__title">Train an Influencer First</h2>' +
                '<p class="cs-empty__text">Scenes are generated with one of your trained influencers.</p><a class="btn btn-secondary" href="/influencers">Open Influencers</a></div>' +
              '<p class="ai-error" id="csSceneErr" role="alert" hidden></p>' +
              '<div class="cs-srun__grid" id="csSceneGrid" hidden></div>' +
            '</div>' +
            '<div class="modal-footer"><span class="ai-cost" id="csSceneCost"></span>' +
              '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="csSceneClose">Cancel</button>' +
              '<button type="button" class="btn btn-primary" id="csSceneGo">Generate 4 Variants</button></div>' +
            '</div></div></div>').appendTo('body');
        $run.on('click', '#csSceneSize .cs-seg__opt', function () {
            if ($(this).prop('disabled')) { return; }
            $('#csSceneSize .cs-seg__opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true');
        });
        $run.on('click', '#csSceneGo', run_go);
        $run.on('click', '[data-vote]', function () {
            var $b = $(this), id = parseInt($b.closest('.cs-srun__item').data('asset'), 10), v = parseInt($b.data('vote'), 10);
            var next = (votes[id] === v) ? 0 : v;
            api('scene_vote', { asset_id: id, vote: next }, function (o) {
                if (!o || !o.success) { err(o, 'Could not save your rating.'); return; }
                votes[id] = o.vote; run_render();
            });
        });
        $run.on('click', '[data-use]', function () {
            var id = parseInt($(this).closest('.cs-srun__item').data('asset'), 10);
            bootstrap.Modal.getOrCreateInstance($run[0]).hide();
            $(document).trigger('cs:use-in-post', [id]);
        });
        $run.on('hidden.bs.modal', function () { if (run_stop) { run_stop(); run_stop = null; } });
    }
    function run_price() { return ((CFG.ai || {}).image_price || 0) * 4; }
    function run_cost() {
        var none = !((CFG.influencers || {}).ready || []).length;
        $('#csSceneCost').html(none ? '' : T.credits_html(run_price()));
        $('#csSceneGo').prop('disabled', none || !T.can_afford(run_price()));
    }
    function run_busy(on, text) {
        $('#csSceneWho, #csSceneSize .cs-seg__opt').prop('disabled', on);
        $('#csSceneGo').prop('disabled', on).html(on ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> ' + esc(text || 'Generating') : (run_assets.length ? 'Generate 4 More' : 'Generate 4 Variants'));
        if (!on) { run_cost(); }
    }
    function run_render() {
        $('#csSceneGrid').prop('hidden', !run_assets.length).html(run_assets.map(function (a) {
            var v = votes[a.id] || 0;
            return '<figure class="cs-srun__item" data-asset="' + a.id + '"><img src="' + esc(a.display_url || a.thumb_url) + '" alt="">' +
                '<figcaption><button type="button" class="cs-srun__vote' + (v === 1 ? ' is-on' : '') + '" data-vote="1" aria-pressed="' + (v === 1 ? 'true' : 'false') + '" aria-label="Thumbs up" title="Thumbs Up"><i class="fa-' + (v === 1 ? 'solid' : 'regular') + ' fa-thumbs-up" aria-hidden="true"></i></button>' +
                '<button type="button" class="cs-srun__vote' + (v === -1 ? ' is-on' : '') + '" data-vote="-1" aria-pressed="' + (v === -1 ? 'true' : 'false') + '" aria-label="Thumbs down" title="Thumbs Down"><i class="fa-' + (v === -1 ? 'solid' : 'regular') + ' fa-thumbs-down" aria-hidden="true"></i></button>' +
                '<button type="button" class="btn btn-secondary btn-sm cs-srun__use" data-use>Use In Post</button></figcaption></figure>';
        }).join(''));
    }
    function run_open(t) {
        run_build();
        run_tpl = t; run_assets = []; votes = {};
        var ready = ((CFG.influencers || {}).ready || []);
        $('#csSceneRunTitle').text(t.title);
        $('#csSceneWho').html(ready.map(function (i) { return '<option value="' + i.id + '">' + esc(i.name) + '</option>'; }).join(''));
        var models = CFG.image_models || [], ok = (models[0] && models[0].aspects && models[0].aspects.length) ? models[0].aspects : ASPECT.keys;
        var chosen = ok.indexOf(t.default_aspect) >= 0 ? t.default_aspect : ok[0];
        $('#csSceneSize').html(ASPECT.keys.map(function (k) {
            var parts = k.split(':');
            return '<button type="button" class="cs-seg__opt' + (k === chosen ? ' is-on' : '') + '" aria-pressed="' + (k === chosen ? 'true' : 'false') + '" data-size="' + k + '" title="' + esc(ASPECT.names[k] || k) + '"' + (ok.indexOf(k) < 0 ? ' disabled' : '') + '>' +
                '<i class="cs-ratio" style="--rw:' + parseInt(parts[0], 10) + ';--rh:' + parseInt(parts[1], 10) + '" aria-hidden="true"></i><span>' + k + '</span></button>';
        }).join(''));
        $('#csSceneForm').prop('hidden', !ready.length);
        $('#csSceneNoInf').prop('hidden', !!ready.length);
        $('#csSceneErr').prop('hidden', true);
        $('#csSceneClose').text('Cancel');
        run_render(); run_busy(false);
        bootstrap.Modal.getOrCreateInstance($run[0]).show();
    }
    function run_go() {
        $('#csSceneErr').prop('hidden', true);
        run_busy(true, 'Sending');
        api('scene_run', { template_id: run_tpl.id, id: $('#csSceneWho').val(), aspect: $('#csSceneSize .cs-seg__opt.is-on').data('size') }, function (o) {
            if (!o || !o.success) {
                run_busy(false);
                if (o && (o.need_credits || o.need_plan || o.need_upgrade)) { err(o); run_cost(); } else { $('#csSceneErr').text((o && o.message) || 'Could not start the scene. Try again.').prop('hidden', false); }
                return;
            }
            run_stop = T.poll_job(o.job_id, function (j) { run_busy(true, T.status_text(j)); }, function (j) {
                run_stop = null;
                api('media_edit_options', {}, function (r) { if (r && r.success) { T.set_balance(r.ai_credits); if (CFG.ai) { CFG.ai.balance = r.ai_credits; } } run_busy(false); });
                if (j.status !== 'done' || !j.assets || !j.assets.length) { run_busy(false); $('#csSceneErr').text((j.error || 'The scene failed.') + ' Your AI credits were returned.').prop('hidden', false); return; }
                run_assets = j.assets.concat(run_assets);
                $.each(j.assets, function (i, a) { $(document).trigger('cs:asset-added', [a.id]); });
                $('#csSceneClose').text('Done');
                run_render();
            });
        });
    }
    /* ---- composer: AI disclosure and Story placement on the Distribution section ---- */
    var STORY_PLATFORMS = ['instagram', 'facebook'];
    function has_ai(c) { return (c.assets || []).some(function (a) { return a.provenance === 'generated' || a.provenance === 'edited'; }); }
    function dest_extras(c, accounts) {
        var $wrap = $('#csCompSocial');
        $wrap.find('.cs-pe__story').remove(); $('#csPeAiRow').remove();
        c.story_accounts = (c.story_accounts || []).map(String);
        (accounts || []).forEach(function (a) {
            if (STORY_PLATFORMS.indexOf(String(a.platform).toLowerCase()) < 0) { return; }
            var $box = $wrap.find('input[data-acct]').filter(function () { return String($(this).data('acct')) === String(a.id); });
            if (!$box.length || $box.prop('disabled')) { return; }
            var on = c.story_accounts.indexOf(String(a.id)) >= 0, id = 'csPeStory_' + String(a.id).replace(/[^A-Za-z0-9_-]/g, '_');
            $box.closest('.cs-pe__dest').after('<label class="cs-pe__story" for="' + id + '" data-story="' + T.esc(a.id) + '"' + ($box.prop('checked') ? '' : ' hidden') + '>' +
                '<span class="cs-pe__storylabel">Post As Story</span>' +
                '<span class="form-check form-switch cs-pe__switch"><input class="form-check-input" type="checkbox" role="switch" id="' + id + '"' + (on ? ' checked' : '') + '></span></label>');
        });
        if (has_ai(c)) {
            $wrap.after('<div class="cs-pe__switchrow" id="csPeAiRow"><label class="cs-pe__switchtext" for="csPeAiDisclose"><span class="cs-pe__switchlabel">AI Disclosure</span></label>' +
                '<div class="form-check form-switch cs-pe__switch"><input class="form-check-input" type="checkbox" role="switch" id="csPeAiDisclose"' + (c.ai_disclosure === 0 || c.ai_disclosure === '0' ? '' : ' checked') + '></div></div>');
        }
        $wrap.off('change.csx').on('change.csx', '.cs-pe__story input', function () {
            var id = String($(this).closest('.cs-pe__story').data('story'));
            c.story_accounts = c.story_accounts.filter(function (x) { return x !== id; });
            if (this.checked) { c.story_accounts.push(id); }
        });
        $('#csPeAiDisclose').off('change').on('change', function () { c.ai_disclosure = this.checked ? 1 : 0; });
    }
    var dest_last = null;
    $(document).on('cs:dest-rendered', function (e, c, accounts) { dest_last = [c, accounts]; dest_extras(c, accounts); });
    // Media can change after the list was drawn: show or drop the AI switch to match.
    $(document).on('click', '#csComposer', function () {
        if (dest_last && has_ai(dest_last[0]) !== ($('#csPeAiRow').length > 0)) { dest_extras(dest_last[0], dest_last[1]); }
    });
    $(document).on('cs:dest-changed', function (e, c) {
        $('#csCompSocial .cs-pe__story').each(function () {
            var id = String($(this).data('story')), on = c.share.has(id);
            $(this).prop('hidden', !on);
            if (!on) { $(this).find('input').prop('checked', false); c.story_accounts = (c.story_accounts || []).filter(function (x) { return x !== id; }); }
        });
    });
    /* ---- composer: the on-video line a Hook Overlay caption was written to follow ---- */
    $(document).on('cs:caption-written', function (e, o) {
        var hook = (o && o.success && o.hook) ? String(o.hook) : '';
        $('#csPeHookText').text(hook); $('#csPeHook').prop('hidden', hook === '');
    });
    $(document).on('click', '#csPeHookCopy', function () {
        var t = $('#csPeHookText').text();
        if (navigator.clipboard && t) { navigator.clipboard.writeText(t); toastr.success('Copied'); }
    });
});
