<?php
/**
 * AI credits: the balance the platform plan grants every billing date and users top up
 * by purchase ($1 = 10 credits, PlanTiers::AI_CREDITS_PER_DOLLAR). Spent by influencer image/enhance/video jobs. Separate
 * from the fan-facing wallet (CreditsModel). Every change is a ledger row in
 * ai_credit_transactions with the resulting balance.
 *
 * ai_credit_balance is the total. Two buckets inside it: ai_credits_plan (the plan's included
 * credits, replaced every billing period and spent first) and ai_credits_pack (the recurring
 * credit pack, spent next). Whatever is left is bought or starter credits, which never expire.
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

    /** Credits spent on runs since $since (UTC), net of refunds. */
    public function spent_since($user_id, $since){
        $rows = parent::select(
            "SELECT COALESCE(SUM(credits), 0) AS n FROM ai_credit_transactions
             WHERE user_id = :u AND type IN ('spend', 'refund') AND created_at >= :s",
            array('u' => (int) $user_id, 's' => (string) $since)
        );
        return max(0, -(int) ((is_array($rows) && count($rows)) ? $rows[0]['n'] : 0));
    }

    /** Credit a completed purchase, idempotent by Stripe PaymentIntent id. Returns the new balance. */
    public function has_payment_intent($payment_intent_id){
        $r = parent::select("SELECT id FROM ai_credit_transactions WHERE stripe_payment_intent_id = :pi LIMIT 1", array('pi' => (string) $payment_intent_id));
        return is_array($r) && count($r) === 1;
    }

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
            $sth = $this->db->prepare("SELECT ai_credit_balance, ai_credits_plan, ai_credits_pack FROM user_accounts WHERE user_id = :u FOR UPDATE");
            $sth->bindValue(':u', $user_id, PDO::PARAM_INT);
            $sth->execute();
            $row = $sth->fetch(PDO::FETCH_ASSOC);
            $current = $row ? (int) $row['ai_credit_balance'] : 0;
            $after = $current + $credits;
            if (!$row || $after < 0) { $this->db->rollBack(); return false; }

            // Spending drains the plan's included credits first, then the recurring pack, then the rest.
            $plan = (int) $row['ai_credits_plan']; $pack = (int) $row['ai_credits_pack'];
            if ($credits < 0) {
                $need = -$credits;
                $from_plan = min($plan, $need); $plan -= $from_plan; $need -= $from_plan;
                $from_pack = min($pack, $need); $pack -= $from_pack;
            }
            $plan = min($plan, $after); $pack = min($pack, max(0, $after - $plan));

            $upd = $this->db->prepare("UPDATE user_accounts SET ai_credit_balance = :bal, ai_credits_plan = :pl, ai_credits_pack = :pk, updated_at = :now WHERE user_id = :u");
            $upd->bindValue(':pl', $plan, PDO::PARAM_INT);
            $upd->bindValue(':pk', $pack, PDO::PARAM_INT);
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

    /**
     * Replace a bucket ('plan' or 'pack') with $credits: what is left of the old bucket expires first
     * (unless $keep_old, e.g. a recurring pack whose credits carry over), then the new credits land.
     * Returns the new balance, or false.
     */
    public function set_bucket($user_id, $bucket, $credits, $description, $keep_old = false){
        $user_id = (int) $user_id; $credits = max(0, (int) $credits);
        $col = ($bucket === 'pack') ? 'ai_credits_pack' : 'ai_credits_plan';
        $this->db->beginTransaction();
        try {
            $sth = $this->db->prepare("SELECT ai_credit_balance, ai_credits_plan, ai_credits_pack FROM user_accounts WHERE user_id = :u FOR UPDATE");
            $sth->bindValue(':u', $user_id, PDO::PARAM_INT);
            $sth->execute();
            $row = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $this->db->rollBack(); return false; }
            $bal = (int) $row['ai_credit_balance']; $old = min((int) $row[$col], $bal);
            if ($old > 0 && !$keep_old) {
                $bal -= $old;
                $this->ledger($user_id, $bucket . '_expire', -$old, $bal, ($bucket === 'pack' ? 'Unused pack credits expired' : 'Unused plan credits expired'), null, null);
            }
            $new = ($keep_old ? $old : 0) + $credits;
            $bal += $credits;
            if ($credits > 0) { $this->ledger($user_id, $bucket === 'pack' ? 'pack_grant' : 'plan_grant', $credits, $bal, (string) $description, null, null); }
            $upd = $this->db->prepare("UPDATE user_accounts SET ai_credit_balance = :b, $col = :n, updated_at = :now WHERE user_id = :u");
            $upd->bindValue(':b', $bal, PDO::PARAM_INT);
            $upd->bindValue(':n', $new, PDO::PARAM_INT);
            $upd->bindValue(':now', date('Y-m-d H:i:s'));
            $upd->bindValue(':u', $user_id, PDO::PARAM_INT);
            $upd->execute();
            $this->db->commit();
            return $bal;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('[ai_credits] set_bucket failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Add to the plan bucket mid-period (an upgrade raises the included amount). */
    public function add_plan_credits($user_id, $credits, $description){
        if ((int) $credits <= 0) { return $this->get_balance($user_id); }
        return $this->set_bucket($user_id, 'plan', (int) $credits, $description, true);
    }

    /** Mark part of the existing balance as this period's plan credits (migration from Stripe subscriptions). No ledger row: nothing is added. */
    public function adopt_plan_bucket($user_id, $credits){
        return parent::sql("UPDATE user_accounts SET ai_credits_plan = LEAST(ai_credit_balance, :n) WHERE user_id = :u", array(':n' => max(0, (int) $credits), ':u' => (int) $user_id));
    }

    /** Give back the credits a failed run took, once per $key (a job or asset can fail more than once). */
    public function refund_once($user_id, $credits, $key){
        $desc = 'Refund: ' . (string) $key;
        // A named lock makes check-then-refund atomic: two workers failing the same job can't both refund it.
        $lock = 'ai_refund:' . (int) $user_id . ':' . substr(md5($desc), 0, 16);
        $got  = parent::select("SELECT GET_LOCK(:k, 5) AS l", array('k' => $lock));
        try {
            $r = parent::select("SELECT id FROM ai_credit_transactions WHERE user_id = :u AND type = 'refund' AND description = :d LIMIT 1", array('u' => (int) $user_id, 'd' => $desc));
            if (is_array($r) && count($r)) { return false; }
            return $this->apply_delta($user_id, (int) $credits, 'refund', $desc);
        } finally {
            if (!empty($got[0]['l'])) { parent::select("SELECT RELEASE_LOCK(:k) AS r", array('k' => $lock)); }
        }
    }

    /** The two buckets and the rest, for the billing page. */
    public function buckets($user_id){
        $r = parent::select("SELECT ai_credit_balance, ai_credits_plan, ai_credits_pack FROM user_accounts WHERE user_id = :u", array('u' => (int) $user_id));
        $row = (is_array($r) && count($r)) ? $r[0] : array('ai_credit_balance' => 0, 'ai_credits_plan' => 0, 'ai_credits_pack' => 0);
        $total = (int) $row['ai_credit_balance']; $plan = min((int) $row['ai_credits_plan'], $total); $pack = min((int) $row['ai_credits_pack'], $total - $plan);
        return array('total' => $total, 'plan' => $plan, 'pack' => $pack, 'other' => $total - $plan - $pack);
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
