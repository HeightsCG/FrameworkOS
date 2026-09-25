<?php
/**
 * Every platform charge attempt (billing_charges): line items, the period it covers, the Stripe
 * PaymentIntent, its status, and the effects BillingService applies exactly once on success.
 */
class BillingChargesModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function create(array $f){
        $now = gmdate('Y-m-d H:i:s');
        $f['created_at'] = $now; $f['updated_at'] = $now;
        return (int) parent::insert('billing_charges', $f);
    }

    public function get($id){
        $r = parent::select("SELECT * FROM billing_charges WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function get_for_user($user_id, $id){
        $r = parent::select("SELECT * FROM billing_charges WHERE id = :id AND user_id = :u", array('id' => (int) $id, 'u' => (int) $user_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function by_payment_intent($pi){
        $r = parent::select("SELECT * FROM billing_charges WHERE stripe_payment_intent_id = :pi", array('pi' => (string) $pi));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function set($id, array $f){
        $f['updated_at'] = gmdate('Y-m-d H:i:s');
        return parent::update('billing_charges', $f, 'id = :cid', array('cid' => (int) $id));
    }

    /**
     * Claim the right to apply a succeeded charge's effects. Only one caller (the job, the page or a
     * webhook) ever gets true for a charge, so a period is advanced and credits granted once.
     */
    public function claim_apply($id){
        $sth = $this->db->prepare("UPDATE billing_charges SET applied = 1, status = 'succeeded', failure_reason = NULL, updated_at = :now WHERE id = :id AND applied = 0");
        $sth->bindValue(':now', gmdate('Y-m-d H:i:s'));
        $sth->bindValue(':id', (int) $id, PDO::PARAM_INT);
        $sth->execute();
        return $sth->rowCount() === 1;
    }

    /**
     * Older charges still waiting for bank authentication that a new attempt replaces: renewals for
     * the same period, or any earlier plan subscribe/upgrade. Left open, the customer could confirm
     * both links and pay twice.
     */
    public function open_action_charges($user_id, $kind, $period_start){
        if ($kind === 'renewal') {
            return (array) parent::select("SELECT * FROM billing_charges WHERE user_id = :u AND kind = 'renewal' AND period_start = :p AND status = 'requires_action'",
                array('u' => (int) $user_id, 'p' => (string) $period_start));
        }
        if (in_array($kind, array('subscribe', 'upgrade'), true)) {
            return (array) parent::select("SELECT * FROM billing_charges WHERE user_id = :u AND kind IN ('subscribe', 'upgrade') AND status = 'requires_action'",
                array('u' => (int) $user_id));
        }
        return array();
    }

    /** Has this period's renewal already been paid? (the never-charge-a-period-twice guard) */
    public function renewal_paid($user_id, $period_start){
        $r = parent::select("SELECT id FROM billing_charges WHERE user_id = :u AND kind = 'renewal' AND period_start = :p AND status = 'succeeded' LIMIT 1",
            array('u' => (int) $user_id, 'p' => (string) $period_start));
        return is_array($r) && count($r) === 1;
    }

    /** The latest renewal attempt for a period (retries reuse its period and bump the attempt). */
    public function last_renewal($user_id, $period_start){
        $r = parent::select("SELECT * FROM billing_charges WHERE user_id = :u AND kind = 'renewal' AND period_start = :p ORDER BY attempt DESC, id DESC LIMIT 1",
            array('u' => (int) $user_id, 'p' => (string) $period_start));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Paid plan charges that redeemed this promo code (a subscribe or upgrade; renewals only continue it). */
    public function promo_redemptions($code){
        $r = parent::select("SELECT COUNT(*) AS n FROM billing_charges WHERE status = 'succeeded' AND kind IN ('subscribe', 'upgrade') AND effects LIKE :c",
            array('c' => '%"promo_code":"' . str_replace(array('%', '_'), array('\\%', '\\_'), strtoupper((string) $code)) . '"%'));
        return (is_array($r) && count($r)) ? (int) $r[0]['n'] : 0;
    }

    /** Has this account ever paid anything through app billing? (Stripe's "first-time customers only") */
    public function has_paid_before($user_id){
        $r = parent::select("SELECT id FROM billing_charges WHERE user_id = :u AND status = 'succeeded' AND amount_cents > 0 LIMIT 1", array('u' => (int) $user_id));
        return is_array($r) && count($r) === 1;
    }

    /** An unfinished charge that needs the user to authenticate, newest first. */
    public function awaiting_action($user_id){
        $r = parent::select("SELECT * FROM billing_charges WHERE user_id = :u AND status = 'requires_action' ORDER BY id DESC LIMIT 1", array('u' => (int) $user_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function history($user_id, $limit = 24){
        $limit = max(1, (int) $limit);
        return (array) parent::select("SELECT * FROM billing_charges WHERE user_id = :u ORDER BY id DESC LIMIT $limit", array('u' => (int) $user_id));
    }

    /** Succeeded charges bucketed by UTC month and day (cents), plus the all-time total — admin Financials. */
    public function revenue_buckets(){
        $out = array('month' => array(), 'day' => array(), 'all' => 0);
        $rows = parent::select("SELECT DATE(created_at) AS d, SUM(amount_cents) AS n FROM billing_charges WHERE status = 'succeeded' AND amount_cents > 0 GROUP BY d");
        foreach ((array) $rows as $r) {
            $m = substr((string) $r['d'], 0, 7);
            $out['day'][(string) $r['d']] = (int) $r['n'];
            $out['month'][$m] = ($out['month'][$m] ?? 0) + (int) $r['n'];
            $out['all'] += (int) $r['n'];
        }
        return $out;
    }
}
