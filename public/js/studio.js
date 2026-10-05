jQuery(function ($) {
    /* Everything inside the platform is credits: "49 credits". */
    function credits(n) { n = Math.round(Number(n) || 0); return n.toLocaleString('en-US') + (Math.abs(n) === 1 ? ' credit' : ' credits'); }
    "use strict";

    if (!$('#cs').length) return;

    var CFG = window.CS_CONFIG || { creator: {}, s3_ready: false };
    var S3_READY = !!CFG.s3_ready;
    var PART = 8388608;

    var state = {
        assets: [],
        collections: [],
        selection: new Set(),
        filters: { search: '', type: '', collection: '', usage: '', influencer: '' },
        loadSeq: 0
    };

    var detailOC = bootstrap.Offcanvas.getOrCreateInstance('#csDetail');

    function esc(s) { return $('<div>').text(s == null ? '' : s).html().replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

    /* ---- Privacy mode: one switch blurs every thumbnail/preview in the Studio (remembered per browser). ---- */
    (function () {
        var KEY = 'cs_privacy', $btn = $('#csPrivacy');
        function apply(on) {
            document.body.classList.toggle('cs-private', !!on);
            $btn.attr('aria-pressed', on ? 'true' : 'false').find('i').attr('class', on ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash');
            $btn.find('span').text(on ? 'Privacy On' : 'Privacy');
        }
        var saved = false; try { saved = localStorage.getItem(KEY) === '1'; } catch (e) {}
        apply(saved);
        $btn.on('click', function () {
            var on = $btn.attr('aria-pressed') !== 'true';
            apply(on);
            try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) {}
        });
    })();

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
                if (o.success) { toastr.success('Removed from ' + esc(openCol.name)); openCollectionView(openCol); } else err(o);
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

    function hasFilters() { var f = state.filters; return !!(f.search || f.type || f.collection || f.usage || f.influencer); }

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
        if (action === 'download') {
            // One file downloads directly; several come as one zip of the originals.
            if (ids.length === 1) {
                ApiDataSvc.apiCall('post', 'media_download', { id: ids[0] }, function (resp) { var o = JSON.parse(resp); if (o.success && o.url) { window.location = o.url; } else err(o); });
            } else {
                if (ids.length > 100) { toastr.error('Download up to 100 files at a time.'); return; }
                toastr.info('Preparing ' + ids.length + ' files. Your download starts in a moment.');
                window.location = '/studio/download?ids=' + ids.join(',');
            }
            return;
        }
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
            $('#csGenBuy').prop('hidden', true);
            $('#csGenRun').prop('hidden', false).prop('disabled', false).html(lastGenAsset ? REGEN_LABEL : GEN_LABEL);
            $('#csGenEdit, #csGenUse').prop('hidden', true);
            genCredits();   // Regenerate with too few credits shows the out-of-credits screen
        }
    }
    /* Not enough AI credits for an image: show the out-of-credits screen instead of the form. */
    CFG.ai = CFG.ai || { balance: 0, image_price: 0 };
    function fmtNum(n) { return (parseInt(n, 10) || 0).toLocaleString('en-US'); }
    /* "50 credits · 3,750 left" beside Generate; updates after every image. */
    /* "50 AI credits · 3,750 left", plus a Buy AI Credits link once two more runs would empty the balance. */
    function aiCostHtml(price) {
        var bal = parseInt(CFG.ai.balance, 10) || 0;
        var low = bal >= price && bal < price * 2;
        return esc(fmtNum(price) + ' AI credits · ' + fmtNum(bal) + ' left') + (low ? ' · <a href="/account/billing?tab=credits">Running low. Buy AI Credits</a>' : '');
    }
    function genCost() {
        $('#csGenCost').html(aiCostHtml(parseInt(CFG.ai.image_price, 10) || 0));
    }
    /* "Who's in it": no influencer, or one of the creator's trained influencers (CFG.influencers.ready). */
    function whoOptions($sel, noneLabel) {
        var cur = $sel.val() || '';
        $sel.empty().append($('<option value="">').text(noneLabel));
        ((CFG.influencers || {}).ready || []).forEach(function (i) { $sel.append($('<option>').val(i.id).text(i.name)); });
        $sel.val(cur);
        if ($sel.val() === null) { $sel.val(''); }
    }
    /* Follow an influencer job until it lands, then hand back the library asset (media_get shape). */
    function followInfluencerJob(jobId, done, fail, tries) {
        tries = (tries || 0) + 1;
        ApiDataSvc.apiCall('post', 'influencer_job_get', { job_id: jobId }, function (d) {
            var r = null; try { r = JSON.parse(d); } catch (e) {}
            var j = r && r.success ? r.job : null;
            if (!j) { fail('Could not check on it. It will appear in your Library when ready.'); return; }
            if (j.status === 'failed' || j.status === 'cancelled') { fail(j.error || 'It did not work this time. Your credits were returned.'); return; }
            var a = (j.assets || []).filter(function (x) { return x.status === 'ready'; })[0];
            if (j.status !== 'done' || !a) {
                if (tries >= 180) { fail('Still working in the background. It will appear in your Library when ready.'); return; }
                setTimeout(function () { followInfluencerJob(jobId, done, fail, tries); }, 4000); return;
            }
            ApiDataSvc.apiCall('post', 'media_get', { id: a.id }, function (d2) {
                var m = null; try { m = JSON.parse(d2); } catch (e) {}
                if (m && m.success && m.asset) { done(m.asset); } else { fail('It is ready in your Library.'); }
            });
        });
    }
    function genCredits() {
        genCost();
        var out = (parseInt(CFG.ai.balance, 10) || 0) < (parseInt(CFG.ai.image_price, 10) || 0);
        $('#csGenPrice').text(fmtNum(CFG.ai.image_price));
        $('#csGenEmpty').prop('hidden', !out);
        $('#csGenBuy').prop('hidden', !out);
        if (out) { $('#csGenInputs, #csGenPreview, #csGenResult, #csGenStatus, #csGenRun, #csGenEdit, #csGenUse').prop('hidden', true); }
        return out;
    }
    $('#csGenerate').on('show.bs.modal', function () {
        whoOptions($('#csGenWho'), 'Brand image (no influencer)');
        $('#csGenWho').trigger('change');
        if (!genCredits()) { $('#csGenEmpty, #csGenBuy').prop('hidden', true); }
    });
    $('#csGenWho').on('change', function () {   // an influencer's look comes from their trained model, not the brand
        var b = CFG.brand || {};
        $('#csGenBrandRow').prop('hidden', !!$(this).val() || !b.has_brand);
        $('#csGenPrompt').attr('placeholder', $(this).val() ? 'At a rooftop bar at sunset, smiling' : 'Sunset over Lake Eola, golden hour');
    });

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

    function showGenerated(asset, brandUsed) {
        lastGenAsset = asset;
        injectAsset(asset);
        var url = (asset && asset.thumb_url) || '';
        if (url) { $('#csGenPreviewImg').attr('src', url); }
        $('#csGenSavedMsg').text('Saved to your Library' + (brandUsed ? ' · matched to your brand' : '') + '.');
        setGenState('result');
        toastr.success('Image added to your Library.');
    }
    function runGeneration() {
        var prompt = ($('#csGenPrompt').val() || '').trim();
        if (prompt === '') { toastr.info('Describe the image you want.'); $('#csGenPrompt').focus(); return; }
        var who = $('#csGenWho').val() || '';
        if (who) {   // the influencer's trained model, same as Influencers → Generate Images
            $('#csGenPrompt, #csGenSize, #csGenWho').prop('disabled', true);
            setGenState('busy');
            $('#csGenStatus').prop('hidden', false).removeClass('is-error')
                .html('<span class="spinner-border spinner-border-sm text-primary"></span> Creating your image. This can take up to a minute.');
            var unlock = function () { $('#csGenPrompt, #csGenSize, #csGenWho').prop('disabled', false); };
            ApiDataSvc.apiCall('post', 'influencer_generate_image', { id: who, prompt: prompt, image_size: $('#csGenSize').val(), num_images: 1 }, function (data) {
                var o = null; try { o = JSON.parse(data); } catch (e) {}
                if (o && o.need_credits) { unlock(); CFG.ai.balance = o.balance; genCredits(); return; }
                if (!o || !o.success || !o.job_id) { unlock(); genError((o && o.message) || 'Generation failed. Try again.'); return; }
                CFG.ai.balance = Math.max(0, (parseInt(CFG.ai.balance, 10) || 0) - (parseInt(CFG.ai.image_price, 10) || 0));
                genCost();
                followInfluencerJob(o.job_id, function (asset) { unlock(); injectAsset(asset); showGenerated(asset, false); },
                    function (msg) { unlock(); genError(msg); });
            });
            return;
        }
        var b = CFG.brand || {};
        var useBrand = (b.has_brand && $('#csGenBrand').is(':checked')) ? '1' : '0';
        $('#csGenPrompt, #csGenSize, #csGenBrand').prop('disabled', true);
        setGenState('busy');
        $('#csGenStatus').prop('hidden', false).removeClass('is-error')
            .html('<span class="spinner-border spinner-border-sm text-primary"></span> Creating your image — this can take up to a minute.');

        ApiDataSvc.apiCall('post', 'media_generate', { prompt: prompt, size: $('#csGenSize').val(), use_brand: useBrand }, function (data) {
            $('#csGenPrompt, #csGenSize, #csGenBrand').prop('disabled', false);
            var o = JSON.parse(data);
            if (o && o.need_credits) { CFG.ai.balance = o.balance; genCredits(); return; }   // balance changed elsewhere
            if (!o || !o.success) { genError((o && o.message) || 'Generation failed. Try again.'); return; }
            CFG.ai.balance = Math.max(0, (parseInt(CFG.ai.balance, 10) || 0) - (parseInt(CFG.ai.image_price, 10) || 0));   // charged on request
            genCost();
            if (o.queued && o.asset && o.asset.status !== 'ready') {
                // Generation now runs in the background; poll the asset until it is ready or failed.
                var assetId = o.asset.id, brandUsed = o.brand_used, tries = 0;
                $('#csGenPrompt, #csGenSize, #csGenBrand').prop('disabled', true);
                (function pollGen() {
                    tries++;
                    ApiDataSvc.apiCall('post', 'media_get', { id: assetId }, function (d2) {
                        var r = null; try { r = JSON.parse(d2); } catch (e) {}
                        if (!r || !r.success || !r.asset) { $('#csGenPrompt, #csGenSize, #csGenBrand').prop('disabled', false); genError((r && r.message) || 'Generation failed. Try again.'); return; }
                        if (r.asset.status === 'failed') { $('#csGenPrompt, #csGenSize, #csGenBrand').prop('disabled', false); genError(r.asset.failure_reason || 'Generation failed. Try again.'); return; }
                        if (r.asset.status !== 'ready') {
                            if (tries >= 90) { $('#csGenPrompt, #csGenSize, #csGenBrand').prop('disabled', false); genError('Still generating in the background. Check your Library in a minute.'); return; }
                            setTimeout(pollGen, 2000); return;
                        }
                        $('#csGenPrompt, #csGenSize, #csGenBrand').prop('disabled', false);
                        showGenerated(r.asset, brandUsed);
                    });
                })();
                return;
            }
            showGenerated(o.asset, o.brand_used);
        });
    }
    $('#csGenRun').on('click', runGeneration);

    /* =====================================================================
     * Generate a video: animate one of the creator's library images (image-to-video).
     * Shows the live cost and balance; out of credits shows the Buy Credits screen first.
     * =================================================================== */
    var vidModal = null, vidPick = 0, vidAsset = null;
    function vidModelSel() {
        var key = $('#csVidModel').val();
        return (CFG.ai.video_models || []).filter(function (m) { return m.key === key; })[0] || (CFG.ai.video_models || [])[0] || { credits: 0, durations: [] };
    }
    /* AI credits for a video model at a length (its price follows the length; server table in credits_by_duration). */
    function videoPrice(m, dur) {
        var t = (m && m.credits_by_duration) || null;
        return (t && t[String(dur)] !== undefined) ? (parseInt(t[String(dur)], 10) || 0) : (parseInt(m && m.credits, 10) || 0);
    }
    function vidPrice() { return videoPrice(vidModelSel(), $('#csVidLength').val()); }
    function vidCost() {
        var price = vidPrice(), out = (parseInt(CFG.ai.balance, 10) || 0) < price;
        $('#csVidCost').html(aiCostHtml(price));
        $('#csVidPriceEmpty').text(fmtNum(price));
        $('#csVidEmpty').prop('hidden', !out);
        $('#csVidInputs').prop('hidden', out || !!vidAsset);
        $('#csVidBuy').prop('hidden', !out || !!vidAsset);
        $('#csVidRun').prop('hidden', out || !!vidAsset).prop('disabled', !vidPick);
        return !out;
    }
    function vidModels() {
        var $m = $('#csVidModel').empty();
        (CFG.ai.video_models || []).forEach(function (m) { $m.append($('<option>').val(m.key).text(m.label)); });
        vidLengths();
    }
    function vidLengths() {
        var $l = $('#csVidLength').empty();
        var m = vidModelSel();
        (m.durations || []).forEach(function (d) { $l.append($('<option>').val(d).text(d + ' seconds · ' + fmtNum(videoPrice(m, d)) + ' AI credits')); });
    }
    function vidImages() {
        var $g = $('#csVidImages').html('<div class="cs-loading"><span class="spinner-border spinner-border-sm text-primary"></span></div>');
        var who = $('#csVidWho').val() || '';
        // An influencer's video starts from one of their images; otherwise any Library image.
        ApiDataSvc.apiCall('post', who ? 'influencer_images' : 'media_list', who ? { id: who } : {}, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) {}
            var list = o && o.success ? (who ? o.images : o.assets) : [];
            var imgs = (list || []).filter(function (a) { return a.type === 'image' && a.status === 'ready'; }).slice(0, 48);
            $g.empty();
            $('#csVidNone').prop('hidden', imgs.length > 0).text(who ? 'This influencer has no images yet. Generate one first, then turn it into a video.' : 'Add or generate an image first, then turn it into a video.');
            imgs.forEach(function (a) {
                $g.append($('<button type="button" class="cs-vid__img" role="radio" aria-checked="false">').attr('data-id', a.id)
                    .append($('<img>').attr('src', a.thumb_url || '').attr('alt', '')));
            });
            if (vidPick) { $g.find('[data-id="' + vidPick + '"]').addClass('is-on').attr('aria-checked', 'true'); }
            vidCost();
        });
    }
    function openVideo(preselect) {
        if (!vidModal) { vidModal = bootstrap.Modal.getOrCreateInstance('#csGenVideo'); }
        vidAsset = null; vidPick = parseInt(preselect, 10) || 0;
        $('#csVidPreview, #csVidUse, #csVidStatus').prop('hidden', true);
        $('#csVidPrompt').val('');
        whoOptions($('#csVidWho'), 'Any image from my Library');
        vidModels();
        if (vidCost()) { vidImages(); }
        vidModal.show();
    }
    $('#csGenVideoBtn').on('click', function () { openVideo(0); });
    $('#csVidModel').on('change', function () { vidLengths(); vidCost(); });
    $('#csVidLength').on('change', vidCost);
    $('#csVidWho').on('change', function () { vidPick = 0; vidImages(); });
    $('#csVidImages').on('click', '.cs-vid__img', function () {
        $('#csVidImages .cs-vid__img').removeClass('is-on').attr('aria-checked', 'false');
        $(this).addClass('is-on').attr('aria-checked', 'true');
        vidPick = parseInt($(this).data('id'), 10) || 0;
        vidCost();
    });
    function vidStatus(html, err) { $('#csVidStatus').prop('hidden', false).toggleClass('is-error', !!err).html(html); }
    $('#csVidRun').on('click', function () {
        var prompt = ($('#csVidPrompt').val() || '').trim();
        if (!vidPick) { vidStatus('Pick an image to animate.', true); return; }
        if (!prompt) { vidStatus('Describe the motion you want.', true); $('#csVidPrompt').focus(); return; }
        $('#csVidRun').prop('disabled', true);
        $('#csVidInputs :input').prop('disabled', true);
        vidStatus('<span class="spinner-border spinner-border-sm text-primary"></span> Creating your video. This usually takes 1 to 3 minutes; you can close this and find it in your Library.');
        var vwho = $('#csVidWho').val() || '';
        if (vwho) {   // the influencer's video flow (their prompt defaults, filed to their gallery)
            ApiDataSvc.apiCall('post', 'influencer_generate_video', { id: vwho, asset_id: vidPick, prompt: prompt, model_key: $('#csVidModel').val(), duration: $('#csVidLength').val() }, function (data) {
                var o = null; try { o = JSON.parse(data); } catch (e) {}
                $('#csVidInputs :input').prop('disabled', false);
                if (o && o.need_credits) { CFG.ai.balance = o.balance; $('#csVidStatus').prop('hidden', true); vidCost(); return; }
                if (!o || !o.success || !o.job_id) { $('#csVidRun').prop('disabled', false); vidStatus(esc((o && o.message) || 'The video could not be started. Try again.'), true); return; }
                CFG.ai.balance = Math.max(0, (parseInt(CFG.ai.balance, 10) || 0) - vidPrice());
                genCost(); vidCost();
                followInfluencerJob(o.job_id, function (asset) {
                    vidAsset = asset; injectAsset(asset); $('#csVidStatus').prop('hidden', true);
                    $('#csVidPreviewVideo').attr('src', asset.video_url || '').attr('poster', asset.thumb_url || '');
                    $('#csVidPreview, #csVidUse').prop('hidden', false); vidCost();
                    toastr.success('Video added to your Library.');
                }, function (msg) { $('#csVidRun').prop('disabled', false); vidStatus(esc(msg), true); });
            });
            return;
        }
        ApiDataSvc.apiCall('post', 'media_generate_video', { source_asset_id: vidPick, prompt: prompt, model_key: $('#csVidModel').val(), duration: $('#csVidLength').val() }, function (data) {
            var o = null; try { o = JSON.parse(data); } catch (e) {}
            $('#csVidInputs :input').prop('disabled', false);
            if (o && o.need_credits) { CFG.ai.balance = o.balance; $('#csVidStatus').prop('hidden', true); vidCost(); return; }
            if (!o || !o.success) { $('#csVidRun').prop('disabled', false); vidStatus(esc((o && o.message) || 'The video could not be started. Try again.'), true); return; }
            CFG.ai.balance = Math.max(0, (parseInt(CFG.ai.balance, 10) || 0) - (parseInt(o.price, 10) || 0));   // charged on request
            if (typeof genCost === 'function') { genCost(); }
            injectAsset(o.asset);
            var id = o.asset.id, tries = 0;
            (function pollVid() {
                tries++;
                ApiDataSvc.apiCall('post', 'media_get', { id: id }, function (d2) {
                    var r = null; try { r = JSON.parse(d2); } catch (e) {}
                    if (!r || !r.success || !r.asset) { $('#csVidRun').prop('disabled', false); vidStatus('Could not check on the video. It will appear in your Library when ready.', true); return; }
                    if (r.asset.status === 'failed') { $('#csVidRun').prop('disabled', false); vidStatus(esc(r.asset.failure_reason || 'The video failed. Your credits were returned.'), true); return; }
                    if (r.asset.status !== 'ready') {
                        if (tries >= 120) { vidStatus('Still working in the background. It will appear in your Library when ready.'); return; }
                        setTimeout(pollVid, 5000); return;
                    }
                    vidAsset = r.asset;
                    injectAsset(r.asset);
                    $('#csVidStatus').prop('hidden', true);
                    if (r.asset.video_url) { $('#csVidPreviewVideo').attr('src', r.asset.video_url).attr('poster', r.asset.thumb_url || ''); }
                    else { $('#csVidPreviewVideo').attr('poster', r.asset.thumb_url || ''); }
                    $('#csVidPreview, #csVidUse').prop('hidden', false);
                    vidCost();
                    toastr.success('Video added to your Library.');
                });
            })();
        });
    });
    $('#csVidUse').on('click', function () {
        if (!vidAsset) return;
        var asset = vidAsset;
        if (vidModal) vidModal.hide();
        newComposer();
        composerModal.show();
        composerAddAsset(asset);
    });
    $('#csGenVideo').on('hidden.bs.modal', function () { var v = document.getElementById('csVidPreviewVideo'); if (v) { v.pause(); } });

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
    $('#csFilterInfluencer').on('change', function () { state.filters.influencer = this.value; loadLibrary(); });
    $('#csClearFilters').on('click', function () {
        state.filters = { search: '', type: '', collection: '', usage: '', influencer: '' };
        $('#csSearch').val(''); $('#csFilterType,#csFilterCollection,#csFilterUsage,#csFilterInfluencer').val('');
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
            '<div id="csDvAi"></div>' +   // Edit + version history, filled by studio-ai.js
            '<button type="button" class="btn btn-outline-secondary w-100 mt-3" id="csDvDownload"><i class="fa-solid fa-download"></i> Download</button>' +
            '<button type="button" class="btn btn-outline-danger w-100 mt-2" id="csDvDelete"><i class="fa-solid fa-trash"></i> Remove file</button>'
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
        $('#csDvDownload').on('click', function () {
            var $b = $(this).prop('disabled', true);
            ApiDataSvc.apiCall('post', 'media_download', { id: a.id }, function (resp) { var o = JSON.parse(resp);
                $b.prop('disabled', false);
                if (o.success && o.url) { window.location = o.url; } else err(o);
            });
        });
        $('#csDvDelete').on('click', function () {
            var n = (a.posts || []).length;
            var msg = n > 0
                ? 'This file is used in ' + n + ' post' + (n > 1 ? 's' : '') + '. Those posts will show the file as missing. This can be undone from support within 30 days.'
                : 'It will be taken out of your library. This can be undone from support within 30 days.';
            confirmDialog('Remove this file?', msg, 'Remove', true, function () {
                ApiDataSvc.apiCall('post', 'media_delete', { id: a.id }, function (resp) { var o = JSON.parse(resp); if (o.success) { (o.unpublished ? toastr.warning : toastr.success)(o.message || 'File removed', '', o.unpublished ? { timeOut: 9000 } : {}); detailOC.hide(); loadLibrary(); if (o.unpublished && typeof loadPosts === 'function') { loadPosts(); } } else err(o); });
            });
        });
        $(document).trigger('cs:detail', [a]);
    }

    // Bridge for studio-ai.js (Edit By Instruction, Scenes): it adds to the Library and opens a file through these
    // events instead of reaching into this closure.
    $(document).on('cs:asset-added', function (e, id) {
        ApiDataSvc.apiCall('post', 'media_get', { id: id }, function (resp) { var o = null; try { o = JSON.parse(resp); } catch (x) {} if (o && o.success && o.asset) { injectAsset(o.asset); } });
    });
    $(document).on('cs:open-asset', function (e, id) { openDetail(id); });
    $(document).on('cs:use-in-post', function (e, id) {
        ApiDataSvc.apiCall('post', 'media_get', { id: id }, function (resp) { var o = null; try { o = JSON.parse(resp); } catch (x) {} if (o && o.success && o.asset) { newComposer(); composerModal.show(); composerAddAsset(o.asset); } });
    });

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
    var composer = { id: null, caption: '', audience: 'free', tier_ids: [], ppv_price: 50, comments_enabled: 1, on_cls: 1, assets: [], coverDisplay: '', coverBlurred: '', view: 'sub', validation: { ok: true, reason: '' }, saveTimer: null };
    composer.share = new Set();
    function socialIcon(pl){ var m={x:'fa-x-twitter',twitter:'fa-x-twitter',facebook:'fa-facebook',youtube:'fa-youtube',tiktok:'fa-tiktok',pinterest:'fa-pinterest',linkedin:'fa-linkedin',instagram:'fa-instagram',bluesky:'fa-bluesky',threads:'fa-threads'}; return m[pl]||''; }
    function socialIconClass(pl){ if (pl === 'fanvue') return 'fa-solid fa-bolt'; var b = socialIcon(pl); return b ? 'fa-brands ' + b : 'fa-solid fa-share-nodes'; }
    function socialPlatformName(p){ var m = { x: 'X', twitter: 'X', instagram: 'Instagram', facebook: 'Facebook', tiktok: 'TikTok', bluesky: 'Bluesky', threads: 'Threads', youtube: 'YouTube', linkedin: 'LinkedIn', pinterest: 'Pinterest', fanvue: 'Fanvue' }; p = String(p || '').toLowerCase(); return m[p] || (p.charAt(0).toUpperCase() + p.slice(1)); }

    /* ---- distribution: filterable list of every connected account; checkboxes keep data-acct so composer.share is unchanged ---- */
    function renderSocial(){
        var $wrap = $('#csCompSocial').empty();
        var soc = CFG.social || { accounts: [], can_post: false };
        var accounts = soc.accounts || [];
        var $empty = $('#csPeDestEmpty').prop('hidden', true).empty();
        $('#csPeDestNone').prop('hidden', true);
        $('#csPeDestSearch').val('');
        // Creator Link Studio itself is a destination too: off = socials-only post (never on your profile or the Home feed).
        $wrap.append('<label class="cs-pe__dest' + (composer.on_cls ? ' is-on' : '') + '" for="csPeDestCls" data-search="creator link studio profile feed">' +
            '<input class="form-check-input" type="checkbox" id="csPeDestCls" data-cls="1"' + (composer.on_cls ? ' checked' : '') + '>' +
            '<i class="fa-solid fa-house cs-pe__desticon" aria-hidden="true"></i>' +
            '<span class="cs-pe__destname">Creator Link Studio</span>' +
            '<span class="cs-pe__destplat">Your profile and the Home feed</span></label>');
        if (!accounts.length) {
            $('#csPeDestTools').prop('hidden', true);
            $empty.prop('hidden', false).html('No connected accounts yet. Connect one in <a href="/account/settings?section=connected">Settings</a> and it will appear here.');
            peUpdateDestCount(); return;
        }
        if (!soc.can_post) {
            $('#csPeDestTools').prop('hidden', true);
            $empty.prop('hidden', false).html('Sharing to social is not part of your current plan. <a href="/account/billing">See plans</a>.');
            peUpdateDestCount(); return;
        }
        $('#csPeDestTools').prop('hidden', false);
        accounts.forEach(function (a) {
            var on = composer.share.has(String(a.id));
            var off = a.status && a.status !== 'connected';
            var name = a.username || a.platform, plat = socialPlatformName(a.platform);
            var id = 'csPeDest_' + String(a.id).replace(/[^A-Za-z0-9_-]/g, '_');
            $wrap.append('<label class="cs-pe__dest' + (on ? ' is-on' : '') + (off ? ' is-off' : '') + '" for="' + id + '" data-search="' + esc((name + ' ' + plat).toLowerCase()) + '">' +
                '<input class="form-check-input" type="checkbox" id="' + id + '" data-acct="' + esc(a.id) + '"' + (on ? ' checked' : '') + (off ? ' disabled' : '') + '>' +
                '<i class="' + socialIconClass(a.platform) + ' cs-pe__desticon" aria-hidden="true"></i>' +
                '<span class="cs-pe__destname" title="' + esc(name) + '">' + esc(name) + '</span>' +
                (off ? '<span class="cs-pe__deststate">Disconnected</span>' : '') +
                '<span class="cs-pe__destplat">' + esc(plat) + '</span></label>');
        });
        peUpdateDestCount();
    }
    function peUpdateDestCount() {
        var n = composer.share.size, total = $('#csCompSocial input[data-acct]').length;
        $('#csPeDestCount').text(total ? (n + ' of ' + total + ' selected') : '');
        var social = n === 0 ? '' : n + ' social account' + (n === 1 ? '' : 's');
        peSum('distribution', composer.on_cls ? ('Creator Link Studio' + (social ? ' + ' + social : '')) : (social || 'Nowhere yet'));
        $('#csPeErr_share').prop('hidden', !!(composer.on_cls || n)).text(composer.on_cls || n ? '' : 'Pick at least one place to publish.');
    }
    $('#csCompSocial').on('change', '#csPeDestCls', function () {
        composer.on_cls = this.checked ? 1 : 0;
        $(this).closest('.cs-pe__dest').toggleClass('is-on', this.checked);
        peUpdateDestCount(); peMarkDirty(); scheduleSave();
    });
    $('#csCompSocial').on('change', 'input[data-acct]', function () {
        var id = String($(this).data('acct')); if (this.checked) composer.share.add(id); else composer.share.delete(id);
        $(this).closest('.cs-pe__dest').toggleClass('is-on', this.checked);
        peUpdateDestCount(); peMarkDirty();
    });
    $('#csPeDestSearch').on('input', function () {
        var q = String(this.value || '').trim().toLowerCase(), shown = 0;
        $('#csCompSocial .cs-pe__dest').each(function () { var hit = q === '' || String($(this).data('search')).indexOf(q) >= 0; $(this).prop('hidden', !hit); if (hit) shown++; });
        $('#csPeDestNone').prop('hidden', shown > 0 || !$('#csCompSocial .cs-pe__dest').length);
    });

    var composerModal = bootstrap.Modal.getOrCreateInstance('#csComposer');
    var pickerModal = bootstrap.Modal.getOrCreateInstance('#csPicker');
    var pickerAssets = [], pickerSel = new Set();
    var TZ = (CFG.creator && CFG.creator.timezone) ? CFG.creator.timezone : 'UTC';
    $('#csCompTz').text(TZ);
    // subscription tiers for audience targeting
    /* ---- tier picker: checkboxes, any number of tiers; a select-all row on top. Empty selection is invalid for subscribers-only. ---- */
    var TIER_NAMES = {};
    function allTierIds() { return (CFG.plans || []).map(function (p) { return String(p.id); }); }
    (function () {
        var $g = $('#csCompTiers');
        var plans = CFG.plans || [];
        if (!plans.length) { $g.append('<p class="cs-pe__empty">No membership tiers yet. Add one in <a href="/account/settings?section=plans">Settings</a>.</p>'); return; }
        $g.append('<label class="cs-pe__dest cs-pe__dest--all" for="csPeTierAll"><input class="form-check-input" type="checkbox" id="csPeTierAll" data-tier-all="1"><span class="cs-pe__destname">All tiers</span><span class="cs-pe__destplat" id="csPeTierCount"></span></label>');
        plans.forEach(function (p) {
            TIER_NAMES[String(p.id)] = p.name;
            var id = 'csPeTier_' + p.id, price = p.price_cents > 0 ? ('$' + (p.price_cents / 100).toFixed(2) + '/mo') : 'Free';
            $g.append('<label class="cs-pe__dest" for="' + id + '"><input class="form-check-input" type="checkbox" id="' + id + '" data-tier="' + esc(String(p.id)) + '">' +
                '<span class="cs-pe__destname">' + esc(p.name) + '</span><span class="cs-pe__destplat">' + esc(price) + '</span></label>');
        });
    })();
    function peRenderTiers() {
        var sel = composer.tier_ids || [], total = allTierIds().length;
        $('#csCompTiers input[data-tier]').each(function () {
            var on = sel.indexOf(String($(this).data('tier'))) >= 0;
            $(this).prop('checked', on).closest('.cs-pe__dest').toggleClass('is-on', on);
        });
        var all = total > 0 && sel.length === total;
        $('#csPeTierAll').prop('checked', all).prop('indeterminate', !all && sel.length > 0).closest('.cs-pe__dest').toggleClass('is-on', all);
        $('#csPeTierCount').text(total ? (sel.length + ' of ' + total + ' selected') : '');
        if (sel.length) peClearError('tier');
    }
    function tierSummary() {
        var sel = composer.tier_ids || [], total = allTierIds().length;
        if (!total) return 'All';
        if (sel.length === total) return 'All tiers';
        if (sel.length === 0) return 'No tiers';
        if (sel.length === 1) return TIER_NAMES[sel[0]] || '1 tier';
        return sel.length + ' tiers';
    }
    $('#csCompTiers').on('change', 'input[data-tier]', function () {
        var id = String($(this).data('tier')), i = composer.tier_ids.indexOf(id);
        if (this.checked && i < 0) composer.tier_ids.push(id); else if (!this.checked && i >= 0) composer.tier_ids.splice(i, 1);
        composer.lastTiers = composer.tier_ids.slice();
        peRenderTiers(); peUpdateSummaries(); peMarkDirty(); scheduleSave();
    });
    $('#csCompTiers').on('change', '#csPeTierAll', function () {
        composer.tier_ids = this.checked ? allTierIds() : [];
        composer.lastTiers = composer.tier_ids.slice();
        peRenderTiers(); peUpdateSummaries(); peMarkDirty(); scheduleSave();
    });

    $('#csNewPostBtn').on('click', function () { openComposer(null); });

    /* ---- sections + nav ---- */
    var PE_SECTIONS = ['content', 'audience', 'distribution', 'publish'];
    var peOpener = null, peSaving = false;
    function peShowSection(key, focusHeading) {
        if (PE_SECTIONS.indexOf(key) < 0) key = 'content';
        composer.section = key;
        $('#csPeMain .cs-pe__section').each(function () { $(this).prop('hidden', $(this).data('section') !== key); });
        $('#csPeNav .cs-pe__navitem').each(function () { if ($(this).data('section') === key) $(this).attr('aria-current', 'true'); else $(this).removeAttr('aria-current'); });
        $('#csPeNavSelect').val(key);
        $('#csPeMain').scrollTop(0);
        peUpdateFooter();
        if (focusHeading) { var h = document.getElementById('csPeH_' + key); if (h) h.focus({ preventScroll: true }); }
    }
    $('#csPeNav').on('click', '.cs-pe__navitem', function () { peShowSection($(this).data('section'), true); });
    $('#csPeNav').on('keydown', '.cs-pe__navitem', function (e) {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        e.preventDefault();
        var items = $('#csPeNav .cs-pe__navitem'), i = items.index(this);
        items.eq((i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length).trigger('focus');
    });
    $('#csPeNavSelect').on('change', function () { peShowSection(this.value, true); });
    $('#csPePreviewBtn').on('click', function () { var open = !$('#csComposer .cs-pe').hasClass('is-preview-open'); $('#csComposer .cs-pe').toggleClass('is-preview-open', open); $(this).attr('aria-pressed', open ? 'true' : 'false'); });

    function peSum(key, text) { $('#csPeNav [data-sum="' + key + '"]').text(text); }
    function peUpdateSummaries() {
        var n = composer.assets.length;
        peSum('content', (n ? (n + ' media') : 'No media') + ' · ' + (composer.caption.trim() ? 'Caption added' : 'No caption'));
        var aud = 'Everyone';
        if (composer.audience === 'subscribers') { aud = 'Subscribers · ' + tierSummary(); }
        else if (composer.audience === 'ppv') { aud = 'Pay-per-view · ' + credits(composer.ppv_price); }
        peSum('audience', aud);
        peUpdateDestCount();
        if (composer.state === 'published') { peSum('publish', 'Published'); }
        else if (composer.mode === 'schedule') { var d = peScheduleDate(); peSum('publish', d ? ('Scheduled · ' + peFmtDate(d)) : 'Scheduled · pick a time'); }
        else if (composer.mode === 'draft') { peSum('publish', 'Save as draft'); }
        else { peSum('publish', 'Publish now'); }
    }
    function peFmtDate(d) { var mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; var h = d.getHours(), m = d.getMinutes(); return mo[d.getMonth()] + ' ' + d.getDate() + ', ' + ((h % 12) || 12) + ':' + (m < 10 ? '0' : '') + m + ' ' + (h < 12 ? 'AM' : 'PM'); }
    function peUpdateFooter() {
        var s = composer.section || 'content', pub = composer.state === 'published';
        var $sec = $('#csPeSecondary'), $pri = $('#csPePrimary');
        if (s === 'content') { $sec.prop('hidden', pub).text('Save Draft'); } else { $sec.prop('hidden', false).text('Back'); }
        if (s !== 'publish') { $pri.text('Continue'); }
        else if (pub) { $pri.text('Save Changes'); }
        else { $pri.text(composer.mode === 'schedule' ? 'Schedule post' : (composer.mode === 'draft' ? 'Save Draft' : 'Publish now')); }
    }
    $('#csPeSecondary').on('click', function () {
        var i = PE_SECTIONS.indexOf(composer.section || 'content');
        if (i <= 0) { runAction('draft'); return; }
        peShowSection(PE_SECTIONS[i - 1], true);
    });
    $('#csPePrimary').on('click', function () {
        var s = composer.section || 'content', i = PE_SECTIONS.indexOf(s);
        if (s !== 'publish') { peShowSection(PE_SECTIONS[i + 1], true); return; }
        if (composer.state === 'published') { runAction('update'); return; }
        runAction(composer.mode === 'schedule' ? 'schedule' : (composer.mode === 'draft' ? 'draft' : 'publish'));
    });

    /* ---- validation: every section, inline message + nav flag ---- */
    var PE_ERR_SECTION = { media: 'content', caption: 'content', price: 'audience', tier: 'audience', share: 'distribution', schedule: 'publish' };
    function peSetError(key, msg, $field) {
        $('#csPeErr_' + key).text(msg).prop('hidden', false);
        if ($field) $field.addClass('is-invalid');
        $('#csPeNav [data-section="' + PE_ERR_SECTION[key] + '"] .cs-pe__navflag').prop('hidden', false);
    }
    function peClearError(key) {
        $('#csPeErr_' + key).prop('hidden', true).text('');
        var sec = PE_ERR_SECTION[key];
        $('#csPeMain [data-section="' + sec + '"] .is-invalid').removeClass('is-invalid');
        if (!$('#csPeMain [data-section="' + sec + '"] .cs-pe__error:not([hidden])').length) $('#csPeNav [data-section="' + sec + '"] .cs-pe__navflag').prop('hidden', true);
    }
    function peClearErrors() { Object.keys(PE_ERR_SECTION).forEach(peClearError); $('#csCompValidation').prop('hidden', true).empty(); }
    function peScheduleDate() {
        var d = $('#csPeDate').val(), t = $('#csPeTime').val();
        if (!d || !t) return null;
        var x = new Date(d + 'T' + t + ':00'); return isNaN(x.getTime()) ? null : x;
    }
    function peSyncSchedAt() { var d = $('#csPeDate').val(), t = $('#csPeTime').val(); $('#csCompSchedAt').val(d && t ? (d + 'T' + t) : ''); }
    function peValidate(kind) {
        peClearErrors();
        var errors = [];
        if (composer.caption.length > 3000) errors.push({ key: 'caption', msg: 'Captions can be up to 3,000 characters.', $f: $('#csCompCaption'), focus: '#csCompCaption' });
        if (kind !== 'draft' && !composer.caption.trim() && !composer.assets.length) errors.push({ key: 'media', msg: 'Add a photo, video, or caption before publishing.', focus: '#csCompCaption' });
        if (composer.audience === 'subscribers' && allTierIds().length && !composer.tier_ids.length) errors.push({ key: 'tier', msg: 'Pick at least one tier.', focus: '#csPeTierAll' });
        var priceBad = false;
        if (composer.audience === 'ppv') {
            // Same rule as the server (Price): whole credits, 10 to 5,000.
            var p = Number(composer.ppv_price);
            priceBad = !isFinite(p) || p < 10 || p > 5000 || Math.floor(p) !== p;
            if (priceBad) errors.push({ key: 'price', msg: 'Enter a price from 10 to 5,000 credits, in whole credits.', $f: $('#csCompPpvPrice'), focus: '#csCompPpvPrice' });
        }
        // The server's own verdict on the saved post (media processing, moderation, PPV rules), routed to its section.
        if (kind !== 'draft' && composer.validation && composer.validation.ok === false && (composer.caption.trim() || composer.assets.length)) {
            var reason = composer.validation.reason || 'This post can\u2019t be published yet.';
            if (/price/i.test(reason)) { if (!priceBad) errors.push({ key: 'price', msg: reason, $f: $('#csCompPpvPrice'), focus: '#csCompPpvPrice' }); }
            else errors.push({ key: 'media', msg: reason, focus: '#csCompMedia [data-pe-add], #csCompCaption' });
        }
        var offSel = $('#csCompSocial input[data-acct]:checked:disabled').length;
        if (offSel) errors.push({ key: 'share', msg: 'A selected account is disconnected. Unselect it or reconnect it in Settings.', focus: '#csPeDestSearch' });
        if (kind === 'schedule') {
            var d = peScheduleDate();
            if (!d) errors.push({ key: 'schedule', msg: 'Pick a date and time.', $f: $('#csPeDate').val() ? $('#csPeTime') : $('#csPeDate'), focus: $('#csPeDate').val() ? '#csPeTime' : '#csPeDate' });
            else if (d.getTime() <= Date.now() + 60000) errors.push({ key: 'schedule', msg: 'Pick a date and time in the future.', $f: $('#csPeDate'), focus: '#csPeDate' });
        }
        errors.forEach(function (e) { peSetError(e.key, e.msg, e.$f); });
        return errors;
    }
    function peShowServerError(msg) {
        var text = msg || 'Something went wrong.';
        var key = /media|photo|video|caption|processing|blocked|removed/i.test(text) ? 'media' : (/price|pay-per-view/i.test(text) ? 'price' : (/date|time|future|schedule/i.test(text) ? 'schedule' : ''));
        if (key) { peSetError(key, text); peShowSection(PE_ERR_SECTION[key], false); setTimeout(function () { $('#csPeErr_' + key).closest('.cs-pe__field').find('.form-control, .form-select, .cs-pe__mbtn').first().trigger('focus'); }, 30); }
        else { peShowSection('publish', false); $('#csCompValidation').prop('hidden', false).html('<i class="fa-solid fa-circle-info" aria-hidden="true"></i> ' + esc(text)); }
    }

    /* ---- open / new / load ---- */
    function openComposer(postId) {
        peOpener = document.activeElement;
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
    $('#csComposer').on('hidden.bs.modal', function () {
        clearInterval(pvTimer);
        $('#csComposer .cs-pe').removeClass('is-preview-open'); $('#csPePreviewBtn').attr('aria-pressed', 'false');
        if (peOpener && peOpener.focus) { try { peOpener.focus(); } catch (e) {} } peOpener = null;
    });
    $('#csComposer').on('shown.bs.modal', function () { $('#csCompCaption').trigger('focus'); });

    function resetScheduleUI() { $('#csPeDate, #csPeTime, #csCompSchedAt').val(''); }
    function newComposer() {
        clearTimeout(composer.saveTimer);
        composer = { id: null, caption: '', audience: 'free', tier_ids: allTierIds(), lastTiers: allTierIds(), ppv_price: 50, lastPpv: 50, comments_enabled: 1, on_cls: 1, assets: [], coverDisplay: '', coverBlurred: '', view: 'sub', validation: { ok: false, reason: '' }, saveTimer: null, mode: 'now', dirty: false, section: 'content', moderation: 'ok' };
        composer.share = new Set();
        composer.state = 'draft';
        resetScheduleUI();
        setSaveStatus('');
        peSnapshot();
        renderComposer();
        peShowSection('content', false);
    }
    function setComposer(p) {
        clearTimeout(composer.saveTimer);
        composer.id = p.id; composer.caption = p.caption || ''; composer.audience = p.audience || 'free';
        composer.tier_ids = (p.tier_ids && p.tier_ids.length) ? p.tier_ids.map(String) : (p.tier_id ? [String(p.tier_id)] : allTierIds()); composer.lastTiers = composer.tier_ids.slice();
        composer.moderation = p.moderation || 'ok';
        composer.ppv_price = p.ppv_price_credits || 50; composer.lastPpv = composer.ppv_price;
        composer.comments_enabled = (p.comments_enabled != null) ? p.comments_enabled : 1;
        composer.on_cls = (p.on_cls != null) ? (p.on_cls ? 1 : 0) : 1;
        composer.share = new Set((p.shared_accounts || []).map(String));
        composer.state = p.state || 'draft';
        composer.assets = p.assets || []; composer.coverDisplay = p.cover_display_url || ''; composer.coverBlurred = p.cover_blurred_url || '';
        composer.validation = p.validation || { ok: true, reason: '' };
        composer.mode = (composer.state === 'scheduled') ? 'schedule' : 'now';
        composer.dirty = false;
        resetScheduleUI();
        if (p.scheduled_local) { var parts = String(p.scheduled_local).replace(' ', 'T').split('T'); $('#csPeDate').val(parts[0] || ''); $('#csPeTime').val((parts[1] || '').slice(0, 5)); peSyncSchedAt(); }
        setSaveStatus('Saved');
        peSnapshot();
        renderComposer();
        peShowSection('content', false);
    }
    function loadPost(id) {
        $('#csPreviewCard').html('<div class="cs-loading"><span class="spinner-border spinner-border-sm text-primary"></span> Loading…</div>');
        ApiDataSvc.apiCall('post', 'post_get', { id: id }, function (resp) { var o = JSON.parse(resp); if (o && o.success) setComposer(o.post); else { toastr.error((o && o.message) || 'Could not open that post.'); composerModal.hide(); } });
    }

    function coverId() { return composer.assets.length ? composer.assets[0].id : 0; }

    // What the post looked like when the editor opened (or after an explicit save): "Discard changes" reverts to this.
    function peStateFields() {
        return { caption: composer.caption, audience: composer.audience, tier_ids: (composer.audience === 'subscribers' ? composer.tier_ids.slice().sort() : []), ppv_price: (composer.audience === 'ppv' ? String(composer.ppv_price || '') : ''),
                 asset_ids: composer.assets.map(function (a) { return a.id; }), comments_enabled: (composer.comments_enabled ? '1' : '0'), on_cls: (composer.on_cls ? '1' : '0') };
    }
    function peSnapshot() { composer.snapshot = { id: composer.id, fields: peStateFields() }; }
    function peChangedSinceOpen() { return !composer.snapshot || JSON.stringify(peStateFields()) !== JSON.stringify(composer.snapshot.fields); }

    // Notice shown in the composer when the post's media is flagged adult (or still scanning).
    function renderModNote() {
        var $note = $('#csCompModNote');
        if (composer.moderation === 'blocked') {
            $note.attr('class', 'cs-comp__modnote cs-comp__modnote--blocked').prop('hidden', false)
                .html('<i class="fa-solid fa-ban"></i> This post contains media that was <strong>blocked</strong> by our content check and can\'t be published. Remove it to continue.');
        } else if (composer.moderation === 'flagged' || composer.moderation === 'adult') {
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
        var pub = composer.state === 'published', sch = composer.state === 'scheduled';
        $('#csPeEyebrow').text(pub || sch || composer.id ? 'Edit post' : 'New Post');
        $('#csCompTitle').text(pub ? 'Edit post' : 'Create Post');
        $('#csPeStatusText').text(pub ? 'Published' : (sch ? 'Scheduled' : 'Draft'));
        $('#csPeStatus').toggleClass('is-published', pub).toggleClass('is-scheduled', sch);
        $('#csCompCaption').val(composer.caption);
        peRenderCount();
        $('#csCompAudience .cs-pe__choice').each(function () { $(this).attr('aria-checked', $(this).data('aud') === composer.audience ? 'true' : 'false'); });
        $('#csCompTier').prop('hidden', composer.audience !== 'subscribers');
        peRenderTiers();
        $('#csCompPpv').prop('hidden', composer.audience !== 'ppv');
        $('#csCompPpvPrice').val(composer.ppv_price || 50);
        $('#csCompComments').prop('checked', composer.comments_enabled != 0);
        $('#csPeMode .cs-pe__choice').each(function () { $(this).attr('aria-checked', $(this).data('mode') === composer.mode ? 'true' : 'false'); });
        $('#csPeMode').prop('hidden', pub);
        $('#csPePublishedNote').prop('hidden', !pub);
        $('#csCompSchedule').prop('hidden', pub || composer.mode !== 'schedule');
        peClearErrors();
        renderModNote();
        renderSocial();
        renderCompMedia();
        renderPreview();
        peUpdateSummaries();
        peUpdateFooter();
    }
    function peRenderCount() { var n = composer.caption.length; $('#csCompCount').text(n); $('#csPeCount').toggleClass('is-over', n > 3000); }

    /* ---- media: empty drop zone, or a grid with reorder/remove ---- */
    function peMediaButtons(cls) {
        return '<div class="' + cls + '"><button type="button" class="cs-pe__mbtn" data-pe-add>Media library</button>' +
               '<button type="button" class="cs-pe__mbtn" data-pe-upload' + (S3_READY ? '' : ' disabled title="Media storage is not set up yet"') + '>Upload</button></div>';
    }
    function renderCompMedia() {
        var $m = $('#csCompMedia').empty();
        $('#csPeMediaHint').prop('hidden', composer.assets.length < 2);
        if (!composer.assets.length) {
            $m.append('<div class="cs-pe__drop" id="csPeDrop"><i class="fa-solid fa-arrow-up-from-bracket cs-pe__dropicon" aria-hidden="true"></i>' +
                '<span class="cs-pe__droptitle">Add photos or video</span><span class="cs-pe__dropsub">Choose from your library or upload files.</span>' + peMediaButtons('cs-pe__dropactions') + '</div>');
            return;
        }
        var $g = $('<div class="cs-pe__grid" id="csPeGrid">');
        composer.assets.forEach(function (a, i) {
            var $it = $('<div class="cs-pe__item" draggable="true">').attr('data-id', a.id).attr('data-i', i);
            if (a.missing) { $it.addClass('is-missing').append('<div class="cs-pe__itemph"><i class="fa-solid fa-triangle-exclamation"></i></div>'); }
            else if (a.thumb_url) { $it.append($('<img alt="">').attr('src', a.thumb_url)); }
            else { $it.append('<div class="cs-pe__itemph"><i class="fa-solid ' + typeIcon(a.type) + '"></i></div>'); }
            if (i === 0) $it.append('<span class="cs-pe__cover">Cover</span>');
            if (a.type === 'video') $it.append('<span class="cs-pe__vid" aria-hidden="true"><i class="fa-solid fa-play"></i></span>');
            $it.append('<button type="button" class="cs-pe__itembtn cs-pe__itembtn--left" data-pe-move="-1" aria-label="Move earlier"' + (i === 0 ? ' disabled' : '') + '><i class="fa-solid fa-chevron-left"></i></button>');
            $it.append('<button type="button" class="cs-pe__itembtn cs-pe__itembtn--right" data-pe-move="1" aria-label="Move later"' + (i === composer.assets.length - 1 ? ' disabled' : '') + '><i class="fa-solid fa-chevron-right"></i></button>');
            $it.append('<button type="button" class="cs-pe__itembtn cs-pe__itembtn--rm" data-pe-rm aria-label="Remove from post"><i class="fa-solid fa-xmark"></i></button>');
            $g.append($it);
        });
        $g.append(peMediaButtons('cs-pe__gridtools'));
        $m.append($g);
    }
    function peMoveAsset(from, to) {
        if (to < 0 || to >= composer.assets.length || from === to) return;
        var m = composer.assets.splice(from, 1)[0]; composer.assets.splice(to, 0, m);
        if (to === 0 || from === 0) { composer.coverDisplay = ''; composer.coverBlurred = ''; }
        renderCompMedia(); renderPreview(); peUpdateSummaries(); peMarkDirty(); scheduleSave();
    }
    var dragIdx = null;
    $('#csCompMedia')
        .on('click', '[data-pe-add]', openPicker)
        .on('click', '[data-pe-upload]', function () { uploadOnComplete = composerAddAsset; peUploadsStarted($('#csFileInput')); pickFiles(); })
        .on('click', '[data-pe-move]', function (e) { e.stopPropagation(); var i = $(this).closest('.cs-pe__item').data('i'); var d = parseInt($(this).data('pe-move'), 10); peMoveAsset(i, i + d); setTimeout(function () { $('#csCompMedia .cs-pe__item').eq(i + d).find('[data-pe-move="' + d + '"]').trigger('focus'); }, 0); })
        .on('click', '[data-pe-rm]', function (e) {
            e.stopPropagation(); var id = $(this).closest('.cs-pe__item').data('id');
            var wasCover = composer.assets.length && composer.assets[0].id == id;
            composer.assets = composer.assets.filter(function (a) { return a.id != id; });
            if (wasCover) { composer.coverDisplay = ''; composer.coverBlurred = ''; }
            peClearError('media');
            renderCompMedia(); renderPreview(); peUpdateSummaries(); peMarkDirty(); scheduleSave();
        })
        .on('dragstart', '.cs-pe__item', function (e) { dragIdx = $(this).data('i'); $(this).addClass('is-dragging'); e.originalEvent.dataTransfer.effectAllowed = 'move'; })
        .on('dragend', '.cs-pe__item', function () { $(this).removeClass('is-dragging'); })
        .on('dragover', '.cs-pe__item', function (e) { e.preventDefault(); })
        .on('drop', '.cs-pe__item', function (e) {
            var dt = e.originalEvent.dataTransfer;
            if (dragIdx === null && dt && dt.files && dt.files.length) return;   // a file drop: handled by the zone below
            e.preventDefault(); e.stopPropagation();
            var to = $(this).data('i');
            if (dragIdx === null || to === dragIdx) { dragIdx = null; return; }
            var from = dragIdx; dragIdx = null; peMoveAsset(from, to);
        })
        .on('dragenter dragover', '.cs-pe__drop, .cs-pe__grid', function (e) {
            var dt = e.originalEvent.dataTransfer; if (dragIdx !== null || !dt || !dt.types || Array.prototype.indexOf.call(dt.types, 'Files') < 0) return;
            e.preventDefault(); if (S3_READY) $(this).addClass('is-dragover');
        })
        .on('dragleave', '.cs-pe__drop, .cs-pe__grid', function (e) { if (e.target === this) $(this).removeClass('is-dragover'); })
        .on('drop', '.cs-pe__drop, .cs-pe__grid', function (e) {
            var dt = e.originalEvent.dataTransfer; $(this).removeClass('is-dragover');
            if (dragIdx !== null || !dt || !dt.files || !dt.files.length) return;
            e.preventDefault(); e.stopPropagation();
            if (!S3_READY) { toastr.info("Media storage isn't set up yet, so uploads are off."); return; }
            peUploadsStarted(dt.files.length);
            handleFiles(dt.files, composerAddAsset);
        });
    // Upload progress lives in the shared tray; the editor announces the count so screen readers hear it too.
    var peUploading = 0;
    function peUploadsStarted(n) {
        if (n && n.jquery) { n.off('change.pe').one('change.pe', function () { peUploadsStarted(this.files ? this.files.length : 0); }); return; }
        n = parseInt(n, 10) || 0; if (!n) return;
        peUploading += n; peUploadStatus();
        setTimeout(function () { if (peUploading > 0) { peUploading = 0; peUploadStatus(); } }, 600000);   // safety: clear a stuck counter after 10 minutes
    }
    function peUploadStatus() { $('#csPeUploadStatus').text(peUploading > 0 ? ('Uploading ' + peUploading + ' file' + (peUploading === 1 ? '' : 's') + '… progress is in the tray at the bottom right.') : ''); }

    function setSaveStatus(t) {
        var $s = $('#csCompSave').removeClass('is-unsaved is-failed');
        if (t === 'Not saved') $s.addClass('is-failed').text('Not saved. We’ll retry when you make a change.');
        else if (t === 'Unsaved changes') $s.addClass('is-unsaved').text('Unsaved changes');
        else $s.text(t);
    }
    function peMarkDirty() { composer.dirty = true; if (composer.caption.trim() || composer.assets.length || composer.id) setSaveStatus('Unsaved changes'); }
    function scheduleSave() { clearTimeout(composer.saveTimer); if (composer.caption.trim() || composer.assets.length || composer.id) setSaveStatus('Saving…'); composer.saveTimer = setTimeout(function () { saveNow(); }, 800); }
    function saveNow(cb) {
        clearTimeout(composer.saveTimer);
        var data = { id: composer.id || 0, caption: composer.caption, audience: composer.audience, tier_ids: (composer.audience === 'subscribers' ? composer.tier_ids.slice() : []), ppv_price: (composer.audience === 'ppv' ? (composer.ppv_price || '') : ''), asset_ids: composer.assets.map(function (a) { return a.id; }), cover_id: coverId(), comments_enabled: (composer.comments_enabled ? '1' : '0'), on_cls: (composer.on_cls ? '1' : '0') };
        var sentIds = data.asset_ids.join(',');
        ApiDataSvc.apiCall('post', 'post_save', data, function (resp) { var o = JSON.parse(resp);
                if (o && o.success) {
                    composer.id = o.id;
                    var oldIds = composer.assets.map(function (a) { return a.id; }).join(',');
                    // Media changed while this save was in flight (an upload landed, a reorder):
                    // keep the local list and save again rather than overwrite it with the stale server copy.
                    if (oldIds !== sentIds) { composer.validation = o.post.validation; if (cb) { saveNow(cb); } else { scheduleSave(); } return; }
                    var newIds = (o.post.assets || []).map(function (a) { return a.id; }).join(',');
                    var hadCover = !!composer.coverDisplay;
                    composer.coverDisplay = o.post.cover_display_url; composer.coverBlurred = o.post.cover_blurred_url;
                    composer.assets = o.post.assets; composer.validation = o.post.validation;
                    if (o.post.moderation) { composer.moderation = o.post.moderation; renderModNote(); }
                    composer.dirty = false;
                    setSaveStatus(composer.id ? 'Saved' : '');
                    if (oldIds !== newIds || (!hadCover && composer.coverDisplay)) { renderCompMedia(); renderPreview(); peUpdateSummaries(); }
                    else { updatePreviewCaption(); }
                    if (cb) cb(true);
                } else { setSaveStatus('Not saved'); if (cb) cb(false); }
            });
    }

    /* ---- preview ---- */
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
            var slides = assets.map(function (a, i) {
                var inner;
                if (a.type === 'video' && a.video_url && !locked) {
                    inner = '<video src="' + esc(a.video_url) + '"' + (a.thumb_url ? ' poster="' + esc(a.thumb_url) + '"' : '') + ' controls preload="metadata" playsinline></video>';
                } else {
                    inner = a.thumb_url ? '<img src="' + esc(a.thumb_url) + '" alt="">' : '<div class="cs-pv__slideph"><i class="fa-solid ' + typeIcon(a.type) + '"></i></div>';
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
            var lockLabel = isPpv ? ('Unlock for ' + credits(composer.ppv_price)) : 'Subscribe to unlock';
            var lock = locked ? '<div class="cs-pv__lock"><i class="fa-solid fa-lock"></i><span>' + lockLabel + '</span></div>' : '';
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
        var meta = (composer.comments_enabled == 0) ? '<p class="cs-pv__cap cs-pv__cap--muted cs-pv__meta">Comments are off.</p>' : '';
        $('#csPreviewCard').html(head + media + '<div class="cs-pv__body">' + cap + meta + '</div>');
        pvAutoplay();
    }
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
                if (v && !v.paused) { return; }
                pvGo(1);
            }, 3500);
        }
    }
    $('#csPreviewCard').on('click', '.cs-pv__nav', function () { pvGo($(this).data('pv') === 'next' ? 1 : -1); pvAutoplay(); });
    function updatePreviewCaption() {
        var cap = composer.caption ? '<p class="cs-pv__cap">' + esc(composer.caption) + '</p>' : '<p class="cs-pv__cap cs-pv__cap--muted">Your caption appears here.</p>';
        var meta = (composer.comments_enabled == 0) ? '<p class="cs-pv__cap cs-pv__cap--muted cs-pv__meta">Comments are off.</p>' : '';
        var $body = $('#csPreviewCard .cs-pv__body');
        if ($body.length) { $body.html(cap + meta); } else { renderPreview(); }
    }
    $('#csCompView').on('click', '.cs-pe__viewopt', function () { composer.view = $(this).data('view'); $('#csCompView .cs-pe__viewopt').removeClass('is-on').attr('aria-pressed', 'false'); $(this).addClass('is-on').attr('aria-pressed', 'true'); renderPreview(); });

    /* ---- field handlers ---- */
    $('#csPeCaptionAuto').on('click', function () {
        var $b = $(this), html = $b.html();
        $b.prop('disabled', true).addClass('is-busy').html('<span class="spinner-border spinner-border-sm"></span> Writing…');
        $('#csCompCaption').prop('disabled', true).attr('placeholder', 'Writing a caption…');
        ApiDataSvc.apiCall('post', 'post_caption_auto', { asset_ids: composer.assets.map(function (a) { return a.id; }), audience: composer.audience, hint: composer.caption.trim() }, function (resp) {
            var o = null; try { o = JSON.parse(resp); } catch (e) {}
            $b.prop('disabled', false).removeClass('is-busy').html(html);
            $('#csCompCaption').prop('disabled', false).attr('placeholder', 'Write a caption…');
            if (!o || !o.success) { toastr.error((o && o.message) || 'Could not write a caption right now.'); return; }
            $('#csCompCaption').val(o.caption).trigger('input').trigger('focus');
        });
    });
    $('#csCompCaption').on('input', function () { composer.caption = this.value; peRenderCount(); peClearError('caption'); peClearError('media'); updatePreviewCaption(); peUpdateSummaries(); peMarkDirty(); scheduleSave(); });
    function peSetAudience(aud) {
        if (composer.audience === 'subscribers') composer.lastTiers = composer.tier_ids.slice();
        if (composer.audience === 'ppv') composer.lastPpv = composer.ppv_price;
        composer.audience = aud;
        composer.tier_ids = (aud === 'subscribers') ? ((composer.lastTiers && composer.lastTiers.length) ? composer.lastTiers.slice() : allTierIds()) : [];
        if (aud === 'ppv') composer.ppv_price = composer.lastPpv || 50;
        $('#csCompAudience .cs-pe__choice').each(function () { $(this).attr('aria-checked', $(this).data('aud') === aud ? 'true' : 'false'); });
        $('#csCompTier').prop('hidden', aud !== 'subscribers'); peRenderTiers();
        $('#csCompPpv').prop('hidden', aud !== 'ppv'); $('#csCompPpvPrice').val(composer.ppv_price || 50);
        if (aud !== 'ppv') peClearError('price');
        renderPreview(); peUpdateSummaries(); peMarkDirty(); scheduleSave();
    }
    $('#csCompAudience').on('click', '.cs-pe__choice', function () { peSetAudience($(this).data('aud')); });
    $('#csCompAudience, #csPeMode').on('keydown', '.cs-pe__choice', function (e) {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
        e.preventDefault(); var items = $(this).parent().find('.cs-pe__choice'), i = items.index(this);
        items.eq((i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length).trigger('focus').trigger('click');
    });
    $('#csCompPpvPrice').on('input', function () { composer.ppv_price = this.value; composer.lastPpv = this.value; peClearError('price'); renderPreview(); peUpdateSummaries(); peMarkDirty(); scheduleSave(); });
    // On blur a valid number is shown with cents ("5" -> "5.00"); it is never changed to a different amount.
    $('#csCompPpvPrice').on('blur', function () { var d = parseFloat(this.value); if (!isNaN(d)) { this.value = Math.round(d); composer.ppv_price = this.value; composer.lastPpv = this.value; } renderPreview(); peUpdateSummaries(); scheduleSave(); });
    $('#csCompComments').on('change', function () { composer.comments_enabled = this.checked ? 1 : 0; updatePreviewCaption(); peMarkDirty(); scheduleSave(); });
    function peSetMode(mode) {
        composer.mode = mode;
        $('#csPeMode .cs-pe__choice').each(function () { $(this).attr('aria-checked', $(this).data('mode') === mode ? 'true' : 'false'); });
        $('#csCompSchedule').prop('hidden', mode !== 'schedule');
        if (mode === 'schedule' && !$('#csPeDate').val()) {
            var d = new Date(Date.now() + 3600000); d.setSeconds(0, 0);
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            $('#csPeDate').val(d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())); $('#csPeTime').val(pad(d.getHours()) + ':' + pad(d.getMinutes())); peSyncSchedAt();
        }
        if (mode !== 'schedule') peClearError('schedule');
        peUpdateSummaries(); peUpdateFooter();
    }
    $('#csPeMode').on('click', '.cs-pe__choice', function () { peSetMode($(this).data('mode')); });
    $('#csPeDate, #csPeTime').on('change input', function () { peSyncSchedAt(); peClearError('schedule'); peUpdateSummaries(); });

    /* ---- uploads + library picker ---- */
    function composerAddAsset(asset) {
        if (peUploading > 0) { peUploading--; peUploadStatus(); }
        if (!asset || composer.assets.some(function (a) { return a.id === asset.id; })) return;
        composer.assets.push({ id: asset.id, type: asset.type, thumb_url: asset.thumb_url, video_url: asset.video_url || '', duration: asset.duration, status: asset.status, is_cover: 0, missing: false });
        peClearError('media');
        renderCompMedia(); renderPreview(); peUpdateSummaries(); peMarkDirty(); scheduleSave();
    }
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
    $('#csPickerGrid').on('keydown', '.cs-tile--pick', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $(this).trigger('click'); } });
    $('#csPickerAdd').on('click', function () {
        Array.from(pickerSel).forEach(function (id) {
            var a = pickerAssets.filter(function (x) { return x.id === id; })[0];
            if (a && !composer.assets.some(function (x) { return x.id === id; })) composer.assets.push({ id: a.id, type: a.type, thumb_url: a.thumb_url, video_url: a.video_url || '', duration: a.duration, status: a.status, is_cover: 0, missing: false });
        });
        pickerModal.hide(); peClearError('media'); renderCompMedia(); renderPreview(); peUpdateSummaries(); peMarkDirty(); scheduleSave();
    });

    /* ---- actions: publish / schedule / draft / update ---- */
    function runAction(kind) {
        if (peSaving) return;
        var errors = peValidate(kind);
        if (errors.length) {
            var first = errors[0];
            peShowSection(PE_ERR_SECTION[first.key], false);
            setTimeout(function () { $(first.focus).filter(':visible').first().trigger('focus'); }, 30);
            return;
        }
        peSaving = true;
        var $btns = $('#csPePrimary, #csPeSecondary').prop('disabled', true);
        var label = $('#csPePrimary').text();
        $('#csPePrimary').html('<span class="spinner-border spinner-border-sm"></span> Saving…');
        function done() { peSaving = false; $btns.prop('disabled', false); $('#csPePrimary').text(label); }
        function fail(o) { done(); peShowServerError(o && o.message); }
        saveNow(function (ok) {
            if (!ok) { done(); toastr.error('Could not save the post. Please try again.'); return; }
            if (!composer.id) {
                done();
                if (kind === 'draft') { peSetError('media', 'Add a caption or media before saving a draft.'); peShowSection('content', false); $('#csCompCaption').trigger('focus'); }
                else { fail({ message: 'Add a photo, video, or caption before publishing.' }); }
                return;
            }
            if (kind === 'update') { done(); peSnapshot(); toastr.success('Changes saved'); composerModal.hide(); afterComposer(); return; }
            if (kind === 'draft') ApiDataSvc.apiCall('post', 'post_save_draft', { id: composer.id }, function (resp) { var o = JSON.parse(resp); done(); if (o.success) { composer.dirty = false; toastr.success('Draft saved'); composerModal.hide(); afterComposer(); } else fail(o); });
            else if (kind === 'schedule') ApiDataSvc.apiCall('post', 'post_schedule', { id: composer.id, scheduled_at: $('#csCompSchedAt').val(), share_accounts: Array.from(composer.share) }, function (resp) { var o = JSON.parse(resp); done(); if (o.success) { composer.dirty = false; toastr.success('Post scheduled'); composerModal.hide(); afterComposer(); } else fail(o); });
            else ApiDataSvc.apiCall('post', 'post_publish', { id: composer.id, share_accounts: Array.from(composer.share) }, function (resp) { var o = JSON.parse(resp); done(); if (o.success) { composer.dirty = false; toastr.success('Published'); composerModal.hide(); afterComposer(); } else fail(o); });
        });
    }

    /* ---- closing: never lose typed content silently ---- */
    function peRequestClose() {
        var hasContent = composer.caption.trim() || composer.assets.length;
        if (!hasContent || !peChangedSinceOpen()) { composerModal.hide(); return; }
        clearTimeout(composer.saveTimer);   // nothing autosaves while the question is open
        var snap = composer.snapshot;
        dialog({
            title: 'Discard changes?',
            bodyHtml: '<p class="text-body-secondary mb-0">You have changes since you opened this post. Discard them, or save the post as a draft.</p>',
            okText: 'Discard', danger: true,
            extra: { text: 'Save Draft', onClick: function () { composerModal.show(); runAction('draft'); } },
            onOk: function () {
                clearTimeout(composer.saveTimer); composer.dirty = false;
                if (composer.id && snap && !snap.id) {
                    // This draft only exists because of autosave during this session: remove it.
                    ApiDataSvc.apiCall('post', 'post_delete', { id: composer.id }, function () { afterComposer(); });
                } else if (composer.id && snap && snap.id) {
                    // Put the post back the way it was when the editor opened.
                    var f = snap.fields;
                    ApiDataSvc.apiCall('post', 'post_save', { id: composer.id, caption: f.caption, audience: f.audience, tier_ids: f.tier_ids, ppv_price: f.ppv_price, asset_ids: f.asset_ids, cover_id: f.asset_ids[0] || 0, comments_enabled: f.comments_enabled, on_cls: f.on_cls }, function () { afterComposer(); });
                }
                composerModal.hide();
            }
        });
        // Keep editing: resume autosave for whatever was pending.
        $('#csModal').one('hidden.bs.modal', function () { if ($('#csComposer').hasClass('show') && composer.dirty) { scheduleSave(); } });
    }
    $('#csPeClose').on('click', peRequestClose);
    $('#csComposer').on('keydown', function (e) { if (e.key === 'Escape' && !$('#csPicker').hasClass('show') && !$('#csModal').hasClass('show')) { e.preventDefault(); peRequestClose(); } });
    function afterComposer() { loadLibrary(); loadPosts(); loadCalendar(); }

    // Hand-off from the Influencers pages ("Use in a Post"): open a fresh composer with that asset attached.
    (function () {
        var handoff = '';
        try { handoff = sessionStorage.getItem('cs_open_asset') || ''; sessionStorage.removeItem('cs_open_asset'); } catch (e) {}
        if (!handoff) { return; }
        ApiDataSvc.apiCall('post', 'media_get', { id: parseInt(handoff, 10) }, function (resp) { var o = JSON.parse(resp);
            if (!o || !o.success || !o.asset) { return; }
            newComposer(); composerModal.show(); composerAddAsset(o.asset);
        });
    })();
    // Hand-off from Generate Carousel ("Use In Post"): the draft it made opens in the composer.
    (function () {
        var post = '';
        try { post = sessionStorage.getItem('cs_open_post') || ''; sessionStorage.removeItem('cs_open_post'); } catch (e) {}
        if (parseInt(post, 10) > 0) { openComposer(parseInt(post, 10)); }
    })();

    if (typeof toastr !== 'undefined') {
        toastr.options = $.extend(toastr.options || {}, { positionClass: 'toast-bottom-right', timeOut: 3200, preventDuplicates: true });
    }

    var postFilters = { state: '', search: '' };
    var postSel = new Set();

    $('#csTabPosts').on('shown.bs.tab', loadPosts);
    $('#csPostsRetry').on('click', loadPosts);
    $('#csPostsEmptyNew').on('click', function () { openComposer(null); });

    function fmtMoney(c) { return credits(Math.round(Math.max(0, c || 0) / 10)); }   // earnings are stored in cents; shown as credits
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
                $e.find('.cs-empty__title').text('No Posts Yet');
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
                ? '<span class="cs-post__aud cs-post__aud--ppv"><i class="fa-solid fa-coins"></i> PPV · ' + credits(p.ppv_price_credits || 0) + '</span>'
                : '<span class="cs-post__aud"><i class="fa-solid fa-globe"></i> Everyone</span>');
        var adult = (p.moderation === 'flagged' || p.moderation === 'adult') ? '<span class="cs-post__adult" title="Marked adult — shown only to fans with adult content on">18+</span>'
            : (p.moderation === 'pending') ? '<span class="cs-post__adult" title="Checking content — hidden from fans until the check finishes"><i class="fa-solid fa-shield-halved"></i> Checking</span>'
            : (p.moderation === 'blocked') ? '<span class="cs-post__blocked" title="Blocked by content check — cannot be published"><i class="fa-solid fa-ban"></i> Blocked</span>' : '';
        var when = p.when ? '<span class="cs-post__when">' + esc(p.when_label) + ' ' + esc(p.when) + '</span>' : '';
        var missing = p.media_missing ? '<span class="cs-post__warn"><i class="fa-solid fa-triangle-exclamation"></i> Media removed</span>' : '';
        var cap = p.caption ? esc(p.caption) : '<em class="cs-post__nocap">No caption</em>';
        var stats =
            '<span class="cs-post__stat"><i class="fa-regular fa-eye"></i> ' + p.views + '</span>' +
            '<span class="cs-post__stat"><i class="fa-regular fa-comment"></i> ' + p.comments + '</span>' +
            (p.audience === 'ppv' ? '<span class="cs-post__stat" title="Unlocks"><i class="fa-solid fa-lock-open"></i> ' + p.ppv_unlocks + '</span>' : '') +
            '<span class="cs-post__stat cs-post__earn">' + fmtMoney(p.earnings_cents) + '</span>' +
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
        peOpener = document.activeElement;
        newComposer();
        composerModal.show();
        $('#csPeDate').val(dateStr); $('#csPeTime').val('12:00'); peSyncSchedAt();
        peSetMode('schedule');
    }



    // =====================================================================
    // Scheduler (automations)
    // =====================================================================
    var schedModal, schedRules = [], schedRunning = {};
    var schedPlan = CFG.automation_plan || { can_create: true, included: true, message: '', upgrade_name: '' };   // what the plan allows; refreshed by scheduler_list
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
                schedPlan = { can_create: o.can_create !== false, included: o.included !== false, message: o.limit_message || '', upgrade_name: o.upgrade_name || '' };
                renderSchedRules();
            });
    }

    function renderSchedRules() {
        var $list = $('#csSchedList');
        // Not on this plan (Free): an upgrade prompt instead of the empty state; saved ones still list, locked.
        $('#csSchedUpgrade').prop('hidden', schedPlan.included);
        if (!schedPlan.included) {
            if (schedPlan.upgrade_name) { $('#csSchedUpgradeTitle').text('Automations Start on ' + schedPlan.upgrade_name); $('#csSchedUpgradeBtn').text('Upgrade to ' + schedPlan.upgrade_name); }
            $('#csSchedUpgradeText').text('Set one up and the Studio generates on-brand content and publishes it on your schedule. Your plan doesn\'t include automations' + (schedRules.length ? ', so the ones below are saved but paused.' : '.'));
        }
        if (!schedRules.length) { $list.prop('hidden', true).empty(); $('#csSchedEmpty').prop('hidden', !schedPlan.included); return; }
        $('#csSchedEmpty').prop('hidden', true);
        $list.prop('hidden', false).empty();
        schedRules.forEach(function (r) { $list.append(schedCard(r)); });
    }

    function schedSegLabel(g) { var l = (CFG.inbox || {}).segment_labels || {}; return l[g] || g; }
    function schedTargetsSummary(r) {
        var t = r.message_targets || {}, cls = t.cls || [];
        if (typeof cls === 'string') { cls = cls ? [cls] : []; }
        return cls.map(schedSegLabel).join(', ') || 'No audience';
    }
    function schedTargetsChecked() { return $('#csSchedTargets input[data-clsseg]:checked').map(function () { return String($(this).data('clsseg')); }).get(); }
    function schedCard(r) {
        var isMsg = r.kind === 'message';
        var aud = isMsg ? schedTargetsSummary(r) : (r.audience === 'subscribers' ? 'Subscribers only' : 'Everyone');
        var body = isMsg ? (r.message_ai ? ('AI: ' + r.topic) : r.message_text) : ((r.image_source === 'influencer' && r.influencer_name) ? (r.influencer_name + ' · ' + r.topic) : r.topic);
        var lastRun = r.last_status === 'success'
            ? '<span class="cs-sched__metaitem cs-sched__metaitem--ok"><i class="fa-solid fa-circle-check"></i>Last run OK</span>'
            : r.last_status === 'rendering'
            ? '<span class="cs-sched__metaitem"><i class="fa-solid fa-film"></i>Rendering video…</span>'
            : (r.last_status === 'failed'
                ? '<span class="cs-sched__metaitem cs-sched__metaitem--fail" title="' + esc(r.last_message || '') + '"><i class="fa-solid fa-circle-exclamation"></i>Last run failed' + (r.last_message ? ': ' + esc(r.last_message) : '') + '</span>'
                : '');
        var stTitle = r.active ? 'Active — posting on schedule. Click to pause.' : 'Paused. Click to activate.';
        if (r.locked) {   // over the plan's limit: kept, not deleted, but it doesn't run
            return $('<div class="cs-sched__card is-paused is-locked" data-id="' + r.id + '"><div class="cs-sched__body"><div class="cs-sched__top"><span class="cs-sched__name">' + esc(r.name) + '</span>' +
                '<span class="cs-sched__status"><i class="fa-solid fa-lock"></i> Locked</span></div><p class="cs-sched__topic">' + esc(body) + '</p>' +
                '<div class="cs-sched__meta"><span class="cs-sched__metaitem">Over your plan\'s automation limit. Saved, not running. <a href="/account/billing">Upgrade</a> to turn it back on.</span></div></div>' +
                '<div class="cs-sched__actions"><button type="button" class="cs-sched__btn cs-sched__btn--icon cs-sched__btn--danger" data-sched-del aria-label="Delete" title="Delete"><i class="fa-solid fa-trash-can"></i></button></div></div>');
        }
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
                    (r.credits_per_run > 0 ? '<span class="cs-sched__metaitem"><i class="fa-solid fa-wand-magic-sparkles"></i>' + fmtNum(r.credits_per_run) + ' AI credits per post · about ' + fmtNum(r.credits_per_month) + ' a month</span>' : '') +
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

    function schedLimitPrompt() {
        Swal.fire({ title: schedPlan.included ? 'Automation limit reached' : 'Upgrade for automations', text: schedPlan.message,
            showCancelButton: true, reverseButtons: true, confirmButtonText: 'See Plans', cancelButtonText: 'Not Now',
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }, buttonsStyling: false })
            .then(function (r) { if (r.isConfirmed) { window.location.href = '/account/billing'; } });
    }
    $('#csSchedNew, #csSchedEmptyNew').on('click', function () { if (!schedPlan.can_create) { schedLimitPrompt(); return; } openSchedForm(null, 'post'); });
    // Deep links from the Influencers pages: #automation-new-<id> opens a preset automation,
    // #library-influencer-<id> shows her media in the Library. Deferred: the editor's helpers further down
    // this file (SCHED_ERR_SECTION etc.) aren't defined yet at this point.
    setTimeout(function () {
        var m = /^#automation-new-(\d+)$/.exec(window.location.hash || '');
        if (m) {
            var tab = document.querySelector('#csTabScheduler'); if (tab && window.bootstrap) { bootstrap.Tab.getOrCreateInstance(tab).show(); }
            openSchedForm(null, 'post');
            setSchedImageSource('influencer', m[1]); $('#csSchedInfluencer').trigger('change');
            return;
        }
        m = /^#library-influencer-(\d+)$/.exec(window.location.hash || '');
        if (m && $('#csFilterInfluencer').length) { gotoLibraryTab(); $('#csFilterInfluencer').val(m[1]); state.filters.influencer = m[1]; loadLibrary(); }
    }, 0);
    $('#csSchedNewMsg').on('click', function () { if (!schedPlan.can_create) { schedLimitPrompt(); return; } openSchedForm(null, 'message'); });
    $('#csSchedList').on('click', '[data-sched-edit]', function () {
        var id = $(this).closest('.cs-sched__card').data('id');
        openSchedForm(schedRules.filter(function (r) { return r.id == id; })[0] || null);
    });

    /* ---- automation editor: one section at a time, left nav with live summaries ---- */
    var SCHED_SECTIONS = ['content', 'publishing', 'destinations', 'schedule'];
    var schedOpener = null;   // element that opened the editor; focus goes back to it on close

    function schedSections() { return SCHED_SECTIONS.filter(function (k) { return k !== 'publishing' || $('#csSchedKind').val() === 'post'; }); }
    function showSchedSection(key, focusHeading) {
        if (schedSections().indexOf(key) < 0) { key = 'content'; }
        $('#csSchedMain .cs-ae__section').each(function () { $(this).prop('hidden', $(this).data('section') !== key); });
        $('#csSchedNav .cs-ae__navitem').each(function () {
            var on = $(this).data('section') === key;
            if (on) { $(this).attr('aria-current', 'true'); } else { $(this).removeAttr('aria-current'); }
        });
        $('#csSchedNavSelect').val(key);
        $('#csSchedMain').scrollTop(0);
        if (focusHeading) { var h = document.getElementById('csSchedH_' + key); if (h) { h.focus({ preventScroll: true }); } }
    }
    $('#csSchedNav').on('click', '.cs-ae__navitem', function () { showSchedSection($(this).data('section'), true); });
    $('#csSchedNav').on('keydown', '.cs-ae__navitem', function (e) {
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') { return; }
        e.preventDefault();
        var items = $('#csSchedNav .cs-ae__navitem:visible'), i = items.index(this);
        items.eq((i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length).trigger('focus');
    });
    $('#csSchedNavSelect').on('change', function () { showSchedSection(this.value, true); });

    function schedSum(key, text) { $('#csSchedNav [data-sum="' + key + '"]').text(text); }
    /* What this automation costs in AI credits, from its kind and schedule (Plan::automation_credits does the same on the server). */
    function schedCredits() {
        var kind = $('#csSchedKind').val() || 'post';
        var $c = $('#csSchedCredits');
        if (kind !== 'post') { $c.removeClass('is-low').text('Scheduled messages don\'t use AI credits.'); return; }
        var per = (parseInt(CFG.ai.image_price, 10) || 0) + schedVideoCredits();
        var runs = (schedForm.cadence === 'weekly') ? Math.round($('#csSchedDays .cs-ae__day.is-on').length * 52 / 12) : 30;
        var bal = parseInt(CFG.ai.balance, 10) || 0;
        var low = bal < per * runs;
        $c.toggleClass('is-low', low).html('Uses <b>' + fmtNum(per) + ' AI credits</b> per post, about <b>' + fmtNum(per * runs) + '</b> a month. You have ' + fmtNum(bal) + '.'
            + (low ? ' <a href="/account/billing?tab=credits">Buy credits</a>' : ''));
    }
    /* Video automations: the still, then animating it with the chosen style (credits per CFG.ai.video_models). */
    function schedVideoModels() { return (CFG.ai && CFG.ai.video_models) || []; }
    function schedIsVideo() { return schedForm.media_type === 'video'; }
    function schedVideoCredits() {
        if (!schedIsVideo()) { return 0; }
        var m = schedVideoModels().filter(function (x) { return x.key === $('#csSchedVideoModel').val(); })[0];
        return m ? videoPrice(m, $('#csSchedVideoDur').val()) : 0;
    }
    // Style and Length are segmented buttons like every other choice here; their values live in hidden inputs.
    function schedTitleCase(t) { return String(t).replace(/\b\w/g, function (c) { return c.toUpperCase(); }); }
    function renderSchedVideoModels(key, dur) {
        var models = schedVideoModels();
        if (!models.some(function (m) { return m.key === key; })) { key = models.length ? models[0].key : ''; }
        $('#csSchedVideoModel').val(key);
        var $m = $('#csSchedVideoModels').empty();
        models.forEach(function (m) {
            $m.append($('<button type="button" class="cs-seg__opt">').attr('data-vmodel', m.key).attr('title', m.purpose || '')
                .toggleClass('is-on', m.key === key)
                .append($('<span>').text(schedTitleCase(m.label))));
        });
        syncPressed('#csSchedVideoModels');
        renderSchedVideoDurations(dur);
    }
    function renderSchedVideoDurations(dur) {
        var m = schedVideoModels().filter(function (x) { return x.key === $('#csSchedVideoModel').val(); })[0];
        var durs = ((m && m.durations) || []).map(String);
        dur = String(dur || '');
        if (durs.indexOf(dur) < 0) { dur = durs.length ? durs[0] : ''; }
        $('#csSchedVideoDur').val(dur);
        var $d = $('#csSchedVideoDurs').empty();
        durs.forEach(function (s) { $d.append($('<button type="button" class="cs-seg__opt">').attr('data-vdur', s).toggleClass('is-on', s === dur)
            .append($('<span>').text(s + ' seconds'), $('<span class="cs-seg__meta">').text(fmtNum(videoPrice(m, s)) + ' AI credits'))); });
        syncPressed('#csSchedVideoDurs');
        updateSchedSummaries();
    }
    $('#csSchedVideoModels').on('click', '.cs-seg__opt', function () { renderSchedVideoModels($(this).attr('data-vmodel'), $('#csSchedVideoDur').val()); updateSchedSummaries(); });
    $('#csSchedVideoDurs').on('click', '.cs-seg__opt', function () { renderSchedVideoDurations($(this).attr('data-vdur')); });
    function setSchedMedia(media) {
        schedForm.media_type = (media === 'video' && schedVideoModels().length) ? 'video' : 'image';
        $('#csSchedMedia .cs-seg__opt').each(function () { $(this).toggleClass('is-on', $(this).data('media') === schedForm.media_type); });
        syncPressed('#csSchedMedia');
        $('#csSchedVideoWrap').prop('hidden', !schedIsVideo());
        $('#csSchedMediaWrap').prop('hidden', !schedVideoModels().length);   // no video model configured: images only
        updateSchedSummaries();
    }
    $('#csSchedMedia').on('click', '.cs-seg__opt', function () {
        setSchedMedia($(this).data('media'));
        if ($(this).data('media') === 'video' && CFG.aspect) { setSchedSize(CFG.aspect.default_video); }   // video starts at 9:16; the shape can still be changed
    });

    function updateSchedSummaries() {
        schedCredits();
        var kind = $('#csSchedKind').val() || 'post';
        if (kind === 'post') {
            var src = schedForm.image_source === 'influencer' ? (schedInfluencerName(schedForm.influencer_id) || 'Influencer') : 'Brand photo';
            var size = aspectKey($('#csSchedSize').val());
            schedSum('content', src + ' · ' + ((CFG.aspect && CFG.aspect.names[size]) || size) + (schedIsVideo() ? ' · Video' : ''));
            var aud = schedForm.audience === 'subscribers' ? 'Subscribers' : 'Everyone';
            var tier = schedForm.audience === 'subscribers' ? ($('#csSchedTierSel option:selected').text() || 'All tiers') : ($('#csSchedAi').is(':checked') ? 'AI captions' : 'Own caption');
            schedSum('publishing', aud + ' · ' + tier);
            var n = $('#csSchedSocial input[data-sacct]:checked').length, total = $('#csSchedSocial input[data-sacct]').length;
            schedSum('destinations', total ? (n + ' of ' + total + ' account' + (total === 1 ? '' : 's')) : 'No accounts connected');
        } else {
            schedSum('content', $('#csSchedMsgAi').is(':checked') ? 'Written by AI' : 'Written by you');
            schedSum('destinations', schedTargetsSummary({ message_targets: { cls: schedTargetsChecked() } }));
        }
        var days = $('#csSchedDays .cs-ae__day.is-on').length;
        var time = $('#csSchedTime').val() || '09:00';
        schedSum('schedule', (schedForm.cadence === 'weekly' ? ('Weekly · ' + days + ' day' + (days === 1 ? '' : 's')) : 'Daily') + ' · ' + fmtTime12(time));
    }
    function fmtTime12(t) { var p = String(t).split(':'), h = parseInt(p[0], 10), m = p[1] || '00'; if (isNaN(h)) { return t; } return ((h % 12) || 12) + ':' + m + ' ' + (h < 12 ? 'AM' : 'PM'); }
    function schedInfluencerName(id) { var inf = ((CFG.influencers && CFG.influencers.ready) || []).filter(function (i) { return String(i.id) === String(id); })[0]; return inf ? inf.name : ''; }
    function updateSchedTitle() {
        var name = ($('#csSchedName').val() || '').trim(), kind = $('#csSchedKind').val() || 'post';
        $('#csSchedModalTitle').text(name !== '' ? name : (kind === 'message' ? 'New scheduled message' : 'New Automation'));
    }
    $('#csSchedName').on('input', function () { updateSchedTitle(); schedClearError('name'); });

    /* validation: inline message under the field + a flag on the section's nav item */
    var SCHED_ERR_SECTION = { name: 'content', topic: 'content', message: 'content', influencer: 'content', targets: 'destinations', days: 'schedule' };
    function schedSetError(key, msg) {
        var $p = $('#csSchedErr_' + key).text(msg).prop('hidden', false);
        $('#csSchedErr_' + key).closest('.cs-ae__field').find('.form-control, .form-select').first().addClass('is-invalid');
        $('#csSchedNav [data-section="' + SCHED_ERR_SECTION[key] + '"] .cs-ae__navflag').prop('hidden', false);
        return $p;
    }
    function schedClearError(key) {
        $('#csSchedErr_' + key).prop('hidden', true).text('');
        $('#csSchedErr_' + key).closest('.cs-ae__field').find('.is-invalid').removeClass('is-invalid');
        var sec = SCHED_ERR_SECTION[key];
        if (!$('#csSchedMain [data-section="' + sec + '"] .cs-ae__error:not([hidden])').length) { $('#csSchedNav [data-section="' + sec + '"] .cs-ae__navflag').prop('hidden', true); }
    }
    function schedClearErrors() { Object.keys(SCHED_ERR_SECTION).forEach(schedClearError); }
    $('#csSchedTopic').on('input', function () { schedClearError('topic'); });
    $('#csSchedMsgText').on('input', function () { schedClearError('message'); });

    function setSchedKind(kind) {
        $('#csSchedKind').val(kind);
        $('#csSchedulerModal [data-kind="post"]').prop('hidden', kind !== 'post');
        $('#csSchedulerModal [data-kind="message"]').prop('hidden', kind !== 'message');
        $('#csSchedNavSelect option[data-kind="post"]').prop('disabled', kind !== 'post');
        $('#csSchedEyebrow').text(kind === 'message' ? 'Scheduled message' : 'Automation');
        setSchedMsgAi();
    }
    function setSchedMsgAi() {
        var kind = $('#csSchedKind').val(), ai = $('#csSchedMsgAi').is(':checked');
        $('#csSchedMsgTextWrap').prop('hidden', kind !== 'message' || ai);
        $('#csSchedTopicWrap').prop('hidden', kind === 'message' && !ai);
        updateSchedSummaries();
    }
    $('#csSchedMsgAi').on('change', setSchedMsgAi);
    function setSchedAi() {
        $('#csSchedCaptionWrap').prop('hidden', $('#csSchedAi').is(':checked'));
        updateSchedSummaries();
    }
    $('#csSchedAi').on('change', setSchedAi);
    $('#csSchedComments').on('change', updateSchedSummaries);

    function renderSchedTargets(sel) {
        var ib = CFG.inbox || {}, $wrap = $('#csSchedTargets').empty();
        var cls = (sel && sel.cls) || []; if (typeof cls === 'string') { cls = cls ? [cls] : []; }
        var on = new Set(cls.map(String));
        (ib.cls_segments || ['all', 'followers', 'subscribers']).forEach(function (g) {
            var id = 'csSchedSeg_' + g;
            $wrap.append('<label class="cs-social" for="' + id + '"><input class="form-check-input" type="checkbox" id="' + id + '" data-clsseg="' + esc(g) + '"' + (on.has(g) ? ' checked' : '') + '><span class="cs-social__name">' + esc(schedSegLabel(g)) + '</span></label>');
        });
    }
    $('#csSchedTargets').on('change', 'input, select', function () { schedClearError('targets'); updateSchedSummaries(); });

    /* destinations: filterable list of every connected account; the checkbox keeps data-sacct so saving is unchanged */
    function renderSchedSocial(selected) {
        var soc = CFG.social || { accounts: [] };
        var $wrap = $('#csSchedSocial').empty();
        var sel = new Set((selected || []).map(String));
        var accounts = soc.accounts || [];
        $('#csSchedDestEmpty').prop('hidden', accounts.length > 0);
        $('#csSchedDestSearch').val('').prop('disabled', !accounts.length);
        $('#csSchedDestNone').prop('hidden', true);
        accounts.forEach(function (a) {
            var on = sel.has(String(a.id));
            var name = a.username || a.platform;
            var plat = socialPlatformLabel(a.platform);
            var id = 'csSchedDest_' + String(a.id).replace(/[^A-Za-z0-9_-]/g, '_');
            $wrap.append('<label class="cs-ae__dest' + (on ? ' is-on' : '') + '" for="' + id + '" data-search="' + esc((name + ' ' + plat).toLowerCase()) + '">' +
                '<input class="form-check-input" type="checkbox" id="' + id + '" data-sacct="' + esc(a.id) + '"' + (on ? ' checked' : '') + '>' +
                '<i class="' + socialIconClass(a.platform) + ' cs-ae__desticon" aria-hidden="true"></i>' +
                '<span class="cs-ae__destname" title="' + esc(name) + '">' + esc(name) + '</span>' +
                '<span class="cs-ae__destplat">' + esc(plat) + '</span></label>');
        });
        updateSchedDestCount();
    }
    function socialPlatformLabel(p) { var m = { x: 'X', twitter: 'X', instagram: 'Instagram', facebook: 'Facebook', tiktok: 'TikTok', bluesky: 'Bluesky', threads: 'Threads', youtube: 'YouTube', linkedin: 'LinkedIn', pinterest: 'Pinterest', fanvue: 'Fanvue' }; p = String(p || '').toLowerCase(); return m[p] || (p.charAt(0).toUpperCase() + p.slice(1)); }
    function updateSchedDestCount() {
        var n = $('#csSchedSocial input[data-sacct]:checked').length, total = $('#csSchedSocial input[data-sacct]').length;
        $('#csSchedDestCount').text(total ? (n + ' of ' + total + ' selected') : '');
        updateSchedSummaries();
    }
    $('#csSchedSocial').on('change', 'input[data-sacct]', function () { $(this).closest('.cs-ae__dest').toggleClass('is-on', this.checked); updateSchedDestCount(); });
    $('#csSchedDestSearch').on('input', function () {
        var q = String(this.value || '').trim().toLowerCase(), shown = 0;
        $('#csSchedSocial .cs-ae__dest').each(function () { var hit = q === '' || String($(this).data('search')).indexOf(q) >= 0; $(this).prop('hidden', !hit); if (hit) { shown++; } });
        $('#csSchedDestNone').prop('hidden', shown > 0 || !$('#csSchedSocial .cs-ae__dest').length);
    });

    function renderSchedDays(selected) {
        var sel = new Set((selected || []).map(Number));
        var $wrap = $('#csSchedDays').empty();
        DOW_LABELS.forEach(function (lbl, i) {
            $wrap.append('<button type="button" class="cs-ae__day' + (sel.has(i) ? ' is-on' : '') + '" aria-pressed="' + (sel.has(i) ? 'true' : 'false') + '" data-day="' + i + '" aria-label="' + DOW_NAMES[i] + '">' + lbl + '</button>');
        });
    }
    var DOW_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $('#csSchedDays').on('click', '.cs-ae__day', function () { $(this).toggleClass('is-on'); syncPressed('#csSchedDays'); schedClearError('days'); updateSchedSummaries(); });
    $('#csSchedTime').on('change input', updateSchedSummaries);

    function openSchedForm(rule, kind) {
        if (!schedModal) {
            schedModal = bootstrap.Modal.getOrCreateInstance('#csSchedulerModal');
            $('#csSchedulerModal').on('hidden.bs.modal', function () { if (schedOpener && schedOpener.focus) { try { schedOpener.focus(); } catch (e) {} } schedOpener = null; });
        }
        schedOpener = document.activeElement;
        kind = rule ? (rule.kind || 'post') : (kind || 'post');
        schedClearErrors();
        renderSchedTargets(rule ? rule.message_targets : null);
        $('#csSchedMsgAi').prop('checked', rule ? !!rule.message_ai : false);
        $('#csSchedMsgText').val(rule ? (rule.message_text || '') : '');
        setSchedKind(kind);
        renderSchedSocial(rule ? rule.social_accounts : []);
        renderSchedDays(rule ? rule.days_of_week : []);

        $('#csSchedId').val(rule ? rule.id : 0);
        $('#csSchedName').val(rule ? rule.name : '');
        $('#csSchedTopic').val(rule ? rule.topic : '');
        setSchedSize(rule ? rule.size : '');   // new automations: 3:4 (full bodies warp in a square frame)
        $('#csSchedBrand').prop('checked', rule ? !!rule.use_brand : true);
        $('#csSchedComments').prop('checked', rule ? rule.comments_enabled != 0 : true);
        $('#csSchedAi').prop('checked', rule ? (rule.ai_assist === undefined || rule.ai_assist != 0) : true);
        $('#csSchedCaption').val(rule ? (rule.caption_text || '') : '');
        setSchedAi();
        $('#csSchedTime').val(rule ? rule.run_time : '09:00');
        schedForm.cadence = rule ? rule.cadence : 'daily';
        schedForm.influencer_id = rule ? String(rule.influencer_id || '') : '';
        schedForm.media_type = rule ? (rule.media_type || 'image') : 'image';
        $('#csSchedVideoPrompt').val(rule ? (rule.video_prompt || '') : '');
        renderSchedVideoModels(rule ? rule.video_model_key : '', rule ? rule.video_duration : '');
        setSchedMedia(schedForm.media_type);
        setSchedImageSource(rule ? (rule.image_source || 'brand') : 'brand', rule ? rule.influencer_id : '');
        setSchedAudience(rule ? rule.audience : 'free', rule ? rule.tier_id : '');
        setSchedCadence(schedForm.cadence);

        // header: live name, status only for an existing rule; footer: delete only for an existing rule
        updateSchedTitle();
        $('#csSchedStatus').prop('hidden', !rule).toggleClass('is-active', !!(rule && rule.active));
        $('#csSchedStatusText').text(rule && rule.active ? 'Active' : 'Paused');
        $('#csSchedDelete').prop('hidden', !rule).text(kind === 'message' ? 'Delete message' : 'Delete automation');
        $('#csSchedSave').prop('disabled', false).text(rule ? 'Save Changes' : (kind === 'message' ? 'Create message' : 'Create automation'));
        updateSchedSummaries();
        showSchedSection('content', false);
        schedModal.show();
        $('#csSchedulerModal').off('shown.bs.modal.ae').one('shown.bs.modal.ae', function () { $('#csSchedName').trigger('focus'); });
    }

    // Mirror the visual selected state to aria-pressed for segmented controls and day pills.
    function syncPressed(sel) { $(sel).find('.cs-seg__opt, .cs-ae__day').each(function () { $(this).attr('aria-pressed', $(this).hasClass('is-on') ? 'true' : 'false'); }); }
    $('#csSchedShape').on('click', '.cs-seg__opt', function () { setSchedSize($(this).data('size')); });
    // A shape key as the server stores it now ('3:4'); rules saved before the ratios carry square | portrait | landscape.
    function aspectKey(v) {
        var A = CFG.aspect || { keys: [], legacy: {}, default_image: '3:4' };
        v = String(v || '');
        if (A.legacy[v]) { return A.legacy[v]; }
        return A.keys.indexOf(v) >= 0 ? v : A.default_image;
    }
    function setSchedSize(size) {
        size = aspectKey(size);
        $('#csSchedSize').val(size);
        $('#csSchedShape .cs-seg__opt').each(function () { $(this).toggleClass('is-on', $(this).data('size') === size); });
        syncPressed('#csSchedShape');
        updateSchedSummaries();
    }
    // Image source: Brand photo or a trained influencer. Switching to Brand hides the influencer
    // field but keeps the choice, so switching back restores it.
    $('#csSchedImageSource').on('click', '.cs-seg__opt', function () { if (!this.disabled) { setSchedImageSource($(this).data('src')); } });
    function setSchedImageSource(src, selectedId) {
        var influencers = (CFG.influencers && CFG.influencers.ready) || [];
        if (src === 'influencer' && !influencers.length) { toastr.info('Train an influencer first.'); src = 'brand'; }
        schedForm.image_source = (src === 'influencer') ? 'influencer' : 'brand';
        $('#csSchedImageSource .cs-seg__opt').each(function () { $(this).toggleClass('is-on', $(this).data('src') === schedForm.image_source); });
        syncPressed('#csSchedImageSource');
        var isInf = schedForm.image_source === 'influencer';
        $('#csSchedInfluencerWrap').prop('hidden', !isInf);

        $('#csSchedTopic').attr('placeholder', '');
        if (isInf) {
            var want = selectedId ? String(selectedId) : (schedForm.influencer_id || '');
            if (!want || !influencers.some(function (i) { return String(i.id) === want; })) { want = String(influencers[0].id); }
            $('#csSchedInfluencer').val(want);
            schedForm.influencer_id = want;
        } else { schedClearError('influencer'); }
        updateSchedSummaries();
    }
    $('#csSchedInfluencer').on('change', function () {
        schedForm.influencer_id = String($('#csSchedInfluencer').val() || '');
        schedClearError('influencer'); updateSchedSummaries();
        // A new influencer automation starts with her default share targets.
        if (parseInt($('#csSchedId').val(), 10) > 0) { return; }
        var inf = ((CFG.influencers && CFG.influencers.ready) || []).filter(function (i) { return String(i.id) === schedForm.influencer_id; })[0];
        if (inf && inf.share_accounts && inf.share_accounts.length) { renderSchedSocial(inf.share_accounts); }
    });
    // Audience: Everyone or Subscribers; the Tier select only exists for subscribers and remembers its value.
    function setSchedAudience(a, tierId) {
        schedForm.audience = (a === 'subscribers') ? 'subscribers' : 'free';
        $('#csSchedAudience').val(schedForm.audience);
        if (tierId !== undefined && tierId !== null && String(tierId) !== '') { schedForm.tier_id = String(tierId); }
        if (schedForm.tier_id && !$('#csSchedTierSel option[value="' + schedForm.tier_id + '"]').length) { schedForm.tier_id = ''; }
        $('#csSchedTierSel').val(schedForm.tier_id || '');
        $('#csSchedTier').prop('hidden', schedForm.audience !== 'subscribers');
        $('#csSchedAudienceRow').toggleClass('is-single', schedForm.audience !== 'subscribers');
        updateSchedSummaries();
    }
    $('#csSchedAudience').on('change', function () { setSchedAudience(this.value, undefined); });
    $('#csSchedTierSel').on('change', function () { schedForm.tier_id = String(this.value || ''); updateSchedSummaries(); });
    $('#csSchedCadence').on('click', '.cs-seg__opt', function () { setSchedCadence($(this).data('cad')); });
    function setSchedCadence(c) {
        schedForm.cadence = (c === 'weekly') ? 'weekly' : 'daily';
        $('#csSchedCadence .cs-seg__opt').each(function () { $(this).toggleClass('is-on', $(this).data('cad') === schedForm.cadence); });
        $('#csSchedDaysRow').prop('hidden', schedForm.cadence !== 'weekly');
        if (schedForm.cadence !== 'weekly') { schedClearError('days'); }
        syncPressed('#csSchedCadence'); syncPressed('#csSchedDays');
        updateSchedSummaries();
    }

    /* validate every section; the first problem switches to its section and focuses the field */
    function validateSchedForm() {
        schedClearErrors();
        var kind = $('#csSchedKind').val() || 'post';
        var name = ($('#csSchedName').val() || '').trim(), topic = ($('#csSchedTopic').val() || '').trim();
        var msgAi = $('#csSchedMsgAi').is(':checked'), msgText = ($('#csSchedMsgText').val() || '').trim();
        var errors = [];
        if (name === '') { errors.push({ key: 'name', msg: 'Give it a name.', focus: '#csSchedName' }); }
        if (kind === 'message') {
            if (msgAi && topic === '') { errors.push({ key: 'topic', msg: 'Tell the AI what the message is about.', focus: '#csSchedTopic' }); }
            if (!msgAi && msgText === '') { errors.push({ key: 'message', msg: 'Write the message.', focus: '#csSchedMsgText' }); }
            if (!schedTargetsChecked().length) { errors.push({ key: 'targets', msg: 'Pick who gets the message.', focus: '#csSchedTargets input' }); }
        } else {
            if (topic === '') { errors.push({ key: 'topic', msg: schedForm.image_source === 'influencer' ? 'Describe the scene.' : 'Describe what to post.', focus: '#csSchedTopic' }); }
            if (schedForm.image_source === 'influencer' && !schedForm.influencer_id) { errors.push({ key: 'influencer', msg: 'Pick an influencer.', focus: '#csSchedInfluencer' }); }
        }
        if (schedForm.cadence === 'weekly' && !$('#csSchedDays .cs-ae__day.is-on').length) { errors.push({ key: 'days', msg: 'Pick at least one day of the week.', focus: '#csSchedDays .cs-ae__day' }); }
        errors.forEach(function (e) { schedSetError(e.key, e.msg); });
        return errors;
    }

    var schedSaving = false;
    $('#csSchedSave').on('click', function () {
        if (schedSaving) { return; }
        var errors = validateSchedForm();
        if (errors.length) {
            var first = errors[0];
            showSchedSection(SCHED_ERR_SECTION[first.key], false);
            setTimeout(function () { $(first.focus).first().trigger('focus'); }, 30);
            return;
        }
        var kind = $('#csSchedKind').val() || 'post';
        var name = ($('#csSchedName').val() || '').trim(), topic = ($('#csSchedTopic').val() || '').trim();
        var msgAi = $('#csSchedMsgAi').is(':checked'), msgText = ($('#csSchedMsgText').val() || '').trim();
        var targets = { cls: schedTargetsChecked() };
        var influencerId = (kind === 'post' && schedForm.image_source === 'influencer') ? (schedForm.influencer_id || '') : '';
        var days = $('#csSchedDays .cs-ae__day.is-on').map(function () { return $(this).data('day'); }).get();
        var social = $('#csSchedSocial input[data-sacct]:checked').map(function () { return String($(this).data('sacct')); }).get();
        var isNew = !(parseInt($('#csSchedId').val(), 10) > 0);
        var label = isNew ? (kind === 'message' ? 'Create message' : 'Create automation') : 'Save Changes';
        var $btn = $(this);
        schedSaving = true;
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving…');
        ApiDataSvc.apiCall('post', 'scheduler_save', {
            id: $('#csSchedId').val(), name: name, topic: topic,
            kind: kind, message_ai: msgAi ? '1' : '0', message_text: msgText, message_targets: JSON.stringify(targets),
            image_source: schedForm.image_source || 'brand', influencer_id: influencerId, content_level: 'safe',
            media_type: (kind === 'post' && schedIsVideo()) ? 'video' : 'image', video_prompt: $('#csSchedVideoPrompt').val() || '',
            video_model_key: $('#csSchedVideoModel').val() || '', video_duration: $('#csSchedVideoDur').val() || '',
            size: $('#csSchedSize').val(), audience: schedForm.audience,
            tier_id: schedForm.audience === 'subscribers' ? (schedForm.tier_id || '') : '',
            comments_enabled: $('#csSchedComments').is(':checked') ? '1' : '0',
            use_brand: $('#csSchedBrand').is(':checked') ? '1' : '0',
            ai_assist: $('#csSchedAi').is(':checked') ? '1' : '0', caption_text: $('#csSchedCaption').val() || '',
            social_accounts: social, cadence: schedForm.cadence, days_of_week: days,
            run_time: $('#csSchedTime').val() || '09:00',
            timezone: (CFG.creator && CFG.creator.timezone) || USER_TZ || '', active: '1'
        }, function (resp) { var o = JSON.parse(resp);
            schedSaving = false;
            $btn.prop('disabled', false).text(label);
            if (!o.success) { toastr.error(o.message); return; }
            schedModal.hide(); toastr.success(isNew ? 'Automation created' : 'Changes saved'); loadScheduler();
        });
    });

    $('#csSchedDelete').on('click', function () {
        var id = parseInt($('#csSchedId').val(), 10);
        if (!(id > 0)) { return; }
        var r = schedRules.filter(function (x) { return x.id == id; })[0];
        var isMsg = $('#csSchedKind').val() === 'message';
        // Bootstrap can't stack dialogs: close the editor, then ask.
        schedModal.hide();
        $('#csSchedulerModal').one('hidden.bs.modal', function () { setTimeout(function () {
            confirmDialog(isMsg ? 'Delete scheduled message?' : 'Delete automation?', 'Remove “' + esc((r && r.name) || 'this automation') + '”? Posts it already published stay.', 'Delete', true, function () {
                ApiDataSvc.apiCall('post', 'scheduler_delete', { id: id }, function (resp) { var o = JSON.parse(resp); if (o.success) { toastr.success('Removed'); loadScheduler(); } else { toastr.error(o.message || 'Could not delete.'); } });
            });
        }, 120); });
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
        function finish(ok, m, rendering) {
            delete schedRunning[id];
            if (rendering) { toastr.info(m); }
            else if (ok) { if (/failed/i.test(m)) { toastr.warning(m); } else { toastr.success(m || 'Published a new post.'); } }
            else { toastr.error(m || 'Run failed.'); }
            loadScheduler();
        }
        ApiDataSvc.apiCall('post', 'scheduler_run_now', { id: id }, function (data) {
            var o = null; try { o = (data && typeof data === 'object') ? data : JSON.parse(data); } catch (e) {}
            if (!o || !o.success) { finish(false, o && o.message); return; }
            if (!o.queued) { finish(true, o.message); return; }
            // The server answered immediately and is still working; poll until the run is recorded.
            var since = o.since || 0, tries = 0;
            (function poll() {
                tries++;
                // If a poll request is lost (gateway hiccup), the watchdog asks again instead of leaving the button stuck.
                var watchdog = setTimeout(poll, 15000);
                ApiDataSvc.apiCall('post', 'scheduler_run_status', { id: id, since: since }, function (d2) {
                    clearTimeout(watchdog);
                    var r = null; try { r = JSON.parse(d2); } catch (e) {}
                    if (r && r.success && r.done) { finish(!!r.ok, r.message, !!r.rendering); return; }
                    if (tries >= 60) { finish(false, 'Still running in the background. Refresh in a minute to see the result.'); return; }
                    setTimeout(poll, 4000);
                });
            })();
        });
    });





    loadCollections();
    loadLibrary();
    loadPosts();   // Posts is the first tab, so it does not get a shown.bs.tab on load
});
