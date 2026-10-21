<?php
/**
 * checkout_attempts: one row per plan / add-on checkout a user opens and what happened to it
 * (opened -> card_entered -> succeeded | failed | abandoned). Written through CheckoutAttempts; read by the admin pages.
 */
class CheckoutAttemptsModel extends Model {

    const OPEN = array('opened', 'card_entered', 'failed');   // a checkout still in play: a failed card can be retried in the same checkout

    public function __construct(){
        parent::__construct();
    }

    public function create(array $f){
        $f['updated_at'] = gmdate('Y-m-d H:i:s');
        if (empty($f['opened_at'])) { $f['opened_at'] = $f['updated_at']; }
        return (int) parent::insert('checkout_attempts', $f);
    }

    public function get($id){
        $r = parent::select("SELECT * FROM checkout_attempts WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function set($id, array $f){
        $f['updated_at'] = gmdate('Y-m-d H:i:s');
        return parent::update('checkout_attempts', $f, 'id = :aid', array('aid' => (int) $id));
    }

    /**
     * The user's newest checkout opened in the last $secs seconds with one of $statuses (default: still in play);
     * $kind narrows it (plan, pack, slots).
     */
    public function current($user_id, $kind = '', $secs = 3600, array $statuses = self::OPEN){
        $p = array('u' => (int) $user_id, 'since' => gmdate('Y-m-d H:i:s', time() - (int) $secs));
        $k = '';
        if ((string) $kind !== '') { $k = ' AND kind = :k'; $p['k'] = (string) $kind; }
        $in = array();
        foreach (array_values($statuses) as $i => $st) { $in[] = ':s' . $i; $p['s' . $i] = (string) $st; }
        if (!$in) { return null; }
        $r = parent::select("SELECT * FROM checkout_attempts WHERE user_id = :u AND status IN (" . implode(', ', $in) . ") AND opened_at >= :since$k
                             ORDER BY id DESC LIMIT 1", $p);
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function by_charge($charge_id){
        $r = parent::select("SELECT * FROM checkout_attempts WHERE charge_id = :c ORDER BY id DESC LIMIT 1", array('c' => (int) $charge_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /**
     * Mark checkouts abandoned an hour after they opened with no card entered, or an hour after a card was
     * entered with no payment started. Returns the number of rows changed.
     */
    public function expire($secs = 3600){
        $cut = gmdate('Y-m-d H:i:s', time() - (int) $secs); $now = gmdate('Y-m-d H:i:s');
        // the founding trial asks for no card (no-card trial, 2026-10-20): closing it means the trial was never started, not a missing card
        $t = $this->db->prepare("UPDATE checkout_attempts SET status = 'abandoned', failure_reason = 'Closed checkout without starting the trial', finished_at = :now, updated_at = :now2
                                 WHERE status = 'opened' AND founding = 1 AND amount_cents = 0 AND opened_at < :cut");
        $t->execute(array('now' => $now, 'now2' => $now, 'cut' => $cut));
        $a = $this->db->prepare("UPDATE checkout_attempts SET status = 'abandoned', failure_reason = 'Closed checkout without entering a card', finished_at = :now, updated_at = :now2
                                 WHERE status = 'opened' AND opened_at < :cut");
        $a->execute(array('now' => $now, 'now2' => $now, 'cut' => $cut));
        $b = $this->db->prepare("UPDATE checkout_attempts SET status = 'abandoned', failure_reason = 'Entered a card but never paid', finished_at = :now, updated_at = :now2
                                 WHERE status = 'card_entered' AND charge_id IS NULL AND COALESCE(card_entered_at, opened_at) < :cut");
        $b->execute(array('now' => $now, 'now2' => $now, 'cut' => $cut));
        return $t->rowCount() + $a->rowCount() + $b->rowCount();
    }

    /**
     * Admin list, newest first, with the user. $f: status (failed|abandoned|succeeded), signup_day (Y-m-d: attempts by
     * people who signed up that UTC day, the Growth funnel's cohort), opened_day (Y-m-d), problems_today (failed or
     * abandoned, opened or ended today UTC), user_id.
     */
    public function admin_list(array $f = array(), $limit = 300){
        $limit = max(1, min(1000, (int) $limit));
        $w = array('1 = 1'); $p = array();
        if (in_array((string) ($f['status'] ?? ''), array('failed', 'abandoned', 'succeeded', 'opened', 'card_entered'), true)) { $w[] = 'a.status = :st'; $p['st'] = (string) $f['status']; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['signup_day'] ?? ''))) { $w[] = 'u.created_at >= :sd AND u.created_at < :sd2 + INTERVAL 1 DAY'; $p['sd'] = $p['sd2'] = (string) $f['signup_day']; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['opened_day'] ?? ''))) { $w[] = 'a.opened_at >= :od AND a.opened_at < :od2 + INTERVAL 1 DAY'; $p['od'] = $p['od2'] = (string) $f['opened_day']; }
        if (!empty($f['problems_today'])) { $w[] = "a.status IN ('failed', 'abandoned') AND (a.opened_at >= UTC_DATE() OR a.finished_at >= UTC_DATE())"; }
        if ((int) ($f['user_id'] ?? 0) > 0) { $w[] = 'a.user_id = :uid'; $p['uid'] = (int) $f['user_id']; }
        return (array) parent::select(
            "SELECT a.*, u.u_name, u.first_name, u.last_name, u.user_email, u.created_at AS signed_up_at, u.is_demo
             FROM checkout_attempts a JOIN user_accounts u ON u.user_id = a.user_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY a.opened_at DESC, a.id DESC LIMIT $limit", $p);
    }
}
