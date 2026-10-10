<?php
/**
 * Persistence for `payouts`: a creator's bank payouts from their connected account,
 * written by the Connect webhook (payout.* events) and when the app creates a payout itself.
 * Times are stored UTC.
 */
class PayoutsModel extends Model {

    const FINAL = array('paid', 'failed', 'canceled');

    public function __construct(){ parent::__construct(); }

    /** The creator who owns a connected account id, 0 if none. */
    public function creator_for_account(string $account_id): int {
        if ($account_id === '') { return 0; }
        $r = parent::select("SELECT user_id FROM user_accounts WHERE stripe_connect_account_id = :a LIMIT 1", array('a' => $account_id));
        return (is_array($r) && count($r) === 1) ? (int) $r[0]['user_id'] : 0;
    }

    public function get_by_payout_id(string $payout_id): ?array {
        $r = parent::select("SELECT * FROM payouts WHERE stripe_payout_id = :p", array('p' => $payout_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /**
     * Insert or update a row from a Stripe payout object. Events can arrive out of order,
     * so a final status is never moved back to pending or in_transit, and failed/canceled
     * also win over paid (a returned payout). Returns true exactly once per payout: the call
     * that first records it as failed claims failure_notified_at, so the caller sends one notice.
     */
    public function upsert_from_event(int $creator_id, $payout): bool {
        $now     = gmdate('Y-m-d H:i:s');
        $created = !empty($payout->created) ? gmdate('Y-m-d H:i:s', (int) $payout->created) : $now;
        $arrival = !empty($payout->arrival_date) ? gmdate('Y-m-d H:i:s', (int) $payout->arrival_date) : null;
        $code    = (string) ($payout->failure_code ?? '');
        $msg     = (string) ($payout->failure_message ?? '');
        parent::sql(
            "INSERT INTO payouts (creator_id, stripe_payout_id, amount_cents, currency, status, arrival_date, failure_code, failure_message, created_at, updated_at)
             VALUES (:c, :p, :amt, :cur, :st, :arr, :fc, :fm, :ca, :ua)
             ON DUPLICATE KEY UPDATE
                status = CASE WHEN status IN ('failed','canceled') THEN status
                              WHEN status = 'paid' AND VALUES(status) NOT IN ('failed','canceled') THEN status
                              ELSE VALUES(status) END,
                amount_cents = VALUES(amount_cents), currency = VALUES(currency),
                arrival_date = COALESCE(VALUES(arrival_date), arrival_date),
                failure_code = COALESCE(VALUES(failure_code), failure_code),
                failure_message = COALESCE(VALUES(failure_message), failure_message),
                updated_at = VALUES(updated_at)",
            array(
                'c'   => $creator_id,
                'p'   => (string) $payout->id,
                'amt' => (int) ($payout->amount ?? 0),
                'cur' => strtolower((string) ($payout->currency ?? 'usd')),
                'st'  => mb_substr((string) ($payout->status ?? 'pending'), 0, 20),
                'arr' => $arrival,
                'fc'  => $code !== '' ? mb_substr($code, 0, 64) : null,
                'fm'  => $msg !== '' ? mb_substr($msg, 0, 255) : null,
                'ca'  => $created,
                'ua'  => $now,
            )
        );
        // claim the failure notice; the conditional update lets only one delivery win.
        $sth = $this->db->prepare("UPDATE payouts SET failure_notified_at = :t
                                   WHERE stripe_payout_id = :p AND status = 'failed' AND failure_notified_at IS NULL");
        $sth->execute(array(':t' => $now, ':p' => (string) $payout->id));
        return $sth->rowCount() > 0;
    }

    /** Newest payouts across all creators, with the creator's handle, for the admin Activity table. */
    public function recent_for_admin(int $limit = 30): array {
        $limit = max(1, (int) $limit);
        return (array) parent::select(
            "SELECT p.id, p.creator_id, p.amount_cents, p.status, p.failure_message, p.created_at, u.u_name
             FROM payouts p JOIN user_accounts u ON u.user_id = p.creator_id
             ORDER BY p.created_at DESC, p.id DESC LIMIT $limit");
    }

    /** Newest payouts for a creator, shaped like StripeService::connect_payouts() plus the failure reason. */
    public function list_for_creator(int $creator_id, int $limit = 10): array {
        $limit = max(1, (int) $limit);
        $rows  = parent::select(
            "SELECT amount_cents, currency, status, arrival_date, failure_message, created_at
             FROM payouts WHERE creator_id = :c ORDER BY created_at DESC, id DESC LIMIT $limit",
            array('c' => $creator_id)
        );
        $out = array();
        foreach ((array) $rows as $r) {
            $out[] = array(
                'amount'          => (int) $r['amount_cents'],
                'currency'        => strtoupper((string) $r['currency']),
                'status'          => (string) $r['status'],
                'arrival'         => $r['arrival_date'] !== null ? (int) strtotime((string) $r['arrival_date'] . ' UTC') : 0,
                'created'         => (int) strtotime((string) $r['created_at'] . ' UTC'),
                'failure_message' => (string) ($r['failure_message'] ?? ''),
            );
        }
        return $out;
    }
}
