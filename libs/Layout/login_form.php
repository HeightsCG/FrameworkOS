<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Cache-Control" content="no-store, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <?php echo CSRF::meta(); ?>
    <title><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
    * { box-sizing: border-box; }
    body.cos-signin-login {
        margin: 0; min-height: 100vh; display: flex; flex-direction: column; color: #1d1d1f; position: relative; overflow-x: hidden;
        background-color: #eef0f4;
        background-image:
            radial-gradient(1200px 760px at 18% 22%, rgba(124,108,246,.18), transparent 58%),
            radial-gradient(1000px 720px at 88% 78%, rgba(91,75,224,.14), transparent 60%),
            radial-gradient(900px 640px at 60% 40%, rgba(255,255,255,.85), transparent 62%);
        background-attachment: fixed;
        font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Inter", "Segoe UI", sans-serif;
        -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
    }
    .cos-wrap { width: 100%; max-width: 1180px; margin: 0 auto; padding: 0 28px; }

    /* Header — aligned to the same 1180px container as the content */
    .cos-signin-header { height: 76px; display: flex; align-items: center; position: relative; z-index: 2; }
    .cos-brand { display: inline-flex; align-items: center; gap: .65rem; }
    .cos-brand__mark { position: relative; width: 30px; height: 30px; border-radius: 2px; background: linear-gradient(140deg, #8273f8, #5b4be0); box-shadow: 0 4px 12px -4px rgba(91,75,224,.6); }
    .cos-brand__mark::before { content: ""; position: absolute; inset: 7px; border-radius: 50%; border: 2px solid #fff; border-right-color: transparent; transform: rotate(-42deg); }
    .cos-brand__mark::after { content: ""; position: absolute; top: 6px; right: 6px; width: 4px; height: 4px; border-radius: 50%; background: #fff; }
    .cos-brand__name { font-weight: 600; font-size: 1.05rem; letter-spacing: -.02em; }

    /* Main — vertically centered below the header */
    .cos-main { flex: 1; display: flex; align-items: center; position: relative; z-index: 1; }
    .cos-grid { width: 100%; max-width: 1180px; margin: 0 auto; padding: 1.5rem 28px 2.5rem; display: grid; grid-template-columns: 1fr; gap: 40px; align-items: center; }

    /* Left content + large soft abstract brand shape */
    .cos-copy { position: relative; }
    .cos-anchor { position: absolute; z-index: 0; top: -150px; left: -160px; width: 620px; height: 620px; border-radius: 46% 54% 58% 42% / 52% 44% 56% 48%;
        background: radial-gradient(circle at 36% 34%, rgba(130,115,248,.55), rgba(91,75,224,.32) 46%, transparent 72%);
        filter: blur(46px); pointer-events: none; }
    .cos-anchor::after { content: ""; position: absolute; top: 210px; left: 250px; width: 260px; height: 260px; border-radius: 50%;
        background: radial-gradient(circle, rgba(86,150,232,.40), transparent 68%); filter: blur(40px); }
    .cos-copy > * { position: relative; z-index: 1; }
    .cos-kicker { display: inline-block; font-size: .72rem; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #5b4be0;
        padding: .42rem .7rem; margin-bottom: 1.4rem; border-radius: 2px; background: rgba(91,75,224,.10); border: 1px solid rgba(91,75,224,.18); }
    .cos-headline { font-size: clamp(2.3rem, 4vw, 3.6rem); font-weight: 600; line-height: 1.06; letter-spacing: -.035em; margin: 0 0 1.35rem; max-width: 16ch; }
    .cos-subcopy { font-size: 1.18rem; line-height: 1.55; color: #51515a; margin: 0 0 1rem; max-width: 44ch; }
    .cos-support { font-size: .95rem; line-height: 1.5; color: #7a7a82; margin: 0; max-width: 42ch; }

    /* Right login area — translucent glass surface, not a heavy card */
    .cos-formpane { position: relative; }
    .cos-form { width: 100%; max-width: 440px; }
    .cos-form-title { font-size: 1.6rem; font-weight: 600; letter-spacing: -.02em; margin: 0 0 .4rem; }
    .cos-help { color: #6e6e73; font-size: .95rem; margin: 0 0 1.7rem; }
    .cos-field { margin-bottom: 1.1rem; }
    .cos-label { display: block; font-size: .8rem; font-weight: 600; color: #51515a; letter-spacing: -.005em; margin-bottom: .5rem; }
    .cos-input { display: block; width: 100%; height: 58px; padding: 0 18px; background: #ffffff; border: 1px solid #cdced6; border-radius: 2px;
        color: #1d1d1f; font-family: inherit; font-size: 1rem; box-shadow: inset 0 2px 4px rgba(28,24,60,.05), inset 0 0 0 1px rgba(255,255,255,.6);
        transition: border-color .15s, box-shadow .15s; -webkit-appearance: none; appearance: none; }
    .cos-input::placeholder { color: #a1a1a6; }
    .cos-input:hover { border-color: #b6b7c2; }
    .cos-input:focus { outline: none; border-color: #5b4be0; box-shadow: 0 0 0 4px rgba(91,75,224,.18), inset 0 1px 2px rgba(91,75,224,.06); }
    .cos-submit { display: block; width: 100%; height: 58px; margin-top: 1.6rem; cursor: pointer; border: 1px solid #0b0b0f; border-radius: 2px;
        background: linear-gradient(180deg, #34343a 0%, #1d1d1f 52%, #161618 100%); color: #fff;
        font-family: inherit; font-size: 1rem; font-weight: 600; letter-spacing: .01em;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.18), 0 6px 16px -8px rgba(20,16,40,.55); transition: transform .12s ease, box-shadow .15s ease, background .15s; }
    .cos-submit:hover { background: linear-gradient(180deg, #3c3c44 0%, #232326 52%, #161618 100%); transform: translateY(-1px); box-shadow: inset 0 1px 0 rgba(255,255,255,.22), 0 12px 26px -10px rgba(20,16,40,.6); }
    .cos-submit:active { transform: translateY(.5px); box-shadow: inset 0 1px 0 rgba(255,255,255,.12), 0 4px 10px -6px rgba(20,16,40,.5); }
    .cos-submit:focus-visible { outline: none; box-shadow: inset 0 1px 0 rgba(255,255,255,.2), 0 0 0 4px rgba(91,75,224,.32); }
    .cos-links { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-top: 1.5rem; font-size: .9rem; }
    .cos-link { color: #6e6e73; font-weight: 500; text-decoration: none; cursor: pointer; transition: color .15s; }
    .cos-link:hover { color: #1d1d1f; }
    .cos-link.accent { color: #5b4be0; }
    .cos-link.accent:hover { color: #4636c4; }

    /* Desktop: balanced 55/45 layout with a glass form surface + gradient divider */
    @media (min-width: 920px) {
        .cos-grid { grid-template-columns: minmax(0, 54fr) minmax(0, 46fr); gap: 80px; padding: 2rem 28px; margin-top: -1.5rem; }
        .cos-formpane {
            display: flex; justify-content: flex-start;
            background: linear-gradient(160deg, rgba(255,255,255,.78), rgba(255,255,255,.55));
            -webkit-backdrop-filter: blur(22px) saturate(1.25); backdrop-filter: blur(22px) saturate(1.25);
            border: 1px solid rgba(255,255,255,.85); border-radius: 2px;
            padding: 52px 48px;
            box-shadow: 0 30px 70px -34px rgba(46,34,104,.42), 0 2px 0 rgba(255,255,255,.7) inset, 0 0 0 1px rgba(120,108,200,.06);
        }
        /* gradient editorial divider sitting in the gap, aligned to the form */
        .cos-formpane::before {
            content: ""; position: absolute; left: -40px; top: 12%; bottom: 12%; width: 2px; border-radius: 2px;
            background: linear-gradient(180deg, transparent, rgba(91,75,224,.45) 28%, rgba(91,75,224,.45) 72%, transparent);
        }
    }
    @media (max-width: 919px) {
        .cos-main { align-items: flex-start; }
        .cos-grid { gap: 28px; padding-top: 1.25rem; padding-bottom: 3rem; }
        .cos-headline { font-size: 2.2rem; }
        .cos-subcopy { font-size: 1.05rem; }
        .cos-formpane {
            background: linear-gradient(160deg, rgba(255,255,255,.82), rgba(255,255,255,.6));
            -webkit-backdrop-filter: blur(18px) saturate(1.2); backdrop-filter: blur(18px) saturate(1.2);
            border: 1px solid rgba(255,255,255,.85); border-radius: 2px; padding: 32px 24px;
            box-shadow: 0 22px 50px -28px rgba(46,34,104,.4);
        }
        .cos-form { max-width: none; }
    }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script src="/js/api.data.js?v=<?php echo @filemtime(Main::app_path().'/public/js/api.data.js'); ?>"></script>
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

            if ($("#u_name").val() === '') {
                toastr.error('Username is required');
                return;
            }

            if ($("#p_word").val() === '') {
                toastr.error('Password is required');
                return;
            }

            ApiDataSvc.apiCall('post', 'login', {
                u_name: $('#u_name').val(),
                p_word: $('#p_word').val()
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success && obj.mfa_required) {
                    startMfa(obj.methods || {});
                } else if (obj.success) {
                    window.location = obj.reset_pw == 1 ? '/account/force_reset' : '/';
                } else {
                    toastr.error(obj.message);
                }
            });
        });

        var mfaMethods = { totp: false, email: false };

        function startMfa(methods) {
            mfaMethods = methods;
            $('#login_form').hide();
            $('#forgot_form').hide();
            $('#register_form').hide();
            $('#mfa_form').show();
            setMfaMethod(mfaMethods.totp ? 'totp' : 'email');
        }

        function setMfaMethod(method) {
            $('#mfa_method').val(method);
            $('#mfa_code').val('').focus();

            if (method === 'totp') {
                $('#mfa_help').text('Enter the 6-digit code from your authenticator app.');
            } else if (method === 'email') {
                $('#mfa_help').text('We emailed you a verification code. Enter it below.');
            } else {
                $('#mfa_help').text('Enter one of your saved backup codes.');
            }

            // Alternate-method links, contextual to the current method.
            $('#mfa_use_email').toggle(method !== 'email' && !!mfaMethods.email);
            $('#mfa_resend_email').toggle(method === 'email');
            $('#mfa_use_totp').toggle(method !== 'totp' && !!mfaMethods.totp);
            $('#mfa_use_backup').toggle(method !== 'backup');
        }

        $('#do_mfa_verify').on('click', function() {
            var code = ($('#mfa_code').val() || '').trim();
            if (code === '') { toastr.error('Enter your verification code'); return; }
            ApiDataSvc.apiCall('post', 'mfa_verify', {
                method: $('#mfa_method').val(),
                code:   code
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) {
                    window.location = obj.reset_pw == 1 ? '/account/force_reset' : '/';
                } else {
                    toastr.error(obj.message);
                }
            });
        });

        $('#mfa_use_email').on('click', function() {
            ApiDataSvc.apiCall('post', 'mfa_send_login_code', {}, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) { toastr.success(obj.message); setMfaMethod('email'); }
                else { toastr.error(obj.message); }
            });
        });

        $('#mfa_resend_email').on('click', function() {
            ApiDataSvc.apiCall('post', 'mfa_send_login_code', {}, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) { toastr.success(obj.message); } else { toastr.error(obj.message); }
            });
        });

        $('#mfa_use_totp').on('click', function() { setMfaMethod('totp'); });
        $('#mfa_use_backup').on('click', function() { setMfaMethod('backup'); });

        $(document).on('keydown', '#mfa_code', function(e) {
            if (e.keyCode === 13) { $('#do_mfa_verify').trigger('click'); }
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

            if ($("#forgot_u_name").val() === '') {
                toastr.error('Username is required');
                return;
            }

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

            if ($("#register_first_name").val() === '') {
                toastr.error('First name is required');
                return;
            }

            if ($("#register_last_name").val() === '') {
                toastr.error('Last name is required');
                return;
            }

            if ($("#register_user_email").val() === '') {
                toastr.error('Email is required');
                return;
            }

            if ($("#register_p_word").val() === '') {
                toastr.error('Password is required');
                return;
            }

            if ($("#register_p_word").val() !== $("#register_p_word_confirm").val()) {
                toastr.error('Passwords do not match');
                return;
            }

            if ($("#register_p_word").val().length < 8) {
                toastr.error('Password must be at least 8 characters');
                return;
            }

            if (!/[A-Z]/.test($("#register_p_word").val())) {
                toastr.error('Password must include an uppercase letter');
                return;
            }

            if (!/[a-z]/.test($("#register_p_word").val())) {
                toastr.error('Password must include a lowercase letter');
                return;
            }

            if (!/[0-9]/.test($("#register_p_word").val())) {
                toastr.error('Password must include a number');
                return;
            }

            if (!/[^A-Za-z0-9]/.test($("#register_p_word").val())) {
                toastr.error('Password must include a symbol');
                return;
            }

            ApiDataSvc.apiCall('post', 'register', {
                first_name: $('#register_first_name').val(),
                last_name:  $('#register_last_name').val(),
                user_email: $('#register_user_email').val(),
                p_word:     $('#register_p_word').val(),
                p_word_confirm: $('#register_p_word_confirm').val()
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) {
                    window.location = '/';
                } else {
                    toastr.error(obj.message);
                }
            });
        });

        $(document).on('keydown', '#u_name, #p_word', function(e) {
            if (e.keyCode === 13) {
                $("#do_login").trigger('click');
            }
        });

        $(document).on('keydown', '#forgot_u_name', function(e) {
            if (e.keyCode === 13) {
                $("#do_forgot").trigger('click');
            }
        });

        $(document).on('keydown', '#register_first_name, #register_last_name, #register_user_email, #register_p_word', function(e) {
            if (e.keyCode === 13) {
                $("#do_register").trigger('click');
            }
        });

    });
    </script>
</head>
<body class="cos-signin-login">

    <header class="cos-signin-header">
        <div class="cos-wrap">
            <span class="cos-brand">
                <span class="cos-brand__mark"></span>
                <span class="cos-brand__name"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
        </div>
    </header>

    <main class="cos-main">
        <div class="cos-grid">

            <div class="cos-copy">
                <span class="cos-anchor" aria-hidden="true"></span>
                <h1 class="cos-headline">Create. Share. Earn. Keep it all connected.</h1>
                <p class="cos-subcopy"><?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?> brings your profiles, content, subscriptions, payouts, and revenue into one simple workspace.</p>
                <p class="cos-support">Built for creators who want less chaos between their tools.</p>
            </div>

            <div class="cos-formpane">
                <div class="cos-form">

                    <div id="login_form">
                        <h2 class="cos-form-title">Sign in</h2>
                        <p class="cos-help">Enter your username and password to sign in.</p>
                        <div class="form-floating mb-3">
                            <input type="text" id="u_name" class="form-control" placeholder="Username" autocomplete="username">
                            <label for="u_name">Username</label>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="password" id="p_word" class="form-control" placeholder="Password" autocomplete="current-password">
                            <label for="p_word">Password</label>
                        </div>
                        <button type="button" id="do_login" class="cos-submit">Sign in</button>
                        <div class="cos-links">
                            <a id="forgot_password" class="cos-link">Forgot password?</a>
                            <a id="register" class="cos-link accent">Create account</a>
                        </div>
                    </div>

                    <div id="mfa_form" style="display:none;">
                        <h2 class="cos-form-title">Verify it's you</h2>
                        <p class="cos-help" id="mfa_help">Enter your verification code to finish signing in.</p>
                        <div class="form-floating mb-3">
                            <input type="text" id="mfa_code" class="form-control" placeholder="Verification code" inputmode="numeric" autocomplete="one-time-code">
                            <label for="mfa_code">Verification code</label>
                        </div>
                        <input type="hidden" id="mfa_method" value="">
                        <button type="button" id="do_mfa_verify" class="cos-submit">Verify</button>
                        <div class="cos-links" style="flex-wrap:wrap; gap:.75rem;">
                            <a id="mfa_use_email" class="cos-link" style="display:none;">Email me a code</a>
                            <a id="mfa_resend_email" class="cos-link" style="display:none;">Resend code</a>
                            <a id="mfa_use_totp" class="cos-link" style="display:none;">Use authenticator app</a>
                            <a id="mfa_use_backup" class="cos-link">Use a backup code</a>
                        </div>
                    </div>

                    <div id="forgot_form" style="display:none;">
                        <h2 class="cos-form-title">Reset password</h2>
                        <p class="cos-help">Enter your username and we&rsquo;ll email a reset link.</p>
                        <div class="form-floating mb-3">
                            <input type="text" id="forgot_u_name" class="form-control" placeholder="Username" autocomplete="username">
                            <label for="forgot_u_name">Username</label>
                        </div>
                        <button type="button" id="do_forgot" class="cos-submit">Send reset link</button>
                        <div class="cos-links">
                            <a class="cos-link show_login">Back to sign in</a>
                        </div>
                    </div>

                    <div id="register_form" style="display:none;">
                        <h2 class="cos-form-title">Create account</h2>
                        <p class="cos-help">Set up your <?php echo htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8'); ?> account.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="text" id="register_first_name" class="form-control" placeholder="First name" autocomplete="given-name">
                                    <label for="register_first_name">First name</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="text" id="register_last_name" class="form-control" placeholder="Last name" autocomplete="family-name">
                                    <label for="register_last_name">Last name</label>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <div class="form-floating">
                                    <input type="email" id="register_user_email" class="form-control" placeholder="Email" autocomplete="email">
                                    <label for="register_user_email">Email</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="password" id="register_p_word" class="form-control" placeholder="Password" autocomplete="new-password">
                                    <label for="register_p_word">Password</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="password" id="register_p_word_confirm" class="form-control" placeholder="Confirm password" autocomplete="new-password">
                                    <label for="register_p_word_confirm">Confirm password</label>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <button type="button" id="do_register" class="cos-submit">Create account</button>
                            </div>
                            <div class="col-md-12">
                                <div class="cos-links">
                                    <a class="cos-link show_login">Back to sign in</a>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </main>
</body>
</html>
