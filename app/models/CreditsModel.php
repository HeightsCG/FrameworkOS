<?php
class CreditsModel extends Model {

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
        // Earned credits (sales, less clawbacks, payouts and returned payouts), capped at the balance, never below zero.
        // Content sales are final, and event earnings only arrive after the event, so nothing needs holding back.
        $rows = parent::select(
            "SELECT COALESCE(SUM(credits), 0) AS net FROM credit_transactions
             WHERE user_id = :u AND (type LIKE '%\\_earning' OR type IN ('refund_reversal', 'payout', 'payout_refund'))",
            array('u' => (int) $user_id)
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

    public function has_payment_intent($payment_intent_id){
        $r = parent::select("SELECT id FROM credit_transactions WHERE stripe_payment_intent_id = :pi LIMIT 1", array('pi' => (string) $payment_intent_id));
        return is_array($r) && count($r) === 1;
    }

    /** Record what the card paid for a top-up (credits + processing fee), for admin Money In. */
    public function set_paid_cents($payment_intent_id, $cents){
        return parent::update('credit_transactions', array('paid_cents' => (int) $cents),
            'stripe_payment_intent_id = :pi AND paid_cents IS NULL', array('pi' => (string) $payment_intent_id));
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
            // Lock the row. A debit may not take the balance below zero (a refund clawback excepted); money coming in is
            // always accepted, even when the wallet is still negative afterwards (it repays the debt).
            $sth = $this->db->prepare(
                "SELECT credit_balance FROM user_accounts WHERE user_id = :user_id FOR UPDATE"
            );
            $sth->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $sth->execute();
            $row = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $this->db->rollBack(); return false; }   // no account: never write a ledger row without a balance
            $current = (int) $row['credit_balance'];

            $new_balance = $current + $credits;
            if ($credits < 0 && $new_balance < 0 && !in_array((string) $type, self::DEBT_TYPES, true)) {
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

    /**
     * A sale in one transaction: take $charge from the buyer and give the creator their $net share, so the buyer is
     * never charged without the creator being paid (or the reverse). Both wallets are locked in user-id order, so two
     * sales at once can't deadlock. Returns the buyer's new balance, or false (not enough funds, or nothing written).
     */
    public function pay($buyer_id, $charge, $spend_type, $spend_desc, $creator_id, $net, $earn_type, $earn_desc){
        $buyer_id = (int) $buyer_id; $creator_id = (int) $creator_id; $charge = (int) $charge; $net = max(0, (int) $net);
        if ($charge <= 0 || $buyer_id === $creator_id) { return false; }
        $this->db->beginTransaction();
        try {
            $ids = array_unique(array($buyer_id, $creator_id)); sort($ids);
            $bal = array();
            $sel = $this->db->prepare("SELECT credit_balance FROM user_accounts WHERE user_id = :u FOR UPDATE");
            foreach ($ids as $id) {
                $sel->bindValue(':u', $id, PDO::PARAM_INT); $sel->execute();
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$row) { $this->db->rollBack(); return false; }
                $bal[$id] = (int) $row['credit_balance'];
            }
            if ($bal[$buyer_id] < $charge) { $this->db->rollBack(); return false; }
            $buyer_after = $bal[$buyer_id] - $charge;
            $this->write_row($buyer_id, -$charge, $buyer_after, $spend_type, $spend_desc);
            if ($net > 0) { $this->write_row($creator_id, $net, $bal[$creator_id] + $net, $earn_type, $earn_desc); }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('[credits] pay failed: ' . $e->getMessage());
            return false;
        }
        if (class_exists('AutoReplenishService')) { AutoReplenishService::after_debit($buyer_id, $buyer_after); }
        return $buyer_after;
    }

    /**
     * Pay the creator their share of one event ticket, once the event is over. One transaction: the registration row
     * is locked and must be a paid, unreleased ticket that wasn't refunded (someone who canceled after the start or was
     * removed doesn't get their money back, so the creator is paid for it), so a refund at the same moment and a second
     * run can't both happen. Returns the credits paid (0 when there was nothing to pay).
     */
    public function release_event_earning($registration_id){
        $this->db->beginTransaction();
        try {
            $sel = $this->db->prepare("SELECT r.id, r.net_credits, e.creator_id FROM event_registrations r JOIN events e ON e.id = r.event_id
                WHERE r.id = :id AND r.status <> 'refunded' AND r.earning_released_at IS NULL AND r.net_credits > 0 FOR UPDATE");
            $sel->execute(array(':id' => (int) $registration_id));
            $reg = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$reg) { $this->db->rollBack(); return 0; }
            $u = $this->db->prepare("SELECT credit_balance FROM user_accounts WHERE user_id = :u FOR UPDATE");
            $u->execute(array(':u' => (int) $reg['creator_id']));
            $row = $u->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $this->db->rollBack(); return 0; }
            $net = (int) $reg['net_credits'];
            $this->write_row((int) $reg['creator_id'], $net, (int) $row['credit_balance'] + $net, 'event_earning', 'Event ticket');
            $this->db->prepare("UPDATE event_registrations SET earning_released_at = :now WHERE id = :id")->execute(array(':now' => date('Y-m-d H:i:s'), ':id' => (int) $reg['id']));
            $this->db->commit();
            return $net;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('[credits] release_event_earning ' . (int) $registration_id . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * The creator marks a service booking delivered: the booking is stamped delivered and the creator is paid their
     * share, in one transaction. The booking row is locked and must still be paid (not refunded) and not yet
     * delivered, so a double click or a refund at the same moment can't pay twice. Free bookings are just marked.
     * Returns the credits paid, or false when the booking can't be marked (already delivered, refunded, not theirs).
     */
    public function release_service_earning($purchase_id, $creator_id){
        $this->db->beginTransaction();
        try {
            $sel = $this->db->prepare("SELECT p.id, p.net_credits FROM service_purchases p JOIN services s ON s.id = p.service_id
                WHERE p.id = :id AND s.creator_id = :c AND p.status = 'paid' AND p.delivered_at IS NULL FOR UPDATE");
            $sel->execute(array(':id' => (int) $purchase_id, ':c' => (int) $creator_id));
            $p = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$p) { $this->db->rollBack(); return false; }
            $net = max(0, (int) $p['net_credits']);
            $now = date('Y-m-d H:i:s');
            if ($net > 0) {
                $u = $this->db->prepare("SELECT credit_balance FROM user_accounts WHERE user_id = :u FOR UPDATE");
                $u->execute(array(':u' => (int) $creator_id));
                $row = $u->fetch(PDO::FETCH_ASSOC);
                if (!$row) { $this->db->rollBack(); return false; }
                $this->write_row((int) $creator_id, $net, (int) $row['credit_balance'] + $net, 'service_earning', 'Service delivered');
            }
            $this->db->prepare("UPDATE service_purchases SET delivered_at = :now, earning_released_at = :now2 WHERE id = :id")
                ->execute(array(':now' => $now, ':now2' => $net > 0 ? $now : null, ':id' => (int) $p['id']));
            $this->db->commit();
            return $net;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            error_log('[credits] release_service_earning ' . (int) $purchase_id . ': ' . $e->getMessage());
            return false;
        }
    }

    /** Inside an open transaction: set a wallet's balance and write its ledger row. */
    private function write_row($user_id, $credits, $balance_after, $type, $description){
        $now = date('Y-m-d H:i:s');
        $upd = $this->db->prepare("UPDATE user_accounts SET credit_balance = :bal, updated_at = :now WHERE user_id = :u");
        $upd->execute(array(':bal' => (int) $balance_after, ':now' => $now, ':u' => (int) $user_id));
        $ins = $this->db->prepare("INSERT INTO credit_transactions (user_id, type, credits, balance_after, description, created_at)
                                   VALUES (:u, :type, :credits, :bal, :description, :now)");
        $ins->execute(array(':u' => (int) $user_id, ':type' => (string) $type, ':credits' => (int) $credits, ':bal' => (int) $balance_after,
                            ':description' => (string) $description, ':now' => $now));
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
