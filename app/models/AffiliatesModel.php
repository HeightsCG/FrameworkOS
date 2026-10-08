<?php
/**
 * Affiliate accounts (affiliates, one row per user): 'pending' after /affiliates/apply, 'approved' (earning),
 * 'rejected' or 'disabled' by staff. Also owns user_accounts.affiliate_id (who referred an account).
 * The rules live in libs/Classes/Affiliates.php; this model only reads and writes rows.
 */
class AffiliatesModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function get($id){
        $r = parent::select("SELECT * FROM affiliates WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function for_user($user_id){
        $r = parent::select("SELECT * FROM affiliates WHERE user_id = :u", array('u' => (int) $user_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function by_code($code){
        $r = parent::select("SELECT * FROM affiliates WHERE code = :c", array('c' => (string) $code));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function code_taken($code): bool {
        return $this->by_code($code) !== null;
    }

    /** A new application (pending). Returns the new id. */
    public function create($user_id, $code, $website, $note){
        return (int) parent::insert('affiliates', array('user_id' => (int) $user_id, 'code' => (string) $code, 'status' => 'pending',
            'website' => (string) $website, 'note' => (string) $note, 'created_at' => gmdate('Y-m-d H:i:s')));
    }

    /** A rejected applicant applies again: back to pending with the new details. */
    public function reapply($id, $website, $note){
        return parent::update('affiliates', array('status' => 'pending', 'website' => (string) $website, 'note' => (string) $note, 'created_at' => gmdate('Y-m-d H:i:s')),
            "id = :id AND status = 'rejected'", array('id' => (int) $id));
    }

    public function set_status($id, $status){
        $f = array('status' => (string) $status);
        if ($status === 'approved') { $f['approved_at'] = gmdate('Y-m-d H:i:s'); }
        return parent::update('affiliates', $f, 'id = :id', array('id' => (int) $id));
    }

    /** Every affiliate with the applicant's account and its numbers, applications first, newest first. */
    public function admin_list($limit = 300){
        return parent::select(
            "SELECT a.*, u.u_name, u.first_name, u.last_name, u.user_email,
                    (SELECT COUNT(*) FROM affiliate_clicks c WHERE c.affiliate_id = a.id) AS clicks,
                    (SELECT COUNT(*) FROM user_accounts r WHERE r.affiliate_id = a.id) AS signups,
                    (SELECT COALESCE(SUM(m.commission_cents), 0) FROM affiliate_commissions m WHERE m.affiliate_id = a.id AND m.status IN ('earned', 'paid')) AS earned_cents,
                    (SELECT COALESCE(SUM(p.amount_cents), 0) FROM affiliate_payouts p WHERE p.affiliate_id = a.id AND p.status = 'paid') AS paid_cents
             FROM affiliates a
             JOIN user_accounts u ON u.user_id = a.user_id
             ORDER BY (a.status = 'pending') DESC, a.created_at DESC
             LIMIT " . max(1, (int) $limit)
        );
    }

    /** Signups this affiliate referred, and how many of them are on a paid plan now. */
    public function referral_counts($id): array {
        $r = parent::select("SELECT COUNT(*) AS signups, COALESCE(SUM(" . Plan::paid_sql('u') . "), 0) AS paying FROM user_accounts u WHERE u.affiliate_id = :a", array('a' => (int) $id));
        return array('signups' => (int) ($r[0]['signups'] ?? 0), 'paying' => (int) ($r[0]['paying'] ?? 0));
    }

    /** Who referred this account (user_accounts.affiliate_id), 0 when nobody. */
    public function affiliate_of_user($user_id): int {
        $r = parent::select("SELECT affiliate_id FROM user_accounts WHERE user_id = :u", array('u' => (int) $user_id));
        return (int) ($r[0]['affiliate_id'] ?? 0);
    }

    /** Stamp the referring affiliate on a new account (only when none is set). */
    public function set_user_affiliate($user_id, $affiliate_id){
        return parent::update('user_accounts', array('affiliate_id' => (int) $affiliate_id), 'user_id = :u AND affiliate_id IS NULL', array('u' => (int) $user_id));
    }
}
