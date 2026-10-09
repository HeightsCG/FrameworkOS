<?php
/** Affiliate payout requests (affiliate_payouts): 'requested' by the affiliate, 'paid' or 'rejected' by staff (paid by bank, by hand). */
class AffiliatePayoutsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function get($id){
        $r = parent::select("SELECT * FROM affiliate_payouts WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /**
     * A payout request, all under the affiliate's lock (shared with reversals): no other open request, then every
     * earned row not yet requested is attached first and the amount is the SUM of what was attached. Under $min_cents
     * the request is undone. Returns ['result' => ok|open|under|busy, 'id', 'amount'].
     */
    public function request($affiliate_id, $min_cents): array {
        $aid = (int) $affiliate_id;
        $cm = new AffiliateCommissionsModel();
        if (!$cm->lock($aid)) { return array('result' => 'busy', 'id' => 0, 'amount' => 0); }
        try {
            if ($this->open_for($aid)) { return array('result' => 'open', 'id' => 0, 'amount' => 0); }
            if ($cm->available_cents($aid) < (int) $min_cents) { return array('result' => 'under', 'id' => 0, 'amount' => 0); }
            $pid = $this->create($aid, 0);
            $cm->attach_to_payout($aid, $pid);
            $sum = $cm->sum_for_payout($pid);
            if ($sum < (int) $min_cents) {
                $cm->detach_payout($pid);
                parent::delete('affiliate_payouts', 'id = :p', 1, array('p' => $pid));
                return array('result' => 'under', 'id' => 0, 'amount' => 0);
            }
            parent::update('affiliate_payouts', array('amount_cents' => $sum), 'id = :p', array('p' => $pid));
            return array('result' => 'ok', 'id' => $pid, 'amount' => $sum);
        } finally {
            $cm->unlock($aid);
        }
    }

    public function create($affiliate_id, $amount_cents){
        return (int) parent::insert('affiliate_payouts', array('affiliate_id' => (int) $affiliate_id, 'amount_cents' => (int) $amount_cents,
            'status' => 'requested', 'requested_at' => gmdate('Y-m-d H:i:s')));
    }

    public function open_for($affiliate_id){
        $r = parent::select("SELECT * FROM affiliate_payouts WHERE affiliate_id = :a AND status = 'requested' LIMIT 1", array('a' => (int) $affiliate_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** requested => paid | rejected, once. Returns rows changed. */
    public function settle($id, $status, $note = ''){
        $f = array('status' => (string) $status, 'note' => (string) $note === '' ? null : mb_substr((string) $note, 0, 255));
        if ($status === 'paid') { $f['paid_at'] = gmdate('Y-m-d H:i:s'); }
        return parent::update('affiliate_payouts', $f, "id = :id AND status = 'requested'", array('id' => (int) $id));
    }

    /** Sums by status for one affiliate: ['requested' => cents, 'paid' => cents]. */
    public function totals($affiliate_id): array {
        $out = array('requested' => 0, 'paid' => 0, 'rejected' => 0);
        foreach ((array) parent::select("SELECT status, COALESCE(SUM(amount_cents), 0) AS s FROM affiliate_payouts WHERE affiliate_id = :a GROUP BY status", array('a' => (int) $affiliate_id)) as $r) {
            $out[(string) $r['status']] = (int) $r['s'];
        }
        return $out;
    }

    public function list_for($affiliate_id, $limit = 200){
        return parent::select("SELECT * FROM affiliate_payouts WHERE affiliate_id = :a ORDER BY requested_at DESC, id DESC LIMIT " . max(1, (int) $limit), array('a' => (int) $affiliate_id));
    }

    /** Every payout request for staff: open ones first, then newest. */
    /** Payout requests staff still have to settle (admin rail badge). */
    public function requested_count(){
        $r = parent::select("SELECT COUNT(*) AS n FROM affiliate_payouts WHERE status = 'requested'");
        return is_array($r) && count($r) ? (int) $r[0]['n'] : 0;
    }

    public function admin_list($limit = 200){
        return parent::select(
            "SELECT p.*, a.code, a.user_id, u.u_name, u.first_name, u.last_name, u.user_email
             FROM affiliate_payouts p
             JOIN affiliates a ON a.id = p.affiliate_id
             JOIN user_accounts u ON u.user_id = a.user_id
             ORDER BY (p.status = 'requested') DESC, p.requested_at DESC LIMIT " . max(1, (int) $limit)
        );
    }
}
