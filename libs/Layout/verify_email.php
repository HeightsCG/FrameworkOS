<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/heycatch.php'; ?>
<?php include __DIR__ . '/google_analytics.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/images/favicon-16x16.png">
    <link rel="apple-touch-icon" href="/images/android-chrome-192x192.png">
    <meta name="robots" content="noindex, nofollow">
    <?php echo CSRF::meta(); ?>
    <title>Verify email &middot; <?php echo Main::site_name(); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css">
    <link rel="stylesheet" href="/css/site.css?v=<?php echo @filemtime(Main::app_path().'/public/css/site.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script src="/js/api.data.js?v=<?php echo @filemtime(Main::app_path().'/public/js/api.data.js'); ?>"></script>
    <script src="/js/csrf-retry.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/csrf-retry.js'); ?>"></script>
    <script>
    $(document).ready(function() {

        var token = new URLSearchParams(window.location.search).get('token') || '';

        // A good link signs them in: follow the server's `redirect`. A dead one offers a resend (expired) or sign-in (already used).
        function show_result(ok, message, response) {
            $('#verify_spinner').hide();
            $('#verify_message').text(message);
            if (ok) {
                var go = (response && response.redirect) ? response.redirect : '/';
                $('#verify_icon').html('&#10003;').addClass('is-ok');
                $('#verify_continue').attr('href', go).show();
                setTimeout(function() { window.location = go; }, 1200);
            } else {
                $('#verify_icon').html('&times;').addClass('is-err');
                if (response && response.expired) { $('#verify_resend').show(); }
                $('#verify_signin').show();
            }
        }

        if (token === '') {
            show_result(false, 'This verification link is missing its token.', null);
            return;
        }

        ApiDataSvc.apiCall('post', 'verify_email', { token: token }, function(data) {
            var response = null;
            try { response = JSON.parse(data); } catch (e) { response = null; }
            if (response && response.success) {
                show_result(true, response.message, response);
            } else {
                show_result(false, response ? response.message : 'Something went wrong.', response);
            }
        });

        $('#do_resend').on('click', function() {
            var email = ($('#resend_email').val() || '').trim();
            if (email === '') { toastr.error('Enter your email'); return; }
            ApiDataSvc.apiCall('post', 'resend_verification', { u_name: email }, function(data) {
                var obj = null;
                try { obj = JSON.parse(data); } catch (e) { obj = null; }
                if (obj && obj.success) { toastr.success(obj.message); } else { toastr.error(obj ? obj.message : 'Something went wrong.'); }
            });
        });
        $(document).on('keydown', '#resend_email', function(e) {
            if (e.keyCode === 13) { $('#do_resend').trigger('click'); }
        });

    });
    </script>
    <style>
        .verify-card { max-width: 420px; margin: 90px auto 0; text-align: center; }
        .verify-icon { width: 56px; height: 56px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center;
                       font-size: 30px; font-weight: 700; margin-bottom: 18px; background: #eef0f4; color: #6b7280; }
        .verify-icon.is-ok  { background: #e7f6ec; color: #1a7f37; }
        .verify-icon.is-err { background: #fdecec; color: #b42318; }
        .verify-spinner { width: 34px; height: 34px; border: 3px solid rgba(0,0,0,.12); border-top-color: #6b7280;
                          border-radius: 50%; animation: vspin .7s linear infinite; margin: 0 auto 18px; }
        @keyframes vspin { to { transform: rotate(360deg); } }
    </style>
</head>
<body class="auth-plain">
    <div class="container verify-card">
        <h1 class="h4 mb-4"><?php echo Main::site_name(); ?></h1>
        <div id="verify_spinner" class="verify-spinner"></div>
        <div id="verify_icon" class="verify-icon" style="display:none;"></div>
        <p id="verify_message" class="text-muted mb-3">Verifying your email&hellip;</p>
        <div id="verify_resend" class="mb-3" style="display:none;">
            <div class="form-floating mb-2">
                <input type="email" id="resend_email" class="form-control" placeholder="Email" autocomplete="email">
                <label for="resend_email">Email</label>
            </div>
            <button type="button" id="do_resend" class="btn btn-primary">Resend Verification</button>
        </div>
        <a id="verify_continue" href="/" class="btn btn-primary" style="display:none;">Continue</a>
        <a id="verify_signin" href="/?auth=login" class="btn btn-outline-secondary" style="display:none;">Sign In</a>
    </div>
    <script>
        // Reveal the icon slot once we have a result (kept hidden while spinning).
        $(document).ready(function() {
            var obs = setInterval(function() {
                if ($('#verify_spinner').is(':hidden')) { $('#verify_icon').show(); clearInterval(obs); }
            }, 100);
        });
    </script>
</body>
</html>
