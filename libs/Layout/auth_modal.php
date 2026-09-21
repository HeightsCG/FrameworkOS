<?php /* Sign-in / register dialog markup, opened by any [data-auth="login|register"] element (public/js/landing.js). Shared by login_form.php and public_page.php. */ ?>
    <div class="ld-auth" id="ld_auth" role="dialog" aria-modal="true" aria-label="Sign in or create account" hidden>
        <div class="ld-auth__veil"></div>
        <div class="ld-auth__dialog">
            <button type="button" class="ld-auth__close" aria-label="Close">&#10005;</button>
            <span class="ld-brand__mark ld-auth__mark" aria-hidden="true"></span>

            <div id="login_form">
                <h2 class="cos-form-title">Sign In</h2>
                <p class="cos-help">Enter your username or email and password to sign in.</p>
                <div class="form-floating mb-3">
                    <input type="text" id="u_name" class="form-control" placeholder="Username or email" autocomplete="username">
                    <label for="u_name">Username or email</label>
                </div>
                <div class="form-floating mb-3">
                    <input type="password" id="p_word" class="form-control" placeholder="Password" autocomplete="current-password">
                    <label for="p_word">Password</label>
                </div>
                <button type="button" id="do_login" class="cos-submit">Sign In</button>
                <div id="resend_verify_wrap" style="display:none; margin-top:.9rem; text-align:center;">
                    <span class="cos-help" style="margin:0;">Didn&rsquo;t get the email? </span>
                    <button type="button" id="do_resend_verify" class="cos-link accent">Resend Verification</button>
                </div>
                <div class="cos-links">
                    <button type="button" id="forgot_password" class="cos-link">Forgot Password?</button>
                    <button type="button" id="register" class="cos-link accent">Create Account</button>
                </div>
            </div>

            <div id="mfa_form" style="display:none;">
                <h2 class="cos-form-title">Verify It's You</h2>
                <p class="cos-help" id="mfa_help">Enter your verification code to finish signing in.</p>
                <div class="form-floating mb-3">
                    <input type="text" id="mfa_code" class="form-control" placeholder="Verification code" inputmode="numeric" autocomplete="one-time-code">
                    <label for="mfa_code">Verification code</label>
                </div>
                <input type="hidden" id="mfa_method" value="">
                <button type="button" id="do_mfa_verify" class="cos-submit">Verify</button>
                <div class="cos-links" style="flex-wrap:wrap; gap:.75rem;">
                    <button type="button" id="mfa_use_email" class="cos-link" style="display:none;">Email me a code</button>
                    <button type="button" id="mfa_resend_email" class="cos-link" style="display:none;">Resend code</button>
                    <button type="button" id="mfa_use_totp" class="cos-link" style="display:none;">Use authenticator app</button>
                    <button type="button" id="mfa_use_backup" class="cos-link">Use a backup code</button>
                </div>
            </div>

            <div id="forgot_form" style="display:none;">
                <h2 class="cos-form-title">Reset Password</h2>
                <p class="cos-help">Enter your username or email and we&rsquo;ll email a reset link.</p>
                <div class="form-floating mb-3">
                    <input type="text" id="forgot_u_name" class="form-control" placeholder="Username or email" autocomplete="username">
                    <label for="forgot_u_name">Username or email</label>
                </div>
                <button type="button" id="do_forgot" class="cos-submit">Send Reset Link</button>
                <div class="cos-links">
                    <button type="button" class="cos-link show_login">Back to Sign In</button>
                </div>
            </div>

            <div id="register_form" style="display:none;">
                <h2 class="cos-form-title">Create Account</h2>
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
                        <button type="button" id="do_register" class="cos-submit">Create Account</button>
                    </div>
                    <div class="col-md-12">
                        <div class="cos-links">
                            <button type="button" class="cos-link show_login">Back to Sign In</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

