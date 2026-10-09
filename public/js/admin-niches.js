/* /admin, Niches tab: the Creator Directory categories (/creators/<slug>) and the Settings choices.
   Add and rename in one window; rename, move and on/off live in the row menu. */
(function () {
    "use strict";
    var modal_el = document.getElementById('admNicheModal');
    if (!modal_el) { return; }
    var $ = window.jQuery, modal = bootstrap.Modal.getOrCreateInstance(modal_el);

    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function bad(msg) { toastr.error(msg || 'Something went wrong. Please try again.'); }
    function back_to_tab() { window.location = '/admin/niches'; }
    function row_niche(el) { return parse($(el).closest('.adm-nrow').attr('data-niche') || ''); }
    function clear_errors() { $('#admNicheForm [data-err]').prop('hidden', true).text(''); $('#admNicheForm .is-invalid').removeClass('is-invalid'); }
    function busy(on) { $('#admNicheSave').prop('disabled', on).html(on ? '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Saving' : 'Save Niche'); }
    function slugify(s) { return String(s || '').toLowerCase().replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40); }
    function confirm_box(opts) {
        if (!window.Swal) { return Promise.resolve(window.confirm(opts.title)); }
        return Swal.fire(Object.assign({ showCancelButton: true, reverseButtons: true, confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' }, opts)).then(function (r) { return r.isConfirmed; });
    }
    function open(n) {
        clear_errors(); busy(false);
        $('#admNicheTitle').text(n ? 'Rename Niche' : 'New Niche');
        $('#admNicheId').val(n ? n.id : 0);
        $('#admNicheName').val(n ? n.name : '');
        $('#admNicheSlug').val(n ? n.slug : '').data('touched', false);
        $('#admNicheSlugField').prop('hidden', !!n);
        modal.show();
    }
    modal_el.addEventListener('shown.bs.modal', function () { $('#admNicheName').trigger('focus'); });

    $('#admNicheNew').on('click', function () { open(null); });
    $('#admNicheName').on('input', function () {
        if ($('#admNicheId').val() === '0' && !$('#admNicheSlug').data('touched')) { $('#admNicheSlug').val(slugify($(this).val())); }
    });
    $('#admNicheSlug').on('input', function () { $(this).data('touched', true); });
    $('#admNicheForm').on('input', '.form-control', function () { $(this).removeClass('is-invalid').closest('.ai-field').find('[data-err]').prop('hidden', true); });
    $('#admNicheForm').on('keydown', '.form-control', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); $('#admNicheSave').trigger('click'); } });

    $('#admNicheSave').on('click', function () {
        clear_errors(); busy(true);
        var body = { id: $('#admNicheId').val(), name: $('#admNicheName').val(), slug: $('#admNicheSlug').val() };
        ApiDataSvc.apiCall('post', 'admin_niche_save', body, function (r) {
            var o = parse(r);
            if (o && o.success) { back_to_tab(); return; }
            busy(false);
            var fields = { name: '#admNicheName', slug: '#admNicheSlug' }, shown = false;
            ((o && o.errors) || []).forEach(function (er) {
                if (!fields[er.input]) { return; }
                $(fields[er.input]).addClass('is-invalid'); $('#admNicheForm [data-err="' + er.input + '"]').text(er.msg).prop('hidden', false);
                if (!shown) { $(fields[er.input]).trigger('focus'); shown = true; }
            });
            if (!shown) { bad(o && o.message); }
        });
    });

    $(document).on('click', '[data-niche-action]', function () {
        var n = row_niche(this), action = $(this).attr('data-niche-action');
        if (!n) { return; }
        if (action === 'rename') { open(n); return; }
        if (action === 'up' || action === 'down') {
            ApiDataSvc.apiCall('post', 'admin_niche_move', { id: n.id, dir: action }, function (r) { var o = parse(r); if (o && o.success) { back_to_tab(); } else { bad(o && o.message); } });
            return;
        }
        if (action === 'toggle') {
            var go = function () {
                ApiDataSvc.apiCall('post', 'admin_niche_set_active', { id: n.id, active: n.active ? 0 : 1 }, function (r) { var o = parse(r); if (o && o.success) { back_to_tab(); } else { bad(o && o.message); } });
            };
            if (!n.active) { go(); return; }
            confirm_box({ title: 'Turn off ' + n.name + '?', text: 'Its directory page and chip go away and creators can no longer pick it in Settings. Creators who chose it stay listed under All.', confirmButtonText: 'Turn Off' })
                .then(function (yes) { if (yes) { go(); } });
        }
    });
})();
