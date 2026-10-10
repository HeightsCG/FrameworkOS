<?php
/**
 * Platform admin dashboard (PRD §38). Read models + moderation/user actions for staff
 * (user_accounts.is_admin). Everything here is already behind an is_admin gate in the
 * controller; the model does not re-check auth.
 */
class AdminModel extends Model {

    public function __construct(){ parent::__construct(); }

    private function creator_role_id(){
        $rr = parent::select("SELECT id FROM user_roles WHERE role_name = 'Creator'");
        return (is_array($rr) && count($rr)) ? (int) $rr[0]['id'] : 0;
    }
    private function scalar($sql, $params = array(), $col = 'n'){
        $r = parent::select($sql, $params);
        return (is_array($r) && count($r)) ? $r[0][$col] : null;
    }

    /** Platform KPIs for the overview strip. */
    public function overview(){
        $crole = $this->creator_role_id();
        $subs  = parent::select("SELECT COUNT(*) AS n, COALESCE(SUM(" . CreatorSubscriptionsModel::MONTHLY_CENTS_SQL . "),0) AS mrr FROM creator_subscriptions WHERE status = 'active'");
        $subs  = (is_array($subs) && count($subs)) ? $subs[0] : array('n' => 0, 'mrr' => 0);
        $mod   = parent::select(
            "SELECT COALESCE(SUM(moderation_status='pending'),0) AS pend,
                    COALESCE(SUM(moderation_status='flagged'),0) AS flag,
                    COALESCE(SUM(moderation_status='blocked'),0) AS blocked
             FROM media_assets WHERE deleted_at IS NULL AND type IN ('image', 'video')");
        $mod   = (is_array($mod) && count($mod)) ? $mod[0] : array('pend' => 0, 'flag' => 0, 'blocked' => 0);

        // Money. Credits are $0.10 each, so gross cents = price_credits * 10. Every sale row stores the creator's cut
        // (net_credits, at their plan's rate), so the platform take is exact; rows from before that column existed
        // (net 0 on a paid sale) fall back to the default fee.
        $fee   = Main::platform_fee_percent();
        $gross = 0; $platform_cents = 0;
        foreach (array('ppv_unlocks', 'bundle_unlocks', 'message_unlocks', 'event_registrations', 'service_purchases') as $tbl) {
            $r = parent::select("SELECT COALESCE(SUM(price_credits),0) AS g,
                    COALESCE(SUM(price_credits - IF(net_credits > 0, net_credits, ROUND(price_credits * (100 - :f) / 100))),0) AS p
                 FROM $tbl WHERE price_credits > 0", array('f' => $fee));
            $gross += (int) ($r[0]['g'] ?? 0) * 10;
            $platform_cents += max(0, (int) ($r[0]['p'] ?? 0)) * 10;
        }
        $mrr = (int) $subs['mrr'];
        // Membership fees go to the creator minus their plan's application fee.
        $sub_fee = 0;
        $users = new UsersModel();
        foreach ((array) parent::select("SELECT creator_id, COALESCE(SUM(" . CreatorSubscriptionsModel::MONTHLY_CENTS_SQL . "),0) AS c FROM creator_subscriptions WHERE status = 'active' GROUP BY creator_id") as $r) {
            $u = $users->get_user_by_id((int) $r['creator_id']);
            $sub_fee += (int) round((int) $r['c'] * ((is_array($u) && count($u) === 1) ? Plan::fee_percent($u[0]) : $fee) / 100);
        }

        return array(
            'users'          => (int) $this->scalar("SELECT COUNT(*) AS n FROM user_accounts WHERE deleted = 0"),
            'creators'       => (int) $this->scalar("SELECT COUNT(*) AS n FROM user_accounts WHERE deleted = 0 AND role_id = :r", array('r' => $crole)),
            'active_subs'    => (int) $subs['n'],
            'mrr_cents'      => $mrr,
            'platform_cents' => $platform_cents,                          // all-time platform take on one-time sales
            'gross_cents'    => $gross,                                   // gross one-time sales (PPV, bundles, messages, events, services)
            'sub_fee_cents'  => $sub_fee,                                 // platform's recurring cut of subscriptions (monthly, per creator's plan)
            'mod_pending'    => (int) $mod['pend'],
            'mod_flagged'    => (int) $mod['flag'],
            'mod_blocked'    => (int) $mod['blocked'],
        );
    }

    /**
     * Platform financials from the source ledgers (not per-post totals, which go stale when posts are deleted).
     * Wallet credits and AI credits are both $0.10 each (10 cents). $plan_prices: tier key => monthly price in cents.
     * Returns cents throughout.
     */
    public function financials(array $plan_prices){
        $kinds = array(
            'ppv'     => array('Pay-per-view', 'ppv_unlock',       'ppv_earning'),
            'bundle'  => array('Bundles',      'bundle_unlock',    'bundle_earning'),
            'message' => array('Paid messages','message_unlock',   'message_earning'),
            'service' => array('Services',     'service_purchase', 'service_earning'),
            'event'   => array('Events',       'event_ticket',     'event_earning'),
            'tip'     => array('Tips',         'live_tip',         'tip_earning'),
            'replay'  => array('Replays',      'replay_unlock',    'replay_earning'),
        );
        $ledger = array();
        foreach ((array) parent::select("SELECT type, COUNT(*) AS n, COALESCE(SUM(credits),0) AS cr FROM credit_transactions GROUP BY type") as $r) {
            $ledger[$r['type']] = array('n' => (int) $r['n'], 'cr' => (int) $r['cr']);
        }
        $refunds = array();
        foreach ((array) parent::select("SELECT kind, COUNT(*) AS n, COALESCE(SUM(amount_credits),0) AS amt, COALESCE(SUM(clawback_credits),0) AS claw FROM refunds GROUP BY kind") as $r) {
            $refunds[$r['kind']] = array('n' => (int) $r['n'], 'amt' => (int) $r['amt'], 'claw' => (int) $r['claw']);
        }
        $rows = array(); $t = array('sales' => 0, 'gross' => 0, 'refunded' => 0, 'creator' => 0, 'platform' => 0);
        foreach ($kinds as $k => $d) {
            $sales    = $ledger[$d[1]]['n'] ?? 0;
            $gross    = abs($ledger[$d[1]]['cr'] ?? 0) * 10;
            $refunded = ($refunds[$k]['amt'] ?? 0) * 10;
            $creator  = (($ledger[$d[2]]['cr'] ?? 0) - ($refunds[$k]['claw'] ?? 0)) * 10;
            $net      = $gross - $refunded;
            $row = array('label' => $d[0], 'sales' => $sales, 'gross' => $gross, 'refunded' => $refunded, 'net' => $net, 'creator' => max(0, $creator), 'platform' => self::platform_fee($gross, $creator, $refunded));
            $rows[$k] = $row;
            $t['sales'] += $sales; $t['gross'] += $gross; $t['refunded'] += $refunded; $t['creator'] += $row['creator']; $t['platform'] += $row['platform'];
        }
        $t['net'] = $t['gross'] - $t['refunded'];

        // Creator plans (the platform's own subscription revenue), priced from the live Stripe plans.
        $plans = array(); $plan_mrr = 0; $plan_n = 0;
        foreach ((array) parent::select("SELECT plan_tier, COUNT(*) AS n FROM user_accounts WHERE deleted = 0 AND plan_tier IS NOT NULL AND plan_tier <> '' AND subscription_status IN ('active','trialing') GROUP BY plan_tier") as $r) {
            $price = (int) ($plan_prices[$r['plan_tier']] ?? 0);
            $plans[$r['plan_tier']] = array('n' => (int) $r['n'], 'mrr' => $price * (int) $r['n']);
            $plan_mrr += $price * (int) $r['n']; $plan_n += (int) $r['n'];
        }

        $ai_n  = (int) $this->scalar("SELECT COUNT(*) AS n FROM ai_credit_transactions WHERE type = 'purchase'");
        $ai_cents = (int) $this->scalar("SELECT COALESCE(SUM(credits),0) * (100 / " . (int) PlanTiers::AI_CREDITS_PER_DOLLAR . ") AS n FROM ai_credit_transactions WHERE type = 'purchase'");
        $held  = (int) $this->scalar("SELECT COALESCE(SUM(credit_balance),0)*10 AS n FROM user_accounts WHERE deleted = 0");
        $held_creators = (int) $this->scalar("SELECT COALESCE(SUM(credit_balance),0)*10 AS n FROM user_accounts WHERE deleted = 0 AND role_id = :r", array('r' => $this->creator_role_id()));
        // Owed to creators: earnings not yet paid out (sales, less clawbacks, payouts and returned payouts), capped at
        // each wallet's balance and never below zero. Includes earnings still in the hold; excludes credits they bought.
        $owed = (int) $this->scalar(
            "SELECT COALESCE(SUM(GREATEST(0, LEAST(e.net, u.credit_balance))),0)*10 AS n
             FROM (SELECT user_id, SUM(credits) AS net FROM credit_transactions
                   WHERE type LIKE '%\\_earning' OR type IN ('refund_reversal', 'payout', 'payout_refund') GROUP BY user_id) e
             JOIN user_accounts u ON u.user_id = e.user_id AND u.deleted = 0");
        $cb    = parent::select("SELECT COUNT(*) AS n, COALESCE(SUM(amount_cents),0) AS amt FROM chargebacks");

        return array(
            'sales'          => $rows,
            'sales_total'    => $t,
            'credits_sold'   => (int) $this->scalar("SELECT COALESCE(SUM(COALESCE(paid_cents, credits * 10)),0) AS n FROM credit_transactions WHERE type = 'purchase'"),   // what cards paid, incl. processing fee
            'credit_orders'  => $ledger['purchase']['n'] ?? 0,
            'ai_sold'        => $ai_cents,
            'ai_orders'      => $ai_n,
            'payouts'        => max(0, abs($ledger['payout']['cr'] ?? 0) - ($ledger['payout_refund']['cr'] ?? 0)) * 10,   // failed payouts came back
            'payout_count'   => max(0, ($ledger['payout']['n'] ?? 0) - ($ledger['payout_refund']['n'] ?? 0)),
            'credits_held'   => $held,
            'held_creators'  => $held_creators,
            'owed_creators'  => $owed,
            'held_fans'      => max(0, $held - $held_creators),
            'plan_mrr'       => $plan_mrr,
            'plan_count'     => $plan_n,
            'plans'          => $plans,
            'refund_count'   => array_sum(array_column($refunds, 'n')),
            'refund_cents'   => $t['refunded'],
            'chargeback_n'   => (int) ($cb[0]['n'] ?? 0),
            'chargeback_cents' => (int) ($cb[0]['amt'] ?? 0),
        );
    }



    /**
     * The last $days UTC days (oldest first) for the KPI sparklines: signups, plan payments (cents, from $plan_days
     * 'Y-m-d' => cents), our fee on sales, credits bought, refunds, payouts, net creator earnings (earnings minus
     * clawbacks and payouts), and the running account total.
     */
    public function daily_series(int $days = 30, array $plan_days = array()): array {
        $days = max(2, min(366, $days));
        $since = gmdate('Y-m-d 00:00:00', time() - ($days - 1) * 86400);
        $keys = array();
        for ($i = $days - 1; $i >= 0; $i--) { $keys[gmdate('Y-m-d', time() - $i * 86400)] = array('signups' => 0, 'plans' => 0, 'fee' => 0, 'credits' => 0, 'refunds' => 0, 'payouts' => 0, 'earned' => 0, 'gross' => array(), 'creator' => array(), 'refunded' => array()); }
        foreach ($plan_days as $d => $c) { if (isset($keys[$d])) { $keys[$d]['plans'] += (int) $c; } }
        foreach ((array) parent::select("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM user_accounts WHERE deleted = 0 AND created_at >= :s GROUP BY d", array('s' => $since)) as $r) { if (isset($keys[$r['d']])) { $keys[$r['d']]['signups'] = (int) $r['n']; } }
        $types = array('ppv' => array('ppv_unlock', 'ppv_earning'), 'bundle' => array('bundle_unlock', 'bundle_earning'), 'message' => array('message_unlock', 'message_earning'), 'service' => array('service_purchase', 'service_earning'), 'event' => array('event_ticket', 'event_earning'), 'tip' => array('live_tip', 'tip_earning'), 'replay' => array('replay_unlock', 'replay_earning'));
        foreach ((array) parent::select("SELECT DATE(created_at) AS d, type, SUM(credits) AS cr, SUM(COALESCE(paid_cents, credits * 10)) AS paid FROM credit_transactions WHERE created_at >= :s GROUP BY d, type", array('s' => $since)) as $r) {
            if (!isset($keys[$r['d']])) { continue; }
            $cr = (int) $r['cr']; $m = &$keys[$r['d']];
            if ($r['type'] === 'purchase') { $m['credits'] += (int) $r['paid']; }
            elseif ($r['type'] === 'payout') { $m['payouts'] += -$cr * 10; $m['earned'] += $cr * 10; }
            elseif ($r['type'] === 'payout_refund') { $m['payouts'] -= $cr * 10; $m['earned'] += $cr * 10; }
            elseif ($r['type'] === 'refund_reversal') { $m['earned'] += $cr * 10; }
            foreach ($types as $tk => $td) {
                if ($r['type'] === $td[0]) { $m['gross'][$tk] = ($m['gross'][$tk] ?? 0) - $cr * 10; }
                if ($r['type'] === $td[1]) { $m['creator'][$tk] = ($m['creator'][$tk] ?? 0) + $cr * 10; $m['earned'] += $cr * 10; }
            }
            unset($m);
        }
        foreach ((array) parent::select("SELECT DATE(created_at) AS d, kind, SUM(amount_credits) AS amt, SUM(clawback_credits) AS claw FROM refunds WHERE created_at >= :s GROUP BY d, kind", array('s' => $since)) as $r) {
            if (!isset($keys[$r['d']])) { continue; }
            $keys[$r['d']]['refunds'] += (int) $r['amt'] * 10;
            $keys[$r['d']]['refunded'][$r['kind']] = ($keys[$r['d']]['refunded'][$r['kind']] ?? 0) + (int) $r['amt'] * 10;
            $keys[$r['d']]['creator'][$r['kind']] = ($keys[$r['d']]['creator'][$r['kind']] ?? 0) - (int) $r['claw'] * 10;
        }
        $accounts = (int) $this->scalar("SELECT COUNT(*) AS n FROM user_accounts WHERE deleted = 0");
        $signups_in_window = 0; foreach ($keys as $v) { $signups_in_window += $v['signups']; }
        $running = $accounts - $signups_in_window;
        $out = array();
        foreach ($keys as $d => $v) {
            $fee = 0;
            foreach (array_unique(array_merge(array_keys($v['gross']), array_keys($v['creator']), array_keys($v['refunded']))) as $tk) { $fee += self::platform_fee($v['gross'][$tk] ?? 0, $v['creator'][$tk] ?? 0, $v['refunded'][$tk] ?? 0); }
            $running += $v['signups'];
            $out[] = array('d' => $d, 'signups' => $v['signups'], 'plans' => $v['plans'], 'fee' => $fee, 'revenue' => $v['plans'] + $fee, 'credits' => $v['credits'], 'refunds' => $v['refunds'], 'payouts' => $v['payouts'], 'earned' => $v['earned'], 'accounts' => $running);
        }
        return $out;
    }

    /**
     * Our fee on a sale type: gross minus the creator's share minus refunds, never below zero. A refund after the
     * creator's share was released (event tickets refunded after the event) takes the fee to zero, not negative.
     */
    public static function platform_fee($gross_cents, $creator_cents, $refunded_cents): int {
        return max(0, (int) $gross_cents - max(0, (int) $creator_cents) - (int) $refunded_cents);
    }

    /**
     * Money per calendar month for the last 24 months, from the ledgers (cents). Each month has platform totals
     * (plans, fee, revenue, credits, ai, cash_in, refunds, payouts, sales) and a per-type sales breakdown
     * (gross, refunded, creator, platform, sales). A refund lands in the month it happened. $plan_buckets:
     * 'Y-m' => paid creator-plan invoice cents (from Stripe). $member_buckets: 'Y-m' => our fee on fan memberships (cents).
     */
    public function money_series(array $plan_buckets = array(), array $member_buckets = array()){
        $types = array(
            'ppv'     => array('ppv_unlock', 'ppv_earning'),
            'bundle'  => array('bundle_unlock', 'bundle_earning'),
            'message' => array('message_unlock', 'message_earning'),
            'service' => array('service_purchase', 'service_earning'),
            'event'   => array('event_ticket', 'event_earning'),
            'tip'     => array('live_tip', 'tip_earning'),
            'replay'  => array('replay_unlock', 'replay_earning'),
        );
        $since = gmdate('Y-m-01 00:00:00', strtotime('first day of -23 months'));
        $keys = array();
        for ($i = 23; $i >= 0; $i--) {
            $k = gmdate('Y-m', strtotime("first day of -$i months"));
            $t = array(); foreach ($types as $tk => $td) { $t[$tk] = array('sales' => 0, 'gross' => 0, 'refunded' => 0, 'creator' => 0); }
            $keys[$k] = array('plans' => (int) ($plan_buckets[$k] ?? 0), 'members' => (int) ($member_buckets[$k] ?? 0), 'credits' => 0, 'ai' => 0, 'refunds' => 0, 'payouts' => 0, 'types' => $t);
        }
        $rows = parent::select("SELECT DATE_FORMAT(created_at, '%Y-%m') AS b, type, COUNT(*) AS n, SUM(credits) AS cr, SUM(COALESCE(paid_cents, credits * 10)) AS paid FROM credit_transactions WHERE created_at >= :s GROUP BY b, type", array('s' => $since));
        foreach ((array) $rows as $r) {
            if (!isset($keys[$r['b']])) { continue; }
            $cr = (int) $r['cr']; $n = (int) $r['n']; $m = &$keys[$r['b']];
            if ($r['type'] === 'purchase') { $m['credits'] += (int) $r['paid']; }   // what cards paid, incl. processing fee
            elseif ($r['type'] === 'payout') { $m['payouts'] += -$cr * 10; }
            elseif ($r['type'] === 'payout_refund') { $m['payouts'] -= $cr * 10; }   // a failed payout returned to the creator
            foreach ($types as $tk => $td) {
                if ($r['type'] === $td[0]) { $m['types'][$tk]['sales'] += $n; $m['types'][$tk]['gross'] += -$cr * 10; }
                if ($r['type'] === $td[1]) { $m['types'][$tk]['creator'] += $cr * 10; }
            }
            unset($m);
        }
        foreach ((array) parent::select("SELECT DATE_FORMAT(created_at, '%Y-%m') AS b, kind, SUM(amount_credits) AS amt, SUM(clawback_credits) AS claw FROM refunds WHERE created_at >= :s GROUP BY b, kind", array('s' => $since)) as $r) {
            if (!isset($keys[$r['b']]) || !isset($keys[$r['b']]['types'][$r['kind']])) { continue; }
            $keys[$r['b']]['types'][$r['kind']]['refunded'] += (int) $r['amt'] * 10;
            $keys[$r['b']]['types'][$r['kind']]['creator']  -= (int) $r['claw'] * 10;
            $keys[$r['b']]['refunds'] += (int) $r['amt'] * 10;
        }
        foreach ((array) parent::select("SELECT DATE_FORMAT(created_at, '%Y-%m') AS b, SUM(credits) AS c FROM ai_credit_transactions WHERE type = 'purchase' AND created_at >= :s GROUP BY b", array('s' => $since)) as $r) {
            if (isset($keys[$r['b']])) { $keys[$r['b']]['ai'] += (int) $r['c'] * (int) (100 / PlanTiers::AI_CREDITS_PER_DOLLAR); }
        }
        $out = array();
        foreach ($keys as $k => $v) {
            $fee = 0; $sales = 0;
            foreach ($v['types'] as $tk => $t) { $v['types'][$tk]['platform'] = self::platform_fee($t['gross'], $t['creator'], $t['refunded']); $fee += $v['types'][$tk]['platform']; $sales += $t['sales']; }
            $out[] = array('k' => $k, 'label' => gmdate('M', strtotime($k . '-01')), 'plans' => $v['plans'], 'fee' => $fee, 'members' => $v['members'], 'revenue' => $v['plans'] + $fee + $v['members'],
                           'credits' => $v['credits'], 'ai' => $v['ai'], 'cash_in' => $v['credits'] + $v['ai'], 'refunds' => $v['refunds'], 'payouts' => $v['payouts'],
                           'sales' => $sales, 'types' => $v['types']);
        }
        return $out;
    }

    /** Creators with a Stripe customer id (for pulling their paid plan invoices). */
    public function plan_customers(){
        return (array) parent::select("SELECT user_id, stripe_customer_id FROM user_accounts WHERE deleted = 0 AND stripe_customer_id IS NOT NULL AND stripe_customer_id <> ''");
    }

    /** Everything the admin user page shows about one account (any status, including deleted). */
    public function user_detail($user_id){
        $rows = parent::select(
            "SELECT u.*, r.role_name, cp.avatar_url, cp.display_name,
                    (SELECT COUNT(*) FROM mfa_backup_codes b WHERE b.user_id = u.user_id AND b.used_at IS NULL) AS backup_codes_left,
                    (SELECT COUNT(*) FROM follows f WHERE f.follower_id = u.user_id) AS following_n,
                    (SELECT COUNT(*) FROM follows f WHERE f.creator_id = u.user_id) AS followers_n
             FROM user_accounts u
             LEFT JOIN user_roles r ON r.id = u.role_id
             LEFT JOIN creator_profiles cp ON cp.user_id = u.user_id
             WHERE u.user_id = :u", array('u' => (int) $user_id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** One-time purchases a fan made (refundable kinds), newest first, with the creator's handle. */
    public function purchases_for($fan_id, $limit = 100){
        $limit = max(1, min(300, (int) $limit));
        return (array) parent::select(
            "SELECT s.*, u.u_name AS creator_handle FROM (
                SELECT 'ppv' AS kind, pu.post_id AS ref_id, pu.creator_id, pu.price_credits, pu.created_at, p.caption COLLATE utf8mb4_unicode_ci AS item
                FROM ppv_unlocks pu LEFT JOIN posts p ON p.id = pu.post_id WHERE pu.fan_id = :f1
                UNION ALL
                SELECT 'bundle', bu.bundle_id, bu.creator_id, bu.price_credits, bu.created_at, b.name COLLATE utf8mb4_unicode_ci
                FROM bundle_unlocks bu LEFT JOIN content_bundles b ON b.id = bu.bundle_id WHERE bu.fan_id = :f2
                UNION ALL
                SELECT 'message', mu.message_id, mu.creator_id, mu.price_credits, mu.created_at, m.body COLLATE utf8mb4_unicode_ci
                FROM message_unlocks mu LEFT JOIN messages m ON m.id = mu.message_id WHERE mu.fan_id = :f3
             ) s LEFT JOIN user_accounts u ON u.user_id = s.creator_id
             ORDER BY s.created_at DESC LIMIT $limit",
            array('f1' => (int) $fan_id, 'f2' => (int) $fan_id, 'f3' => (int) $fan_id));
    }

    /** Refunds already issued to a fan. */
    public function refunds_for($fan_id){
        return (array) parent::select("SELECT kind, ref_id, amount_credits, reason, created_at FROM refunds WHERE fan_id = :f ORDER BY created_at DESC", array('f' => (int) $fan_id));
    }

    /** Recent sign-in, reset and MFA attempts for an account (matched on username and email). */
    public function sign_in_history($u_name, $email, $limit = 20){
        $limit = max(1, min(100, (int) $limit));
        return (array) parent::select(
            "SELECT ip_address, identifier, action, created_at FROM login_attempts
             WHERE action IN ('login','forgot','mfa') AND (identifier = :a OR identifier = :b)
             ORDER BY created_at DESC LIMIT $limit", array('a' => (string) $u_name, 'b' => (string) $email));
    }

    /** One fan membership by id, with the creator's payout account (to cancel it). */
    public function membership($id){
        $rows = parent::select(
            "SELECT cs.*, u.stripe_connect_account_id AS creator_connect FROM creator_subscriptions cs
             JOIN user_accounts u ON u.user_id = cs.creator_id WHERE cs.id = :id", array('id' => (int) $id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** Social accounts a user has connected (active ones). */
    public function social_accounts($user_id){
        return (array) parent::select("SELECT platform, username, status FROM user_social_accounts WHERE user_id = :u AND (disconnected_at IS NULL) ORDER BY platform", array('u' => (int) $user_id));
    }

    /** Clear two-step sign-in completely: authenticator app, email codes and backup codes. */
    public function reset_mfa($user_id){
        parent::update('user_accounts', array('mfa_totp_enabled' => 0, 'mfa_totp_secret' => null, 'mfa_email_enabled' => 0, 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :u', array('u' => (int) $user_id));
        parent::delete_all('mfa_backup_codes', 'user_id = :u', array('u' => (int) $user_id));
    }

    /** Images awaiting a decision (flagged first, then unscanned), with creator + AI signal. */
    /** Media waiting for a human look (pending scan or flagged), for the admin rail badge; cheaper than overview(). */
    public function moderation_count(){
        return (int) $this->scalar("SELECT COUNT(*) AS n FROM media_assets WHERE deleted_at IS NULL AND type IN ('image', 'video') AND moderation_status IN ('pending', 'flagged')");
    }

    public function moderation_queue($limit = 40){
        $limit = max(1, min(100, (int) $limit));
        return (array) parent::select(
            "SELECT ma.*, u.u_name AS creator_handle,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '')) AS creator_name
             FROM media_assets ma
             JOIN user_accounts u ON u.user_id = ma.creator_id
             LEFT JOIN creator_profiles cp ON cp.user_id = ma.creator_id
             WHERE ma.deleted_at IS NULL AND ma.type IN ('image', 'video')
               AND ma.moderation_status IN ('pending', 'flagged')
             ORDER BY (ma.moderation_status = 'flagged') DESC, ma.created_at DESC
             LIMIT $limit"
        );
    }

    /**
     * Recent one-off sales of every kind (pay-per-view, bundles, paid messages, services, event tickets, tips, replays),
     * newest first. Refunded pay-per-view, bundle and message sales drop off automatically (the unlock row is removed);
     * those three kinds are the ones staff can refund (RefundsModel).
     */
    const REFUNDABLE_KINDS = array('ppv', 'bundle', 'message');
    const SALE_KINDS = array('ppv' => 'Pay-per-view', 'bundle' => 'Bundle', 'message' => 'Paid message', 'service' => 'Service', 'event' => 'Event', 'tip' => 'Tip', 'replay' => 'Replay');
    public function recent_sales($limit = 25){
        $limit = max(1, min(500, (int) $limit));
        $rows = parent::select(
            "SELECT s.* FROM (
                SELECT 'ppv' AS kind, pu.post_id AS ref_id, pu.fan_id, pu.creator_id, pu.price_credits, pu.created_at, p.caption COLLATE utf8mb4_unicode_ci AS item
                FROM ppv_unlocks pu JOIN posts p ON p.id = pu.post_id
                UNION ALL
                SELECT 'bundle' AS kind, bu.bundle_id AS ref_id, bu.fan_id, bu.creator_id, bu.price_credits, bu.created_at, b.name COLLATE utf8mb4_unicode_ci AS item
                FROM bundle_unlocks bu JOIN content_bundles b ON b.id = bu.bundle_id
                UNION ALL
                SELECT 'message' AS kind, mu.message_id AS ref_id, mu.fan_id, mu.creator_id, mu.price_credits, mu.created_at, m.body COLLATE utf8mb4_unicode_ci AS item
                FROM message_unlocks mu JOIN messages m ON m.id = mu.message_id
                UNION ALL
                SELECT 'service' AS kind, sp.service_id AS ref_id, sp.buyer_id AS fan_id, sv.creator_id, sp.price_credits, sp.created_at, sv.name COLLATE utf8mb4_unicode_ci AS item
                FROM service_purchases sp JOIN services sv ON sv.id = sp.service_id WHERE sp.price_credits > 0
                UNION ALL
                SELECT 'event' AS kind, er.event_id AS ref_id, er.user_id AS fan_id, ev.creator_id, er.price_credits, er.created_at, ev.title COLLATE utf8mb4_unicode_ci AS item
                FROM event_registrations er JOIN events ev ON ev.id = er.event_id WHERE er.price_credits > 0
                UNION ALL
                SELECT 'tip' AS kind, lt.id AS ref_id, lt.fan_id, lt.creator_id, lt.credits AS price_credits, lt.created_at, CONCAT('Tip in ', lt.room) COLLATE utf8mb4_unicode_ci AS item
                FROM live_tips lt
                UNION ALL
                SELECT 'replay' AS kind, ru.event_id AS ref_id, ru.fan_id, ru.creator_id, ru.price_credits, ru.created_at, CONCAT('Replay: ', ev.title) COLLATE utf8mb4_unicode_ci AS item
                FROM replay_unlocks ru JOIN events ev ON ev.id = ru.event_id
             ) s ORDER BY s.created_at DESC LIMIT $limit");
        if (empty($rows)) { return array(); }
        $ids = array();
        foreach ($rows as $r) { $ids[] = (int) $r['fan_id']; $ids[] = (int) $r['creator_id']; }
        $idmap = (new MessagesModel())->identity_map($ids);
        $out = array();
        foreach ($rows as $r) {
            $fan = $idmap[(int) $r['fan_id']] ?? array('handle' => '', 'name' => 'Unknown');
            $cre = $idmap[(int) $r['creator_id']] ?? array('handle' => '', 'name' => 'Unknown');
            $out[] = array(
                'kind'           => (string) $r['kind'],
                'refundable'     => in_array((string) $r['kind'], self::REFUNDABLE_KINDS, true),
                'ref_id'         => (int) $r['ref_id'],
                'fan_id'         => (int) $r['fan_id'],
                'fan_handle'     => (string) $fan['handle'],
                'fan_name'       => (string) $fan['name'],
                'fan_avatar'     => (string) ($fan['avatar'] ?? ''),
                'creator_handle' => (string) $cre['handle'],
                'item'           => html_entity_decode((string) ($r['item'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'credits'        => (int) $r['price_credits'],
                'created_at'     => (string) $r['created_at'],
            );
        }
        return $out;
    }

    public function get_asset($asset_id){
        $r = parent::select("SELECT * FROM media_assets WHERE id = :id", array('id' => (int) $asset_id));
        return (is_array($r) && count($r)) ? $r[0] : null;
    }

    /** Users list with role + status, searchable and status-filterable. */
    public function users($q = '', $status = '', $limit = 60){
        $limit  = max(1, min(200, (int) $limit));
        $where  = array('u.deleted = 0');
        $params = array();
        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\%', '\_'), $q) . '%';
            $where[] = '(u.u_name LIKE :q1 OR u.user_email LIKE :q2 OR TRIM(CONCAT(u.first_name, " ", u.last_name)) LIKE :q3)';
            $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like;
        }
        if ($status === 'Active' || $status === 'Disabled') {
            $where[] = 'u.user_status = :st'; $params['st'] = $status;
        }
        return (array) parent::select(
            "SELECT u.user_id, u.u_name, u.user_email, u.first_name, u.last_name, u.role_id, u.is_admin,
                    u.user_status, u.created_at, u.last_active_at, r.role_name, cp.avatar_url
             FROM user_accounts u LEFT JOIN user_roles r ON r.id = u.role_id LEFT JOIN creator_profiles cp ON cp.user_id = u.user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY u.created_at DESC
             LIMIT $limit",
            $params
        );
    }

    /**
     * Growth tab: signups over the last $days UTC days (today included), demo accounts left out.
     * "paid" = billing account active or past due on a selling plan (PagesController::selling_tiers()).
     */
    private function growth_parts($days){
        $days   = max(1, min(366, (int) $days));
        $keys   = array(); $params = array();
        foreach (PagesController::selling_tiers() as $i => $t) { $keys[] = ':pk' . $i; $params['pk' . $i] = (string) $t['key']; }
        $paid   = count($keys) ? "(b.status IN ('active', 'past_due') AND b.plan_key IN (" . implode(', ', $keys) . "))" : '0';
        $where  = "u.is_demo = 0 AND u.deleted = 0 AND u.created_at >= UTC_DATE() - INTERVAL " . ($days - 1) . " DAY";
        $sums   = "COUNT(*) AS signups,
                   COALESCE(SUM(u.email_verified = 1), 0) AS verified,
                   COALESCE(SUM(u.role_id = " . (int) $this->creator_role_id() . "), 0) AS became_creator,
                   COALESCE(SUM($paid), 0) AS paid,
                   COALESCE(SUM(u.referred_by_creator_id IS NOT NULL), 0) AS referred";
        return array('where' => $where, 'sums' => $sums, 'paid' => $paid, 'params' => $params);
    }

    /** Per-day funnel: signups, verified, became creator, paid, referred. Newest day first. */
    public function funnel($days){
        $g = $this->growth_parts($days);
        return (array) parent::select(
            "SELECT DATE(u.created_at) AS day, {$g['sums']}
             FROM user_accounts u LEFT JOIN billing_accounts b ON b.user_id = u.user_id
             WHERE {$g['where']}
             GROUP BY DATE(u.created_at) ORDER BY day DESC", $g['params']);
    }

    /** Same counts grouped by first-touch source + medium (no source = direct). */
    public function funnel_by_source($days){
        $g = $this->growth_parts($days);
        return (array) parent::select(
            "SELECT COALESCE(NULLIF(u.acq_source, ''), 'direct') AS source, COALESCE(u.acq_medium, '') AS medium, {$g['sums']}
             FROM user_accounts u LEFT JOIN billing_accounts b ON b.user_id = u.user_id
             WHERE {$g['where']}
             GROUP BY COALESCE(NULLIF(u.acq_source, ''), 'direct'), COALESCE(u.acq_medium, '')
             ORDER BY signups DESC, source ASC", $g['params']);
    }

    /**
     * Build before you pay: creators who signed up in the period, by UTC day. The first paid moment is the first succeeded
     * subscribe/upgrade charge for a selling tier, else the billing row's date when it is on a selling tier (migrated plans),
     * else never. "built" = a post, plan, bundle, service, event, media file or filled profile created before that moment;
     * "upgraded" = a paid moment after they became a creator.
     */
    public function free_builders($days){
        $g    = $this->growth_parts($days);
        // tier keys come from code (PagesController::selling_tiers) and are used twice, so they go in as quoted literals
        $keys = array_map(function ($t) { return "'" . preg_replace('/[^a-z0-9_]/', '', (string) $t['key']) . "'"; }, PagesController::selling_tiers());
        $sell = $keys ? implode(', ', $keys) : "''";
        $paid_at = "COALESCE((SELECT MIN(ch.created_at) FROM billing_charges ch WHERE ch.user_id = u.user_id AND ch.status = 'succeeded'
                                AND ch.kind IN ('subscribe', 'upgrade') AND JSON_UNQUOTE(JSON_EXTRACT(ch.effects, '$.plan')) IN ($sell)),
                             CASE WHEN b.plan_key IN ($sell) THEN b.created_at END)";
        $before = "< COALESCE(c0.paid_at, '9999-12-31')";
        $built  = "(EXISTS (SELECT 1 FROM posts x WHERE x.creator_id = c0.user_id AND x.created_at $before)
                 OR EXISTS (SELECT 1 FROM media_assets x WHERE x.creator_id = c0.user_id AND x.created_at $before)
                 OR EXISTS (SELECT 1 FROM creator_plans x WHERE x.user_id = c0.user_id AND x.created_at $before)
                 OR EXISTS (SELECT 1 FROM content_bundles x WHERE x.creator_id = c0.user_id AND x.created_at $before)
                 OR EXISTS (SELECT 1 FROM services x WHERE x.creator_id = c0.user_id AND x.created_at $before)
                 OR EXISTS (SELECT 1 FROM events x WHERE x.creator_id = c0.user_id AND x.created_at $before)
                 OR EXISTS (SELECT 1 FROM creator_profiles x WHERE x.user_id = c0.user_id AND x.created_at $before
                            AND (COALESCE(x.display_name, '') <> '' OR COALESCE(x.bio, '') <> '' OR COALESCE(x.avatar_url, '') <> '')))";
        $upgraded = "(c0.paid_at IS NOT NULL AND c0.paid_at >= COALESCE(c0.creator_since, c0.created_at))";
        return (array) parent::select(
            "SELECT day, COUNT(*) AS creators, COALESCE(SUM(built), 0) AS built, COALESCE(SUM(built AND upgraded), 0) AS upgraded,
                    COALESCE(SUM(built AND NOT upgraded), 0) AS still_free, COALESCE(SUM(upgraded AND NOT built), 0) AS paid_direct
             FROM (SELECT c0.day, $built AS built, $upgraded AS upgraded
                   FROM (SELECT u.user_id, u.created_at, u.creator_since, DATE(u.created_at) AS day, $paid_at AS paid_at
                         FROM user_accounts u LEFT JOIN billing_accounts b ON b.user_id = u.user_id
                         WHERE {$g['where']} AND u.role_id = " . (int) $this->creator_role_id() . ") c0) c
             GROUP BY day ORDER BY day DESC");
    }

    /** Accounts that signed up from a creator's referral, newest first. */
    public function referred_signups($days){
        $g = $this->growth_parts($days);
        return (array) parent::select(
            "SELECT u.user_id, u.u_name, u.user_email, u.created_at, u.email_verified, r.role_name, {$g['paid']} AS paid,
                    ref.user_id AS referrer_id, ref.u_name AS referrer_u_name
             FROM user_accounts u LEFT JOIN billing_accounts b ON b.user_id = u.user_id
                  LEFT JOIN user_roles r ON r.id = u.role_id
                  LEFT JOIN user_accounts ref ON ref.user_id = u.referred_by_creator_id
             WHERE {$g['where']} AND u.referred_by_creator_id IS NOT NULL
             ORDER BY u.created_at DESC LIMIT 200", $g['params']);
    }

    /** Suspend / reactivate an account. */
    public function set_user_status($user_id, $status){
        if (!in_array($status, array('Active', 'Disabled'), true)) { return false; }
        $ok = parent::update('user_accounts',
            array('user_status' => $status),
            'user_id = :id', array('id' => (int) $user_id));
        if ($ok) { $status === 'Disabled' ? AccountBilling::on_suspend($user_id) : AccountBilling::on_reactivate($user_id); }   // memberships stop billing while suspended
        if ($ok && $status === 'Disabled') { PublicThumbService::queue_purge(array('creator' => (int) $user_id)); }   // a suspended creator's public copies come down
        return $ok;
    }

    /** Flag / unflag a demo account (kept out of the directory, sitemap, feed and search; profile noindex). */
    public function set_demo($user_id, $is_demo){
        $res = parent::update('user_accounts',
            array('is_demo' => $is_demo ? 1 : 0),
            'user_id = :id', array('id' => (int) $user_id));
        if ($is_demo) { PublicThumbService::queue_purge(array('creator' => (int) $user_id)); }   // demo accounts have no public copies
        return $res;
    }

    /** Set an asset's moderation decision. */
    public function set_moderation($asset_id, $status){
        if (!in_array($status, array('approved', 'blocked', 'flagged', 'pending'), true)) { return false; }
        $data = array('moderation_status' => $status);
        // Approving a FLAGGED (adult) image keeps it adult: live to opted-in fans only, never SFW.
        $cur = parent::select("SELECT moderation_status FROM media_assets WHERE id = :id", array('id' => (int) $asset_id));
        if ($status === 'approved' && (string) ($cur[0]['moderation_status'] ?? '') === 'flagged') { $data['is_adult'] = 1; }
        $res = parent::update('media_assets', $data, 'id = :id', array('id' => (int) $asset_id));
        PublicThumbService::queue_purge(array('assets' => array((int) $asset_id)));   // flagged, blocked or now adult: the public copy comes down
        return $res;
    }
}
