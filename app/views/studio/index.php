<link rel="stylesheet" href="/css/studio.css">
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>

<?php
    // plan_id => name, for rendering the access badge on subscriber items.
    $plan_names = array();
    foreach ($this->creator_plans as $p) { $plan_names[(int) $p['id']] = $p['name']; }
?>

<div class="studio">
    <div class="studio__head">
        <div>
            <h1 class="studio__title">Content Studio</h1>
            <p class="studio__sub">Publish posts and gate them by access. Drag to reorder.</p>
        </div>
        <button type="button" class="btn btn-primary" id="content_new"><i class="fa-solid fa-plus"></i> New content</button>
    </div>

    <div class="content-list" id="content_list">
        <?php foreach ($this->content_items as $it): ?>
        <?php
            $access = $it['access'];
            if ($access === 'paid')             { $badge = number_format((int) $it['price_credits']) . ' credits'; $badge_cls = 'is-paid'; }
            elseif ($access === 'subscribers')  { $badge = $plan_names[(int) $it['required_plan_id']] ?? 'Subscribers'; $badge_cls = 'is-sub'; }
            else                                { $badge = 'Public'; $badge_cls = 'is-public'; }
            $published = ($it['status'] === 'published');
            $scheduled = ($it['status'] === 'scheduled');
            $sched_txt = $scheduled && !empty($it['scheduled_at']) ? date('M j, g:i A', strtotime((string) $it['scheduled_at'])) : '';
            $status_txt = $published ? 'Published' : ($scheduled ? 'Scheduled · ' . $sched_txt : 'Draft');
            $pinned = !empty($it['pinned']);
        ?>
        <div class="content-row<?php echo $published ? '' : ' is-draft'; ?>" data-id="<?php echo (int) $it['id']; ?>"
             data-title="<?php echo htmlspecialchars((string) $it['title'], ENT_QUOTES, 'UTF-8'); ?>"
             data-description="<?php echo htmlspecialchars((string) $it['description'], ENT_QUOTES, 'UTF-8'); ?>"
             data-body="<?php echo htmlspecialchars((string) $it['body'], ENT_QUOTES, 'UTF-8'); ?>"
             data-tags="<?php echo htmlspecialchars((string) $it['tags'], ENT_QUOTES, 'UTF-8'); ?>"
             data-access="<?php echo htmlspecialchars((string) $access, ENT_QUOTES, 'UTF-8'); ?>"
             data-required-plan="<?php echo (int) $it['required_plan_id']; ?>"
             data-price="<?php echo (int) $it['price_credits']; ?>"
             data-comments="<?php echo !empty($it['comments_enabled']) ? 1 : 0; ?>"
             data-status="<?php echo htmlspecialchars((string) $it['status'], ENT_QUOTES, 'UTF-8'); ?>"
             data-scheduled="<?php echo $scheduled && !empty($it['scheduled_at']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string) $it['scheduled_at'])), ENT_QUOTES, 'UTF-8') : ''; ?>"
             data-preview="<?php echo htmlspecialchars((string) $it['preview_url'], ENT_QUOTES, 'UTF-8'); ?>">
            <span class="content-row__handle"><i class="fa-solid fa-grip-vertical"></i></span>
            <button type="button" class="content-row__pin<?php echo $pinned ? ' is-pinned' : ''; ?>" title="Pin to top" aria-label="Pin"><i class="fa-solid fa-thumbtack"></i></button>
            <span class="content-row__thumb"<?php echo !empty($it['preview_url']) ? ' style="background-image:url(\'' . htmlspecialchars($it['preview_url'], ENT_QUOTES, 'UTF-8') . '\')"' : ''; ?>><?php echo empty($it['preview_url']) ? '<i class="fa-regular fa-image"></i>' : ''; ?></span>
            <div class="content-row__info">
                <span class="content-row__title"><?php echo htmlspecialchars((string) $it['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="content-badge <?php echo $badge_cls; ?>"><?php echo htmlspecialchars($badge, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <span class="content-status<?php echo $scheduled ? ' is-scheduled' : ''; ?>"><?php echo htmlspecialchars($status_txt, ENT_QUOTES, 'UTF-8'); ?></span>
            <label class="content-row__switch" title="Published">
                <input type="checkbox" class="content-publish" <?php echo $published ? 'checked' : ''; ?>>
                <span class="content-row__slider"></span>
            </label>
            <button type="button" class="content-row__btn content-edit" aria-label="Edit"><i class="fa-solid fa-pen"></i></button>
            <button type="button" class="content-row__btn content-delete" aria-label="Delete"><i class="fa-solid fa-trash"></i></button>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="studio__empty" id="content_empty" <?php echo empty($this->content_items) ? '' : 'hidden'; ?>>No content yet. Create your first post.</p>
</div>

<div class="modal fade" id="content_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="content_modal_title">New content</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="content_id" value="0">
                <div class="std-field">
                    <label for="content_title">Title</label>
                    <input type="text" class="form-control" id="content_title" maxlength="200" placeholder="Post title">
                </div>
                <div class="std-field">
                    <label for="content_description">Description <span class="std-optional">(optional — short summary shown in listings)</span></label>
                    <input type="text" class="form-control" id="content_description" maxlength="500" placeholder="One-line summary">
                </div>
                <div class="std-field">
                    <label for="content_body">Text <span class="std-optional">(optional)</span></label>
                    <textarea class="form-control" id="content_body" rows="4" placeholder="Write something..."></textarea>
                </div>
                <div class="std-field">
                    <label for="content_tags">Tags <span class="std-optional">(optional — comma separated)</span></label>
                    <input type="text" class="form-control" id="content_tags" maxlength="255" placeholder="behind the scenes, tutorial">
                </div>
                <div class="std-field-row">
                    <div class="std-field">
                        <label for="content_access">Access</label>
                        <select class="form-control" id="content_access">
                            <option value="public">Public — any signed-in fan</option>
                            <option value="subscribers">Subscribers — by tier</option>
                            <option value="paid">Paid — unlock with credits</option>
                        </select>
                    </div>
                    <div class="std-field" id="content_plan_wrap" hidden>
                        <label for="content_required_plan">Minimum tier</label>
                        <select class="form-control" id="content_required_plan">
                            <?php foreach ($this->creator_plans as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>"><?php echo htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8'); ?> ($<?php echo number_format($p['price_cents'] / 100, 2); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="std-field" id="content_price_wrap" hidden>
                        <label for="content_price">Price (credits)</label>
                        <input type="number" class="form-control" id="content_price" min="1" step="1" placeholder="25">
                    </div>
                </div>

                <div class="std-media" id="content_media" hidden>
                    <div class="std-field" id="content_preview_wrap" hidden>
                        <label>Locked preview <span class="std-optional">(shown to non-members)</span></label>
                        <div class="std-preview">
                            <span class="std-preview__thumb" id="content_preview_thumb"></span>
                            <button type="button" class="btn btn-secondary" id="content_preview_btn"><i class="fa-solid fa-camera"></i> Upload preview</button>
                            <input type="file" id="content_preview_file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                        </div>
                        <p class="std-help">Fans who can't access this post see only this image. Required so the original is never exposed.</p>
                    </div>
                    <div class="std-field">
                        <label>Media <span class="std-optional">(images, video, audio, PDF)</span></label>
                        <div class="std-assets" id="content_assets"></div>
                        <button type="button" class="btn btn-secondary" id="content_media_btn"><i class="fa-solid fa-plus"></i> Add media</button>
                        <input type="file" id="content_media_file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime,audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/ogg,application/pdf" hidden>
                    </div>
                </div>
                <p class="std-help" id="content_savefirst" hidden>Save the post first, then add media.</p>

                <label class="std-check"><input type="checkbox" id="content_comments" checked> Allow comments</label>

                <div class="std-field std-publish">
                    <label for="content_publish">Visibility</label>
                    <select class="form-control" id="content_publish">
                        <option value="draft">Draft — only you</option>
                        <option value="publish">Publish now</option>
                        <option value="schedule">Schedule for later</option>
                    </select>
                    <input type="datetime-local" class="form-control" id="content_scheduled_at" hidden>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Done</button>
                <button type="button" class="btn btn-primary" id="content_save">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {

    function escapeHtml(s) { return $('<div>').text(s == null ? '' : s).html(); }

    function accessBadge(access, planId, price) {
        if (access === 'paid') { return { text: (parseInt(price, 10) || 0).toLocaleString() + ' credits', cls: 'is-paid' }; }
        if (access === 'subscribers') { return { text: ($('#content_required_plan option[value="' + planId + '"]').text() || 'Subscribers').replace(/\s*\(\$.*\)$/, ''), cls: 'is-sub' }; }
        return { text: 'Public', cls: 'is-public' };
    }

    function fmtSched(v) {
        var d = new Date(v);
        return isNaN(d) ? '' : d.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }
    function statusLabel(status, scheduled) {
        if (status === 'published') { return { txt: 'Published', cls: '' }; }
        if (status === 'scheduled') { return { txt: 'Scheduled · ' + fmtSched(scheduled), cls: ' is-scheduled' }; }
        return { txt: 'Draft', cls: '' };
    }

    function renderRow(d) {
        var b = accessBadge(d.access, d.required_plan, d.price);
        var st = statusLabel(d.status || 'draft', d.scheduled || '');
        var thumb = d.preview
            ? '<span class="content-row__thumb" style="background-image:url(\'' + escapeHtml(d.preview) + '\')"></span>'
            : '<span class="content-row__thumb"><i class="fa-regular fa-image"></i></span>';
        return '<div class="content-row' + (d.status === 'published' ? '' : ' is-draft') + '" data-id="' + d.id + '"'
            + ' data-title="' + escapeHtml(d.title) + '" data-description="' + escapeHtml(d.description || '') + '"'
            + ' data-body="' + escapeHtml(d.body || '') + '" data-tags="' + escapeHtml(d.tags || '') + '" data-access="' + escapeHtml(d.access) + '"'
            + ' data-required-plan="' + (d.required_plan || 0) + '" data-price="' + (d.price || 0) + '"'
            + ' data-comments="' + (d.comments ? 1 : 0) + '" data-status="' + escapeHtml(d.status || 'draft') + '" data-scheduled="' + escapeHtml(d.scheduled || '') + '"'
            + ' data-preview="' + escapeHtml(d.preview || '') + '">'
            + '<span class="content-row__handle"><i class="fa-solid fa-grip-vertical"></i></span>'
            + '<button type="button" class="content-row__pin' + (d.pinned ? ' is-pinned' : '') + '" title="Pin to top" aria-label="Pin"><i class="fa-solid fa-thumbtack"></i></button>'
            + thumb
            + '<div class="content-row__info"><span class="content-row__title">' + escapeHtml(d.title) + '</span>'
            + '<span class="content-badge ' + b.cls + '">' + escapeHtml(b.text) + '</span></div>'
            + '<span class="content-status' + st.cls + '">' + escapeHtml(st.txt) + '</span>'
            + '<label class="content-row__switch" title="Published"><input type="checkbox" class="content-publish"' + (d.status === 'published' ? ' checked' : '') + '><span class="content-row__slider"></span></label>'
            + '<button type="button" class="content-row__btn content-edit" aria-label="Edit"><i class="fa-solid fa-pen"></i></button>'
            + '<button type="button" class="content-row__btn content-delete" aria-label="Delete"><i class="fa-solid fa-trash"></i></button>'
            + '</div>';
    }

    // Access-driven field visibility.
    function applyAccessFields() {
        var a = $('#content_access').val();
        $('#content_plan_wrap').prop('hidden', a !== 'subscribers');
        $('#content_price_wrap').prop('hidden', a !== 'paid');
        $('#content_preview_wrap').prop('hidden', a === 'public');
    }
    $('#content_access').on('change', applyAccessFields);

    function applyPublishFields() {
        $('#content_scheduled_at').prop('hidden', $('#content_publish').val() !== 'schedule');
    }
    $('#content_publish').on('change', applyPublishFields);

    function mediaEnabled(on) {
        $('#content_media').prop('hidden', !on);
        $('#content_savefirst').prop('hidden', on);
    }

    $('#content_new').on('click', function () {
        $('#content_id').val(0);
        $('#content_title').val(''); $('#content_description').val(''); $('#content_body').val(''); $('#content_tags').val('');
        $('#content_access').val('public'); $('#content_required_plan').prop('selectedIndex', 0);
        $('#content_price').val(''); $('#content_preview_thumb').css('background-image', '');
        $('#content_comments').prop('checked', true);
        $('#content_publish').val('draft'); $('#content_scheduled_at').val('');
        $('#content_assets').empty();
        applyAccessFields(); applyPublishFields(); mediaEnabled(false);
        $('#content_modal_title').text('New content');
        $('#content_modal').modal('show');
    });

    $('#content_list').on('click', '.content-edit', function () {
        var $row = $(this).closest('.content-row');
        var id = $row.data('id');
        $('#content_id').val(id);
        $('#content_title').val($row.data('title'));
        $('#content_description').val($row.data('description') || '');
        $('#content_body').val($row.data('body') || '');
        $('#content_tags').val($row.data('tags') || '');
        $('#content_access').val($row.data('access'));
        if ($row.data('required-plan')) { $('#content_required_plan').val($row.data('required-plan')); }
        $('#content_price').val($row.data('price') || '');
        $('#content_comments').prop('checked', $row.data('comments') == 1);
        var status = $row.data('status') || 'draft';
        $('#content_publish').val(status === 'published' ? 'publish' : (status === 'scheduled' ? 'schedule' : 'draft'));
        $('#content_scheduled_at').val($row.data('scheduled') || '');
        var prev = $row.data('preview') || '';
        $('#content_preview_thumb').css('background-image', prev ? "url('" + prev + "')" : '');
        applyAccessFields(); applyPublishFields(); mediaEnabled(true);
        $('#content_assets').empty();
        $('#content_modal_title').text('Edit content');
        loadAssets(id);
        $('#content_modal').modal('show');
    });

    function loadAssets(id) {
        ApiDataSvc.apiCall('post', 'get_content_assets', { content_id: id }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { return; }
            $('#content_assets').empty();
            o.assets.forEach(function (a) { addAsset(a.id, a.url, a.type); });
        });
    }

    function addAsset(assetId, url, type) {
        var del = '<button type="button" class="std-asset__del" aria-label="Remove"><i class="fa-solid fa-xmark"></i></button>';
        var html;
        if (type === 'image') {
            html = '<span class="std-asset" data-asset-id="' + assetId + '" style="background-image:url(\'' + escapeHtml(url) + '\')">' + del + '</span>';
        } else {
            var icon = type === 'video' ? 'fa-film' : (type === 'audio' ? 'fa-music' : 'fa-file-pdf');
            html = '<span class="std-asset std-asset--file" data-asset-id="' + assetId + '"><i class="fa-solid ' + icon + '"></i>' + del + '</span>';
        }
        $('#content_assets').append(html);
    }

    $('#content_assets').on('click', '.std-asset__del', function () {
        var $a = $(this).closest('.std-asset');
        ApiDataSvc.apiCall('post', 'delete_content_asset', { id: $a.data('asset-id') }, function (data) {
            var o = JSON.parse(data);
            if (o.success) { $a.remove(); } else { toastr.error(o.message); }
        });
    });

    $('#content_save').on('click', function () {
        var id = parseInt($('#content_id').val(), 10) || 0;
        var wasNew = (id === 0);
        var title = ($('#content_title').val() || '').trim();
        var access = $('#content_access').val();
        var publish = $('#content_publish').val();
        var scheduledAt = $('#content_scheduled_at').val();
        if (title === '') { toastr.error('Enter a title'); return; }
        if (access === 'paid' && !(parseInt($('#content_price').val(), 10) >= 1)) { toastr.error('Set a credit price of at least 1'); return; }
        if (publish === 'schedule' && !scheduledAt) { toastr.error('Pick a schedule date and time'); return; }

        var payload = {
            id: id, title: title, description: $('#content_description').val() || '',
            body: $('#content_body').val() || '', tags: $('#content_tags').val() || '', access: access,
            required_plan_id: access === 'subscribers' ? $('#content_required_plan').val() : 0,
            price: access === 'paid' ? $('#content_price').val() : 0,
            comments_enabled: $('#content_comments').is(':checked') ? 1 : 0,
            publish: publish, scheduled_at: scheduledAt
        };
        ApiDataSvc.apiCall('post', 'save_content', payload, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            $('#content_id').val(o.id);

            var $existing = $('.content-row[data-id="' + o.id + '"]');
            var d = {
                id: o.id, title: title, description: payload.description, body: payload.body, tags: payload.tags,
                access: access, required_plan: payload.required_plan_id, price: payload.price,
                comments: payload.comments_enabled, status: o.status,
                scheduled: (o.status === 'scheduled' ? scheduledAt : ''),
                preview: ($existing.data('preview') || ''),
                pinned: ($existing.find('.content-row__pin').hasClass('is-pinned'))
            };
            if ($existing.length) { $existing.replaceWith(renderRow(d)); }
            else { $('#content_list').append(renderRow(d)); $('#content_empty').attr('hidden', true); }

            if (wasNew) { mediaEnabled(true); $('#content_modal_title').text('Edit content'); toastr.info('Now add media below'); }
        });
    });

    // Uploads (need a saved content id).
    function requireId() {
        var id = parseInt($('#content_id').val(), 10) || 0;
        if (id === 0) { toastr.error('Save the post first'); return 0; }
        return id;
    }

    $('#content_media_btn').on('click', function () { if (requireId()) { $('#content_media_file').click(); } });
    $('#content_media_file').on('change', function () {
        var id = requireId(); if (!id || !this.files.length) { return; }
        uploadAsset(id, 'media', this.files[0], this);
    });

    $('#content_preview_btn').on('click', function () { if (requireId()) { $('#content_preview_file').click(); } });
    $('#content_preview_file').on('change', function () {
        var id = requireId(); if (!id || !this.files.length) { return; }
        uploadAsset(id, 'preview', this.files[0], this);
    });

    function uploadAsset(id, kind, file, input) {
        var fd = new FormData();
        fd.append('content_id', id);
        fd.append('kind', kind);
        fd.append('file', file);
        var $btn = (kind === 'preview' ? $('#content_preview_btn') : $('#content_media_btn')).prop('disabled', true);
        $.ajax({
            url: ApiDataSvc.baseUrl + 'upload_content_asset', type: 'POST', data: fd,
            processData: false, contentType: false,
            success: function (data) {
                var o = JSON.parse(data);
                if (!o.success) { toastr.error(o.message); return; }
                if (kind === 'preview') {
                    $('#content_preview_thumb').css('background-image', "url('" + o.url + "')");
                    $('.content-row[data-id="' + id + '"]').attr('data-preview', o.url)
                        .find('.content-row__thumb').css('background-image', "url('" + o.url + "')").html('');
                } else {
                    addAsset(o.asset_id, o.url, o.type);
                }
                toastr.success('Uploaded');
            },
            error: function () { toastr.error('Upload failed'); },
            complete: function () { $btn.prop('disabled', false); if (input) { input.value = ''; } }
        });
    }

    $('#content_list').on('change', '.content-publish', function () {
        var $row = $(this).closest('.content-row');
        var pub = $(this).is(':checked');
        $row.toggleClass('is-draft', !pub).attr('data-status', pub ? 'published' : 'draft');
        $row.find('.content-status').removeClass('is-scheduled').text(pub ? 'Published' : 'Draft');
        ApiDataSvc.apiCall('post', 'toggle_publish_content', { id: $row.data('id'), published: pub ? 1 : 0 }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); }
        });
    });

    $('#content_list').on('click', '.content-row__pin', function () {
        var $row = $(this).closest('.content-row');
        var $pin = $(this);
        var pinned = !$pin.hasClass('is-pinned');
        $pin.toggleClass('is-pinned', pinned);
        ApiDataSvc.apiCall('post', 'pin_content', { id: $row.data('id'), pinned: pinned ? 1 : 0 }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); $pin.toggleClass('is-pinned', !pinned); }
        });
    });

    $('#content_list').on('click', '.content-delete', function () {
        var $row = $(this).closest('.content-row');
        ApiDataSvc.apiCall('post', 'delete_content', { id: $row.data('id') }, function (data) {
            var o = JSON.parse(data);
            if (!o.success) { toastr.error(o.message); return; }
            toastr.success(o.message);
            $row.remove();
            if ($('#content_list .content-row').length === 0) { $('#content_empty').removeAttr('hidden'); }
        });
    });

    if (window.Sortable && document.getElementById('content_list')) {
        Sortable.create(document.getElementById('content_list'), {
            handle: '.content-row__handle', animation: 150,
            onEnd: function () {
                var ids = $('#content_list .content-row').map(function () { return $(this).data('id'); }).get();
                ApiDataSvc.apiCall('post', 'reorder_content', { ids: ids }, function () {});
            }
        });
    }

    // Reorder media within a post.
    if (window.Sortable && document.getElementById('content_assets')) {
        Sortable.create(document.getElementById('content_assets'), {
            animation: 150,
            onEnd: function () {
                var ids = $('#content_assets .std-asset').map(function () { return $(this).data('asset-id'); }).get();
                if (ids.length) { ApiDataSvc.apiCall('post', 'reorder_content_assets', { ids: ids }, function () {}); }
            }
        });
    }
});
</script>
