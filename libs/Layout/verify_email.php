<!DOCTYPE html>
<html lang="en">
<head>
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
    <script>
    $(document).ready(function() {

        var token = new URLSearchParams(window.location.search).get('token') || '';

        function show_result(ok, message) {
            $('#verify_spinner').hide();
            $('#verify_message').text(message);
            if (ok) {
                $('#verify_icon').html('&#10003;').addClass('is-ok');
                $('#verify_signin').show();
                setTimeout(function() { window.location = '/'; }, 2500);
            } else {
                $('#verify_icon').html('&times;').addClass('is-err');
                $('#verify_signin').show();
            }
        }

        if (token === '') {
            show_result(false, 'This verification link is missing its token.');
            return;
        }

        ApiDataSvc.apiCall('post', 'verify_email', { token: token }, function(data) {
            var response = null;
            try { response = JSON.parse(data); } catch (e) { response = null; }
            if (response && response.success) {
                show_result(true, response.message);
            } else {
                show_result(false, response ? response.message : 'Something went wrong.');
            }
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
<body>
    <div class="container verify-card">
        <h1 class="h4 mb-4"><?php echo Main::site_name(); ?></h1>
        <div id="verify_spinner" class="verify-spinner"></div>
        <div id="verify_icon" class="verify-icon" style="display:none;"></div>
        <p id="verify_message" class="text-muted mb-3">Verifying your email&hellip;</p>
        <a id="verify_signin" href="/" class="btn btn-primary" style="display:none;">Go to sign in</a>
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
