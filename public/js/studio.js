/* Content Studio — Library, uploads, collections, detail.
   Built on jQuery + Bootstrap (Offcanvas, Modal, Tabs). CSRF header is added
   globally for POSTs in api.data.js. */
jQuery(function ($) {
    "use strict";

    if (!$('#cs').length) return; // gate page (non-creator) — nothing to wire

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

    // ---- helpers ----
    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
    function apiGet(ep, data) { return $.ajax({ url: '/api/' + ep, method: 'GET', data: data || {}, dataType: 'json' }); }
    function apiPost(ep, data) { return $.ajax({ url: '/api/' + ep, method: 'POST', data: data || {}, dataType: 'json' }); }
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

    // =====================================================================
    // Reusable Bootstrap dialogs
    // =====================================================================
    function dialog(opts) {
        var m = bootstrap.Modal.getOrCreateInstance('#csModal');
        $('#csModalTitle').text(opts.title);
        $('#csModalBody').html(opts.bodyHtml || '');
        var footer = $('#csModalFooter').empty();
        if (opts.extra) {
            $('<button type="button" class="btn btn-outline-danger me-auto">').text(opts.extra.text)
                .on('click', function () { m.hide(); opts.extra.onClick(); }).appendTo(footer);
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
    function promptDialog(title, text, label, value, onOk, extraText, onExtra) {
        var body = '<p class="text-body-secondary">' + esc(text) + '</p>' +
            '<label class="form-label cs-dv__label">' + esc(label) + '</label>' +
            '<input class="form-control" id="csPrompt" value="' + esc(value) + '">';
        var ok = dialog({
            title: title, bodyHtml: body, okText: 'Save',
            extra: extraText ? { text: extraText, onClick: onExtra } : null,
            onOk: function () { onOk($('#csPrompt').val()); }
        });
        $('#csModal').on('keydown', '#csPrompt', function (e) { if (e.key === 'Enter') { e.preventDefault(); ok.trigger('click'); } });
    }

    // =====================================================================
    // Tabs (Bootstrap) — lazy-render collections when shown
    // =====================================================================
    $('#csTabCollections').on('shown.bs.tab', renderCollections);
    function gotoLibraryTab() { bootstrap.Tab.getOrCreateInstance(document.getElementById('csTabLibrary')).show(); }

    // =====================================================================
    // Library
    // =====================================================================
    function hasFilters() { var f = state.filters; return !!(f.search || f.type || f.collection || f.usage); }

    function loadLibrary() {
        var seq = ++state.loadSeq;
        $('#csLibLoading').prop('hidden', false);
        $('#csLibError, #csLibEmpty, #csGrid').prop('hidden', true);
        apiGet('media_list', state.filters)
            .done(function (o) {
                if (seq !== state.loadSeq) return;
                $('#csLibLoading').prop('hidden', true);
                if (!o || !o.success) { $('#csLibError').prop('hidden', false); return; }
                state.assets = o.assets || [];
                renderGrid();
            })
            .fail(function () {
                if (seq !== state.loadSeq) return;
                $('#csLibLoading').prop('hidden', true);
                $('#csLibError').prop('hidden', false);
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
        // image error → refresh signed URL once
        $grid.find('.cs-tile__img').on('error', function () {
            var img = this;
            if (img.dataset.retried) return;
            img.dataset.retried = '1';
            apiGet('media_sign', { id: $(img).closest('.cs-tile').data('id'), variant: 'thumb' }).done(function (o) { if (o && o.success && o.url) img.src = o.url; });
        });
    }

    function buildTile(a, i) {
        var $t = $('<div class="cs-tile" tabindex="0">').attr('data-id', a.id).attr('data-type', a.type)
            .css('animation-delay', Math.min(i * 18, 360) + 'ms');
        if (state.selection.has(a.id)) $t.addClass('is-selected');

        if (a.status === 'ready' && a.thumb_url) {
            $('<img class="cs-tile__img" loading="lazy">').attr('alt', a.name || '').attr('src', a.thumb_url).appendTo($t);
        } else {
            $('<div class="cs-tile__ph"><i class="fa-solid ' + typeIcon(a.type) + '"></i></div>').appendTo($t);
        }
        $('<span class="cs-tile__type"><i class="fa-solid ' + typeIcon(a.type) + '"></i></span>').appendTo($t);
        if (a.type === 'video' && a.duration) $('<span class="cs-tile__badge"><i class="fa-solid fa-play"></i> ' + fmtDuration(a.duration) + '</span>').appendTo($t);
        if (a.usage_count > 0) $('<span class="cs-tile__use">In ' + a.usage_count + '</span>').appendTo($t);
        if (a.status === 'processing' || a.status === 'uploading') $('<div class="cs-tile__state"><span class="spinner-border spinner-border-sm"></span> Processing…</div>').appendTo($t);
        else if (a.status === 'failed') $('<div class="cs-tile__state cs-tile__state--failed"><i class="fa-solid fa-circle-exclamation"></i> Upload failed</div>').appendTo($t);
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
                    apiPost('media_bulk', { bulk_action: 'delete', ids: ids }).done(function (o) {
                        if (o.success) { toastr.success(o.message); clearSelection(); loadLibrary(); } else err(o);
                    });
                });
        } else if (action === 'collection_add') {
            chooseCollection(function (colId) {
                apiPost('media_bulk', { bulk_action: 'collection_add', ids: ids, collection_id: colId }).done(function (o) {
                    if (o.success) { toastr.success(o.message); clearSelection(); loadCollections(); } else err(o);
                });
            });
        }
    }

    // =====================================================================
    // Uploads (button + drag/drop) → tray with progress + retry
    // =====================================================================
    function pickFiles() {
        if (!S3_READY) { toastr.info("Media storage isn't set up yet, so uploads are off."); return; }
        $('#csFileInput').val('').trigger('click');
    }
    $('#csUploadBtn, #csEmptyUpload').on('click', pickFiles);
    $('#csFileInput').on('change', function () { handleFiles(this.files); });
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

    function handleFiles(list) {
        var files = Array.prototype.slice.call(list);
        if (!files.length) return;
        $('#csTray').prop('hidden', false);
        files.forEach(startUpload);
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
            setStatus: function (t, cls) { $row.find('.cs-up__status').removeClass('cs-up__status--done cs-up__status--failed').addClass(cls ? 'cs-up__status--' + cls : '').text(t); },
            setThumb: function (url) { if (url) $row.find('.cs-up__thumb').html('<img class="cs-up__thumb" src="' + esc(url) + '">'); },
            retry: function (fn) { $('<button type="button" class="cs-up__retry">Retry</button>').on('click', function () { $(this).remove(); fn(); }).appendTo($row.find('.cs-up__status')); }
        };
    }

    function startUpload(file) {
        var ui = trayItem(file);
        if (file.type.indexOf('video') === 0) uploadVideo(file, ui); else uploadImage(file, ui);
    }

    function uploadImage(file, ui) {
        var fd = new FormData(); fd.append('file', file);
        ui.setStatus('Uploading…');
        apiForm('media_upload', fd, function (e) { if (e.lengthComputable) ui.setProgress(e.loaded / e.total * 92); })
            .done(function (o) {
                if (o && o.success) { ui.setProgress(100); ui.setStatus('Done', 'done'); if (o.asset) ui.setThumb(o.asset.thumb_url); injectAsset(o.asset); }
                else { ui.setStatus((o && o.message) || 'Upload failed', 'failed'); ui.retry(function () { uploadImage(file, ui); }); }
            })
            .fail(function () { ui.setStatus('Upload failed — check your connection', 'failed'); ui.retry(function () { uploadImage(file, ui); }); });
    }

    function uploadVideo(file, ui) {
        var token = file.name + '|' + file.size + '|' + file.lastModified;
        ui.setStatus('Preparing…');
        apiPost('media_upload_init', { filename: file.name, mime: file.type, bytes_total: file.size, client_token: token })
            .done(function (init) {
                if (!init || !init.success) { ui.setStatus((init && init.message) || 'Could not start upload', 'failed'); ui.retry(function () { uploadVideo(file, ui); }); return; }
                var partSize = init.part_size || PART, done = {};
                (init.uploaded_parts || []).forEach(function (p) { done[p] = true; });
                var total = Math.ceil(file.size / partSize);
                if (init.resumed) ui.setStatus('Resuming…');

                function sendPart(p) {
                    if (p > total) { finishVideo(init.session_id, file, ui); return; }
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
                                else { ui.setStatus((o && o.message) || 'A chunk failed', 'failed'); ui.retry(function () { uploadVideo(file, ui); }); }
                            })
                            .fail(function () {
                                if (attempts < 4) setTimeout(attempt, 1000 * attempts);
                                else { ui.setStatus('Connection dropped — you can resume', 'failed'); ui.retry(function () { uploadVideo(file, ui); }); }
                            });
                    })();
                }
                sendPart(1);
            })
            .fail(function () { ui.setStatus('Could not start upload', 'failed'); ui.retry(function () { uploadVideo(file, ui); }); });
    }

    function finishVideo(sessionId, file, ui) {
        ui.setStatus('Finishing…'); ui.setProgress(98);
        apiPost('media_upload_complete', { session_id: sessionId })
            .done(function (o) {
                if (o && o.success) { ui.setProgress(100); ui.setStatus('Done', 'done'); if (o.asset) ui.setThumb(o.asset.thumb_url); injectAsset(o.asset); }
                else { ui.setStatus((o && o.message) || 'Could not finish', 'failed'); ui.retry(function () { uploadVideo(file, ui); }); }
            })
            .fail(function () { ui.setStatus('Could not finish — you can resume', 'failed'); ui.retry(function () { uploadVideo(file, ui); }); });
    }

    function injectAsset(asset) {
        if (!asset) return;
        if (hasFilters()) return;
        var i = state.assets.findIndex(function (a) { return a.id === asset.id; });
        if (i >= 0) state.assets[i] = asset; else state.assets.unshift(asset);
        renderGrid();
    }

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
        apiGet('collections_list').done(function (o) {
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
                    state.filters.collection = String(c.id);
                    $('#csFilterCollection').val(String(c.id));
                    gotoLibraryTab();
                    loadLibrary();
                });
                $wrap.append($row);
            });
        });
    }

    function collectionMenu(c) {
        promptDialog('Rename collection', 'Give this collection a clearer name, or delete it below.', 'Collection name', c.name,
            function (name) {
                if (name.trim() && name !== c.name) apiPost('collection_save', { id: c.id, name: name }).done(function (o) { if (o.success) { toastr.success('Collection renamed'); renderCollections(); } else err(o); });
            },
            'Delete', function () {
                confirmDialog('Delete “' + c.name + '”?', 'The collection is removed. Your files stay in your library.', 'Delete', true, function () {
                    apiPost('collection_delete', { id: c.id }).done(function (o) { if (o.success) { toastr.success('Collection deleted'); renderCollections(); } else err(o); });
                });
            });
    }

    $('#csNewCollectionBtn, #csCreateCollectionBtn').on('click', function () {
        promptDialog('New collection', 'Name a folder to group related files.', 'Collection name', '', function (name) {
            if (!name.trim()) return;
            apiPost('collection_save', { name: name }).done(function (o) { if (o.success) { toastr.success('Collection created'); renderCollections(); } else err(o); });
        });
    });

    function chooseCollection(onPick) {
        if (!state.collections.length) {
            promptDialog('Add to a new collection', 'You have no collections yet. Name one to create it.', 'Collection name', '', function (name) {
                if (!name.trim()) return;
                apiPost('collection_save', { name: name }).done(function (o) { if (o.success) { loadCollections(); onPick(o.id); } else err(o); });
            });
            return;
        }
        var opts = state.collections.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('');
        dialog({ title: 'Add to collection', okText: 'Add', bodyHtml: '<label class="form-label cs-dv__label">Collection</label><select id="csColPick" class="form-select">' + opts + '</select>', onOk: function () { onPick($('#csColPick').val()); } });
    }

    // =====================================================================
    // Detail (Bootstrap offcanvas)
    // =====================================================================
    function openDetail(id) {
        $('#csDetailBody').html('<div class="cs-loading"><span class="spinner-border spinner-border-sm text-primary"></span> Loading…</div>');
        detailOC.show();
        apiGet('media_get', { id: id })
            .done(function (o) { if (o && o.success) renderDetail(o.asset); else $('#csDetailBody').html('<div class="cs-error"><i class="fa-solid fa-circle-exclamation"></i><p>' + esc((o && o.message) || 'Could not load this file.') + '</p></div>'); })
            .fail(function () { $('#csDetailBody').html('<div class="cs-error"><i class="fa-solid fa-circle-exclamation"></i><p>Could not load this file. Please try again.</p></div>'); });
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
            return '<button type="button" class="cs-chip' + (on ? ' is-on' : '') + '" data-col="' + c.id + '">' + (on ? '<i class="fa-solid fa-check"></i> ' : '') + esc(c.name) + '</button>';
        }).join('') || '<span class="cs-col__count">No collections yet.</span>';
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
            '<label class="form-label cs-dv__label">Collections</label><div class="cs-dv__cols">' + cols + '</div>' +
            '<label class="form-label cs-dv__label">Used in</label>' + posts +
            '<button type="button" class="btn btn-outline-danger w-100 mt-3" id="csDvDelete"><i class="fa-solid fa-trash"></i> Remove file</button>'
        );

        $('#csDvSave').on('click', function () {
            apiPost('media_update', { id: a.id, description: $('#csDvDesc').val() }).done(function (o) { if (o.success) { toastr.success('Description saved'); mergeAsset(o.asset); } else err(o); });
        });
        $('#csWm').on('change', function () {
            var $w = $(this).prop('disabled', true), on = this.checked;
            var $pv = $('#csDetailBody .cs-dv__preview').addClass('is-busy').toggleClass('wm-on', on).toggleClass('wm-off', !on);
            apiPost('media_watermark', { id: a.id, enabled: on ? '1' : '0' })
                .done(function (o) {
                    $w.prop('disabled', false);
                    if (!o.success) { $pv.removeClass('is-busy wm-on wm-off'); $w.prop('checked', !on); err(o); return; }
                    toastr.success(on ? 'Watermark on' : 'Watermark off');
                    a.watermark_applied = on ? 1 : 0;
                    mergeAsset(o.asset);
                    // The server overwrote the renditions in place, so re-sign and swap
                    // the preview + grid thumb to force a fresh (cache-busted) load.
                    apiGet('media_sign', { id: a.id, variant: 'display' }).done(function (s) {
                        $pv.removeClass('is-busy wm-on wm-off');
                        if (s.success && s.url) { $pv.find('img').attr('src', s.url); a.preview_url = s.url; }
                    }).fail(function () { $pv.removeClass('is-busy wm-on wm-off'); });
                })
                .fail(function () { $pv.removeClass('is-busy wm-on wm-off'); $w.prop('disabled', false).prop('checked', !on); toastr.error('Could not update the watermark. Please try again.'); });
        });
        $('#csDetailBody').find('[data-col]').on('click', function () {
            var $chip = $(this), colId = $chip.data('col'), on = $chip.hasClass('is-on');
            apiPost(on ? 'collection_remove_assets' : 'collection_add_assets', { id: colId, ids: [a.id] }).done(function (o) {
                if (o.success) {
                    $chip.toggleClass('is-on');
                    var nm = state.collections.filter(function (c) { return String(c.id) === String(colId); })[0].name;
                    $chip.html((!on ? '<i class="fa-solid fa-check"></i> ' : '') + esc(nm));
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
                apiPost('media_delete', { id: a.id }).done(function (o) { if (o.success) { toastr.success('File removed'); detailOC.hide(); loadLibrary(); } else err(o); });
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
    $('#csNewPostBtn').on('click', function () { toastr.info('The post composer arrives in the next step.'); });

    if (typeof toastr !== 'undefined') {
        toastr.options = $.extend(toastr.options || {}, { positionClass: 'toast-bottom-right', timeOut: 3200, preventDuplicates: true });
    }

    loadCollections();
    loadLibrary();
});
