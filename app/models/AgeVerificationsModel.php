<?php
/**
 * One age verification per account (age_verifications), reused by both triggers (the fan's adult toggle and a creator
 * publishing adult media). Stores the status and the provider's session reference only: never images, documents or
 * birth dates. A verified row is never downgraded; a failed row is replaced by the next attempt (retry).
 */
class AgeVerificationsModel extends Model {

    public function get($user_id){
        $r = parent::select("SELECT * FROM age_verifications WHERE user_id = :u", array('u' => (int) $user_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function is_verified($user_id): bool {
        $r = $this->get($user_id);
        return $r !== null && (string) $r['status'] === 'verified';
    }

    /** The row a provider reference belongs to (webhooks carry the reference, not our user id). */
    public function by_ref($provider, $ref){
        $r = parent::select("SELECT * FROM age_verifications WHERE provider = :p AND provider_ref = :r", array('p' => (string) $provider, 'r' => (string) $ref));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** A new or retried session: the row becomes pending with the new reference. False when the account is already verified. */
    public function start_pending($user_id, $provider, $ref): bool {
        $now = gmdate('Y-m-d H:i:s');
        $cur = $this->get($user_id);
        if ($cur && (string) $cur['status'] === 'verified') { return false; }
        if ($cur) {
            parent::update('age_verifications', array('status' => 'pending', 'provider' => (string) $provider, 'provider_ref' => (string) $ref, 'verified_at' => null, 'updated_at' => $now), 'user_id = :u', array('u' => (int) $user_id));
            return true;
        }
        parent::insert('age_verifications', array('user_id' => (int) $user_id, 'status' => 'pending', 'provider' => (string) $provider, 'provider_ref' => (string) $ref, 'verified_at' => null, 'created_at' => $now, 'updated_at' => $now));
        return $this->get($user_id) !== null;
    }

    /** Record the provider's result: 'verified' (with the time) or 'failed'. */
    public function mark($user_id, $status, $verified_at): bool {
        if (!in_array((string) $status, array('pending', 'verified', 'failed'), true)) { return false; }
        parent::update('age_verifications', array('status' => (string) $status, 'verified_at' => $verified_at, 'updated_at' => gmdate('Y-m-d H:i:s')), 'user_id = :u', array('u' => (int) $user_id));
        $r = $this->get($user_id);
        return $r !== null && (string) $r['status'] === (string) $status;
    }

    public function remove($user_id): bool {
        parent::delete('age_verifications', 'user_id = :u', 1, array('u' => (int) $user_id));
        return $this->get($user_id) === null;
    }

    /** Newest activity first, with the account's handle and name, for /admin/verification (Age checks). */
    public function recent($limit = 100){
        $limit = max(1, min(500, (int) $limit));
        return (array) parent::select(
            "SELECT a.*, u.u_name, u.user_email, u.adult_content_enabled, u.role_id, r.role_name,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS name
             FROM age_verifications a
             JOIN user_accounts u ON u.user_id = a.user_id
             LEFT JOIN user_roles r ON r.id = u.role_id
             ORDER BY (a.status = 'pending') DESC, a.updated_at DESC
             LIMIT $limit");
    }

    /** How many accounts sit in each state: ['pending' => n, 'verified' => n, 'failed' => n]. */
    public function counts(): array {
        $out = array('pending' => 0, 'verified' => 0, 'failed' => 0);
        foreach ((array) parent::select("SELECT status, COUNT(*) AS n FROM age_verifications GROUP BY status") as $r) {
            if (isset($out[$r['status']])) { $out[$r['status']] = (int) $r['n']; }
        }
        return $out;
    }
}
