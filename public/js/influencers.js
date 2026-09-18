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
        var STEP_LABELS = { name: 'Name', photos: 'Photos', train: 'Train', input: 'Input', reference: 'Reference', set: 'Training set', review: 'Review', training: 'Training', done: 'Done' };
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
            $('#inf_retry_train').on('click', function () { show(path === 'photos' ? 'train' : 'review'); });
            $('[data-copy]').on('click', function () { var t = $(this).data('copy'); if (navigator.clipboard) { navigator.clipboard.writeText(t); toastr.success('Copied'); } });
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

        var RENDER = { name: render_name, training: render_training, done: render_done };
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
            show(inf.wizard_step || 'name');
        }
    }

    if (page === 'index')  { init_index(); }
    if (page === 'create') { init_create(); }
});
