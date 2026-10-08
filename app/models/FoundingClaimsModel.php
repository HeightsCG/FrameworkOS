<?php
/**
 * Founding creator spots (founding_claims, one row per account). A spot is 'claimed' at checkout, 'active' once the
 * Creator plan starts, 'lapsed' when that plan ends or changes, 'refused' when staff take it back. Spots in use =
 * claimed + active. Founding (libs/Classes/Founding.php) holds the rules; this model only reads and writes rows.
 */
class FoundingClaimsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** Spots in use: claimed (checkout running) or active. */
    public function taken(): int {
        $r = parent::select("SELECT COUNT(*) AS n FROM founding_claims WHERE status IN ('claimed', 'active')");
        return (int) ($r[0]['n'] ?? 0);
    }

    public function for_user($user_id){
        $r = parent::select("SELECT * FROM founding_claims WHERE user_id = :u", array('u' => (int) $user_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function get($id){
        $r = parent::select("SELECT * FROM founding_claims WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /**
     * Hold a spot for this account: 'ok' when it holds one (new, or its own claim from an earlier attempt), 'full', or 'busy' (lock timeout).
     * The count and the insert run under one named lock, so two checkouts at the last spot cannot both land.
     */
    public function claim($user_id, $spots): string {
        $got = parent::select("SELECT GET_LOCK('cls_founding_claim', 10) AS l");
        if (empty($got[0]['l'])) { return 'busy'; }
        try {
            $have = $this->for_user($user_id);
            if ($have) { return (string) $have['status'] === 'claimed' ? 'ok' : 'full'; }
            if ($this->taken() >= (int) $spots) { return 'full'; }
            $now = gmdate('Y-m-d H:i:s');
            parent::insert('founding_claims', array('user_id' => (int) $user_id, 'claimed_at' => $now, 'status' => 'claimed', 'created_at' => $now));
            return 'ok';
        } finally {
            parent::select("SELECT RELEASE_LOCK('cls_founding_claim') AS r");
        }
    }

    /** Give a held spot back (the checkout failed). */
    public function release($user_id){
        return parent::delete_all('founding_claims', "user_id = :u AND status = 'claimed'", array('u' => (int) $user_id));
    }

    /** Held spots older than $minutes that never activated (a checkout that died): released. Returns rows removed. */
    public function release_stale($minutes){
        return parent::delete_all('founding_claims', "status = 'claimed' AND claimed_at < UTC_TIMESTAMP() - INTERVAL " . max(1, (int) $minutes) . " MINUTE");
    }

    /** The plan started: the held spot becomes active. Returns rows changed. */
    public function activate($user_id){
        return parent::update('founding_claims', array('status' => 'active', 'activated_at' => gmdate('Y-m-d H:i:s')), "user_id = :u AND status = 'claimed'", array('u' => (int) $user_id));
    }

    /** The Creator plan ended or changed: an active claim lapses. Returns rows changed. */
    public function lapse($user_id){
        return parent::update('founding_claims', array('status' => 'lapsed'), "user_id = :u AND status = 'active'", array('u' => (int) $user_id));
    }

    public function set_status($id, $status){
        return parent::update('founding_claims', array('status' => (string) $status), 'id = :id', array('id' => (int) $id));
    }

    /** Stamp the testimonial request once; 0 when it was already sent. */
    public function mark_testimonial($id){
        return parent::update('founding_claims', array('testimonial_requested_at' => gmdate('Y-m-d H:i:s')), 'id = :id AND testimonial_requested_at IS NULL', array('id' => (int) $id));
    }

    /** Save the testimonial from /founding/testimonial (text up to 300 characters, consent to show name and handle). */
    public function save_testimonial($id, $text, $consent){
        return parent::update('founding_claims', array('testimonial_text' => (string) $text, 'testimonial_consent' => $consent ? 1 : 0, 'testimonial_submitted_at' => gmdate('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $id));
    }

    /** The testimonial email failed: allow it to be sent again. */
    public function clear_testimonial($id){
        return parent::update('founding_claims', array('testimonial_requested_at' => null), 'id = :id', array('id' => (int) $id));
    }

    /** Active claims activated at least $days ago whose testimonial request has not gone out. */
    public function testimonial_due($days, $limit = 50){
        $limit = max(1, (int) $limit);
        return (array) parent::select(
            "SELECT * FROM founding_claims WHERE status = 'active' AND testimonial_requested_at IS NULL
               AND activated_at IS NOT NULL AND activated_at <= UTC_TIMESTAMP() - INTERVAL " . max(0, (int) $days) . " DAY
             ORDER BY activated_at ASC LIMIT $limit");
    }

    /** Every claim for the admin Founding tab, newest first, with the account. */
    public function admin_list($limit = 300){
        $limit = max(1, (int) $limit);
        return (array) parent::select(
            "SELECT f.*, u.u_name, u.first_name, u.last_name, u.user_email
             FROM founding_claims f JOIN user_accounts u ON u.user_id = f.user_id
             ORDER BY f.claimed_at DESC, f.id DESC LIMIT $limit");
    }
}
