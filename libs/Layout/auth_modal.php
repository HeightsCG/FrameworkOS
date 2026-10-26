<?php /* Sign-in / register dialog markup, opened by any [data-auth="login|register"] element (public/js/landing.js). Shared by login_form.php and public_page.php. */ ?>
<?php $google_next = CustomDomains::safe_path((string) ($_GET['next'] ?? '')); $google_next = $google_next !== '/' ? '?next=' . htmlspecialchars(rawurlencode($google_next), ENT_QUOTES, 'UTF-8') : '';   // Google sign-in lands on ?next= too (Controller::bounce) ?>
    <div class="ld-auth" id="ld_auth" role="dialog" aria-modal="true" aria-label="Sign in or create account" hidden>
        <div class="ld-auth__veil"></div>
        <div class="ld-auth__dialog">
            <button type="button" class="ld-auth__close" aria-label="Close">&#10005;</button>
            <span class="ld-brand__mark ld-auth__mark" aria-hidden="true"></span>

            <div id="login_form">
                <p class="cos-form-title">Sign In</p>
                <?php if (GoogleAuth::configured()): ?>
                <a class="cos-google" href="/account/google_start<?php echo $google_next; ?>" rel="nofollow">
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
                <p class="cos-form-title">Verify It's You</p>
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
                <p class="cos-form-title">Reset Password</p>
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
              <div id="register_fields">
                <p class="cos-form-title">Create Account</p>
                <p class="cos-help" id="register_help" data-trial="Your <?php echo (int) Founding::TRIAL_DAYS; ?>-day free trial starts with your account. No card needed.">Free to join. Creators pick a plan after signing up.</p>
                <?php if (GoogleAuth::configured()): ?>
                <a class="cos-google" href="/account/google_start<?php echo $google_next; ?>" rel="nofollow">
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
                        <p class="cos-typo" id="register_typo" aria-live="polite" style="display:none;">Did you mean <button type="button" class="cos-link accent" id="register_typo_fix"></button>?</p>
                    </div>
                    <div class="col-md-6">
                        <div class="form-floating">
                            <input type="password" id="register_p_word" class="form-control" placeholder="Password" autocomplete="new-password" aria-describedby="register_pw_rules">
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
                        <ul class="cos-rules" id="register_pw_rules" aria-label="Password requirements">
                            <?php foreach (array('len' => '8+ characters', 'up' => 'Uppercase letter', 'low' => 'Lowercase letter', 'num' => 'Number', 'sym' => 'Symbol') as $rk => $rl): ?>
                            <li data-rule="<?php echo $rk; ?>"><svg class="cos-rules__ic" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><path class="cos-rules__ck" d="m5.25 8.25 1.75 1.75 3.75-4"/></svg><span><?php echo $rl; ?></span><span class="cos-sr" data-state></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php /* honeypot (ApiAuthController::HONEYPOT): a name no autofill knows, never displayed; a bot that fills it gets a "success" and no account */ ?>
                    <div style="display:none" aria-hidden="true">
                        <input type="text" id="register_hp" name="hp_field_x" tabindex="-1" autocomplete="new-password">
                    </div>
                    <?php /* a founding creator sign-up starts its trial with the account (ApiAuthController::start_founding_trial), so the Creator Agreement is ticked here; auth_head.php shows this only for that sign-up */ ?>
                    <div class="col-md-12" id="register_agree_col" hidden>
                        <div class="cos-agree" id="register_agree_wrap">
                            <input type="checkbox" class="form-check-input cos-agree__box" id="register_agree" aria-describedby="register_agree_sum">
                            <div class="cos-agree__body">
                                <label for="register_agree">I Agree to the</label> <button type="button" class="cos-agree__link" id="register_agree_link" aria-expanded="false" aria-controls="register_agree_text">Creator Agreement</button>
                                <small class="cos-agree__sum" id="register_agree_sum">You are 18 or older, you have the rights to what you post, and you follow the content rules.</small>
                            </div>
                        </div>
                        <div class="cos-agree__text" id="register_agree_text" tabindex="0" role="region" aria-label="Creator Agreement" hidden><?php echo nl2br(htmlspecialchars(AccountController::creator_terms(Main::site_name()), ENT_QUOTES, 'UTF-8')); ?></div>
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
              <?php /* after signing up: where the link went, a way to fix a mistyped address, and Gmail one tap away */ ?>
              <div id="register_done" style="display:none;" tabindex="-1">
                <p class="cos-form-title">Check Your Email</p>
                <p class="cos-help cos-done__p">We sent a link to <b id="register_done_email"></b>. Open it to verify your account. It works in any browser.</p>
                <p class="cos-help cos-done__p">Not there? Check Spam or Promotions.</p>
                <a class="cos-submit cos-done__gmail" id="register_gmail" href="https://mail.google.com/mail/u/0/#search/from%3Acreatorlinkstudio.com" target="_blank" rel="noopener" style="display:none;">Open Gmail</a>
                <div class="cos-links">
                    <button type="button" class="cos-link" id="register_resend">Resend Email</button>
                    <button type="button" class="cos-link accent" id="register_change">Wrong Email? Change It</button>
                </div>
              </div>
            </div>

        </div>
    </div>

