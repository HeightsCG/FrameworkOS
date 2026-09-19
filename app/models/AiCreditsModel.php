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
     * The plan's monthly grant for one billing period, at most once per period: the
     * grant-period stamp and the balance move in the same transaction, guarded by
     * "stamp <> this period", so two concurrent callers cannot both grant.
     * Returns true when this call granted.
     */
    public function grant_for_period($user_id, $period_key, $credits, $description){
        $user_id = (int) $user_id;
        $credits = (int) $credits;
        if ($credits <= 0 || (string) $period_key === '') { return false; }
        $this->db->beginTransaction();
        try {
            $sth = $this->db->prepare(
                "UPDATE user_accounts SET ai_credit_grant_period = :p, ai_credit_balance = ai_credit_balance + :n, updated_at = :now
                 WHERE user_id = :u AND deleted = 0 AND (ai_credit_grant_period IS NULL OR ai_credit_grant_period <> :p2)"
            );
            $sth->bindValue(':p', (string) $period_key);
            $sth->bindValue(':p2', (string) $period_key);
            $sth->bindValue(':n', $credits, PDO::PARAM_INT);
            $sth->bindValue(':now', date('Y-m-d H:i:s'));
            $sth->bindValue(':u', $user_id, PDO::PARAM_INT);
            $sth->execute();
            if ($sth->rowCount() !== 1) { $this->db->rollBack(); return false; }
            $bal = $this->db->prepare("SELECT ai_credit_balance FROM user_accounts WHERE user_id = :u");
            $bal->bindValue(':u', $user_id, PDO::PARAM_INT);
            $bal->execute();
            $after = (int) $bal->fetchColumn();
            $this->ledger($user_id, 'plan_grant', $credits, $after, (string) $description, null, null);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('[ai_credits] grant_for_period failed: ' . $e->getMessage());
            return false;
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
