/* Free tool pages (/tools/*): validate, post to /api/tool_run, then show the inbox message. */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('tl_form');
    var btn = document.getElementById('tl_send');
    if (!form || typeof ApiDataSvc === 'undefined') { return; }
    var val = function (id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var niche = val('tl_niche'), first_name = val('tl_name'), email = val('tl_email'), consent = document.getElementById('tl_consent').checked;
        $('#tl_form .ct-input').removeClass('is-bad'); $('.tl-consent').removeClass('is-bad');
        var bad = '';
        if (niche.length < 3) { bad = bad || 'Add your niche'; $('#tl_niche').addClass('is-bad'); }
        if (first_name == '') { bad = bad || 'Add your first name'; $('#tl_name').addClass('is-bad'); }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { bad = bad || 'Add a valid email address'; $('#tl_email').addClass('is-bad'); }
        if (!consent) { bad = bad || 'Tick the box to agree to receive emails'; $('.tl-consent').addClass('is-bad'); }
        if (bad != '') { toastr.error(bad); $('#tl_form .is-bad').first().find('input').addBack('input').first().trigger('focus'); return; }
        btn.disabled = true; btn.textContent = 'Sending...';
        ApiDataSvc.apiCall('post', 'tool_run', {
            source: form.getAttribute('data-source'), niche: niche, first_name: first_name, email: email, consent: '1',
            vibe: val('tl_vibe'), audience: val('tl_audience'), company: val('tl_company')
        }, function (data) {
            var obj = null;
            try { obj = JSON.parse(data); } catch (err) { obj = null; }
            if (obj && obj.success) {
                document.getElementById('tl_done_text').textContent = obj.message || 'Check your inbox in a few minutes.';
                form.hidden = true;
                document.getElementById('tl_done').hidden = false;
            } else {
                toastr.error(obj ? obj.message : 'Something went wrong. Please try again.');
                btn.disabled = false; btn.textContent = btn.getAttribute('data-label');
            }
        });
    });
});
