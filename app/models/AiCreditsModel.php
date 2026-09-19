<?php
/**
 * AI credits: the balance the platform plan grants every billing date and users top up
 * by purchase ($1 = 1 credit). Spent by influencer image/enhance/video jobs. Separate
 * from the fan-facing wallet (CreditsModel). Every change is a ledger row in
 * ai_credit_transactions with the resulting balance.
 */
class AiCreditsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function get_balance($user_id){
        $rows = parent::select(
            "SELECT ai_credit_balance FROM user_accounts WHERE user_id = :user_id AND deleted = 0",
            array('user_id' => (int) $user_id)
        );
        return (is_array($rows) && count($rows) === 1) ? (int) $rows[0]['ai_credit_balance'] : 0;
    }

    public function get_transactions($user_id, $limit = 10){
        $limit = max(1, (int) $limit);
        return parent::select(
            "SELECT type, credits, balance_after, description, job_id, created_at
             FROM ai_credit_transactions WHERE user_id = :u ORDER BY id DESC LIMIT $limit",
            array('u' => (int) $user_id)
        );
    }

    /** Credit a completed purchase, idempotent by Stripe PaymentIntent id. Returns the new balance. */
    public function credit_purchase($user_id, $credits, $payment_intent_id, $description = 'AI credit purchase'){
        $existing = parent::select(
            "SELECT id FROM ai_credit_transactions WHERE stripe_payment_intent_id = :pi",
            array('pi' => (string) $payment_intent_id)
        );
        if (is_array($existing) && count($existing) >= 1) { return $this->get_balance($user_id); }
        return $this->apply_delta($user_id, (int) $credits, 'purchase', $description, null, (string) $payment_intent_id);
    }

    /**
     * The plan's grant for one billing period. The row remembers the period and how much
     * that period's grant has added so far, so this is idempotent: a new period grants the
     * full amount; the same period grants only the difference when the plan's amount went
     * up (upgrade, or a higher allowance). Row-locked, so concurrent callers cannot both
     * grant. Returns the credits added (0 when nothing was due).
     */
    public function grant_for_period($user_id, $period_key, $credits, $description){
        $user_id = (int) $user_id;
        $credits = (int) $credits;
        if ($credits <= 0 || (string) $period_key === '') { return 0; }
        $this->db->beginTransaction();
        try {
            $sth = $this->db->prepare("SELECT ai_credit_balance, ai_credit_grant_period, ai_credit_grant_amount FROM user_accounts WHERE user_id = :u AND deleted = 0 FOR UPDATE");
            $sth->bindValue(':u', $user_id, PDO::PARAM_INT);
            $sth->execute();
            $row = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $this->db->rollBack(); return 0; }
            $same  = ((string) $row['ai_credit_grant_period'] === (string) $period_key);
            $delta = $same ? $credits - (int) $row['ai_credit_grant_amount'] : $credits;
            if ($delta <= 0) { $this->db->rollBack(); return 0; }
            $after = (int) $row['ai_credit_balance'] + $delta;
            $upd = $this->db->prepare("UPDATE user_accounts SET ai_credit_grant_period = :p, ai_credit_grant_amount = :a, ai_credit_balance = :b, updated_at = :now WHERE user_id = :u");
            $upd->bindValue(':p', (string) $period_key);
            $upd->bindValue(':a', $credits, PDO::PARAM_INT);
            $upd->bindValue(':b', $after, PDO::PARAM_INT);
            $upd->bindValue(':now', date('Y-m-d H:i:s'));
            $upd->bindValue(':u', $user_id, PDO::PARAM_INT);
            $upd->execute();
            $this->ledger($user_id, 'plan_grant', $delta, $after, (string) $description, null, null);
            $this->db->commit();
            return $delta;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('[ai_credits] grant_for_period failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Apply a signed delta atomically (row lock, never negative). Returns the new
     * balance, or false when the account cannot cover a negative delta.
     */
    public function apply_delta($user_id, $credits, $type, $description = '', $job_id = null, $payment_intent_id = null){
        $user_id = (int) $user_id;
        $credits = (int) $credits;
        if ($credits === 0) { return $this->get_balance($user_id); }

        $this->db->beginTransaction();
        try {
            $sth = $this->db->prepare("SELECT ai_credit_balance FROM user_accounts WHERE user_id = :u FOR UPDATE");
            $sth->bindValue(':u', $user_id, PDO::PARAM_INT);
            $sth->execute();
            $row = $sth->fetch(PDO::FETCH_ASSOC);
            $current = $row ? (int) $row['ai_credit_balance'] : 0;
            $after = $current + $credits;
            if (!$row || $after < 0) { $this->db->rollBack(); return false; }

            $upd = $this->db->prepare("UPDATE user_accounts SET ai_credit_balance = :bal, updated_at = :now WHERE user_id = :u");
            $upd->bindValue(':bal', $after, PDO::PARAM_INT);
            $upd->bindValue(':now', date('Y-m-d H:i:s'));
            $upd->bindValue(':u', $user_id, PDO::PARAM_INT);
            $upd->execute();

            $this->ledger($user_id, (string) $type, $credits, $after, (string) $description, $job_id, $payment_intent_id);
            $this->db->commit();
            return $after;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('[ai_credits] apply_delta failed: ' . $e->getMessage());
            return false;
        }
    }

    private function ledger($user_id, $type, $credits, $after, $description, $job_id, $pi){
        $ins = $this->db->prepare(
            "INSERT INTO ai_credit_transactions (user_id, type, credits, balance_after, job_id, stripe_payment_intent_id, description, created_at)
             VALUES (:u, :t, :c, :b, :j, :pi, :d, :now)"
        );
        $ins->bindValue(':u', (int) $user_id, PDO::PARAM_INT);
        $ins->bindValue(':t', $type);
        $ins->bindValue(':c', (int) $credits, PDO::PARAM_INT);
        $ins->bindValue(':b', (int) $after, PDO::PARAM_INT);
        $ins->bindValue(':j', $job_id !== null ? (int) $job_id : null, $job_id !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $ins->bindValue(':pi', $pi !== null && $pi !== '' ? (string) $pi : null);
        $ins->bindValue(':d', mb_substr((string) $description, 0, 255));
        $ins->bindValue(':now', date('Y-m-d H:i:s'));
        $ins->execute();
    }
}
