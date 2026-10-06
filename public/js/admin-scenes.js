/* /admin, Scenes tab: the scene template library creators pick from in Influencers, Generate Images, Scenes.
   Create and edit in one window (thumbnail included); row actions live in the row menu. */
(function () {
    "use strict";
    var modal_el = document.getElementById('admSceneModal');
    if (!modal_el) { return; }
    var $ = window.jQuery, modal = bootstrap.Modal.getOrCreateInstance(modal_el), thumb_file = null;

    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null) ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    function bad(msg) { toastr.error(msg || 'Something went wrong. Please try again.'); }
    function back_to_tab() { window.location = '/admin?tab=scenes'; }
    function confirm_box(opts) {
        if (!window.Swal) { return Promise.resolve(window.confirm(opts.title)); }
        return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' }, opts)).then(function (r) { return r.isConfirmed; });
    }
    function clear_errors() { $('#admSceneForm [data-err]').prop('hidden', true).text(''); $('#admSceneForm .is-invalid').removeClass('is-invalid'); }
    function set_thumb(url) {
        $('#admSceneThumbBtn').html(url ? '<img src="' + esc(url) + '" alt=""><span class="adm-scene__change">Change Image</span>' : '<i class="fa-regular fa-image" aria-hidden="true"></i><span>Choose Image</span>').toggleClass('has-img', !!url);
    }
    function busy(on, text) {
        $('#admSceneSave').prop('disabled', on).html(on ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Saving' : 'Save Scene');
        $('#admSceneState').text(on ? (text || '') : '');
    }
    function open(s) {
        clear_errors(); thumb_file = null; busy(false);
        $('#admSceneTitle').text(s ? 'Edit Scene' : 'New Scene');
        $('#admSceneId').val(s ? s.id : 0);
        $('#admSceneName').val(s ? s.title : '');
        $('#admSceneCat').val(s ? s.category : '');
        $('#admSceneAspect').val(s ? s.default_aspect : '3:4');
        $('#admSceneSort').val(s ? s.sort_order : 0);
        $('#admScenePrompt').val(s ? s.base_prompt : '');
        $('#admSceneActive').prop('checked', s ? !!s.is_active : true);
        $('#admSceneAdult').prop('checked', s ? !!s.is_adult : false);
        $('#admSceneThumb').val('');
        set_thumb(s ? s.thumb_url : '');
        modal.show();
    }
    function row_scene(el) { return parse($(el).closest('.adm-srow').attr('data-scene') || ''); }

    $('#admSceneNew').on('click', function () { open(null); });
    $('#admSceneThumbBtn').on('click', function () { $('#admSceneThumb').trigger('click'); });
    $('#admSceneThumb').on('change', function () {
        var f = this.files && this.files[0];
        if (!f) { return; }
        if (f.size > 15 * 1048576) { bad('That image is too large. Images can be up to 15 MB.'); this.value = ''; return; }
        thumb_file = f;
        set_thumb(URL.createObjectURL(f));
    });
    $('#admSceneForm').on('input change', '.form-control, .form-select', function () { $(this).removeClass('is-invalid').closest('.ai-field').find('[data-err]').prop('hidden', true); });

    function upload_thumb(id, done) {
        if (!thumb_file) { done(true); return; }
        var fd = new FormData(); fd.append('file', thumb_file); fd.append('id', id);
        busy(true, 'Uploading the thumbnail');
        $.ajax({ url: '/api/admin_scene_thumb', method: 'POST', data: fd, dataType: 'json', processData: false, contentType: false })
            .done(function (o) { if (o && o.success) { done(true); } else { bad((o && o.message) || 'The thumbnail could not be saved.'); done(false); } })
            .fail(function () { bad('The thumbnail could not be uploaded. Check your connection.'); done(false); });
    }
    $('#admSceneSave').on('click', function () {
        clear_errors(); busy(true);
        var body = { id: $('#admSceneId').val(), title: $('#admSceneName').val(), category: $('#admSceneCat').val(), default_aspect: $('#admSceneAspect').val(),
            sort_order: $('#admSceneSort').val(), base_prompt: $('#admScenePrompt').val(), is_active: $('#admSceneActive').prop('checked') ? 1 : 0, is_adult: $('#admSceneAdult').prop('checked') ? 1 : 0 };
        ApiDataSvc.apiCall('post', 'admin_scene_save', body, function (r) {
            var o = parse(r);
            if (!o || !o.success) {
                busy(false);
                var fields = { title: '#admSceneName', base_prompt: '#admScenePrompt', default_aspect: '#admSceneAspect' }, shown = false;
                ((o && o.errors) || []).forEach(function (er) {
                    if (!fields[er.input]) { return; }
                    $(fields[er.input]).addClass('is-invalid'); $('#admSceneForm [data-err="' + er.input + '"]').text(er.msg).prop('hidden', false);
                    if (!shown) { $(fields[er.input]).trigger('focus'); shown = true; }
                });
                if (!shown) { bad(o && o.message); }
                return;
            }
            $('#admSceneId').val(o.id);
            upload_thumb(o.id, function (ok) { if (ok) { back_to_tab(); } else { busy(false); thumb_file = null; } });
        });
    });

    $(document).on('click', '[data-scene-action]', function () {
        var s = row_scene(this), action = $(this).attr('data-scene-action');
        if (!s) { return; }
        if (action === 'edit') { open(s); return; }
        if (action === 'toggle') {
            ApiDataSvc.apiCall('post', 'admin_scene_set_active', { id: s.id, active: s.is_active ? 0 : 1 }, function (r) { var o = parse(r); if (o && o.success) { back_to_tab(); } else { bad(o && o.message); } });
            return;
        }
        if (action === 'delete') {
            confirm_box({ title: 'Delete this scene?', text: s.title + ' will no longer be offered to creators. Images already made from it stay in their libraries.', confirmButtonText: 'Delete' }).then(function (yes) {
                if (!yes) { return; }
                ApiDataSvc.apiCall('post', 'admin_scene_delete', { id: s.id }, function (r) { var o = parse(r); if (o && o.success) { back_to_tab(); } else { bad(o && o.message); } });
            });
        }
    });
})();
