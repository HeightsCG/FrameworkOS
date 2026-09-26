<?php
/**
 * What happens after someone has proved who they are, whichever way they did it (password or Google):
 * account checks, the second factor, then the session. Password login (ApiAuthController::loginAction)
 * and Google login (AccountController::google_callbackAction) both go through here, so the checks
 * can't drift apart.
 */
class LoginGate {

    const SUSPENDED_MESSAGE = 'This account has been suspended. Contact support if you believe this is a mistake.';

    /** 'suspended' | 'seat' | '' — reasons this account may not sign in at all. */
    public static function blocked(array $user): string {
        if (($user['user_status'] ?? 'Active') === 'Disabled' || (int) ($user['deleted'] ?? 0) === 1) { return 'suspended'; }
        if (Plan::team_member_locked($user)) { return 'seat'; }   // a collaborator over the owner's seat limit
        return '';
    }

    /**
     * Start the sign-in: a second factor when one is on (the session only remembers who is pending),
     * otherwise the full session. Returns ['mfa' => ['totp' => bool, 'email' => bool]] or ['ok' => true, 'reset_pw' => int].
     */
    public static function finish(array $user): array {
        session_regenerate_id(true);
        if (!empty($user['mfa_totp_enabled']) || !empty($user['mfa_email_enabled'])) {
            Session::set('mfa_pending_user_id', (int) $user['user_id']);
            $has_totp = !empty($user['mfa_totp_enabled']);
            if (!$has_totp && !empty($user['mfa_email_enabled'])) { self::send_login_code($user); }   // no authenticator app: email a code now
            return array('mfa' => array('totp' => $has_totp, 'email' => !empty($user['mfa_email_enabled'])));
        }
        UserSession::start($user);
        return array('ok' => true, 'reset_pw' => (int) ($user['reset_pw'] ?? 0));
    }

    /** Issue and email a login verification code. */
    public static function send_login_code(array $user): void {
        $code = (new MfaModel())->issue_email_code((int) $user['user_id'], 'login');
        if (!empty($user['user_email'])) {
            (new NotificationsModel())->send_mfa_code_email($user['user_email'], trim($user['first_name'] . ' ' . $user['last_name']), $code);
        }
    }
}
