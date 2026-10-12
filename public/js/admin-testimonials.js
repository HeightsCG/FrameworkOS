/* /admin, Testimonials tab: add (SweetAlert2 form), approve, unapprove, remove. Removal confirms first. */
(function () {
    "use strict";
    if (!document.querySelector('.adm-panel[data-panel="testimonials"]') && !document.getElementById('admTestimonialNew')) { return; }
    var $ = window.jQuery;
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }
    function call(action, data, done) {
        ApiDataSvc.apiCall('post', action, data, function (r) {
            var o = parse(r);
            if (!o || !o.success) { toastr.error((o && o.message) || 'Something went wrong. Please try again.'); return; }
            toastr.success(o.message); if (done) { done(o); }
            setTimeout(function () { window.location = '/admin/testimonials'; }, 700);
        });
    }
    function esc(s) { return $('<div>').text(s || '').html(); }

    $(document).on('click', '#admTestimonialNew', function () {
        if (!window.Swal) { return; }
        Swal.fire({
            title: 'New testimonial',
            html: '<div class="adm-form">'
                + '<label class="adm-form__l">First Name<input class="adm-form__in" id="tsFirst" maxlength="60" placeholder="Maya"></label>'
                + '<label class="adm-form__l">Niche<input class="adm-form__in" id="tsNiche" maxlength="80" placeholder="Fitness coach"></label>'
                + '<label class="adm-form__l">Quote<textarea class="adm-form__in" id="tsQuote" rows="4" maxlength="400" placeholder="Their words, as written"></textarea></label>'
                + '<label class="adm-form__l">Photo URL (optional)<input class="adm-form__in" id="tsPhoto" maxlength="500" placeholder="https://"></label>'
                + '</div>',
            showCancelButton: true, reverseButtons: true, confirmButtonText: 'Save', confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779', focusConfirm: false,
            preConfirm: function () {
                var d = { first_name: $('#tsFirst').val().trim(), niche: $('#tsNiche').val().trim(), quote: $('#tsQuote').val().trim(), photo_url: $('#tsPhoto').val().trim() };
                if (d.first_name === '' || d.quote === '') { Swal.showValidationMessage('A first name and a quote are needed.'); return false; }
                return d;
            }
        }).then(function (r) { if (r.isConfirmed && r.value) { call('admin_testimonial_add', r.value); } });
    });

    $(document).on('click', '[data-testimonial-action]', function () {
        var id = $(this).closest('.adm-tsrow').attr('data-testimonial'), action = $(this).attr('data-testimonial-action');
        if (!id) { return; }
        if (action === 'remove') {
            var ask = window.Swal ? Swal.fire({ title: 'Remove this testimonial?', text: 'It disappears from the home page at once.', showCancelButton: true, reverseButtons: true, confirmButtonText: 'Remove', confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' }).then(function (r) { return r.isConfirmed; }) : Promise.resolve(window.confirm('Remove this testimonial?'));
            ask.then(function (ok) { if (ok) { call('admin_testimonial_remove', { id: id }); } });
            return;
        }
        call('admin_testimonial_status', { id: id, approved: action === 'approve' ? 1 : 0 });
    });
})();
