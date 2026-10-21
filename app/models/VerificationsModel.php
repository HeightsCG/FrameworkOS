<?php
/**
 * Creator verification (PRD §33), by ID check since 2026-10-21 (IdentityVerification): each row is one Didit session
 * (provider, provider_ref, session_url). Didit's decision sets the status (approved / rejected) and user_accounts.verified;
 * full_name is the name read from the ID. 'revoked' = staff removed the badge, 'replaced' = an old manual request
 * (a typed name, never checked) closed when the ID check came in.
 */
class VerificationsModel extends Model {

    /** A new ID-check session for a user. Returns the row id. */
    public function start_session($user_id, $provider, $ref, $url){
        return (int) parent::insert('verifications', array(
            'user_id' => (int) $user_id, 'status' => 'pending', 'provider' => (string) $provider, 'provider_ref' => (string) $ref,
            'session_url' => mb_substr((string) $url, 0, 255), 'created_at' => gmdate('Y-m-d H:i:s'),
        ));
    }

    public function by_ref($provider, $ref){
        $r = parent::select("SELECT * FROM verifications WHERE provider = :p AND provider_ref = :r LIMIT 1", array('p' => (string) $provider, 'r' => (string) $ref));
        return (is_array($r) && count($r)) ? $r[0] : null;
    }

    /** The user's latest row, or null. */
    public function latest_for($user_id){
        $r = parent::select("SELECT * FROM verifications WHERE user_id = :u ORDER BY id DESC LIMIT 1", array('u' => (int) $user_id));
        return (is_array($r) && count($r)) ? $r[0] : null;
    }

    /** Record Didit's decision on a row, and set the badge to match. */
    public function decide($id, $approved, $name, $reason){
        $v = $this->get($id);
        if (!$v) { return false; }
        parent::update('verifications', array('status' => $approved ? 'approved' : 'rejected', 'full_name' => $name !== '' ? mb_substr((string) $name, 0, 190) : $v['full_name'],
            'review_note' => mb_substr((string) $reason, 0, 255), 'decided_at' => gmdate('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $id));
        parent::update('user_accounts', array('verified' => $approved ? 1 : 0), 'user_id = :u', array('u' => (int) $v['user_id']));
        return true;
    }

    /** Staff removed the badge: the latest approved row is marked revoked, the account unverified. */
    public function revoke($user_id, $admin_id){
        parent::sql("UPDATE verifications SET status = 'revoked', reviewed_by = :a, reviewed_at = :t WHERE user_id = :u AND status = 'approved'",
            array(':a' => (int) $admin_id, ':t' => gmdate('Y-m-d H:i:s'), ':u' => (int) $user_id));
        parent::update('user_accounts', array('verified' => 0), 'user_id = :u', array('u' => (int) $user_id));
        return true;
    }

    /** The admin record: each creator's latest ID check, newest first. */
    public function recent_for_admin($limit = 50){
        $limit = max(1, min(200, (int) $limit));
        return (array) parent::select(
            "SELECT v.*, u.u_name, u.verified,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '')) AS name
               FROM verifications v
               JOIN (SELECT user_id, MAX(id) AS id FROM verifications WHERE provider IS NOT NULL GROUP BY user_id) last ON last.id = v.id
               JOIN user_accounts u ON u.user_id = v.user_id
               LEFT JOIN creator_profiles cp ON cp.user_id = v.user_id
              ORDER BY COALESCE(v.decided_at, v.created_at) DESC LIMIT $limit");
    }

    public function get($id){
        $r = parent::select("SELECT * FROM verifications WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r)) ? $r[0] : null;
    }
}
