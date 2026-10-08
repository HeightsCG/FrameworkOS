<?php
/**
 * App-managed platform billing. The app owns plans, add-ons, periods and retries
 * (billing_accounts + billing_charges); Stripe only stores the card and runs one off-session
 * PaymentIntent per charge. Prices, limits and billing settings come from PlanTiers.
 *
 * Every charge is a billing_charges row carrying its line items and the "effects" to apply
 * when it succeeds. apply() is the one place effects land, guarded by claim_apply(), so the
 * job, the page and the webhook can all report the same success without double-granting.
 *
 * user_accounts keeps a mirror (plan_tier, subscription_status, period end, cancel flag) so
 * Plan:: checks everywhere stay plain column reads.
 */
class BillingService {

    const PLAN_FREE = 'free';

    /* =====================================================================
     * Reads
     * =================================================================== */

    public static function account($user_id): array
    {
        return (new BillingAccountsModel())->get_or_create((int) $user_id);
    }

    public static function user($user_id): array
    {
        $rows = (new UsersModel())->get_user_by_id((int) $user_id);
        if (!is_array($rows) || count($rows) !== 1) { throw new RuntimeException('Account not found'); }
        return $rows[0];
    }

    public static function plan_cents($plan_key): int
    {
        return PlanTiers::price($plan_key) * 100;
    }

    public static function slot_cents(): int
    {
        $a = PlanTiers::addon('influencer_slot');
        return $a ? (int) $a['price'] * 100 : 0;
    }

    public static function money($cents): string
    {
        return ((int) $cents < 0 ? '-' : '') . '$' . number_format(abs((int) $cents) / 100, 2);
    }

    /** Does this plan take influencer slots? */
    public static function takes_slots($plan_key): bool
    {
        $a = PlanTiers::addon('influencer_slot');
        return $a && in_array((string) $plan_key, (array) $a['plans'], true);
    }

    /** Is the account on a paid plan (active, or past due inside the grace period)? */
    public static function is_paid(array $acct): bool
    {
        return (string) $acct['plan_key'] !== self::PLAN_FREE && in_array((string) $acct['status'], array('active', 'past_due'), true);
    }

    /** Share of the current period still to run (0..1), for prorating. */
    public static function remaining_fraction(array $acct): float
    {
        $s = strtotime((string) $acct['current_period_start'] . ' UTC'); $e = strtotime((string) $acct['current_period_end'] . ' UTC');
        if (!$s || !$e || $e <= $s) { return 1.0; }
        return max(0.0, min(1.0, ($e - time()) / ($e - $s)));
    }

    /**
     * The share of the plan price actually paid for the current period (1.0 = full price, 0.5 = a 50%-off promo,
     * 0 = free), from the charge that bought it: its plan line against its promo lines. Upgrades credit unused time
     * at this rate, so a discounted period is never refunded as if it were paid in full. No charge on record (e.g. an
     * account migrated from Stripe) = full price.
     */
    public static function paid_share(array $acct): float
    {
        $since = (string) ($acct['current_period_start'] ?? '');
        if ($since === '') { return 1.0; }
        $row = (new BillingChargesModel())->last_plan_charge((int) $acct['user_id'], gmdate('Y-m-d H:i:s', strtotime($since . ' UTC') - 300));
        if (!$row) { return 1.0; }
        $plan = 0; $promo = 0;
        foreach ((array) json_decode((string) $row['line_items'], true) as $l) {
            $label = (string) ($l['label'] ?? ''); $amt = (int) ($l['amount_cents'] ?? 0);
            if (strpos($label, 'Promo ') === 0) { $promo += -$amt; }
            elseif ($plan === 0 && $amt > 0 && preg_match('/ plan( \(rest of this period\))?$/', $label)) { $plan = $amt; }
        }
        return $plan > 0 ? max(0.0, min(1.0, ($plan - $promo) / $plan)) : 1.0;
    }

