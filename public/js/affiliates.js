/* /affiliates/apply (send the application) and /affiliates/dashboard (copy the link, request a payout). */
(function () {
    "use strict";
    var $ = window.jQuery;
    function parse(r) { try { return JSON.parse(r); } catch (e) { return null; } }

    var form = document.getElementById('aflApply');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('aflSubmit');
            var data = { website: $('#aflWebsite').val(), note: $('#aflNote').val(), agree: document.getElementById('aflAgree').checked ? '1' : '' };
            if (String(data.website || '').trim() === '') { toastr.error('Enter the website or social profile where you will share your link.'); $('#aflWebsite').trigger('focus'); return; }
            if (String(data.note || '').trim() === '') { toastr.error('Tell us how you will promote it.'); $('#aflNote').trigger('focus'); return; }
            if (data.agree !== '1') { toastr.error('Agree to the affiliate terms to apply.'); return; }
            btn.disabled = true;
            ApiDataSvc.apiCall('post', 'affiliate_apply', data, function (r) {
                var o = parse(r);
                if (!o || !o.success) { btn.disabled = false; toastr.error((o && o.message) || 'Something went wrong. Please try again.'); return; }
                toastr.success(o.message);
                setTimeout(function () { window.location = '/affiliates/apply'; }, 700);
            });
        });
    }

    var dash = document.getElementById('aflDash');
    if (!dash) { return; }

    /* copy link: straight to the clipboard, no dialog */
    dash.addEventListener('click', function (e) {
        if (!e.target.closest('[data-copy-link]')) { return; }
        var link = dash.getAttribute('data-link');
        var done = function () { toastr.success('Link copied'); };
        var fallback = function () {
            var t = document.getElementById('aflLink');
            t.select();
            try { document.execCommand('copy'); done(); } catch (err) { toastr.error('Could not copy the link'); }
        };
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(link).then(done, fallback); return; }
        fallback();
    });

    var pay = document.getElementById('aflPayout');
    if (pay) {
        pay.addEventListener('click', function () {
            var go = function () {
                pay.disabled = true;
                ApiDataSvc.apiCall('post', 'affiliate_payout_request', {}, function (r) {
                    var o = parse(r);
                    if (!o || !o.success) { pay.disabled = false; toastr.error((o && o.message) || 'Something went wrong. Please try again.'); return; }
                    toastr.success(o.message);
                    setTimeout(function () { window.location.reload(); }, 700);
                });
            };
            if (!window.Swal) { if (window.confirm('Request a payout of your available balance?')) { go(); } return; }
            Swal.fire({ title: 'Request a payout?', text: 'Your available balance is requested and paid to your bank by our team.', showCancelButton: true, reverseButtons: true,
                confirmButtonText: 'Request Payout', confirmButtonColor: '#CD4C00', cancelButtonColor: '#6b6779' }).then(function (res) { if (res.isConfirmed) { go(); } });
        });
    }
})();
