<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php echo CSRF::meta(); ?>
    <title><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="/css/site.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/site.css') ?: time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script src="/js/api.data.js"></script>
    <script>
    $(document).ready(function() {

        $('#login_form').show();
        $('#forgot_form').hide();
        $('#register_form').hide();

        function showRegister() {
            $('#login_form').hide();
            $('#forgot_form').hide();
            $('#register_form').show();
        }

        $('#do_login').on('click', function() {
            ApiDataSvc.apiCall('post', 'login', {
                u_name: $('#u_name').val(),
                p_word: $('#p_word').val()
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) {
                    window.location = obj.reset_pw == 1 ? '/account/force_reset' : '/';
                } else {
                    toastr.error(obj.message);
                }
            });
        });

        $('#forgot_password').on('click', function() {
            $('#login_form').hide();
            $('#register_form').hide();
            $('#forgot_form').show();
        });

        $('#register').on('click', function(e) { e.preventDefault(); showRegister(); });

        $('.show_login').on('click', function() {
            $('#forgot_form').hide();
            $('#register_form').hide();
            $('#login_form').show();
        });

        $('#do_forgot').on('click', function() {
            ApiDataSvc.apiCall('post', 'forgot', {
                u_name: $('#forgot_u_name').val()
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) {
                    toastr.success(obj.message);
                } else {
                    toastr.error(obj.message);
                }
            });
        });

        $('#do_register').on('click', function() {
            ApiDataSvc.apiCall('post', 'register', {
                u_name:     $('#register_u_name').val(),
                user_email: $('#register_user_email').val(),
                p_word:     $('#register_p_word').val()
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) {
                    window.location = '/';
                } else {
                    toastr.error(obj.message);
                }
            });
        });

    });
    </script>
</head>
<body class="cos-signin-login">
    <span class="cos-signin-glow"></span>

    <header class="cos-signin-header">
        <span class="cos-signin-brand">
            <span class="cos-signin-brand__mark"></span>
            <span class="cos-signin-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
        </span>
    </header>

    <main class="cos-signin-main">

        <div class="cos-signin-copy">
            <h1 class="cos-signin-headline">Your creator content, in one place.</h1>
            <p class="cos-signin-subcopy">Profiles, content, subscriptions, and payouts &mdash; all in one place.</p>
        </div>

        <div class="cos-signin-form-wrap">
            <span class="cos-signin-sheet cos-signin-sheet--2"></span>
            <span class="cos-signin-sheet cos-signin-sheet--1"></span>
            <div class="cos-signin-panel">

            <div id="login_form" class="cos-login-form">
                <h2 class="cos-login-title">Sign In</h2>
                <div class="cos-field">
                    <label class="cos-label" for="u_name">Username</label>
                    <input type="text" id="u_name" class="cos-input" autocomplete="username">
                </div>
                <div class="cos-field">
                    <label class="cos-label" for="p_word">Password</label>
                    <input type="password" id="p_word" class="cos-input" autocomplete="current-password">
                </div>
                <button type="button" id="do_login" class="cos-submit">Sign In</button>
                <div class="cos-login-links">
                    <a id="forgot_password" class="cos-login-link">Forgot Password?</a>
                    <a id="register" class="cos-login-link">Create Account</a>
                </div>
            </div>

            <div id="forgot_form" class="cos-login-form" style="display:none;">
                <h2 class="cos-login-title">Reset Password</h2>
                <p class="cos-login-help">Enter your username and we&rsquo;ll email a reset link.</p>
                <div class="cos-field">
                    <label class="cos-label" for="forgot_u_name">Username</label>
                    <input type="text" id="forgot_u_name" class="cos-input" autocomplete="username">
                </div>
                <button type="button" id="do_forgot" class="cos-submit">Send Reset Link</button>
                <div class="cos-login-links">
                    <a class="cos-login-link show_login">Back to Sign In</a>
                </div>
            </div>

            <div id="register_form" class="cos-login-form" style="display:none;">
                <h2 class="cos-login-title">Create Account</h2>
                <p class="cos-login-help">Set up your <?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?> Account.</p>
                <div class="cos-field">
                    <label class="cos-label" for="register_u_name">Username</label>
                    <input type="text" id="register_u_name" class="cos-input" autocomplete="username">
                </div>
                <div class="cos-field">
                    <label class="cos-label" for="register_user_email">Email</label>
                    <input type="email" id="register_user_email" class="cos-input" autocomplete="email">
                </div>
                <div class="cos-field">
                    <label class="cos-label" for="register_p_word">Password</label>
                    <input type="password" id="register_p_word" class="cos-input" autocomplete="new-password">
                </div>
                <button type="button" id="do_register" class="cos-submit">Create Account</button>
                <div class="cos-login-links">
                    <a class="cos-login-link show_login">Back to Sign In</a>
                </div>
            </div>
            </div>

        </div>

    </main>
</body>
</html>
