<?php
/**
 * Signing a person into the session, and admin "Sign in as" (impersonation).
 *
 * start(): copy the account into the session minus its secrets (TOTP secret, reset / verify tokens); the password hash
 * is kept only as a fingerprint (enough to end other sessions when the password changes); fresh CSRF token.
 *
 * Impersonation: the admin's own session is saved server-side inside the new session ('impersonator') and restored
 * exactly on the way back. While it's active, Controller skips presence (the user never looks online because of the
 * admin) and skips the forced-reset redirect. Nothing else is blocked: the admin can do everything the user can.
 */
class UserSession {

    const SECRET_KEYS = array('p_word', 'mfa_totp_secret', 'mfa_totp_last_step', 'reset_token', 'reset_token_expires', 'email_verify_token');

    /** API actions an admin can't take while signed in as someone else. Empty by Daniel's rule (2026-10-18): signed in as a user, an admin can do everything that user can. */
    const IMPERSONATION_BLOCKED = array();

    public static function start(array $user): void {
        foreach ($user as $key => $value) {
            if (in_array($key, self::SECRET_KEYS, true)) { continue; }
            Session::set($key, $value);
        }
        Session::set('p_word', null);
        Session::set('pw_fp', hash('sha256', (string) ($user['p_word'] ?? '')));
        CSRF::rotate();
    }

    public static function impersonating(): bool {
        return is_array(Session::get('impersonator'));
    }

    /** The admin becomes $target. $return: where "Return to Admin" lands. */
    public static function begin_impersonation(array $target, array $admin, string $return): void {
        $saved = $_SESSION;
        session_unset();
        session_regenerate_id(true);
        self::start($target);
        Session::set('impersonator', array(
            'id' => (int) $admin['user_id'], 'handle' => (string) $admin['u_name'], 'return' => $return, 'since' => time(), 'session' => $saved,
        ));
    }

    /** Back to the admin's own session, exactly as it was. Returns the impersonator record, or null if none. */
    public static function end_impersonation(): ?array {
        $imp = Session::get('impersonator');
        if (!is_array($imp)) { return null; }
        $target_id = (int) Session::get('user_id');
        session_unset();
        session_regenerate_id(true);
        foreach ((array) ($imp['session'] ?? array()) as $k => $v) { Session::set($k, $v); }
        CSRF::rotate();
        $imp['target_id'] = $target_id;
        unset($imp['session']);
        return $imp;
    }
}
