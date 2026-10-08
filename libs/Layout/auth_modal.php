<?php /* Sign-in / register dialog markup, opened by any [data-auth="login|register"] element (public/js/landing.js). Shared by login_form.php and public_page.php. */ ?>
    <div class="ld-auth" id="ld_auth" role="dialog" aria-modal="true" aria-label="Sign in or create account" hidden>
        <div class="ld-auth__veil"></div>
        <div class="ld-auth__dialog">
            <button type="button" class="ld-auth__close" aria-label="Close">&#10005;</button>
            <span class="ld-brand__mark ld-auth__mark" aria-hidden="true"></span>

            <div id="login_form">
                <h2 class="cos-form-title">Sign In</h2>
                <?php if (GoogleAuth::configured()): ?>
                <a class="cos-google" href="/account/google_start">
                    <svg class="cos-google__g" viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                    <span>Continue With Google</span>
                </a>
                <div class="cos-or"><span>or</span></div>
                <?php endif; ?>
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
                <p class="cos-help">Free for fans. Creators pick a plan after signing up.</p>
                <?php if (GoogleAuth::configured()): ?>
                <a class="cos-google" href="/account/google_start">
                    <svg class="cos-google__g" viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                    <span>Continue With Google</span>
                </a>
                <div class="cos-or"><span>or</span></div>
                <?php endif; ?>
                <div class="row g-3">
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
                    <?php /* Creator Agreement: landing.js copies this in when the sign-up is for a creator (?role=creator); registerAction requires it then */ ?>
                    <div class="col-md-12" id="register_creator_slot" hidden></div>
                    <template id="register_creator_tpl">
                        <textarea class="form-control mb-2" rows="4" readonly aria-label="Creator Agreement &amp; Content Policy" style="font-size:.8125rem;"><?php echo htmlspecialchars(AccountController::creator_terms(Main::site_name()), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="register_creator_agree">
                            <label class="form-check-label" for="register_creator_agree">I have read and accept the Creator Agreement and Content Policy.</label>
                        </div>
                    </template>
                    <?php /* honeypot: off-screen, never shown; a bot that fills it gets a "success" and no account (ApiAuthController::registerAction) */ ?>
                    <div aria-hidden="true" style="position:absolute; left:-9999px; top:0; width:1px; height:1px; overflow:hidden;">
                        <input type="text" id="register_company" name="company" tabindex="-1" autocomplete="off" placeholder="Company">
                    </div>
                    <div class="col-md-12">
                        <button type="button" id="do_register" class="cos-submit">Create Account</button>
                        <p class="cos-legal">By creating an account you agree to the <a href="/terms" target="_blank" rel="noopener">Terms of Service</a> and <a href="/privacy" target="_blank" rel="noopener">Privacy Policy</a>.</p>
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

