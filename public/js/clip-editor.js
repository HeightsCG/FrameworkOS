/* Clip editor (Content Studio, New Edit): /studio/edit lists edits, /studio/edit/<id> edits one. The timeline
   (clips and stills, text overlays, PNG overlays, an audio track) saves itself as you work; Export queues a server
   render and the finished video lands in the Library. Library files are chosen with AiTools.pick_image.
   AJAX through ApiDataSvc.apiCall (string responses, JSON.parse in AiTools.api). snake_case throughout. */
jQuery(function ($) {
    "use strict";

    var T = window.AiTools;
    if (!T) { return; }
    var esc = T.esc, api = T.api, err = T.err;
    if (typeof toastr !== 'undefined') { toastr.options = $.extend(toastr.options || {}, { positionClass: 'toast-bottom-right', timeOut: 3200, preventDuplicates: true }); }

    /* ---- the list page ---- */
    $('#ceNew').on('click', function () {
        var $b = $(this).prop('disabled', true);
        api('edit_project_create', {}, function (o) {
            if (!o || !o.success) { $b.prop('disabled', false); err(o, 'Could not start an edit.'); return; }
            window.location = '/studio/edit/' + o.project.id;
        });
    });

    var $ce = $('#ce');
    if (!$ce.length) { return; }

    var id = parseInt($ce.data('project'), 10), can_export = String($ce.data('can-export')) === '1';
    var P = null, TL = { clips: [], texts: [], overlays: [], audio: null }, assets = {}, fonts = [], selected = 0;
    var save_timer = null, saving = false, dirty = false, poll_timer = null, sortable = null;
    var POS = { top: 14, upper: 30, middle: 50, lower: 70, bottom: 86 }, SIZE = { small: 4.2, medium: 5.8, large: 7.8, huge: 10.4 };
    var POS_LABEL = { top: 'Top', upper: 'Upper', middle: 'Middle', lower: 'Lower', bottom: 'Bottom' }, SIZE_LABEL = { small: 'Small', medium: 'Medium', large: 'Large', huge: 'Huge' };
    var ANCHOR_LABEL = { top_left: 'Top Left', top_right: 'Top Right', center: 'Center', bottom_left: 'Bottom Left', bottom_right: 'Bottom Right' };
    var FONT_CSS = { sans: 'Arial, Helvetica, sans-serif', sans_bold: 'Arial, Helvetica, sans-serif', serif: 'Georgia, serif', mono: '"Courier New", monospace', heavy: 'Impact, "Arial Narrow Bold", sans-serif' };

    function num(v, d) { v = parseFloat(v); return isNaN(v) ? d : v; }
    function r2(n) { return Math.round(n * 100) / 100; }
    function clip_len(c) { return c.type === 'video' ? Math.max(0, r2(c.end - c.start)) : c.duration; }
    function total() { var t = 0; $.each(TL.clips, function (i, c) { t += clip_len(c); }); return r2(t); }
    function opts(map, cur) { return $.map(map, function (label, k) { return '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + esc(label) + '</option>'; }).join(''); }

    /* ---- saving: every change is saved a moment later ---- */
    function saved_text(t) { $('#ceSaved').text(t); }
    function touch() {
        dirty = true; saved_text('Saving');
        clearTimeout(save_timer); save_timer = setTimeout(save, 700);
        render_stage(); render_total();
    }
    function save(cb) {
        if (saving) { clearTimeout(save_timer); save_timer = setTimeout(function () { save(cb); }, 300); return; }
        clearTimeout(save_timer); saving = true; dirty = false;
        api('edit_project_save', { id: id, name: $('#ceName').val(), aspect: P.aspect, timeline: JSON.stringify(TL) }, function (o) {
            saving = false;
            if (!o || !o.success) { dirty = true; saved_text('Not saved'); err(o, 'The edit could not be saved.'); if (cb) { cb(false); } return; }
            if (!dirty) { saved_text('Saved'); apply_saved(o.project.timeline || {}); }
            problems(o.project.problems || []);
            if (cb) { cb(true); }
        });
    }
    /* The server tidies the timeline on save (drops files no longer in the Library, clamps times, fills blank ends). What is
       on screen then follows what was stored, so the preview never shows an edit that will not export. Only rows the
       creator is still filling in (a text row with no words yet) are kept as they are; nothing is redrawn when it already matches. */
    function canon(tl) {
        return JSON.stringify({
            clips: tl.clips.map(function (c) { return c.type === 'video' ? [c.asset_id, 'video', r2(num(c.start, 0)), r2(num(c.end, 0)), !!c.mute] : [c.asset_id, 'image', r2(num(c.duration, 3))]; }),
            texts: tl.texts.map(function (x) { return [String(x.text).trim(), x.font, x.size, x.position, String(x.color).toUpperCase(), r2(num(x.start, 0)), r2(num(x.end, 0)), !!x.shadow]; }),
            overlays: tl.overlays.map(function (o) { return [o.asset_id, o.anchor, Math.round(num(o.scale, 25)), r2(num(o.start, 0)), r2(num(o.end, 0))]; }),
            audio: tl.audio ? [tl.audio.asset_id, Math.round(num(tl.audio.volume, 100))] : null
        });
    }
    function apply_saved(srv) {
        var next = { clips: srv.clips || [], texts: [], overlays: srv.overlays || [], audio: srv.audio || null }, si = 0, kept = srv.texts || [];
        $.each(TL.texts, function (i, x) { if (String(x.text).trim() === '') { next.texts.push(x); } else if (kept[si]) { next.texts.push(kept[si++]); } });
        if (canon(next) === canon(TL)) { return; }
        TL = next; selected = Math.max(0, Math.min(selected, TL.clips.length - 1));
        render_clips(); render_texts(); render_overlays(); render_audio(); render_stage(); render_total();
    }
    function problems(list) {
        $('#ceProblems').prop('hidden', !list.length).html(list.map(function (p) { return '<li>' + esc(p) + '</li>'; }).join(''));
        export_button();
    }
    function export_button() {
        $('#ceExport').prop('disabled', !can_export || !TL.clips.length || (P && P.status === 'rendering') || !$('#ceProblems').prop('hidden'))
            .attr('title', can_export ? '' : 'Exporting is not available on this server');
    }

    /* ---- the preview: the selected clip with the overlays roughly where they will land ---- */
    function render_stage() {
        var c = TL.clips[selected] || TL.clips[0], a = c ? assets[c.asset_id] : null;
        $('#ceStage').attr('data-shape', P.aspect);
        $('#ceStageEmpty').prop('hidden', !!a);
        var $m = $('#ceStageMedia'), key = a ? a.id + ':' + (c.type === 'video' ? c.start : '') : '';
        if ($m.data('key') !== key) {
            $m.data('key', key).html(!a ? '' : (c.type === 'video'
                ? '<video src="' + esc(a.preview_url) + '#t=' + c.start + '" poster="' + esc(a.thumb_url) + '" muted playsinline controls preload="metadata"></video>'
                : '<img src="' + esc(a.preview_url || a.thumb_url) + '" alt="">'));
        }
        var html = '';
        $.each(TL.overlays, function (i, o) {
            var oa = assets[o.asset_id]; if (!oa) { return; }
            var css = 'width:' + o.scale + '%;';
            css += (o.anchor === 'center') ? 'left:50%;top:50%;transform:translate(-50%,-50%);'
                : ((o.anchor.indexOf('left') >= 0 ? 'left:4%;' : 'right:4%;') + (o.anchor.indexOf('top') === 0 ? 'top:2.5%;' : 'bottom:2.5%;'));
            html += '<img class="ce-stage__ov" style="' + css + '" src="' + esc(oa.thumb_url) + '" alt="">';
        });
        $.each(TL.texts, function (i, x) {
            if (String(x.text).trim() === '') { return; }
            html += '<div class="ce-stage__text' + (x.shadow ? ' has-shadow' : '') + '" style="top:' + (POS[x.position] || 70) + '%;font-size:' + (SIZE[x.size] || 5.8) + 'cqw;color:' + esc(x.color) +
                ';font-family:' + (FONT_CSS[x.font] || FONT_CSS.sans) + ';font-weight:' + (x.font === 'sans_bold' || x.font === 'mono' ? 700 : 400) + '">' + esc(x.text) + '</div>';
        });
        $('#ceStageLayer').html(html);
    }
    function render_total() {
        var t = total();
        $('#ceTotal').text(TL.clips.length ? TL.clips.length + (TL.clips.length === 1 ? ' clip' : ' clips') + ' · ' + t + ' seconds · exports at 1080 wide' : '');
        export_button();
    }

    /* ---- clips ---- */
    function render_clips() {
        $('#ceClipsNone').prop('hidden', TL.clips.length > 0);
        $('#ceClips').html(TL.clips.map(function (c, i) {
            var a = assets[c.asset_id] || {}, n = TL.clips.length;
            var fields = c.type === 'video'
                ? '<label class="ce-f ce-f--num"><span>Start</span><input type="number" class="form-control" data-f="start" min="0" max="' + (a.duration || 0) + '" step="0.1" value="' + c.start + '"></label>' +
                  '<label class="ce-f ce-f--num"><span>End</span><input type="number" class="form-control" data-f="end" min="0" max="' + (a.duration || 0) + '" step="0.1" value="' + c.end + '"></label>' +
                  '<label class="ce-switch form-check form-switch"><input class="form-check-input" type="checkbox" data-f="mute"' + (c.mute ? ' checked' : '') + '><span>Mute</span></label>'
                : '<label class="ce-f ce-f--num"><span>Seconds</span><input type="number" class="form-control" data-f="duration" min="0.5" max="30" step="0.5" value="' + c.duration + '"></label>';
            return '<li class="ce-row' + (i === selected ? ' is-on' : '') + '" data-i="' + i + '">' +
                '<div class="ce-row__grip"><i class="fa-solid fa-grip-vertical" aria-hidden="true"></i>' +
                '<button type="button" class="ce-row__thumb" data-select aria-label="Preview clip ' + (i + 1) + '">' + (a.thumb_url ? '<img src="' + esc(a.thumb_url) + '" alt="">' : '<i class="fa-solid fa-film" aria-hidden="true"></i>') + '</button></div>' +
                '<div class="ce-row__body"><span class="ce-row__name">' + (i + 1) + '. ' + esc(a.name || 'Missing file') + '</span><div class="ce-row__fields">' + fields + '</div></div>' +
                '<div class="ce-row__act"><button type="button" class="ce-ic" data-move="-1" title="Move Up" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button>' +
                '<button type="button" class="ce-ic" data-move="1" title="Move Down" aria-label="Move down"' + (i === n - 1 ? ' disabled' : '') + '><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button>' +
                '<button type="button" class="ce-ic ce-ic--rm" data-rm title="Remove" aria-label="Remove clip"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div></li>';
        }).join(''));
        if (window.Sortable && !sortable) {
            sortable = Sortable.create(document.getElementById('ceClips'), { animation: 150, handle: '.ce-row__grip', onEnd: function (e) {
                if (e.oldIndex === e.newIndex) { return; }
                var moved = TL.clips.splice(e.oldIndex, 1)[0]; TL.clips.splice(e.newIndex, 0, moved);
                selected = e.newIndex; render_clips(); touch();
            } });
        }
    }
    $('#ceAddClip').on('click', function () {
        T.pick_image({ type: 'media' }, function (a) {
            var is_video = a.type === 'video';
            assets[a.id] = { id: a.id, type: is_video ? 'video' : 'image', name: a.name, duration: a.duration || 0, thumb_url: a.thumb_url, preview_url: is_video ? a.video_url : (a.preview_url || a.thumb_url) };
            TL.clips.push(is_video ? { asset_id: a.id, type: 'video', start: 0, end: a.duration || 0, mute: false } : { asset_id: a.id, type: 'image', duration: 3 });
            selected = TL.clips.length - 1; render_clips(); touch();
        });
    });
    $('#ceClips').on('click', '[data-select]', function () { selected = parseInt($(this).closest('.ce-row').data('i'), 10); $('#ceClips .ce-row').removeClass('is-on').eq(selected).addClass('is-on'); render_stage(); });
    $('#ceClips').on('click', '[data-rm]', function () { TL.clips.splice(parseInt($(this).closest('.ce-row').data('i'), 10), 1); selected = Math.max(0, Math.min(selected, TL.clips.length - 1)); render_clips(); touch(); });
    $('#ceClips').on('click', '[data-move]', function () {
        var i = parseInt($(this).closest('.ce-row').data('i'), 10), d = parseInt($(this).data('move'), 10), to = i + d;
        if (to < 0 || to >= TL.clips.length) { return; }
        var m = TL.clips.splice(i, 1)[0]; TL.clips.splice(to, 0, m); selected = to; render_clips(); touch();
        $('#ceClips .ce-row').eq(to).find('[data-move="' + d + '"]').trigger('focus');
    });
    $('#ceClips').on('change', '[data-f]', function () {
        var c = TL.clips[parseInt($(this).closest('.ce-row').data('i'), 10)], f = $(this).data('f'), a = assets[c.asset_id] || {};
        if (f === 'mute') { c.mute = this.checked; }
        else if (f === 'duration') { c.duration = Math.max(0.5, Math.min(30, num(this.value, 3))); this.value = c.duration; }
        else {
            var max = a.duration || 9999;
            c[f] = Math.max(0, Math.min(max, r2(num(this.value, f === 'start' ? 0 : max))));
            if (c.end <= c.start) { if (f === 'start') { c.start = Math.max(0, r2(c.end - 0.5)); } else { c.end = Math.min(max, r2(c.start + 0.5)); } }
            $(this).closest('.ce-row').find('[data-f="start"]').val(c.start); $(this).closest('.ce-row').find('[data-f="end"]').val(c.end);
        }
        touch();
    });

    /* ---- text overlays ---- */
    function font_opts(cur) { return fonts.map(function (f) { return '<option value="' + esc(f.key) + '"' + (f.key === cur ? ' selected' : '') + '>' + esc(f.label) + '</option>'; }).join(''); }
    function render_texts() {
        $('#ceTextsNone').prop('hidden', TL.texts.length > 0);
        $('#ceTexts').html(TL.texts.map(function (x, i) {
            return '<li class="ce-row" data-i="' + i + '"><div class="ce-row__grip" style="cursor:default"><i class="fa-solid fa-font" aria-hidden="true"></i></div>' +
                '<div class="ce-row__body"><div class="ce-row__fields">' +
                '<label class="ce-f ce-f--wide"><span>Text</span><input type="text" class="form-control" data-f="text" maxlength="300" placeholder="Wait for it" value="' + esc(x.text) + '"></label>' +
                '<label class="ce-f"><span>Font</span><select class="form-select" data-f="font">' + font_opts(x.font) + '</select></label>' +
                '<label class="ce-f"><span>Size</span><select class="form-select" data-f="size">' + opts(SIZE_LABEL, x.size) + '</select></label>' +
                '<label class="ce-f"><span>Position</span><select class="form-select" data-f="position">' + opts(POS_LABEL, x.position) + '</select></label>' +
                '<label class="ce-f ce-f--color"><span>Color</span><input type="color" class="form-control" data-f="color" value="' + esc(x.color) + '"></label>' +
                '<label class="ce-f ce-f--num"><span>From</span><input type="number" class="form-control" data-f="start" min="0" step="0.1" value="' + x.start + '"></label>' +
                '<label class="ce-f ce-f--num"><span>To</span><input type="number" class="form-control" data-f="end" min="0" step="0.1" value="' + x.end + '"></label>' +
                '<label class="ce-switch form-check form-switch"><input class="form-check-input" type="checkbox" data-f="shadow"' + (x.shadow ? ' checked' : '') + '><span>Shadow</span></label>' +
                '</div></div><div class="ce-row__act"><button type="button" class="ce-ic ce-ic--rm" data-rm title="Remove" aria-label="Remove text"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div></li>';
        }).join(''));
    }
    $('#ceAddText').on('click', function () {
        if (TL.texts.length >= 20) { return; }
        TL.texts.push({ text: '', font: (fonts[1] || fonts[0] || { key: 'sans' }).key, size: 'medium', color: '#FFFFFF', position: 'lower', start: 0, end: total(), shadow: true });   // shadow on by default
        render_texts(); touch();
        $('#ceTexts .ce-row').last().find('[data-f="text"]').trigger('focus');
    });
    $('#ceTexts').on('input change', '[data-f]', function (e) {
        var x = TL.texts[parseInt($(this).closest('.ce-row').data('i'), 10)], f = $(this).data('f');
        if (f === 'shadow') { x.shadow = this.checked; }
        else if (f === 'start' || f === 'end') { if (e.type !== 'change') { return; } x[f] = Math.max(0, r2(num(this.value, 0))); }
        else { x[f] = this.value; }
        touch();
    });
    $('#ceTexts').on('click', '[data-rm]', function () { TL.texts.splice(parseInt($(this).closest('.ce-row').data('i'), 10), 1); render_texts(); touch(); });

    /* ---- image overlays ---- */
    function render_overlays() {
        $('#ceOverlaysNone').prop('hidden', TL.overlays.length > 0);
        $('#ceOverlays').html(TL.overlays.map(function (o, i) {
            var a = assets[o.asset_id] || {};
            return '<li class="ce-row" data-i="' + i + '"><div class="ce-row__grip" style="cursor:default"><span class="ce-row__thumb" style="cursor:default">' + (a.thumb_url ? '<img src="' + esc(a.thumb_url) + '" alt="">' : '<i class="fa-regular fa-image" aria-hidden="true"></i>') + '</span></div>' +
                '<div class="ce-row__body"><span class="ce-row__name">' + esc(a.name || 'Missing file') + '</span><div class="ce-row__fields">' +
                '<label class="ce-f"><span>Position</span><select class="form-select" data-f="anchor">' + opts(ANCHOR_LABEL, o.anchor) + '</select></label>' +
                '<label class="ce-f ce-f--num"><span>Width %</span><input type="number" class="form-control" data-f="scale" min="5" max="100" step="1" value="' + o.scale + '"></label>' +
                '<label class="ce-f ce-f--num"><span>From</span><input type="number" class="form-control" data-f="start" min="0" step="0.1" value="' + o.start + '"></label>' +
                '<label class="ce-f ce-f--num"><span>To</span><input type="number" class="form-control" data-f="end" min="0" step="0.1" value="' + o.end + '"></label>' +
                '</div></div><div class="ce-row__act"><button type="button" class="ce-ic ce-ic--rm" data-rm title="Remove" aria-label="Remove image"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div></li>';
        }).join(''));
    }
    $('#ceAddOverlay').on('click', function () {
        if (TL.overlays.length >= 10) { return; }
        T.pick_image({ title: 'Choose a PNG Image' }, function (a) {
            if (String(a.mime || '').toLowerCase() !== 'image/png' && !/\.png$/i.test(String(a.filename || a.name || ''))) { toastr.error('Image overlays need to be PNG files.'); return; }
            assets[a.id] = { id: a.id, type: 'image', name: a.name, thumb_url: a.thumb_url, preview_url: a.thumb_url, png: true };
            TL.overlays.push({ asset_id: a.id, anchor: 'top_right', scale: 25, start: 0, end: total() });
            render_overlays(); touch();
        });
    });
    $('#ceOverlays').on('change', '[data-f]', function () {
        var o = TL.overlays[parseInt($(this).closest('.ce-row').data('i'), 10)], f = $(this).data('f');
        if (f === 'anchor') { o.anchor = this.value; }
        else if (f === 'scale') { o.scale = Math.max(5, Math.min(100, Math.round(num(this.value, 25)))); this.value = o.scale; }
        else { o[f] = Math.max(0, r2(num(this.value, 0))); }
        touch();
    });
    $('#ceOverlays').on('click', '[data-rm]', function () { TL.overlays.splice(parseInt($(this).closest('.ce-row').data('i'), 10), 1); render_overlays(); touch(); });

    /* ---- audio track ---- */
    function render_audio() {
        var a = TL.audio ? assets[TL.audio.asset_id] : null;
        $('#ceAudioNone').prop('hidden', !!TL.audio);
        $('#ceAddAudio').html(TL.audio ? '<i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> Change Audio' : '<i class="fa-solid fa-plus" aria-hidden="true"></i> Choose Audio');
        $('#ceAudio').html(!TL.audio ? '' :
            '<div class="ce-audio"><span class="ce-audio__name"><i class="fa-solid fa-music" aria-hidden="true"></i><span>' + esc(a ? a.name : 'Missing file') + '</span></span>' +
            '<div class="ce-row__fields"><label class="ce-f ce-f--num"><span>Volume %</span><input type="number" class="form-control" id="ceVolume" min="0" max="100" step="5" value="' + TL.audio.volume + '"></label>' +
            '<button type="button" class="ce-ic ce-ic--rm" id="ceAudioRm" title="Remove" aria-label="Remove audio track"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>' +
            (a && a.preview_url ? '<audio controls preload="none" src="' + esc(a.preview_url) + '"></audio>' : '') + '</div>');
    }
    $('#ceAddAudio').on('click', function () {
        T.pick_image({ type: 'audio', title: 'Choose an Audio Track' }, function (a) {
            assets[a.id] = { id: a.id, type: 'audio', name: a.name, duration: a.duration || 0, preview_url: a.audio_url || '' };
            TL.audio = { asset_id: a.id, volume: TL.audio ? TL.audio.volume : 100 };
            render_audio(); touch();
        });
    });
    $('#ceAudio').on('change', '#ceVolume', function () { TL.audio.volume = Math.max(0, Math.min(100, Math.round(num(this.value, 100)))); this.value = TL.audio.volume; touch(); });
    $('#ceAudio').on('click', '#ceAudioRm', function () { TL.audio = null; render_audio(); touch(); });

    /* ---- header ---- */
    $('#ceName').on('input', touch);
    $('#ceAspect').on('click', '.cs-seg__opt', function () {
        P.aspect = String($(this).data('value'));
        $('#ceAspect .cs-seg__opt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true');
        touch();
    });
    $('#ceDelete').on('click', function () {
        Swal.fire({ title: 'Delete This Edit?', text: 'The edit is removed. Videos you already exported stay in your Library.', showCancelButton: true, confirmButtonText: 'Delete',
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }, buttonsStyling: false, reverseButtons: true })
            .then(function (r) { if (!r.isConfirmed) { return; } api('edit_project_delete', { id: id }, function (o) { if (o && o.success) { window.location = '/studio/edit'; } else { err(o, 'Could not delete the edit.'); } }); });
    });

    /* ---- export ---- */
    function render_export() {
        var $b = $('#ceExportBox');
        if (P.status === 'rendering') {
            $b.prop('hidden', false).html('<div class="ce-export__row"><span class="spinner-border spinner-border-sm text-primary" role="status"></span><span>Exporting. You can leave this page; the video will be in your Library.</span></div>');
        } else if (P.status === 'failed') {
            $b.prop('hidden', false).html('<div class="ce-export__row ce-export__row--bad"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>' + esc(P.error || 'The export failed.') + '</span></div>');
        } else if (P.status === 'done' && P.result) {
            $b.prop('hidden', false).html('<video controls playsinline preload="metadata" poster="' + esc(P.result.thumb_url) + '" src="' + esc(P.result.video_url) + '"></video>' +
                '<div class="ce-export__row"><i class="fa-solid fa-circle-check" aria-hidden="true" style="color:var(--success)"></i><span>Exported · ' + P.result.width + '×' + P.result.height + ' · ' + P.result.duration + ' seconds · saved to your Library</span></div>' +
                '<div class="ce-export__actions"><button type="button" class="btn btn-secondary btn-sm" id="ceUse"><i class="fa-solid fa-feather-pointed" aria-hidden="true"></i> Use in a Post</button>' +
                '<a class="btn btn-secondary btn-sm" href="/studio"><i class="fa-solid fa-images" aria-hidden="true"></i> Open Library</a></div>');
        } else { $b.prop('hidden', true).empty(); }
        export_button();
    }
    $('#ceExportBox').on('click', '#ceUse', function () { try { sessionStorage.setItem('cs_open_asset', String(P.result.id)); } catch (e) {} window.location = '/studio'; });
    function poll() {
        clearTimeout(poll_timer);
        api('edit_project_status', { id: id }, function (o) {
            if (!o || !o.success) { poll_timer = setTimeout(poll, 5000); return; }
            P.status = o.project.status; P.error = o.project.error; P.result = o.project.result;
            render_export();
            if (P.status === 'rendering') { poll_timer = setTimeout(poll, 4000); }
            else if (P.status === 'done') { toastr.success('Export finished'); }
        });
    }
    $('#ceExport').on('click', function () {
        var $b = $(this).prop('disabled', true);
        save(function (ok) {
            if (!ok) { export_button(); return; }
            api('edit_project_export', { id: id }, function (o) {
                if (!o || !o.success) { export_button(); if (o && o.problems) { problems(o.problems); } else { err(o, 'Could not start the export.'); } return; }
                P.status = 'rendering'; P.result = null; render_export(); poll_timer = setTimeout(poll, 3000);
            });
        });
    });
    window.addEventListener('beforeunload', function (e) { if (dirty || saving) { e.preventDefault(); e.returnValue = ''; } });

    /* ---- load ---- */
    function load() {
        $('#ceLoading').prop('hidden', false); $('#ceError, #ceBody').prop('hidden', true);
        api('edit_project_get', { id: id }, function (o) {
            $('#ceLoading').prop('hidden', true);
            if (!o || !o.success) { $('#ceError').prop('hidden', false); return; }
            P = o.project; fonts = o.fonts || []; assets = P.assets || {};
            TL = { clips: P.timeline.clips || [], texts: P.timeline.texts || [], overlays: P.timeline.overlays || [], audio: P.timeline.audio || null };
            $('#ceName').val(P.name);
            $('#ceAspect .cs-seg__opt').each(function () { var on = String($(this).data('value')) === P.aspect; $(this).toggleClass('is-on', on).attr('aria-pressed', on ? 'true' : 'false'); });
            $('#ceBody').prop('hidden', false);
            render_clips(); render_texts(); render_overlays(); render_audio(); render_stage(); render_total(); problems(P.problems && TL.clips.length ? P.problems : []); render_export();
            saved_text('Saved');
            if (P.status === 'rendering') { poll_timer = setTimeout(poll, 3000); }
        });
    }
    $('#ceRetry').on('click', load);
    load();
});
