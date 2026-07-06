<?php
class ApiController extends Controller {

    public $protected = 1;
    private $userModel;
    private $notificationsModel;
    private $loginAttemptsModel;
    private $billingModel;

    public function __construct(){
        parent::__construct();

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !CSRF::validate()) {
            echo json_encode(array('success' => false, 'message' => 'Invalid or expired request token'));
            exit;
        }

        $this->userModel = new UsersModel();
        $this->notificationsModel = new NotificationsModel();
        $this->loginAttemptsModel = new LoginAttemptsModel();
        $this->billingModel = new BillingModel();
    }

    private function password_complexity_error($p){
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

    public function registerAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty($this->post['first_name'])) {
            $response['message'] = 'First name is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['last_name'])) {
            $response['message'] = 'Last name is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['user_email']) || !filter_var($this->post['user_email'], FILTER_VALIDATE_EMAIL)) {
            $response['message'] = 'A valid email is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['p_word'])) {
            $response['message'] = 'Password is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['p_word_confirm']) || $this->post['p_word'] !== $this->post['p_word_confirm']) {
            $response['message'] = 'Passwords do not match';
            echo json_encode($response);
            exit;
        }

        $pw_error = $this->password_complexity_error($this->post['p_word']);
        if ($pw_error !== '') {
            $response['message'] = $pw_error;
            echo json_encode($response);
            exit;
        }

        if ($this->userModel->email_exists($this->post['user_email'])) {
            $response['message'] = 'Could not create an account with those details';
            echo json_encode($response);
            exit;
        }

        $u_name = $this->userModel->generate_unique_username($this->post['first_name'], $this->post['last_name']);
        $enc_p_word = password_hash($this->post['p_word'], PASSWORD_DEFAULT);

        $this->userModel->create_user(
            $u_name,
            $enc_p_word,
            $this->post['first_name'],
            $this->post['last_name'],
            $this->post['user_email'],
        );

        $response['success']  = true;
        $response['message']  = 'Account created';
        $response['reset_pw'] = 0;
        echo json_encode($response);
        exit;
    }

    public function loginAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty($this->post['u_name'])) {
            $response['message'] = 'Username is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['p_word'])) {
            $response['message'] = 'Password is required';
            echo json_encode($response);
            exit;
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'login', 15) >= 5) {
            $response['message'] = 'Too many attempts. Please try again later.';
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_username($this->post['u_name']);
        if (!is_array($user_account) || count($user_account) !== 1
            || !password_verify($this->post['p_word'], $user_account[0]['p_word'])) {
            $this->loginAttemptsModel->record($ip, $this->post['u_name'], 'login');
            $response['message'] = 'Invalid username or password';
            echo json_encode($response);
            exit;
        }

        $user = $user_account[0];

        session_regenerate_id(true);

        // MFA gate: if a second factor is enabled, defer full login until verified.
        if (!empty($user['mfa_totp_enabled']) || !empty($user['mfa_email_enabled'])) {
            Session::set('mfa_pending_user_id', (int) $user['user_id']);
            $has_totp = !empty($user['mfa_totp_enabled']);
            // No authenticator app → email a code straight away.
            if (!$has_totp && !empty($user['mfa_email_enabled'])) {
                $this->issue_and_send_login_code($user);
            }
            $response['success']      = true;
            $response['message']      = 'Verification required';
            $response['mfa_required'] = true;
            $response['methods']      = array('totp' => $has_totp, 'email' => !empty($user['mfa_email_enabled']));
            echo json_encode($response);
            exit;
        }

        foreach ($user as $key => $value) {
            Session::set($key, $value);
        }

        $response['success']  = true;
        $response['message']  = 'Login successful';
        $response['reset_pw'] = (int) ($user['reset_pw'] ?? 0);
        echo json_encode($response);
        exit;
    }

    public function logoutAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        Main::do_logout();

        $response['success'] = true;
        $response['message'] = 'You have been signed out';
        echo json_encode($response);
        exit;
    }

    public function forgotAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty($this->post['u_name'])) {
            $response['message'] = 'Username is required';
            echo json_encode($response);
            exit;
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'forgot', 15) >= 5) {
            $response['message'] = 'Too many attempts. Please try again later.';
            echo json_encode($response);
            exit;
        }
        $this->loginAttemptsModel->record($ip, $this->post['u_name'], 'forgot');

        $user_account = $this->userModel->get_user_by_username($this->post['u_name']);
        if (is_array($user_account) && count($user_account) === 1) {
            $user  = $user_account[0];
            $token = $this->userModel->set_reset_token($user['user_id']);

            if (!empty($user['user_email'])) {
                $reset_link = Main::get_base_domain() . '/account/reset?token=' . urlencode($token);
                $to_name    = trim($user['first_name'] . ' ' . $user['last_name']);
                $this->notificationsModel->send_password_reset_email($user['user_email'], $to_name, $reset_link);
            }
        }

        $response['success'] = true;
        $response['message'] = 'If the account exists, a reset link has been sent';
        echo json_encode($response);
        exit;
    }

    public function resetAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty($this->post['reset_token'])) {
            $response['message'] = 'Reset token is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['p_word'])) {
            $response['message'] = 'Password is required';
            echo json_encode($response);
            exit;
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'reset', 15) >= 5) {
            $response['message'] = 'Too many attempts. Please try again later.';
            echo json_encode($response);
            exit;
        }

        $pw_error = $this->password_complexity_error($this->post['p_word']);
        if ($pw_error !== '') {
            $response['message'] = $pw_error;
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_reset_token($this->post['reset_token']);
        if (!is_array($user_account) || count($user_account) !== 1) {
            $this->loginAttemptsModel->record($ip, null, 'reset');
            $response['message'] = 'This reset link is invalid or has expired';
            echo json_encode($response);
            exit;
        }

        $user = $user_account[0];
        $enc_p_word = password_hash($this->post['p_word'], PASSWORD_DEFAULT);
        $this->userModel->change_password($user['user_id'], $enc_p_word);

        $response['success'] = true;
        $response['message'] = 'Your password has been updated';
        echo json_encode($response);
        exit;
    }

    public function change_passwordAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['current_password'])) {
            $response['message'] = 'Current password is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['p_word'])) {
            $response['message'] = 'New password is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['confirm_password'])) {
            $response['message'] = 'Confirm password is required';
            echo json_encode($response);
            exit;
        }

        if ($this->post['p_word'] !== $this->post['confirm_password']) {
            $response['message'] = 'New passwords do not match';
            echo json_encode($response);
            exit;
        }

        $pw_error = $this->password_complexity_error($this->post['p_word']);
        if ($pw_error !== '') {
            $response['message'] = $pw_error;
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user_account) || count($user_account) !== 1) {
            $response['message'] = 'User not found';
            echo json_encode($response);
            exit;
        }

        if (!password_verify($this->post['current_password'], $user_account[0]['p_word'])) {
            $response['message'] = 'Your current password is incorrect';
            echo json_encode($response);
            exit;
        }

        $enc_p_word = password_hash($this->post['p_word'], PASSWORD_DEFAULT);
        $this->userModel->change_password(Session::get('user_id'), $enc_p_word, Session::get('user_id'));
        Session::set('reset_pw', 0);

        $response['success'] = true;
        $response['message'] = 'Your password has been updated';
        echo json_encode($response);
        exit;
    }

    /* ================================================================
     * Multi-factor authentication (MFA)
     * ================================================================ */

    /** Issue and email a login verification code for a user row. */
    private function issue_and_send_login_code($user){
        $mfa  = new MfaModel();
        $code = $mfa->issue_email_code((int) $user['user_id'], 'login');
        if (!empty($user['user_email'])) {
            $to_name = trim($user['first_name'] . ' ' . $user['last_name']);
            $this->notificationsModel->send_mfa_code_email($user['user_email'], $to_name, $code);
        }
    }

    /** Begin authenticator-app enrollment: mint a pending secret, return its otpauth URI. */
    public function mfa_totp_beginAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $secret = TotpService::generate_secret();
        Session::set('mfa_totp_pending', $secret);

        $label = (string) Session::get('user_email');
        $uri   = TotpService::otpauth_uri($secret, $label !== '' ? $label : (string) Session::get('u_name'), Main::site_name());

        $response['success']     = true;
        $response['secret']      = $secret;
        $response['otpauth_uri'] = $uri;
        echo json_encode($response);
        exit;
    }

    /** Confirm authenticator enrollment by verifying a code against the pending secret. */
    public function mfa_totp_confirmAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $pending = (string) Session::get('mfa_totp_pending');
        if ($pending === '') {
            $response['message'] = 'Start the setup again';
            echo json_encode($response);
            exit;
        }

        if (!TotpService::verify($pending, (string) ($this->post['code'] ?? ''))) {
            $response['message'] = 'That code is incorrect. Check your authenticator app and try again.';
            echo json_encode($response);
            exit;
        }

        $user_id = (int) Session::get('user_id');
        $first   = empty(Session::get('mfa_totp_enabled')) && empty(Session::get('mfa_email_enabled'));

        $mfa = new MfaModel();
        $mfa->set_totp($user_id, $pending);
        Session::set('mfa_totp_pending', null);
        Session::set('mfa_totp_enabled', 1);
        Session::set('mfa_totp_secret', $pending);

        $response['success']      = true;
        $response['message']      = 'Authenticator app enabled';
        $response['backup_codes'] = $first ? $mfa->generate_backup_codes($user_id) : array();
        echo json_encode($response);
        exit;
    }

    /** Disable the authenticator app (requires the current password). */
    public function mfa_totp_disableAction(){
        echo json_encode($this->disable_mfa_method('totp'));
        exit;
    }

    /** Email a code to confirm the user controls their inbox before enabling email MFA. */
    public function mfa_email_send_enrollAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user_account) || count($user_account) !== 1) {
            $response['message'] = 'Account not found';
            echo json_encode($response);
            exit;
        }
        $user = $user_account[0];

        $mfa  = new MfaModel();
        $code = $mfa->issue_email_code((int) $user['user_id'], 'enroll');
        if (!empty($user['user_email'])) {
            $to_name = trim($user['first_name'] . ' ' . $user['last_name']);
            $this->notificationsModel->send_mfa_code_email($user['user_email'], $to_name, $code);
        }

        $response['success'] = true;
        $response['message'] = 'We sent a code to your email';
        echo json_encode($response);
        exit;
    }

    /** Confirm the emailed enrollment code and turn on email MFA. */
    public function mfa_email_confirmAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $user_id = (int) Session::get('user_id');
        $mfa     = new MfaModel();

        if (!$mfa->verify_email_code($user_id, (string) ($this->post['code'] ?? ''), 'enroll')) {
            $response['message'] = 'That code is incorrect or expired';
            echo json_encode($response);
            exit;
        }

        $first = empty(Session::get('mfa_totp_enabled')) && empty(Session::get('mfa_email_enabled'));
        $mfa->set_email_enabled($user_id, true);
        Session::set('mfa_email_enabled', 1);

        $response['success']      = true;
        $response['message']      = 'Email verification enabled';
        $response['backup_codes'] = $first ? $mfa->generate_backup_codes($user_id) : array();
        echo json_encode($response);
        exit;
    }

    /** Disable email MFA (requires the current password). */
    public function mfa_email_disableAction(){
        echo json_encode($this->disable_mfa_method('email'));
        exit;
    }

    /** Shared disable path for a method; re-authenticates with the current password. */
    private function disable_mfa_method($method){
        $response = array('success' => false, 'message' => 'Something went wrong');

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

        $response['success'] = true;
        $response['message'] = 'Two-factor method disabled';
        return $response;
    }

    /** Regenerate backup codes (requires the current password); returns the new set once. */
    public function mfa_regenerate_backup_codesAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user_account) || count($user_account) !== 1) {
            $response['message'] = 'Account not found';
            echo json_encode($response);
            exit;
        }
        $user = $user_account[0];

        if (empty($user['mfa_totp_enabled']) && empty($user['mfa_email_enabled'])) {
            $response['message'] = 'Enable two-factor authentication first';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['current_password']) || !password_verify($this->post['current_password'], $user['p_word'])) {
            $response['message'] = 'Your current password is incorrect';
            echo json_encode($response);
            exit;
        }

        $mfa = new MfaModel();
        $response['success']      = true;
        $response['message']      = 'New backup codes generated';
        $response['backup_codes'] = $mfa->generate_backup_codes((int) $user['user_id']);
        echo json_encode($response);
        exit;
    }

    /** Complete a login that was gated by MFA. Reads the pending user from the session. */
    public function mfa_verifyAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        $pending = (int) Session::get('mfa_pending_user_id');
        if ($pending <= 0) {
            $response['message'] = 'Your session has expired. Please sign in again.';
            echo json_encode($response);
            exit;
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'mfa', 15) >= 8) {
            $response['message'] = 'Too many attempts. Please try again later.';
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_id($pending);
        if (!is_array($user_account) || count($user_account) !== 1) {
            $response['message'] = 'Please sign in again.';
            echo json_encode($response);
            exit;
        }
        $user = $user_account[0];

        $method = $this->post['method'] ?? '';
        $code   = (string) ($this->post['code'] ?? '');
        $mfa    = new MfaModel();
        $ok     = false;

        if ($method === 'totp' && !empty($user['mfa_totp_enabled'])) {
            $ok = TotpService::verify((string) $user['mfa_totp_secret'], $code);
        } elseif ($method === 'email' && !empty($user['mfa_email_enabled'])) {
            $ok = $mfa->verify_email_code($pending, $code, 'login');
        } elseif ($method === 'backup') {
            $ok = $mfa->verify_and_consume_backup($pending, $code);
        }

        if (!$ok) {
            $this->loginAttemptsModel->record($ip, $user['u_name'], 'mfa');
            $response['message'] = 'That code is incorrect or expired';
            echo json_encode($response);
            exit;
        }

        Session::set('mfa_pending_user_id', null);
        session_regenerate_id(true);
        foreach ($user as $key => $value) {
            Session::set($key, $value);
        }

        $response['success']  = true;
        $response['message']  = 'Login successful';
        $response['reset_pw'] = (int) ($user['reset_pw'] ?? 0);
        echo json_encode($response);
        exit;
    }

    /** (Re)send an email login code for the pending MFA login. */
    public function mfa_send_login_codeAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        $pending = (int) Session::get('mfa_pending_user_id');
        if ($pending <= 0) {
            $response['message'] = 'Your session has expired. Please sign in again.';
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_id($pending);
        if (!is_array($user_account) || count($user_account) !== 1) {
            $response['message'] = 'Please sign in again.';
            echo json_encode($response);
            exit;
        }
        $user = $user_account[0];

        if (empty($user['mfa_email_enabled'])) {
            $response['message'] = 'Email verification is not enabled for this account';
            echo json_encode($response);
            exit;
        }

        $this->issue_and_send_login_code($user);
        $response['success'] = true;
        $response['message'] = 'We sent a code to your email';
        echo json_encode($response);
        exit;
    }

    public function update_profileAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['first_name'])) {
            $response['message'] = 'First name is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['last_name'])) {
            $response['message'] = 'Last name is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['user_email']) || !filter_var($this->post['user_email'], FILTER_VALIDATE_EMAIL)) {
            $response['message'] = 'A valid email is required';
            echo json_encode($response);
            exit;
        }

        if (strtolower($this->post['user_email']) !== strtolower((string) Session::get('user_email')) && $this->userModel->email_exists($this->post['user_email'])) {
            $response['message'] = 'That email is already in use';
            echo json_encode($response);
            exit;
        }

        $user_phone    = empty($this->post['user_phone']) ? '' : $this->post['user_phone'];
        $business_name = empty($this->post['business_name']) ? '' : $this->post['business_name'];
        $website_url   = empty($this->post['website_url']) ? '' : $this->post['website_url'];

        $this->userModel->update_profile(
            (int) Session::get('user_id'),
            $this->post['first_name'],
            $this->post['last_name'],
            $this->post['user_email'],
            $user_phone,
            $business_name,
            $website_url,
            (int) Session::get('user_id')
        );

        Session::set('first_name', $this->post['first_name']);
        Session::set('last_name', $this->post['last_name']);
        Session::set('user_email', $this->post['user_email']);
        Session::set('user_phone', $user_phone);
        Session::set('business_name', $business_name);
        Session::set('website_url', $website_url);

        $response['success'] = true;
        $response['message'] = 'Your profile has been updated';
        echo json_encode($response);
        exit;
    }

    /** Change the signed-in user's username, enforcing the PRD 6.4 rules. */
    public function change_usernameAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $user_id = (int) Session::get('user_id');
        $new     = ltrim(strtolower(trim((string) ($this->post['u_name'] ?? ''))), '@');

        $user = $this->userModel->get_user_by_id($user_id);
        if (!is_array($user) || count($user) !== 1) {
            $response['message'] = 'Account not found';
            echo json_encode($response);
            exit;
        }
        $user    = $user[0];
        $current = (string) $user['u_name'];

        if ($new === strtolower($current)) {
            $response['message'] = 'That is already your username';
            echo json_encode($response);
            exit;
        }

        $usernameModel = new UsernameModel();

        if ($usernameModel->cooldown_days_left($user['u_name_changed_at']) > 0) {
            $response['message'] = 'You can change your username again on ' . $usernameModel->next_change_date($user['u_name_changed_at']);
            echo json_encode($response);
            exit;
        }

        $format_error = $usernameModel->validate_format($new);
        if ($format_error !== '') {
            $response['message'] = $format_error;
            echo json_encode($response);
            exit;
        }

        if ($usernameModel->is_taken($new, $user_id)) {
            $response['message'] = 'That username is not available';
            echo json_encode($response);
            exit;
        }

        $usernameModel->change($user_id, $current, $new);
        Session::set('u_name', $new);

        $response['success']          = true;
        $response['message']          = 'Your username has been updated';
        $response['u_name']           = $new;
        $response['next_change_date'] = $usernameModel->next_change_date(date('Y-m-d H:i:s'));
        echo json_encode($response);
        exit;
    }

    /** Follow a creator (auth required). */
    public function follow_creatorAction(){
        echo json_encode($this->set_follow(true));
        exit;
    }

    /** Unfollow a creator (auth required). */
    public function unfollow_creatorAction(){
        echo json_encode($this->set_follow(false));
        exit;
    }

    private function set_follow($following){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message']    = 'Sign in to follow creators';
            $response['need_login'] = true;
            return $response;
        }

        $user_id    = (int) Session::get('user_id');
        $creator_id = (int) ($this->post['creator_id'] ?? 0);

        if ($creator_id <= 0 || $creator_id === $user_id) {
            $response['message'] = 'You cannot follow this account';
            return $response;
        }

        $follows = new FollowsModel();
        if ($following) {
            $follows->follow($user_id, $creator_id);
        } else {
            $follows->unfollow($user_id, $creator_id);
        }

        $response['success']        = true;
        $response['message']        = $following ? 'Following' : 'Unfollowed';
        $response['following']      = $following;
        $response['follower_count'] = $follows->count_followers($creator_id);
        return $response;
    }

    /** Join a free membership tier (auth required). Paid tiers go through checkout. */
    public function join_free_planAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message']    = 'Sign in to join';
            $response['need_login'] = true;
            echo json_encode($response);
            exit;
        }

        $user_id = (int) Session::get('user_id');
        $plan    = (new CreatorPlansModel())->get_public((int) ($this->post['plan_id'] ?? 0));

        if (!$plan) {
            $response['message'] = 'That plan is no longer available';
            echo json_encode($response);
            exit;
        }
        if ((int) $plan['price_cents'] !== 0) {
            $response['message'] = 'This is a paid plan';
            echo json_encode($response);
            exit;
        }
        if ((int) $plan['user_id'] === $user_id) {
            $response['message'] = 'You cannot join your own plan';
            echo json_encode($response);
            exit;
        }

        (new CreatorSubscriptionsModel())->join_free($user_id, (int) $plan['user_id'], $plan);

        $response['success'] = true;
        $response['message'] = 'You joined ' . $plan['name'];
        $response['plan_id'] = (int) $plan['id'];
        echo json_encode($response);
        exit;
    }

    /** Start Stripe Checkout for a paid membership on the creator's connected account. */
    public function subscribe_planAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message']    = 'Sign in to subscribe';
            $response['need_login'] = true;
            echo json_encode($response);
            exit;
        }

        $user_id    = (int) Session::get('user_id');
        $plansModel = new CreatorPlansModel();
        $plan       = $plansModel->get_public((int) ($this->post['plan_id'] ?? 0));

        if (!$plan) {
            $response['message'] = 'That plan is no longer available';
            echo json_encode($response);
            exit;
        }
        if ((int) $plan['price_cents'] === 0) {
            $response['message'] = 'This is a free plan';
            echo json_encode($response);
            exit;
        }

        $creator_id = (int) $plan['user_id'];
        if ($creator_id === $user_id) {
            $response['message'] = 'You cannot subscribe to your own plan';
            echo json_encode($response);
            exit;
        }
        $subsModel = new CreatorSubscriptionsModel();
        if ($subsModel->is_subscribed_to_plan($user_id, (int) $plan['id'])) {
            $response['message'] = 'You are already a member of this plan';
            echo json_encode($response);
            exit;
        }
        // One paid membership per creator — switching tiers is done from My Subscriptions.
        if ($subsModel->has_active_paid_for_creator($user_id, $creator_id)) {
            $response['message'] = 'You already have a paid membership with this creator. Manage or switch it in My Subscriptions.';
            echo json_encode($response);
            exit;
        }

        // The creator must have a payments-enabled Connect account.
        $creator    = $this->userModel->get_user_by_id($creator_id);
        $creator    = (is_array($creator) && count($creator) === 1) ? $creator[0] : null;
        $connect_id = $creator ? (string) ($creator['stripe_connect_account_id'] ?? '') : '';
        if (!$creator || $connect_id === '' || empty(StripeService::connect_account_status($connect_id)['payouts_enabled'])) {
            $response['message'] = "This creator isn't set up to accept payments yet";
            echo json_encode($response);
            exit;
        }

        // Create the Stripe price on first subscribe, then cache it on the plan.
        $price_id = (string) ($plan['stripe_price_id'] ?? '');
        if ($price_id === '') {
            $created = StripeService::create_connect_price($connect_id, $plan);
            if (empty($created['price_id'])) {
                $response['message'] = 'Could not start checkout. Please try again.';
                echo json_encode($response);
                exit;
            }
            $price_id = $created['price_id'];
            $plansModel->set_stripe_ids((int) $plan['id'], $created['product_id'], $price_id);
        }

        $base    = Main::get_base_domain();
        $handle  = rawurlencode((string) $creator['u_name']);
        $success = $base . '/@' . $handle . '?sub=success&session_id={CHECKOUT_SESSION_ID}';
        $cancel  = $base . '/@' . $handle . '?sub=cancel';
        $meta    = array('subscriber_id' => (string) $user_id, 'creator_id' => (string) $creator_id, 'plan_id' => (string) $plan['id']);

        $session = StripeService::create_subscription_checkout(
            $connect_id, $price_id, Main::platform_fee_percent(), $success, $cancel, $meta, (string) Session::get('user_email')
        );
        if (empty($session['url'])) {
            $response['message'] = 'Could not start checkout. Please try again.';
            echo json_encode($response);
            exit;
        }

        $response['success'] = true;
        $response['url']     = $session['url'];
        echo json_encode($response);
        exit;
    }

    /** Cancel a creator membership. Free → immediate; paid → at period end (via Stripe). */
    public function cancel_creator_subscriptionAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $user_id = (int) Session::get('user_id');
        $subs    = new CreatorSubscriptionsModel();
        $sub     = $subs->get_owned($user_id, (int) ($this->post['id'] ?? 0));

        if (!$sub || $sub['status'] !== 'active') {
            $response['message'] = 'Subscription not found';
            echo json_encode($response);
            exit;
        }

        if (!empty($sub['is_free'])) {
            $subs->set_status($user_id, (int) $sub['id'], 'canceled');
            $response['state']   = 'canceled';
            $response['message'] = 'Membership canceled';
        } else {
            $creator = $this->userModel->get_user_by_id((int) $sub['creator_id']);
            $creator = (is_array($creator) && count($creator) === 1) ? $creator[0] : null;
            $connect = $creator ? (string) ($creator['stripe_connect_account_id'] ?? '') : '';
            if ($connect === '' || empty($sub['stripe_subscription_id'])
                || !StripeService::set_subscription_cancel_at_period_end($connect, $sub['stripe_subscription_id'], true)) {
                $response['message'] = 'Could not cancel. Please try again.';
                echo json_encode($response);
                exit;
            }
            $subs->set_cancel_at_period_end($user_id, (int) $sub['id'], true);
            $response['state']   = 'canceling';
            $response['message'] = 'Your membership will end at the current billing period';
        }

        $response['success'] = true;
        echo json_encode($response);
        exit;
    }

    /** Resume a creator membership: free → reactivate; paid → undo the scheduled cancellation. */
    public function reactivate_creator_subscriptionAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $user_id = (int) Session::get('user_id');
        $subs    = new CreatorSubscriptionsModel();
        $sub     = $subs->get_owned($user_id, (int) ($this->post['id'] ?? 0));

        if (!$sub) {
            $response['message'] = 'Subscription not found';
            echo json_encode($response);
            exit;
        }

        if (!empty($sub['is_free'])) {
            $plan = (new CreatorPlansModel())->get_public((int) $sub['plan_id']);
            if (!$plan || (int) $plan['price_cents'] !== 0) {
                $response['message'] = 'This plan is no longer available';
                echo json_encode($response);
                exit;
            }
            $subs->set_status($user_id, (int) $sub['id'], 'active');
        } else {
            if ($sub['status'] !== 'active') {
                $response['message'] = 'This membership has ended — subscribe again from the profile';
                echo json_encode($response);
                exit;
            }
            $creator = $this->userModel->get_user_by_id((int) $sub['creator_id']);
            $creator = (is_array($creator) && count($creator) === 1) ? $creator[0] : null;
            $connect = $creator ? (string) ($creator['stripe_connect_account_id'] ?? '') : '';
            if ($connect === '' || empty($sub['stripe_subscription_id'])
                || !StripeService::set_subscription_cancel_at_period_end($connect, $sub['stripe_subscription_id'], false)) {
                $response['message'] = 'Could not resume. Please try again.';
                echo json_encode($response);
                exit;
            }
            $subs->set_cancel_at_period_end($user_id, (int) $sub['id'], false);
        }

        $response['success'] = true;
        $response['state']   = 'active';
        $response['message'] = 'Membership resumed';
        echo json_encode($response);
        exit;
    }

    public function delete_my_accountAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $this->userModel->delete_account((int) Session::get('user_id'), (int) Session::get('user_id'));
        Main::do_logout();

        $response['success'] = true;
        $response['message'] = 'Your account has been deleted';
        echo json_encode($response);
        exit;
    }

    public function create_subscriptionAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['price_id'])) {
            $response['message'] = 'Please choose a plan';
            echo json_encode($response);
            exit;
        }

        try {
            $stripe  = StripeService::client();
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];

            $customer_id = $user['stripe_customer_id'] ?? '';
            if (empty($customer_id)) {
                $customer = $stripe->customers->create(array(
                    'email'    => $user['user_email'],
                    'name'     => trim($user['first_name'] . ' ' . $user['last_name']),
                    'metadata' => array('user_id' => (string) $user_id),
                ));
                $customer_id = $customer->id;
                $this->billingModel->set_customer_id($user_id, $customer_id);
            }

            $subscription = $stripe->subscriptions->create(array(
                'customer'         => $customer_id,
                'items'            => array(array('price' => $this->post['price_id'])),
                'payment_behavior' => 'default_incomplete',
                'payment_settings' => array('save_default_payment_method' => 'on_subscription'),
                'expand'           => array('latest_invoice.confirmation_secret'),
            ));

            $client_secret = $subscription->latest_invoice->confirmation_secret->client_secret ?? null;
            if (empty($client_secret)) {
                $response['message'] = 'Could not initialize payment';
                echo json_encode($response);
                exit;
            }

            $period_end = $subscription->items->data[0]->current_period_end ?? null;
            $this->billingModel->save_subscription($user_id, $subscription->id, $this->post['price_id'], $subscription->status, $period_end, $subscription->cancel_at_period_end ? 1 : 0);

            $response['success']         = true;
            $response['client_secret']   = $client_secret;
            $response['subscription_id'] = $subscription->id;
            $response['message']         = 'Subscription started';
            echo json_encode($response);
            exit;

        } catch (\Throwable $e) {
            error_log('[stripe] create_subscription: ' . $e->getMessage());
            $response['message'] = 'Could not start the subscription. Please try again.';
            echo json_encode($response);
            exit;
        }
    }

    public function sync_subscriptionAction(){

        $response = array('success' => false, 'message' => 'Something went wrong', 'status' => '');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        try {
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];
            $sub_id  = $user['stripe_subscription_id'] ?? '';

            if (empty($sub_id)) {
                $response['success'] = true;
                $response['status']  = '';
                echo json_encode($response);
                exit;
            }

            $stripe       = StripeService::client();
            $subscription = $stripe->subscriptions->retrieve($sub_id);
            $price_id     = $subscription->items->data[0]->price->id ?? ($user['stripe_price_id'] ?? '');
            $period_end   = $subscription->items->data[0]->current_period_end ?? null;

            $this->billingModel->save_subscription($user_id, $subscription->id, $price_id, $subscription->status, $period_end, $subscription->cancel_at_period_end ? 1 : 0);

            $response['success'] = true;
            $response['status']  = $subscription->status;
            $response['message'] = 'Subscription updated';
            echo json_encode($response);
            exit;

        } catch (\Throwable $e) {
            error_log('[stripe] sync_subscription: ' . $e->getMessage());
            $response['message'] = 'Could not refresh subscription';
            echo json_encode($response);
            exit;
        }
    }

    public function cancel_subscriptionAction(){
        echo json_encode($this->set_cancel_at_period_end(true, 'Your subscription will cancel at the end of the period'));
        exit;
    }

    public function resume_subscriptionAction(){
        echo json_encode($this->set_cancel_at_period_end(false, 'Your subscription has been resumed'));
        exit;
    }

    public function cancel_now_subscriptionAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        try {
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];
            $sub_id  = $user['stripe_subscription_id'] ?? '';

            if (empty($sub_id)) {
                $response['message'] = 'No active subscription';
                echo json_encode($response);
                exit;
            }

            StripeService::client()->subscriptions->cancel($sub_id);
            $this->billingModel->clear_subscription($user_id);

            $response['success'] = true;
            $response['message'] = 'Your subscription has been canceled';
            echo json_encode($response);
            exit;

        } catch (\Throwable $e) {
            error_log('[stripe] cancel_now_subscription: ' . $e->getMessage());
            $response['message'] = 'Could not cancel the subscription. Please try again.';
            echo json_encode($response);
            exit;
        }
    }

    private function set_cancel_at_period_end($cancel, $success_message){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            return $response;
        }

        try {
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];
            $sub_id  = $user['stripe_subscription_id'] ?? '';

            if (empty($sub_id)) {
                $response['message'] = 'No active subscription';
                return $response;
            }

            $stripe       = StripeService::client();
            $subscription = $stripe->subscriptions->update($sub_id, array('cancel_at_period_end' => (bool) $cancel));
            $price_id     = $subscription->items->data[0]->price->id ?? ($user['stripe_price_id'] ?? '');
            $period_end   = $subscription->items->data[0]->current_period_end ?? null;

            $this->billingModel->save_subscription($user_id, $subscription->id, $price_id, $subscription->status, $period_end, $subscription->cancel_at_period_end ? 1 : 0);

            $response['success'] = true;
            $response['message'] = $success_message;
            return $response;

        } catch (\Throwable $e) {
            error_log('[stripe] set_cancel_at_period_end: ' . $e->getMessage());
            $response['message'] = 'Could not update the subscription. Please try again.';
            return $response;
        }
    }

    /* ---------- Social publishing (Post for Me) ---------- */

    /** Auth + plan gate for all social endpoints. Returns the user array or exits with a JSON error. */
    private function social_user(){
        if (empty(Session::get('user_id'))) {
            echo json_encode(array('success' => false, 'message' => 'Not authorized'));
            exit;
        }
        $user = $this->userModel->get_user_by_id((int) Session::get('user_id'));
        $user = (is_array($user) && count($user) === 1) ? $user[0] : null;
        if (!$user || !Plan::can_social_post($user)) {
            echo json_encode(array('success' => false, 'message' => 'Your plan does not include social posting'));
            exit;
        }
        return $user;
    }

    public function connect_accountAction(){
        $user = $this->social_user();
        $platform = $this->post['platform'] ?? '';
        if ($platform === '') {
            echo json_encode(array('success' => false, 'message' => 'Platform is required'));
            exit;
        }
        // Some platforms need extra connection data (keyed by platform).
        // Instagram: connection_type ("instagram" = Login with Instagram, "facebook" = via a linked Page).
        // LinkedIn: connection_type "organization" is required when using Post for Me's provided credentials.
        $platform_data = null;
        if ($platform === 'instagram') {
            $platform_data = array('instagram' => array('connection_type' => 'instagram'));
        } elseif ($platform === 'linkedin') {
            $platform_data = array('linkedin' => array('connection_type' => 'organization'));
        }
        $url = PostForMeService::create_auth_url($platform, (int) $user['user_id'], array('posts'), $platform_data);
        if ($url === '') {
            echo json_encode(array('success' => false, 'message' => 'Could not start the connection. Please try again.'));
            exit;
        }
        echo json_encode(array('success' => true, 'url' => $url));
        exit;
    }

    public function disconnect_accountAction(){
        $user   = $this->social_user();
        $pfm_id = $this->post['account_id'] ?? '';
        if ($pfm_id === '') {
            echo json_encode(array('success' => false, 'message' => 'Account is required'));
            exit;
        }
        $accountsModel = new SocialAccountsModel();
        $acct = $accountsModel->get_by_pfm_id($pfm_id);
        if (!$acct || (int) $acct['user_id'] !== (int) $user['user_id']) {
            echo json_encode(array('success' => false, 'message' => 'Account not found'));
            exit;
        }
        PostForMeService::disconnect($pfm_id);
        $accountsModel->mark_disconnected((int) $user['user_id'], $pfm_id);
        echo json_encode(array('success' => true, 'message' => 'Account disconnected'));
        exit;
    }

    public function upload_media_urlAction(){
        $this->social_user();
        $res = PostForMeService::create_upload_url();
        if (!$res) {
            echo json_encode(array('success' => false, 'message' => 'Could not prepare the upload'));
            exit;
        }
        echo json_encode(array('success' => true, 'media_url' => $res[0], 'upload_url' => $res[1]));
        exit;
    }

    public function create_postAction(){
        $user     = $this->social_user();
        $caption  = $this->post['caption'] ?? '';
        $ids      = $this->post['social_account_ids'] ?? array();
        $media    = $this->post['media_url'] ?? '';
        $schedule = $this->post['schedule'] ?? 'now';
        $when     = $this->post['scheduled_at'] ?? '';

        if (!is_array($ids) || count($ids) === 0) {
            echo json_encode(array('success' => false, 'message' => 'Select at least one connected account'));
            exit;
        }
        if (trim($caption) === '' && $media === '') {
            echo json_encode(array('success' => false, 'message' => 'Add a caption or media'));
            exit;
        }

        // Only allow this user's currently-connected accounts.
        $accountsModel = new SocialAccountsModel();
        $valid = array();
        foreach ($accountsModel->get_connected_for_user((int) $user['user_id']) as $c) {
            $valid[$c['post_for_me_social_account_id']] = true;
        }
        $target = array();
        foreach ($ids as $id) {
            if (isset($valid[$id])) { $target[] = $id; }
        }
        if (count($target) === 0) {
            echo json_encode(array('success' => false, 'message' => 'No connected accounts selected'));
            exit;
        }

        $sched = null;
        if ($schedule === 'later' && $when !== '') {
            $ts = strtotime($when);
            if ($ts) { $sched = date('c', $ts); }
        }
        $media_urls = ($media !== '') ? array($media) : array();

        $post = PostForMeService::create_post($target, $caption, $media_urls, $sched);
        if (!$post || empty($post['id'])) {
            echo json_encode(array('success' => false, 'message' => 'Could not create the post. Please try again.'));
            exit;
        }

        $postsModel = new SocialPostsModel();
        $postsModel->create((int) $user['user_id'], $post['id'], $caption, $post['status'] ?? 'processing', $sched, $target);

        echo json_encode(array(
            'success' => true,
            'post_id' => $post['id'],
            'status'  => $post['status'] ?? 'processing',
            'message' => $sched ? 'Post scheduled' : 'Post submitted',
        ));
        exit;
    }

    public function post_statusAction(){
        $this->social_user();
        $pfm_post_id = $this->post['post_id'] ?? '';
        if ($pfm_post_id === '') {
            echo json_encode(array('success' => false, 'message' => 'Post id is required'));
            exit;
        }
        $post = PostForMeService::get_post($pfm_post_id);
        if (!$post) {
            echo json_encode(array('success' => false, 'message' => 'Post not found'));
            exit;
        }
        $status = $post['status'] ?? '';
        (new SocialPostsModel())->update_status($pfm_post_id, $status);
        echo json_encode(array('success' => true, 'status' => $status));
        exit;
    }

    /* ---------- Notification preferences ---------- */

    public function save_notification_prefsAction(){
        if (empty(Session::get('user_id'))) {
            echo json_encode(array('success' => false, 'message' => 'Not authorized'));
            exit;
        }

        $posted = $this->post['prefs'] ?? array();
        if (!is_array($posted)) {
            echo json_encode(array('success' => false, 'message' => 'Invalid preferences'));
            exit;
        }

        // Normalize every known category from the posted set so unchecked boxes
        // (absent from the payload) are saved as off, not left untouched.
        $incoming = array();
        foreach (array_keys(NotificationPrefsModel::$categories) as $category) {
            $row = $posted[$category] ?? array();
            $incoming[$category] = array(
                'in_platform' => !empty($row['in_platform']),
                'email'       => !empty($row['email']),
            );
        }

        $prefsModel = new NotificationPrefsModel();
        $prefsModel->save_prefs((int) Session::get('user_id'), $incoming);

        echo json_encode(array('success' => true, 'message' => 'Notification preferences saved'));
        exit;
    }

    /* ---------- Content preferences ---------- */

    public function save_adult_content_prefAction(){
        if (empty(Session::get('user_id'))) {
            echo json_encode(array('success' => false, 'message' => 'Not authorized'));
            exit;
        }

        $enabled = !empty($this->post['enabled']);

        // Enabling adult content requires an explicit age confirmation (PRD 34.7).
        if ($enabled && empty($this->post['age_confirmed'])) {
            echo json_encode(array('success' => false, 'message' => 'Age confirmation is required'));
            exit;
        }

        $this->userModel->set_adult_content_enabled((int) Session::get('user_id'), $enabled, (int) Session::get('user_id'));

        echo json_encode(array(
            'success' => true,
            'message' => $enabled ? 'Adult content enabled' : 'Adult content hidden',
        ));
        exit;
    }

    /* ---------- Blocked accounts ---------- */

    public function block_userAction(){
        if (empty(Session::get('user_id'))) {
            echo json_encode(array('success' => false, 'message' => 'Not authorized'));
            exit;
        }

        $u_name = trim((string) ($this->post['u_name'] ?? ''));
        $u_name = ltrim($u_name, '@');
        if ($u_name === '') {
            echo json_encode(array('success' => false, 'message' => 'A username is required'));
            exit;
        }

        $target = $this->userModel->get_user_by_username($u_name);
        if (!is_array($target) || count($target) !== 1) {
            echo json_encode(array('success' => false, 'message' => 'No account found with that username'));
            exit;
        }
        $target = $target[0];

        if ((int) $target['user_id'] === (int) Session::get('user_id')) {
            echo json_encode(array('success' => false, 'message' => 'You cannot block yourself'));
            exit;
        }

        $blocksModel = new BlocksModel();
        $blocksModel->add_block((int) Session::get('user_id'), (int) $target['user_id']);

        $name = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? ''));

        echo json_encode(array(
            'success'         => true,
            'message'         => 'Account blocked',
            'blocked_user_id' => (int) $target['user_id'],
            'u_name'          => $target['u_name'],
            'name'            => $name,
        ));
        exit;
    }

    public function unblock_userAction(){
        if (empty(Session::get('user_id'))) {
            echo json_encode(array('success' => false, 'message' => 'Not authorized'));
            exit;
        }

        $blocked_user_id = (int) ($this->post['blocked_user_id'] ?? 0);
        if ($blocked_user_id <= 0) {
            echo json_encode(array('success' => false, 'message' => 'Account is required'));
            exit;
        }

        $blocksModel = new BlocksModel();
        $blocksModel->remove_block((int) Session::get('user_id'), $blocked_user_id);

        echo json_encode(array('success' => true, 'message' => 'Account unblocked'));
        exit;
    }

    /* ---------- Become a creator ---------- */

    public function become_creatorAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['accept_agreement'])) {
            $response['message'] = 'You must accept the Creator Agreement and Content Policy';
            echo json_encode($response);
            exit;
        }

        $user_id = (int) Session::get('user_id');
        $result  = $this->userModel->make_creator($user_id, $user_id);
        if ($result === false) {
            $response['message'] = 'Could not activate your creator account';
            echo json_encode($response);
            exit;
        }

        // Reflect the new role on the session so gating updates without re-login.
        $creator_role_id = $this->userModel->get_role_id_by_name('Creator');
        Session::set('role_id', $creator_role_id);
        Session::set('creator_since', date('Y-m-d H:i:s'));

        $response['success'] = true;
        $response['message'] = 'Welcome — your creator account is active';
        echo json_encode($response);
        exit;
    }

    public function leave_creatorAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        // Guard against accidental calls — the UI requires an explicit confirmation.
        if (empty($this->post['confirm'])) {
            $response['message'] = 'Please confirm you want to stop being a creator';
            echo json_encode($response);
            exit;
        }

        $user_id = (int) Session::get('user_id');

        // Hard delete all creator content first (irreversible, no soft delete),
        // then revert the account to a regular User.
        $this->userModel->hard_delete_creator_content($user_id);

        $result = $this->userModel->revert_creator($user_id, $user_id);
        if ($result === false) {
            $response['message'] = 'Could not update your account';
            echo json_encode($response);
            exit;
        }

        Session::set('role_id', $this->userModel->get_role_id_by_name('User'));
        Session::set('creator_since', null);

        $response['success'] = true;
        $response['message'] = 'Your creator account has been removed';
        echo json_encode($response);
        exit;
    }

    /* ---------- Creator profile / branding ---------- */

    /** Auth + creator-role gate. Returns the user array or exits with a JSON error. */
    private function require_creator(){
        if (empty(Session::get('user_id'))) {
            echo json_encode(array('success' => false, 'message' => 'Not authorized'));
            exit;
        }
        $user = $this->userModel->get_user_by_id((int) Session::get('user_id'));
        $user = (is_array($user) && count($user) === 1) ? $user[0] : null;
        $creator_role_id = $this->userModel->get_role_id_by_name('Creator');
        if (!$user || (int) $user['role_id'] !== $creator_role_id) {
            echo json_encode(array('success' => false, 'message' => 'Only creators can do that'));
            exit;
        }
        return $user;
    }

    public function save_creator_profileAction(){
        $this->require_creator();

        $display_name = trim((string) ($this->post['display_name'] ?? ''));
        if ($display_name === '') {
            echo json_encode(array('success' => false, 'message' => 'Display name is required'));
            exit;
        }

        (new CreatorProfileModel())->save((int) Session::get('user_id'), array(
            'display_name' => $display_name,
            'bio'          => trim((string) ($this->post['bio'] ?? '')),
            'location'     => trim((string) ($this->post['location'] ?? '')),
            'tags'         => trim((string) ($this->post['tags'] ?? '')),
        ));

        echo json_encode(array('success' => true, 'message' => 'Profile saved'));
        exit;
    }

    public function upload_creator_imageAction(){
        $this->require_creator();

        $kind = (string) ($this->post['kind'] ?? '');
        if (!in_array($kind, array('avatar', 'cover'), true)) {
            echo json_encode(array('success' => false, 'message' => 'Invalid image type'));
            exit;
        }

        $file = $_FILES['image'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            echo json_encode(array('success' => false, 'message' => 'No image was uploaded'));
            exit;
        }
        if ((int) $file['size'] > 5 * 1024 * 1024) {
            echo json_encode(array('success' => false, 'message' => 'Image must be 5MB or smaller'));
            exit;
        }

        // Trust the actual bytes, not the client-supplied name/type.
        $info = @getimagesize($file['tmp_name']);
        $ext_map = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
        if ($info === false || !isset($ext_map[$info['mime']])) {
            echo json_encode(array('success' => false, 'message' => 'Unsupported image type (use JPG, PNG, WebP, or GIF)'));
            exit;
        }

        if (!S3Service::configured()) {
            echo json_encode(array('success' => false, 'message' => 'Image uploads are not available right now'));
            exit;
        }

        $user_id = (int) Session::get('user_id');
        $key     = 'creator/u' . $user_id . '_' . $kind . '_' . bin2hex(random_bytes(8)) . '.' . $ext_map[$info['mime']];

        $url = S3Service::upload_file($key, $file['tmp_name'], $info['mime']);
        if ($url === '') {
            echo json_encode(array('success' => false, 'message' => 'Could not save the image'));
            exit;
        }

        $column = ($kind === 'avatar') ? 'avatar_url' : 'cover_url';
        (new CreatorProfileModel())->set_image($user_id, $column, $url);

        echo json_encode(array('success' => true, 'url' => $url, 'message' => ucfirst($kind) . ' updated'));
        exit;
    }

    public function remove_creator_imageAction(){
        $this->require_creator();

        $kind = (string) ($this->post['kind'] ?? '');
        if (!in_array($kind, array('avatar', 'cover'), true)) {
            echo json_encode(array('success' => false, 'message' => 'Invalid image type'));
            exit;
        }

        $user_id = (int) Session::get('user_id');
        $column  = ($kind === 'avatar') ? 'avatar_url' : 'cover_url';

        $model   = new CreatorProfileModel();
        $current = $model->get_for_user($user_id);
        $old_url = $current[$column] ?? '';

        $model->set_image($user_id, $column, '');
        if ($old_url !== '') {
            S3Service::delete_by_url($old_url);
        }

        echo json_encode(array('success' => true, 'message' => ucfirst($kind) . ' removed'));
        exit;
    }

    /* ---------- Creator external links ---------- */

    public function save_creator_linkAction(){
        $this->require_creator();
        $user_id = (int) Session::get('user_id');

        $title = trim((string) ($this->post['title'] ?? ''));
        $url   = trim((string) ($this->post['url'] ?? ''));
        $id    = (int) ($this->post['id'] ?? 0);

        if ($title === '') {
            echo json_encode(array('success' => false, 'message' => 'A label is required'));
            exit;
        }
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode(array('success' => false, 'message' => 'Enter a valid URL (including https://)'));
            exit;
        }

        $model = new CreatorLinksModel();
        if ($id > 0) {
            if (!$model->get_one($user_id, $id)) {
                echo json_encode(array('success' => false, 'message' => 'Link not found'));
                exit;
            }
            $model->update_link($user_id, $id, $title, $url);
        } else {
            $id = (int) $model->add($user_id, $title, $url);
        }

        echo json_encode(array('success' => true, 'message' => 'Link saved', 'id' => $id));
        exit;
    }

    public function delete_creator_linkAction(){
        $this->require_creator();
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(array('success' => false, 'message' => 'Link is required'));
            exit;
        }
        (new CreatorLinksModel())->delete_link((int) Session::get('user_id'), $id);
        echo json_encode(array('success' => true, 'message' => 'Link removed'));
        exit;
    }

    public function toggle_creator_linkAction(){
        $this->require_creator();
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(array('success' => false, 'message' => 'Link is required'));
            exit;
        }
        (new CreatorLinksModel())->set_enabled((int) Session::get('user_id'), $id, !empty($this->post['enabled']));
        echo json_encode(array('success' => true, 'message' => 'Link updated'));
        exit;
    }

    public function reorder_creator_linksAction(){
        $this->require_creator();
        $ids = $this->post['ids'] ?? array();
        if (!is_array($ids)) {
            echo json_encode(array('success' => false, 'message' => 'Invalid order'));
            exit;
        }
        (new CreatorLinksModel())->reorder((int) Session::get('user_id'), $ids);
        echo json_encode(array('success' => true, 'message' => 'Order saved'));
        exit;
    }

    /* ---------- Creator membership plans ---------- */

    public function save_creator_planAction(){
        $this->require_creator();
        $user_id = (int) Session::get('user_id');

        $name             = trim((string) ($this->post['name'] ?? ''));
        $billing_interval = (string) ($this->post['billing_interval'] ?? 'month');
        if (!in_array($billing_interval, array('week', 'month', 'year'), true)) {
            $billing_interval = 'month';
        }
        $description      = trim((string) ($this->post['description'] ?? ''));
        $perks            = trim((string) ($this->post['perks'] ?? ''));
        $id               = (int) ($this->post['id'] ?? 0);

        if ($name === '') {
            echo json_encode(array('success' => false, 'message' => 'A plan name is required'));
            exit;
        }

        // Price arrives as dollars; store integer cents. A free tier is 0; any
        // paid tier must be at least $1.00 (Stripe won't charge sub-dollar reliably).
        $is_free     = !empty($this->post['is_free']);
        $price       = (float) ($this->post['price'] ?? 0);
        $price_cents = $is_free ? 0 : (int) round($price * 100);
        if ($price_cents !== 0 && $price_cents < 100) {
            echo json_encode(array('success' => false, 'message' => 'Enter a price of at least $1.00, or make it a free tier'));
            exit;
        }

        $fields = array(
            'name'             => $name,
            'price_cents'      => $price_cents,
            'billing_interval' => $billing_interval,
            'description'      => $description,
            'perks'            => $perks,
        );

        $model = new CreatorPlansModel();
        if ($id > 0) {
            if (!$model->get_one($user_id, $id)) {
                echo json_encode(array('success' => false, 'message' => 'Plan not found'));
                exit;
            }
            $model->update_plan($user_id, $id, $fields);
        } else {
            $id = (int) $model->add($user_id, $fields);
        }

        echo json_encode(array('success' => true, 'message' => 'Plan saved', 'id' => $id));
        exit;
    }

    public function delete_creator_planAction(){
        $this->require_creator();
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(array('success' => false, 'message' => 'Plan is required'));
            exit;
        }
        (new CreatorPlansModel())->delete_plan((int) Session::get('user_id'), $id);
        echo json_encode(array('success' => true, 'message' => 'Plan removed'));
        exit;
    }

    public function toggle_creator_planAction(){
        $this->require_creator();
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(array('success' => false, 'message' => 'Plan is required'));
            exit;
        }
        (new CreatorPlansModel())->set_active((int) Session::get('user_id'), $id, !empty($this->post['active']));
        echo json_encode(array('success' => true, 'message' => 'Plan updated'));
        exit;
    }

    public function reorder_creator_plansAction(){
        $this->require_creator();
        $ids = $this->post['ids'] ?? array();
        if (!is_array($ids)) {
            echo json_encode(array('success' => false, 'message' => 'Invalid order'));
            exit;
        }
        (new CreatorPlansModel())->reorder((int) Session::get('user_id'), $ids);
        echo json_encode(array('success' => true, 'message' => 'Order saved'));
        exit;
    }

    /* ---------- Content Studio ---------- */

    public function save_contentAction(){
        $this->require_creator();
        $user_id = (int) Session::get('user_id');

        // clean_post_data() html-encodes all input; decode so we store raw text and
        // escape exactly once at render (avoids double-encoded &mdash; / &#039;).
        $dec = function ($k) { return html_entity_decode((string) ($this->post[$k] ?? ''), ENT_QUOTES, 'UTF-8'); };
        $title       = trim($dec('title'));
        $description = trim($dec('description'));
        $body        = $dec('body');
        $tags        = trim($dec('tags'));
        $access      = (string) ($this->post['access'] ?? 'public');
        $id          = (int) ($this->post['id'] ?? 0);

        if ($title === '') {
            echo json_encode(array('success' => false, 'message' => 'A title is required'));
            exit;
        }
        if (!in_array($access, array('public', 'subscribers', 'paid'), true)) {
            $access = 'public';
        }

        $required_plan_id = 0;
        $price_credits    = 0;
        $plansModel       = new CreatorPlansModel();

        if ($access === 'subscribers') {
            $required_plan_id = (int) ($this->post['required_plan_id'] ?? 0);
            if ($required_plan_id <= 0 || !$plansModel->get_one($user_id, $required_plan_id)) {
                echo json_encode(array('success' => false, 'message' => 'Choose which tier can access this'));
                exit;
            }
        } elseif ($access === 'paid') {
            $price_credits = (int) ($this->post['price'] ?? 0);
            if ($price_credits < 1) {
                echo json_encode(array('success' => false, 'message' => 'Set a credit price of at least 1'));
                exit;
            }
        }

        $fields = array(
            'title'            => $title,
            'description'      => $description,
            'body'             => $body,
            'tags'             => $tags,
            'access'           => $access,
            'required_plan_id' => $required_plan_id,
            'price_credits'    => $price_credits,
            'comments_enabled' => !empty($this->post['comments_enabled']) ? 1 : 0,
        );

        $model = new ContentItemsModel();
        if ($id > 0) {
            if (!$model->get_one($user_id, $id)) {
                echo json_encode(array('success' => false, 'message' => 'Content not found'));
                exit;
            }
            $model->update_item($user_id, $id, $fields);
        } else {
            $id = (int) $model->add($user_id, $fields);
        }

        // Optional publish state: draft (default) | publish (now) | schedule (future).
        $publish = (string) ($this->post['publish'] ?? '');
        $status  = 'draft';
        if ($publish === 'publish') {
            $model->set_status($user_id, $id, 'published');
            $status = 'published';
        } elseif ($publish === 'schedule') {
            $when = strtotime((string) ($this->post['scheduled_at'] ?? ''));
            if (!$when || $when <= time()) {
                echo json_encode(array('success' => false, 'message' => 'Pick a schedule time in the future', 'id' => $id));
                exit;
            }
            $model->set_status($user_id, $id, 'scheduled', date('Y-m-d H:i:s', $when));
            $status = 'scheduled';
        } elseif ($publish === 'draft') {
            $model->set_status($user_id, $id, 'draft');
        } else {
            // No publish change (e.g. an auto-save when attaching media) — report the real status.
            $cur    = $model->get_one($user_id, $id);
            $status = $cur ? (string) $cur['status'] : 'draft';
        }

        echo json_encode(array('success' => true, 'message' => 'Content saved', 'id' => $id, 'status' => $status));
        exit;
    }

    /** List an owned item's media assets (for the editor). */
    public function get_content_assetsAction(){
        $this->require_creator();
        $user_id    = (int) Session::get('user_id');
        $content_id = (int) ($this->post['content_id'] ?? 0);

        if (!(new ContentItemsModel())->get_one($user_id, $content_id)) {
            echo json_encode(array('success' => false, 'message' => 'Content not found'));
            exit;
        }
        $assets = array();
        foreach ((new ContentAssetsModel())->get_for_content($content_id) as $a) {
            $assets[] = array('id' => (int) $a['id'], 'type' => $a['type'], 'url' => $a['url']);
        }
        echo json_encode(array('success' => true, 'assets' => $assets));
        exit;
    }

    /** The creator's posts, for the Studio list. */
    public function get_my_contentAction(){
        $user  = $this->require_creator();
        $model = new ContentItemsModel();
        $model->publish_due($user['user_id']); // flip any now-due scheduled posts live
        $items = array();
        foreach ($model->get_for_creator($user['user_id']) as $it) {
            $items[] = array(
                'id'               => (int) $it['id'],
                'title'            => html_entity_decode((string) $it['title'], ENT_QUOTES, 'UTF-8'),
                'access'           => (string) $it['access'],
                'required_plan_id' => (int) $it['required_plan_id'],
                'price_credits'    => (int) $it['price_credits'],
                'status'           => (string) $it['status'],
                'scheduled_at'     => (string) $it['scheduled_at'],
                'preview_url'      => (string) $it['preview_url'],
                'pinned'           => (int) $it['pinned'],
            );
        }
        echo json_encode(array('success' => true, 'items' => $items));
        exit;
    }

    /** The creator's subscription tiers, for the audience picker. */
    public function get_my_plansAction(){
        $user  = $this->require_creator();
        $plans = array();
        foreach ((new CreatorPlansModel())->get_for_user($user['user_id']) as $p) {
            $plans[] = array('id' => (int) $p['id'], 'name' => (string) $p['name'], 'price_cents' => (int) $p['price_cents']);
        }
        echo json_encode(array('success' => true, 'plans' => $plans));
        exit;
    }

    /** One post's full data + media, for the editor. */
    public function get_contentAction(){
        $this->require_creator();
        $user_id = (int) Session::get('user_id');
        $item    = (new ContentItemsModel())->get_one($user_id, (int) ($this->post['id'] ?? 0));
        if (!$item) { echo json_encode(array('success' => false, 'message' => 'Post not found')); exit; }

        $assets = array();
        foreach ((new ContentAssetsModel())->get_for_content((int) $item['id']) as $a) {
            $assets[] = array('id' => (int) $a['id'], 'type' => $a['type'], 'url' => $a['url']);
        }
        echo json_encode(array('success' => true, 'assets' => $assets, 'item' => array(
            'id'               => (int) $item['id'],
            'title'            => html_entity_decode((string) $item['title'], ENT_QUOTES, 'UTF-8'),
            'body'             => html_entity_decode((string) $item['body'], ENT_QUOTES, 'UTF-8'),
            'tags'             => html_entity_decode((string) $item['tags'], ENT_QUOTES, 'UTF-8'),
            'access'           => (string) $item['access'],
            'required_plan_id' => (int) $item['required_plan_id'],
            'price_credits'    => (int) $item['price_credits'],
            'comments_enabled' => (int) $item['comments_enabled'],
            'status'           => (string) $item['status'],
            'scheduled_at'     => (string) $item['scheduled_at'],
            'preview_url'      => (string) $item['preview_url'],
        )));
        exit;
    }

    /** Allowed content media: real mime => [content_assets.type, extension, max bytes]. */
    private static $content_media = array(
        'image/jpeg'      => array('image', 'jpg',  5242880),
        'image/png'       => array('image', 'png',  5242880),
        'image/webp'      => array('image', 'webp', 5242880),
        'image/gif'       => array('image', 'gif',  5242880),
        'video/mp4'       => array('video', 'mp4',  209715200),
        'video/webm'      => array('video', 'webm', 209715200),
        'video/quicktime' => array('video', 'mov',  209715200),
        'audio/mpeg'      => array('audio', 'mp3',  31457280),
        'audio/mp4'       => array('audio', 'm4a',  31457280),
        'audio/x-m4a'     => array('audio', 'm4a',  31457280),
        'audio/wav'       => array('audio', 'wav',  31457280),
        'audio/x-wav'     => array('audio', 'wav',  31457280),
        'audio/ogg'       => array('audio', 'ogg',  31457280),
        'application/pdf' => array('document', 'pdf', 26214400),
    );

    /** Upload a media asset (kind=media) or the locked preview image (kind=preview) for a content item. */
    public function upload_content_assetAction(){
        $this->require_creator();
        $user_id = (int) Session::get('user_id');

        $content_id = (int) ($this->post['content_id'] ?? 0);
        $kind       = (string) ($this->post['kind'] ?? '');
        if (!in_array($kind, array('media', 'preview'), true)) {
            echo json_encode(array('success' => false, 'message' => 'Invalid upload'));
            exit;
        }

        $itemModel = new ContentItemsModel();
        $item      = $itemModel->get_one($user_id, $content_id);
        if (!$item) {
            echo json_encode(array('success' => false, 'message' => 'Save the content first'));
            exit;
        }

        $file = $_FILES['file'] ?? ($_FILES['image'] ?? null);
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            echo json_encode(array('success' => false, 'message' => 'No file was uploaded'));
            exit;
        }
        if (!S3Service::configured()) {
            echo json_encode(array('success' => false, 'message' => 'Uploads are not available right now'));
            exit;
        }

        // The locked preview is always an image.
        if ($kind === 'preview') {
            $info = @getimagesize($file['tmp_name']);
            $img  = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
            if ($info === false || !isset($img[$info['mime']])) {
                echo json_encode(array('success' => false, 'message' => 'Preview must be an image (JPG, PNG, WebP, GIF)'));
                exit;
            }
            if ((int) $file['size'] > 5242880) {
                echo json_encode(array('success' => false, 'message' => 'Preview must be 5MB or smaller'));
                exit;
            }
            $mime = $info['mime'];
            // Under the creator/ prefix so the bucket's public-read policy serves it (random key = unguessable).
            $key  = 'creator/content/u' . $user_id . '_c' . $content_id . '_preview_' . bin2hex(random_bytes(8)) . '.' . $img[$mime];
            $url  = S3Service::upload_file($key, $file['tmp_name'], $mime);
            if ($url === '') { echo json_encode(array('success' => false, 'message' => 'Could not save the image')); exit; }
            $old = (string) ($item['preview_url'] ?? '');
            $itemModel->set_preview($user_id, $content_id, $url);
            if ($old !== '') { S3Service::delete_by_url($old); }
            echo json_encode(array('success' => true, 'kind' => 'preview', 'url' => $url));
            exit;
        }

        // kind = media: trust the bytes, not the filename/client type.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($file['tmp_name']);
        if (!isset(self::$content_media[$mime])) {
            echo json_encode(array('success' => false, 'message' => 'Unsupported file type (images, MP4/WebM/MOV video, MP3/M4A/WAV/OGG audio, or PDF)'));
            exit;
        }
        list($type, $ext, $max) = self::$content_media[$mime];
        if ((int) $file['size'] > $max) {
            echo json_encode(array('success' => false, 'message' => ucfirst($type) . ' is too large (max ' . round($max / 1048576) . 'MB)'));
            exit;
        }

        // Under the creator/ prefix so the bucket's public-read policy serves it (random key = unguessable).
        $key = 'creator/content/u' . $user_id . '_c' . $content_id . '_' . $type . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $url = S3Service::upload_file($key, $file['tmp_name'], $mime);
        if ($url === '') { echo json_encode(array('success' => false, 'message' => 'Could not save the file')); exit; }

        $asset_id = (new ContentAssetsModel())->add($user_id, $content_id, $type, $url, $mime);
        echo json_encode(array('success' => true, 'kind' => 'media', 'url' => $url, 'type' => $type, 'asset_id' => (int) $asset_id));
        exit;
    }

    public function delete_content_assetAction(){
        $this->require_creator();
        $user_id = (int) Session::get('user_id');
        $id      = (int) ($this->post['id'] ?? 0);

        $assets = new ContentAssetsModel();
        $asset  = $assets->get_one($user_id, $id);
        if (!$asset) {
            echo json_encode(array('success' => false, 'message' => 'Asset not found'));
            exit;
        }
        $assets->delete_asset($user_id, $id);
        S3Service::delete_by_url((string) $asset['url']);
        echo json_encode(array('success' => true, 'message' => 'Removed'));
        exit;
    }

    public function toggle_publish_contentAction(){
        $this->require_creator();
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(array('success' => false, 'message' => 'Content is required'));
            exit;
        }
        $status = !empty($this->post['published']) ? 'published' : 'draft';
        (new ContentItemsModel())->set_status((int) Session::get('user_id'), $id, $status);
        echo json_encode(array('success' => true, 'status' => $status));
        exit;
    }

    public function delete_contentAction(){
        $this->require_creator();
        $user_id = (int) Session::get('user_id');
        $id      = (int) ($this->post['id'] ?? 0);

        $itemModel = new ContentItemsModel();
        $item      = $itemModel->get_one($user_id, $id);
        if (!$item) {
            echo json_encode(array('success' => false, 'message' => 'Content not found'));
            exit;
        }

        // Clean up the stored S3 assets + preview before removing the rows.
        $assetsModel = new ContentAssetsModel();
        foreach ($assetsModel->get_for_content($id) as $a) {
            S3Service::delete_by_url((string) $a['url']);
        }
        if (!empty($item['preview_url'])) {
            S3Service::delete_by_url((string) $item['preview_url']);
        }
        $assetsModel->delete_for_content($user_id, $id);
        $itemModel->delete_item($user_id, $id);

        echo json_encode(array('success' => true, 'message' => 'Content removed'));
        exit;
    }

    public function reorder_contentAction(){
        $this->require_creator();
        $ids = $this->post['ids'] ?? array();
        if (!is_array($ids)) {
            echo json_encode(array('success' => false, 'message' => 'Invalid order'));
            exit;
        }
        (new ContentItemsModel())->reorder((int) Session::get('user_id'), $ids);
        echo json_encode(array('success' => true, 'message' => 'Order saved'));
        exit;
    }

    public function pin_contentAction(){
        $this->require_creator();
        $id = (int) ($this->post['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(array('success' => false, 'message' => 'Content is required'));
            exit;
        }
        $pinned = !empty($this->post['pinned']);
        (new ContentItemsModel())->set_pinned((int) Session::get('user_id'), $id, $pinned);
        echo json_encode(array('success' => true, 'pinned' => $pinned));
        exit;
    }

    public function reorder_content_assetsAction(){
        $this->require_creator();
        $ids = $this->post['ids'] ?? array();
        if (!is_array($ids)) {
            echo json_encode(array('success' => false, 'message' => 'Invalid order'));
            exit;
        }
        (new ContentAssetsModel())->reorder((int) Session::get('user_id'), $ids);
        echo json_encode(array('success' => true, 'message' => 'Order saved'));
        exit;
    }

    /** Unlock paid content by spending credits (any authenticated viewer). Idempotent. */
    public function unlock_contentAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message']    = 'Sign in to unlock';
            $response['need_login'] = true;
            echo json_encode($response);
            exit;
        }

        $user_id  = (int) Session::get('user_id');
        $itemModel = new ContentItemsModel();
        $item     = $itemModel->get_public_by_id((int) ($this->post['content_id'] ?? 0));

        if (!$item || $item['access'] !== 'paid') {
            $response['message'] = 'That content is not available to unlock';
            echo json_encode($response);
            exit;
        }
        $creator_id = (int) $item['creator_id'];
        if ($creator_id === $user_id) {
            $response['message'] = 'This is your own content';
            echo json_encode($response);
            exit;
        }

        $unlocks = new ContentUnlocksModel();
        $content_id = (int) $item['id'];

        // Already owned → reveal without charging (idempotent, §16.3).
        if ($unlocks->is_unlocked($user_id, $content_id)) {
            $this->respond_unlocked($item);
        }

        $price = (int) $item['price_credits'];

        // Insert the ownership row first; the UNIQUE key makes concurrent unlocks safe.
        if ($unlocks->record($user_id, $content_id, $creator_id, $price) === false) {
            // Lost the race → someone else's request already recorded it; reveal.
            $this->respond_unlocked($item);
        }

        // Atomic spend (FOR UPDATE, rejects a negative balance).
        $new_balance = (new CreditsModel())->apply_delta($user_id, -$price, 'content_unlock', 'Unlocked content #' . $content_id);
        if ($new_balance === false) {
            $unlocks->remove($user_id, $content_id);
            $response['message']      = 'Not enough credits';
            $response['need_credits'] = true;
            echo json_encode($response);
            exit;
        }

        // TODO: credit the creator's earnings wallet (§18.7) here once it exists.
        $this->respond_unlocked($item, $new_balance);
    }

    /** Emit the revealed content (original assets + body). Only reached post-entitlement (§11.6). */
    private function respond_unlocked($item, $balance = null){
        $assets = array();
        foreach ((new ContentAssetsModel())->get_for_content((int) $item['id']) as $a) {
            $assets[] = array('type' => $a['type'], 'url' => $a['url']);
        }
        $tags = array();
        foreach (explode(',', (string) ($item['tags'] ?? '')) as $t) {
            $t = trim($t);
            if ($t !== '') { $tags[] = $t; }
        }
        $out = array(
            'success'     => true,
            'unlocked'    => true,
            'message'     => 'Unlocked',
            'description' => (string) ($item['description'] ?? ''),
            'body'        => (string) $item['body'],
            'tags'        => $tags,
            'assets'      => $assets,
        );
        if ($balance !== null) {
            $out['balance'] = (int) $balance;
        }
        echo json_encode($out);
        exit;
    }

    /* ---------- Payouts (Stripe Connect) ---------- */

    private function site_base_url(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    public function start_payout_onboardingAction(){
        $user = $this->require_creator();

        $account_id = $user['stripe_connect_account_id'] ?? '';
        if (empty($account_id)) {
            $account_id = StripeService::create_connect_account($user);
            if ($account_id === '') {
                echo json_encode(array('success' => false, 'message' => 'Payouts are not available yet. Please try again later.'));
                exit;
            }
            $this->billingModel->set_connect_account_id((int) $user['user_id'], $account_id);
        }

        $base = $this->site_base_url();
        $url  = StripeService::account_onboarding_link(
            $account_id,
            $base . '/account/settings?section=payouts&payout_refresh=1',
            $base . '/account/settings?section=payouts&payout_return=1'
        );
        if ($url === '') {
            echo json_encode(array('success' => false, 'message' => 'Could not start payout setup. Please try again.'));
            exit;
        }

        echo json_encode(array('success' => true, 'url' => $url));
        exit;
    }

    public function payout_login_linkAction(){
        $user       = $this->require_creator();
        $account_id = $user['stripe_connect_account_id'] ?? '';
        if (empty($account_id)) {
            echo json_encode(array('success' => false, 'message' => 'Set up payouts first'));
            exit;
        }
        $url = StripeService::connect_login_link($account_id);
        if ($url === '') {
            echo json_encode(array('success' => false, 'message' => 'Could not open the payouts dashboard'));
            exit;
        }
        echo json_encode(array('success' => true, 'url' => $url));
        exit;
    }

    public function request_payoutAction(){
        $user       = $this->require_creator();
        $account_id = $user['stripe_connect_account_id'] ?? '';
        if (empty($account_id)) {
            echo json_encode(array('success' => false, 'message' => 'Set up payouts first'));
            exit;
        }

        $balance = StripeService::connect_balance($account_id);
        if ((int) $balance['available'] <= 0) {
            echo json_encode(array('success' => false, 'message' => 'No funds available to pay out'));
            exit;
        }

        $result = StripeService::create_payout($account_id, (int) $balance['available'], $balance['currency']);
        if (empty($result['ok'])) {
            echo json_encode(array('success' => false, 'message' => 'Could not request the payout. Make sure a bank account is connected.'));
            exit;
        }

        echo json_encode(array('success' => true, 'message' => 'Payout of $' . number_format($balance['available'] / 100, 2) . ' requested'));
        exit;
    }

    public function disconnect_payout_accountAction(){
        $user       = $this->require_creator();
        $account_id = $user['stripe_connect_account_id'] ?? '';

        if ($account_id !== '') {
            StripeService::delete_connect_account($account_id);
        }
        $this->billingModel->set_connect_account_id((int) $user['user_id'], null);

        echo json_encode(array('success' => true, 'message' => 'Payout account disconnected'));
        exit;
    }

    /* ---------- Credits & wallet ---------- */

    /** Ensure the logged-in user has a Stripe customer; returns [user, customer_id]. */
    private function ensure_stripe_customer($stripe){
        $user_id     = (int) Session::get('user_id');
        $user        = $this->userModel->get_user_by_id($user_id)[0];
        $customer_id = $user['stripe_customer_id'] ?? '';
        if (empty($customer_id)) {
            $customer = $stripe->customers->create(array(
                'email'    => $user['user_email'],
                'name'     => trim($user['first_name'] . ' ' . $user['last_name']),
                'metadata' => array('user_id' => (string) $user_id),
            ));
            $customer_id = $customer->id;
            $this->billingModel->set_customer_id($user_id, $customer_id);
        }
        return array($user, $customer_id);
    }

    public function buy_creditsAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $dollars = (int) ($this->post['dollars'] ?? 0);
        $package = CreditsModel::package_for_dollars($dollars);
        if (!$package) {
            $response['message'] = 'Choose a valid credit package';
            echo json_encode($response);
            exit;
        }

        try {
            $stripe = StripeService::client();
            list($user, $customer_id) = $this->ensure_stripe_customer($stripe);

            $intent = $stripe->paymentIntents->create(array(
                'amount'                    => $package['dollars'] * 100,
                'currency'                  => 'usd',
                'customer'                  => $customer_id,
                'automatic_payment_methods' => array('enabled' => true),
                'metadata'                  => array(
                    'user_id' => (string) $user['user_id'],
                    'credits' => (string) $package['credits'],
                    'type'    => 'credit_purchase',
                ),
            ));

            $response['success']       = true;
            $response['client_secret'] = $intent->client_secret;
            $response['credits']       = $package['credits'];
            $response['message']       = 'Payment ready';
            echo json_encode($response);
            exit;

        } catch (\Throwable $e) {
            error_log('[stripe] buy_credits: ' . $e->getMessage());
            $response['message'] = 'Could not start the purchase. Please try again.';
            echo json_encode($response);
            exit;
        }
    }

    public function confirm_credit_purchaseAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $pi_id = (string) ($this->post['payment_intent_id'] ?? '');
        if ($pi_id === '') {
            $response['message'] = 'Payment reference is required';
            echo json_encode($response);
            exit;
        }

        try {
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];
            $stripe  = StripeService::client();
            $intent  = $stripe->paymentIntents->retrieve($pi_id);

            // Trust the PaymentIntent, not the client: verify owner, status, and purpose.
            if ((string) ($intent->metadata['type'] ?? '') !== 'credit_purchase'
                || (int) ($intent->metadata['user_id'] ?? 0) !== $user_id
                || (string) $intent->customer !== (string) ($user['stripe_customer_id'] ?? '')) {
                $response['message'] = 'This payment could not be verified';
                echo json_encode($response);
                exit;
            }
            if ($intent->status !== 'succeeded') {
                $response['message'] = 'Payment has not completed yet';
                echo json_encode($response);
                exit;
            }

            $credits     = (int) ($intent->metadata['credits'] ?? 0);
            $creditsModel = new CreditsModel();
            $balance = $creditsModel->credit_purchase($user_id, $credits, $intent->id, 'Purchased ' . $credits . ' credits');

            $response['success'] = true;
            $response['balance'] = (int) $balance;
            $response['message'] = number_format($credits) . ' credits added';
            echo json_encode($response);
            exit;

        } catch (\Throwable $e) {
            error_log('[stripe] confirm_credit_purchase: ' . $e->getMessage());
            $response['message'] = 'Could not confirm the purchase';
            echo json_encode($response);
            exit;
        }
    }

    public function save_autoreplenishmentAction(){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $enabled   = !empty($this->post['enabled']);
        $threshold = (int) ($this->post['threshold'] ?? 0);
        $dollars   = (int) ($this->post['dollars'] ?? 0);
        $pm_id     = (string) ($this->post['payment_method_id'] ?? '');

        if ($enabled) {
            if ($threshold <= 0) {
                $response['message'] = 'Set a low-balance threshold above zero';
                echo json_encode($response);
                exit;
            }
            if (!CreditsModel::package_for_dollars($dollars)) {
                $response['message'] = 'Choose a valid replenishment package';
                echo json_encode($response);
                exit;
            }
            if ($pm_id === '') {
                $response['message'] = 'Choose a payment method';
                echo json_encode($response);
                exit;
            }
        }

        $creditsModel = new CreditsModel();
        $creditsModel->save_autoreplenishment((int) Session::get('user_id'), $enabled, $threshold, $dollars * 100, $pm_id);

        $response['success'] = true;
        $response['message'] = 'Auto-replenishment saved';
        echo json_encode($response);
        exit;
    }

}
