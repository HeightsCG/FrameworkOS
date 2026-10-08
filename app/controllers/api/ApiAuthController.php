<?php
/** Sign-up, sign-in, password lifecycle and MFA enrolment/verification. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiAuthController extends BaseApiController {

    public function registerAction(){

        if (empty($this->post['user_email']) || !filter_var($this->post['user_email'], FILTER_VALIDATE_EMAIL)) {
            $this->jsonError('A valid email is required');
        }

        if (empty($this->post['p_word'])) {
            $this->jsonError('Password is required');
        }

        if (empty($this->post['p_word_confirm']) || $this->post['p_word'] !== $this->post['p_word_confirm']) {
            $this->jsonError('Passwords do not match');
        }

        $pw_error = $this->password_complexity_error($this->post['p_word']);
        if ($pw_error !== '') {
            $this->jsonError((string) ($pw_error));
        }

        // ?plan= / ?role= / ?ref=: posted by the register form, each one else from the cls_signup cookie. The Creator Agreement is accepted at the plan checkout.
        $signup = array_merge(UsersModel::signup_cookie(), array_filter(UsersModel::signup_params($this->post), 'strlen'));

        // Sign-up throttle, like login: per connection and per address. Every well-formed attempt counts.
        $ip    = $this->get_ip_address();
        $email = (string) $this->post['user_email'];
        if ($this->loginAttemptsModel->count_recent($ip, 'register', 60) >= 5) {
            $this->jsonError('Too many sign-ups from this connection. Try again later.');
        }
        if ($this->loginAttemptsModel->count_recent_for($email, 'register', 60) >= 3) {
            $this->jsonError('Too many sign-ups for this email. Try again later.');
        }
        $this->loginAttemptsModel->record($ip, $email, 'register');

        // Honeypot: a hidden "company" field nobody sees (auth_modal.php). Filled in = a bot, which gets the normal reply and no account.
        if ((string) ($this->post['company'] ?? '') !== '') {
            error_log('[register] honeypot filled from ' . $ip . ' for ' . $email);
            $this->jsonSuccess(['message' => 'Account created — check your email to verify your account.']);
        }

        if ($this->userModel->email_exists($this->post['user_email'])) {
            $this->jsonError('Could not create an account with those details');
        }

        // Where the verification link lands them once it has signed them in: the page they were on (same-site path only).
        $return = html_entity_decode((string) ($this->post['return'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($return === '') { $return = (string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH); }
        Session::set('signup_return', CustomDomains::safe_path($return));

        // no name on the form: a neutral handle (never the email), changed at /setup; names stay '' until Settings.
        $u_name = $this->userModel->neutral_username();
        $enc_p_word = password_hash($this->post['p_word'], PASSWORD_DEFAULT);

        $user_id = (int) $this->userModel->create_user(
            $u_name,
            $enc_p_word,
            '',
            '',
            $this->post['user_email'],
        );

        if ($user_id <= 0) {
            error_log('[register] create_user returned no id for ' . $this->post['user_email']);
            $this->jsonError('Could not create an account with those details');
        }

        // Where they came from (first touch, from the cls_ft cookie set by google_analytics.php).
        $this->userModel->record_first_touch($user_id);
        TrackingLinks::attribute(0, 'signup', $user_id);   // came in through a creator's tracking link (cls_tl)
        try { $this->userModel->record_signup_params($user_id, $signup, true); }
        catch (\Throwable $e) { error_log('[register] record_signup_params user_id=' . $user_id . ': ' . $e->getMessage()); }   // never block the verification email
        Affiliates::attribute_signup($user_id);   // came in through an affiliate link (cls_aff) or an approved affiliate's ?ref=
        UsersModel::clear_signup_cookie();
        SignupAlertJob::queue($user_id, 'email');   // admins get an email with the new account's details

        // Email verification is required before the account can sign in.
        $token       = $this->userModel->set_email_verify_token($user_id);
        $verify_link = Main::get_base_domain() . '/account/verify?token=' . urlencode($token);
        $to_name     = '';
        $sent = $this->notificationsModel->send_verification_email($this->post['user_email'], $to_name, $verify_link, $u_name);
        if (!$sent) {
            // The account exists but the link never left: tell the user (the panel offers Resend) and the admins.
            error_log('[register] verification email failed for user_id=' . $user_id . ' (' . $email . ')');
            try {
                Notify::many($this->userModel->admin_ids(), 'system', 'Verification email failed', 'The sign-up email to ' . $email . ' (user #' . $user_id . ') could not be sent.', '/admin/user/' . $user_id, 'fa-envelope');
            } catch (\Throwable $e) { error_log('[register] admin notice: ' . $e->getMessage()); }
            $this->jsonSuccess(['message' => 'Account created, but we couldn\'t send the verification email. Use Resend Verification to try again.', 'email_failed' => true]);
        }

        $this->jsonSuccess(['message' => 'Account created — check your email to verify your account.']);
    }

    public function loginAction(){

        if (empty($this->post['u_name'])) {
            $this->jsonError('Username is required');
        }

        if (empty($this->post['p_word'])) {
            $this->jsonError('Password is required');
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'login', 15) >= 5
            || $this->loginAttemptsModel->count_recent_for((string) $this->post['u_name'], 'login', 15) >= 10) {   // per IP and per account
            $this->jsonError('Too many attempts. Please try again later.');
        }

        $user_account = $this->userModel->get_user_by_login($this->post['u_name']);
        if (!is_array($user_account) || count($user_account) !== 1
            || !password_verify($this->post['p_word'], $user_account[0]['p_word'])) {
            $this->loginAttemptsModel->record($ip, $this->post['u_name'], 'login');
            $this->jsonError('Invalid username or password');
        }

        $user = $user_account[0];

        // Suspended (or deleted) accounts, and collaborators over the owner's seat limit, cannot sign in.
        $blocked = LoginGate::blocked($user);
        if ($blocked === 'suspended') {
            $this->loginAttemptsModel->record($ip, $this->post['u_name'], 'login');
            $this->jsonError(LoginGate::SUSPENDED_MESSAGE);
        }
        if ($blocked === 'seat') {
            $this->jsonError(Plan::SEAT_LOCKED_MESSAGE);
        }

        // Hard gate: an unconfirmed email cannot sign in.
        if ((int) ($user['email_verified'] ?? 0) === 0) {
            $this->jsonError('Please verify your email before signing in. Check your inbox for the verification link.', ['unverified' => true]);
        }

        // MFA gate (a second factor defers the full login until verified), else the session.
        $done = LoginGate::finish($user);
        if (isset($done['mfa'])) {
            $this->jsonSuccess(['message' => 'Verification required', 'mfa_required' => true, 'methods' => $done['mfa']]);
        }

        $this->jsonSuccess(['message' => 'Login successful', 'reset_pw' => $done['reset_pw']]);
    }

    private function start_user_session(array $user): void{
        UserSession::start($user);
    }

    /** "Return to Admin": leave the account an admin signed in as, back to the admin's own session. */
    public function impersonate_stopAction(){
        $imp = UserSession::end_impersonation();
        if (!$imp) { $this->jsonError('You are not signed in as another user.'); }
        try {
            (new AuditModel())->record((int) $imp['id'], 'admin_impersonate_end', array('user_id' => (int) $imp['target_id']),
                array('message' => 'Returned to admin after ' . max(1, (int) round((time() - (int) $imp['since']) / 60)) . ' min'), $this->get_ip_address());
        } catch (\Throwable $e) { error_log('[impersonate] audit: ' . $e->getMessage()); }
        $this->jsonSuccess(['redirect' => (string) ($imp['return'] ?? '/admin')]);
    }

    public function logoutAction(){

        Main::do_logout();

        $this->jsonSuccess(['message' => 'You have been signed out']);
    }

    public function forgotAction(){

        if (empty($this->post['u_name'])) {
            $this->jsonError('Username is required');
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'forgot', 15) >= 5) {
            $this->jsonError('Too many attempts. Please try again later.');
        }
        $this->loginAttemptsModel->record($ip, $this->post['u_name'], 'forgot');

        $user_account = $this->userModel->get_user_by_login($this->post['u_name']);
        if (is_array($user_account) && count($user_account) === 1) {
            $user  = $user_account[0];
            $token = $this->userModel->set_reset_token($user['user_id']);

            if (!empty($user['user_email'])) {
                $reset_link = Main::get_base_domain() . '/account/reset?token=' . urlencode($token);
                $to_name    = trim($user['first_name'] . ' ' . $user['last_name']);
                $this->notificationsModel->send_password_reset_email($user['user_email'], $to_name, $reset_link);
            }
        }

        $this->jsonSuccess(['message' => 'If the account exists, a reset link has been sent']);
    }

    public function resetAction(){

        if (empty($this->post['reset_token'])) {
            $this->jsonError('Reset token is required');
        }

        if (empty($this->post['p_word'])) {
            $this->jsonError('Password is required');
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'reset', 15) >= 5) {
            $this->jsonError('Too many attempts. Please try again later.');
        }

        $pw_error = $this->password_complexity_error($this->post['p_word']);
        if ($pw_error !== '') {
            $this->jsonError((string) ($pw_error));
        }

        $user_account = $this->userModel->get_user_by_reset_token($this->post['reset_token']);
        if (!is_array($user_account) || count($user_account) !== 1) {
            $this->loginAttemptsModel->record($ip, null, 'reset');
            $this->jsonError('This reset link is invalid or has expired');
        }

        $user = $user_account[0];
        $enc_p_word = password_hash($this->post['p_word'], PASSWORD_DEFAULT);
        $this->userModel->change_password($user['user_id'], $enc_p_word);

        $this->notify((int) $user['user_id'], 'security', 'Password changed', 'Your password was just changed. If this was not you, reset it now and contact support.', '/account/settings?section=security', 'fa-key', false, true);
        $this->jsonSuccess(['message' => 'Your password has been updated']);
    }

    /**
     * Confirm a new account's email from the link in the verification email. A good link proves the address,
     * so it also signs them in (same checks as a password login) and says where to go next (`redirect`).
     * `expired` in a failed reply means the page may offer a resend; `already_used` means sign in instead.
     */
    public function verify_emailAction(){
        $response = ['success' => false, 'message' => 'This verification link is invalid or has expired.', 'expired' => true];

        $token = (string) ($this->post['token'] ?? '');
        if ($token === '') {
            echo json_encode($response);
            exit;
        }

        $rows = $this->userModel->get_user_by_verify_token($token);
        if (!is_array($rows) || count($rows) !== 1) {
            error_log('[verify_email] token not found (len=' . strlen($token) . ', ip=' . $this->get_ip_address() . ')');
            echo json_encode($response);
            exit;
        }
        $user = $rows[0];

        // A used link never signs anyone in again: a scanner or an earlier click already confirmed it.
        if ((int) ($user['email_verified'] ?? 0) === 1) {
            $this->jsonError('This link was already used. Sign in to continue.', ['already_used' => true]);
        }

        $expires = (string) ($user['email_verify_expires'] ?? '');
        if ($expires === '' || strtotime($expires) < time()) {
            error_log('[verify_email] token expired for user_id=' . (int) $user['user_id'] . ' (expires=' . $expires . ')');
            $this->jsonError('This link has expired. Enter your email and we will send a new one.', ['expired' => true]);
        }

        $this->userModel->mark_email_verified((int) $user['user_id']);
        $user['email_verified'] = 1;

        // Sign them in, the way a password login would.
        $blocked = LoginGate::blocked($user);
        if ($blocked === 'suspended') {
            $this->jsonError(LoginGate::SUSPENDED_MESSAGE);
        }
        if ($blocked === 'seat') {
            $this->jsonError(Plan::SEAT_LOCKED_MESSAGE);
        }

        $return = CustomDomains::safe_path((string) Session::get('signup_return'));   // set by registerAction in this browser, if it is the same one
        Session::destroyValue('signup_return');
        $done = LoginGate::finish($user);
        if (isset($done['mfa'])) {
            $m = array_keys(array_filter($done['mfa']));
            $this->jsonSuccess(['message' => 'Your email is verified. Enter your verification code to finish signing in.', 'mfa_required' => true, 'methods' => $done['mfa'], 'redirect' => '/?auth=mfa&m=' . implode(',', $m)]);
        }

        $role     = (int) ($user['role_id'] ?? 0) > 0 ? $this->userModel->get_role_name_by_id((int) $user['role_id']) : '';
        $plan     = (string) ($user['signup_plan'] ?? '');   // ?plan= from signup rides along to /setup
        $redirect = (int) $done['reset_pw'] === 1 ? '/account/force_reset' : ($role === 'Creator' ? '/setup' . ($plan !== '' ? '?plan=' . rawurlencode($plan) : '') : $return);
        $this->jsonSuccess(['message' => 'Your email is verified. Signing you in…', 'redirect' => $redirect]);
    }

    /** Resend the verification email for an unconfirmed account. Generic response (no account enumeration). */
    public function resend_verificationAction(){
        $response = ['success' => true, 'message' => 'If that account exists and is unverified, a new link has been sent.'];

        $identifier = (string) ($this->post['u_name'] ?? '');
        if ($identifier === '') {
            echo json_encode($response);
            exit;
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'verify_resend', 15) >= 5) {
            $response['message'] = 'Too many attempts. Please try again later.';
            $response['success'] = false;
            echo json_encode($response);
            exit;
        }
        $this->loginAttemptsModel->record($ip, $identifier, 'verify_resend');

        $rows = $this->userModel->get_user_by_login($identifier);
        if (is_array($rows) && count($rows) === 1 && (int) ($rows[0]['email_verified'] ?? 0) === 0) {
            $user  = $rows[0];
            $token = $this->userModel->set_email_verify_token((int) $user['user_id']);
            if (!empty($user['user_email'])) {
                $verify_link = Main::get_base_domain() . '/account/verify?token=' . urlencode($token);
                $to_name     = trim($user['first_name'] . ' ' . $user['last_name']);
                $this->notificationsModel->send_verification_email($user['user_email'], $to_name, $verify_link, (string) $user['u_name']);
            }
        }

        echo json_encode($response);
        exit;
    }

    public function change_passwordAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        if (empty($this->post['current_password'])) {
            $this->jsonError('Current password is required');
        }

        if (empty($this->post['p_word'])) {
            $this->jsonError('New password is required');
        }

        if (empty($this->post['confirm_password'])) {
            $this->jsonError('Confirm password is required');
        }

        if ($this->post['p_word'] !== $this->post['confirm_password']) {
            $this->jsonError('New passwords do not match');
        }

        $pw_error = $this->password_complexity_error($this->post['p_word']);
        if ($pw_error !== '') {
            $this->jsonError((string) ($pw_error));
        }

        $user_account = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user_account) || count($user_account) !== 1) {
            $this->jsonError('User not found');
        }

        if (!password_verify($this->post['current_password'], $user_account[0]['p_word'])) {
            $this->jsonError('Your current password is incorrect');
        }

        $enc_p_word = password_hash($this->post['p_word'], PASSWORD_DEFAULT);
        $this->userModel->change_password(Session::get('user_id'), $enc_p_word, Session::get('user_id'));
        Session::set('reset_pw', 0);
        Session::set('p_word', null);
        Session::set('pw_fp', hash('sha256', $enc_p_word));   // keep THIS session; every other session is signed out on its next check

        $this->jsonSuccess(['message' => 'Your password has been updated']);
    }

    /** Begin authenticator-app enrollment: mint a pending secret, return its otpauth URI. */
    public function mfa_totp_beginAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $secret = TotpService::generate_secret();
        Session::set('mfa_totp_pending', $secret);

        $label = (string) Session::get('user_email');
        $uri   = TotpService::otpauth_uri($secret, $label !== '' ? $label : (string) Session::get('u_name'), Main::site_name());

        $this->jsonSuccess(['message' => 'Something went wrong', 'secret' => $secret, 'otpauth_uri' => $uri]);
    }

    /** Confirm authenticator enrollment by verifying a code against the pending secret. */
    public function mfa_totp_confirmAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $pending = (string) Session::get('mfa_totp_pending');
        if ($pending === '') {
            $this->jsonError('Start the setup again');
        }

        if (!TotpService::verify($pending, (string) ($this->post['code'] ?? ''))) {
            $this->jsonError('That code is incorrect. Check your authenticator app and try again.');
        }

        $user_id = (int) Session::get('user_id');
        $first   = empty(Session::get('mfa_totp_enabled')) && empty(Session::get('mfa_email_enabled'));

        $mfa = new MfaModel();
        $mfa->set_totp($user_id, $pending);
        Session::set('mfa_totp_pending', null);
        Session::set('mfa_totp_enabled', 1);
        Session::set('mfa_totp_secret', $pending);

        $this->notify($user_id, 'security', 'Two-factor authentication enabled', 'An authenticator app now protects your sign-in.', '/account/settings?section=security', 'fa-shield-halved', false, true);
        $this->jsonSuccess(['message' => 'Authenticator app enabled', 'backup_codes' => $first ? $mfa->generate_backup_codes($user_id) : []]);
    }

    /** Disable the authenticator app (requires the current password). */
    public function mfa_totp_disableAction(){
        echo json_encode($this->disable_mfa_method('totp'));
        exit;
    }

    /** Email a code to confirm the user controls their inbox before enabling email MFA. */
    public function mfa_email_send_enrollAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $user_account = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user_account) || count($user_account) !== 1) {
            $this->jsonError('Account not found');
        }
        $user = $user_account[0];

        $mfa  = new MfaModel();
        $code = $mfa->issue_email_code((int) $user['user_id'], 'enroll');
        if (!empty($user['user_email'])) {
            $to_name = trim($user['first_name'] . ' ' . $user['last_name']);
            $this->notificationsModel->send_mfa_code_email($user['user_email'], $to_name, $code);
        }

        $this->jsonSuccess(['message' => 'We sent a code to your email']);
    }

    /** Confirm the emailed enrollment code and turn on email MFA. */
    public function mfa_email_confirmAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $user_id = (int) Session::get('user_id');
        $mfa     = new MfaModel();

        if (!$mfa->verify_email_code($user_id, (string) ($this->post['code'] ?? ''), 'enroll')) {
            $this->jsonError('That code is incorrect or expired');
        }

        $first = empty(Session::get('mfa_totp_enabled')) && empty(Session::get('mfa_email_enabled'));
        $mfa->set_email_enabled($user_id, true);
        Session::set('mfa_email_enabled', 1);

        $this->notify($user_id, 'security', 'Two-factor authentication enabled', 'Email codes now protect your sign-in.', '/account/settings?section=security', 'fa-shield-halved', false, true);
        $this->jsonSuccess(['message' => 'Email verification enabled', 'backup_codes' => $first ? $mfa->generate_backup_codes($user_id) : []]);
    }

    /** Disable email MFA (requires the current password). */
    public function mfa_email_disableAction(){
        echo json_encode($this->disable_mfa_method('email'));
        exit;
    }

    /** Regenerate backup codes (requires the current password); returns the new set once. */
    public function mfa_regenerate_backup_codesAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $user_account = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user_account) || count($user_account) !== 1) {
            $this->jsonError('Account not found');
        }
        $user = $user_account[0];

        if (empty($user['mfa_totp_enabled']) && empty($user['mfa_email_enabled'])) {
            $this->jsonError('Enable two-factor authentication first');
        }

        if (empty($this->post['current_password']) || !password_verify($this->post['current_password'], $user['p_word'])) {
            $this->jsonError('Your current password is incorrect');
        }

        $mfa = new MfaModel();
        $this->jsonSuccess(['message' => 'New backup codes generated', 'backup_codes' => $mfa->generate_backup_codes((int) $user['user_id'])]);
    }

    /** Complete a login that was gated by MFA. Reads the pending user from the session. */
    public function mfa_verifyAction(){

        $pending = (int) Session::get('mfa_pending_user_id');
        if ($pending <= 0) {
            $this->jsonError('Your session has expired. Please sign in again.');
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'mfa', 15) >= 8
            || $this->loginAttemptsModel->count_recent_for('uid:' . $pending, 'mfa', 15) >= 8) {   // per IP and per account
            $this->jsonError('Too many attempts. Please try again later.');
        }

        $user_account = $this->userModel->get_user_by_id($pending);
        if (!is_array($user_account) || count($user_account) !== 1) {
            $this->jsonError('Please sign in again.');
        }
        $user = $user_account[0];

        $method = $this->post['method'] ?? '';
        $code   = (string) ($this->post['code'] ?? '');
        $mfa    = new MfaModel();
        $ok     = false;

        if ($method === 'totp' && !empty($user['mfa_totp_enabled'])) {
            $step = TotpService::matched_step((string) $user['mfa_totp_secret'], $code);
            $ok = $step !== null && $mfa->claim_totp_step($pending, $step);   // each code works once
        } elseif ($method === 'email' && !empty($user['mfa_email_enabled'])) {
            $ok = $mfa->verify_email_code($pending, $code, 'login');
        } elseif ($method === 'backup') {
            $ok = $mfa->verify_and_consume_backup($pending, $code);
        }

        if (!$ok) {
            $this->loginAttemptsModel->record($ip, 'uid:' . $pending, 'mfa');
            $this->jsonError('That code is incorrect or expired');
        }

        Session::set('mfa_pending_user_id', null);
        session_regenerate_id(true);
        $this->start_user_session($user);

        $this->jsonSuccess(['message' => 'Login successful', 'reset_pw' => (int) ($user['reset_pw'] ?? 0)]);
    }

    /** (Re)send an email login code for the pending MFA login. */
    public function mfa_send_login_codeAction(){

        $pending = (int) Session::get('mfa_pending_user_id');
        if ($pending <= 0) {
            $this->jsonError('Your session has expired. Please sign in again.');
        }

        $user_account = $this->userModel->get_user_by_id($pending);
        if (!is_array($user_account) || count($user_account) !== 1) {
            $this->jsonError('Please sign in again.');
        }
        $user = $user_account[0];

        if (empty($user['mfa_email_enabled'])) {
            $this->jsonError('Email verification is not enabled for this account');
        }
        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent_for('uid:' . $pending, 'mfa_send', 15) >= 5
            || $this->loginAttemptsModel->count_recent($ip, 'mfa_send', 15) >= 10) {
            $this->jsonError('Too many codes sent. Wait a few minutes and try again.');
        }
        $this->loginAttemptsModel->record($ip, 'uid:' . $pending, 'mfa_send');

        $this->issue_and_send_login_code($user);
        $this->jsonSuccess(['message' => 'We sent a code to your email']);
    }

    private function password_complexity_error(string $p): string{
        if (strlen($p) < 8) {
            return 'Password must be at least 8 characters';
        }
        if (!preg_match('/[A-Z]/', $p)) {
            return 'Password must include an uppercase letter';
        }
        if (!preg_match('/[a-z]/', $p)) {
            return 'Password must include a lowercase letter';
        }
        if (!preg_match('/[0-9]/', $p)) {
            return 'Password must include a number';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $p)) {
            return 'Password must include a symbol';
        }
        return '';
    }

    /** Issue and email a login verification code for a user row. */
    private function issue_and_send_login_code(array $user): void{
        LoginGate::send_login_code($user);
    }

    /** Shared disable path for a method; re-authenticates with the current password. */
    /** Settings > Security: Google sign-in off for this account. Needs the current password, like disabling a second factor. */
    public function google_disconnectAction(){
        $uid = (int) Session::get('user_id');
        if ($uid <= 0) { $this->jsonError('Not authorized'); }
        $rows = $this->userModel->get_user_by_id($uid);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$user) { $this->jsonError('Account not found'); }
        if ((string) ($this->post['current_password'] ?? '') === '' || !password_verify((string) $this->post['current_password'], (string) $user['p_word'])) {
            $this->jsonError('Your current password is incorrect');
        }
        $this->userModel->unlink_google($uid);
        $this->jsonSuccess(['message' => 'Google disconnected']);
    }

    private function disable_mfa_method(string $method): array{
        $response = ['success' => false, 'message' => 'Something went wrong'];

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            return $response;
        }

        $user_account = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user_account) || count($user_account) !== 1) {
            $response['message'] = 'Account not found';
            return $response;
        }
        $user = $user_account[0];

        if (empty($this->post['current_password']) || !password_verify($this->post['current_password'], $user['p_word'])) {
            $response['message'] = 'Your current password is incorrect';
            return $response;
        }

        $user_id = (int) $user['user_id'];
        $mfa     = new MfaModel();

        if ($method === 'totp') {
            $mfa->disable_totp($user_id);
            Session::set('mfa_totp_enabled', 0);
            Session::set('mfa_totp_secret', null);
            $still_on = !empty($user['mfa_email_enabled']);
        } else {
            $mfa->set_email_enabled($user_id, false);
            Session::set('mfa_email_enabled', 0);
            $still_on = !empty($user['mfa_totp_enabled']);
        }

        // No factors left → the backup codes have nothing to protect.
        if (!$still_on) {
            $mfa->clear_backup_codes($user_id);
        }

        $this->notify($user_id, 'security', 'Two-factor method turned off', ($method === 'totp' ? 'Your authenticator app' : 'Email codes') . ' no longer protect your sign-in.' . ($still_on ? '' : ' Two-factor authentication is now off.'), '/account/settings?section=security', 'fa-shield-halved', false, true);
        $response['success'] = true;
        $response['message'] = 'Two-factor method disabled';
        return $response;
    }

}
