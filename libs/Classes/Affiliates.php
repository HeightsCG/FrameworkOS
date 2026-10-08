<?php
/**
 * Affiliate program (/affiliates). An approved affiliate shares /?aff=<code>; the visit sets the cls_aff cookie
 * {code, t} for 30 days (last click wins) and logs a click; a signup in that browser stores
 * user_accounts.affiliate_id. Every paid Creator or Studio plan charge of that account then earns the affiliate
 * RATE_PERCENT of the plan part of the charge (BillingService::apply); a dispute on the charge reverses it
 * or refund (WebhookController). Payouts are requested from Price::PAYOUT_MIN_CENTS and paid by staff, by hand.
 * The models own the SQL; nothing here throws into the page, the signup or the billing path.
 */
class Affiliates {

    const RATE_PERCENT = 25;
    const COOKIE = 'cls_aff';
    const DAYS = 30;
    const KINDS = array('subscribe', 'upgrade', 'renewal', 'slots');   // plan invoices that earn (add-on slots included); credit packs never do
    const MENU_TTL = 60;   // seconds the account-menu check is kept in the session

    /** Letters and digits, 3 to 24. */
    public static function valid_code($code): bool {
        return (bool) preg_match('/^[A-Za-z0-9]{3,24}$/', (string) $code);
    }

    /** The share link for a code, on the detected host. */
    public static function link($code): string {
        return Main::get_base_domain() . '/?aff=' . rawurlencode((string) $code);
    }

    /** The selling plan keys (paid, not retired), from PlanTiers. */
    public static function selling_keys(): array {
        $out = array();
        foreach (PlanTiers::all() as $t) { if ((int) ($t['price'] ?? 0) > 0 && empty($t['retired'])) { $out[] = (string) $t['key']; } }
        return $out;
    }

    /** For the account menu: is the signed-in account an approved affiliate? Kept in the session for MENU_TTL seconds. */
    public static function menu_visible($user_id): bool {
        $uid = (int) $user_id;
        if ($uid <= 0) { return false; }
        $c = Session::get('aff_menu');
        if (is_array($c) && (int) ($c['u'] ?? 0) == $uid && (int) ($c['t'] ?? 0) > time() - self::MENU_TTL) { return !empty($c['ok']); }
        $ok = self::approved_for_user($uid) !== null;
        Session::set('aff_menu', array('u' => $uid, 'ok' => $ok ? 1 : 0, 't' => time()));
        return $ok;
    }

    /** The approved affiliate row for this account, or null. */
    public static function approved_for_user($user_id) {
        try {
            $a = (new AffiliatesModel())->for_user((int) $user_id);
            return ($a && (string) $a['status'] === 'approved') ? $a : null;
        } catch (\Throwable $e) {
            return null;   // before the affiliates SQL runs
        }
    }

