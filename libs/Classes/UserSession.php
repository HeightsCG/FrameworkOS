<?php
/**
 * Signing a person into the session, and admin "Sign in as" (impersonation).
 *
 * start(): copy the account into the session minus its secrets (TOTP secret, reset / verify tokens); the password hash
 * is kept only as a fingerprint (enough to end other sessions when the password changes); fresh CSRF token.
 *
 * Impersonation: the admin's own session is saved server-side inside the new session ('impersonator') and restored
 * exactly on the way back. While it's active, Controller skips presence (the user never looks online because of the
 * admin), skips the forced-reset redirect, and blocks credential / money / identity actions (IMPERSONATION_BLOCKED).
 */
class UserSession {

    const SECRET_KEYS = array('p_word', 'mfa_totp_secret', 'mfa_totp_last_step', 'reset_token', 'reset_token_expires', 'email_verify_token');

    /** API actions an admin can't take while signed in as someone else: their credentials, money and identity. */
    const IMPERSONATION_BLOCKED = array(
        'change_password', 'change_username', 'delete_my_account',
        'mfa_totp_begin', 'mfa_totp_confirm', 'mfa_totp_disable', 'mfa_email_send_enroll', 'mfa_email_confirm', 'mfa_email_disable', 'mfa_regenerate_backup_codes',
        'mcp_token_generate', 'mcp_token_revoke',
        'buy_credits', 'confirm_credit_purchase', 'confirm_ai_credit_purchase', 'save_autoreplenishment',
        'billing_card_setup', 'billing_card_save', 'billing_change_plan', 'billing_confirm', 'billing_cancel', 'billing_resume', 'billing_set_pack', 'billing_set_slots',
        'subscribe_plan', 'cancel_creator_subscription', 'ppv_unlock', 'bundle_unlock', 'message_unlock', 'event_register', 'service_purchase',
        'request_payout', 'start_payout_onboarding', 'payout_login_link', 'disconnect_payout_account',
        'team_invite', 'team_remove', 'team_set_role', 'team_set_status', 'data_export_request', 'data_export_download',
        'buy_ai_credits', 'leave_creator', 'reactivate_creator_subscription', 'event_cancel', 'event_refund_attendee', 'event_cancel_all',
        'service_refund_buyer', 'block_user', 'live_join', 'live_remove', 'live_mute_all',
        'event_replay_buy', 'live_tip', 'join_free_plan', 'unblock_user',
        'message_send', 'broadcast_send', 'event_message_send', 'inbox_reply_send',
    );

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
