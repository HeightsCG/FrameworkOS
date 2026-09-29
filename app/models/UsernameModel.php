<?php
/**
 * Username change rules (PRD 6.4).
 *
 * - 30-day cooldown between changes (u_name_changed_at on user_accounts).
 * - A released handle is reserved for its owner for 90 days: nobody else can
 *   claim it in that window, but the original owner may reclaim it.
 * - Every released handle is archived in username_history so the public profile
 *   route can 301-redirect an old URL to the current handle once that route exists.
 */
class UsernameModel extends Model {

    const COOLDOWN_DAYS    = 30;
    const RESERVATION_DAYS = 90;
    const MIN_LEN          = 3;
    const MAX_LEN          = 30;

    // Handles that can never be taken (reserved routes / system words).
    public static $reserved_words = array(
        'admin', 'administrator', 'api', 'account', 'settings', 'login', 'logout',
        'signup', 'signin', 'register', 'password', 'root', 'support', 'help',
        'about', 'terms', 'privacy', 'billing', 'payout', 'payouts', 'wallet',
        'creator', 'creators', 'home', 'dashboard', 'explore', 'search',
        'notifications', 'messages', 'user', 'users', 'profile', 'profiles',
        'www', 'mail', 'static', 'assets', 'public', 'null', 'undefined',
    );

    /** Validate the format only. Returns '' when valid, else an error message. */
    public function validate_format($u_name){
        if ($u_name === '') {
            return 'A username is required';
        }
        if (strlen($u_name) < self::MIN_LEN) {
            return 'Username must be at least ' . self::MIN_LEN . ' characters';
        }
        if (strlen($u_name) > self::MAX_LEN) {
            return 'Username must be ' . self::MAX_LEN . ' characters or fewer';
        }
        if (!preg_match('/^[a-z0-9_]+$/', $u_name)) {
            return 'Use only lowercase letters, numbers, and underscores';
        }
        if (!preg_match('/[a-z]/', $u_name)) {
            return 'Username must include at least one letter';
        }
        if (in_array($u_name, self::$reserved_words, true)) {
            return 'That username is reserved';
        }
        return '';
    }

    /** Whole-day count until the user may change again (0 = allowed now). */
    public function cooldown_days_left($u_name_changed_at){
        if (empty($u_name_changed_at)) {
            return 0;
        }
        $next = strtotime((string) $u_name_changed_at) + self::COOLDOWN_DAYS * 86400;
        $left = (int) ceil(($next - time()) / 86400);
        return $left > 0 ? $left : 0;
    }

    /** Date the user may next change their handle, or '' when allowed now. */
    public function next_change_date($u_name_changed_at){
        if (empty($u_name_changed_at)) {
            return '';
        }
        $next = strtotime((string) $u_name_changed_at) + self::COOLDOWN_DAYS * 86400;
        return $next > time() ? date('M j, Y', $next) : '';
    }

    /**
     * Is $u_name unavailable to $user_id? Taken when another live account holds
     * it, or when it's another user's reserved (recently released) handle.
     * The requesting user reclaiming their own released handle is allowed.
     */
    public function is_taken($u_name, $user_id){
        $live = parent::select(
            "SELECT user_id FROM user_accounts WHERE u_name = :u AND deleted = 0",
            array('u' => $u_name)
        );
        if (is_array($live) && count($live) > 0 && (int) $live[0]['user_id'] !== (int) $user_id) {
            return true;
        }

        $reserved = parent::select(
            "SELECT id FROM username_history
             WHERE u_name = :u AND reserved_until > :now AND user_id <> :uid
             LIMIT 1",
            array('u' => $u_name, 'now' => date('Y-m-d H:i:s'), 'uid' => (int) $user_id)
        );
        return is_array($reserved) && count($reserved) > 0;
    }

    /**
     * Archive the old handle (reserved for RESERVATION_DAYS) and set the new one,
     * stamping the cooldown clock. Caller must have validated format, cooldown,
     * and availability first.
     */
    public function change($user_id, $old_uname, $new_uname){
        $now = date('Y-m-d H:i:s');

        parent::insert('username_history', array(
            'user_id'        => (int) $user_id,
            'u_name'         => $old_uname,
            'changed_at'     => $now,
            'reserved_until' => date('Y-m-d H:i:s', time() + self::RESERVATION_DAYS * 86400),
        ));

        return parent::update(
            'user_accounts',
            array(
                'u_name'            => $new_uname,
                'u_name_changed_at' => $now,
                'updated_at'        => $now,
                'updated_by'        => (int) $user_id,
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    /**
     * Map a previously-used handle to the account's CURRENT handle, for 301
     * redirects. Returns '' when the old handle isn't in the history. Wire this
     * into the public profile route when it's built.
     */
    public function resolve_current_username($old_uname){
        $rows = parent::select(
            "SELECT u.u_name
             FROM username_history h
             JOIN user_accounts u ON u.user_id = h.user_id AND u.deleted = 0
             WHERE h.u_name = :u
             ORDER BY h.changed_at DESC
             LIMIT 1",
            array('u' => $old_uname)
        );
        return (is_array($rows) && count($rows) > 0) ? (string) $rows[0]['u_name'] : '';
    }
}
