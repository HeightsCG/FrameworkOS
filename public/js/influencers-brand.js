/* Brand mode on the Generate pages (/influencers/images/0 and /influencers/videos/0): no influencer in the picture.
   Images go through media_generate (the brand image model, fixed server-side) and videos through
   media_generate_video; both land in the Library as 'generated' with no influencer, which is what the canvas lists.
   The canvas, chips and viewer are the same markup the influencer pages use; influencers.js sizes and opens them.
   Shared pieces (api, credit line, errors) come from AiTools. snake_case throughout. */
jQuery(function ($) {
    "use strict";

    var $root = $('#inf');
    if (!$root.length || !window.AiTools || !$root.data('brand')) { return; }

    var CFG = window.INF_CONFIG || {}, C = CFG.config || {}, B = CFG.brand || {}, T = window.AiTools;
    var esc = T.esc, api = T.api, err = T.err, page = $root.data('page');
    T.set_balance(C.ai_credits);
    var BUSY = ['processing', 'queued', 'pending'];

    $('#inf_who').on('change', function () { window.location = '/influencers/' + page + '/' + this.value; });

    function send_in_message(a) {
        if (!a || !window.CLSMessenger) { toastr.error('Messages are not available on this page'); return; }
        window.CLSMessenger.compose({ assets: [{ id: a.id, type: a.type || 'image', thumb: a.thumb_url || '', name: a.name || '' }], price: 0 });
    }
    function use_in_post(id) { try { sessionStorage.setItem('cs_open_asset', String(id)); } catch (e) {} window.location = '/studio'; }
    function download(id) {
        api('media_download', { id: id }, function (o) { if (o && o.success && o.url) { window.open(o.url, '_blank'); } else { err(o, 'Could not prepare the download.'); } });
    }
    function confirm_delete(what, cb) {
        Swal.fire({ title: 'Delete this ' + what + '?', text: 'It is removed from your library and from any posts or collections that use it.', icon: 'warning', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Delete', confirmButtonColor: '#e5484d', cancelButtonColor: '#6b6779' })
            .then(function (r) { if (r.isConfirmed) { cb(); } });
    }
    function seg_val(id) { return $('#' + id + ' .inf-seg__opt.is-on').data('value'); }
    function seg_pick(id, on_change) {
        $('#' + id).on('click', '.inf-seg__opt', function () {
            if ($(this).prop('disabled')) { return; }
            $('#' + id + ' .inf-seg__opt').removeClass('is-on').attr('aria-pressed', 'false');
            $(this).addClass('is-on').attr('aria-pressed', 'true');
            if (on_change) { on_change(); }
        });
    }
    /* Watch one Library file until it is ready or failed (media_get every few seconds), the way the Studio dialog did. */
    function follow_asset(id, every, max, on_tick, on_done) {
        var tries = 0, stopped = false;
        (function tick() {
            if (stopped) { return; }
            tries++;
            api('media_get', { id: id }, function (r) {
                if (stopped) { return; }
                if (!r || !r.success || !r.asset) { on_done(null, (r && r.message) || 'Could not check on it. It will appear in your Library when ready.'); return; }
                if (r.asset.status === 'failed') { on_done(null, r.asset.failure_reason || 'It did not work this time. Your AI credits were returned.'); return; }
                if (r.asset.status !== 'ready') {
                    if (tries >= max) { on_done(null, 'Still working in the background. It will appear in your Library when ready.'); return; }
                    if (on_tick) { on_tick(r.asset); }
                    setTimeout(tick, every); return;
                }
                on_done(r.asset, '');
            });
        })();
        return function () { stopped = true; };
    }

    /* =====================================================================
     * Generate Images, brand mode
     * =================================================================== */
    function init_images() {
        var assets = [], current = null, stop = null, last_prompt = '';
        var ideas = $('#inf_prompt_chips .inf-chip--text').map(function () { return $(this).attr('title'); }).get();
        var price = parseInt(B.image_price, 10) || (C.ai_prices || {}).image || 0;

        function cost() { $('#inf_gen_cost').html(T.credits_html(price)); }
        function busy(text) { $('#inf_busy').prop('hidden', false); $('#inf_busy_text').text(text); }
        function use_brand() { return $('#inf_brand_use').length ? ($('#inf_brand_use').attr('aria-pressed') === 'true' ? '1' : '0') : '0'; }
        seg_pick('inf_size');
        $('#inf_prompt_chips').on('click', '.inf-chip--text', function () { $('#inf_prompt').val(ideas[$(this).data('i')] || '').trigger('focus'); });
        $('#inf_brand_use').on('click', function () { var on = $(this).attr('aria-pressed') !== 'true'; $(this).attr('aria-pressed', on ? 'true' : 'false').toggleClass('is-on', on); });
        cost();

        function show_current() {
            $('#inf_busy').prop('hidden', true);
            if (!current) { $('#inf_idle').prop('hidden', false); $('#inf_main, #inf_result').prop('hidden', true); return; }
            $('#inf_idle').prop('hidden', true); $('#inf_main, #inf_result').prop('hidden', false);
            $('#inf_main_img').attr('src', current.preview_url || current.thumb_url);
            $('#inf_strip .inf-strip__item').removeClass('is-on').filter('[data-asset="' + current.id + '"]').addClass('is-on');
        }
        /* The list carries thumbnails only; the full-size signed link is fetched once an image is opened. */
        function select(a) {
            current = a; show_current();
            if (a.preview_url) { return; }
            api('media_get', { id: a.id }, function (r) { if (r && r.success && r.asset && current && current.id === a.id) { a.preview_url = r.asset.preview_url; current = a; show_current(); } });
        }
        function render_strip() {
            var $s = $('#inf_strip').empty();
            assets.forEach(function (a) {
                if (a.status === 'ready' && a.thumb_url) {
                    var $t = $('<button type="button" class="inf-strip__item' + (current && current.id === a.id ? ' is-on' : '') + '">').attr('data-asset', a.id).append('<img src="' + esc(a.thumb_url) + '" alt="">');
                    $t.on('click', function () { select(a); });
                    $s.append($t);
                } else if (BUSY.indexOf(a.status) >= 0) {
                    $s.append('<span class="inf-strip__item inf-strip__item--busy"><span class="spinner-border spinner-border-sm"></span></span>');
                }
            });
        }
        function merge(a) { var i = assets.findIndex(function (x) { return x.id === a.id; }); if (i >= 0) { assets[i] = a; } else { assets.unshift(a); } }
        function watch(id) {
            if (stop) { stop(); }
            stop = follow_asset(id, 2000, 90, function (a) { merge(a); }, function (a, msg) {
                stop = null; $('#inf_gen_go, #inf_res_again').prop('disabled', false);
                if (!a) { assets = assets.filter(function (x) { return x.id !== id || x.status === 'ready'; }); toastr.error(msg); show_current(); render_strip(); return; }
                merge(a); select(a); render_strip();
                toastr.success('Image added to your Library');
            });
        }
        function generate(prompt) {
            prompt = String(prompt == null ? $('#inf_prompt').val() : prompt).trim();
            if (prompt == '') { toastr.error('Write a prompt first'); $('#inf_prompt').trigger('focus'); return; }
            if (!C.enabled) { toastr.info('Rendering is not configured yet'); return; }
            last_prompt = prompt;
            $('#inf_gen_go, #inf_res_again').prop('disabled', true);
            busy('Sending');
            api('media_generate', { prompt: prompt, size: seg_val('inf_size'), use_brand: use_brand() }, function (o) {
                if (!o || !o.success || !o.asset) { err(o); $('#inf_gen_go, #inf_res_again').prop('disabled', false); show_current(); return; }
                T.set_balance(T.get_balance() - price); cost();
                merge(o.asset); render_strip();
                busy('Generating');
                watch(o.asset.id);
            });
        }
        $('#inf_gen_go').on('click', function () { generate(null); });
        $('#inf_res_again').on('click', function () { if (last_prompt !== '') { $('#inf_prompt').val(last_prompt); } generate(null); });
        $('#inf_res_video').on('click', function () { if (current) { window.location = '/influencers/videos/0/' + current.id; } });
        $('#inf_res_download').on('click', function () { if (current) { download(current.id); } });
        $('#inf_res_post').on('click', function () { if (current) { use_in_post(current.id); } });
        $('#inf_res_message').on('click', function () { if (current) { send_in_message(current); } });
        $('#inf_res_delete').on('click', function () {
            if (!current) { return; }
            var gone = current.id;
            confirm_delete('image', function () {
                api('media_delete', { id: gone }, function (o) {
                    if (!o || !o.success) { err(o); return; }
                    toastr.success(o.message || 'File removed');
                    assets = assets.filter(function (x) { return x.id !== gone; });
                    current = null;
                    var next = assets.filter(function (x) { return x.status === 'ready'; })[0];
                    if (next) { select(next); } else { show_current(); }
                    render_strip();
                });
            });
        });
        $('#inf_expand').on('click', function () { if (current) { $('#inf_lightbox_img').attr('src', current.preview_url || current.thumb_url); $('#inf_lightbox').prop('hidden', false); } });
        $('#inf_lightbox, #inf_lightbox_close').on('click', function () { $('#inf_lightbox').prop('hidden', true); });
        $(document).on('keydown', function (e) { if (e.key === 'Escape') { $('#inf_lightbox').prop('hidden', true); } });

        api('media_list', { type: 'image', brand: 1 }, function (o) {
            if (!o || !o.success) { return; }
            assets = (o.assets || []).filter(function (a) { return a.moderation !== 'blocked'; }).slice(0, 60);
            render_strip();
            var running = assets.filter(function (a) { return BUSY.indexOf(a.status) >= 0; })[0];
            if (running) { busy('Generating'); $('#inf_gen_go').prop('disabled', true); watch(running.id); }
        });
    }

    /* =====================================================================
     * Generate Videos, brand mode
     * =================================================================== */
    function init_videos() {
        var assets = [], current = null, stop = null;
        var still = parseInt($('#inf_gen').data('still'), 10) || 0;

        function model_key() { return $('#inf_vmodel .inf-opt.is-on').data('key') || ''; }
        function model_opt(key) { return (C.pickers.video || []).filter(function (o) { return o.key === key; })[0]; }
        function durations() {
            var m = model_opt(model_key()); var d = (m && m.durations.length) ? m.durations : ['5'];
            var cur = seg_val('inf_vdur');
            $('#inf_vdur').html(d.map(function (x) { return '<button type="button" class="inf-seg__opt' + (x === cur || (!d.includes(cur) && x === d[0]) ? ' is-on' : '') + '" data-value="' + esc(x) + '" aria-pressed="false"><span>' + esc(x) + 's</span></button>'; }).join(''));
            $('#inf_vdur .inf-seg__opt.is-on').attr('aria-pressed', 'true');
            cost();
        }
        function vprice() {
            var m = model_opt(model_key()), d = String(seg_val('inf_vdur') || ''), t = m && m.credits_by_duration;
            if (t && t[d] !== undefined) { return parseInt(t[d], 10) || 0; }
            return (m && m.credits) ? m.credits : ((C.ai_prices || {}).video || 0);
        }
        function cost() { $('#inf_vcost').html(T.credits_html(vprice())); }
        function busy(t) { $('#inf_vbusy').prop('hidden', false); $('#inf_vbusy_text').text(t); }
        $('#inf_vmodel').on('click', '.inf-opt', function () { $('#inf_vmodel .inf-opt').removeClass('is-on'); $(this).addClass('is-on'); durations(); });
        seg_pick('inf_vdur', cost);
        durations();

        /* stills: any ready image in the Library, newest first */
        $('#inf_stills').on('click', '.inf-photo--pick', function () {
            var id = +$(this).attr('data-id'), was = (still === id);
            $('#inf_stills .inf-photo').removeClass('is-on').attr('aria-pressed', 'false');
            still = was ? 0 : id;
            if (!was) { $(this).addClass('is-on').attr('aria-pressed', 'true'); }
            $('#inf_vgo').prop('disabled', !still);
        });
        api('media_list', { type: 'image' }, function (o) {
            var $g = $('#inf_stills').empty();
            var imgs = (o && o.success) ? (o.assets || []).filter(function (x) { return x.status === 'ready' && x.thumb_url && x.moderation !== 'blocked'; }) : [];
            if (!imgs.length) { $g.html('<span class="inf-wiz__meta">No images in your Library yet. Generate or upload one first.</span>'); return; }
            imgs.forEach(function (img) {
                var $t = $('<button type="button" class="inf-photo inf-photo--pick' + (img.id === still ? ' is-on' : '') + '">').attr('data-id', img.id).append('<img src="' + esc(img.thumb_url) + '" alt="" loading="lazy">');
                $g.append($t.attr('aria-pressed', img.id === still ? 'true' : 'false').attr('aria-label', 'Use this image'));
            });
            if (still && !$g.find('.is-on').length) { still = 0; }
            $('#inf_vgo').prop('disabled', !still);
        });

        function show_current() {
            $('#inf_vbusy').prop('hidden', true);
            if (!current) { $('#inf_vidle').prop('hidden', false); $('#inf_video, #inf_vresult').prop('hidden', true); return; }
            $('#inf_vidle').prop('hidden', true); $('#inf_video, #inf_vresult').prop('hidden', false);
            var v = document.getElementById('inf_video'); v.poster = current.thumb_url || ''; v.src = current.video_url || ''; v.load();
            $('#inf_vstrip .inf-strip__item').removeClass('is-on').filter('[data-asset="' + current.id + '"]').addClass('is-on');
        }
        function select(a) { current = a; show_current(); }
        function merge(a) { var i = assets.findIndex(function (x) { return x.id === a.id; }); if (i >= 0) { assets[i] = a; } else { assets.unshift(a); } }
        function render_strip() {
            var $s = $('#inf_vstrip').empty();
            assets.forEach(function (a) {
                if (a.status === 'ready') {
                    var $t = $('<button type="button" class="inf-strip__item' + (current && current.id === a.id ? ' is-on' : '') + '">').attr('data-asset', a.id).append(a.thumb_url ? '<img src="' + esc(a.thumb_url) + '" alt="">' : '<i class="fa-solid fa-play"></i>');
                    $t.on('click', function () { select(a); });
                    $s.append($t);
                } else if (BUSY.indexOf(a.status) >= 0) { $s.append('<span class="inf-strip__item inf-strip__item--busy"><span class="spinner-border spinner-border-sm"></span></span>'); }
            });
        }
        function watch(id) {
            if (stop) { stop(); }
            stop = follow_asset(id, 5000, 120, function (a) { merge(a); }, function (a, msg) {
                stop = null; $('#inf_vgo').prop('disabled', !still);
                if (!a) { assets = assets.filter(function (x) { return x.id !== id || x.status === 'ready'; }); toastr.error(msg); show_current(); render_strip(); return; }
                merge(a); select(a); render_strip();
                toastr.success('Video added to your Library');
            });
        }
        $('#inf_vgo').on('click', function () {
            if (!still) { toastr.error('Pick an image first'); return; }
            var prompt = String($('#inf_vprompt').val() || '').trim();
            if (prompt == '') { toastr.error('Describe the motion you want'); $('#inf_vprompt').trigger('focus'); return; }
            if (!C.enabled) { toastr.info('Rendering is not configured yet'); return; }
            var $b = $(this).prop('disabled', true);
            busy('Sending');
            api('media_generate_video', { source_asset_id: still, prompt: prompt, model_key: model_key(), duration: seg_val('inf_vdur') }, function (o) {
                if (!o || !o.success || !o.asset) { err(o); $b.prop('disabled', false); show_current(); return; }
                T.set_balance(T.get_balance() - (parseInt(o.price, 10) || vprice())); cost();
                merge(o.asset); render_strip();
                busy('Generating');
                watch(o.asset.id);
            });
        });
        $('#inf_vres_download').on('click', function () { if (current) { download(current.id); } });
        $('#inf_vres_message').on('click', function () { if (current) { send_in_message($.extend({ type: 'video' }, current)); } });
        $('#inf_vres_post').on('click', function () { if (current) { use_in_post(current.id); } });
        $('#inf_vres_delete').on('click', function () {
            if (!current) { return; }
            var gone = current.id;
            confirm_delete('video', function () {
                api('media_delete', { id: gone }, function (o) {
                    if (!o || !o.success) { err(o); return; }
                    toastr.success(o.message || 'File removed');
                    assets = assets.filter(function (x) { return x.id !== gone; });
                    current = null;
                    var next = assets.filter(function (x) { return x.status === 'ready'; })[0];
                    if (next) { select(next); } else { show_current(); }
                    render_strip();
                });
            });
        });

        api('media_list', { type: 'video', brand: 1 }, function (o) {
            if (!o || !o.success) { return; }
            assets = (o.assets || []).slice(0, 60);
            render_strip();
            var running = assets.filter(function (a) { return BUSY.indexOf(a.status) >= 0; })[0];
            if (running) { busy('Generating'); $('#inf_vgo').prop('disabled', true); watch(running.id); }
        });
    }

    if (page === 'images') { init_images(); }
    if (page === 'videos') { init_videos(); }
});
