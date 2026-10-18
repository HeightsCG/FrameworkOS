<?php /* Sign-in / register / MFA / forgot dialog: styles, libraries and the auth requests. Shared by login_form.php (landing) and public_page.php. Needs CSRF::meta() in <head>. */ ?>
    <!-- Bootstrap + toastr are only needed by the auth dialog: load them without blocking first paint. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css"></noscript>
    <script defer src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script defer src="/js/api.data.js?v=<?php echo @filemtime(Main::app_path().'/public/js/api.data.js'); ?>"></script>
    <script defer src="/js/csrf-retry.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/csrf-retry.js'); ?>"></script>
    <style>
    /* register dialog: the password rules, ticked as they are met */
    .cos-rules { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 16px; margin: -4px 0 0; padding: 0; list-style: none; font-size: .8125rem; line-height: 1.3; color: var(--ld-muted, #6e6e73); }
    .cos-rules li { display: flex; align-items: center; gap: 8px; transition: color .15s; }
    .cos-rules__ic { flex: none; }
    .cos-rules__ck { opacity: 0; transition: opacity .15s; }
    .cos-rules li.is-met { color: var(--sx-t2, #3a3a40); }
    .cos-rules li.is-met .cos-rules__ic { color: #22c55e; }
    .cos-rules li.is-met .cos-rules__ck { opacity: 1; }
    .cos-rules li.is-miss { color: #ef4444; }
    .cos-typo { margin: .5rem 0 0; font-size: .875rem; color: var(--ld-muted, #6e6e73); overflow-wrap: anywhere; }
    .cos-typo .cos-link { text-decoration: underline; text-underline-offset: 2px; }
    .cos-done__p { margin-bottom: .9rem; overflow-wrap: anywhere; }
    .cos-done__p b { color: var(--ld-ink, #1d1d1f); font-weight: 600; }
    a.cos-done__gmail { display: flex; align-items: center; justify-content: center; text-decoration: none; }
    a.cos-done__gmail:hover { color: #fff; }
    .cos-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    </style>
    <script>
    /* Where to go after signing in: back to the page that sent them (?next=/@handle/events/12), same site only. */
    function cls_after_login() {
        var n = new URLSearchParams(location.search).get('next') || '';
        if (/[\u0000-\u0020\u007f\\]/.test(n)) { return '/'; }   // browsers drop tabs/newlines and read \ as /: "/\t/evil.com" would leave the site
        return (n.charAt(0) === '/' && n.charAt(1) !== '/') ? n : '/';
    }
    document.addEventListener('DOMContentLoaded', function () {
    $(document).ready(function() {

        var auth_box = document.getElementById('ld_auth');
        if (!auth_box || auth_box.hidden) {   // landing.js may already have opened a panel (?auth=register): leave it
            $('#login_form').show();
            $('#forgot_form').hide();
            $('#register_form').hide();
        }

        function showRegister() {
            $('#login_form').hide();
            $('#forgot_form').hide();
            $('#register_form').show();
        }
        // Common domain typos (gmial.com, hotmial.com...): offered before the account is made; one tap applies the fix.
        var reg_typos = { 'gmial.com': 'gmail.com', 'gmal.com': 'gmail.com', 'gmai.com': 'gmail.com', 'gmaill.com': 'gmail.com', 'gamil.com': 'gmail.com', 'gnail.com': 'gmail.com', 'gmail.co': 'gmail.com', 'gmail.con': 'gmail.com', 'gmail.cm': 'gmail.com', 'gmail.om': 'gmail.com',
            'hotmial.com': 'hotmail.com', 'hotmal.com': 'hotmail.com', 'hotmai.com': 'hotmail.com', 'hotmail.co': 'hotmail.com', 'hotmail.con': 'hotmail.com', 'yahooo.com': 'yahoo.com', 'yaho.com': 'yahoo.com', 'yhoo.com': 'yahoo.com', 'yahoo.co': 'yahoo.com', 'yahoo.con': 'yahoo.com',
            'outlok.com': 'outlook.com', 'outllok.com': 'outlook.com', 'outloo.com': 'outlook.com', 'outlook.co': 'outlook.com', 'outlook.con': 'outlook.com', 'icloud.co': 'icloud.com', 'icloud.con': 'icloud.com', 'iclod.com': 'icloud.com', 'icoud.com': 'icloud.com', 'icluod.com': 'icloud.com', 'aol.co': 'aol.com' };
        var reg_typo_seen = '';
        function reg_typo_check() {
            var v = String($('#register_user_email').val() || '').trim(), m = /^([^\s@]+)@([^\s@]+)$/.exec(v.toLowerCase());
            var t = m && reg_typos[m[2]] ? v.split('@')[0] + '@' + reg_typos[m[2]] : '';
            $('#register_typo_fix').text(t); $('#register_typo').toggle(t !== '');
            return t;
        }
        $(document).on('blur', '#register_user_email', reg_typo_check);
        $(document).on('input', '#register_user_email', function () { if ($('#register_typo').is(':visible')) { reg_typo_check(); } });
        $(document).on('click', '#register_typo_fix', function () { $('#register_user_email').val($(this).text()); $('#register_typo').hide(); $('#register_p_word').trigger('focus'); });
        // Signed up: the check-your-email panel. Wrong Email? Change It reopens the form with the address filled in.
        var reg_done_email = '';
        function show_register_done(email) {
            reg_done_email = email;
            $('#register_done_email').text(email);
            $('#register_gmail').toggle(/@(gmail|googlemail)\.com$/i.test(email));
            $('#register_fields').hide(); $('#register_done').show().trigger('focus');
        }
        $(document).on('click', '#register_change', function () {
            $('#register_done').hide(); $('#register_fields').show();
            $('#register_user_email').val(reg_done_email).trigger('focus').trigger('select');
        });
        $(document).on('click', '#register_resend', function () {
            var $b = $(this).prop('disabled', true);
            ApiDataSvc.apiCall('post', 'resend_verification', { u_name: reg_done_email }, function (data) {
                var obj = null; try { obj = JSON.parse(data); } catch (e) {}
                if (obj && obj.success) { $b.text('Email Sent'); setTimeout(function () { $b.text('Resend Email').prop('disabled', false); }, 30000); }
                else { $b.prop('disabled', false); toastr.error(obj ? obj.message : 'Something went wrong.'); }
            });
        });

        $('#do_login').on('click', function() {

            if ($("#u_name").val() === '') {
                toastr.error('Username or email is required');
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
                    window.location = obj.reset_pw == 1 ? '/account/force_reset' : cls_after_login();
                } else if (obj.unverified) {
                    show_unverified($('#u_name').val());
                } else {
                    toastr.error(obj.message);
                }
            });
        });

        // Unconfirmed email: surface a resend option using whatever identifier they signed in with.
        var resend_identifier = '';
        function show_unverified(identifier) {
            resend_identifier = identifier;
            toastr.warning('Please verify your email before signing in.');
            $('#resend_verify_wrap').show();
        }
        $('#do_resend_verify').on('click', function() {
            ApiDataSvc.apiCall('post', 'resend_verification', { u_name: resend_identifier }, function(data) {
                var obj = null;
                try { obj = JSON.parse(data); } catch (e) { obj = null; }
                if (obj && obj.success) { toastr.success(obj.message); }
                else { toastr.error(obj ? obj.message : 'Something went wrong.'); }
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
                    window.location = obj.reset_pw == 1 ? '/account/force_reset' : cls_after_login();
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
                toastr.error('Username or email is required');
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

        // Password rules under the register fields, ticked as they are met; a failed submit names every one still missing.
        var pw_rules = [
            { k: 'len', t: 'at least 8 characters', ok: function (p) { return p.length >= 8; } },
            { k: 'up', t: 'an uppercase letter', ok: function (p) { return /[A-Z]/.test(p); } },
            { k: 'low', t: 'a lowercase letter', ok: function (p) { return /[a-z]/.test(p); } },
            { k: 'num', t: 'a number', ok: function (p) { return /[0-9]/.test(p); } },
            { k: 'sym', t: 'a symbol', ok: function (p) { return /[^A-Za-z0-9]/.test(p); } }
        ];
        function paint_pw_rules(flag) {
            var p = String($('#register_p_word').val() || ''), miss = [];
            pw_rules.forEach(function (r) {
                var li = $('#register_pw_rules [data-rule="' + r.k + '"]'), met = r.ok(p);
                li.toggleClass('is-met', met);
                if (flag) { li.toggleClass('is-miss', !met); } else if (met) { li.removeClass('is-miss'); }
                li.find('[data-state]').text(met ? ' (done)' : '');
                if (!met) { miss.push(r.t); }
            });
            return miss;
        }
        $(document).on('input', '#register_p_word', function () { paint_pw_rules(false); });

        $('#do_register').on('click', function() {

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

            var pw_miss = paint_pw_rules(true);
            if (pw_miss.length) {
                var pw_last = pw_miss.pop();
                toastr.error('Password needs ' + (pw_miss.length ? pw_miss.join(', ') + ' and ' : '') + pw_last);
                return;
            }

            var reg_typo = reg_typo_check(), reg_em = String($('#register_user_email').val() || '').trim();
            if (reg_typo && reg_typo_seen !== reg_em) { reg_typo_seen = reg_em; $('#register_typo_fix').trigger('focus'); return; }   // asked once; Create Account again keeps what they typed

            var signup_q = new URLSearchParams(location.search);   // ?plan= / ?role= / ?ref= on this page (else the cls_signup cookie, server side)
            ApiDataSvc.apiCall('post', 'register', {
                user_email: $('#register_user_email').val(),
                p_word:     $('#register_p_word').val(),
                p_word_confirm: $('#register_p_word_confirm').val(),
                hp_field_x: $('#register_hp').val(),   // honeypot: empty for people
                plan:       signup_q.get('plan') || '',
                role:       signup_q.get('role') || '',
                ref:        signup_q.get('ref') || '',
                'return':   cls_after_login() !== '/' ? cls_after_login() : location.pathname   // where the verification link lands them
            }, function(data) {
                var obj = JSON.parse(data);
                if (obj.success) {
                    if (window.CLSTikTokRegistration) { window.CLSTikTokRegistration(obj.tt_registration); }   // TikTok CompleteRegistration: the account exists (server-confirmed, once)
                    // Account created but not yet usable: the link in the email signs them in. The check-your-email panel meanwhile.
                    show_register_done(String($('#register_user_email').val() || '').trim());
                    if (obj.email_failed) { toastr.warning(obj.message); }   // the email never left: Resend Email is right there
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

        $(document).on('keydown', '#register_user_email, #register_p_word', function(e) {
            if (e.keyCode === 13) {
                $("#do_register").trigger('click');
            }
        });

        // Back from Google (AccountController::google_callbackAction): a second factor to enter, or why it didn't work.
        var google_q = new URLSearchParams(location.search);
        if (google_q.get('auth') === 'mfa') {
            var m = (google_q.get('m') || '').split(',');
            setTimeout(function () { startMfa({ totp: m.indexOf('totp') >= 0, email: m.indexOf('email') >= 0 }); }, 0);   // after the dialog opens on Sign In
        }
        var google_errors = {
            denied: 'Google sign-in was cancelled.',
            expired: 'That Google sign-in took too long or was already used. Try again.',
            unverified: 'Your Google account’s email isn’t verified, so it can’t be used to sign in.',
            other: 'That account is linked to a different Google account. Sign in with your email and password.',
            exists: 'An account with this email already exists. Sign in with your email and password, then connect Google in Settings.',
            suspended: 'This account has been suspended. Contact support if you believe this is a mistake.',
            seat: 'Your team seat is over the account owner’s plan limit. Ask the owner to upgrade.',
            owner: 'The account you work on has been suspended, so team access is paused.',
            busy: 'Too many attempts. Please try again later.',
            unavailable: 'Google sign-in isn’t available right now. Use your email and password.'
        };
        var google_err = google_q.get('google_error');
        if (google_err) {
            toastr.error(google_errors[google_err] || 'Google sign-in didn’t work. Try again, or use your email and password.');
            google_q.delete('google_error');
            history.replaceState(null, '', location.pathname + (google_q.toString() ? '?' + google_q.toString() : ''));
        }

    });
    });
    </script>
