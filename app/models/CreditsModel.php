<?php
class CreditsModel extends Model {

    /** Days a sale's earning is held before it can be cashed out, so a credit refund can still be taken back. */
    const HOLD_DAYS = 7;

    /**
     * Ledger types allowed to take a wallet below zero. A refund's clawback is owed in full even when the credits were
     * already spent or cashed out; the negative balance is a debt that future earnings or top-ups repay.
     */
    const DEBT_TYPES = array('refund_reversal');

    /** Debits that must never trigger an automatic top-up of the user's card. */
    const NO_AUTO_TOPUP = array('payout', 'refund_reversal', 'admin_adjust');


    /**
     * Fixed credit packages (PRD 18.2): $1 = 10 credits. No bonus credits in V1.
     * Admin-configurable packages are a separate admin concern; this is the V1 set.
     * Each entry: dollars => credits. `cents` is derived for Stripe.
     */
    public static $packages = array(
        array('dollars' => 1,   'credits' => 10),
        array('dollars' => 10,  'credits' => 100),
        array('dollars' => 25,  'credits' => 250),
        array('dollars' => 50,  'credits' => 500),
        array('dollars' => 100, 'credits' => 1000),
        array('dollars' => 250, 'credits' => 2500),
        array('dollars' => 500, 'credits' => 5000),
        array('dollars' => 1000, 'credits' => 10000),
    );

    public function __construct(){
        parent::__construct();
    }

    /** Package matching a dollar amount (int dollars), or null. */
    public static function package_for_dollars($dollars){
        foreach (self::$packages as $p) {
            if ((int) $p['dollars'] === (int) $dollars) {
                return $p;
            }
        }
        return null;
    }

    public function get_balance($user_id){
        $rows = parent::select(
            "SELECT credit_balance FROM user_accounts WHERE user_id = :user_id AND deleted = 0",
            array('user_id' => (int) $user_id)
        );
        return (is_array($rows) && count($rows) === 1) ? (int) $rows[0]['credit_balance'] : 0;
    }

    /**
     * Credits the user may cash out: net EARNINGS (sales earnings, less refund clawbacks and
     * payouts already sent, plus failed payouts returned), capped at the live balance.
     * Purchased credits are spendable but never withdrawable (card-to-bank laundering).
     */
    public function withdrawable($user_id){
        // Earnings count once they are older than HOLD_DAYS (so a refund can still be taken back);
        // reversals, payouts and returned payouts always count. Capped at the balance, never below zero.
        $rows = parent::select(
            "SELECT COALESCE(SUM(CASE WHEN type LIKE '%\\_earning' AND created_at > :cut THEN 0 ELSE credits END), 0) AS net
             FROM credit_transactions
             WHERE user_id = :u AND (type LIKE '%\\_earning' OR type IN ('refund_reversal', 'payout', 'payout_refund'))",
            array('u' => (int) $user_id, 'cut' => date('Y-m-d H:i:s', time() - self::HOLD_DAYS * 86400))
        );
        $net = (is_array($rows) && count($rows) === 1) ? (int) $rows[0]['net'] : 0;
        return max(0, min($net, (int) $this->get_balance($user_id)));
    }

    /**
     * Take the whole withdrawable balance off the wallet for a cash out, under a per-user lock so two requests at once
     * can't both read the same balance and pay it twice. Returns the credits taken, 0 if below $min, false on failure.
     */
    public function debit_for_payout($user_id, $min){
        $lock = 'payout:' . (int) $user_id;
        $got  = parent::select("SELECT GET_LOCK(:k, 10) AS l", array('k' => $lock));
        if (empty($got[0]['l'])) { return false; }
        try {
            $amount = (int) $this->withdrawable($user_id);
            if ($amount < (int) $min) { return 0; }
            return $this->apply_delta($user_id, -$amount, 'payout', 'Cash out to bank') === false ? false : $amount;
        } finally {
            parent::select("SELECT RELEASE_LOCK(:k) AS r", array('k' => $lock));
        }
    }

    /** Earnings still inside the hold (credits), and when the oldest of them becomes available. */
    public function held_earnings($user_id): array{
        $rows = parent::select(
            "SELECT COALESCE(SUM(credits), 0) AS n, MIN(created_at) AS oldest FROM credit_transactions
             WHERE user_id = :u AND type LIKE '%\\_earning' AND created_at > :cut",
            array('u' => (int) $user_id, 'cut' => date('Y-m-d H:i:s', time() - self::HOLD_DAYS * 86400)));
        $r = (is_array($rows) && count($rows) === 1) ? $rows[0] : array('n' => 0, 'oldest' => null);
        return array('credits' => (int) $r['n'], 'next_at' => $r['oldest'] ? date('Y-m-d H:i:s', strtotime((string) $r['oldest']) + self::HOLD_DAYS * 86400) : null);
    }

    /** Cash-out history (the 'payout' ledger rows), shaped for the Payouts view. */
    public function get_payout_history($user_id, $limit = 12){
        $limit = (int) $limit;
        $rows = parent::select(
            "SELECT credits, created_at FROM credit_transactions
             WHERE user_id = :u AND type = 'payout'
             ORDER BY id DESC LIMIT $limit",
            array('u' => (int) $user_id)
        );
        $out = array();
        foreach ((array) $rows as $r) {
            $out[] = array(
                'amount'  => abs((int) $r['credits']) * 10,   // credits -> cents ($1 = 10 credits)
                'status'  => 'sent',
                'created' => strtotime((string) $r['created_at']),
                'arrival' => 0,
            );
        }
        return $out;
    }

