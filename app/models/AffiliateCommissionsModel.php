<?php
/**
 * Affiliate commissions (affiliate_commissions), one per paid plan charge. 'earned' counts toward the balance until a
 * payout request takes it (payout_id) and Mark Paid turns it 'paid'. A refund or dispute reverses it: an earned row
 * (in a requested payout or not) becomes 'reversed' and leaves the payout; a paid row stays 'paid' and a negative
 * 'earned' adjustment row (negative invoice_cents) nets it out of the balance. Payout changes run under the
 * per-affiliate lock (LOCK_PREFIX), shared with AffiliatePayoutsModel::request.
 */
class AffiliateCommissionsModel extends Model {

    const LOCK_PREFIX = 'aff_payout_';
    const LOCK_BUSY = -2;

    public function __construct(){
        parent::__construct();
    }

    /** The commission written for this charge (not the adjustment), or null. */
    public function for_charge($charge_id){
        $r = parent::select("SELECT * FROM affiliate_commissions WHERE charge_id = :c AND invoice_cents >= 0 LIMIT 1", array('c' => (int) $charge_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Write an earned commission once per charge. Returns the new id, or 0 when this charge already has one. */
    public function add_earned($affiliate_id, $user_id, $charge_id, $invoice_cents, $commission_cents): int {
        if ($this->for_charge($charge_id)) { return 0; }
        $now = gmdate('Y-m-d H:i:s');
        try {
            return (int) parent::insert('affiliate_commissions', array('affiliate_id' => (int) $affiliate_id, 'referred_user_id' => (int) $user_id,
                'charge_id' => (int) $charge_id, 'invoice_cents' => (int) $invoice_cents, 'commission_cents' => (int) $commission_cents,
                'status' => 'earned', 'created_at' => $now, 'earned_at' => $now));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() == '23000') { return 0; }   // the unique key: a parallel apply got there first
            error_log('[affiliates] add_earned charge ' . (int) $charge_id . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /** Hold the affiliate's payout lock (5 s), or false. */
    public function lock($affiliate_id): bool {
        $got = parent::select("SELECT GET_LOCK(:k, 5) AS l", array('k' => self::LOCK_PREFIX . (int) $affiliate_id));
        return !empty($got[0]['l']);
    }

    public function unlock($affiliate_id): void {
        parent::select("SELECT RELEASE_LOCK(:k) AS r", array('k' => self::LOCK_PREFIX . (int) $affiliate_id));
    }

    /** All affiliates: earned and not yet paid (requested or not, adjustments included). */
    public function owed_cents(): int {
        $r = parent::select("SELECT COALESCE(SUM(commission_cents), 0) AS s FROM affiliate_commissions WHERE status = 'earned'");
        return (int) ($r[0]['s'] ?? 0);
    }

    /**
     * A refund or dispute on the charge. $share (0..1) is how much of the charge it takes back in total (a partial refund
     * reverses only that part of the commission; repeat events for the same refund add nothing). Returns 1 when it reversed
     * something, 0 when there was nothing to do, LOCK_BUSY when the payout lock was held.
     *  - The whole commission, not paid and never partly reversed: the row becomes 'reversed' and leaves any requested
     *    payout (the payout shrinks, or goes when it falls under $min_cents).
     *  - Otherwise (a part of it, or already paid out): a negative 'earned' adjustment row nets the new part from the
     *    balance. Each adjustment's invoice_cents is minus the refunded total so far, which keeps (charge_id, invoice_cents) unique.
     */
    public function reverse_charge($charge_id, $min_cents, $share = 1.0): int {
        $c = $this->for_charge($charge_id);
        if (!$c || (string) $c['status'] == 'reversed') { return 0; }
        $aid = (int) $c['affiliate_id'];
        if (!$this->lock($aid)) { return self::LOCK_BUSY; }
        try {
            $c = $this->for_charge($charge_id);   // again, under the lock
            if (!$c || (string) $c['status'] == 'reversed') { return 0; }
            $share  = max(0.0, min(1.0, (float) $share));
            $full   = (int) $c['commission_cents'];
            $target = $share >= 1.0 ? $full : (int) round($full * $share);
            $adj    = $this->adjustments($charge_id);
            $done   = -array_sum(array_map('intval', array_column($adj, 'commission_cents')));
            $delta  = $target - $done;
            if ($delta <= 0) { return 0; }
            $now = gmdate('Y-m-d H:i:s');
            if ($target >= $full && empty($adj) && (string) $c['status'] != 'paid') {
                $pid = (int) ($c['payout_id'] ?? 0);
                parent::update('affiliate_commissions', array('status' => 'reversed', 'reversed_at' => $now, 'payout_id' => null), 'id = :id', array('id' => (int) $c['id']));
                if ($pid > 0) {   // still only requested: the payout shrinks, or goes when it falls under the minimum
                    $sum = $this->sum_for_payout($pid);
                    if ($sum < (int) $min_cents) {
                        parent::update('affiliate_commissions', array('payout_id' => null), "payout_id = :p AND status = 'earned'", array('p' => $pid));
                        parent::delete('affiliate_payouts', "id = :p AND status = 'requested'", 1, array('p' => $pid));
                    } else {
                        parent::update('affiliate_payouts', array('amount_cents' => $sum), "id = :p AND status = 'requested'", array('p' => $pid));
                    }
                }
                return 1;
            }
            $inv = (int) $c['invoice_cents'];
            $inv_target = $share >= 1.0 ? $inv : max(1, (int) round($inv * $share));
            try {
                parent::insert('affiliate_commissions', array('affiliate_id' => $aid, 'referred_user_id' => (int) $c['referred_user_id'],
                    'charge_id' => (int) $c['charge_id'], 'invoice_cents' => -$inv_target, 'commission_cents' => -$delta,
                    'status' => 'earned', 'created_at' => $now, 'earned_at' => $now));
            } catch (\PDOException $e) {
                if ((string) $e->getCode() == '23000') { return 0; }   // the same refunded total again
                throw $e;
            }
            if ($target >= $full) { parent::update('affiliate_commissions', array('reversed_at' => $now), 'id = :id', array('id' => (int) $c['id'])); }
            return 1;
        } finally {
            $this->unlock($aid);
        }
    }

    /** The negative adjustment rows written for a charge's reversals (any status but reversed). */
    public function adjustments($charge_id): array {
        return (array) parent::select("SELECT * FROM affiliate_commissions WHERE charge_id = :c AND invoice_cents < 0 AND status <> 'reversed'", array('c' => (int) $charge_id));
    }

    /**
     * A dispute was won: give the commission back where that is still possible. A reversed row turns earned again;
     * adjustment rows not yet in a payout are removed. Adjustments already requested or paid stay (logged). Returns 1
     * when something was restored, 0 when nothing could be, LOCK_BUSY when the payout lock was held.
     */
    public function restore_charge($charge_id): int {
        $c = $this->for_charge($charge_id);
        if (!$c) { return 0; }
        $aid = (int) $c['affiliate_id'];
        if (!$this->lock($aid)) { return self::LOCK_BUSY; }
        try {
            $c = $this->for_charge($charge_id);
            $n = 0;
            if ($c && (string) $c['status'] == 'reversed') {
                $n += parent::update('affiliate_commissions', array('status' => 'earned', 'reversed_at' => null, 'payout_id' => null), 'id = :id', array('id' => (int) $c['id']));
            }
            foreach ($this->adjustments($charge_id) as $a) {
                if ((string) $a['status'] == 'earned' && empty($a['payout_id'])) {
                    $n += parent::delete('affiliate_commissions', "id = :id AND status = 'earned' AND payout_id IS NULL", 1, array('id' => (int) $a['id']));
                } else {
                    error_log('[affiliates] restore charge ' . (int) $charge_id . ': adjustment ' . (int) $a['id'] . ' is already in a payout, left as is');
                }
            }
            if ($n > 0 && $c) { parent::update('affiliate_commissions', array('reversed_at' => null), 'id = :id', array('id' => (int) $c['id'])); }
            return $n > 0 ? 1 : 0;
        } finally {
            $this->unlock($aid);
        }
    }

    /** The earned rows a payout holds, summed. */
    public function sum_for_payout($payout_id): int {
        $r = parent::select("SELECT COALESCE(SUM(commission_cents), 0) AS s FROM affiliate_commissions WHERE payout_id = :p AND status = 'earned'", array('p' => (int) $payout_id));
        return (int) ($r[0]['s'] ?? 0);
    }

    /** Earned and not yet in a payout (adjustments included): what a payout request would take. */
    public function available_cents($affiliate_id): int {
        $r = parent::select("SELECT COALESCE(SUM(commission_cents), 0) AS s FROM affiliate_commissions WHERE affiliate_id = :a AND status = 'earned' AND payout_id IS NULL", array('a' => (int) $affiliate_id));
        return (int) ($r[0]['s'] ?? 0);
    }

    /** Lifetime earned (earned + paid, adjustments included; reversed rows left out). */
    public function earned_cents($affiliate_id): int {
        $r = parent::select("SELECT COALESCE(SUM(commission_cents), 0) AS s FROM affiliate_commissions WHERE affiliate_id = :a AND status IN ('earned', 'paid')", array('a' => (int) $affiliate_id));
        return (int) ($r[0]['s'] ?? 0);
    }

    /** Hand every available row to a payout. Returns rows taken. */
    public function attach_to_payout($affiliate_id, $payout_id){
        return parent::update('affiliate_commissions', array('payout_id' => (int) $payout_id), "affiliate_id = :a AND status = 'earned' AND payout_id IS NULL", array('a' => (int) $affiliate_id));
    }

    /** The payout was paid: its earned rows are paid. */
    public function mark_paid($payout_id){
        return parent::update('affiliate_commissions', array('status' => 'paid', 'paid_at' => gmdate('Y-m-d H:i:s')), "payout_id = :p AND status = 'earned'", array('p' => (int) $payout_id));
    }

    /** The payout was rejected: its rows go back to the balance. */
    public function detach_payout($payout_id){
        return parent::update('affiliate_commissions', array('payout_id' => null), "payout_id = :p AND status = 'earned'", array('p' => (int) $payout_id));
    }

    /** One affiliate's commissions between two UTC dates (inclusive days), newest first. */
    public function list_for($affiliate_id, $from = '', $to = '', $limit = 500){
        $w = 'm.affiliate_id = :a'; $p = array('a' => (int) $affiliate_id);
        if ((string) $from !== '') { $w .= ' AND m.created_at >= :f'; $p['f'] = (string) $from . ' 00:00:00'; }
        if ((string) $to !== '')   { $w .= ' AND m.created_at <= :t'; $p['t'] = (string) $to . ' 23:59:59'; }
        return parent::select("SELECT m.*, c.kind FROM affiliate_commissions m LEFT JOIN billing_charges c ON c.id = m.charge_id WHERE $w ORDER BY m.created_at DESC, m.id DESC LIMIT " . max(1, (int) $limit), $p);
    }

    /** The whole ledger for staff (and the CSV), newest first; 0 = no limit. */
    public function ledger($limit = 300){
        return parent::select(
            "SELECT m.*, c.kind, a.code, u.u_name AS referred_handle
             FROM affiliate_commissions m
             JOIN affiliates a ON a.id = m.affiliate_id
             LEFT JOIN billing_charges c ON c.id = m.charge_id
             LEFT JOIN user_accounts u ON u.user_id = m.referred_user_id
             ORDER BY m.created_at DESC, m.id DESC" . ((int) $limit > 0 ? ' LIMIT ' . (int) $limit : '')
        );
    }
}
