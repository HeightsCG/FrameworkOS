<?php
/**
 * Refunds (PRD §20) and chargebacks (PRD §21). A refund reverses a credit-based
 * purchase end-to-end: credit the buyer, claw the creator's net earning back in full
 * (their balance can go below zero; that debt comes out of future earnings), reverse the post's recorded earnings, and revoke
 * the unlock so access is removed. Chargebacks are logged from Stripe dispute webhooks
 * and suspend the associated account pending review.
 */
class RefundsModel extends Model {

    private function label($kind){
        if ($kind === 'bundle')  { return 'content bundle'; }
        if ($kind === 'message') { return 'message unlock'; }
        return 'pay-per-view post';
    }

    /**
     * Refund a PPV, bundle or DM-unlock purchase. Returns ['ok'=>bool, 'message'=>?, 'amount'=>credits, 'clawback_ok'=>bool].
     * Idempotent via the unlock row: once revoked, a second refund finds nothing.
     */
    public function refund($kind, $ref_id, $fan_id, $admin_id, $reason = ''){
        $ref_id = (int) $ref_id; $fan_id = (int) $fan_id;
        if ($kind === 'ppv') {
            $rows = parent::select("SELECT price_credits, net_credits, creator_id FROM ppv_unlocks WHERE post_id = :p AND fan_id = :f",
                array('p' => $ref_id, 'f' => $fan_id));
        } elseif ($kind === 'bundle') {
            $rows = parent::select("SELECT price_credits, net_credits, creator_id FROM bundle_unlocks WHERE bundle_id = :b AND fan_id = :f",
                array('b' => $ref_id, 'f' => $fan_id));
        } elseif ($kind === 'message') {
            $rows = parent::select("SELECT price_credits, net_credits, creator_id FROM message_unlocks WHERE message_id = :m AND fan_id = :f",
                array('m' => $ref_id, 'f' => $fan_id));
        } else {
            return array('ok' => false, 'message' => 'Invalid purchase type');
        }
        if (!is_array($rows) || count($rows) !== 1) {
            return array('ok' => false, 'message' => 'Purchase not found (it may already be refunded)');
        }
        $charge     = (int) $rows[0]['price_credits'];
        $creator_id = (int) $rows[0]['creator_id'];

        // 1) Revoke access FIRST and only continue if this request deleted the unlock row:
        //    that row is the mutex, so two concurrent refunds can't both pay the buyer.
        if ($kind === 'ppv')          { $claimed = (new PpvUnlocksModel())->remove($ref_id, $fan_id); }
        elseif ($kind === 'message')  { $claimed = (new MessageUnlocksModel())->remove($ref_id, $fan_id); }
        else                          { $claimed = (new ContentBundlesModel())->remove_unlock($ref_id, $fan_id); }
        if ((int) $claimed < 1) {
            return array('ok' => false, 'message' => 'Purchase not found (it may already be refunded)');
        }

        $credits = new CreditsModel();
        // 2) Credit the buyer back.
        if ($credits->apply_delta($fan_id, $charge, 'refund', 'Refund: ' . $this->label($kind)) === false) {
            error_log("[refund] access revoked but buyer credit FAILED: kind=$kind ref=$ref_id fan=$fan_id credits=$charge");
            return array('ok' => false, 'message' => 'Access was revoked but the buyer could not be credited. Credit ' . $charge . ' credits by hand.');
        }
        // 3) Claw the creator's net earning back in full. If they already spent or cashed it out, their balance goes
        //    negative and future earnings repay it (withdrawable() stays at zero until then).
        $creator_row = (new UsersModel())->get_user_by_id($creator_id);
        $creator_row = (is_array($creator_row) && count($creator_row) === 1) ? $creator_row[0] : null;
        // Reverse what they actually earned at sale time; legacy rows (0) fall back to today's rate.
        $net = (int) $rows[0]['net_credits'];
        if ($net <= 0) { $net = (int) round($charge * (100 - Plan::fee_percent($creator_row)) / 100); }
        $clawback_ok = 1; $clawback = 0;
        if ($net > 0) {
            if ($credits->apply_delta($creator_id, -$net, 'refund_reversal', 'Refund reversal: ' . $this->label($kind)) !== false) {
                $clawback = $net;
            }
            if ($clawback < $net) { $clawback_ok = 0; }   // no wallet row for the creator: logged for a manual fix
        }
        // 4) Reverse the post's recorded earnings so revenue reporting stays correct.
        if ($kind === 'ppv' && $net > 0) { (new PostsModel())->add_earnings($ref_id, -$net * 10); }
        // 5) Tell both sides.
        Notify::send($fan_id, 'refunds', 'Refund issued', Notify::credits($charge) . ' returned to your wallet for a ' . $this->label($kind) . '.', '/account/settings?section=wallet', 'fa-rotate-left');
        Notify::send($creator_id, 'refunds', 'Refund issued to a buyer', Notify::credits($charge) . ' was refunded for a ' . $this->label($kind) . ($clawback > 0 ? '; ' . Notify::credits($clawback) . ' came out of your balance.' : '.'), '/dashboard', 'fa-rotate-left');
        // 6) Audit log.
        $this->log($kind, $ref_id, $creator_id, $fan_id, $charge, $clawback, $clawback_ok, $admin_id, $reason);
        return array('ok' => true, 'amount' => $charge, 'clawback_ok' => (bool) $clawback_ok);
    }

    /**
     * Record a refund (credits back to the fan, the creator's earning clawed back). Every refund path writes here
     * (unlocks above, EventRefunds, ServiceRefunds) so admin financials and reports see all of them.
     * $by: who issued it (admin, the creator, or the fan canceling their own ticket).
     */
    public function log($kind, $ref_id, $creator_id, $fan_id, $amount, $clawback, $clawback_ok, $by, $reason){
        parent::insert('refunds', array(
            'kind' => (string) $kind, 'ref_id' => (int) $ref_id, 'creator_id' => (int) $creator_id, 'fan_id' => (int) $fan_id,
            'amount_credits' => (int) $amount, 'clawback_credits' => (int) $clawback, 'clawback_ok' => $clawback_ok ? 1 : 0,
            'admin_id' => (int) $by, 'reason' => mb_substr((string) $reason, 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    /** Record a Stripe dispute (chargeback) and suspend the associated account. Idempotent. Returns the suspended user id, or 0. */
    public function record_chargeback($dispute){
        $did = (string) ($dispute->id ?? '');
        if ($did === '') { return 0; }
        $ex = parent::select("SELECT id FROM chargebacks WHERE stripe_dispute_id = :d", array('d' => $did));
        if (is_array($ex) && count($ex)) { return 0; }

        $pi = (string) ($dispute->payment_intent ?? '');
        $user_id = null;
        if ($pi !== '') {
            $u = parent::select("SELECT user_id FROM credit_transactions WHERE stripe_payment_intent_id = :pi LIMIT 1", array('pi' => $pi));
            if (is_array($u) && count($u)) { $user_id = (int) $u[0]['user_id']; }
            if (!$user_id) {   // not a credit purchase: a creator plan charge (app-managed billing)
                $b = parent::select("SELECT user_id FROM billing_charges WHERE stripe_payment_intent_id = :pi LIMIT 1", array('pi' => $pi));
                if (is_array($b) && count($b)) { $user_id = (int) $b[0]['user_id']; }
            }
        }
        $suspended = 0;
        if ($user_id) {
            $suspended = parent::update('user_accounts', array('user_status' => 'Disabled'), 'user_id = :id AND is_admin = 0', array('id' => $user_id)) ? 1 : 0;
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
