jQuery(function ($) {
    "use strict";

    if (!$('#cs').length) return;

    var CFG = window.CS_CONFIG || { creator: {}, s3_ready: false };
    var S3_READY = !!CFG.s3_ready;
    var PART = 8388608;

    var state = {
        assets: [],
        collections: [],
        selection: new Set(),
        filters: { search: '', type: '', collection: '', usage: '' },
        loadSeq: 0
    };

    var detailOC = bootstrap.Offcanvas.getOrCreateInstance('#csDetail');

    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

    // Never trust the stored UTC default — detect the creator's real timezone and persist it
    // so scheduling, the calendar and automations all use the right zone.
    var USER_TZ = (function () { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { return ''; } })();
    (function () {
        if (!CFG.creator) { CFG.creator = {}; }
        var cur = CFG.creator.timezone || '';
        if (USER_TZ && USER_TZ !== 'UTC' && (cur === '' || cur === 'UTC')) {
            CFG.creator.timezone = USER_TZ;
            ApiDataSvc.apiCall('post', 'set_timezone', { timezone: USER_TZ }, function () {});
        }
    })();

    // Presence heartbeat — keeps the creator shown as "online" on their public profile
    // while the Studio is open, even if they're not actively clicking.
    function heartbeat() { ApiDataSvc.apiCall('post', 'heartbeat', {}, function () {}); }
    heartbeat();
    setInterval(function () { if (!document.hidden) { heartbeat(); } }, 60000);
    function apiForm(ep, fd, onProgress) {
        return $.ajax({
            url: '/api/' + ep, method: 'POST', data: fd, dataType: 'json', processData: false, contentType: false,
            xhr: function () { var x = $.ajaxSettings.xhr(); if (onProgress && x.upload) x.upload.addEventListener('progress', onProgress); return x; }
        });
    }
    function fmtBytes(b) {
        if (b == null) return '—';
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
        if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
        return (b / 1073741824).toFixed(2) + ' GB';
    }
    function fmtDuration(s) { if (!s) return ''; var m = Math.floor(s / 60), x = s % 60; return m + ':' + (x < 10 ? '0' : '') + x; }
    function fmtDate(iso) { if (!iso) return ''; var d = new Date(iso.replace(' ', 'T') + 'Z'); return isNaN(d) ? iso : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); }
    function typeIcon(t) { return t === 'video' ? 'fa-play' : (t === 'gif' ? 'fa-clapperboard' : 'fa-image'); }
    function err(o, fallback) { toastr.error((o && o.message) || fallback || 'Something went wrong. Please try again.'); }

    function dialog(opts) {
        var m = bootstrap.Modal.getOrCreateInstance('#csModal');
        $('#csModalTitle').text(opts.title);
        $('#csModalBody').html(opts.bodyHtml || '');
        var footer = $('#csModalFooter').empty();
        if (opts.extra) {
            // Bootstrap ignores show() while the modal is still hiding, so chain the next dialog off hidden.bs.modal
            $('<button type="button" class="btn btn-outline-danger me-auto">').text(opts.extra.text)
                .on('click', function () { $('#csModal').one('hidden.bs.modal', function () { setTimeout(opts.extra.onClick, 120); }); m.hide(); }).appendTo(footer);
        }
        $('<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>').appendTo(footer);
        var ok = $('<button type="button" class="btn">').addClass(opts.danger ? 'btn-danger' : 'btn-primary').text(opts.okText || 'OK')
            .on('click', function () { if (opts.onOk && opts.onOk() === false) return; m.hide(); }).appendTo(footer);
        $('#csModal').off('shown.bs.modal').one('shown.bs.modal', function () {
            var f = $('#csModal').find('input,select').first();
            if (f.length) { f.trigger('focus'); if (f[0].select) f[0].select(); }
        });
        m.show();
        return ok;
    }
    function confirmDialog(title, text, okText, danger, onOk) {
        dialog({ title: title, bodyHtml: '<p class="mb-0 text-body-secondary">' + esc(text) + '</p>', okText: okText, danger: danger, onOk: onOk });
    }
    function promptDialog(title, label, value, onOk, extraText, onExtra) {
        var body = '<label class="form-label cs-dv__label">' + esc(label) + '</label>' +
            '<input class="form-control" id="csPrompt" value="' + esc(value) + '">';
        var ok = dialog({
            title: title, bodyHtml: body, okText: 'Save',
            extra: extraText ? { text: extraText, onClick: onExtra } : null,
            onOk: function () { onOk($('#csPrompt').val()); }
        });
        $('#csModal').off('keydown').on('keydown', '#csPrompt', function (e) { if (e.key === 'Enter') { e.preventDefault(); ok.trigger('click'); } });
    }

    $('#csTabCollections').on('shown.bs.tab', showCollectionsList);
    function gotoLibraryTab() { bootstrap.Tab.getOrCreateInstance(document.getElementById('csTabLibrary')).show(); }

    var openCol = null;

    function showCollectionsList() {
        openCol = null;
        $('#csColDetail').prop('hidden', true);
        $('#csColList').prop('hidden', false);
        renderCollections();
    }

    function openCollectionView(c) {
        openCol = c;
        $('#csColList').prop('hidden', true);
        $('#csColDetail').prop('hidden', false);
        $('#csColName').text(c.name);
        $('#csColCountLbl').text('');
        $('#csColGridEmpty').prop('hidden', true);
        $('#csColGrid').prop('hidden', false).html('<div class="cs-loading"><span class="spinner-border spinner-border-sm text-primary"></span> Loading…</div>');
        ApiDataSvc.apiCall('post', 'media_list', { collection: c.id }, function (resp) { var o = JSON.parse(resp);
            var assets = (o && o.success) ? o.assets : [];
            $('#csColCountLbl').text(assets.length + (assets.length === 1 ? ' file' : ' files'));
            renderColGrid(assets);
        });
    }

    function renderColGrid(assets) {
        var $g = $('#csColGrid').empty();
        if (!assets.length) { $g.prop('hidden', true); $('#csColGridEmpty').prop('hidden', false); return; }
        $g.prop('hidden', false); $('#csColGridEmpty').prop('hidden', true);
        assets.forEach(function (a, i) {
            var $t = $('<div class="cs-tile" tabindex="0">').attr('data-id', a.id).css('animation-delay', Math.min(i * 18, 360) + 'ms');
            if (a.thumb_url) $t.append($('<img class="cs-tile__img" loading="lazy">').attr('src', a.thumb_url)); else $t.append('<div class="cs-tile__ph"><i class="fa-solid ' + typeIcon(a.type) + '"></i></div>');
            $t.append('<span class="cs-tile__type"><i class="fa-solid ' + typeIcon(a.type) + '"></i></span>');
            if (a.type === 'video' && a.duration) $t.append('<span class="cs-tile__badge"><i class="fa-solid fa-play"></i> ' + fmtDuration(a.duration) + '</span>');
            $t.append('<button type="button" class="cs-tile__rmcol" title="Remove from this collection"><i class="fa-solid fa-xmark"></i></button>');
            $g.append($t);
        });
        $g.find('.cs-tile__img').on('error', function () {
            var img = this; if (img.dataset.retried) return; img.dataset.retried = '1';
            ApiDataSvc.apiCall('post', 'media_sign', { id: $(img).closest('.cs-tile').data('id'), variant: 'thumb' }, function (resp) { var o = JSON.parse(resp); if (o && o.success && o.url) img.src = o.url; });
        });
    }

    $('#csColGrid')
        .on('click', '.cs-tile__rmcol', function (e) {
            e.stopPropagation();
            if (!openCol) return;
            var id = $(this).closest('.cs-tile').data('id');
            ApiDataSvc.apiCall('post', 'collection_remove_assets', { id: openCol.id, ids: [id] }, function (resp) { var o = JSON.parse(resp);
                if (o.success) { toastr.success('Removed from ' + openCol.name); openCollectionView(openCol); } else err(o);
            });
        })
        .on('click', '.cs-tile', function () { openDetail($(this).data('id')); });

    $('#csColBack').on('click', showCollectionsList);
    $('#csColRename').on('click', function () {
        if (!openCol) return;
        promptDialog('Rename Collection', 'Collection Name', openCol.name, function (name) {
            if (!name.trim() || name === openCol.name) return;
            ApiDataSvc.apiCall('post', 'collection_save', { id: openCol.id, name: name }, function (resp) { var o = JSON.parse(resp);
                if (o.success) { openCol.name = name; $('#csColName').text(name); toastr.success('Collection renamed'); } else err(o);
            });
        });
    });
    $('#csColDelete').on('click', function () {
        if (!openCol) return;
        confirmDialog('Delete “' + openCol.name + '”?', 'The collection is removed. Your files stay in your library.', 'Delete', true, function () {
            ApiDataSvc.apiCall('post', 'collection_delete', { id: openCol.id }, function (resp) { var o = JSON.parse(resp);
                if (o.success) { toastr.success('Collection deleted'); showCollectionsList(); } else err(o);
            });
        });
    });

    function hasFilters() { var f = state.filters; return !!(f.search || f.type || f.collection || f.usage); }

    function loadLibrary() {
        var seq = ++state.loadSeq;
        $('#csLibLoading').prop('hidden', false);
        $('#csLibError, #csLibEmpty, #csGrid').prop('hidden', true);
        ApiDataSvc.apiCall('post', 'media_list', state.filters, function (resp) { var o = JSON.parse(resp);
                if (seq !== state.loadSeq) return;
                $('#csLibLoading').prop('hidden', true);
                if (!o || !o.success) { $('#csLibError').prop('hidden', false); return; }
                state.assets = o.assets || [];
                renderGrid();
            });
    }

    function renderGrid() {
        var $grid = $('#csGrid').empty();
        $('#csClearFilters').prop('hidden', !hasFilters());

        if (!state.assets.length) {
            $('#csGrid').prop('hidden', true);
            var $e = $('#csLibEmpty').prop('hidden', false);
            if (hasFilters()) {
                $e.find('.cs-empty__title').text('No files match those filters');
                $e.find('.cs-empty__text').text('Try a different search, or clear the filters to see everything.');
                $('#csEmptyUpload').prop('hidden', true);
            } else {
                $e.find('.cs-empty__title').text('Your library is ready for its first upload');
                $e.find('.cs-empty__text').text("Drag photos or videos anywhere here, or use the button below. They'll be safe, watermarked, and ready to post.");
                $('#csEmptyUpload').prop('hidden', !S3_READY);
            }
            return;
        }
        $('#csLibEmpty').prop('hidden', true);
        $('#csGrid').prop('hidden', false);
        state.assets.forEach(function (a, i) { $grid.append(buildTile(a, i)); });
        $grid.find('.cs-tile__img').on('error', function () {
            var img = this;
            if (img.dataset.retried) return;
            img.dataset.retried = '1';
            ApiDataSvc.apiCall('post', 'media_sign', { id: $(img).closest('.cs-tile').data('id'), variant: 'thumb' }, function (resp) { var o = JSON.parse(resp); if (o && o.success && o.url) img.src = o.url; });
        });
    }

    function buildTile(a, i) {
        var $t = $('<div class="cs-tile" tabindex="0">').attr('data-id', a.id).attr('data-type', a.type)
            .css('animation-delay', Math.min(i * 18, 360) + 'ms');
        if (state.selection.has(a.id)) $t.addClass('is-selected');

        var blocked = a.moderation === 'blocked';
        // A blocked asset never re-displays its content — show a placeholder, not the thumbnail.
        if (a.status === 'ready' && a.thumb_url && !blocked) {
            $('<img class="cs-tile__img" loading="lazy">').attr('alt', a.name || '').attr('src', a.thumb_url).appendTo($t);
        } else {
            $('<div class="cs-tile__ph"><i class="fa-solid ' + (blocked ? 'fa-ban' : typeIcon(a.type)) + '"></i></div>').appendTo($t);
        }
        $('<span class="cs-tile__type"><i class="fa-solid ' + typeIcon(a.type) + '"></i></span>').appendTo($t);
        if (a.type === 'video' && a.duration) $('<span class="cs-tile__badge"><i class="fa-solid fa-play"></i> ' + fmtDuration(a.duration) + '</span>').appendTo($t);
        if (a.moderation === 'flagged') $('<span class="cs-tile__adult" title="Marked adult — shown only to fans with adult content on">18+</span>').appendTo($t);
        else if (a.moderation === 'pending' && a.status === 'ready') $('<span class="cs-tile__scan" title="Checking content…"><i class="fa-solid fa-shield-halved"></i></span>').appendTo($t);
        if (a.usage_count > 0) $('<span class="cs-tile__use">In ' + a.usage_count + '</span>').appendTo($t);
        if (a.status === 'processing' || a.status === 'uploading') $('<div class="cs-tile__state"><span class="spinner-border spinner-border-sm"></span> Processing…</div>').appendTo($t);
        else if (a.status === 'failed') $('<div class="cs-tile__state cs-tile__state--failed"><i class="fa-solid fa-circle-exclamation"></i> Upload failed</div>').appendTo($t);
        else if (blocked) $('<div class="cs-tile__state cs-tile__state--blocked"><i class="fa-solid fa-ban"></i> Blocked</div>').appendTo($t);
        $('<span class="cs-tile__check"><i class="fa-solid fa-check"></i></span>').appendTo($t);
        return $t;
    }

    // grid interactions (delegated)
    $('#csGrid')
        .on('click', '.cs-tile__check', function (e) { e.stopPropagation(); toggleSelect($(this).closest('.cs-tile')); })
        .on('click', '.cs-tile', function () {
            var $t = $(this);
            if (state.selection.size > 0) { toggleSelect($t); return; }
            if (assetById($t.data('id')).status === 'ready') openDetail($t.data('id'));
        })
        .on('keydown', '.cs-tile', function (e) {
            var $t = $(this);
            if (e.key === 'Enter' && assetById($t.data('id')).status === 'ready') openDetail($t.data('id'));
            if (e.key === ' ') { e.preventDefault(); toggleSelect($t); }
        });

    function assetById(id) { return state.assets.filter(function (a) { return a.id == id; })[0] || {}; }

    // =====================================================================
    // Selection + bulk
    // =====================================================================
    function toggleSelect($t) {
        var id = $t.data('id');
        if (state.selection.has(id)) state.selection.delete(id); else state.selection.add(id);
        $t.toggleClass('is-selected', state.selection.has(id));
        updateSelbar();
    }
    function clearSelection() { state.selection.clear(); $('.cs-tile.is-selected').removeClass('is-selected'); updateSelbar(); }
    function updateSelbar() { var n = state.selection.size; $('#csSelbar').prop('hidden', n === 0); $('#csSelCount').text(n); }

    $('#csSelClear').on('click', clearSelection);
    $('[data-bulk]').on('click', function () { runBulk($(this).data('bulk')); });

    function runBulk(action) {
        var ids = Array.from(state.selection);
        if (!ids.length) return;
        if (action === 'delete') {
            confirmDialog('Remove ' + ids.length + ' file' + (ids.length > 1 ? 's' : '') + '?',
                'They will be taken out of your library and removed from any collections. Posts already using them will show the file as missing.',
                'Remove', true, function () {
                    ApiDataSvc.apiCall('post', 'media_bulk', { bulk_action: 'delete', ids: ids }, function (resp) { var o = JSON.parse(resp);
                        if (o.success) { toastr.success(o.message); clearSelection(); loadLibrary(); } else err(o);
                    });
                });
        } else if (action === 'collection_add') {
            chooseCollection(function (colId) {
                ApiDataSvc.apiCall('post', 'media_bulk', { bulk_action: 'collection_add', ids: ids, collection_id: colId }, function (resp) { var o = JSON.parse(resp);
                    if (o.success) { toastr.success(o.message); clearSelection(); loadCollections(); } else err(o);
                });
            });
        }
    }

    // =====================================================================
    // Uploads (button + drag/drop) → tray with progress + retry
    // =====================================================================
    var uploadOnComplete = null;   // set when an upload should also attach to the composer
    function pickFiles() {
        if (!S3_READY) { toastr.info("Media storage isn't set up yet, so uploads are off."); return; }
        $('#csFileInput').val('').trigger('click');
    }
    $('#csUploadBtn, #csEmptyUpload').on('click', function () { uploadOnComplete = null; pickFiles(); });
    $('#csFileInput').on('change', function () { var cb = uploadOnComplete; uploadOnComplete = null; handleFiles(this.files, cb); });
    $('#csTrayClose').on('click', function () { $('#csTray').prop('hidden', true); });

    var $dz = $('#csDropzone');
    $dz.on('dragenter dragover', function (e) { e.preventDefault(); if (S3_READY) $dz.addClass('is-dragover'); });
    $dz.on('dragleave', function (e) { e.preventDefault(); if (e.target === $dz[0]) $dz.removeClass('is-dragover'); });
    $dz.on('drop', function (e) {
        e.preventDefault(); $dz.removeClass('is-dragover');
        if (!S3_READY) { toastr.info("Media storage isn't set up yet, so uploads are off."); return; }
        var dt = e.originalEvent.dataTransfer;
        if (dt && dt.files) handleFiles(dt.files);
    });

    function handleFiles(list, onComplete) {
        var files = Array.prototype.slice.call(list);
        if (!files.length) return;
        $('#csTray').prop('hidden', false);
        files.forEach(function (f) { startUpload(f, onComplete); });
    }

    function trayItem(file) {
        var isVideo = file.type.indexOf('video') === 0;
        var $row = $(
            '<div class="cs-up">' +
            '<div class="cs-up__thumb"><i class="fa-solid ' + typeIcon(isVideo ? 'video' : 'image') + '"></i></div>' +
            '<div class="cs-up__body">' +
            '<div class="cs-up__name"></div>' +
            '<div class="cs-up__bar"><div class="cs-up__fill"></div></div>' +
            '<div class="cs-up__status">Starting…</div>' +
            '</div></div>');
        $row.find('.cs-up__name').text(file.name);
        $('#csTrayList').prepend($row);
        return {
            setProgress: function (p) { $row.find('.cs-up__fill').css('width', Math.max(2, Math.min(100, p)) + '%'); },
            setStatus: function (t, cls) {
                $row.find('.cs-up__status').removeClass('cs-up__status--done cs-up__status--failed').addClass(cls ? 'cs-up__status--' + cls : '').text(t);
                // Finished rows clear themselves after a moment; the tray hides once it's empty.
                if (cls === 'done') {
                    setTimeout(function () {
                        $row.fadeOut(250, function () {
                            $row.remove();
                            if (!$('#csTrayList').children().length) { $('#csTray').prop('hidden', true); }
                        });
                    }, 2000);
                }
            },
            setThumb: function (url) { if (url) $row.find('.cs-up__thumb').html('<img class="cs-up__thumb" src="' + esc(url) + '">'); },
            retry: function (fn) { $('<button type="button" class="cs-up__retry">Retry</button>').on('click', function () { $(this).remove(); fn(); }).appendTo($row.find('.cs-up__status')); }
        };
    }

    function startUpload(file, onComplete) {
        var ui = trayItem(file);
        if (file.type.indexOf('video') === 0) uploadVideo(file, ui, onComplete); else uploadImage(file, ui, onComplete);
    }

    function uploadImage(file, ui, onComplete) {
        var fd = new FormData(); fd.append('file', file);
        ui.setStatus('Uploading…');
        apiForm('media_upload', fd, function (e) { if (e.lengthComputable) ui.setProgress(e.loaded / e.total * 92); })
            .done(function (o) {
                if (o && o.success) { ui.setProgress(100); ui.setStatus('Done', 'done'); if (o.asset) ui.setThumb(o.asset.thumb_url); injectAsset(o.asset); if (onComplete && o.asset) onComplete(o.asset); }
                else { ui.setStatus((o && o.message) || 'Upload failed', 'failed'); ui.retry(function () { uploadImage(file, ui, onComplete); }); }
            })
            .fail(function () { ui.setStatus('Upload failed — check your connection', 'failed'); ui.retry(function () { uploadImage(file, ui, onComplete); }); });
    }

    function uploadVideo(file, ui, onComplete) {
        var token = file.name + '|' + file.size + '|' + file.lastModified;
        ui.setStatus('Preparing…');
        ApiDataSvc.apiCall('post', 'media_upload_init', { filename: file.name, mime: file.type, bytes_total: file.size, client_token: token }, function (resp) { var init = JSON.parse(resp);
                if (!init || !init.success) { ui.setStatus((init && init.message) || 'Could not start upload', 'failed'); ui.retry(function () { uploadVideo(file, ui, onComplete); }); return; }
                var partSize = init.part_size || PART, done = {};
                (init.uploaded_parts || []).forEach(function (p) { done[p] = true; });
                var total = Math.ceil(file.size / partSize);
                if (init.resumed) ui.setStatus('Resuming…');

                function sendPart(p) {
                    if (p > total) { finishVideo(init.session_id, file, ui, onComplete); return; }
                    if (done[p]) { ui.setProgress(p / total * 96); return sendPart(p + 1); }
                    var blob = file.slice((p - 1) * partSize, Math.min(p * partSize, file.size));
                    var fd = new FormData(); fd.append('session_id', init.session_id); fd.append('part_number', p); fd.append('chunk', blob);
                    var attempts = 0;
                    (function attempt() {
                        attempts++;
                        ui.setStatus('Uploading ' + Math.round(p / total * 100) + '%');
                        apiForm('media_upload_chunk', fd)
                            .done(function (o) {
                                if (o && o.success) { ui.setProgress(o.bytes_received / file.size * 96); sendPart(p + 1); }
                                else if (attempts < 3) setTimeout(attempt, 800 * attempts);
                                else { ui.setStatus((o && o.message) || 'A chunk failed', 'failed'); ui.retry(function () { uploadVideo(file, ui, onComplete); }); }
                            })
                            .fail(function () {
                                if (attempts < 4) setTimeout(attempt, 1000 * attempts);
                                else { ui.setStatus('Connection dropped — you can resume', 'failed'); ui.retry(function () { uploadVideo(file, ui, onComplete); }); }
                            });
                    })();
                }
                sendPart(1);
            });
    }

    // Grab one frame (~1s in) from the local file so the server has a poster even when
    // it can't decode video itself. Resolves with a JPEG Blob, or null if the browser can't.
    function capturePosterFrame(file) {
        return new Promise(function (resolve) {
            var done = false, url, meta = { duration: 0, width: 0, height: 0 };
            function finish(blob) { if (done) return; done = true; if (url) URL.revokeObjectURL(url); resolve({ blob: blob || null, meta: meta }); }
            try {
                url = URL.createObjectURL(file);
                var v = document.createElement('video');
                v.muted = true; v.playsInline = true; v.preload = 'auto'; v.src = url;
                var timer = setTimeout(function () { finish(null); }, 8000);
                v.addEventListener('error', function () { clearTimeout(timer); finish(null); });
                v.addEventListener('loadedmetadata', function () {
                    meta = { duration: Math.round(v.duration || 0), width: v.videoWidth || 0, height: v.videoHeight || 0 };
                    try { v.currentTime = Math.min(1, Math.max(0, (v.duration || 0) / 2)); } catch (e) { finish(null); }
                });
                v.addEventListener('seeked', function () {
                    try {
                        var w = v.videoWidth, h = v.videoHeight;
                        if (!w || !h) { clearTimeout(timer); finish(null); return; }
                        var scale = Math.min(1, 1280 / w);
                        var c = document.createElement('canvas'); c.width = Math.round(w * scale); c.height = Math.round(h * scale);
                        c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
                        c.toBlob(function (b) { clearTimeout(timer); finish(b); }, 'image/jpeg', 0.86);
                    } catch (e) { clearTimeout(timer); finish(null); }
                });
            } catch (e) { finish(null); }
        });
    }

    function finishVideo(sessionId, file, ui, onComplete) {
        ui.setStatus('Finishing…'); ui.setProgress(98);
        capturePosterFrame(file).then(function (cap) {
            function send(withPoster) {
                var fd = new FormData(), csrf = document.querySelector('meta[name="csrf-token"]');
                fd.append('session_id', sessionId);
                fd.append('csrf_token', csrf ? csrf.getAttribute('content') : '');
                fd.append('client_duration', cap.meta.duration); fd.append('client_width', cap.meta.width); fd.append('client_height', cap.meta.height);
                if (withPoster && cap.blob) fd.append('poster', cap.blob, 'poster.jpg');
                return apiForm('media_upload_complete', fd);
            }
            function failed(xhr) {
                var msg = 'Could not finish (HTTP ' + (xhr && xhr.status) + ')';
                var body = (xhr && xhr.responseText) || '';
                try { var o = JSON.parse(body); if (o && o.message) msg = o.message; }
                catch (e) { if (body) msg += ': ' + body.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160); }
                if (window.console) console.error('media_upload_complete failed', xhr && xhr.status, body.slice(0, 500));
                ui.setStatus(msg, 'failed'); ui.retry(function () { uploadVideo(file, ui, onComplete); });
            }
            function done(o) {
                if (o && o.success) { ui.setProgress(100); ui.setStatus('Done', 'done'); if (o.asset) ui.setThumb(o.asset.thumb_url); injectAsset(o.asset); if (onComplete && o.asset) onComplete(o.asset); }
                else { ui.setStatus((o && o.message) || 'Could not finish', 'failed'); ui.retry(function () { uploadVideo(file, ui, onComplete); }); }
            }
            send(true).done(done).fail(function (xhr) {
                // If the request itself was rejected (not a JSON "no" from the app), try once
                // more without the poster so a server that dislikes the extra part still finishes.
                if (cap.blob && xhr && (xhr.status === 0 || xhr.status === 413 || xhr.status >= 500)) {
                    send(false).done(done).fail(failed);
                } else { failed(xhr); }
            });
        });
    }

    function injectAsset(asset) {
        if (!asset) return;
        if (hasFilters()) return;
        var i = state.assets.findIndex(function (a) { return a.id === asset.id; });
        if (i >= 0) state.assets[i] = asset; else state.assets.unshift(asset);
        renderGrid();
    }

    // =====================================================================
    // AI image generation
    // =====================================================================
    var genModal, lastGenAsset = null;
    var GEN_LABEL = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate';
    var REGEN_LABEL = '<i class="fa-solid fa-rotate"></i> Regenerate';
    // 'edit' → inputs + [Generate/Regenerate]; 'busy' → all disabled; 'result' → image + [Regenerate][Use in a post].
    function setGenState(s) {
        if (s === 'busy') { $('#csGenRun, #csGenEdit, #csGenUse').prop('disabled', true); return; }
        if (s === 'result') {
            $('#csGenInputs').prop('hidden', true);
            $('#csGenPreview, #csGenResult').prop('hidden', false);
            $('#csGenStatus').prop('hidden', true);
            $('#csGenRun').prop('hidden', true);
            $('#csGenEdit, #csGenUse').prop('hidden', false).prop('disabled', false);
        } else { // edit
            $('#csGenInputs').prop('hidden', false);
            $('#csGenPreview, #csGenResult').prop('hidden', true);
            $('#csGenStatus').prop('hidden', true).removeClass('is-error');
            $('#csGenRun').prop('hidden', false).prop('disabled', false).html(lastGenAsset ? REGEN_LABEL : GEN_LABEL);
            $('#csGenEdit, #csGenUse').prop('hidden', true);
        }
    }
    function genError(msg) {
        $('#csGenPrompt, #csGenSize, #csGenBrand').prop('disabled', false);
        setGenState('edit');
        $('#csGenStatus').prop('hidden', false).addClass('is-error').text(msg);
    }
    $('#csGenerateBtn').on('click', function () {
        if (!genModal) genModal = bootstrap.Modal.getOrCreateInstance('#csGenerate');
        var b = CFG.brand || {};
        if (b.has_brand) {
            $('#csGenBrandName').text(b.brand_name ? ('“' + b.brand_name + '”') : '');
            $('#csGenBrandRow').prop('hidden', false);
        } else {
            $('#csGenBrandRow').prop('hidden', true);
        }
        lastGenAsset = null;
        $('#csGenStatus').empty();
        setGenState('edit');
        genModal.show();
    });

    function runGeneration() {
        var prompt = ($('#csGenPrompt').val() || '').trim();
        if (prompt === '') { toastr.info('Describe the image you want.'); $('#csGenPrompt').focus(); return; }
        var b = CFG.brand || {};
        var useBrand = (b.has_brand && $('#csGenBrand').is(':checked')) ? '1' : '0';
        $('#csGenPrompt, #csGenSize, #csGenBrand').prop('disabled', true);
        setGenState('busy');
        $('#csGenStatus').prop('hidden', false).removeClass('is-error')
            .html('<span class="spinner-border spinner-border-sm text-primary"></span> Creating your image — this can take up to a minute.');

        ApiDataSvc.apiCall('post', 'media_generate', { prompt: prompt, size: $('#csGenSize').val(), use_brand: useBrand }, function (data) {
            $('#csGenPrompt, #csGenSize, #csGenBrand').prop('disabled', false);
            var o = JSON.parse(data);
            if (!o || !o.success) { genError((o && o.message) || 'Generation failed. Try again.'); return; }
            lastGenAsset = o.asset;
            injectAsset(o.asset);
            var url = (o.asset && o.asset.thumb_url) || '';
            if (url) { $('#csGenPreviewImg').attr('src', url); }
            $('#csGenSavedMsg').text('Saved to your Library' + (o.brand_used ? ' · matched to your brand' : '') + '.');
            setGenState('result');
            toastr.success('Image added to your Library.');
        });
    }
    $('#csGenRun').on('click', runGeneration);

    // "Regenerate" from the result view → bring the inputs back to tweak (button now reads "Regenerate").
    $('#csGenEdit').on('click', function () { setGenState('edit'); $('#csGenPrompt').focus(); });

    // Take the generated image straight into a fresh post.
    $('#csGenUse').on('click', function () {
        if (!lastGenAsset) return;
        var asset = lastGenAsset;
        if (genModal) genModal.hide();
        newComposer();
        composerModal.show();
        composerAddAsset(asset);
    });

    // =====================================================================
    // Filters
    // =====================================================================
    var searchTimer;
    $('#csSearch').on('input', function () {
        var v = this.value.trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { state.filters.search = v; loadLibrary(); }, 220);
    });
    $('#csFilterType').on('change', function () { state.filters.type = this.value; loadLibrary(); });
    $('#csFilterCollection').on('change', function () { state.filters.collection = this.value; loadLibrary(); });
    $('#csFilterUsage').on('change', function () { state.filters.usage = this.value; loadLibrary(); });
    $('#csClearFilters').on('click', function () {
        state.filters = { search: '', type: '', collection: '', usage: '' };
        $('#csSearch').val(''); $('#csFilterType,#csFilterCollection,#csFilterUsage').val('');
        loadLibrary();
    });
    $('#csLibRetry').on('click', loadLibrary);

    // =====================================================================
    // Collections
    // =====================================================================
    function loadCollections(cb) {
        ApiDataSvc.apiCall('post', 'collections_list', function (resp) { var o = JSON.parse(resp);
            state.collections = (o && o.success) ? o.collections : [];
            var $sel = $('#csFilterCollection'), cur = $sel.val();
            $sel.find('option:not(:first)').remove();
            state.collections.forEach(function (c) { $('<option>').val(c.id).text(c.name + ' (' + c.asset_count + ')').appendTo($sel); });
            $sel.val(cur);
            if (cb) cb();
        });
    }

    function renderCollections() {
        loadCollections(function () {
            var $wrap = $('#csCollections').empty();
            $('#csColEmpty').prop('hidden', state.collections.length > 0);
            state.collections.forEach(function (c) {
                var $row = $(
                    '<div class="cs-col">' +
                    '<span class="cs-col__icon"><i class="fa-solid fa-folder"></i></span>' +
                    '<span><span class="cs-col__name"></span><span class="cs-col__count d-block"></span></span>' +
                    '<button type="button" class="cs-col__menu" title="Options"><i class="fa-solid fa-ellipsis"></i></button>' +
                    '</div>');
                $row.find('.cs-col__name').text(c.name);
                $row.find('.cs-col__count').text(c.asset_count + ' file' + (c.asset_count === 1 ? '' : 's'));
                $row.on('click', function (e) {
                    if ($(e.target).closest('.cs-col__menu').length) { collectionMenu(c); return; }
                    openCollectionView(c);
                });
                $wrap.append($row);
            });
        });
    }

    function collectionMenu(c) {
        promptDialog('Update Collection', 'Collection Name', c.name,
            function (name) {
                if (name.trim() && name !== c.name) ApiDataSvc.apiCall('post', 'collection_save', { id: c.id, name: name }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success('Collection renamed'); renderCollections(); } else err(o); });
            },
            'Delete', function () {
                confirmDialog('Delete “' + c.name + '”?', 'The collection is removed. Your files stay in your library.', 'Delete', true, function () {
                    ApiDataSvc.apiCall('post', 'collection_delete', { id: c.id }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success('Collection deleted'); renderCollections(); } else err(o); });
                });
            });
    }

    $('#csNewCollectionBtn, #csCreateCollectionBtn').on('click', function () {
        promptDialog('New Collection', 'Collection Name', '', function (name) {
            if (!name.trim()) return;
            ApiDataSvc.apiCall('post', 'collection_save', { name: name }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success('Collection created'); renderCollections(); } else err(o); });
        });
    });

    function chooseCollection(onPick) {
        if (!state.collections.length) {
            promptDialog('Add to a New Collection', 'Collection Name', '', function (name) {
                if (!name.trim()) return;
                ApiDataSvc.apiCall('post', 'collection_save', { name: name }, function (resp) { var o = JSON.parse(resp); if (o.success) { loadCollections(); onPick(o.id); } else err(o); });
            });
            return;
        }
        var opts = state.collections.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('');
        dialog({ title: 'Add to collection', okText: 'Add', bodyHtml: '<label class="form-label cs-dv__label">Collection</label><select id="csColPick" class="form-select">' + opts + '</select>', onOk: function () { onPick($('#csColPick').val()); } });
    }

    function openDetail(id) {
        $('#csDetailBody').html('<div class="cs-loading"><span class="spinner-border spinner-border-sm text-primary"></span> Loading…</div>');
        detailOC.show();
        ApiDataSvc.apiCall('post', 'media_get', { id: id }, function (resp) { var o = JSON.parse(resp); if (o && o.success) renderDetail(o.asset); else $('#csDetailBody').html('<div class="cs-error"><i class="fa-solid fa-circle-exclamation"></i><p>' + esc((o && o.message) || 'Could not load this file.') + '</p></div>'); });
    }

    function renderDetail(a) {
        var preview = a.type === 'video'
            ? '<video controls preload="metadata" poster="' + esc(a.preview_url) + '" src="' + esc(a.video_url) + '"></video>'
            : '<img src="' + esc(a.preview_url) + '" alt="' + esc(a.name) + '">';
        var dims = (a.width && a.height) ? (a.width + ' × ' + a.height) : '—';
        var meta = a.type === 'video'
            ? '<dt>Duration</dt><dd>' + (fmtDuration(a.duration) || '—') + '</dd><dt>Dimensions</dt><dd>' + dims + '</dd>'
            : '<dt>Dimensions</dt><dd>' + dims + '</dd><dt>Type</dt><dd>' + esc(String(a.type).toUpperCase()) + '</dd>';
        var cols = state.collections.map(function (c) {
            var on = (a.collection_ids || []).indexOf(c.id) >= 0;
            return '<button type="button" class="cs-chip' + (on ? ' is-on' : '') + '" data-col="' + c.id + '"><i class="fa-solid ' + (on ? 'fa-check' : 'fa-plus') + '"></i> ' + esc(c.name) + '</button>';
        }).join('') || '<span class="cs-col__count">No collections yet — create one to group this file.</span>';
        var posts = (a.posts && a.posts.length)
            ? '<ul class="cs-dv__posts">' + a.posts.map(function (p) { return '<li><i class="fa-solid fa-rectangle-list"></i> ' + esc(p.excerpt) + ' <em>· ' + esc(p.state) + '</em></li>'; }).join('') + '</ul>'
            : '<p class="cs-col__count">Not used in any post yet.</p>';
        var wm = a.type === 'video'
            ? '<div class="cs-dv__toggle"><span>Watermark<small>Shown as an overlay on the video player.</small></span></div>'
            : '<div class="cs-dv__toggle"><span>Watermark<small>Your name, baked into the delivered image.</small></span>' +
              '<div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="csWm"' + (a.watermark_applied ? ' checked' : '') + '></div></div>';

        $('#csDetailBody').html(
            '<div class="cs-dv__preview">' + preview + '</div>' +
            '<div class="mb-3"><label class="form-label cs-dv__label" for="csDvDesc">Description</label><textarea class="form-control" id="csDvDesc" rows="3" placeholder="Add a note about this file — what it is, where it\'s from, how you plan to use it.">' + esc(a.description || '') + '</textarea></div>' +
            '<button type="button" class="btn btn-outline-secondary w-100 mb-3" id="csDvSave">Save description</button>' +
            '<dl class="cs-dv__meta">' + meta + '<dt>Size</dt><dd>' + fmtBytes(a.bytes) + '</dd><dt>Uploaded</dt><dd>' + fmtDate(a.created_at) + '</dd></dl>' +
            wm +
            '<label class="form-label cs-dv__label">Add to a collection</label><div class="cs-dv__cols">' + cols + '</div>' +
            '<label class="form-label cs-dv__label">Used in</label>' + posts +
            '<button type="button" class="btn btn-outline-danger w-100 mt-3" id="csDvDelete"><i class="fa-solid fa-trash"></i> Remove file</button>'
        );

        $('#csDvSave').on('click', function () {
            ApiDataSvc.apiCall('post', 'media_update', { id: a.id, description: $('#csDvDesc').val() }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success('Description saved'); mergeAsset(o.asset); } else err(o); });
        });
        $('#csWm').on('change', function () {
            var $w = $(this).prop('disabled', true), on = this.checked;
            var $pv = $('#csDetailBody .cs-dv__preview').addClass('is-busy').toggleClass('wm-on', on).toggleClass('wm-off', !on);
            ApiDataSvc.apiCall('post', 'media_watermark', { id: a.id, enabled: on ? '1' : '0' }, function (resp) { var o = JSON.parse(resp);
                    $w.prop('disabled', false);
                    if (!o.success) { $pv.removeClass('is-busy wm-on wm-off'); $w.prop('checked', !on); err(o); return; }
                    toastr.success(on ? 'Watermark on' : 'Watermark off');
                    a.watermark_applied = on ? 1 : 0;
                    mergeAsset(o.asset);
                    // The server overwrote the renditions in place, so re-sign and swap
                    // the preview + grid thumb to force a fresh (cache-busted) load.
                    ApiDataSvc.apiCall('post', 'media_sign', { id: a.id, variant: 'display' }, function (resp) { var s = JSON.parse(resp);
                        $pv.removeClass('is-busy wm-on wm-off');
                        if (s.success && s.url) { $pv.find('img').attr('src', s.url); a.preview_url = s.url; }
                    });
                });
        });
        $('#csDetailBody').find('[data-col]').on('click', function () {
            var $chip = $(this), colId = $chip.data('col'), on = $chip.hasClass('is-on');
            ApiDataSvc.apiCall('post', on ? 'collection_remove_assets' : 'collection_add_assets', { id: colId, ids: [a.id] }, function (resp) { var o = JSON.parse(resp);
                if (o.success) {
                    $chip.toggleClass('is-on');
                    var nm = state.collections.filter(function (c) { return String(c.id) === String(colId); })[0].name;
                    $chip.html('<i class="fa-solid ' + (!on ? 'fa-check' : 'fa-plus') + '"></i> ' + esc(nm));
                    toastr.success(!on ? 'Added to ' + nm : 'Removed from ' + nm);
                    loadCollections();
                } else err(o);
            });
        });
        $('#csDvDelete').on('click', function () {
            var n = (a.posts || []).length;
            var msg = n > 0
                ? 'This file is used in ' + n + ' post' + (n > 1 ? 's' : '') + '. Those posts will show the file as missing. This can be undone from support within 30 days.'
                : 'It will be taken out of your library. This can be undone from support within 30 days.';
            confirmDialog('Remove this file?', msg, 'Remove', true, function () {
                ApiDataSvc.apiCall('post', 'media_delete', { id: a.id }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success('File removed'); detailOC.hide(); loadLibrary(); } else err(o); });
            });
        });
    }

    function mergeAsset(asset) {
        if (!asset) return;
        var i = state.assets.findIndex(function (a) { return a.id === asset.id; });
        if (i >= 0) { state.assets[i] = $.extend(state.assets[i], asset); renderGrid(); }
    }

    // =====================================================================
    // Misc + init
    // =====================================================================
    // =====================================================================
    // Post composer
    // =====================================================================
    var composer = { id: null, caption: '', audience: 'free', tier_id: '', ppv_price: 5, comments_enabled: 1, assets: [], coverDisplay: '', coverBlurred: '', view: 'sub', validation: { ok: true, reason: '' }, saveTimer: null };
    composer.share = new Set();
    function socialIcon(pl){ var m={x:'fa-x-twitter',twitter:'fa-x-twitter',facebook:'fa-facebook',youtube:'fa-youtube',tiktok:'fa-tiktok',pinterest:'fa-pinterest',linkedin:'fa-linkedin',instagram:'fa-instagram'}; return m[pl]||'fa-share-nodes'; }
    function socialIconClass(pl){ return pl === 'fanvue' ? 'fa-solid fa-bolt' : 'fa-brands ' + socialIcon(pl); }
    function renderSocial(){
        var $wrap = $('#csCompSocial').empty();
        var soc = CFG.social || { accounts: [], can_post: false };
        if (!soc.accounts || !soc.accounts.length) { $wrap.html('<p class="cs-comp__socialnote">No accounts connected yet — <a href="/account/settings?section=connected">connect your accounts</a> to cross-post.</p>'); return; }
        if (!soc.can_post) { $wrap.html('<p class="cs-comp__socialnote">Sharing to social is not part of your current plan. <a href="/account/billing">See plans</a>.</p>'); return; }
        soc.accounts.forEach(function (a) {
            var on = composer.share.has(a.id);
            $wrap.append('<label class="cs-social"><input class="form-check-input" type="checkbox" data-acct="' + esc(a.id) + '"' + (on ? ' checked' : '') + '><i class="' + socialIconClass(a.platform) + '"></i><span class="cs-social__name">' + esc(a.username || a.platform) + '</span></label>');
        });
    }
    $('#csCompSocial').on('change', 'input[data-acct]', function () { var id = String($(this).data('acct')); if (this.checked) composer.share.add(id); else composer.share.delete(id); });
    var composerModal = bootstrap.Modal.getOrCreateInstance('#csComposer');
    var pickerModal = bootstrap.Modal.getOrCreateInstance('#csPicker');
    var pickerAssets = [], pickerSel = new Set();
    var TZ = (CFG.creator && CFG.creator.timezone) ? CFG.creator.timezone : 'UTC';
    $('#csCompTz').text(TZ);
    // subscription tiers for audience targeting
    (CFG.plans || []).forEach(function (p) { $('#csCompTierSel').append($('<option>').val(String(p.id)).text(p.name + ' · $' + (p.price_cents / 100).toFixed(2) + '/mo')); });

    $('#csNewPostBtn').on('click', function () { openComposer(null); });

    function openComposer(postId) {
        if (postId) { composerModal.show(); loadPost(postId); return; }
        ApiDataSvc.apiCall('post', 'post_open_draft', function (resp) { var o = JSON.parse(resp);
            if (o && o.success && o.draft) {
                dialog({
                    title: 'Resume your draft?',
                    bodyHtml: '<p class="text-body-secondary mb-0">You have an unfinished post. Pick up where you left off, or start fresh.</p>',
                    okText: 'Resume Draft',
                    extra: { text: 'Start New', onClick: function () { newComposer(); composerModal.show(); } },
                    onOk: function () { setComposer(o.draft); composerModal.show(); }
                });
            } else { newComposer(); composerModal.show(); }
        });
    }

    function resetScheduleUI() { $('#csCompSchedule').prop('hidden', true); $('#csSchedule').text('Schedule'); }
    function newComposer() {
        composer = { id: null, caption: '', audience: 'free', tier_id: '', ppv_price: 5, comments_enabled: 1, assets: [], coverDisplay: '', coverBlurred: '', view: 'sub', validation: { ok: true, reason: '' }, saveTimer: null };
        composer.share = new Set();
        composer.state = 'draft';
        $('#csCompTitle').text('New Post');
        $('#csCompSchedAt').val('');
        resetScheduleUI();
        setSaveStatus('');
        renderComposer();
    }
    function setComposer(p) {
        composer.id = p.id; composer.caption = p.caption || ''; composer.audience = p.audience || 'free';
        composer.tier_id = p.tier_id ? String(p.tier_id) : '';
        composer.moderation = p.moderation || 'ok';
        composer.ppv_price = p.ppv_price_dollars || 5;
        composer.comments_enabled = (p.comments_enabled != null) ? p.comments_enabled : 1;
        composer.share = new Set((p.shared_accounts || []).map(String));
        composer.state = p.state || 'draft';
        composer.assets = p.assets || []; composer.coverDisplay = p.cover_display_url || ''; composer.coverBlurred = p.cover_blurred_url || '';
        composer.validation = p.validation || { ok: true, reason: '' };
        $('#csCompTitle').text(p.state === 'published' ? 'Edit Post' : 'New Post');
        resetScheduleUI();
        if (p.scheduled_local) $('#csCompSchedAt').val(p.scheduled_local);
        setSaveStatus('Saved');
        renderComposer();
    }
    function loadPost(id) {
        $('#csPreviewCard').html('<div class="cs-loading"><span class="spinner-border spinner-border-sm text-primary"></span> Loading…</div>');
        ApiDataSvc.apiCall('post', 'post_get', { id: id }, function (resp) { var o = JSON.parse(resp); if (o && o.success) setComposer(o.post); else { toastr.error((o && o.message) || 'Could not open that post.'); composerModal.hide(); } });
    }

    function coverId() { return composer.assets.length ? composer.assets[0].id : 0; }

    // Notice shown in the composer when the post's media is flagged adult (or still scanning).
    function renderModNote() {
        var $note = $('#csCompModNote');
        if (!$note.length) {
            // Place it above the "Who can see this" label that precedes the audience control.
            var $anchor = $('#csCompAudience').prevAll('.cs-dv__label').first();
            if (!$anchor.length) { $anchor = $('#csCompAudience'); }
            $note = $('<div id="csCompModNote" class="cs-comp__modnote" hidden></div>').insertBefore($anchor);
        }
        if (composer.moderation === 'blocked') {
            $note.attr('class', 'cs-comp__modnote cs-comp__modnote--blocked').prop('hidden', false)
                .html('<i class="fa-solid fa-ban"></i> This post contains media that was <strong>blocked</strong> by our content check and can\'t be published. Remove it to continue.');
        } else if (composer.moderation === 'flagged') {
            $note.attr('class', 'cs-comp__modnote cs-comp__modnote--adult').prop('hidden', false)
                .html('<i class="fa-solid fa-circle-exclamation"></i> This post is marked <strong>adult</strong> — it will only be shown to fans who have adult content turned on.');
        } else if (composer.moderation === 'pending') {
            $note.attr('class', 'cs-comp__modnote cs-comp__modnote--scan').prop('hidden', false)
                .html('<i class="fa-solid fa-shield-halved"></i> Checking your media for adult content — it won\'t be visible to fans until the check finishes.');
        } else {
            $note.prop('hidden', true).empty();
        }
    }

    function renderComposer() {
        $('#csCompCaption').val(composer.caption);
        
        $('#csCompCount').text(composer.caption.length);
        $('#csCompAudience .cs-seg__opt').each(function () { $(this).toggleClass('is-on', $(this).data('aud') === composer.audience); });
        $('#csCompTier').prop('hidden', composer.audience !== 'subscribers');
        $('#csCompTierSel').val(composer.tier_id || '');
        $('#csCompPpv').prop('hidden', composer.audience !== 'ppv');
        $('#csCompPpvPrice').val(composer.ppv_price || 5);
        renderPpvCredits();
        $('#csCompComments').prop('checked', composer.comments_enabled != 0);
        renderModNote();
        renderSocial();
        renderCompMedia();
        renderPreview();
        updateValidation();
        var pub = composer.state === 'published';
        $('#csPublishNow').text(pub ? 'Update post' : 'Publish Now');
        $('#csSchedule, #csSaveDraft').toggle(!pub);
    }

    function renderCompMedia() {
        var $m = $('#csCompMedia').empty();
        if (!composer.assets.length) { $m.append('<div class="cs-comp__mediaempty"><i class="fa-solid fa-cloud-arrow-up"></i><span>Add photos or video</span><small>Pick from your library or upload — drag to reorder, first is the cover.</small></div>'); return; }
        composer.assets.forEach(function (a, i) {
            var $it = $('<div class="cs-comp__item" draggable="true">').attr('data-id', a.id).attr('data-i', i);
            if (a.missing) { $it.addClass('is-missing').append('<div class="cs-comp__itemph"><i class="fa-solid fa-triangle-exclamation"></i></div>'); }
            else if (a.thumb_url) { $it.append($('<img>').attr('src', a.thumb_url)); }
            else { $it.append('<div class="cs-comp__itemph"><i class="fa-solid ' + typeIcon(a.type) + '"></i></div>'); }
            if (i === 0) $it.append('<span class="cs-comp__cover">Cover</span>');
            if (a.type === 'video') $it.append('<span class="cs-comp__vid"><i class="fa-solid fa-play"></i></span>');
            $it.append('<button type="button" class="cs-comp__rm" title="Remove from post"><i class="fa-solid fa-xmark"></i></button>');
            $m.append($it);
        });
    }

    var dragIdx = null;
    $('#csCompMedia')
        .on('dragstart', '.cs-comp__item', function (e) { dragIdx = $(this).data('i'); e.originalEvent.dataTransfer.effectAllowed = 'move'; })
        .on('dragover', '.cs-comp__item', function (e) { e.preventDefault(); })
        .on('drop', '.cs-comp__item', function (e) {
            e.preventDefault(); var to = $(this).data('i');
            if (dragIdx === null || to === dragIdx) return;
            var m = composer.assets.splice(dragIdx, 1)[0]; composer.assets.splice(to, 0, m);
            if (to === 0 || dragIdx === 0) { composer.coverDisplay = ''; composer.coverBlurred = ''; } // cover changed
            dragIdx = null;
            renderCompMedia(); renderPreview(); scheduleSave();
        })
        .on('click', '.cs-comp__rm', function (e) {
            e.stopPropagation(); var id = $(this).closest('.cs-comp__item').data('id');
            var wasCover = composer.assets.length && composer.assets[0].id == id;
            composer.assets = composer.assets.filter(function (a) { return a.id != id; });
            if (wasCover) { composer.coverDisplay = ''; composer.coverBlurred = ''; } // stale cover — let it refresh
            renderCompMedia(); renderPreview(); scheduleSave();
        });

    function setSaveStatus(t) { $('#csCompSave').text(t); }
    function scheduleSave() { clearTimeout(composer.saveTimer); setSaveStatus('Saving…'); composer.saveTimer = setTimeout(function () { saveNow(); }, 800); }
    function saveNow(cb) {
        var data = { id: composer.id || 0, caption: composer.caption, audience: composer.audience, tier_id: (composer.audience === 'subscribers' ? (composer.tier_id || '') : ''), ppv_price: (composer.audience === 'ppv' ? (composer.ppv_price || '') : ''), asset_ids: composer.assets.map(function (a) { return a.id; }), cover_id: coverId(), comments_enabled: (composer.comments_enabled ? '1' : '0') };
        ApiDataSvc.apiCall('post', 'post_save', data, function (resp) { var o = JSON.parse(resp);
                if (o && o.success) {
                    composer.id = o.id;
                    var oldIds = composer.assets.map(function (a) { return a.id; }).join(',');
                    var newIds = (o.post.assets || []).map(function (a) { return a.id; }).join(',');
                    var hadCover = !!composer.coverDisplay;
                    composer.coverDisplay = o.post.cover_display_url; composer.coverBlurred = o.post.cover_blurred_url;
                    composer.assets = o.post.assets; composer.validation = o.post.validation;
                    setSaveStatus('Saved');
                    // Only rebuild the media (which re-fetches images) when the media set
                    // actually changed, or the cover just became available. Otherwise a
                    // caption-only autosave leaves the images untouched.
                    if (oldIds !== newIds || (!hadCover && composer.coverDisplay)) { renderCompMedia(); renderPreview(); }
                    else { updatePreviewCaption(); }
                    updateValidation();
                    if (cb) cb(true);
                } else { setSaveStatus('Not saved'); if (cb) cb(false); }
            });
    }

    function renderPreview() {
        var isPpv = composer.audience === 'ppv';
        // PPV is locked for everyone until purchased, so it shows locked in both preview views.
        var locked = isPpv || (composer.view === 'pub' && composer.audience === 'subscribers');
        var assets = composer.assets;
        var media;
        if (!assets.length) {
            media = '<div class="cs-pv__media cs-pv__media--empty"><i class="fa-solid fa-image"></i></div>';
        } else {
            if (composer.pvIdx == null || composer.pvIdx >= assets.length || composer.pvIdx < 0) composer.pvIdx = 0;
            // Preview from each asset's (watermarked) thumb; the locked view CSS-blurs them so the
            // creator sees roughly what a non-subscriber gets. Real delivery uses server variants.
            var slides = assets.map(function (a, i) {
                var inner;
                if (a.type === 'video' && a.video_url && !locked) {
                    inner = '<video src="' + esc(a.video_url) + '"' + (a.thumb_url ? ' poster="' + esc(a.thumb_url) + '"' : '') + ' controls preload="metadata" playsinline></video>';
                } else {
                    inner = a.thumb_url ? '<img src="' + esc(a.thumb_url) + '">' : '<div class="cs-pv__slideph"><i class="fa-solid ' + typeIcon(a.type) + '"></i></div>';
                    if (a.type === 'video') { inner += '<span class="cs-pv__play"><i class="fa-solid fa-play"></i></span>'; }
                }
                return '<div class="cs-pv__slide' + (i === composer.pvIdx ? ' is-on' : '') + '">' + inner + '</div>';
            }).join('');
            var nav = '';
            if (assets.length > 1) {
                nav = '<button type="button" class="cs-pv__nav cs-pv__nav--prev" data-pv="prev" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></button>' +
                      '<button type="button" class="cs-pv__nav cs-pv__nav--next" data-pv="next" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></button>' +
                      '<div class="cs-pv__dots">' + assets.map(function (a, i) { return '<span class="cs-pv__dot' + (i === composer.pvIdx ? ' is-on' : '') + '"></span>'; }).join('') + '</div>';
            }
            var lockLabel = isPpv ? ('Unlock for $' + Math.max(3, Math.min(500, parseInt(composer.ppv_price, 10) || 0))) : 'Subscribe to Unlock';
            var lock = locked ? '<div class="cs-pv__lock"><i class="fa-solid ' + (isPpv ? 'fa-dollar-sign' : 'fa-lock') + '"></i><span>' + lockLabel + '</span></div>' : '';
            media = '<div class="cs-pv__media cs-pv__media--carousel' + (locked ? ' is-locked' : '') + '">' + slides + lock + nav + '</div>';
        }
        var cap = composer.caption ? '<p class="cs-pv__cap">' + esc(composer.caption) + '</p>' : '<p class="cs-pv__cap cs-pv__cap--muted">Your caption appears here.</p>';
        var cr = CFG.creator || {};
        var avStyle = cr.avatar_url ? (' style="background-image:url(\'' + esc(cr.avatar_url) + '\')"') : '';
        var avInit  = cr.avatar_url ? '' : esc((cr.display_name || '?').charAt(0).toUpperCase());
        var head = '<div class="cs-pv__head">' +
            '<span class="cs-pv__avatar"' + avStyle + '>' + avInit + '</span>' +
            '<span class="cs-pv__who"><span class="cs-pv__name">' + esc(cr.display_name || 'Your name') + '</span>' +
            '<span class="cs-pv__handle">@' + esc(cr.u_name || 'handle') + '</span></span></div>';
        $('#csPreviewCard').html(head + media + '<div class="cs-pv__body">' + cap + '</div>');
        pvAutoplay();
    }

    // Carousel nav — toggle the active slide/dot without re-rendering (no image refetch).
    var pvTimer = null;
    function pvGo(dir) {
        var $slides = $('#csPreviewCard .cs-pv__slide'); var n = $slides.length; if (n < 2) return;
        var cur = $slides.index($slides.filter('.is-on'));
        var next = (cur + dir + n) % n;
        $slides.removeClass('is-on').eq(next).addClass('is-on');
        $('#csPreviewCard .cs-pv__dot').removeClass('is-on').eq(next).addClass('is-on');
        composer.pvIdx = next;
    }
    function pvAutoplay() {
        clearInterval(pvTimer);
        if ($('#csPreviewCard .cs-pv__slide').length > 1) {
            pvTimer = setInterval(function () {
                if ($('#csPreviewCard .cs-pv__slide').length < 2) { clearInterval(pvTimer); return; }
                var v = document.querySelector('#csPreviewCard .cs-pv__slide.is-on video');
                if (v && !v.paused) { return; } // don't interrupt a playing video
                pvGo(1);
            }, 3500);
        }
    }
    $('#csPreviewCard').on('click', '.cs-pv__nav', function () {
        pvGo($(this).data('pv') === 'next' ? 1 : -1);
        pvAutoplay(); // restart the countdown after a manual move
    });
    $('#csComposer').on('hidden.bs.modal', function () { clearInterval(pvTimer); });

    // Update ONLY the caption in the preview — never rebuild the <img> (that refetch
    // is what made the image flicker on every keystroke).
    function updatePreviewCaption() {
        var cap = composer.caption ? '<p class="cs-pv__cap">' + esc(composer.caption) + '</p>' : '<p class="cs-pv__cap cs-pv__cap--muted">Your caption appears here.</p>';
        var $body = $('#csPreviewCard .cs-pv__body');
        if ($body.length) { $body.html(cap); } else { renderPreview(); }
    }

    function updateValidation() {
        $('#csCompValidation').prop('hidden', true).text('');
    }

    $('#csCompCaption').on('input', function () { composer.caption = this.value; $('#csCompCount').text(this.value.length); updatePreviewCaption(); scheduleSave(); });
    $('#csCompAudience').on('click', '.cs-seg__opt', function () {
        composer.audience = $(this).data('aud');
        if (composer.audience !== 'subscribers') composer.tier_id = '';
        $('#csCompAudience .cs-seg__opt').removeClass('is-on'); $(this).addClass('is-on');
        $('#csCompTier').prop('hidden', composer.audience !== 'subscribers');
        $('#csCompPpv').prop('hidden', composer.audience !== 'ppv');
        renderPreview(); updateValidation(); scheduleSave();
    });
    $('#csCompTierSel').on('change', function () { composer.tier_id = this.value; scheduleSave(); });
    // PPV price (dollars). Clamp $3–$500; show the credits equivalent ($1 = 10 credits).
    function renderPpvCredits() { $('#csCompPpvCredits').text('= ' + (Math.max(3, Math.min(500, parseInt(composer.ppv_price, 10) || 0)) * 10) + ' credits'); }
    $('#csCompPpvPrice').on('input', function () { composer.ppv_price = this.value; renderPpvCredits(); updateValidation(); scheduleSave(); });
    $('#csCompPpvPrice').on('blur', function () { var d = Math.max(3, Math.min(500, parseInt(this.value, 10) || 3)); composer.ppv_price = d; this.value = d; renderPpvCredits(); scheduleSave(); });
    $('#csCompComments').on('change', function () { composer.comments_enabled = this.checked ? 1 : 0; scheduleSave(); });
    $('#csCompView').on('click', '.cs-seg__opt', function () { composer.view = $(this).data('view'); $('#csCompView .cs-seg__opt').removeClass('is-on'); $(this).addClass('is-on'); renderPreview(); });

    $('#csCompUpload').on('click', function () { uploadOnComplete = composerAddAsset; pickFiles(); });
    function composerAddAsset(asset) {
        if (!asset || composer.assets.some(function (a) { return a.id === asset.id; })) return;
        composer.assets.push({ id: asset.id, type: asset.type, thumb_url: asset.thumb_url, video_url: asset.video_url || '', duration: asset.duration, status: asset.status, is_cover: 0, missing: false });
        renderCompMedia(); renderPreview(); scheduleSave();
    }

    $('#csCompAdd').on('click', openPicker);
    function openPicker() {
        pickerSel = new Set();
        $('#csPickerGrid').html('<div class="cs-loading"><span class="spinner-border spinner-border-sm text-primary"></span> Loading…</div>').prop('hidden', false);
        $('#csPickerEmpty').prop('hidden', true);
        $('#csPickerCount').text('0');
        pickerModal.show();
        ApiDataSvc.apiCall('post', 'media_list', {}, function (resp) { var o = JSON.parse(resp);
            pickerAssets = (o && o.success ? o.assets : []).filter(function (a) { return a.status === 'ready'; });
            renderPicker();
        });
    }
    function renderPicker() {
        var $g = $('#csPickerGrid').empty();
        if (!pickerAssets.length) { $g.prop('hidden', true); $('#csPickerEmpty').prop('hidden', false); return; }
        $g.prop('hidden', false);
        var inPost = {}; composer.assets.forEach(function (a) { inPost[a.id] = true; });
        pickerAssets.forEach(function (a) {
            var $t = $('<div class="cs-tile cs-tile--pick" tabindex="0">').attr('data-id', a.id);
            if (a.thumb_url) $t.append($('<img class="cs-tile__img">').attr('src', a.thumb_url)); else $t.append('<div class="cs-tile__ph"><i class="fa-solid ' + typeIcon(a.type) + '"></i></div>');
            if (a.type === 'video' && a.duration) $t.append('<span class="cs-tile__badge"><i class="fa-solid fa-play"></i> ' + fmtDuration(a.duration) + '</span>');
            if (inPost[a.id]) $t.addClass('is-added').append('<span class="cs-tile__added">Added</span>');
            $t.append('<span class="cs-tile__check"><i class="fa-solid fa-check"></i></span>');
            $g.append($t);
        });
    }
    $('#csPickerGrid').on('click', '.cs-tile--pick', function () {
        var $t = $(this); if ($t.hasClass('is-added')) return;
        var id = $t.data('id');
        if (pickerSel.has(id)) pickerSel.delete(id); else pickerSel.add(id);
        $t.toggleClass('is-selected', pickerSel.has(id));
        $('#csPickerCount').text(pickerSel.size);
    });
    $('#csPickerAdd').on('click', function () {
        Array.from(pickerSel).forEach(function (id) {
            var a = pickerAssets.filter(function (x) { return x.id === id; })[0];
            if (a && !composer.assets.some(function (x) { return x.id === id; })) composer.assets.push({ id: a.id, type: a.type, thumb_url: a.thumb_url, video_url: a.video_url || '', duration: a.duration, status: a.status, is_cover: 0, missing: false });
        });
        pickerModal.hide(); renderCompMedia(); renderPreview(); scheduleSave();
    });

    function runAction(kind) {
        var $btns = $('#csPublishNow, #csSchedule, #csSaveDraft').prop('disabled', true);
        function done() { $btns.prop('disabled', false); }
        function fail(o) { done(); $('#csCompValidation').prop('hidden', false).html('<i class="fa-solid fa-circle-info"></i> ' + esc((o && o.message) || 'Something went wrong.')); }
        saveNow(function (ok) {
            if (!ok) { done(); toastr.error('Could not save the post. Please try again.'); return; }
            // Empty post: nothing was saved (id 0), so there's nothing to publish/schedule.
            if (!composer.id) {
                done();
                if (kind === 'draft') { toastr.info('Add a caption or media before saving a draft.'); }
                else { fail({ message: 'Add a photo, video, or caption before publishing.' }); }
                return;
            }
            if (kind === 'update') { done(); toastr.success('Post updated'); composerModal.hide(); afterComposer(); return; }
            if (kind === 'draft') ApiDataSvc.apiCall('post', 'post_save_draft', { id: composer.id }, function (resp) { var o = JSON.parse(resp); done(); if (o.success) { toastr.success('Saved as draft'); composerModal.hide(); afterComposer(); } else fail(o); });
            else if (kind === 'schedule') ApiDataSvc.apiCall('post', 'post_schedule', { id: composer.id, scheduled_at: $('#csCompSchedAt').val(), share_accounts: Array.from(composer.share) }, function (resp) { var o = JSON.parse(resp); done(); if (o.success) { toastr.success('Scheduled'); composerModal.hide(); afterComposer(); } else fail(o); });
            else ApiDataSvc.apiCall('post', 'post_publish', { id: composer.id, share_accounts: Array.from(composer.share) }, function (resp) { var o = JSON.parse(resp); done(); if (o.success) { toastr.success('Published'); composerModal.hide(); afterComposer(); } else fail(o); });
        });
    }
    $('#csPublishNow').on('click', function () { runAction(composer.state === 'published' ? 'update' : 'publish'); });
    $('#csSaveDraft').on('click', function () { runAction('draft'); });
    $('#csSchedule').on('click', function () {
        if ($('#csCompSchedule').prop('hidden')) {
            if (!$('#csCompSchedAt').val()) {
                var d = new Date(Date.now() + 3600000); d.setSeconds(0, 0);
                var pad = function (n) { return (n < 10 ? '0' : '') + n; };
                $('#csCompSchedAt').val(d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()));
            }
            $('#csCompSchedule').prop('hidden', false);
            $('#csSchedule').text('Schedule for this time');
            $('#csCompSchedAt').trigger('focus');
            return;
        }
        runAction('schedule');
    });
    function afterComposer() { loadLibrary(); loadPosts(); loadCalendar(); }

    if (typeof toastr !== 'undefined') {
        toastr.options = $.extend(toastr.options || {}, { positionClass: 'toast-bottom-right', timeOut: 3200, preventDuplicates: true });
    }

    var postFilters = { state: '', search: '' };
    var postSel = new Set();

    $('#csTabPosts').on('shown.bs.tab', loadPosts);
    $('#csPostsRetry').on('click', loadPosts);
    $('#csPostsEmptyNew').on('click', function () { openComposer(null); });

    function fmtMoney(c) { return (Math.max(0, c || 0) / 100).toFixed(2); }
    function sharePostDialog(id) {
        var soc = CFG.social || { accounts: [], can_post: false };
        if (!soc.accounts || !soc.accounts.length) { toastr.info('Connect social accounts in Settings first.'); return; }
        if (!soc.can_post) { toastr.info('Sharing to social is not part of your current plan.'); return; }
        var boxes = soc.accounts.map(function (a) {
            return '<label class="cs-social"><input class="form-check-input" type="checkbox" value="' + esc(a.id) + '"><i class="' + socialIconClass(a.platform) + '"></i><span class="cs-social__name">' + esc(a.username || a.platform) + '</span></label>';
        }).join('');
        dialog({
            title: 'Share to Social', okText: 'Share',
            bodyHtml: '<div class="cs-comp__social" id="csShareBoxes">' + boxes + '</div>',
            onOk: function () {
                var ids = $('#csShareBoxes input:checked').map(function () { return this.value; }).get();
                if (!ids.length) { toastr.info('Pick at least one account.'); return false; }
                ApiDataSvc.apiCall('post', 'post_share', { id: id, share_accounts: ids }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success(o.message); loadPosts(); } else err(o); });
            }
        });
    }
    function hasPostFilters() { return !!(postFilters.state || postFilters.search); }

    function loadPosts() {
        $('#csPostsLoading').prop('hidden', false);
        $('#csPostsError, #csPostsEmpty, #csPostsList').prop('hidden', true);
        ApiDataSvc.apiCall('post', 'posts_list', postFilters, function (resp) { var o = JSON.parse(resp);
                $('#csPostsLoading').prop('hidden', true);
                if (!o || !o.success) { $('#csPostsError').prop('hidden', false); return; }
                renderPosts(o.posts || []);
            });
    }

    function renderPosts(posts) {
        var $list = $('#csPostsList').empty();
        clearPostSel();
        if (!posts.length) {
            $('#csPostsList').prop('hidden', true);
            var $e = $('#csPostsEmpty').prop('hidden', false);
            if (hasPostFilters()) {
                $e.find('.cs-empty__title').text('No posts match');
                $e.find('.cs-empty__text').text('Try a different search or status.');
                $('#csPostsEmptyNew').prop('hidden', true);
            } else {
                $e.find('.cs-empty__title').text('No posts yet');
                $e.find('.cs-empty__text').text('Create your first post — publish it now, schedule it, or save a draft.');
                $('#csPostsEmptyNew').prop('hidden', false);
            }
            return;
        }
        $('#csPostsEmpty').prop('hidden', true);
        $('#csPostsList').prop('hidden', false);
        posts.forEach(function (p) { $list.append(buildPostRow(p)); });
        $list.find('.cs-post__cover img').on('error', function () { $(this).hide(); });
    }

    function buildPostRow(p) {
        var cover = p.cover_url
            ? '<img src="' + esc(p.cover_url) + '" alt="">'
            : '<i class="fa-solid ' + typeIcon(p.cover_type || 'image') + '"></i>';
        if (p.asset_count > 1) cover += '<span class="cs-post__num">' + p.asset_count + '</span>';
        var badge = '<span class="cs-badge cs-badge--' + p.state + '">' + esc(p.state) + '</span>';
        var aud = p.audience === 'subscribers'
            ? '<span class="cs-post__aud"><i class="fa-solid fa-lock"></i> Subscribers</span>'
            : (p.audience === 'ppv'
                ? '<span class="cs-post__aud cs-post__aud--ppv"><i class="fa-solid fa-dollar-sign"></i> PPV · $' + (p.ppv_price_dollars || 0) + '</span>'
                : '<span class="cs-post__aud"><i class="fa-solid fa-globe"></i> Everyone</span>');
        var adult = (p.moderation === 'flagged') ? '<span class="cs-post__adult" title="Marked adult — shown only to fans with adult content on">18+</span>'
            : (p.moderation === 'blocked') ? '<span class="cs-post__blocked" title="Blocked by content check — cannot be published"><i class="fa-solid fa-ban"></i> Blocked</span>' : '';
        var when = p.when ? '<span class="cs-post__when">' + esc(p.when_label) + ' ' + esc(p.when) + '</span>' : '';
        var missing = p.media_missing ? '<span class="cs-post__warn"><i class="fa-solid fa-triangle-exclamation"></i> Media removed</span>' : '';
        var cap = p.caption ? esc(p.caption) : '<em class="cs-post__nocap">No caption</em>';
        var stats =
            '<span class="cs-post__stat"><i class="fa-regular fa-eye"></i> ' + p.views + '</span>' +
            '<span class="cs-post__stat"><i class="fa-regular fa-comment"></i> ' + p.comments + '</span>' +
            (p.audience === 'ppv' ? '<span class="cs-post__stat" title="Unlocks"><i class="fa-solid fa-lock-open"></i> ' + p.ppv_unlocks + '</span>' : '') +
            '<span class="cs-post__stat cs-post__earn">$' + fmtMoney(p.earnings_cents) + '</span>' +
            (p.shared_count > 0 ? '<span class="cs-post__stat"><i class="fa-solid fa-share-nodes"></i> ' + p.shared_count + '</span>' : '');
        var reschedule = (p.state === 'scheduled')
            ? '<li><button class="dropdown-item" data-post-act="reschedule"><i class="fa-solid fa-clock"></i> Reschedule</button></li>' : '';
        var arch = (p.state === 'archived')
            ? '<li><button class="dropdown-item" data-post-act="unarchive"><i class="fa-solid fa-box-open"></i> Unarchive</button></li>'
            : '<li><button class="dropdown-item" data-post-act="archive"><i class="fa-solid fa-box-archive"></i> Archive</button></li>';
        return '<div class="cs-post" data-id="' + p.id + '">' +
            '<label class="cs-post__check"><input type="checkbox"></label>' +
            '<div class="cs-post__cover" data-post-edit>' + cover + '</div>' +
            '<div class="cs-post__main" data-post-edit>' +
                '<div class="cs-post__cap">' + cap + '</div>' +
                '<div class="cs-post__meta">' + badge + aud + adult + when + missing + '</div>' +
            '</div>' +
            '<div class="cs-post__stats">' + stats + '</div>' +
            '<div class="cs-post__act">' +
                '<button type="button" class="btn btn-sm btn-outline-secondary" data-post-edit>Edit</button>' +
                '<div class="dropdown">' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More"><i class="fa-solid fa-ellipsis"></i></button>' +
                    '<ul class="dropdown-menu dropdown-menu-end">' +
                        reschedule +
                        '<li><button class="dropdown-item" data-post-act="duplicate"><i class="fa-solid fa-copy"></i> Duplicate</button></li>' +
                        '<li><button class="dropdown-item" data-post-act="share"><i class="fa-solid fa-share-nodes"></i> Share to Social</button></li>' +
                        arch +
                        '<li><hr class="dropdown-divider"></li>' +
                        '<li><button class="dropdown-item text-danger" data-post-act="delete"><i class="fa-solid fa-trash"></i> Remove</button></li>' +
                    '</ul>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    $('#csPostsList')
        .on('change', '.cs-post__check input', function () {
            var id = +$(this).closest('.cs-post').data('id');
            if (this.checked) postSel.add(id); else postSel.delete(id);
            $(this).closest('.cs-post').toggleClass('is-selected', this.checked);
            updatePostSelbar();
        })
        .on('click', '[data-post-edit]', function (e) { e.stopPropagation(); openComposer(+$(this).closest('.cs-post').data('id')); })
        .on('click', '[data-post-act]', function (e) { e.stopPropagation(); postAction($(this).data('post-act'), +$(this).closest('.cs-post').data('id')); });

    function postAction(act, id) {
        if (act === 'duplicate') {
            ApiDataSvc.apiCall('post', 'post_duplicate', { id: id }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success(o.message); loadPosts(); } else err(o); });
        } else if (act === 'archive') {
            ApiDataSvc.apiCall('post', 'post_archive', { id: id }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success(o.message); loadPosts(); } else err(o); });
        } else if (act === 'unarchive') {
            ApiDataSvc.apiCall('post', 'post_archive', { id: id, unarchive: '1' }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success(o.message); loadPosts(); } else err(o); });
        } else if (act === 'share') {
            sharePostDialog(id);
        } else if (act === 'reschedule') {
            dialog({
                title: 'Reschedule post', okText: 'Schedule',
                bodyHtml: '<label class="form-label cs-dv__label">New date & time</label><input type="datetime-local" class="form-control" id="csReschedAt"><small class="text-muted">In your timezone (' + esc(TZ) + ').</small>',
                onOk: function () { var v = $('#csReschedAt').val(); if (!v) return false; ApiDataSvc.apiCall('post', 'post_schedule', { id: id, scheduled_at: v }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success('Rescheduled'); loadPosts(); } else err(o); }); }
            });
        } else if (act === 'delete') {
            confirmDialog('Remove this post?', 'It will be permanently removed. This cannot be undone.', 'Remove', true, function () {
                ApiDataSvc.apiCall('post', 'post_delete', { id: id }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success(o.message); loadPosts(); } else err(o); });
            });
        }
    }

    var postSearchTimer;
    $('#csPostSearch').on('input', function () { var v = this.value.trim(); clearTimeout(postSearchTimer); postSearchTimer = setTimeout(function () { postFilters.search = v; loadPosts(); }, 220); });
    $('#csPostFilter').on('change', function () { postFilters.state = this.value; loadPosts(); });

    function clearPostSel() { postSel.clear(); $('.cs-post.is-selected').removeClass('is-selected'); $('.cs-post__check input').prop('checked', false); updatePostSelbar(); }
    function updatePostSelbar() { var n = postSel.size; $('#csPostSelbar').prop('hidden', n === 0); $('#csPostSelCount').text(n); }
    $('#csPostSelClear').on('click', clearPostSel);
    $('[data-pbulk]').on('click', function () {
        var act = $(this).data('pbulk'), ids = Array.from(postSel);
        if (!ids.length) return;
        if (act === 'delete') {
            confirmDialog('Remove ' + ids.length + ' post' + (ids.length > 1 ? 's' : '') + '?', 'They will be permanently removed. This cannot be undone.', 'Remove', true, function () {
                ApiDataSvc.apiCall('post', 'posts_bulk', { bulk_action: 'delete', ids: ids }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success(o.message); loadPosts(); } else err(o); });
            });
        } else {
            confirmDialog('Archive ' + ids.length + ' post' + (ids.length > 1 ? 's' : '') + '?', 'Archived posts are hidden from your active list but not deleted.', 'Archive', false, function () {
                ApiDataSvc.apiCall('post', 'posts_bulk', { bulk_action: 'archive', ids: ids }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success(o.message); loadPosts(); } else err(o); });
            });
        }
    });



    // =====================================================================
    // Calendar
    // =====================================================================
    var calView = 'month';
    var calAnchor = (function () { var d = new Date(); d.setHours(0, 0, 0, 0); return d; })();
    var calItems = [];
    var calDragId = null, calDragFrom = null;
    var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    $('#csTabCalendar').on('shown.bs.tab', loadCalendar);
    $('#csCalPrev').on('click', function () { shiftCal(-1); });
    $('#csCalNext').on('click', function () { shiftCal(1); });
    $('#csCalToday').on('click', function () { calAnchor = startOfToday(); renderCalendar(); });
    $('#csCalView').on('click', '.cs-seg__opt', function () { calView = $(this).data('cal'); $('#csCalView .cs-seg__opt').removeClass('is-on'); $(this).addClass('is-on'); renderCalendar(); });

    function startOfToday() { var d = new Date(); d.setHours(0, 0, 0, 0); return d; }
    function ymd(d) { var m = d.getMonth() + 1, day = d.getDate(); return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day; }
    function shiftCal(dir) { if (calView === 'month') calAnchor.setMonth(calAnchor.getMonth() + dir); else calAnchor.setDate(calAnchor.getDate() + dir * 7); renderCalendar(); }

    function loadCalendar() {
        $('#csCal').html('<div class="cs-loading"><span class="spinner-border spinner-border-sm text-primary"></span> Loading…</div>');
        ApiDataSvc.apiCall('post', 'posts_calendar', function (resp) { var o = JSON.parse(resp);
                if (!o || !o.success) { $('#csCal').html('<div class="cs-error"><i class="fa-solid fa-circle-exclamation"></i><p>Could not load the calendar.</p></div>'); return; }
                calItems = o.items || [];
                renderQueue(o.queue || {});
                renderCalendar();
            });
    }

    function renderQueue(q) {
        var $q = $('#csCalQueue').prop('hidden', false);
        var autoIds = {};
        calItems.forEach(function (it) { if (it.state === 'automation' && it.rule_id) { autoIds[it.rule_id] = 1; } });
        var autoN = Object.keys(autoIds).length;
        var autoTxt = '<i class="fa-solid fa-robot"></i> <strong>' + autoN + '</strong> automation' + (autoN === 1 ? '' : 's') + ' posting on schedule';

        if (q.scheduled_count) {
            $q.addClass('cs-queue--ok').html('<i class="fa-solid fa-layer-group"></i> <strong>' + q.scheduled_count + '</strong> scheduled · queue reaches <strong>' + esc(q.reaches) + '</strong> (' + q.days_ahead + ' day' + (q.days_ahead === 1 ? '' : 's') + ' out)' + (autoN ? ' · ' + autoTxt : ''));
            return;
        }
        if (autoN) { $q.addClass('cs-queue--ok').html(autoTxt); return; }
        $q.removeClass('cs-queue--ok').html('<i class="fa-solid fa-circle-info"></i> No scheduled posts — your queue is empty. Click any day to schedule one.');
    }

    function itemsByDate() {
        var map = {};
        calItems.forEach(function (it) { (map[it.date] = map[it.date] || []).push(it); });
        Object.keys(map).forEach(function (k) { map[k].sort(function (a, b) { return a.iso < b.iso ? -1 : 1; }); });
        return map;
    }

    function renderCalendar() { var map = itemsByDate(); if (calView === 'month') renderMonth(map); else renderWeek(map); }

    function calChip(it) {
        var isAuto = it.state === 'automation';
        var cover = isAuto ? '<i class="fa-solid fa-robot"></i>'
            : (it.cover_url ? '<img src="' + esc(it.cover_url) + '" onerror="this.remove()">' : '<i class="fa-solid ' + typeIcon(it.cover_type || 'image') + '"></i>');
        var cap = it.caption ? esc(it.caption) : (isAuto ? 'Automation' : 'Untitled');
        return '<div class="cs-cchip cs-cchip--' + it.state + '"' + (it.state === 'scheduled' ? ' draggable="true"' : '') +
            ' data-id="' + it.id + '"' + (isAuto ? ' data-rule="' + it.rule_id + '"' : '') + ' data-date="' + it.date +
            '" title="' + esc(it.time + ' · ' + cap + (isAuto ? ' (automation)' : '')) + '">' +
            '<span class="cs-cchip__cover">' + cover + '</span>' +
            '<span class="cs-cchip__body"><span class="cs-cchip__time">' + esc(it.time) + '</span><span class="cs-cchip__cap">' + cap + '</span></span>' +
            (it.media_missing ? '<i class="fa-solid fa-triangle-exclamation cs-cchip__warn" title="Media removed"></i>' : '') +
            '</div>';
    }

    function renderMonth(map) {
        var year = calAnchor.getFullYear(), month = calAnchor.getMonth();
        $('#csCalTitle').text(new Date(year, month, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' }));
        var startDow = new Date(year, month, 1).getDay();
        var gridStart = new Date(year, month, 1 - startDow);
        var todayStr = ymd(startOfToday());
        var html = '<div class="cs-cal__dow">' + DOW.map(function (d) { return '<div>' + d + '</div>'; }).join('') + '</div><div class="cs-cal__grid">';
        for (var i = 0; i < 42; i++) {
            var d = new Date(gridStart); d.setDate(gridStart.getDate() + i);
            var ds = ymd(d), items = (map[ds] || []);
            html += '<div class="cs-cal__cell' + (d.getMonth() === month ? '' : ' is-out') + (ds === todayStr ? ' is-today' : '') + '" data-date="' + ds + '">' +
                '<div class="cs-cal__daynum">' + d.getDate() + '</div>' +
                '<div class="cs-cal__items">' + items.map(calChip).join('') + '</div></div>';
        }
        $('#csCal').html(html + '</div>');
    }

    function renderWeek(map) {
        var start = new Date(calAnchor); start.setDate(calAnchor.getDate() - calAnchor.getDay());
        var end = new Date(start); end.setDate(start.getDate() + 6);
        $('#csCalTitle').text(start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' – ' + end.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }));
        var todayStr = ymd(startOfToday());
        var head = '<div class="cs-cal__dow cs-cal__dow--week">';
        for (var j = 0; j < 7; j++) { var dd = new Date(start); dd.setDate(start.getDate() + j); head += '<div>' + DOW[dd.getDay()] + ' ' + dd.getDate() + '</div>'; }
        var html = head + '</div><div class="cs-cal__grid cs-cal__grid--week">';
        for (var k = 0; k < 7; k++) {
            var d = new Date(start); d.setDate(start.getDate() + k);
            var ds = ymd(d), items = (map[ds] || []);
            html += '<div class="cs-cal__cell cs-cal__cell--week' + (ds === todayStr ? ' is-today' : '') + '" data-date="' + ds + '"><div class="cs-cal__items">' + items.map(calChip).join('') + '</div></div>';
        }
        $('#csCal').html(html + '</div>');
    }

    $('#csCal')
        .on('click', '.cs-cchip', function (e) {
            e.stopPropagation();
            if ($(this).hasClass('cs-cchip--automation')) { bootstrap.Tab.getOrCreateInstance(document.getElementById('csTabScheduler')).show(); return; }
            openComposer(+$(this).data('id'));
        })
        .on('click', '.cs-cal__cell', function () { openComposerScheduled($(this).data('date')); })
        .on('dragstart', '.cs-cchip', function (e) { calDragId = +$(this).data('id'); calDragFrom = String($(this).data('date')); e.originalEvent.dataTransfer.effectAllowed = 'move'; })
        .on('dragover', '.cs-cal__cell', function (e) { e.preventDefault(); $(this).addClass('is-drop'); })
        .on('dragleave', '.cs-cal__cell', function () { $(this).removeClass('is-drop'); })
        .on('drop', '.cs-cal__cell', function (e) {
            e.preventDefault(); var to = String($(this).data('date')); $(this).removeClass('is-drop');
            if (calDragId && to && to !== calDragFrom) rescheduleTo(calDragId, to);
            calDragId = null; calDragFrom = null;
        });

    function rescheduleTo(id, dateStr) {
        var it = calItems.filter(function (x) { return x.id === id; })[0];
        if (!it) return;
        var timePart = (it.iso.split('T')[1] || '12:00');
        var human = new Date(dateStr + 'T' + timePart).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
        confirmDialog('Reschedule to ' + human + '?', 'This post will publish on ' + human + ' at ' + it.time + ' (your timezone).', 'Reschedule', false, function () {
            ApiDataSvc.apiCall('post', 'post_schedule', { id: id, scheduled_at: dateStr + 'T' + timePart }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success('Rescheduled'); loadCalendar(); } else err(o); });
        });
    }

    function openComposerScheduled(dateStr) {
        newComposer();
        composerModal.show();
        $('#csCompSchedAt').val(dateStr + 'T12:00');
        $('#csCompSchedule').prop('hidden', false);
        $('#csSchedule').text('Schedule for this time');
    }



    // =====================================================================
    // Scheduler (automations)
    // =====================================================================
    var schedModal, schedRules = [], schedRunning = {};
    var DOW_LABELS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    var schedForm = { audience: 'free', cadence: 'daily' };

    $('#csTabScheduler').on('shown.bs.tab', loadScheduler);
    $('#csSchedRetry').on('click', loadScheduler);

    function loadScheduler() {
        $('#csSchedLoading').prop('hidden', false);
        $('#csSchedError, #csSchedEmpty, #csSchedList').prop('hidden', true);
        ApiDataSvc.apiCall('post', 'scheduler_list', function (resp) { var o = JSON.parse(resp);
                $('#csSchedLoading').prop('hidden', true);
                if (!o || !o.success) { $('#csSchedError').prop('hidden', false); return; }
                schedRules = o.rules || [];
                renderSchedRules();
            });
    }

    function renderSchedRules() {
        var $list = $('#csSchedList');
        if (!schedRules.length) { $list.prop('hidden', true).empty(); $('#csSchedEmpty').prop('hidden', false); return; }
        $('#csSchedEmpty').prop('hidden', true);
        $list.prop('hidden', false).empty();
        schedRules.forEach(function (r) { $list.append(schedCard(r)); });
    }

    function schedTargetsSummary(r) {
        var t = r.message_targets || {}, parts = [];
        (t.fanvue || []).forEach(function (l) { parts.push('Fanvue ' + String(l).replace(/_/g, ' ')); });
        if (t.cls) { parts.push('CLS ' + (t.cls === 'all' ? 'everyone' : t.cls)); }
        return parts.join(', ') || 'No audience';
    }
    function schedCard(r) {
        var isMsg = r.kind === 'message';
        var aud = isMsg ? schedTargetsSummary(r) : (r.audience === 'subscribers' ? 'Subscribers only' : 'Everyone');
        var body = isMsg ? (r.message_ai ? ('AI: ' + r.topic) : r.message_text) : r.topic;
        var lastRun = r.last_status === 'success'
            ? '<span class="cs-sched__metaitem cs-sched__metaitem--ok"><i class="fa-solid fa-circle-check"></i>Last run OK</span>'
            : (r.last_status === 'failed'
                ? '<span class="cs-sched__metaitem cs-sched__metaitem--fail"><i class="fa-solid fa-circle-exclamation"></i>Last run failed</span>'
                : '');
        var stTitle = r.active ? 'Active — posting on schedule. Click to pause.' : 'Paused. Click to activate.';
        return $(
            '<div class="cs-sched__card' + (r.active ? '' : ' is-paused') + '" data-id="' + r.id + '">' +
            '<div class="cs-sched__body">' +
                '<div class="cs-sched__top">' +
                    '<span class="cs-sched__name">' + esc(r.name) + '</span>' +
                    '<button type="button" class="cs-sched__status' + (r.active ? ' is-active' : '') + '" data-sched-toggle title="' + stTitle + '">' +
                        '<span class="cs-sched__dot"></span>' + (r.active ? 'Active' : 'Paused') +
                    '</button>' +
                '</div>' +
                '<p class="cs-sched__topic">' + (isMsg ? '<i class="fa-solid fa-paper-plane cs-sched__kind" title="Scheduled message"></i> ' : '') + esc(body) + '</p>' +
                '<div class="cs-sched__meta">' +
                    '<span class="cs-sched__metaitem"><i class="fa-regular fa-clock"></i>' + esc(r.cadence_summary) + '</span>' +
                    '<span class="cs-sched__metaitem"><i class="fa-solid fa-' + (isMsg ? 'users' : (r.audience === 'subscribers' ? 'lock' : 'globe')) + '"></i>' + esc(aud) + '</span>' +
                    (r.next_run ? '<span class="cs-sched__metaitem"><i class="fa-solid fa-forward"></i>Next ' + esc(r.next_run) + '</span>' : '') +
                    lastRun +
                '</div>' +
            '</div>' +
            '<div class="cs-sched__actions">' +
                (schedRunning[r.id]
                    ? '<button type="button" class="cs-sched__btn cs-sched__btn--run" data-sched-run disabled><span class="spinner-border spinner-border-sm"></span> Generating…</button>'
                    : '<button type="button" class="cs-sched__btn cs-sched__btn--run" data-sched-run><i class="fa-solid fa-bolt"></i> ' + (isMsg ? 'Send now' : 'Run now') + '</button>') +
                '<button type="button" class="cs-sched__btn cs-sched__btn--icon" data-sched-edit aria-label="Edit" title="Edit"><i class="fa-solid fa-pen"></i></button>' +
                '<button type="button" class="cs-sched__btn cs-sched__btn--icon cs-sched__btn--danger" data-sched-del aria-label="Delete" title="Delete"><i class="fa-solid fa-trash-can"></i></button>' +
            '</div>' +
            '</div>'
        );
    }

    $('#csSchedNew, #csSchedEmptyNew').on('click', function () { openSchedForm(null, 'post'); });
    $('#csSchedNewMsg').on('click', function () { openSchedForm(null, 'message'); });
    $('#csSchedList').on('click', '[data-sched-edit]', function () {
        var id = $(this).closest('.cs-sched__card').data('id');
        openSchedForm(schedRules.filter(function (r) { return r.id == id; })[0] || null);
    });

    function setSchedKind(kind) {
        $('#csSchedKind').val(kind);
        $('#csSchedulerModal [data-kind="post"]').prop('hidden', kind !== 'post');
        $('#csSchedulerModal [data-kind="message"]').prop('hidden', kind !== 'message');
        $('#csSchedSave').text(kind === 'message' ? 'Save message' : 'Save automation');
        setSchedMsgAi();
    }
    function setSchedMsgAi() {
        var kind = $('#csSchedKind').val(), ai = $('#csSchedMsgAi').is(':checked');
        $('#csSchedMsgTextWrap').prop('hidden', kind === 'message' && ai);
        $('#csSchedTopicWrap').prop('hidden', kind === 'message' && !ai);
    }
    $('#csSchedMsgAi').on('change', setSchedMsgAi);
    function renderSchedTargets(sel) {
        var ib = CFG.inbox || {}, $wrap = $('#csSchedTargets').empty();
        sel = sel || {}; var fv = new Set((sel.fanvue || []).map(String));
        if (ib.fanvue_ok) {
            (ib.fanvue_lists || []).forEach(function (l) {
                $wrap.append('<label class="cs-social"><input class="form-check-input" type="checkbox" data-fvlist="' + esc(l) + '"' + (fv.has(l) ? ' checked' : '') + '><i class="fa-solid fa-bolt"></i><span class="cs-social__name">Fanvue: ' + esc(l.replace(/_/g, ' ')) + '</span></label>');
            });
        } else {
            $wrap.append('<p class="cs-comp__socialnote">Connect Fanvue with inbox access in <a href="/account/settings?section=inbox">Inbox Automation</a> to message Fanvue fans.</p>');
        }
        var segs = ib.cls_segments || ['all', 'followers', 'subscribers'];
        var opts = '<option value="">Not on Creator Link Studio</option>' + segs.map(function (g) { return '<option value="' + esc(g) + '"' + (sel.cls === g ? ' selected' : '') + '>Creator Link Studio: ' + esc(g === 'all' ? 'everyone I can message' : g) + '</option>'; }).join('');
        $wrap.append('<select class="form-select mt-2" id="csSchedClsSeg">' + opts + '</select>');
    }

    function openSchedForm(rule, kind) {
        if (!schedModal) schedModal = bootstrap.Modal.getOrCreateInstance('#csSchedulerModal');
        kind = rule ? (rule.kind || 'post') : (kind || 'post');
        renderSchedTargets(rule ? rule.message_targets : null);
        $('#csSchedMsgAi').prop('checked', rule ? !!rule.message_ai : false);
        $('#csSchedMsgText').val(rule ? (rule.message_text || '') : '');
        setSchedKind(kind);
        var b = CFG.brand || {};
        if (b.has_brand) { $('#csSchedBrandName').text(b.brand_name ? ('“' + b.brand_name + '”') : ''); $('#csSchedBrandRow').prop('hidden', false); }
        else { $('#csSchedBrandRow').prop('hidden', true); }
        $('#csSchedTz').text((CFG.creator && CFG.creator.timezone) || USER_TZ || 'UTC');
        renderSchedTiers();
        renderSchedSocial(rule ? rule.social_accounts : []);
        renderSchedDays(rule ? rule.days_of_week : []);

        $('#csSchedModalTitle').html('<i class="fa-solid fa-' + (kind === 'message' ? 'paper-plane' : 'robot') + '"></i> ' + (kind === 'message' ? (rule ? 'Edit scheduled message' : 'New scheduled message') : (rule ? 'Edit automation' : 'New automation')));
        $('#csSchedId').val(rule ? rule.id : 0);
        $('#csSchedName').val(rule ? rule.name : '');
        $('#csSchedTopic').val(rule ? rule.topic : '');
        $('#csSchedSize').val(rule ? rule.size : 'square');
        $('#csSchedBrand').prop('checked', rule ? !!rule.use_brand : true);
        $('#csSchedComments').prop('checked', rule ? rule.comments_enabled != 0 : true);
        $('#csSchedTime').val(rule ? rule.run_time : '09:00');
        schedForm.audience = rule ? rule.audience : 'free';
        schedForm.cadence  = rule ? rule.cadence : 'daily';
        setSchedAudience(schedForm.audience);
        setSchedCadence(schedForm.cadence);
        if (rule && rule.audience === 'subscribers') { $('#csSchedTierSel').val(rule.tier_id || ''); }
        schedModal.show();
    }

    function renderSchedTiers() {
        var $sel = $('#csSchedTierSel').empty().append('<option value="">All Subscribers</option>');
        (CFG.plans || []).forEach(function (p) { $sel.append('<option value="' + p.id + '">' + esc(p.name) + '</option>'); });
    }
    function renderSchedSocial(selected) {
        var soc = CFG.social || { accounts: [] };
        var $wrap = $('#csSchedSocial').empty();
        var sel = new Set((selected || []).map(String));
        if (!soc.accounts || !soc.accounts.length) { $wrap.append('<p class="cs-comp__socialnote">No connected social accounts.</p>'); return; }
        soc.accounts.forEach(function (a) {
            var on = sel.has(String(a.id));
            $wrap.append('<label class="cs-social"><input class="form-check-input" type="checkbox" data-sacct="' + esc(a.id) + '"' + (on ? ' checked' : '') + '><i class="' + socialIconClass(a.platform) + '"></i><span class="cs-social__name">' + esc(a.username || a.platform) + '</span></label>');
        });
    }
    function renderSchedDays(selected) {
        var sel = new Set((selected || []).map(Number));
        var $wrap = $('#csSchedDays').empty();
        DOW_LABELS.forEach(function (lbl, i) {
            $wrap.append('<button type="button" class="cs-sched__day' + (sel.has(i) ? ' is-on' : '') + '" data-day="' + i + '">' + lbl + '</button>');
        });
    }

    $('#csSchedAudience').on('click', '.cs-seg__opt', function () { setSchedAudience($(this).data('aud')); });
    function setSchedAudience(a) {
        schedForm.audience = (a === 'subscribers') ? 'subscribers' : 'free';
        $('#csSchedAudience .cs-seg__opt').each(function () { $(this).toggleClass('is-on', $(this).data('aud') === schedForm.audience); });
        $('#csSchedTier').prop('hidden', schedForm.audience !== 'subscribers');
    }
    $('#csSchedCadence').on('click', '.cs-seg__opt', function () { setSchedCadence($(this).data('cad')); });
    function setSchedCadence(c) {
        schedForm.cadence = (c === 'weekly') ? 'weekly' : 'daily';
        $('#csSchedCadence .cs-seg__opt').each(function () { $(this).toggleClass('is-on', $(this).data('cad') === schedForm.cadence); });
        $('#csSchedDays').prop('hidden', schedForm.cadence !== 'weekly');
    }
    $('#csSchedDays').on('click', '.cs-sched__day', function () { $(this).toggleClass('is-on'); });

    $('#csSchedSave').on('click', function () {
        var name = ($('#csSchedName').val() || '').trim();
        var topic = ($('#csSchedTopic').val() || '').trim();
        var kind = $('#csSchedKind').val() || 'post';
        var msgAi = $('#csSchedMsgAi').is(':checked'), msgText = ($('#csSchedMsgText').val() || '').trim();
        var targets = { fanvue: $('#csSchedTargets input[data-fvlist]:checked').map(function () { return String($(this).data('fvlist')); }).get(), cls: $('#csSchedClsSeg').val() || '' };
        if (name === '')  { toastr.info('Give it a name.'); $('#csSchedName').focus(); return; }
        if (kind === 'message') {
            if (!targets.fanvue.length && !targets.cls) { toastr.info('Pick who gets the message.'); return; }
            if (msgAi && topic === '') { toastr.info('Tell the AI what the message is about.'); $('#csSchedTopic').focus(); return; }
            if (!msgAi && msgText === '') { toastr.info('Write the message.'); $('#csSchedMsgText').focus(); return; }
        } else if (topic === '') { toastr.info('Describe what to post.'); $('#csSchedTopic').focus(); return; }
        var days = $('#csSchedDays .cs-sched__day.is-on').map(function () { return $(this).data('day'); }).get();
        if (schedForm.cadence === 'weekly' && !days.length) { toastr.info('Pick at least one day of the week.'); return; }
        var social = $('#csSchedSocial input[data-sacct]:checked').map(function () { return String($(this).data('sacct')); }).get();
        var $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving…');
        ApiDataSvc.apiCall('post', 'scheduler_save', {
            id: $('#csSchedId').val(), name: name, topic: topic,
            kind: kind, message_ai: msgAi ? '1' : '0', message_text: msgText, message_targets: JSON.stringify(targets),
            size: $('#csSchedSize').val(), audience: schedForm.audience,
            tier_id: schedForm.audience === 'subscribers' ? ($('#csSchedTierSel').val() || '') : '',
            comments_enabled: $('#csSchedComments').is(':checked') ? '1' : '0',
            use_brand: $('#csSchedBrand').is(':checked') ? '1' : '0',
            social_accounts: social, cadence: schedForm.cadence, days_of_week: days,
            run_time: $('#csSchedTime').val() || '09:00',
            timezone: (CFG.creator && CFG.creator.timezone) || USER_TZ || '', active: '1'
        }, function (resp) { var o = JSON.parse(resp);
            $btn.prop('disabled', false).text(kind === 'message' ? 'Save message' : 'Save automation');
            if (!o.success) { toastr.error(o.message); return; }
            schedModal.hide(); toastr.success('Automation saved'); loadScheduler();
        });
    });

    $('#csSchedList').on('click', '[data-sched-toggle]', function () {
        var $b = $(this), id = $b.closest('.cs-sched__card').data('id');
        var makeActive = !$b.hasClass('is-active');
        $b.prop('disabled', true);
        ApiDataSvc.apiCall('post', 'scheduler_toggle', { id: id, active: makeActive ? '1' : '0' }, function (resp) { var o = JSON.parse(resp); if (o.success) { loadScheduler(); } else { $b.prop('disabled', false); } });
    });

    $('#csSchedList').on('click', '[data-sched-del]', function () {
        var id = $(this).closest('.cs-sched__card').data('id');
        var r = schedRules.filter(function (x) { return x.id == id; })[0];
        confirmDialog('Delete automation?', 'Remove “' + esc((r && r.name) || 'this automation') + '”? Posts it already published stay.', 'Delete', true, function () {
            ApiDataSvc.apiCall('post', 'scheduler_delete', { id: id }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success('Removed'); loadScheduler(); } });
        });
    });

    $('#csSchedList').on('click', '[data-sched-run]', function () {
        var id = $(this).closest('.cs-sched__card').data('id');
        if (schedRunning[id]) { return; }              // already generating — ignore double-clicks
        schedRunning[id] = true;
        renderSchedRules();                            // show the generating state immediately
        // The request keeps running even if the creator switches tabs; schedRunning keeps
        // the card in its "Generating…" state across re-renders until it completes.
        ApiDataSvc.apiCall('post', 'scheduler_run_now', { id: id }, function (data) {
            delete schedRunning[id];
            var o = JSON.parse(data);
            if (o.success) {
                var m = (o && o.message) || 'Published a new post.';
                if (/failed/i.test(m)) { toastr.warning(m); } else { toastr.success(m); }
            }
            else { toastr.error((o && o.message) || 'Run failed.'); }
            loadScheduler();
        });
    });





    loadCollections();
    loadLibrary();
});
