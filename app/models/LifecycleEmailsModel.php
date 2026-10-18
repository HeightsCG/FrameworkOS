<?php
/**
 * Lifecycle email bookkeeping (LifecycleEmails): one lifecycle_emails row per email claimed, unique on
 * user + kind + ref, so an email is claimed exactly once however many senders race. Also the candidate
 * queries for the trial-ending reminder and the abandoned-checkout follow-up.
 */
class LifecycleEmailsModel extends Model {

    /** Claim (user, kind, ref). Returns the new row id, or 0 when it was already claimed. */
    public function claim($user_id, $kind, $ref = '', $status = 'sent'): int {
        try {
            return (int) parent::insert('lifecycle_emails', array('user_id' => (int) $user_id, 'kind' => (string) $kind, 'ref' => (string) $ref,
                'status' => (string) $status, 'created_at' => gmdate('Y-m-d H:i:s')));
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { return 0; }   // duplicate: someone else has it
            throw $e;
        }
    }

    /** Give a claim back (the send failed), so the next run tries again. */
    public function release($id): void {
        parent::delete('lifecycle_emails', 'id = :id', 1, array('id' => (int) $id));
    }

    public function set_status($id, $status): void {
        parent::update('lifecycle_emails', array('status' => (string) $status), 'id = :id', array('id' => (int) $id));
    }

    /** The latest claim of this kind for the account (row or null). */
    public function last($user_id, $kind) {
        $r = parent::select("SELECT * FROM lifecycle_emails WHERE user_id = :u AND kind = :k ORDER BY created_at DESC, id DESC LIMIT 1",
            array('u' => (int) $user_id, 'k' => (string) $kind));
        return $r ? $r[0] : null;
    }

    public function for_user($user_id): array {
        return (array) parent::select("SELECT * FROM lifecycle_emails WHERE user_id = :u ORDER BY id", array('u' => (int) $user_id));
    }

    /**
     * Founding trials whose first charge is due within $hours: active claim, active plan not set to cancel, no renewal
     * paid since the trial started, and no trial-ending email claimed for that claim yet.
     */
    public function trial_ending_candidates($hours = 72, $limit = 200): array {
        return (array) parent::select(
            "SELECT fc.id AS claim_id, fc.user_id, fc.activated_at, ba.next_charge_at, ba.plan_key
               FROM founding_claims fc
               JOIN billing_accounts ba ON ba.user_id = fc.user_id
               JOIN user_accounts u ON u.user_id = fc.user_id
              WHERE fc.status = 'active' AND u.deleted = 0
                AND ba.status = 'active' AND ba.cancel_at_period_end = 0 AND ba.plan_key <> 'free'
                AND ba.next_charge_at > UTC_TIMESTAMP()
                AND ba.next_charge_at <= UTC_TIMESTAMP() + INTERVAL " . (int) $hours . " HOUR
                AND NOT EXISTS (SELECT 1 FROM billing_charges bc WHERE bc.user_id = fc.user_id AND bc.kind = 'renewal' AND bc.applied = 1
                                 AND bc.created_at >= fc.activated_at)
                AND NOT EXISTS (SELECT 1 FROM lifecycle_emails le WHERE le.user_id = fc.user_id AND le.kind = 'trial_ending'
                                 AND le.ref = CONCAT('claim:', fc.id))
              ORDER BY ba.next_charge_at ASC
              LIMIT " . max(1, (int) $limit));
    }

    /** Plan quotes asked for (Analytics 'checkout_started') in the last $days days: user_id => [last_ts, meta of the latest]. */
    public function checkout_starts($days = 7): array {
        $rows = (array) parent::select(
            "SELECT user_id, ts, meta FROM analytics_events
              WHERE kind = 'event' AND name = 'checkout_started' AND user_id > 0
                AND ts > UTC_TIMESTAMP() - INTERVAL " . (int) $days . " DAY
              ORDER BY ts ASC");
        $out = array();
        foreach ($rows as $r) { $out[(int) $r['user_id']] = array('last_ts' => (string) $r['ts'], 'meta' => (string) $r['meta']); }
        return $out;
    }

    /** Subscribe charges that failed or wait for authentication in the last $days days: user_id => [last_ts, plan]. */
    public function failed_subscribes($days = 7): array {
        $rows = (array) parent::select(
            "SELECT user_id, created_at, effects FROM billing_charges
              WHERE kind = 'subscribe' AND status IN ('failed', 'requires_action') AND applied = 0
                AND created_at > UTC_TIMESTAMP() - INTERVAL " . (int) $days . " DAY
              ORDER BY created_at ASC");
        $out = array();
        foreach ($rows as $r) {
            $fx = (array) json_decode((string) $r['effects'], true);
            $out[(int) $r['user_id']] = array('last_ts' => (string) $r['created_at'], 'plan' => (string) ($fx['plan'] ?? ''));
        }
        return $out;
    }

    /** A plan subscribe charge that went through at or after $since. */
    public function subscribed_since($user_id, $since): bool {
        $r = parent::select("SELECT 1 FROM billing_charges WHERE user_id = :u AND kind IN ('subscribe', 'upgrade') AND applied = 1 AND created_at >= :s LIMIT 1",
            array('u' => (int) $user_id, 's' => (string) $since));
        return !empty($r);
    }
}
