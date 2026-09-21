<?php /* Sign-in / register / MFA / forgot dialog: styles, libraries and the auth requests. Shared by login_form.php (landing) and public_page.php. Needs CSRF::meta() in <head>. */ ?>
    <!-- Bootstrap + toastr are only needed by the auth dialog: load them without blocking first paint. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css"></noscript>
    <script defer src="https://cdn.jsdelivr.net/npm/jquery@4.0.0/dist/jquery.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script defer src="/js/api.data.js?v=<?php echo @filemtime(Main::app_path().'/public/js/api.data.js'); ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
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
                    window.location = obj.reset_pw == 1 ? '/account/force_reset' : '/';
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
                    // Account created but not yet usable: send them to sign in with a heads-up to verify.
                    $('#register_form').hide();
                    $('#forgot_form').hide();
                    $('#login_form').show();
                    toastr.success(obj.message);
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
    });
    </script>
