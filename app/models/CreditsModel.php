<?php
class CreditsModel extends Model {

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
            $current = $row ? (int) $row['credit_balance'] : 0;

            $new_balance = $current + $credits;
            if ($new_balance < 0) {
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
            return $new_balance;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('[credits] apply_delta failed: ' . $e->getMessage());
            return false;
        }
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
