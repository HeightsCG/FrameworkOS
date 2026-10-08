<?php
/**
 * Affiliate program API (Affiliates): the application and payout request (any signed-in account), and the staff
 * actions on /admin > Affiliates (approve, reject, disable, payouts, ledger CSV), each audited via AuditTrail.
 */
class ApiAffiliatesController extends BaseApiController {

    use AuditTrail;
    /** User-facing actions here: not staff actions, so not audited. */
    protected $audit_skip = array('affiliate_apply', 'affiliate_payout_request');

    /** Apply (or apply again after a rejection): website or social URL, how they will promote, the terms ticked. */
    public function affiliate_applyAction(){
        $uid = $this->signed_in();
        $website = trim(html_entity_decode((string) ($this->post['website'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $note    = trim(html_entity_decode((string) ($this->post['note'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($website !== '' && !preg_match('#^https?://#i', $website)) { $website = 'https://' . $website; }
        if ($website === '' || strlen($website) > 255 || !filter_var($website, FILTER_VALIDATE_URL)) { $this->jsonError('Enter the website or social profile where you will share your link.'); }
        if (mb_strlen($note) < 10) { $this->jsonError('Tell us how you will promote ' . Main::site_name() . '.'); }
        if (mb_strlen($note) > 2000) { $this->jsonError('Keep how you will promote it under 2,000 characters.'); }
        if ((string) ($this->post['agree'] ?? '') !== '1') { $this->jsonError('Agree to the affiliate terms to apply.'); }
        $m = new AffiliatesModel();
        $a = $m->for_user($uid);
        if ($a && (string) $a['status'] !== 'rejected') { $this->jsonError((string) $a['status'] === 'approved' ? 'You are already an affiliate.' : 'Your application is already in.'); }
        if ($a) { $m->reapply((int) $a['id'], $website, $note); }
        else {
            $rows = $this->userModel->get_user_by_id($uid);
            $m->create($uid, Affiliates::new_code((string) ($rows[0]['u_name'] ?? '')), $website, $note);
        }
        $this->jsonSuccess(['message' => 'Application sent']);
    }

    /** Ask for a payout of everything earned and not yet requested (Price::PAYOUT_MIN_CENTS minimum). */
    public function affiliate_payout_requestAction(){
        $uid = $this->signed_in();
        $a = Affiliates::approved_for_user($uid);
        if (!$a) { $this->jsonError('Affiliates only'); }
        $r = Affiliates::request_payout($a);
        if (!$r['ok']) { $this->jsonError($r['message']); }
        $this->jsonSuccess(['id' => (int) $r['id'], 'message' => $r['message']]);
    }

    /* ---- staff (/admin > Affiliates) ---- */

    /** Approve, reject or disable an affiliate; the applicant is told on approve and reject. */
    public function admin_affiliate_setAction(){
        $this->admin_guard();
        $m = new AffiliatesModel();
        $a = $m->get((int) ($this->post['id'] ?? 0));
        if (!$a) { $this->jsonError('Affiliate not found'); }
        $to = (string) ($this->post['status'] ?? '');
        $from = (string) $a['status'];
        $allowed = array('approved' => array('pending', 'rejected', 'disabled'), 'rejected' => array('pending'), 'disabled' => array('approved'));
        if (!isset($allowed[$to]) || !in_array($from, $allowed[$to], true)) { $this->jsonError('That change is not possible from ' . $from . '.'); }
        $m->set_status((int) $a['id'], $to);
        if ($to === 'approved') {
            Notify::send((int) $a['user_id'], 'system', 'Your affiliate application is approved', 'Your link is ' . Affiliates::link($a['code']) . '. You earn ' . Affiliates::RATE_PERCENT . '% of the plan payments of accounts you refer.', '/affiliates/dashboard', 'fa-handshake', true, true);
        } elseif ($to === 'rejected') {
            Notify::send((int) $a['user_id'], 'system', 'Your affiliate application', 'We could not approve your affiliate application this time. You can apply again from the affiliate page.', '/affiliates', 'fa-handshake', true, true);
        }
        $msg = array('approved' => 'Affiliate approved', 'rejected' => 'Application rejected', 'disabled' => 'Affiliate disabled');
        $this->jsonSuccess(['id' => (int) $a['id'], 'status' => $to, 'message' => $msg[$to]]);
    }

    /** A payout request is paid (by bank, by hand) or rejected (its commissions go back to the balance). */
    public function admin_affiliate_payoutAction(){
        $this->admin_guard();
        $po = new AffiliatePayoutsModel();
        $p = $po->get((int) ($this->post['id'] ?? 0));
        if (!$p) { $this->jsonError('Payout not found'); }
        $to = (string) ($this->post['status'] ?? '');
        if (!in_array($to, array('paid', 'rejected'), true)) { $this->jsonError('Invalid request'); }
        if ((string) $p['status'] !== 'requested') { $this->jsonError('That payout is already ' . $p['status'] . '.'); }
        $note = trim(html_entity_decode((string) ($this->post['note'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $cm = new AffiliateCommissionsModel(); $aid = (int) $p['affiliate_id'];
        if (!$cm->lock($aid)) { $this->jsonError('This affiliate\'s balance is being updated. Try again in a moment.'); }   // same lock as requests and reversals
        try {
            $p = $po->get((int) $p['id']);
            $ok = $p && (string) $p['status'] == 'requested' && $po->settle((int) $p['id'], $to, $note);
            if ($ok && $to == 'paid') { $cm->mark_paid((int) $p['id']); }
            if ($ok && $to == 'rejected') { $cm->detach_payout((int) $p['id']); }
        } finally {
            $cm->unlock($aid);
        }
        if (!$ok) { $this->jsonError('That payout was already settled.'); }
        $a = (new AffiliatesModel())->get($aid);
        if ($to === 'paid') {
            if ($a) { Notify::send((int) $a['user_id'], 'system', 'Your affiliate payout was sent', Affiliates::money($p['amount_cents']) . ' is on its way to your bank.', '/affiliates/dashboard', 'fa-building-columns', true, true); }
        } else {
            if ($a) { Notify::send((int) $a['user_id'], 'system', 'Your affiliate payout request', 'We could not pay this request' . ($note !== '' ? ': ' . $note : '') . '. The amount is back in your balance.', '/affiliates/dashboard', 'fa-building-columns', true, true); }
        }
        $this->jsonSuccess(['id' => (int) $p['id'], 'status' => $to, 'message' => $to === 'paid' ? 'Payout marked paid' : 'Payout rejected']);
    }

    /** /admin > Affiliates > Export CSV: the whole commissions ledger. Audited here (no JSON reply). */
    public function admin_affiliates_csvAction(){
        $this->admin_guard();
        $rows = (new AffiliateCommissionsModel())->ledger(0);
        (new AuditModel())->record((int) Session::get('user_id'), 'admin_affiliates_csv', array(), array('message' => 'Exported ' . count($rows) . ' commissions'), $this->get_ip_address());
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="affiliate-commissions-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Created (UTC)', 'Affiliate code', 'Referred account', 'Charge id', 'Charge kind', 'Invoice', 'Commission', 'Status', 'Earned (UTC)', 'Reversed (UTC)', 'Paid (UTC)', 'Payout id'], ',', '"', '');
        $cell = function ($v) { $v = (string) $v; return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v; };
        foreach ($rows as $r) {
            fputcsv($out, [$r['created_at'], $cell($r['code']), $cell($r['referred_handle']), (int) $r['charge_id'], (string) $r['kind'], number_format((int) $r['invoice_cents'] / 100, 2, '.', ''),
                number_format((int) $r['commission_cents'] / 100, 2, '.', ''), $r['status'], (string) $r['earned_at'], (string) $r['reversed_at'], (string) $r['paid_at'], (string) $r['payout_id']], ',', '"', '');
        }
        fclose($out);
        exit;
    }

    private function signed_in(): int {
        $uid = (int) Session::get('user_id');
        if ($uid <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (UserSession::impersonating()) { $this->jsonError('Not available while you\'re signed in as this user.'); }   // money and applications stay the account owner's
        return $uid;
    }

    private function admin_guard(): void {
        if ((int) Session::get('user_id') <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (!Permissions::is_admin()) { $this->jsonError('Admins only'); }
    }
}