    /** $from plus whole billing periods, keeping the day of month (clamped like Stripe). */
    public static function add_period($from, $periods = 1): string
    {
        $d = new DateTimeImmutable((string) $from, new DateTimeZone('UTC'));
        $months = (int) PlanTiers::BILLING['interval_months'] * (int) $periods;
        $day = (int) $d->format('j');
        $first = $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1)->modify('+' . $months . ' months');
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, (int) $first->format('t')))->format('Y-m-d H:i:s');
    }

    /**
     * What the account's next charge will be, after changes scheduled for that date:
     * ['at', 'lines' => [[label, cents]], 'total', 'plan', 'slots', 'pack'] or null when nothing renews.
     */
    public static function next_charge(array $acct)
    {
        if ((string) $acct['status'] === 'free' || empty($acct['next_charge_at'])) { return null; }
        $plan  = (int) $acct['cancel_at_period_end'] ? self::PLAN_FREE : ((string) ($acct['pending_plan_key'] ?? '') !== '' ? (string) $acct['pending_plan_key'] : (string) $acct['plan_key']);
        $slots = self::takes_slots($plan) ? ($acct['influencer_slots_next'] !== null ? (int) $acct['influencer_slots_next'] : (int) $acct['influencer_slots']) : 0;
        $pack  = $acct['pack_dollars_next'] !== null ? (int) $acct['pack_dollars_next'] : (int) $acct['pack_dollars'];
        $pack_cents = ($acct['pack_dollars_next'] !== null) ? $pack * 100 : (int) ($acct['pack_price_cents'] ?? $pack * 100);
        $lines = self::lines($plan, $slots, $pack, $pack_cents);
        if (empty($lines)) { return null; }
        if ($plan !== self::PLAN_FREE && !empty($acct['promo_code'])) {
            $off = self::promo_off(self::plan_cents($plan), $acct);
            if ($off > 0) { $lines[] = array('Promo ' . $acct['promo_code'], -$off); }
        }
        return array('at' => (string) $acct['next_charge_at'], 'lines' => $lines, 'total' => array_sum(array_column($lines, 1)),
            'plan' => $plan, 'slots' => $slots, 'pack' => $pack);
    }

    /** Line items for one full period: [[label, cents], ...]. */
    public static function lines($plan, $slots, $pack_dollars, $pack_cents = null): array
    {
        $out = array();
        if ((string) $plan !== self::PLAN_FREE) {
            $t = PlanTiers::get($plan);
            if ($t) { $out[] = array($t['name'] . ' plan', self::plan_cents($plan)); }
        }
        if ((int) $slots > 0) { $out[] = array((int) $slots . ' extra AI influencer' . ((int) $slots === 1 ? '' : 's'), (int) $slots * self::slot_cents()); }
        if ((int) $pack_dollars > 0) { $out[] = array(number_format(PlanTiers::pack_credits($pack_dollars)) . ' AI credits (monthly)', $pack_cents !== null ? (int) $pack_cents : (int) $pack_dollars * 100); }
        return $out;
    }

    /** What a promo takes off a plan charge (cents): percent of it, or a fixed amount up to it. */
    public static function promo_off($plan_cents, array $p): int
    {
        if (!empty($p['promo_percent'])) { return (int) round((int) $plan_cents * (float) $p['promo_percent'] / 100); }
        if (!empty($p['promo_amount_cents'])) { return min((int) $plan_cents, (int) $p['promo_amount_cents']); }
        return 0;
    }

    /** Check a promo code against Stripe (active, valid). ['ok', 'promo' => [...fields for billing_accounts], 'label'] or ['ok' => false, 'message']. */
    public static function resolve_promo($user_id, $code, $charge_cents = 0): array
    {
        $r = StripeService::resolve_promo_code((string) $code);
        if (empty($r) || (empty($r['percent_off']) && empty($r['amount_off'])) || empty($r['coupon_valid'])) { return array('ok' => false, 'message' => 'That promo code is not valid.'); }
        // The rules set on the code and its coupon in Stripe. Stripe only counts its own redemptions,
        // so charges the app made with the code are added before comparing with the limits.
        $now = time();
        if (($r['expires_at'] && $r['expires_at'] <= $now) || ($r['coupon_redeem_by'] && $r['coupon_redeem_by'] <= $now)) { return array('ok' => false, 'message' => 'That promo code has expired.'); }
        $ours = (new BillingChargesModel())->promo_redemptions($r['code']);
        if (($r['max_redemptions'] && $r['times_redeemed'] + $ours >= $r['max_redemptions'])
            || ($r['coupon_max_redemptions'] && $r['coupon_times_redeemed'] + $ours >= $r['coupon_max_redemptions'])) { return array('ok' => false, 'message' => 'That promo code has been used up.'); }
        if ($r['customer'] !== '' && $r['customer'] !== (string) (self::user($user_id)['stripe_customer_id'] ?? '')) { return array('ok' => false, 'message' => 'That promo code is not valid for your account.'); }
        if (!empty($r['first_time_only']) && (new BillingChargesModel())->has_paid_before($user_id)) { return array('ok' => false, 'message' => 'That promo code is only for your first purchase.'); }
        if ($r['minimum_amount'] && (int) $charge_cents < $r['minimum_amount']) { return array('ok' => false, 'message' => 'That promo code needs an order of at least ' . self::money($r['minimum_amount']) . '.'); }
        return array('ok' => true, 'label' => (string) $r['label'], 'promo' => array('promo_code' => $r['code'], 'promo_percent' => $r['percent_off'],
            'promo_amount_cents' => $r['amount_off'], 'promo_periods_left' => $r['periods']));
    }

    /** The promo as it stands after one more discounted charge (null = nothing left to store). */
    private static function promo_after_charge(array $p)
    {
        if ($p['promo_periods_left'] === null) { return $p; }   // forever
        $left = (int) $p['promo_periods_left'] - 1;
        return $left > 0 ? array_merge($p, array('promo_periods_left' => $left)) : null;
    }

    /** billing_accounts fields that store (or clear) a promo. */
    private static function promo_fields($p): array
    {
        return is_array($p) ? $p : array('promo_code' => null, 'promo_percent' => null, 'promo_amount_cents' => null, 'promo_periods_left' => null);
    }

    /* =====================================================================
     * Charging
     * =================================================================== */

    /**
     * Charge the saved card once and, on success, apply $effects. Returns
     * ['status' => succeeded|requires_action|failed|processing, 'charge_id', 'client_secret', 'message'].
     */
    public static function charge($user_id, $kind, array $lines, array $effects, $period_start, $period_end, $idem, $attempt = 1): array
    {
        $user_id = (int) $user_id;
        $charges = new BillingChargesModel();
        // An earlier attempt whose outcome isn't known yet: find out first. Charging again now could charge twice.
        foreach ($charges->unresolved($user_id, (string) $kind, $period_start) as $old) {
            $r = self::resolve($old);
            if ($r['status'] === 'processing') {
                return array('status' => 'processing', 'charge_id' => (int) $old['id'], 'client_secret' => '', 'message' => 'A previous payment is still being processed. We will update your plan as soon as it clears.');
            }
            if ($r['status'] === 'succeeded') { return $r; }   // it went through: nothing more to charge
            if ($r['status'] === 'requires_action') { continue; }   // handled just below with the other authentication waits
        }
        // A new attempt replaces any older one still waiting for authentication: cancel its payment so
        // the customer can't confirm both emailed links and pay for the same thing twice.
        foreach ($charges->open_action_charges($user_id, (string) $kind, $period_start) as $old) {
            $pi = (string) $old['stripe_payment_intent_id'];
            $st = ($pi !== '') ? StripeService::cancel_payment_intent($pi) : 'canceled';
            if ($st === 'succeeded') {   // the customer paid the old one after all: record it, don't charge again
                $done = self::settle((int) $old['id'], StripeService::payment_intent_result($pi));
                if ($kind === 'renewal') { return $done; }
                continue;
            }
            if ($st !== 'canceled') { return array('status' => 'failed', 'charge_id' => (int) $old['id'], 'client_secret' => '', 'message' => 'A previous payment is still being processed. Try again in a minute.'); }
            $charges->set((int) $old['id'], array('status' => 'failed', 'failure_reason' => 'Replaced by a newer payment attempt'));
        }
        $amount  = (int) array_sum(array_column($lines, 1));
        $items   = array_map(function ($l) { return array('label' => (string) $l[0], 'amount_cents' => (int) $l[1]); }, $lines);
        try {
            $cid = $charges->create(array('user_id' => $user_id, 'kind' => (string) $kind, 'amount_cents' => $amount,
                'line_items' => json_encode($items), 'effects' => json_encode($effects), 'period_start' => $period_start, 'period_end' => $period_end,
                'idempotency_key' => (string) $idem, 'status' => 'pending', 'attempt' => (int) $attempt));
        } catch (\Throwable $e) {
            return array('status' => 'failed', 'charge_id' => 0, 'client_secret' => '', 'message' => 'This charge is already being processed.');
        }
        (new BillingAccountsModel())->save($user_id, array('last_charge_id' => $cid, 'last_charge_status' => 'pending'));

        if ($amount <= 0) {   // nothing to collect (e.g. an upgrade fully covered by unused time)
            self::apply($cid);
            return array('status' => 'succeeded', 'charge_id' => $cid, 'client_secret' => '', 'message' => 'Done');
        }
        $user = self::user($user_id);
        $acct = self::account($user_id);
        $customer = (string) ($user['stripe_customer_id'] ?? '');
        $pm = (string) ($acct['stripe_payment_method_id'] ?? '');
        if ($customer === '' || $pm === '') {
            $charges->set($cid, array('status' => 'failed', 'failure_reason' => 'No card on file'));
            self::after_failure($cid);
            return array('status' => 'failed', 'charge_id' => $cid, 'client_secret' => '', 'message' => 'Add a card first.');
        }
        $desc = Main::site_name() . ': ' . implode(', ', array_column($lines, 0));
        $r = StripeService::charge_saved_card($customer, $pm, $amount, $desc,
            array('type' => 'platform_billing', 'user_id' => (string) $user_id, 'charge_id' => (string) $cid, 'kind' => (string) $kind), 'cls-billing-' . $idem);
        if ($r['payment_intent_id'] !== '') { $charges->set($cid, array('stripe_payment_intent_id' => $r['payment_intent_id'])); }
        return self::settle($cid, $r);
    }

    /** Record a PaymentIntent outcome on its charge and act on it. Safe to call more than once. */
    public static function settle($charge_id, array $r): array
    {
        $charges = new BillingChargesModel();
        $row = $charges->get($charge_id);
        if (!$row) { return array('status' => 'failed', 'charge_id' => 0, 'client_secret' => '', 'message' => 'Charge not found'); }
        if ($r['status'] === 'succeeded') {
            self::apply((int) $row['id']);
            return array('status' => 'succeeded', 'charge_id' => (int) $row['id'], 'client_secret' => '', 'message' => 'Payment received');
        }
        if ((int) $row['applied'] === 1) {   // already succeeded through another path
            return array('status' => 'succeeded', 'charge_id' => (int) $row['id'], 'client_secret' => '', 'message' => 'Payment received');
        }
        if ($r['status'] === 'processing' || $r['status'] === 'unknown') {
            // Not failed: the money may have moved. Park it until resolve() (next attempt, billing run or webhook) knows.
            $charges->set((int) $row['id'], array('status' => 'processing', 'failure_reason' => $r['status'] === 'unknown' ? 'Waiting for the bank to confirm' : null));
            return array('status' => 'processing', 'charge_id' => (int) $row['id'], 'client_secret' => '', 'message' => 'Your payment is processing.');
        }
        if ($r['status'] === 'requires_action') {
            $was = (string) $row['status'];
            $charges->set((int) $row['id'], array('status' => 'requires_action', 'failure_reason' => 'Authentication required'));
            if ($was !== 'requires_action') { self::after_failure((int) $row['id'], true); }
            return array('status' => 'requires_action', 'charge_id' => (int) $row['id'], 'client_secret' => (string) $r['client_secret'], 'message' => 'Your bank needs you to confirm this payment.');
        }
        $was = (string) $row['status'];
        $charges->set((int) $row['id'], array('status' => 'failed', 'failure_reason' => mb_substr((string) ($r['reason'] ?: 'The card was declined.'), 0, 255)));
        if ($was !== 'failed') { self::after_failure((int) $row['id']); }
        return array('status' => 'failed', 'charge_id' => (int) $row['id'], 'client_secret' => '', 'message' => (string) ($r['reason'] ?: 'The card was declined.'));
    }

    /**
     * Find the real outcome of a charge left pending or processing and settle it. A charge that never reached Stripe
     * (no PaymentIntent an hour later) is failed, which schedules the normal retry. Returns settle()'s shape.
     */
    public static function resolve(array $row): array
    {
        $pi = (string) ($row['stripe_payment_intent_id'] ?? '');
        $r  = $pi !== '' ? StripeService::payment_intent_result($pi) : StripeService::find_billing_payment((int) $row['id']);
        if ($r === null) {   // Stripe has no payment for this charge
            if (strtotime((string) $row['created_at'] . ' UTC') > time() - 3600) {
                return array('status' => 'processing', 'charge_id' => (int) $row['id'], 'client_secret' => '', 'message' => 'Still checking');
            }
            $r = array('status' => 'failed', 'payment_intent_id' => '', 'client_secret' => '', 'reason' => 'The payment could not be processed.');
        }
        if ($r['status'] === 'unknown' || ($pi !== '' && $r['reason'] === 'The payment could not be checked.')) {   // Stripe unreachable again
            return array('status' => 'processing', 'charge_id' => (int) $row['id'], 'client_secret' => '', 'message' => 'Still checking');
        }
        if ($pi === '' && $r['payment_intent_id'] !== '') { (new BillingChargesModel())->set((int) $row['id'], array('stripe_payment_intent_id' => $r['payment_intent_id'])); }
        return self::settle((int) $row['id'], $r);
    }

    /** Apply a succeeded charge's effects exactly once: plan/period/add-ons, credits, receipt. */
    public static function apply($charge_id): bool
    {
        $charges = new BillingChargesModel();
        if (!$charges->claim_apply($charge_id)) { return false; }
        $row = $charges->get($charge_id);
        $uid = (int) $row['user_id'];
        $fx  = (array) json_decode((string) $row['effects'], true);
        $accts = new BillingAccountsModel();
        $acct  = self::account($uid);
        $ai    = new AiCreditsModel();
        $f     = array('last_charge_id' => (int) $row['id'], 'last_charge_status' => 'succeeded');

        switch ((string) $row['kind']) {
            case 'subscribe':
                $f += array('plan_key' => $fx['plan'], 'status' => 'active', 'cancel_at_period_end' => 0, 'pending_plan_key' => null,
                    'current_period_start' => $fx['period_start'], 'current_period_end' => $fx['period_end'], 'next_charge_at' => $fx['period_end'],
                    'influencer_slots' => 0, 'influencer_slots_next' => null, 'past_due_since' => null, 'retry_count' => 0, 'next_retry_at' => null)
                    + self::promo_fields(!empty($fx['promo']) ? self::promo_after_charge($fx['promo']) : null);
                $t = PlanTiers::get($fx['plan']);
                $frac = isset($fx['credit_frac']) ? max(0.0, min(1.0, (float) $fx['credit_frac'])) : 1.0;
                $ai->set_bucket($uid, 'plan', (int) round((int) ($t['limits']['ai_credits'] ?? 0) * $frac), $t['name'] . ' plan: included AI credits');
                break;
            case 'upgrade':
                $f += array('plan_key' => $fx['plan'], 'pending_plan_key' => null, 'cancel_at_period_end' => 0);
                if (!empty($fx['promo'])) { $f += self::promo_fields(self::promo_after_charge($fx['promo'])); }   // a new code replaces the old one
                if (!self::takes_slots($fx['plan'])) { $f += array('influencer_slots' => 0, 'influencer_slots_next' => null); }
                $old = PlanTiers::get($fx['from']); $new = PlanTiers::get($fx['plan']);
                $frac = isset($fx['credit_frac']) ? max(0.0, min(1.0, (float) $fx['credit_frac'])) : 1.0;
                $diff = (int) round(((int) ($new['limits']['ai_credits'] ?? 0) - (int) ($old['limits']['ai_credits'] ?? 0)) * $frac);
                if ($diff > 0) { $ai->add_plan_credits($uid, $diff, $new['name'] . ' plan: included AI credits (upgrade)'); }
                break;
            case 'slots':
                $f += array('influencer_slots' => (int) $fx['slots'], 'influencer_slots_next' => null);
                break;
            case 'pack':
                $f += array('pack_dollars' => (int) $fx['pack'], 'pack_price_cents' => (int) $fx['pack_cents'], 'pack_started_at' => gmdate('Y-m-d H:i:s'), 'pack_dollars_next' => null);
                $ai->set_bucket($uid, 'pack', PlanTiers::pack_credits($fx['pack']), number_format(PlanTiers::pack_credits($fx['pack'])) . ' AI credits (monthly)', (bool) PlanTiers::BILLING['pack_carry_over']);
                break;
            case 'renewal':
                $f += array('status' => 'active', 'current_period_start' => $row['period_start'], 'current_period_end' => $row['period_end'],
                    'next_charge_at' => $row['period_end'], 'past_due_since' => null, 'retry_count' => 0, 'next_retry_at' => null);
                if (!empty($fx['promo'])) { $f += self::promo_fields(self::promo_after_charge($fx['promo'])); }
                if ((string) $fx['plan'] !== self::PLAN_FREE) {
                    $t = PlanTiers::get($fx['plan']);
                    $ai->set_bucket($uid, 'plan', (int) ($t['limits']['ai_credits'] ?? 0), $t['name'] . ' plan: AI credits for ' . date('M j', strtotime($row['period_start'] . ' UTC')) . ' to ' . date('M j', strtotime($row['period_end'] . ' UTC')));
                }
                if ((int) $fx['pack'] > 0) {
                    $ai->set_bucket($uid, 'pack', PlanTiers::pack_credits($fx['pack']), number_format(PlanTiers::pack_credits($fx['pack'])) . ' AI credits (monthly)', (bool) PlanTiers::BILLING['pack_carry_over']);
                }
                break;
        }
        $accts->save($uid, $f);
        // --- founding offer: the Creator plan started on the founding promo, so the spot turns active and the fee locks (before mirror(), which moves memberships to the new fee) ---
        if ((string) $row['kind'] === 'subscribe' && (string) ($fx['promo']['promo_code'] ?? '') === Founding::CODE) { Founding::activate($uid); }
        // --- end founding offer ---
        // --- affiliates: a paid plan charge of a referred account earns its approved affiliate a commission (Affiliates) ---
        Affiliates::on_charge($row);
        // --- end affiliates ---
        self::mirror($uid);
        if ((string) $row['kind'] === 'subscribe') { DirectoryService::list_on_upgrade($uid); }   // Free to a paid plan: listed in /creators by default
        if (!empty($fx['agreement']['version'])) {   // the Creator Agreement ticked at this checkout: stamp date, version and ip on success
            (new UsersModel())->accept_creator_agreement($uid, $uid, (string) $fx['agreement']['version'], (string) ($fx['agreement']['ip'] ?? ''));
            CreatorAgreement::forget($uid);
        }
        self::receipt($row);
        return true;
    }

    /** What a failed charge means for the account; renewals go past due and schedule a retry. */
    private static function after_failure($charge_id, $needs_action = false): void
    {
        $row = (new BillingChargesModel())->get($charge_id);
        if (!$row) { return; }
        $uid = (int) $row['user_id'];
        $accts = new BillingAccountsModel();
        $accts->save($uid, array('last_charge_id' => (int) $row['id'], 'last_charge_status' => $needs_action ? 'requires_action' : 'failed'));
        if ((string) $row['kind'] !== 'renewal') { return; }   // on-page charges: the person sees the error right away

        $acct  = self::account($uid);
        $since = (string) ($acct['past_due_since'] ?? '') !== '' ? (string) $acct['past_due_since'] : gmdate('Y-m-d H:i:s');
        $retries = (array) PlanTiers::BILLING['retry_after_days'];
        $n = (int) $row['attempt'];   // attempts so far; retry n happens retries[n-1] days after the first failure
        $grace_end = strtotime($since . ' UTC') + (int) PlanTiers::BILLING['grace_days'] * 86400;
        $next = isset($retries[$n - 1]) ? strtotime($since . ' UTC') + (int) $retries[$n - 1] * 86400 : null;
        if ($next === null || $next > $grace_end || time() >= $grace_end) {
            self::to_free($uid, true, 'payment_failed');
            self::email($uid, 'Your plan has ended', 'We couldn\'t collect your payment after several tries, so your account is now on Free and your creator tools are paused. Everything you made is saved and comes back when you pick a plan again.', array(), '', 'Choose a Plan', '/account/billing');
            return;
        }
        $accts->save($uid, array('status' => 'past_due', 'past_due_since' => $since, 'retry_count' => $n, 'next_retry_at' => gmdate('Y-m-d H:i:s', $next)));
        self::mirror($uid);
        $lines = self::money_lines((array) json_decode((string) $row['line_items'], true));
        if ($needs_action) {
            self::email($uid, 'Confirm your payment', 'Your bank needs you to confirm this month\'s payment. Your plan stays on until ' . gmdate('M j', $grace_end) . '.',
                $lines, self::money($row['amount_cents']), 'Confirm Payment', '/account/billing?pay=' . (int) $row['id']);
        } else {
            self::email($uid, 'Your payment didn\'t go through', 'We couldn\'t charge your card (' . ((string) $row['failure_reason'] ?: 'declined') . '). We\'ll try again on ' . gmdate('M j', $next)
                . '. Update your card to keep your plan; it stays on until ' . gmdate('M j', $grace_end) . '.',
                $lines, self::money($row['amount_cents']), 'Update Card', '/account/billing');
        }
    }

    /* =====================================================================
     * Renewals (the scheduled job)
     * =================================================================== */

    /**
     * Start the next period for a due account (or retry a past-due one): apply changes scheduled
     * for this date, then charge plan + slots + pack in one PaymentIntent.
     */
    public static function renew($user_id): array
    {
        $uid   = (int) $user_id;
        $accts = new BillingAccountsModel();
        $acct  = self::account($uid);
        $start = (string) ($acct['current_period_end'] ?: $acct['next_charge_at']);
        if ($start === '') { return array('status' => 'skipped', 'message' => 'No billing period'); }

        // Changes that take effect on the billing date: cancel, downgrade, slot removal, pack change.
        $f = array();
        $plan = (string) $acct['plan_key'];
        if ((int) $acct['cancel_at_period_end']) {
            $plan = self::PLAN_FREE;
            $f += array('plan_key' => $plan, 'cancel_at_period_end' => 0, 'pending_plan_key' => null, 'influencer_slots' => 0, 'influencer_slots_next' => null) + self::promo_fields(null);
            (new AiCreditsModel())->set_bucket($uid, 'plan', 0, '');
            self::email($uid, 'Your plan has ended', 'Your paid plan ended as you asked, so your account is now on Free and your creator tools are paused. Everything you made is saved and comes back when you pick a plan again.', array(), '', 'See Plans', '/account/billing');
        } elseif ((string) ($acct['pending_plan_key'] ?? '') !== '') {
            $plan = (string) $acct['pending_plan_key'];
            $f += array('plan_key' => $plan, 'pending_plan_key' => null);
            if (!self::takes_slots($plan)) { $f += array('influencer_slots' => 0, 'influencer_slots_next' => null); }
        }
        if (!array_key_exists('influencer_slots', $f) && $acct['influencer_slots_next'] !== null) { $f += array('influencer_slots' => (int) $acct['influencer_slots_next'], 'influencer_slots_next' => null); }
        if ($acct['pack_dollars_next'] !== null) {
            $p = (int) $acct['pack_dollars_next'];
            $f += $p > 0 ? array('pack_dollars' => $p, 'pack_price_cents' => $p * 100, 'pack_started_at' => gmdate('Y-m-d H:i:s'), 'pack_dollars_next' => null)
                         : array('pack_dollars' => null, 'pack_price_cents' => null, 'pack_started_at' => null, 'pack_dollars_next' => null);
        }
        if (!empty($f)) { $accts->save($uid, $f); $acct = self::account($uid); self::mirror($uid); }

        $lines = self::lines($plan, (int) $acct['influencer_slots'], (int) $acct['pack_dollars'], $acct['pack_price_cents'] !== null ? (int) $acct['pack_price_cents'] : null);
        if (empty($lines)) {   // nothing recurring left: plain Free
            $accts->save($uid, array('status' => 'free', 'next_charge_at' => null, 'past_due_since' => null, 'retry_count' => 0, 'next_retry_at' => null));
            self::mirror($uid);
            return array('status' => 'free', 'message' => 'Moved to Free');
        }

        $charges = new BillingChargesModel();
        if ($charges->renewal_paid($uid, $start)) { return array('status' => 'skipped', 'message' => 'Already paid'); }
        $last    = $charges->last_renewal($uid, $start);
        $attempt = $last ? (int) $last['attempt'] + 1 : 1;
        $end     = self::add_period($start);
        $fx      = array('plan' => $plan, 'slots' => (int) $acct['influencer_slots'], 'pack' => (int) $acct['pack_dollars']);
        if ($plan !== self::PLAN_FREE && !empty($acct['promo_code'])) {
            $off = self::promo_off(self::plan_cents($plan), $acct);
            if ($off > 0) {
                $lines[] = array('Promo ' . $acct['promo_code'], -$off);
                $fx['promo'] = array('promo_code' => $acct['promo_code'], 'promo_percent' => $acct['promo_percent'], 'promo_amount_cents' => $acct['promo_amount_cents'],
                    'promo_periods_left' => $acct['promo_periods_left'] === null ? null : (int) $acct['promo_periods_left']);
            }
        }
        return self::charge($uid, 'renewal', $lines, $fx, $start, $end, 'renewal-' . $uid . '-' . strtotime($start . ' UTC') . '-a' . $attempt, $attempt);
    }

    /** Move to Free: plan and slots end; $drop_pack also ends the recurring pack. Nothing is deleted. */
    public static function to_free($user_id, $drop_pack, $why = ''): void
    {
        $uid  = (int) $user_id;
        $acct = self::account($uid);
        $f = array('plan_key' => self::PLAN_FREE, 'cancel_at_period_end' => 0, 'pending_plan_key' => null, 'influencer_slots' => 0, 'influencer_slots_next' => null,
            'past_due_since' => null, 'retry_count' => 0, 'next_retry_at' => null) + self::promo_fields(null);
        $keep_pack = !$drop_pack && (int) $acct['pack_dollars'] > 0;
        if ($keep_pack) { $f += array('status' => 'active'); }
        else { $f += array('status' => 'free', 'next_charge_at' => null, 'pack_dollars' => null, 'pack_price_cents' => null, 'pack_started_at' => null, 'pack_dollars_next' => null); }
        (new BillingAccountsModel())->save($uid, $f);
        (new AiCreditsModel())->set_bucket($uid, 'plan', 0, '');
        self::mirror($uid);
    }

    /**
     * End the plan immediately with nothing billed again (leaving creator mode): app billing
     * goes to Free with add-ons and pack removed, and a not-yet-migrated Stripe subscription is
     * cancelled. No refund for the rest of the period.
     */
    public static function end_plan_now($user_id): void
    {
        $uid = (int) $user_id;
        self::to_free($uid, true, 'left_creator');
        $u = self::user($uid);
        $sub = (string) ($u['stripe_subscription_id'] ?? '');
        if ($sub !== '') {
            try { StripeService::client()->subscriptions->cancel($sub); }
            catch (\Throwable $e) { error_log('[billing] end_plan_now cancel ' . $sub . ': ' . $e->getMessage()); }
            (new BillingModel())->forget_stripe_subscription($uid);
        }
        (new BillingModel())->save_plan_mirror($uid, null, null, null, 0);
    }

    /* =====================================================================
     * Changes from the billing page
     * =================================================================== */

    /** Save the card from a succeeded SetupIntent; a past-due account is retried right away. */
    public static function save_card($user_id, $setup_intent_id): array
    {
        $user = self::user($user_id);
        $customer = (string) ($user['stripe_customer_id'] ?? '');
        $card = $customer !== '' ? StripeService::card_from_setup_intent($customer, $setup_intent_id) : array();
        if (empty($card)) { return array('ok' => false, 'message' => 'We couldn\'t save that card. Please try again.'); }
        (new BillingAccountsModel())->save((int) $user_id, array('stripe_payment_method_id' => $card['id'], 'card_brand' => $card['brand'], 'card_last4' => $card['last4'], 'card_exp' => $card['exp']));
        $acct = self::account($user_id);
        if ((string) $acct['status'] === 'past_due') {
            $r = self::renew((int) $user_id);
            return array('ok' => true, 'retry' => $r, 'message' => ($r['status'] ?? '') === 'succeeded' ? 'Card saved and your payment went through.' : 'Card saved. ' . ($r['message'] ?? ''));
        }
        return array('ok' => true, 'message' => 'Card saved');
    }

    /** What moving to $plan would charge today and from when: for the disclosure before confirming. */
    public static function quote_plan($user_id, $plan, $code = ''): array
    {
        $q = self::quote_plan_base($user_id, $plan);
        if (empty($q['ok'])) { return $q; }
        if (trim((string) $code) !== '' && in_array($q['mode'], array('subscribe', 'upgrade'), true)) {
            // founding offer: an internal promo (no Stripe coupon), checked against the spots left (Founding)
            $pr = strtoupper(trim((string) $code)) === Founding::CODE ? Founding::promo($user_id, $plan, $q) : self::resolve_promo($user_id, $code, (int) $q['lines'][0][1]);
            if (empty($pr['ok'])) { return array('ok' => false, 'message' => $pr['message']); }
            $off = self::promo_off((int) $q['lines'][0][1], $pr['promo']);   // the new plan's charge is always the first line
            $q['lines'][] = array('Promo ' . $pr['promo']['promo_code'] . ' (' . $pr['label'] . ')', -$off);
            $q['today'] = max(0, (int) array_sum(array_column($q['lines'], 1)));
            $q['promo'] = $pr['promo'];
            $q['promo_label'] = $pr['label'];
        }
        $q['card_needed'] = self::card_needed(self::account($user_id), $q);
        return $q;
    }

    /**
     * Does this plan change need a card on file? Not for a downgrade, and not when nothing is due today
     * and nothing will ever be due: a forever promo covering the whole plan, with no credit pack or
     * extra AI influencers renewing alongside it. A one-time or limited promo still needs a card for the renewal.
     */
    private static function card_needed(array $acct, array $q): bool
    {
        if ($q['mode'] === 'downgrade') { return false; }
        if ((int) $q['today'] > 0) { return true; }
        $p = $q['promo'] ?? null;
        $free_forever = is_array($p) && $p['promo_periods_left'] === null && self::promo_off((int) $q['recurring'], $p) >= (int) $q['recurring'];
        $pack = $acct['pack_dollars_next'] !== null ? (int) $acct['pack_dollars_next'] : (int) $acct['pack_dollars'];
        return !$free_forever || $pack > 0 || (int) $acct['influencer_slots'] > 0;
    }

    private static function quote_plan_base($user_id, $plan): array
    {
        $acct = self::account($user_id);
        $to = PlanTiers::get($plan);
        if (!$to || !empty($to['retired']) || $plan === self::PLAN_FREE) { return array('ok' => false, 'message' => 'That plan is not available.'); }
        $from = (string) $acct['plan_key'];
        $scheduled = (string) $acct['status'] === 'active' && !empty($acct['next_charge_at']);
        if (!self::is_paid($acct)) {
            if ($scheduled) {   // Free with a recurring pack: join its billing date, prorated
                $today = (int) round(self::plan_cents($plan) * self::remaining_fraction($acct));
                return array('ok' => true, 'mode' => 'subscribe', 'today' => $today, 'lines' => array(array($to['name'] . ' plan (rest of this period)', $today)),
                    'next_at' => (string) $acct['next_charge_at'], 'recurring' => self::plan_cents($plan));
            }
            $now = gmdate('Y-m-d H:i:s');
            return array('ok' => true, 'mode' => 'subscribe', 'today' => self::plan_cents($plan), 'lines' => array(array($to['name'] . ' plan', self::plan_cents($plan))),
                'next_at' => self::add_period($now), 'recurring' => self::plan_cents($plan));
        }
        if ($from === $plan) { return array('ok' => false, 'message' => 'That is already your plan.'); }
        if (self::plan_cents($plan) > self::plan_cents($from)) {
            $frac = self::remaining_fraction($acct);
            // Credit for the rest of the current plan at what was actually paid for it (a promo period isn't refunded at list price).
            $lines = array(array($to['name'] . ' plan (rest of this period)', (int) round(self::plan_cents($plan) * $frac)),
                           array('Unused ' . PlanTiers::get($from)['name'] . ' time', -(int) round(self::plan_cents($from) * $frac * self::paid_share($acct))));
            if ((int) $acct['influencer_slots'] > 0 && !self::takes_slots($plan)) {
                $lines[] = array('Unused extra AI influencers', -(int) round((int) $acct['influencer_slots'] * self::slot_cents() * $frac));
            }
            $today = max(0, (int) array_sum(array_column($lines, 1)));
            return array('ok' => true, 'mode' => 'upgrade', 'today' => $today, 'lines' => $lines, 'next_at' => (string) $acct['next_charge_at'], 'recurring' => self::plan_cents($plan));
        }
        return array('ok' => true, 'mode' => 'downgrade', 'today' => 0, 'lines' => array(), 'next_at' => (string) $acct['next_charge_at'], 'recurring' => self::plan_cents($plan));
    }

    /** Subscribe from Free, upgrade now (prorated), or schedule a downgrade for the billing date. */
    public static function change_plan($user_id, $plan, $code = '', array $agreement = array()): array
    {
        $uid  = (int) $user_id;
        $acct = self::account($uid);
        if ((string) $acct['status'] === 'past_due') { return array('status' => 'failed', 'message' => 'Update your card to settle your last payment before changing plans.'); }
        $q = self::quote_plan($uid, $plan, $code);
        if (empty($q['ok'])) { return array('status' => 'failed', 'message' => $q['message']); }
        $name = PlanTiers::get($plan)['name'];
        // $0 today but a renewal to come: the card has to be on file now, or the plan lapses at renewal.
        if (!empty($q['card_needed']) && (string) ($acct['stripe_payment_method_id'] ?? '') === '') { return array('status' => 'failed', 'message' => 'Add a card first.'); }
        if ($q['mode'] === 'downgrade') {
            (new BillingAccountsModel())->save($uid, array('pending_plan_key' => $plan, 'cancel_at_period_end' => 0));
            self::mirror($uid);
            return array('status' => 'scheduled', 'message' => 'You\'ll move to ' . $name . ' on ' . date('M j', strtotime($acct['next_charge_at'] . ' UTC')) . '.');
        }
        if ($q['mode'] === 'upgrade') {
            // Included AI credits are prorated exactly like the price: paying for 1/30 of a period buys 1/30 of the extra credits.
            $fx = array('plan' => $plan, 'from' => (string) $acct['plan_key'], 'promo' => $q['promo'] ?? null, 'promo_code' => $q['promo']['promo_code'] ?? null,
                        'credit_frac' => self::remaining_fraction($acct), 'agreement' => $agreement);
            return self::charge($uid, 'upgrade', $q['lines'], $fx, gmdate('Y-m-d H:i:s'), (string) $acct['current_period_end'], 'upgrade-' . $uid . '-' . bin2hex(random_bytes(6)));
        }
        $keep = (string) $acct['status'] === 'active' && !empty($acct['next_charge_at']);
        $start = $keep ? (string) $acct['current_period_start'] : gmdate('Y-m-d H:i:s');
        $end   = $keep ? (string) $acct['current_period_end'] : $q['next_at'];
        // --- founding offer: hold a spot before any charge; given back if the charge fails (Founding) ---
        $founding = (string) ($q['promo']['promo_code'] ?? '') === Founding::CODE;
        $held = $founding ? Founding::claim($uid) : '';
        if ($held !== '') { return array('status' => 'failed', 'message' => $held); }
        try {
            $r = self::charge($uid, 'subscribe', $q['lines'], array('plan' => $plan, 'period_start' => $start, 'period_end' => $end, 'promo' => $q['promo'] ?? null, 'promo_code' => $q['promo']['promo_code'] ?? null,
                    'credit_frac' => $keep ? self::remaining_fraction($acct) : 1.0, 'agreement' => $agreement),   // joining a running period mid-way: prorated credits, like the price
                gmdate('Y-m-d H:i:s'), $end, 'subscribe-' . $uid . '-' . bin2hex(random_bytes(6)));
        } catch (\Throwable $e) {
            if ($founding) { Founding::release($uid); }   // an exception never keeps the spot
            throw $e;
        }
        if ($founding && ($r['status'] ?? '') === 'failed') { Founding::release($uid); }
        // --- end founding offer ---
        return $r;
    }

    public static function set_cancel($user_id, $cancel): array
    {
        $acct = self::account($user_id);
        if (!self::is_paid($acct)) { return array('ok' => false, 'message' => 'You\'re on Free.'); }
        (new BillingAccountsModel())->save((int) $user_id, array('cancel_at_period_end' => $cancel ? 1 : 0, 'pending_plan_key' => null));
        self::mirror($user_id);
        return array('ok' => true, 'message' => $cancel ? 'Your plan ends on ' . date('M j, Y', strtotime($acct['current_period_end'] . ' UTC')) . '. You\'ll move to Free then.' : 'Your plan will renew as usual.');
    }

    /** Extra AI influencer slots: adding charges the prorated price now; removing applies on the billing date. */
    public static function set_slots($user_id, $quantity): array
    {
        $uid = (int) $user_id; $acct = self::account($uid); $a = PlanTiers::addon('influencer_slot');
        if (!$a || !self::is_paid($acct) || !self::takes_slots($acct['plan_key'])) { return array('status' => 'failed', 'message' => 'Extra AI influencers are only available on the Creator plan.'); }
        if ((string) $acct['status'] === 'past_due') { return array('status' => 'failed', 'message' => 'Update your card to settle your last payment first.'); }
        if ((int) $acct['cancel_at_period_end']) { return array('status' => 'failed', 'message' => 'Resume your plan before changing add-ons.'); }
        $q = (int) $quantity; $have = (int) $acct['influencer_slots'];
        if ($q < 0 || $q > (int) $a['max']) { return array('status' => 'failed', 'message' => 'Choose between 0 and ' . (int) $a['max'] . '.'); }
        if ($q <= $have) {
            (new BillingAccountsModel())->save($uid, array('influencer_slots_next' => $q === $have ? null : $q));
            return array('status' => 'scheduled', 'message' => $q === $have ? 'Removal cancelled.' : 'You keep ' . $have . ' until ' . date('M j', strtotime($acct['next_charge_at'] . ' UTC')) . ', then ' . $q . '.');
        }
        $add = $q - $have;
        $cents = (int) round($add * self::slot_cents() * self::remaining_fraction($acct));
        $lines = array(array($add . ' extra AI influencer' . ($add === 1 ? '' : 's') . ' (rest of this period)', $cents));
        return self::charge($uid, 'slots', $lines, array('slots' => $q), gmdate('Y-m-d H:i:s'), (string) $acct['current_period_end'], 'slots-' . $uid . '-' . bin2hex(random_bytes(6)));
    }

    /**
     * Recurring AI credit pack (paid plans only). Adding one charges it now and grants the credits; it renews
     * on the account's billing date. Changing or removing it applies on the next billing date.
     */
    public static function set_pack($user_id, $dollars): array
    {
        $uid = (int) $user_id; $acct = self::account($uid); $d = (int) $dollars;
        if ($d !== 0 && !in_array($d, PlanTiers::AI_PACKS, true)) { return array('status' => 'failed', 'message' => 'Choose a valid credit pack.'); }
        if (!self::is_paid($acct)) { return array('status' => 'failed', 'message' => 'Choose a plan to buy AI credits.'); }
        if ((string) $acct['status'] === 'past_due') { return array('status' => 'failed', 'message' => 'Update your card to settle your last payment first.'); }
        $have = (int) $acct['pack_dollars'];
        if ($have > 0) {
            $next = ($d === $have) ? null : $d;
            (new BillingAccountsModel())->save($uid, array('pack_dollars_next' => $next));
            $when = date('M j', strtotime($acct['next_charge_at'] . ' UTC'));
            return array('status' => 'scheduled', 'message' => $next === null ? 'No change to your monthly credits.' : ($d === 0 ? 'Your monthly credits stop on ' . $when . '.' : 'Your monthly credits change to ' . number_format(PlanTiers::pack_credits($d)) . ' on ' . $when . '.'));
        }
        if ($d === 0) { return array('status' => 'failed', 'message' => 'You have no recurring pack.'); }
        $start = gmdate('Y-m-d H:i:s');
        $fx = array('pack' => $d, 'pack_cents' => $d * 100);
        $lines = array(array(number_format(PlanTiers::pack_credits($d)) . ' AI credits (monthly)', $d * 100));
        return self::charge($uid, 'pack', $lines, $fx, $start, (string) $acct['next_charge_at'], 'pack-' . $uid . '-' . bin2hex(random_bytes(6)));
    }

    /** After the page confirmed an authentication, record the PaymentIntent's outcome. */
    public static function confirm($user_id, $charge_id): array
    {
        $row = (new BillingChargesModel())->get_for_user((int) $user_id, (int) $charge_id);
        if (!$row || (string) $row['stripe_payment_intent_id'] === '') { return array('status' => 'failed', 'charge_id' => 0, 'client_secret' => '', 'message' => 'Payment not found'); }
        return self::settle((int) $row['id'], StripeService::payment_intent_result((string) $row['stripe_payment_intent_id']));
    }

    /* =====================================================================
     * Mirror, receipts, email
     * =================================================================== */

    /** Copy billing state onto user_accounts so Plan:: reads (tier, status, period) stay column reads. */
    public static function mirror($user_id): void
    {
        $acct = self::account($user_id);
        $paid = self::is_paid($acct);
        $fee_before = Plan::fee_percent(self::user($user_id));
        (new BillingModel())->save_plan_mirror((int) $user_id, $paid ? (string) $acct['plan_key'] : null,
            $paid ? ((string) $acct['status'] === 'past_due' ? 'past_due' : 'active') : null,
            $acct['current_period_end'] ?: null, (int) $acct['cancel_at_period_end']);
        if (Plan::fee_percent(self::user($user_id)) !== $fee_before) {   // new plan, new fee: existing fan memberships follow
            (new DatabaseJobQueue())->dispatch('membership_fee', array('user_id' => (int) $user_id), 'membership_fee:' . (int) $user_id);
        }
    }

    private static function money_lines(array $items): array
    {
        return array_map(function ($i) { return array((string) $i['label'], self::money((int) $i['amount_cents'])); }, $items);
    }

    private static function receipt(array $row): void
    {
        if ((int) $row['amount_cents'] <= 0) { return; }
        $acct = self::account((int) $row['user_id']);
        $next = !empty($acct['next_charge_at']) ? ' Next charge: ' . date('M j, Y', strtotime($acct['next_charge_at'] . ' UTC')) . '.' : '';
        self::email((int) $row['user_id'], 'Your receipt from ' . Main::site_name(), 'Thanks, we received your payment.' . $next,
            self::money_lines((array) json_decode((string) $row['line_items'], true)), self::money($row['amount_cents']), 'View Billing', '/account/billing');
    }

    /** Billing email plus the in-app notice. $link is a site path. */
    private static function email($user_id, $subject, $intro, array $lines, $total, $button, $link): void
    {
        try {
            $u = self::user($user_id);
            (new UserNotificationsModel())->push((int) $user_id, 'subscriptions', (string) $subject, (string) $intro, (string) $link, 'fa-credit-card');
            if ((string) ($u['user_email'] ?? '') === '') { return; }
            (new NotificationsModel())->send_billing_email((string) $u['user_email'], trim($u['first_name'] . ' ' . $u['last_name']), $subject, $intro, $lines, $total,
                $button, $link !== '' ? Main::get_base_domain() . $link : '');
        } catch (\Throwable $e) {
            error_log('[billing] email ' . $subject . ': ' . $e->getMessage());
        }
    }
}
