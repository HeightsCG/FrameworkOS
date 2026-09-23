<?php
/**
 * App-managed platform billing state, one row per account (billing_accounts). The app owns the
 * plan, add-ons and schedule; Stripe only holds the card. BillingService is the only writer of
 * plan/period state and mirrors it onto user_accounts so Plan:: reads stay plain column reads.
 */
class BillingAccountsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function get($user_id){
        $r = parent::select("SELECT * FROM billing_accounts WHERE user_id = :u", array('u' => (int) $user_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** The row, created as Free when the account has none yet. */
    public function get_or_create($user_id){
        $row = $this->get($user_id);
        if ($row) { return $row; }
        parent::sql("INSERT IGNORE INTO billing_accounts (user_id, plan_key, status) VALUES (:u, 'free', 'free')", array(':u' => (int) $user_id));
        return $this->get($user_id);
    }

    public function save($user_id, array $fields){
        $this->get_or_create($user_id);
        return parent::update('billing_accounts', $fields, 'user_id = :uid', array('uid' => (int) $user_id));
    }

    /** Accounts whose next charge is due (renewals, and Free accounts with a recurring pack). */
    public function due($now, $limit = 50){
        $limit = max(1, (int) $limit);
        return (array) parent::select(
            "SELECT * FROM billing_accounts WHERE status = 'active' AND next_charge_at IS NOT NULL AND next_charge_at <= :n
             ORDER BY next_charge_at ASC LIMIT $limit", array('n' => (string) $now));
    }

    /** Past-due accounts whose next retry is due. */
    public function retries_due($now, $limit = 50){
        $limit = max(1, (int) $limit);
        return (array) parent::select(
            "SELECT * FROM billing_accounts WHERE status = 'past_due' AND next_retry_at IS NOT NULL AND next_retry_at <= :n
             ORDER BY next_retry_at ASC LIMIT $limit", array('n' => (string) $now));
    }

    /** Every billed account for the admin Billing tab, with the owner's name and the last charge. */
    public function admin_list($limit = 300){
        $limit = max(1, (int) $limit);
        return (array) parent::select(
            "SELECT b.*, u.u_name, u.first_name, u.last_name, u.user_email,
                    c.amount_cents AS last_amount_cents, c.failure_reason AS last_failure, c.created_at AS last_charge_at
             FROM billing_accounts b
             JOIN user_accounts u ON u.user_id = b.user_id AND u.deleted = 0
             LEFT JOIN billing_charges c ON c.id = b.last_charge_id
             WHERE b.status <> 'free' OR b.last_charge_id IS NOT NULL
             ORDER BY FIELD(b.status, 'past_due', 'active', 'canceled', 'free'), b.next_charge_at ASC LIMIT $limit");
    }

    /** Monthly recurring revenue (cents) and account count per plan, from live billing rows. */
    public function recurring_summary(){
        return (array) parent::select(
            "SELECT plan_key, COUNT(*) AS n, SUM(influencer_slots) AS slots, SUM(COALESCE(pack_price_cents, 0)) AS pack_cents
             FROM billing_accounts WHERE status IN ('active', 'past_due') GROUP BY plan_key");
    }
}