    public function get_transactions($user_id, $limit = 25){
        $limit = (int) $limit;
        return parent::select(
            "SELECT type, credits, balance_after, description, stripe_payment_intent_id, created_at
             FROM credit_transactions
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT $limit",
            array('user_id' => (int) $user_id)
        );
    }

    /**
     * Credit a completed purchase, idempotent by Stripe PaymentIntent id.
     * Returns the new balance. If the PaymentIntent was already recorded, this is
     * a no-op and returns the current balance.
     */
    public function credit_purchase($user_id, $credits, $payment_intent_id, $description = 'Credit purchase'){
        $user_id = (int) $user_id;
        $credits = (int) $credits;

        $existing = parent::select(
            "SELECT id FROM credit_transactions WHERE stripe_payment_intent_id = :pi",
            array('pi' => $payment_intent_id)
        );
        if (is_array($existing) && count($existing) >= 1) {
            return $this->get_balance($user_id);
        }

        return $this->apply_delta($user_id, $credits, 'purchase', $description, $payment_intent_id);
    }

    /**
     * Apply a signed credit delta atomically: bump the denormalized balance and
     * write a ledger row recording the resulting balance.
     */
    public function apply_delta($user_id, $credits, $type, $description = '', $payment_intent_id = null){
        $user_id = (int) $user_id;
        $credits = (int) $credits;

        $this->db->beginTransaction();
        try {
            // Lock the row and never let the balance go negative.
            $sth = $this->db->prepare(
                "SELECT credit_balance FROM user_accounts WHERE user_id = :user_id FOR UPDATE"
            );
            $sth->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $sth->execute();
            $row = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $this->db->rollBack(); return false; }   // no account: never write a ledger row without a balance
            $current = (int) $row['credit_balance'];

            $new_balance = $current + $credits;
            if ($new_balance < 0 && !in_array((string) $type, self::DEBT_TYPES, true)) {
                $this->db->rollBack();
                return false;
            }

            $upd = $this->db->prepare(
                "UPDATE user_accounts SET credit_balance = :bal, updated_at = :now WHERE user_id = :user_id"
            );
            $upd->bindValue(':bal', $new_balance, PDO::PARAM_INT);
            $upd->bindValue(':now', date('Y-m-d H:i:s'));
            $upd->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $upd->execute();

            $ins = $this->db->prepare(
                "INSERT INTO credit_transactions
                    (user_id, type, credits, balance_after, description, stripe_payment_intent_id, created_at)
                 VALUES (:user_id, :type, :credits, :balance_after, :description, :pi, :now)"
            );
            $ins->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $ins->bindValue(':type', $type);
            $ins->bindValue(':credits', $credits, PDO::PARAM_INT);
            $ins->bindValue(':balance_after', $new_balance, PDO::PARAM_INT);
            $ins->bindValue(':description', $description);
            $ins->bindValue(':pi', $payment_intent_id);
            $ins->bindValue(':now', date('Y-m-d H:i:s'));
            $ins->execute();

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('[credits] apply_delta failed: ' . $e->getMessage());
            return false;
        }
        // A spend that leaves the wallet under the user's threshold tops it up from their saved card.
        if ($credits < 0 && !in_array((string) $type, self::NO_AUTO_TOPUP, true) && class_exists('AutoReplenishService')) {
            AutoReplenishService::after_debit($user_id, $new_balance);
        }
        return $new_balance;
    }

    /** True when an auto top-up was attempted in the last $minutes (guards against charging twice). */

    /** Take the auto-replenish slot: true only for the one request that gets it within $minutes (the conditional update is the mutex). */
    public function claim_autoreplenish_attempt($user_id, $minutes){
        $cut = gmdate('Y-m-d H:i:s', time() - (int) $minutes * 60);
        return parent::update('user_accounts', array('autoreplenish_last_attempt_at' => gmdate('Y-m-d H:i:s')),
            'user_id = :u AND (autoreplenish_last_attempt_at IS NULL OR autoreplenish_last_attempt_at < :cut)', array('u' => (int) $user_id, 'cut' => $cut)) > 0;
    }


    public function get_autoreplenishment($user_id){
        $rows = parent::select(
            "SELECT autoreplenish_enabled, autoreplenish_threshold, autoreplenish_amount_cents, autoreplenish_pm_id
             FROM user_accounts WHERE user_id = :user_id AND deleted = 0",
            array('user_id' => (int) $user_id)
        );
        if (!is_array($rows) || count($rows) !== 1) {
            return array('enabled' => 0, 'threshold' => 0, 'amount_cents' => 0, 'pm_id' => null);
        }
        return array(
            'enabled'      => (int) $rows[0]['autoreplenish_enabled'],
            'threshold'    => (int) $rows[0]['autoreplenish_threshold'],
            'amount_cents' => (int) $rows[0]['autoreplenish_amount_cents'],
            'pm_id'        => $rows[0]['autoreplenish_pm_id'],
        );
    }

    public function save_autoreplenishment($user_id, $enabled, $threshold, $amount_cents, $pm_id){
        return parent::update(
            'user_accounts',
            array(
                'autoreplenish_enabled'      => $enabled ? 1 : 0,
                'autoreplenish_threshold'    => (int) $threshold,
                'autoreplenish_amount_cents' => (int) $amount_cents,
                'autoreplenish_pm_id'        => ($pm_id === '' ? null : $pm_id),
                'updated_at'                 => date('Y-m-d H:i:s'),
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

}
