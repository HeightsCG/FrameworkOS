<?php
/**
 * Refunds (PRD §20) and chargebacks (PRD §21). A refund reverses a credit-based
 * purchase end-to-end: credit the buyer, claw the creator's net earning back (best
 * effort — capped at their balance), reverse the post's recorded earnings, and revoke
 * the unlock so access is removed. Chargebacks are logged from Stripe dispute webhooks
 * and suspend the associated account pending review.
 */
class RefundsModel extends Model {

    private function label($kind){ return $kind === 'bundle' ? 'content bundle' : 'pay-per-view post'; }

    /**
     * Refund a PPV or bundle purchase. Returns ['ok'=>bool, 'message'=>?, 'amount'=>credits, 'clawback_ok'=>bool].
     * Idempotent via the unlock row: once revoked, a second refund finds nothing.
     */
    public function refund($kind, $ref_id, $fan_id, $admin_id, $reason = ''){
        $ref_id = (int) $ref_id; $fan_id = (int) $fan_id;
        if ($kind === 'ppv') {
            $rows = parent::select("SELECT price_credits, creator_id FROM ppv_unlocks WHERE post_id = :p AND fan_id = :f",
                array('p' => $ref_id, 'f' => $fan_id));
        } elseif ($kind === 'bundle') {
            $rows = parent::select("SELECT price_credits, creator_id FROM bundle_unlocks WHERE bundle_id = :b AND fan_id = :f",
                array('b' => $ref_id, 'f' => $fan_id));
        } else {
            return array('ok' => false, 'message' => 'Invalid purchase type');
        }
        if (!is_array($rows) || count($rows) !== 1) {
            return array('ok' => false, 'message' => 'Purchase not found (it may already be refunded)');
        }
        $charge     = (int) $rows[0]['price_credits'];
        $creator_id = (int) $rows[0]['creator_id'];

        $credits = new CreditsModel();
        // 1) Credit the buyer back.
        if ($credits->apply_delta($fan_id, $charge, 'refund', 'Refund: ' . $this->label($kind)) === false) {
            return array('ok' => false, 'message' => 'Could not credit the buyer');
        }
        // 2) Claw the creator's net earning back (best effort — apply_delta won't go negative).
        $creator_row = (new UsersModel())->get_user_by_id($creator_id);
        $creator_row = (is_array($creator_row) && count($creator_row) === 1) ? $creator_row[0] : null;
        $net = (int) round($charge * (100 - Plan::fee_percent($creator_row)) / 100);
        $clawback_ok = 1; $clawback = 0;
        if ($net > 0) {
            if ($credits->apply_delta($creator_id, -$net, 'refund_reversal', 'Refund reversal: ' . $this->label($kind)) !== false) {
                $clawback = $net;
            } else {
                $clawback_ok = 0;   // creator already spent it; platform absorbs the shortfall
            }
        }
        // 3) Reverse the post's recorded earnings so revenue reporting stays correct.
        if ($kind === 'ppv' && $net > 0) { (new PostsModel())->add_earnings($ref_id, -$net * 10); }
        // 4) Revoke access.
        if ($kind === 'ppv') { (new PpvUnlocksModel())->remove($ref_id, $fan_id); }
        else                 { (new ContentBundlesModel())->remove_unlock($ref_id, $fan_id); }
        // 5) Audit log.
        parent::insert('refunds', array(
            'kind' => $kind, 'ref_id' => $ref_id, 'creator_id' => $creator_id, 'fan_id' => $fan_id,
            'amount_credits' => $charge, 'clawback_credits' => $clawback, 'clawback_ok' => $clawback_ok,
            'admin_id' => (int) $admin_id, 'reason' => mb_substr((string) $reason, 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ));
        return array('ok' => true, 'amount' => $charge, 'clawback_ok' => (bool) $clawback_ok);
    }

    /** Record a Stripe dispute (chargeback) and suspend the associated account. Idempotent. */
    public function record_chargeback($dispute){
        $did = (string) ($dispute->id ?? '');
        if ($did === '') { return; }
        $ex = parent::select("SELECT id FROM chargebacks WHERE stripe_dispute_id = :d", array('d' => $did));
        if (is_array($ex) && count($ex)) { return; }

        $pi = (string) ($dispute->payment_intent ?? '');
        $user_id = null;
        if ($pi !== '') {
            $u = parent::select("SELECT user_id FROM credit_transactions WHERE stripe_payment_intent_id = :pi LIMIT 1", array('pi' => $pi));
            if (is_array($u) && count($u)) { $user_id = (int) $u[0]['user_id']; }
        }
        $suspended = 0;
        if ($user_id) {
            parent::update('user_accounts', array('user_status' => 'Disabled'), 'user_id = :id AND is_admin = 0', array('id' => $user_id));
            $suspended = 1;
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
    }

    /** Summary for the admin overview. */
    public function totals(){
        $r = parent::select("SELECT COUNT(*) AS c, COALESCE(SUM(amount_credits),0) AS cr FROM refunds");
        $c = parent::select("SELECT COUNT(*) AS c, COALESCE(SUM(amount_cents),0) AS cents FROM chargebacks");
        return array(
            'refund_count'     => (int) ($r[0]['c'] ?? 0),
            'refund_credits'   => (int) ($r[0]['cr'] ?? 0),
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
