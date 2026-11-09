<?php
/**
 * Chargebacks (PRD §21) on platform plan charges (billing_charges). Logged from Stripe dispute webhooks; the
 * associated account is suspended pending review.
 */
class RefundsModel extends Model {

    /**
     * Record a Stripe dispute (chargeback) and suspend the associated account. Idempotent. Returns the suspended user id, or 0.
     */
    public function record_chargeback($dispute){
        $did = (string) ($dispute->id ?? '');
        if ($did === '') { return 0; }
        $ex = parent::select("SELECT id FROM chargebacks WHERE stripe_dispute_id = :d", array('d' => $did));
        if (is_array($ex) && count($ex)) { return 0; }

        $pi = (string) ($dispute->payment_intent ?? '');
        $user_id = null;
        if ($pi !== '') {
            $b = parent::select("SELECT user_id FROM billing_charges WHERE stripe_payment_intent_id = :pi LIMIT 1", array('pi' => $pi));   // a creator plan charge (app-managed billing)
            if (is_array($b) && count($b)) { $user_id = (int) $b[0]['user_id']; }
        }
        $suspended = 0;
        if ($user_id) {
            $suspended = parent::update('user_accounts', array('user_status' => 'Disabled'), 'user_id = :id AND is_admin = 0', array('id' => $user_id)) ? 1 : 0;
            if ($suspended) { AccountBilling::on_suspend($user_id); }   // no plan charges while suspended
        }
        parent::insert('chargebacks', array(
            'stripe_dispute_id' => $did,
            'payment_intent_id' => ($pi !== '' ? $pi : null),
            'user_id'           => $user_id,
            'amount_cents'      => (int) ($dispute->amount ?? 0),
            'currency'          => strtoupper(mb_substr((string) ($dispute->currency ?? ''), 0, 8)),
            'reason'            => mb_substr((string) ($dispute->reason ?? ''), 0, 64),
            'status'            => mb_substr((string) ($dispute->status ?? ''), 0, 32),
            'account_suspended' => $suspended,
            'created_at'        => date('Y-m-d H:i:s'),
        ));
        return $suspended ? (int) $user_id : 0;
    }

    /** Summary for the admin overview. */
    public function totals(){
        $c = parent::select("SELECT COUNT(*) AS c, COALESCE(SUM(amount_cents),0) AS cents FROM chargebacks");
        return array(
            'chargeback_count' => (int) ($c[0]['c'] ?? 0),
            'chargeback_cents' => (int) ($c[0]['cents'] ?? 0),
        );
    }

    /** Recent chargebacks for the admin view. */
    public function recent_chargebacks($limit = 10){
        $limit = max(1, min(50, (int) $limit));
        return (array) parent::select(
            "SELECT c.*, u.u_name AS handle FROM chargebacks c
             LEFT JOIN user_accounts u ON u.user_id = c.user_id
             ORDER BY c.id DESC LIMIT $limit");
    }
}