    /** A free code from the handle (letters and digits), with digits added when it is taken or too short. */
    public static function new_code($handle): string {
        $m = new AffiliatesModel();
        $base = strtolower(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $handle), 0, 16));
        if (strlen($base) >= 3 && !$m->code_taken($base)) { return $base; }
        if (strlen($base) < 3) { $base = 'cls' . $base; }
        for ($i = 0; $i < 20; $i++) {
            $c = $base . random_int(100, 99999);
            if (!$m->code_taken($c)) { return $c; }
        }
        return 'cls' . bin2hex(random_bytes(8));
    }

    /**
     * A page view with ?aff=<code> (home page, public pages): when the code is an approved affiliate's, remember it in
     * cls_aff (last click wins) and log one click per viewer per hour. Never throws.
     */
    public static function capture($ip = ''): void {
        try {
            $code = (string) ($_GET['aff'] ?? '');
            if ($code == '' || !self::valid_code($code) || (int) Session::get('user_id') > 0) { return; }   // signed-out visitors only
            $a = (new AffiliatesModel())->by_code($code);
            if (!$a || (string) $a['status'] !== 'approved') { return; }
            if (!headers_sent()) {
                setcookie(self::COOKIE, json_encode(array('code' => (string) $a['code'], 't' => time())), array('expires' => time() + self::DAYS * 86400, 'path' => '/',
                    'secure' => strpos(Main::get_base_domain(), 'https:') === 0, 'httponly' => false, 'samesite' => 'Lax'));
            }
            $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview/i', $ua)) { return; }   // crawlers keep the link working but are not clicks
            $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            (new AffiliateClicksModel())->record((int) $a['id'], sha1((string) $ip . '|' . $ua), $path == '' ? '/' : $path);
        } catch (\Throwable $e) {
            error_log('[affiliates] capture: ' . $e->getMessage());
        }
    }

    /** The approved affiliate in this browser's cls_aff cookie (inside its 30 days), or null. */
    private static function cookie_affiliate() {
        $c = json_decode((string) ($_COOKIE[self::COOKIE] ?? ''), true);
        if (!is_array($c) || !self::valid_code($c['code'] ?? '') || (int) ($c['t'] ?? 0) < time() - self::DAYS * 86400) { return null; }
        $a = (new AffiliatesModel())->by_code((string) $c['code']);
        return ($a && (string) $a['status'] === 'approved') ? $a : null;
    }

    /**
     * A new account (email or Google signup, after record_signup_params): stamp the referring affiliate. The cls_aff
     * cookie first; else the creator from ?ref= when that creator is an approved affiliate. Never the account itself.
     */
    public static function attribute_signup($user_id): void {
        try {
            $uid = (int) $user_id;
            if ($uid <= 0) { return; }
            $a = self::cookie_affiliate();
            if (!$a) {
                $rows = (new UsersModel())->get_user_by_id($uid);
                $ref = (is_array($rows) && count($rows) === 1) ? (int) ($rows[0]['referred_by_creator_id'] ?? 0) : 0;
                $a = $ref > 0 ? self::approved_for_user($ref) : null;
            }
            if (!$a || (int) $a['user_id'] === $uid) { return; }
            (new AffiliatesModel())->set_user_affiliate($uid, (int) $a['id']);
        } catch (\Throwable $e) {
            error_log('[affiliates] signup user_id=' . (int) $user_id . ': ' . $e->getMessage());
        }
    }

    /** The part of a charge a commission is paid on: every line except AI credit packs, never below zero. */
    public static function commission_base(array $row): int {
        $sum = 0;
        foreach ((array) json_decode((string) ($row['line_items'] ?? ''), true) as $l) {
            if (preg_match('/ AI credits \(monthly\)$/', (string) ($l['label'] ?? ''))) { continue; }   // the pack line (BillingService::lines)
            $sum += (int) ($l['amount_cents'] ?? 0);
        }
        return max(0, min($sum, (int) ($row['amount_cents'] ?? 0)));
    }

    /**
     * A plan charge succeeded (BillingService::apply): when the account was referred by an approved affiliate and the
     * charge is for a selling plan, write one earned commission (RATE_PERCENT, rounded down to the cent) and tell the
     * affiliate. Nothing for a $0 charge, a pack, or the affiliate's own account. Never throws.
     */
    public static function on_charge(array $row): void {
        try {
            if (!in_array((string) $row['kind'], self::KINDS, true)) { return; }
            $fx = (array) json_decode((string) ($row['effects'] ?? ''), true);
            $uid = (int) $row['user_id'];
            $plan = (string) $row['kind'] === 'slots' ? (string) ((new BillingAccountsModel())->get($uid)['plan_key'] ?? '') : (string) ($fx['plan'] ?? '');   // add-on slots: the plan they sit on
            if (!in_array($plan, self::selling_keys(), true)) { return; }
            $aid = (new AffiliatesModel())->affiliate_of_user($uid);
            if ($aid <= 0) { return; }
            $a = (new AffiliatesModel())->get($aid);
            if (!$a || (string) $a['status'] !== 'approved' || (int) $a['user_id'] === $uid) { return; }
            $base = self::commission_base($row);
            $cents = intdiv($base * self::RATE_PERCENT, 100);
            if ($cents <= 0) { return; }
            $id = (new AffiliateCommissionsModel())->add_earned($aid, $uid, (int) $row['id'], $base, $cents);
            if ($id > 0) {
                Notify::send((int) $a['user_id'], 'system', 'You earned a commission', self::money($cents) . ' from a plan payment by an account you referred.', '/affiliates/dashboard', 'fa-handshake');
            }
        } catch (\Throwable $e) {
            error_log('[affiliates] commission charge_id=' . (int) ($row['id'] ?? 0) . ': ' . $e->getMessage());
        }
    }

    /**
     * A refund or dispute on a platform plan charge (WebhookController): reverse its commission. Earned: reversed. In a
     * requested payout: reversed and taken out of the payout. Paid: stays paid, a negative adjustment nets it. Never throws;
     * false only when the affiliate's payout lock was busy, so the webhook can ask for a redelivery.
     */
    public static function on_reverse($payment_intent_id): bool {
        try {
            if ((string) $payment_intent_id == '') { return true; }
            $ch = (new BillingChargesModel())->by_payment_intent((string) $payment_intent_id);
            if (!$ch) { return true; }
            $r = (new AffiliateCommissionsModel())->reverse_charge((int) $ch['id'], Price::PAYOUT_MIN_CENTS);
            if ($r === AffiliateCommissionsModel::LOCK_BUSY) { error_log('[affiliates] reverse ' . $payment_intent_id . ': lock busy'); return false; }
        } catch (\Throwable $e) {
            error_log('[affiliates] reverse ' . $payment_intent_id . ': ' . $e->getMessage());
        }
        return true;
    }

    /** One affiliate's numbers for the dashboard and admin. */
    public static function stats(array $a): array {
        $id = (int) $a['id'];
        $ref = (new AffiliatesModel())->referral_counts($id);
        $cm = new AffiliateCommissionsModel();
        $pay = (new AffiliatePayoutsModel())->totals($id);
        return array('clicks' => (new AffiliateClicksModel())->count_for($id), 'signups' => $ref['signups'], 'paying' => $ref['paying'],
            'earned' => $cm->earned_cents($id), 'available' => $cm->available_cents($id), 'pending' => $pay['requested'], 'paid' => $pay['paid']);
    }

    /**
     * Ask for a payout of everything earned and not yet requested. Returns ['ok' => bool, 'message', 'id'].
     * One open request at a time; refused under Price::PAYOUT_MIN_CENTS.
     */
    public static function request_payout(array $a): array {
        $r = (new AffiliatePayoutsModel())->request((int) $a['id'], Price::PAYOUT_MIN_CENTS);
        if ($r['result'] === 'busy') { return array('ok' => false, 'message' => 'Your request is still being handled. Try again in a moment.'); }
        if ($r['result'] === 'open') { return array('ok' => false, 'message' => 'You already have a payout request waiting.'); }
        if ($r['result'] === 'under') { return array('ok' => false, 'message' => 'Minimum payout is ' . Price::PAYOUT_MIN_LABEL . '.'); }
        return array('ok' => true, 'id' => (int) $r['id'], 'message' => 'Payout of ' . self::money($r['amount']) . ' requested');
    }

    public static function money($cents): string {
        return ((int) $cents < 0 ? '-$' : '$') . number_format(abs((int) $cents) / 100, 2);
    }
}
