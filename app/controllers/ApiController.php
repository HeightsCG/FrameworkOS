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

    // ---------- Public post engagement (likes / comments / views) ----------

    /** Whether this viewer is allowed to see — and thus engage with — the post. */
    private function post_engagement_ok($post, $viewer_id){
        if (!$post || ($post['state'] ?? '') !== 'published') { return false; }
        $creator_id = (int) $post['creator_id'];
        if ($viewer_id === $creator_id) { return true; }
        if (($post['audience'] ?? 'free') === 'free') { return true; }
        if ($viewer_id <= 0) { return false; }
        $subs    = new CreatorSubscriptionsModel();
        $tier_id = (int) ($post['tier_id'] ?? 0);
        if ($tier_id > 0) {
            $max  = $subs->max_active_tier_price($viewer_id, $creator_id);
            $plan = (new CreatorPlansModel())->get_public($tier_id);
            return ($max !== null && $max >= (int) ($plan['price_cents'] ?? 0));
        }
        return !empty($subs->active_plan_ids($viewer_id, $creator_id));
    }

    private function time_ago($dt){
        $t = strtotime((string) $dt); if (!$t) { return ''; }
        $s = time() - $t;
        if ($s < 60) { return 'just now'; }
        $m = intdiv($s, 60); if ($m < 60) { return $m . 'm ago'; }
        $h = intdiv($m, 60); if ($h < 24) { return $h . 'h ago'; }
        $d = intdiv($h, 24); if ($d < 7) { return $d . 'd ago'; }
        return date('M j, Y', $t);
    }

    private function commenter_name($row){
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        return ($name === '') ? ('@' . (string) ($row['u_name'] ?? 'user')) : $name;
    }

    /** Toggle the viewer's like on a published post. */
    public function post_likeAction(){
        if (empty(Session::get('user_id'))) { echo json_encode(array('success' => false, 'message' => 'Sign in to like posts', 'need_login' => true)); exit; }
        $viewer = (int) Session::get('user_id');
        $post   = (new PostsModel())->get_by_id((int) ($this->post['id'] ?? 0));
        if (!$post) { echo json_encode(array('success' => false, 'message' => 'Post not found')); exit; }
        if (!$this->post_engagement_ok($post, $viewer)) { echo json_encode(array('success' => false, 'message' => 'You cannot like this post')); exit; }
        $likes = new PostLikesModel();
        $liked = $likes->toggle((int) $post['id'], $viewer);
        $count = $likes->count((int) $post['id']);
        (new PostsModel())->set_counter((int) $post['id'], 'likes', $count);
        echo json_encode(array('success' => true, 'liked' => $liked, 'likes' => $count)); exit;
    }

    /** List a post's comments (only for entitled viewers). */
    public function post_commentsAction(){
        $viewer = (int) Session::get('user_id');
        $post   = (new PostsModel())->get_by_id((int) ($_GET['id'] ?? 0));
        if (!$post) { echo json_encode(array('success' => false, 'message' => 'Post not found')); exit; }
        if (!$this->post_engagement_ok($post, $viewer)) { echo json_encode(array('success' => true, 'comments' => array(), 'can_comment' => false, 'comments_enabled' => (int) $post['comments_enabled'])); exit; }
        $out = array();
        foreach ((new PostCommentsModel())->list_for_post((int) $post['id']) as $r) {
            $name = $this->commenter_name($r);
            $out[] = array(
                'id'         => (int) $r['id'],
                'name'       => $name,
                'initial'    => strtoupper(mb_substr(ltrim($name, '@'), 0, 1)),
                'body'       => (string) $r['body'],
                'when'       => $this->time_ago($r['created_at']),
                'can_delete' => ($viewer > 0 && ($viewer === (int) $r['user_id'] || $viewer === (int) $post['creator_id'])),
            );
        }
        echo json_encode(array('success' => true, 'comments' => $out,
            'can_comment' => ($viewer > 0 && (int) $post['comments_enabled'] === 1),
            'comments_enabled' => (int) $post['comments_enabled'])); exit;
    }

    /** Add a comment to a post. */
    public function post_comment_addAction(){
        if (empty(Session::get('user_id'))) { echo json_encode(array('success' => false, 'message' => 'Sign in to comment', 'need_login' => true)); exit; }
        $viewer = (int) Session::get('user_id');
        $post   = (new PostsModel())->get_by_id((int) ($this->post['id'] ?? 0));
        if (!$post) { echo json_encode(array('success' => false, 'message' => 'Post not found')); exit; }
        if ((int) $post['comments_enabled'] !== 1) { echo json_encode(array('success' => false, 'message' => 'Comments are turned off for this post')); exit; }
        if (!$this->post_engagement_ok($post, $viewer)) { echo json_encode(array('success' => false, 'message' => 'You cannot comment on this post')); exit; }
        $body = html_entity_decode(trim((string) ($this->post['body'] ?? '')), ENT_QUOTES);
        if ($body === '') { echo json_encode(array('success' => false, 'message' => 'Write something first')); exit; }
        $comments = new PostCommentsModel();
        $comments->add((int) $post['id'], $viewer, mb_substr($body, 0, 2000));
        $count = $comments->count((int) $post['id']);
        (new PostsModel())->set_counter((int) $post['id'], 'comments', $count);
        echo json_encode(array('success' => true, 'count' => $count)); exit;
    }

    /** Delete a comment (its author, or the post's creator). */
    public function post_comment_deleteAction(){
        if (empty(Session::get('user_id'))) { echo json_encode(array('success' => false, 'message' => 'Sign in first', 'need_login' => true)); exit; }
        $viewer   = (int) Session::get('user_id');
        $comments = new PostCommentsModel();
        $c        = $comments->get_one((int) ($this->post['comment_id'] ?? 0));
        if (!$c) { echo json_encode(array('success' => false, 'message' => 'Comment not found')); exit; }
        $post = (new PostsModel())->get_by_id((int) $c['post_id']);
        if ($viewer !== (int) $c['user_id'] && !($post && $viewer === (int) $post['creator_id'])) {
            echo json_encode(array('success' => false, 'message' => 'Not allowed')); exit;
        }
        $comments->soft_delete((int) $c['id']);
        $count = $comments->count((int) $c['post_id']);
        (new PostsModel())->set_counter((int) $c['post_id'], 'comments', $count);
        echo json_encode(array('success' => true, 'count' => $count)); exit;
    }

    /** Record a view, deduped per unique viewer. Creator's own views don't count. */
    public function post_viewAction(){
        $viewer = (int) Session::get('user_id');
        $post   = (new PostsModel())->get_by_id((int) ($this->post['id'] ?? 0));
        if (!$post) { echo json_encode(array('success' => false)); exit; }
        if (!$this->post_engagement_ok($post, $viewer) || $viewer === (int) $post['creator_id']) {
            echo json_encode(array('success' => true, 'views' => (int) $post['views'])); exit;
        }
        $key = $viewer > 0
            ? ('u:' . $viewer)
            : ('ip:' . substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 40));
        $views = new PostViewsModel();
        if ($views->record((int) $post['id'], $key)) {
            $count = $views->count((int) $post['id']);
            (new PostsModel())->set_counter((int) $post['id'], 'views', $count);
            echo json_encode(array('success' => true, 'views' => $count)); exit;
        }
        echo json_encode(array('success' => true, 'views' => (int) $post['views'])); exit;
    }

    /** Signed, ready asset URLs for a post — returned to a viewer who is entitled to see it. */
    private function ppv_reveal_assets(array $post){
        $out = array();
        foreach ((new PostsModel())->get_assets((int) $post['id']) as $a) {
            if (!empty($a['deleted_at']) || $a['status'] !== 'ready') { continue; }
            if ($a['type'] === 'video') {
                $out[] = array('type' => 'video',
                    'url'    => MediaService::signed_variant($a, 'original', 900),
                    'poster' => MediaService::signed_variant($a, 'poster', 900));
            } else {
                $out[] = array('type' => 'image',
                    'url' => MediaService::signed_variant($a, 'display', 900), 'poster' => '');
            }
        }
        return $out;
    }

    /** Spend credits to unlock a pay-per-view post. Records the unlock and pays the creator. */
    public function ppv_unlockAction(){
        $viewer = (int) Session::get('user_id');
        if ($viewer <= 0) {
            echo json_encode(array('success' => false, 'need_login' => true, 'message' => 'Sign in to unlock this post.')); exit;
        }
        $post_id = (int) ($this->post['post_id'] ?? 0);
        $post    = (new PostsModel())->get_by_id($post_id);
        if (!$post || $post['state'] !== 'published' || $post['audience'] !== 'ppv') {
            echo json_encode(array('success' => false, 'message' => 'That post is not available.')); exit;
        }
        $creator_id = (int) $post['creator_id'];
        $price      = (int) $post['ppv_price_credits'];

        // The creator sees their own PPV posts unlocked, for free.
        if ($creator_id === $viewer) {
            echo json_encode(array('success' => true, 'assets' => $this->ppv_reveal_assets($post))); exit;
        }
        if ($price <= 0) { echo json_encode(array('success' => false, 'message' => 'This post is not for sale.')); exit; }

        $unlocks = new PpvUnlocksModel();
        $credits = new CreditsModel();

        if ($unlocks->has_unlocked($post_id, $viewer)) {
            echo json_encode(array('success' => true, 'already' => true, 'assets' => $this->ppv_reveal_assets($post))); exit;
        }
        $balance = $credits->get_balance($viewer);
        if ($balance < $price) {
            echo json_encode(array('success' => false, 'need_credits' => true, 'balance' => $balance,
                'price' => $price, 'shortfall' => $price - $balance,
                'message' => 'You need ' . ($price - $balance) . ' more credits to unlock this.')); exit;
        }

        // Record first: the UNIQUE(post_id, fan_id) key is the mutex that prevents a
        // double charge from concurrent clicks. Then debit; roll the row back if it fails.
        if (!$unlocks->record($post_id, $creator_id, $viewer, $price)) {
            echo json_encode(array('success' => true, 'already' => true, 'assets' => $this->ppv_reveal_assets($post))); exit;
        }
        if ($credits->apply_delta($viewer, -$price, 'ppv_unlock', 'Unlocked a post') === false) {
            $unlocks->remove($post_id, $viewer);
            echo json_encode(array('success' => false, 'need_credits' => true, 'balance' => $credits->get_balance($viewer),
                'price' => $price, 'message' => 'Not enough credits.')); exit;
        }

        // Pay the creator their share (net of the platform fee) and record per-post revenue.
        $net = (int) round($price * (100 - Main::platform_fee_percent()) / 100);
        if ($net > 0) {
            $credits->apply_delta($creator_id, $net, 'ppv_earning', 'Pay-per-view unlock');
            (new PostsModel())->add_earnings($post_id, $net * 10); // 1 credit = 10 cents
        }

        echo json_encode(array('success' => true, 'assets' => $this->ppv_reveal_assets($post),
            'balance' => $credits->get_balance($viewer))); exit;
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
        // Refresh presence (throttled to ~once/45s so we don't write on every call).
        $last = $user['last_active_at'] ?? null;
        if ($last === null || strtotime((string) $last . ' UTC') < time() - 45) {
            $this->userModel->touch_last_active((int) $user['user_id']);
        }
        return $user;
    }

    /** Presence heartbeat — pinged by the Studio so an open-but-idle creator stays "online". */
    public function heartbeatAction(){
        $this->require_creator();
        echo json_encode(array('success' => true));
        exit;
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
        ));

        echo json_encode(array('success' => true, 'message' => 'Profile saved'));
        exit;
    }

    /** Generate brand details from a website URL (Claude). Does not persist. */
    public function generate_brand_identityAction(){
        $this->require_creator();
        $url = html_entity_decode(trim((string) ($this->post['url'] ?? '')), ENT_QUOTES);
        if ($url === '') {
            echo json_encode(array('success' => false, 'message' => 'Enter your website URL first.'));
            exit;
        }
        $result = BrandService::generate_from_url($url);
        if (empty($result['ok'])) {
            echo json_encode(array('success' => false, 'message' => $result['error'] ?? 'Generation failed. Try again.'));
            exit;
        }
        echo json_encode(array('success' => true, 'brand' => $result['data']));
        exit;
    }

    /** Persist the reviewed/edited brand identity for this creator. */
    public function save_brand_identityAction(){
        $this->require_creator();
        $split = function ($v) {
            if (is_array($v)) { return array_values(array_filter(array_map('trim', $v), 'strlen')); }
            $v = html_entity_decode((string) $v, ENT_QUOTES);
            return array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
        };
        $dec = function ($k) { return html_entity_decode(trim((string) ($this->post[$k] ?? '')), ENT_QUOTES); };

        (new CreatorBrandModel())->save((int) Session::get('user_id'), array(
            'source_url'  => $dec('source_url'),
            'brand_name'  => $dec('brand_name'),
            'tagline'     => $dec('tagline'),
            'description' => $dec('description'),
            'voice'       => $dec('voice'),
            'colors'      => $split($this->post['colors'] ?? ''),
            'keywords'    => $split($this->post['keywords'] ?? ''),
        ));
        echo json_encode(array('success' => true, 'message' => 'Brand identity saved'));
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

    /* ---------- Content Studio: media vault ---------- */

    /** Accepted vault media: real mime => [type, extension, max bytes]. */
    private function studio_media_types(){
        return array(
            // images (processed server-side via GD)
            'image/jpeg' => array('image', 'jpg',  15728640),
            'image/png'  => array('image', 'png',  15728640),
            'image/webp' => array('image', 'webp', 15728640),
            'image/gif'  => array('gif',   'gif',  15728640),
            // videos (resumable multipart; poster + duration extracted via ffmpeg)
            'video/mp4'       => array('video', 'mp4',  4294967296),
            'video/quicktime' => array('video', 'mov',  4294967296),
            'video/webm'      => array('video', 'webm', 4294967296),
        );
    }

    /** Shape a media_assets row for the client, with a fresh signed thumbnail URL. */
    private function studio_asset_json(array $a, $creator_id){
        $name = (isset($a['display_name']) && $a['display_name'] !== null && $a['display_name'] !== '')
            ? $a['display_name'] : $a['filename'];
        $tags = array();
        foreach (explode(',', (string) ($a['tags'] ?? '')) as $t) {
            $t = trim($t);
            if ($t !== '') { $tags[] = $t; }
        }
        $thumb = ($a['status'] === 'ready') ? MediaService::signed_url($a, 'thumb', $creator_id) : '';
        return array(
            'id'                => (int) $a['id'],
            'type'              => $a['type'],
            'status'            => $a['status'],
            'name'              => $name,
            'filename'          => $a['filename'],
            'description'       => (string) ($a['description'] ?? ''),
            'tags'              => $tags,
            'duration'          => isset($a['duration_sec']) && $a['duration_sec'] !== null ? (int) $a['duration_sec'] : null,
            'width'             => isset($a['width'])  && $a['width']  !== null ? (int) $a['width']  : null,
            'height'            => isset($a['height']) && $a['height'] !== null ? (int) $a['height'] : null,
            'bytes'             => isset($a['bytes'])  && $a['bytes']  !== null ? (int) $a['bytes']  : null,
            'watermark_applied' => (int) ($a['watermark_applied'] ?? 0),
            'usage_count'       => (int) ($a['usage_count'] ?? 0),
            'failure_reason'    => (string) ($a['failure_reason'] ?? ''),
            'moderation'        => ($a['type'] === 'image') ? (string) ($a['moderation_status'] ?? 'pending') : 'n/a',
            'created_at'        => $a['created_at'],
            'thumb_url'         => $thumb,
            'video_url'         => ($a['type'] === 'video' && $a['status'] === 'ready') ? MediaService::signed_url($a, 'original', $creator_id) : '',
        );
    }

    /** Single-request upload for images/gifs (small enough for one POST). */
    public function media_uploadAction(){
        @ini_set('memory_limit', '512M');
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];

        if (!S3Service::configured()) {
            echo json_encode(array('success' => false, 'message' => 'Uploads are unavailable right now. Please try again shortly.')); exit;
        }
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            echo json_encode(array('success' => false, 'message' => 'No file was received. Please pick a file and try again.')); exit;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($file['tmp_name']);
        $types = $this->studio_media_types();
        if (!isset($types[$mime]) || $types[$mime][0] === 'video') {
            echo json_encode(array('success' => false, 'message' => 'That file type is not supported here. Use JPG, PNG, WebP, or GIF.')); exit;
        }
        list($type, $ext, $max) = $types[$mime];
        if ((int) $file['size'] > $max) {
            echo json_encode(array('success' => false, 'message' => 'That image is too large. Images can be up to 15 MB.')); exit;
        }

        $orig_name = (string) ($file['name'] ?? 'upload.' . $ext);
        $model     = new MediaAssetsModel();
        $asset_id  = (int) $model->add($creator_id, $type, $orig_name, $mime, 'processing');
        if ($asset_id <= 0) {
            echo json_encode(array('success' => false, 'message' => 'Could not start the upload. Please try again.')); exit;
        }

        $watermark = !empty($user['watermark_enabled']);
        $res = MediaService::process_image($creator_id, $asset_id, $file['tmp_name'], $ext, $mime, $user, $watermark);
        if (isset($res['error'])) {
            $model->set_failed($creator_id, $asset_id, $res['error']);
            $a = $model->get_one($creator_id, $asset_id);
            echo json_encode(array('success' => false, 'message' => $res['error'], 'asset' => $a ? $this->studio_asset_json($a, $creator_id) : null)); exit;
        }
        $model->set_ready($creator_id, $asset_id, $res);
        $a = $model->get_one($creator_id, $asset_id);
        echo json_encode(array('success' => true, 'asset' => $this->studio_asset_json($a, $creator_id)));
        exit;
    }

    /** Generate an image with OpenAI, folding in the creator's brand, and ingest it as a vault asset. */
    public function media_generateAction(){
        @set_time_limit(180);
        @ini_set('memory_limit', '512M');
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        // Long job (~40s) — release the session lock so other tabs aren't blocked behind it.
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

        if (!S3Service::configured()) {
            echo json_encode(array('success' => false, 'message' => 'Image generation is unavailable right now. Please try again shortly.')); exit;
        }
        $prompt = html_entity_decode(trim((string) ($this->post['prompt'] ?? '')), ENT_QUOTES);
        if ($prompt === '') {
            echo json_encode(array('success' => false, 'message' => 'Describe the image you want to generate.')); exit;
        }
        $size_key  = (string) ($this->post['size'] ?? 'square');
        $use_brand = ((string) ($this->post['use_brand'] ?? '1')) !== '0';

        $final = $prompt; $brand_used = false;
        if ($use_brand) {
            $cb = (new CreatorBrandModel())->get_for_user($creator_id);
            if (!empty($cb['brand_name']) || !empty($cb['colors']) || !empty($cb['voice']) || !empty($cb['keywords'])) {
                $final = $this->brand_image_prompt($prompt, $cb);
                $brand_used = true;
            }
        }

        $res = ImageGenService::generate($final, ImageGenService::dimensions($size_key));
        if (empty($res['ok'])) {
            echo json_encode(array('success' => false, 'message' => $res['error'] ?? 'Generation failed. Try again.')); exit;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'gen');
        if ($tmp === false || file_put_contents($tmp, $res['bytes']) === false) {
            echo json_encode(array('success' => false, 'message' => 'Could not process the generated image. Try again.')); exit;
        }

        $model    = new MediaAssetsModel();
        $label    = 'Generated · ' . mb_substr($prompt, 0, 40);
        $asset_id = (int) $model->add($creator_id, 'image', $label . '.png', 'image/png', 'processing');
        if ($asset_id <= 0) { @unlink($tmp); echo json_encode(array('success' => false, 'message' => 'Could not save the image. Try again.')); exit; }

        $watermark = !empty($user['watermark_enabled']);
        $r = MediaService::process_image($creator_id, $asset_id, $tmp, 'png', 'image/png', $user, $watermark);
        @unlink($tmp);
        if (isset($r['error'])) {
            $model->set_failed($creator_id, $asset_id, $r['error']);
            echo json_encode(array('success' => false, 'message' => $r['error'])); exit;
        }
        $model->set_ready($creator_id, $asset_id, $r);
        $a = $model->get_one($creator_id, $asset_id);
        echo json_encode(array('success' => true, 'brand_used' => $brand_used, 'asset' => $this->studio_asset_json($a, $creator_id)));
        exit;
    }

    /** Weave the creator's brand into an image prompt (shared with the Scheduler). */
    private function brand_image_prompt($prompt, $cb){
        return BrandService::image_prompt($prompt, (array) $cb);
    }

    /** Validate an IANA timezone id (rejects the 'UTC' default sentinel). Returns '' if invalid. */
    private function valid_tz($tz){
        $tz = trim((string) $tz);
        if ($tz === '' || strtoupper($tz) === 'UTC') { return ''; }
        try { new DateTimeZone($tz); return $tz; } catch (\Throwable $e) { return ''; }
    }

    /** Persist the creator's real timezone (auto-detected from their browser). */
    public function set_timezoneAction(){
        $user = $this->require_creator();
        $tz   = $this->valid_tz($this->post['timezone'] ?? '');
        if ($tz === '') { echo json_encode(array('success' => false, 'message' => 'Invalid timezone')); exit; }
        (new UsersModel())->set_content_timezone((int) $user['user_id'], $tz);
        echo json_encode(array('success' => true, 'timezone' => $tz));
        exit;
    }

    // ---------- Scheduler (automations) ----------

    private function cadence_summary($r){
        $t = date('g:i A', strtotime('2000-01-01 ' . (string) $r['run_time']));
        if (($r['cadence'] ?? 'daily') === 'weekly') {
            $names = array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat');
            $days  = array_filter(array_map('intval', explode(',', (string) $r['days_of_week'])), function ($d) { return $d >= 0 && $d <= 6; });
            $lbl   = empty($days) ? 'Weekly' : implode(', ', array_map(function ($d) use ($names) { return $names[$d]; }, $days));
            return $lbl . ' · ' . $t;
        }
        return 'Daily · ' . $t;
    }

    private function scheduler_next_human($utc, $tz){
        if ((string) $utc === '' || $utc === null) { return ''; }
        try {
            $d = new DateTime((string) $utc, new DateTimeZone('UTC'));
            $d->setTimezone(new DateTimeZone($tz ?: 'UTC'));
            return $d->format('M j, g:i A');
        } catch (\Throwable $e) { return ''; }
    }

    private function scheduler_rule_json($r, $tz){
        $accounts = json_decode((string) ($r['social_accounts'] ?? '[]'), true) ?: array();
        $days     = array_values(array_filter(array_map('intval', explode(',', (string) $r['days_of_week'])), function ($d) { return $d >= 0 && $d <= 6; }));
        return array(
            'id'               => (int) $r['id'],
            'name'             => (string) $r['name'],
            'active'           => (int) $r['active'],
            'topic'            => (string) $r['topic'],
            'size'             => (string) $r['size'],
            'audience'         => (string) $r['audience'],
            'tier_id'          => $r['tier_id'] !== null ? (int) $r['tier_id'] : null,
            'comments_enabled' => (int) $r['comments_enabled'],
            'use_brand'        => (int) $r['use_brand'],
            'social_accounts'  => array_map('strval', (array) $accounts),
            'cadence'          => (string) $r['cadence'],
            'days_of_week'     => $days,
            'run_time'         => substr((string) $r['run_time'], 0, 5),
            'cadence_summary'  => $this->cadence_summary($r),
            'next_run'         => $this->scheduler_next_human($r['next_run_at'] ?? '', $tz),
            'last_status'      => (string) $r['last_status'],
            'last_run'         => $this->scheduler_next_human($r['last_run_at'] ?? '', $tz),
        );
    }

    public function scheduler_listAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $tz         = (string) ($user['content_timezone'] ?? 'UTC');
        $out        = array();
        foreach ((new SchedulerRulesModel())->list_for_creator($creator_id) as $r) {
            $out[] = $this->scheduler_rule_json($r, $tz);
        }
        echo json_encode(array('success' => true, 'rules' => $out, 'can_social' => Plan::can_social_post($user)));
        exit;
    }

    public function scheduler_saveAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $name       = trim(html_entity_decode((string) ($this->post['name'] ?? ''), ENT_QUOTES));
        $topic      = trim(html_entity_decode((string) ($this->post['topic'] ?? ''), ENT_QUOTES));
        if ($name === '')  { echo json_encode(array('success' => false, 'message' => 'Give your automation a name.')); exit; }
        if ($topic === '') { echo json_encode(array('success' => false, 'message' => 'Describe what to post (the topic).')); exit; }

        $fields = array(
            'name'             => $name,
            'topic'            => $topic,
            'active'           => ((string) ($this->post['active'] ?? '1')) !== '0',
            'size'             => (string) ($this->post['size'] ?? 'square'),
            'audience'         => (string) ($this->post['audience'] ?? 'free'),
            'tier_id'          => (int) ($this->post['tier_id'] ?? 0),
            'comments_enabled' => ((string) ($this->post['comments_enabled'] ?? '1')) !== '0',
            'use_brand'        => ((string) ($this->post['use_brand'] ?? '1')) !== '0',
            'social_accounts'  => $this->post['social_accounts'] ?? array(),
            'cadence'          => (string) ($this->post['cadence'] ?? 'daily'),
            'days_of_week'     => $this->post['days_of_week'] ?? array(),
            'run_time'         => (string) ($this->post['run_time'] ?? '09:00'),
            'timezone'         => $this->valid_tz($this->post['timezone'] ?? '') ?: (string) ($user['content_timezone'] ?? 'UTC'),
        );
        $model = new SchedulerRulesModel();
        if ($id > 0 && $model->get_one($creator_id, $id)) {
            $model->update_rule($creator_id, $id, $fields);
        } else {
            $id = (int) $model->create($creator_id, $fields);
        }
        echo json_encode(array('success' => true, 'message' => 'Automation saved', 'id' => $id));
        exit;
    }

    public function scheduler_toggleAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $active     = ((string) ($this->post['active'] ?? '0')) === '1';
        (new SchedulerRulesModel())->set_active($creator_id, (int) ($this->post['id'] ?? 0), $active);
        echo json_encode(array('success' => true, 'active' => $active ? 1 : 0));
        exit;
    }

    public function scheduler_deleteAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        (new SchedulerRulesModel())->delete_rule($creator_id, (int) ($this->post['id'] ?? 0));
        echo json_encode(array('success' => true, 'message' => 'Automation removed'));
        exit;
    }

    /** Run an automation immediately (test path) — same pipeline the worker uses. */
    public function scheduler_run_nowAction(){
        @set_time_limit(180);
        @ini_set('memory_limit', '512M');
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        // Long job (~40s) — release the session lock so other tabs aren't blocked behind it.
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        $rule       = (new SchedulerRulesModel())->get_one($creator_id, (int) ($this->post['id'] ?? 0));
        if (!$rule) { echo json_encode(array('success' => false, 'message' => 'Automation not found')); exit; }
        $res = AutoPostService::run_rule($rule, $user);
        (new SchedulerRunsModel())->add((int) $rule['id'], $creator_id, $res['ok'] ? 'success' : 'failed', $res['post_id'], $res['message']);
        (new SchedulerRulesModel())->set_last_run((int) $rule['id'], $res['ok'] ? 'success' : 'failed');
        echo json_encode(array('success' => $res['ok'], 'message' => $res['message'], 'post_id' => $res['post_id']));
        exit;
    }

    /** Begin (or resume) a resumable multipart video upload. */
    public function media_upload_initAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        if (!S3Service::configured()) {
            echo json_encode(array('success' => false, 'message' => 'Uploads are unavailable right now. Please try again shortly.')); exit;
        }
        $filename = trim(html_entity_decode((string) ($this->post['filename'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $mime     = (string) ($this->post['mime'] ?? '');
        $bytes    = (int) ($this->post['bytes_total'] ?? 0);
        $token    = (string) ($this->post['client_token'] ?? '');
        $types    = $this->studio_media_types();
        if (!isset($types[$mime]) || $types[$mime][0] !== 'video') {
            echo json_encode(array('success' => false, 'message' => 'That video format is not supported. Use MP4, MOV, or WebM.')); exit;
        }
        list($type, $ext, $max) = $types[$mime];
        if ($bytes <= 0 || $bytes > $max) {
            echo json_encode(array('success' => false, 'message' => 'That video is too large. Videos can be up to 4 GB.')); exit;
        }

        $sessions = new UploadSessionsModel();
        // Resume an existing active session for the same file if we have one.
        if ($token !== '') {
            $existing = $sessions->get_active_by_token($creator_id, $token);
            if ($existing) {
                $nums = array();
                foreach ($sessions->parts($existing) as $p) { $nums[] = (int) $p['PartNumber']; }
                echo json_encode(array(
                    'success'         => true,
                    'session_id'      => (int) $existing['id'],
                    'asset_id'        => (int) $existing['asset_id'],
                    'part_size'       => 8388608,
                    'uploaded_parts'  => $nums,
                    'bytes_received'  => (int) $existing['bytes_received'],
                    'resumed'         => true,
                )); exit;
            }
        }

        $model    = new MediaAssetsModel();
        $asset_id = (int) $model->add($creator_id, 'video', $filename !== '' ? $filename : ('video.' . $ext), $mime, 'uploading');
        if ($asset_id <= 0) {
            echo json_encode(array('success' => false, 'message' => 'Could not start the upload. Please try again.')); exit;
        }
        $key       = MediaService::key($creator_id, $asset_id, 'original', $ext);
        $upload_id = S3Service::create_multipart($key, $mime);
        if ($upload_id === '') {
            $model->set_failed($creator_id, $asset_id, 'Could not begin the upload');
            echo json_encode(array('success' => false, 'message' => 'Could not begin the upload. Please try again.')); exit;
        }
        $session_id = (int) $sessions->create($creator_id, $filename, $mime, $bytes, $key, $upload_id, $token, $asset_id);

        echo json_encode(array(
            'success'        => true,
            'session_id'     => $session_id,
            'asset_id'       => $asset_id,
            'part_size'      => 8388608,
            'uploaded_parts' => array(),
            'bytes_received' => 0,
            'resumed'        => false,
        ));
        exit;
    }

    /** Upload one ~8 MB part of a resumable video upload. */
    public function media_upload_chunkAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $session_id = (int) ($this->post['session_id'] ?? 0);
        $part_no    = (int) ($this->post['part_number'] ?? 0);
        $sessions   = new UploadSessionsModel();
        $session    = $sessions->get_one($creator_id, $session_id);
        if (!$session || $session['status'] !== 'active') {
            echo json_encode(array('success' => false, 'message' => 'This upload session is no longer active. Please restart the upload.')); exit;
        }
        if ($part_no < 1) {
            echo json_encode(array('success' => false, 'message' => 'Invalid upload chunk.')); exit;
        }
        $chunk = $_FILES['chunk'] ?? null;
        if (!$chunk || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($chunk['tmp_name'])) {
            echo json_encode(array('success' => false, 'message' => 'That chunk did not arrive. It will be retried.')); exit;
        }
        $body = @file_get_contents($chunk['tmp_name']);
        $size = strlen((string) $body);
        $etag = S3Service::upload_part($session['storage_key'], $session['s3_upload_id'], $part_no, $body);
        if ($etag === '') {
            echo json_encode(array('success' => false, 'message' => 'That chunk failed to store. It will be retried.')); exit;
        }
        $sessions->add_part($creator_id, $session_id, $part_no, $etag, $size);
        $fresh = $sessions->get_one($creator_id, $session_id);
        echo json_encode(array('success' => true, 'bytes_received' => (int) $fresh['bytes_received']));
        exit;
    }

    /** Report resume state for a file fingerprint (uploaded part numbers). */
    public function media_upload_statusAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $token      = (string) ($_GET['client_token'] ?? '');
        $session    = $token !== '' ? (new UploadSessionsModel())->get_active_by_token($creator_id, $token) : null;
        if (!$session) {
            echo json_encode(array('success' => true, 'active' => false)); exit;
        }
        $nums = array();
        foreach ((new UploadSessionsModel())->parts($session) as $p) { $nums[] = (int) $p['PartNumber']; }
        echo json_encode(array(
            'success'        => true,
            'active'         => true,
            'session_id'     => (int) $session['id'],
            'asset_id'       => (int) $session['asset_id'],
            'part_size'      => 8388608,
            'uploaded_parts' => $nums,
            'bytes_received' => (int) $session['bytes_received'],
        ));
        exit;
    }

    /** Finish a multipart video upload: assemble in S3, extract poster+duration, go ready. */
    public function media_upload_completeAction(){
        @ini_set('memory_limit', '512M');
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $session_id = (int) ($this->post['session_id'] ?? 0);
        $sessions   = new UploadSessionsModel();
        $session    = $sessions->get_one($creator_id, $session_id);
        if (!$session || $session['status'] !== 'active') {
            echo json_encode(array('success' => false, 'message' => 'This upload session is no longer active. Please restart the upload.')); exit;
        }
        $asset_id = (int) $session['asset_id'];
        $model    = new MediaAssetsModel();

        $parts = $sessions->parts($session);
        if (empty($parts)) {
            echo json_encode(array('success' => false, 'message' => 'No video data was received. Please try the upload again.')); exit;
        }
        $s3parts = array();
        foreach ($parts as $p) { $s3parts[] = array('PartNumber' => (int) $p['PartNumber'], 'ETag' => (string) $p['ETag']); }
        if (!S3Service::complete_multipart($session['storage_key'], $session['s3_upload_id'], $s3parts)) {
            $model->set_failed($creator_id, $asset_id, 'Could not assemble the uploaded video');
            echo json_encode(array('success' => false, 'message' => 'The upload could not be finalized. Please try again.')); exit;
        }

        // Read the assembled original back through a short-lived signed URL and let
        // ffmpeg/ffprobe (server-side) extract the poster frame + duration/dimensions.
        $src   = S3Service::presigned_get_url($session['storage_key'], 900);
        $probe = MediaService::probe_video($src);
        $res   = MediaService::process_video($creator_id, $asset_id, $src, $user);
        if (isset($res['error'])) {
            $model->set_failed($creator_id, $asset_id, $res['error']);
            echo json_encode(array('success' => false, 'message' => $res['error'])); exit;
        }
        $fields = array_merge($res, array(
            'original_key' => $session['storage_key'],
            'bytes'        => (int) $session['bytes_received'],
            'duration_sec' => (int) ($probe['duration'] ?? 0),
            'width'        => (int) ($probe['width'] ?? 0),
            'height'       => (int) ($probe['height'] ?? 0),
        ));
        $model->set_ready($creator_id, $asset_id, $fields);
        $sessions->mark_completed($creator_id, $session_id, $asset_id);
        $a = $model->get_one($creator_id, $asset_id);
        echo json_encode(array('success' => true, 'asset' => $this->studio_asset_json($a, $creator_id)));
        exit;
    }

    /** Vault listing with filters (type, collection, usage, search). */
    public function media_listAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $filters = array(
            'type'       => (string) ($_GET['type'] ?? ''),
            'collection' => (int) ($_GET['collection'] ?? 0),
            'usage'      => (string) ($_GET['usage'] ?? ''),
            'search'     => (string) ($_GET['search'] ?? ''),
        );
        $rows = (new MediaAssetsModel())->get_for_creator($creator_id, $filters);
        $assets = array();
        foreach ($rows as $a) { $assets[] = $this->studio_asset_json($a, $creator_id); }
        echo json_encode(array('success' => true, 'assets' => $assets, 'total' => count($assets)));
        exit;
    }

    /** Full detail for one asset: signed preview, metadata, usage, collections. */
    public function media_getAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($_GET['id'] ?? 0);
        $model      = new MediaAssetsModel();
        $a          = $model->get_one($creator_id, $id);
        if (!$a) { echo json_encode(array('success' => false, 'message' => 'That file was not found.')); exit; }

        $preview = ($a['type'] === 'video')
            ? MediaService::signed_url($a, 'poster', $creator_id)
            : MediaService::signed_url($a, 'display', $creator_id);
        $video_url = ($a['type'] === 'video') ? MediaService::signed_url($a, 'original', $creator_id) : '';
        $usage = $model->get_posts_using($creator_id, $id);
        $out = $this->studio_asset_json($a, $creator_id);
        $out['preview_url']    = $preview;
        $out['video_url']      = $video_url;
        $out['collection_ids'] = $model->get_collection_ids($id);
        $out['posts']          = array();
        foreach ($usage as $p) {
            $cap = trim((string) $p['caption']);
            $out['posts'][] = array(
                'id'      => (int) $p['id'],
                'excerpt' => $cap === '' ? '(no caption)' : mb_substr($cap, 0, 60),
                'state'   => $p['state'],
            );
        }
        echo json_encode(array('success' => true, 'asset' => $out));
        exit;
    }

    /** Rename + retag an asset. */
    public function media_updateAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new MediaAssetsModel();
        if (!$model->get_one($creator_id, $id)) {
            echo json_encode(array('success' => false, 'message' => 'That file was not found.')); exit;
        }
        $description = trim(html_entity_decode((string) ($this->post['description'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $model->set_description($creator_id, $id, $description);
        $a = $model->get_one($creator_id, $id);
        echo json_encode(array('success' => true, 'asset' => $this->studio_asset_json($a, $creator_id)));
        exit;
    }

    /** Mint a fresh signed URL for a variant (used when a grid thumb URL expires). */
    public function media_signAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($_GET['id'] ?? 0);
        $variant    = (string) ($_GET['variant'] ?? 'thumb');
        $a          = (new MediaAssetsModel())->get_one($creator_id, $id);
        if (!$a) { echo json_encode(array('success' => false, 'message' => 'That file was not found.')); exit; }
        echo json_encode(array('success' => true, 'url' => MediaService::signed_url($a, $variant, $creator_id)));
        exit;
    }

    /** Toggle the baked watermark on an image by re-processing from the original. */
    public function media_watermarkAction(){
        @ini_set('memory_limit', '512M');
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $enabled    = ((string) ($this->post['enabled'] ?? '1')) === '1';
        $model      = new MediaAssetsModel();
        $a          = $model->get_one($creator_id, $id);
        if (!$a) { echo json_encode(array('success' => false, 'message' => 'That file was not found.')); exit; }
        if ($a['type'] === 'video') {
            echo json_encode(array('success' => false, 'message' => 'Video watermarks show as an overlay on the player and cannot be turned off per-file.')); exit;
        }
        if (empty($a['original_key'])) {
            echo json_encode(array('success' => false, 'message' => 'The original file is unavailable, so the watermark cannot be changed.')); exit;
        }
        // Pull the original down to a temp file, then re-run the image pipeline.
        $url = S3Service::presigned_get_url($a['original_key'], 300);
        $tmp = tempnam(sys_get_temp_dir(), 'wm');
        $bytes = $url !== '' ? @file_get_contents($url) : false;
        if ($bytes === false || $bytes === '') {
            @unlink($tmp);
            echo json_encode(array('success' => false, 'message' => 'Could not read the original file. Please try again.')); exit;
        }
        file_put_contents($tmp, $bytes);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        $types = $this->studio_media_types();
        $ext   = isset($types[$mime]) ? $types[$mime][1] : 'jpg';
        $res   = MediaService::process_image($creator_id, $id, $tmp, $ext, $mime, $user, $enabled);
        @unlink($tmp);
        if (isset($res['error'])) {
            echo json_encode(array('success' => false, 'message' => $res['error'])); exit;
        }
        $model->set_ready($creator_id, $id, $res);
        $a = $model->get_one($creator_id, $id);
        echo json_encode(array('success' => true, 'asset' => $this->studio_asset_json($a, $creator_id)));
        exit;
    }

    /** Soft-delete an asset (recoverable). Removes it from all collections. */
    public function media_deleteAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new MediaAssetsModel();
        $a          = $model->get_one($creator_id, $id);
        if (!$a) { echo json_encode(array('success' => false, 'message' => 'That file was not found.')); exit; }
        $affected = count($model->get_posts_using($creator_id, $id));
        $model->soft_delete($creator_id, $id);
        (new CollectionsModel())->remove_asset_everywhere($id);
        echo json_encode(array('success' => true, 'affected_posts' => $affected, 'message' => 'File removed.'));
        exit;
    }

    /** Bulk actions over selected assets: add to collection, tag, or delete. */
    public function media_bulkAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $action     = (string) ($this->post['bulk_action'] ?? '');
        $ids        = $this->post['ids'] ?? array();
        if (!is_array($ids)) { $ids = array(); }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) { echo json_encode(array('success' => false, 'message' => 'No files were selected.')); exit; }

        $model = new MediaAssetsModel();
        // Ownership: keep only assets that belong to this creator.
        $owned = array();
        foreach ($ids as $id) { if ($model->get_one($creator_id, $id)) { $owned[] = $id; } }
        if (empty($owned)) { echo json_encode(array('success' => false, 'message' => 'No matching files were found.')); exit; }

        if ($action === 'collection_add') {
            $col = (int) ($this->post['collection_id'] ?? 0);
            if (!(new CollectionsModel())->get_one($creator_id, $col)) {
                echo json_encode(array('success' => false, 'message' => 'That collection was not found.')); exit;
            }
            (new CollectionsModel())->add_assets($col, $owned);
            echo json_encode(array('success' => true, 'message' => count($owned) . ' file(s) added.')); exit;
        }
        if ($action === 'delete') {
            $col = new CollectionsModel();
            foreach ($owned as $id) { $model->soft_delete($creator_id, $id); $col->remove_asset_everywhere($id); }
            echo json_encode(array('success' => true, 'message' => count($owned) . ' file(s) removed.')); exit;
        }
        echo json_encode(array('success' => false, 'message' => 'Unknown action.'));
        exit;
    }

    /* ---------- Content Studio: collections ---------- */

    public function collections_listAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $rows = (new CollectionsModel())->get_for_creator($creator_id);
        $out = array();
        foreach ($rows as $c) {
            $out[] = array(
                'id'          => (int) $c['id'],
                'name'        => $c['name'],
                'asset_count' => (int) $c['asset_count'],
            );
        }
        echo json_encode(array('success' => true, 'collections' => $out));
        exit;
    }

    public function collection_saveAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $name       = trim(html_entity_decode((string) ($this->post['name'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($name === '') { echo json_encode(array('success' => false, 'message' => 'Give the collection a name.')); exit; }
        $model = new CollectionsModel();
        if ($id > 0) {
            if (!$model->get_one($creator_id, $id)) { echo json_encode(array('success' => false, 'message' => 'That collection was not found.')); exit; }
            $model->rename($creator_id, $id, $name);
        } else {
            $id = (int) $model->add($creator_id, $name);
        }
        echo json_encode(array('success' => true, 'id' => $id, 'name' => $name));
        exit;
    }

    public function collection_deleteAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        (new CollectionsModel())->delete_collection($creator_id, $id);
        echo json_encode(array('success' => true, 'message' => 'Collection deleted.'));
        exit;
    }

    public function collection_add_assetsAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $ids        = $this->post['ids'] ?? array();
        if (!is_array($ids)) { $ids = array(); }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $model = new CollectionsModel();
        if (!$model->get_one($creator_id, $id)) { echo json_encode(array('success' => false, 'message' => 'That collection was not found.')); exit; }
        // Only add assets this creator owns.
        $ma = new MediaAssetsModel();
        $owned = array();
        foreach ($ids as $aid) { if ($ma->get_one($creator_id, $aid)) { $owned[] = $aid; } }
        $model->add_assets($id, $owned);
        echo json_encode(array('success' => true, 'message' => count($owned) . ' file(s) added.'));
        exit;
    }

    public function collection_remove_assetsAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $ids        = $this->post['ids'] ?? array();
        if (!is_array($ids)) { $ids = array(); }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $model = new CollectionsModel();
        if (!$model->get_one($creator_id, $id)) { echo json_encode(array('success' => false, 'message' => 'That collection was not found.')); exit; }
        $model->remove_assets($id, $ids);
        echo json_encode(array('success' => true, 'message' => 'Removed from collection.'));
        exit;
    }

    /* ---------- Content Studio: post composer ---------- */

    private function post_audience($v){ return in_array($v, array('subscribers', 'ppv'), true) ? $v : 'free'; }

    /** Clamp a dollar PPV price ($3–$500) to credits ($1 = 10 credits). 0 if invalid. */
    private function ppv_credits_from_dollars($dollars){
        $d = (int) $dollars;
        if ($d < 3) { $d = 3; }
        if ($d > 500) { $d = 500; }
        return $d * 10;
    }

    /** Convert a creator-local 'YYYY-MM-DDTHH:MM' to a UTC 'Y-m-d H:i:s', or null. */
    private function to_utc($local, $tz){
        if ((string) $local === '') { return null; }
        try {
            $d = new DateTime((string) $local, new DateTimeZone($tz ?: 'UTC'));
            $d->setTimezone(new DateTimeZone('UTC'));
            return $d->format('Y-m-d H:i:s');
        } catch (\Throwable $e) { return null; }
    }

    /** Convert a stored UTC datetime to a creator-local 'YYYY-MM-DDTHH:MM' for pickers. */
    private function from_utc($utc, $tz){
        if ((string) $utc === '' || $utc === null) { return ''; }
        try {
            $d = new DateTime((string) $utc, new DateTimeZone('UTC'));
            $d->setTimezone(new DateTimeZone($tz ?: 'UTC'));
            return $d->format('Y-m-d\TH:i');
        } catch (\Throwable $e) { return ''; }
    }

    /** Shape a post + its media (with signed preview URLs) for the composer/preview. */
    private function studio_post_json(array $post, array $user){
        $creator_id = (int) $user['user_id'];
        $tz         = (string) ($user['content_timezone'] ?? 'UTC');
        $model      = new PostsModel();
        $assets     = $model->get_assets((int) $post['id']);

        $out = array(); $cover = null;
        foreach ($assets as $a) {
            $signable = array('creator_id' => $creator_id, 'type' => $a['type'],
                'thumb_key' => $a['thumb_key'], 'blurred_key' => $a['blurred_key'],
                'display_key' => $a['display_key'], 'poster_key' => $a['poster_key'],
                'original_key' => $a['original_key'] ?? '');
            $ready = ($a['status'] === 'ready' && empty($a['deleted_at']));
            $out[] = array(
                'id'       => (int) $a['asset_id'],
                'type'     => $a['type'],
                'status'   => $a['status'],
                'duration' => $a['duration_sec'] !== null ? (int) $a['duration_sec'] : null,
                'is_cover' => (int) $a['is_cover'],
                'missing'  => !empty($a['deleted_at']),
                'thumb_url'=> $ready ? MediaService::signed_url($signable, 'thumb', $creator_id) : '',
                'video_url'=> ($ready && $a['type'] === 'video') ? MediaService::signed_url($signable, 'original', $creator_id) : '',
            );
            if ((int) $a['is_cover'] === 1 && $cover === null) { $cover = $signable; }
        }
        if ($cover === null && !empty($assets)) {
            $f = $assets[0];
            $cover = array('creator_id' => $creator_id, 'type' => $f['type'],
                'thumb_key' => $f['thumb_key'], 'blurred_key' => $f['blurred_key'],
                'display_key' => $f['display_key'], 'poster_key' => $f['poster_key']);
        }
        $cover_display = ''; $cover_blurred = '';
        if ($cover) {
            $cover_display = MediaService::signed_url($cover, $cover['type'] === 'video' ? 'poster' : 'display', $creator_id);
            $cover_blurred = MediaService::signed_url($cover, 'blurred', $creator_id);
        }
        return array(
            'id'                => (int) $post['id'],
            'caption'           => (string) $post['caption'],
            'audience'          => $post['audience'],
            'tier_id'           => (isset($post['tier_id']) && $post['tier_id'] !== null) ? (int) $post['tier_id'] : null,
            'moderation'        => (string) ((new PostsModel())->moderation_map(array((int) $post['id']))[(int) $post['id']] ?? 'ok'),
            'ppv_price_credits' => ($post['ppv_price_credits'] ?? null) !== null ? (int) $post['ppv_price_credits'] : null,
            'ppv_price_dollars' => ($post['ppv_price_credits'] ?? null) !== null ? (int) round($post['ppv_price_credits'] / 10) : null,
            'comments_enabled'  => (int) ($post['comments_enabled'] ?? 1),
            'state'             => $post['state'],
            'scheduled_local'   => $this->from_utc($post['scheduled_at'] ?? '', $tz),
            'timezone'          => $tz,
            'assets'            => $out,
            'cover_display_url' => $cover_display,
            'cover_blurred_url' => $cover_blurred,
            'shared_accounts'   => (new SocialPostsModel())->account_ids_for_post((int) $post['id']),
        );
    }

    /** Why a post can't publish yet (plain words), or ok. */
    private function post_validation(array $post){
        $model  = new PostsModel();
        $assets = $model->get_assets((int) $post['id']);
        $has_caption = trim((string) $post['caption']) !== '';
        if (empty($assets) && !$has_caption) {
            return array('ok' => false, 'reason' => 'Add a photo, video, or caption before publishing.');
        }
        foreach ($assets as $a) {
            if (empty($a['deleted_at']) && $a['status'] !== 'ready') {
                return array('ok' => false, 'reason' => "Some media is still processing. It'll be ready in a moment.");
            }
        }
        if ($model->count_missing_assets((int) $post['id']) > 0) {
            return array('ok' => false, 'reason' => 'Some media was removed from your library. Take it off the post to publish.');
        }
        if (($post['audience'] ?? '') === 'ppv') {
            $live = array_filter($assets, function ($a) { return empty($a['deleted_at']); });
            if (empty($live)) { return array('ok' => false, 'reason' => 'Pay-per-view posts need at least one photo or video to sell.'); }
            if ((int) ($post['ppv_price_credits'] ?? 0) <= 0) { return array('ok' => false, 'reason' => 'Set a price for this pay-per-view post.'); }
        }
        return array('ok' => true, 'reason' => '');
    }

    /** Create or update a draft (also powers autosave). */
    public function post_saveAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $caption    = html_entity_decode((string) ($this->post['caption'] ?? ''), ENT_QUOTES, 'UTF-8');
        $audience   = $this->post_audience((string) ($this->post['audience'] ?? 'free'));
        $tier_id    = ($audience === 'subscribers') ? (int) ($this->post['tier_id'] ?? 0) : 0;
        $ppv_credits = ($audience === 'ppv') ? $this->ppv_credits_from_dollars($this->post['ppv_price'] ?? 0) : 0;
        $comments   = (((string) ($this->post['comments_enabled'] ?? '1')) === '1') ? 1 : 0;
        $asset_ids  = $this->post['asset_ids'] ?? array();
        if (!is_array($asset_ids)) { $asset_ids = array(); }
        $asset_ids  = array_values(array_map('intval', $asset_ids));
        $cover_id   = (int) ($this->post['cover_id'] ?? 0);

        // Don't create an empty draft: a brand-new post with no caption and no media
        // isn't worth saving yet (avoids junk drafts from just toggling options).
        if ($id === 0 && trim($caption) === '' && empty($asset_ids)) {
            echo json_encode(array('success' => true, 'id' => 0, 'post' => array(
                'id' => 0, 'caption' => '', 'audience' => $audience, 'tier_id' => $tier_id ?: null, 'comments_enabled' => $comments, 'state' => 'draft',
                'scheduled_local' => '', 'timezone' => (string) ($user['content_timezone'] ?? 'UTC'),
                'assets' => array(), 'cover_display_url' => '', 'cover_blurred_url' => '',
                'validation' => array('ok' => false, 'reason' => 'Add a photo, video, or caption before publishing.'),
            )));
            exit;
        }

        $model  = new PostsModel();
        $fields = array('caption' => $caption, 'audience' => $audience, 'tier_id' => $tier_id, 'comments_enabled' => $comments, 'ppv_price_credits' => $ppv_credits);
        // If the post was removed elsewhere while the composer had it open, don't
        // hard-fail — fall back to creating a fresh draft so nothing is lost.
        if ($id > 0 && !$model->get_one($creator_id, $id)) { $id = 0; }
        if ($id > 0) {
            $model->update_fields($creator_id, $id, $fields);
        } else {
            $id = (int) $model->create_draft($creator_id, $caption, $audience);
            $model->update_fields($creator_id, $id, $fields);
        }
        // PPV integrity: once a post has buyers you may ADD media but not remove what
        // they paid for. (Adding is fine; removals are blocked.)
        if ($audience === 'ppv') {
            $sold = (new PpvUnlocksModel())->stats_for_posts(array($id));
            if (!empty($sold[$id]['unlocks'])) {
                $current = array_map(function ($a) { return (int) $a['asset_id']; }, $model->get_assets($id));
                if (array_diff($current, $asset_ids)) {
                    echo json_encode(array('success' => false, 'message' => 'This post has buyers — you can add media but not remove what they paid for.')); exit;
                }
            }
        }
        $model->set_assets($creator_id, $id, $asset_ids, $cover_id);
        $post = $model->get_one($creator_id, $id);
        $json = $this->studio_post_json($post, $user);
        $json['validation'] = $this->post_validation($post);
        echo json_encode(array('success' => true, 'id' => $id, 'post' => $json));
        exit;
    }

    /** Load a post (edit / reopen draft). */
    public function post_getAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($_GET['id'] ?? 0);
        $post       = (new PostsModel())->get_one($creator_id, $id);
        if (!$post) { echo json_encode(array('success' => false, 'message' => 'That post was not found.')); exit; }
        $json = $this->studio_post_json($post, $user);
        $json['validation'] = $this->post_validation($post);
        echo json_encode(array('success' => true, 'post' => $json));
        exit;
    }

    /** The creator's most recent open draft (for the "resume draft?" offer). */
    public function post_open_draftAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $draft      = (new PostsModel())->get_open_draft($creator_id);
        if (!$draft) { echo json_encode(array('success' => true, 'draft' => null)); exit; }
        // Only offer it if it actually has content worth resuming.
        $json = $this->studio_post_json($draft, $user);
        $has = trim((string) $draft['caption']) !== '' || !empty($json['assets']);
        echo json_encode(array('success' => true, 'draft' => $has ? $json : null));
        exit;
    }

    private function share_accounts_from_request(){
        $a = $this->post['share_accounts'] ?? array();
        if (!is_array($a)) { $a = array(); }
        return array_values(array_filter(array_map('strval', $a)));
    }

    /**
     * Cross-post the PROMOTIONAL version of a post to the selected connected
     * social accounts. Best-effort — never fails the publish/schedule if sharing
     * errors. Always sends the public caption + a SAFE preview image (the blurred
     * variant for subscriber posts) + a link back — never the subscriber media.
     */
    private function share_post_to_social(array $user, array $post, array $account_ids, $scheduled_iso = null){
        SocialShareService::share($user, $post, $account_ids, $scheduled_iso);
    }

    public function post_publishAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        $post       = $model->get_one($creator_id, $id);
        if (!$post) { echo json_encode(array('success' => false, 'message' => 'That post was not found.')); exit; }
        $v = $this->post_validation($post);
        if (!$v['ok']) { echo json_encode(array('success' => false, 'message' => $v['reason'])); exit; }
        $model->set_state($creator_id, $id, 'published');
        $this->share_post_to_social($user, $post, $this->share_accounts_from_request(), null);
        echo json_encode(array('success' => true, 'message' => 'Published', 'state' => 'published'));
        exit;
    }

    public function post_scheduleAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        $post       = $model->get_one($creator_id, $id);
        if (!$post) { echo json_encode(array('success' => false, 'message' => 'That post was not found.')); exit; }
        $v = $this->post_validation($post);
        if (!$v['ok']) { echo json_encode(array('success' => false, 'message' => $v['reason'])); exit; }
        $utc = $this->to_utc((string) ($this->post['scheduled_at'] ?? ''), (string) ($user['content_timezone'] ?? 'UTC'));
        if (!$utc || strtotime($utc) <= time()) {
            echo json_encode(array('success' => false, 'message' => 'Pick a date and time in the future.')); exit;
        }
        $model->set_state($creator_id, $id, 'scheduled', $utc);
        // $utc is already 'Y-m-d H:i:s' in UTC — build the ISO directly (strtotime would
        // misread it in the server's America/New_York default zone and send a wrong time).
        $this->share_post_to_social($user, $post, $this->share_accounts_from_request(), str_replace(' ', 'T', $utc) . 'Z');
        echo json_encode(array('success' => true, 'message' => 'Scheduled', 'state' => 'scheduled'));
        exit;
    }

    public function post_save_draftAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new PostsModel();
        if (!$model->get_one($creator_id, $id)) { echo json_encode(array('success' => false, 'message' => 'That post was not found.')); exit; }
        $model->set_state($creator_id, $id, 'draft');
        echo json_encode(array('success' => true, 'message' => 'Saved as draft', 'state' => 'draft'));
        exit;
    }

    /** Scheduled + published posts placed on their local date, plus queue health. */
    public function posts_calendarAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $tz         = (string) ($user['content_timezone'] ?? 'UTC');
        $model      = new PostsModel();
        $model->publish_due($creator_id);

        $items = array();
        $furthest = null; $scheduled_count = 0;
        foreach ($model->list_for_creator($creator_id, array()) as $p) {
            $when_utc = ($p['state'] === 'scheduled') ? ($p['scheduled_at'] ?? '') : (($p['state'] === 'published') ? ($p['published_at'] ?? '') : '');
            if ($when_utc === '' || $when_utc === null) { continue; }
            try {
                $d = new DateTime((string) $when_utc, new DateTimeZone('UTC'));
                $d->setTimezone(new DateTimeZone($tz ?: 'UTC'));
            } catch (\Throwable $e) { continue; }
            $cover = '';
            if (!empty($p['cover_thumb_key'])) {
                $cover = MediaService::signed_url(array('creator_id' => $creator_id, 'type' => $p['cover_type'], 'thumb_key' => $p['cover_thumb_key']), 'thumb', $creator_id);
            }
            $cap = trim((string) $p['caption']);
            $items[] = array(
                'id'         => (int) $p['id'],
                'state'      => $p['state'],
                'caption'    => ($cap === '') ? '' : mb_substr($cap, 0, 60),
                'cover_url'  => $cover,
                'cover_type' => (string) ($p['cover_type'] ?? ''),
                'date'       => $d->format('Y-m-d'),
                'time'       => $d->format('g:i A'),
                'iso'        => $d->format('Y-m-d\TH:i'),
                'media_missing' => (int) $p['media_missing'],
            );
            if ($p['state'] === 'scheduled') {
                $scheduled_count++;
                if ($furthest === null || $when_utc > $furthest) { $furthest = $when_utc; }
            }
        }

        // Upcoming automations — expand each active rule into its occurrences over the
        // next ~2 months so a daily rule shows on every day, weekly on each matching day.
        $rulesModel = new SchedulerRulesModel();
        $horizon    = (new DateTime('now', new DateTimeZone('UTC')))->modify('+62 days');
        foreach ($rulesModel->list_for_creator($creator_id) as $rule) {
            if ((int) $rule['active'] !== 1 || empty($rule['next_run_at'])) { continue; }
            try { $cursor = new DateTime((string) $rule['next_run_at'], new DateTimeZone('UTC')); }
            catch (\Throwable $e) { continue; }
            for ($guard = 0; $cursor <= $horizon && $guard < 90; $guard++) {
                $local = (clone $cursor)->setTimezone(new DateTimeZone($tz ?: 'UTC'));
                $items[] = array(
                    'id'            => 0,
                    'rule_id'       => (int) $rule['id'],
                    'state'         => 'automation',
                    'caption'       => (string) $rule['name'],
                    'cover_url'     => '',
                    'cover_type'    => '',
                    'date'          => $local->format('Y-m-d'),
                    'time'          => $local->format('g:i A'),
                    'iso'           => $local->format('Y-m-d\TH:i'),
                    'media_missing' => 0,
                );
                try { $cursor = new DateTime($rulesModel->compute_next_run($rule, $cursor), new DateTimeZone('UTC')); }
                catch (\Throwable $e) { break; }
            }
        }

        $queue = array('scheduled_count' => $scheduled_count, 'days_ahead' => 0, 'reaches' => '');
        if ($furthest !== null) {
            try {
                $f = new DateTime((string) $furthest, new DateTimeZone('UTC'));
                $f->setTimezone(new DateTimeZone($tz ?: 'UTC'));
                $today = new DateTime('now', new DateTimeZone($tz ?: 'UTC'));
                $queue['reaches']    = $f->format('M j, Y');
                $queue['days_ahead'] = (int) $today->diff($f)->days;
            } catch (\Throwable $e) {}
        }
        echo json_encode(array('success' => true, 'items' => $items, 'queue' => $queue, 'timezone' => $tz));
        exit;
    }

    /** Share an existing post to selected connected accounts (from the Posts list). */
    public function post_shareAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $post       = (new PostsModel())->get_one($creator_id, $id);
        if (!$post) { echo json_encode(array('success' => false, 'message' => 'That post was not found.')); exit; }
        if (!Plan::can_social_post($user)) { echo json_encode(array('success' => false, 'message' => 'Sharing to social is not part of your current plan.')); exit; }
        $accounts = $this->share_accounts_from_request();
        if (empty($accounts)) { echo json_encode(array('success' => false, 'message' => 'Pick at least one account.')); exit; }
        $req = array_map('strval', $accounts); $n = 0;
        foreach ((new SocialAccountsModel())->get_connected_for_user($creator_id) as $a) {
            if (in_array((string) $a['post_for_me_social_account_id'], $req, true)) { $n++; }
        }
        if ($n === 0) { echo json_encode(array('success' => false, 'message' => 'Those accounts are not connected.')); exit; }
        $this->share_post_to_social($user, $post, $accounts, null);
        echo json_encode(array('success' => true, 'message' => 'Shared to ' . $n . ' account' . ($n > 1 ? 's' : '') . '.'));
        exit;
    }

    /* ---------- Content Studio: posts list ---------- */

    /** Human display of a UTC datetime in the creator's timezone. */
    private function fmt_local($utc, $tz){
        if ((string) $utc === '' || $utc === null) { return ''; }
        try {
            $d = new DateTime((string) $utc, new DateTimeZone('UTC'));
            $d->setTimezone(new DateTimeZone($tz ?: 'UTC'));
            return $d->format('M j, Y · g:i A');
        } catch (\Throwable $e) { return ''; }
    }

    /** Shape a post row for the Posts list. */
    private function studio_post_row(array $p, array $user, array $ppv_stats = array(), array $mod_map = array()){
        $creator_id = (int) $user['user_id'];
        $tz         = (string) ($user['content_timezone'] ?? 'UTC');
        $cover = '';
        if (!empty($p['cover_thumb_key'])) {
            $cover = MediaService::signed_url(array(
                'creator_id' => $creator_id, 'type' => $p['cover_type'], 'thumb_key' => $p['cover_thumb_key'],
            ), 'thumb', $creator_id);
        }
        $cap = trim((string) $p['caption']);
        if ($p['state'] === 'published') { $when_label = 'Published'; $when = $this->fmt_local($p['published_at'] ?? '', $tz); }
        elseif ($p['state'] === 'scheduled') { $when_label = 'Publishes'; $when = $this->fmt_local($p['scheduled_at'] ?? '', $tz); }
        elseif ($p['state'] === 'archived') { $when_label = 'Archived'; $when = $this->fmt_local($p['updated_at'] ?? '', $tz); }
        else { $when_label = 'Edited'; $when = $this->fmt_local($p['updated_at'] ?? $p['created_at'], $tz); }

        return array(
            'id'             => (int) $p['id'],
            'state'          => $p['state'],
            'audience'       => $p['audience'],
            'caption'        => ($cap === '') ? '' : mb_substr($cap, 0, 140),
            'cover_url'      => $cover,
            'cover_type'     => (string) ($p['cover_type'] ?? ''),
            'asset_count'    => (int) $p['asset_count'],
            'media_missing'  => (int) $p['media_missing'],
            'when_label'     => $when_label,
            'when'           => $when,
            'views'          => (int) $p['views'],
            'likes'          => (int) $p['likes'],
            'comments'       => (int) $p['comments'],
            'earnings_cents' => (int) $p['earnings_cents'],
            'ppv_price_dollars' => ($p['audience'] === 'ppv' && ($p['ppv_price_credits'] ?? null) !== null) ? (int) round($p['ppv_price_credits'] / 10) : null,
            'ppv_unlocks'    => ($p['audience'] === 'ppv') ? (int) ($ppv_stats[(int) $p['id']]['unlocks'] ?? 0) : 0,
            'moderation'     => (string) ($mod_map[(int) $p['id']] ?? 'ok'),   // 'flagged'|'pending'|'ok'
            'shared_count'   => (new SocialPostsModel())->count_for_post((int) $p['id']),
        );
    }

    public function posts_listAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $model      = new PostsModel();
        $model->publish_due($creator_id);   // cron-less: flip any now-due scheduled posts
        $filters = array('state' => (string) ($_GET['state'] ?? ''), 'search' => (string) ($_GET['search'] ?? ''));
        $rows = $model->list_for_creator($creator_id, $filters);
        $posts = array();
        $ppv_ids = array();
        foreach ($rows as $p) { if ($p['audience'] === 'ppv') { $ppv_ids[] = (int) $p['id']; } }
        $ppv_stats = !empty($ppv_ids) ? (new PpvUnlocksModel())->stats_for_posts($ppv_ids) : array();
        $mod_map   = $model->moderation_map(array_map(function ($p) { return (int) $p['id']; }, $rows));
        foreach ($rows as $p) { $posts[] = $this->studio_post_row($p, $user, $ppv_stats, $mod_map); }
        echo json_encode(array('success' => true, 'posts' => $posts, 'counts' => $model->counts_by_state($creator_id)));
        exit;
    }

    /** Archive / unarchive a post. */
    public function post_archiveAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $unarchive  = ((string) ($this->post['unarchive'] ?? '0')) === '1';
        $model      = new PostsModel();
        if (!$model->get_one($creator_id, $id)) { echo json_encode(array('success' => false, 'message' => 'That post was not found.')); exit; }
        $model->set_state($creator_id, $id, $unarchive ? 'draft' : 'archived');
        echo json_encode(array('success' => true, 'message' => $unarchive ? 'Moved to drafts' : 'Archived'));
        exit;
    }

    public function post_duplicateAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $new_id     = (int) (new PostsModel())->duplicate($creator_id, $id);
        if ($new_id <= 0) { echo json_encode(array('success' => false, 'message' => 'That post was not found.')); exit; }
        echo json_encode(array('success' => true, 'id' => $new_id, 'message' => 'Duplicated to a new draft'));
        exit;
    }

    public function post_deleteAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        (new PostsModel())->delete_post($creator_id, $id);
        echo json_encode(array('success' => true, 'message' => 'Post removed'));
        exit;
    }

    public function posts_bulkAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $action     = (string) ($this->post['bulk_action'] ?? '');
        $ids        = $this->post['ids'] ?? array();
        if (!is_array($ids)) { $ids = array(); }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) { echo json_encode(array('success' => false, 'message' => 'No posts were selected.')); exit; }
        $model = new PostsModel();
        $done = 0;
        foreach ($ids as $id) {
            if (!$model->get_one($creator_id, $id)) { continue; }
            if ($action === 'archive') { $model->set_state($creator_id, $id, 'archived'); $done++; }
            elseif ($action === 'delete') { $model->delete_post($creator_id, $id); $done++; }
        }
        if ($done === 0) { echo json_encode(array('success' => false, 'message' => 'Nothing to update.')); exit; }
        echo json_encode(array('success' => true, 'message' => $done . ' post' . ($done > 1 ? 's' : '') . ($action === 'delete' ? ' removed' : ' archived')));
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
            $base . '/account/settings?section=wallet&tab=cashout&payout_refresh=1',
            $base . '/account/settings?section=wallet&tab=cashout&payout_return=1'
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

    /** Cash out the creator's earned credits: convert to $, deduct, and send via Stripe. */
    public function request_payoutAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $account_id = (string) ($user['stripe_connect_account_id'] ?? '');
        if ($account_id === '') {
            echo json_encode(array('success' => false, 'message' => 'Set up payouts first'));
            exit;
        }

        $credits = new CreditsModel();
        $balance = (int) $credits->get_balance($creator_id);      // credits
        $min     = 100;                                           // $10.00 minimum ($1 = 10 credits)
        if ($balance < $min) {
            echo json_encode(array('success' => false,
                'message' => 'You need at least ' . $min . ' credits ($' . number_format($min / 10, 2) . ') to cash out.'));
            exit;
        }
        $cents = $balance * 10;                                   // 1 credit = 10 cents

        // Deduct first (this also prevents a double-payout from a double-click: the second
        // request sees a zero balance). Roll the credits back if the Stripe transfer fails.
        if ($credits->apply_delta($creator_id, -$balance, 'payout', 'Cash out to bank') === false) {
            echo json_encode(array('success' => false, 'message' => 'Could not start the payout. Please try again.'));
            exit;
        }
        $idem = 'payout_' . $creator_id . '_' . $balance . '_' . substr(hash('sha256', uniqid('', true)), 0, 16);
        $res  = StripeService::create_transfer($account_id, $cents, 'usd', $idem);
        if (empty($res['ok'])) {
            $credits->apply_delta($creator_id, $balance, 'payout_refund', 'Payout failed — credits returned');
            echo json_encode(array('success' => false, 'message' => 'Could not send the payout. Make sure your bank account is connected.'));
            exit;
        }

        echo json_encode(array('success' => true,
            'message' => 'Payout of $' . number_format($cents / 100, 2) . ' is on its way to your bank.',
            'balance' => 0));
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

            // Buyer pays the package price PLUS a processing (merchant service) fee.
            $base_cents  = $package['dollars'] * 100;
            $fee_percent = Main::credit_fee_percent();
            $fee_cents   = (int) round($base_cents * $fee_percent / 100);
            $total_cents = $base_cents + $fee_cents;

            $intent = $stripe->paymentIntents->create(array(
                'amount'                    => $total_cents,
                'currency'                  => 'usd',
                'customer'                  => $customer_id,
                'automatic_payment_methods' => array('enabled' => true),
                'metadata'                  => array(
                    'user_id'    => (string) $user['user_id'],
                    'credits'    => (string) $package['credits'],
                    'type'       => 'credit_purchase',
                    'base_cents' => (string) $base_cents,
                    'fee_cents'  => (string) $fee_cents,
                ),
            ));

            $response['success']       = true;
            $response['client_secret'] = $intent->client_secret;
            $response['credits']       = $package['credits'];
            $response['base_cents']    = $base_cents;
            $response['fee_cents']     = $fee_cents;
            $response['total_cents']   = $total_cents;
            $response['fee_percent']   = $fee_percent;
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
