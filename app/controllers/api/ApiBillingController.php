<?php
/** Platform plan billing (app-managed, BillingService), credit purchases and creator payouts. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiBillingController extends BaseApiController {

    /* ---------- Platform plan: app-managed billing (BillingService); Stripe only holds the card ---------- */

    /** Plan, add-ons, card and the next charge, for the billing page script. */
    private function billing_state(array $user): array{
        $acct = BillingService::account((int) $user['user_id']);
        $next = BillingService::next_charge($acct);
        return array(
            'plan' => (string) $acct['plan_key'], 'status' => (string) $acct['status'],
            'next_charge_at' => (string) ($acct['next_charge_at'] ?? ''), 'cancel_at_period_end' => (int) $acct['cancel_at_period_end'],
            'card' => (string) ($acct['stripe_payment_method_id'] ?? '') !== '' ? trim($acct['card_brand'] . ((string) $acct['card_last4'] !== '' ? ' •••• ' . $acct['card_last4'] : '')) : '',
            'next' => $next,
        );
    }

    /** Answer a BillingService charge result; requires_action hands the page a secret to confirm with. */
    private function charge_answer(array $r, string $ok_message): void{
        $st = (string) ($r['status'] ?? 'failed');
        if ($st === 'succeeded' || $st === 'scheduled') { $this->jsonSuccess(['status' => $st, 'message' => $st === 'succeeded' ? $ok_message : (string) $r['message']]); }
        if ($st === 'requires_action') {
            $acct = BillingService::account((int) Session::get('user_id') > 0 ? Permissions::creator_id() : 0);
            $this->jsonSuccess(['status' => $st, 'charge_id' => (int) $r['charge_id'], 'client_secret' => (string) $r['client_secret'],
                'payment_method' => (string) ($acct['stripe_payment_method_id'] ?? ''), 'message' => (string) $r['message']]);
        }
        if ($st === 'processing') { $this->jsonSuccess(['status' => $st, 'message' => (string) $r['message']]); }
        $this->jsonError((string) ($r['message'] ?? 'The payment did not go through.'));
    }

    /**
     * The recurring-charge disclosure before a change: what is charged today, what renews, how
     * often, the next charge date. For plans (plan=<key>), slots (slots=<n>) or a pack (pack=<dollars>).
     */
    public function billing_quoteAction(){
        $user = $this->require_creator('owner');
        $uid  = (int) $user['user_id'];
        $acct = BillingService::account($uid);
        $fmt  = function ($lines) { return array_map(function ($l) { return array('label' => $l[0], 'amount' => BillingService::money($l[1])); }, $lines); };
        if (isset($this->post['plan'])) {
            $q = BillingService::quote_plan($uid, (string) $this->post['plan'], (string) ($this->post['promo_code'] ?? ''));
            if (empty($q['ok'])) { $this->jsonError((string) $q['message']); }
            $t = PlanTiers::get((string) $this->post['plan']);
            $this->jsonSuccess(['mode' => $q['mode'], 'today' => BillingService::money($q['today']), 'lines' => $fmt($q['lines']),
                'recurring' => BillingService::money($q['recurring']) . ' / month for ' . $t['name'], 'next_at' => date('M j, Y', strtotime($q['next_at'] . ' UTC')),
                'has_card' => (string) ($acct['stripe_payment_method_id'] ?? '') !== '', 'card' => $this->billing_state($user)['card'],
                'promo_ok' => in_array($q['mode'], array('subscribe', 'upgrade'), true), 'promo_label' => (string) ($q['promo_label'] ?? ''),
                'today_zero' => (int) $q['today'] === 0, 'card_needed' => !empty($q['card_needed'])]);
        }
        if (isset($this->post['pack'])) {
            $d = (int) $this->post['pack'];
            if (!in_array($d, PlanTiers::AI_PACKS, true)) { $this->jsonError('Choose a valid credit pack.'); }
            $sched = (string) $acct['status'] === 'active' && !empty($acct['next_charge_at']);
            $next  = $sched ? (string) $acct['next_charge_at'] : BillingService::add_period(gmdate('Y-m-d H:i:s'));
            $this->jsonSuccess(['mode' => 'pack', 'today' => BillingService::money($d * 100), 'lines' => $fmt(array(array(number_format(PlanTiers::pack_credits($d)) . ' AI credits (monthly)', $d * 100))),
                'recurring' => BillingService::money($d * 100) . ' / month for ' . number_format(PlanTiers::pack_credits($d)) . ' AI credits', 'next_at' => date('M j, Y', strtotime($next . ' UTC')),
                'has_card' => (string) ($acct['stripe_payment_method_id'] ?? '') !== '', 'card' => $this->billing_state($user)['card']]);
        }
        if (isset($this->post['slots'])) {
            $add = (int) $this->post['slots'] - (int) $acct['influencer_slots'];
            if ($add <= 0) { $this->jsonError('Nothing to charge.'); }
            $cents = (int) round($add * BillingService::slot_cents() * BillingService::remaining_fraction($acct));
            $this->jsonSuccess(['mode' => 'slots', 'today' => BillingService::money($cents), 'lines' => $fmt(array(array($add . ' extra AI influencer' . ($add === 1 ? '' : 's') . ' (rest of this period)', $cents))),
                'recurring' => BillingService::money(BillingService::slot_cents()) . ' / month per extra AI influencer', 'next_at' => date('M j, Y', strtotime($acct['next_charge_at'] . ' UTC')),
                'has_card' => (string) ($acct['stripe_payment_method_id'] ?? '') !== '', 'card' => $this->billing_state($user)['card']]);
        }
        $this->jsonError('Nothing to quote.');
    }

    /** Start saving a card: a SetupIntent for off-session charges. */
    public function billing_card_setupAction(){
        $user = $this->require_creator('owner');
        $customer = StripeService::ensure_customer($user);
        $si = $customer !== '' ? StripeService::create_setup_intent($customer) : array();
        if (empty($si)) { $this->jsonError('Could not start adding a card. Please try again.'); }
        $this->jsonSuccess(['client_secret' => $si['client_secret']]);
    }

    /** The SetupIntent succeeded in the page: keep its card as the one we charge (retries a past-due account). */
    public function billing_card_saveAction(){
        $user = $this->require_creator('owner');
        $r = BillingService::save_card((int) $user['user_id'], (string) ($this->post['setup_intent_id'] ?? ''));
        if (empty($r['ok'])) { $this->jsonError((string) $r['message']); }
        $retry = (array) ($r['retry'] ?? array());
        if (($retry['status'] ?? '') === 'requires_action') {
            $acct = BillingService::account((int) $user['user_id']);
            $this->jsonSuccess(['message' => 'Card saved. Confirm the payment with your bank.', 'status' => 'requires_action', 'charge_id' => (int) $retry['charge_id'],
                'client_secret' => (string) $retry['client_secret'], 'payment_method' => (string) ($acct['stripe_payment_method_id'] ?? '')]);
        }
        $this->jsonSuccess(['message' => (string) $r['message']]);
    }

    /** Choose a paid plan (from Free), upgrade now (prorated), or schedule a downgrade. */
    public function billing_change_planAction(){
        $user = $this->require_creator('owner');
        $plan = (string) ($this->post['plan'] ?? '');
        if ($plan === PlanTiers::FREE_KEY) { $r = BillingService::set_cancel((int) $user['user_id'], true); if (empty($r['ok'])) { $this->jsonError($r['message']); } $this->jsonSuccess(['status' => 'scheduled', 'message' => $r['message']]); }
        $t = PlanTiers::get($plan);
        $this->charge_answer(BillingService::change_plan((int) $user['user_id'], $plan, (string) ($this->post['promo_code'] ?? '')), 'You\'re on ' . ($t ? $t['name'] : 'your new plan') . ' now.');
    }

    /** One-click cancel: the plan runs to the end of the period, then the account moves to Free. */
    public function billing_cancelAction(){
        $user = $this->require_creator('owner');
        $r = BillingService::set_cancel((int) $user['user_id'], true);
        if (empty($r['ok'])) { $this->jsonError($r['message']); }
        $this->jsonSuccess(['message' => $r['message']]);
    }

    public function billing_resumeAction(){
        $user = $this->require_creator('owner');
        $r = BillingService::set_cancel((int) $user['user_id'], false);
        if (empty($r['ok'])) { $this->jsonError($r['message']); }
        $this->jsonSuccess(['message' => $r['message']]);
    }

    /** Extra AI influencer slots (Creator): quantity = the total wanted. */
    public function billing_set_slotsAction(){
        $user = $this->require_creator('owner');
        $q = (int) ($this->post['quantity'] ?? -1);
        $this->charge_answer(BillingService::set_slots((int) $user['user_id'], $q), 'You now have ' . $q . ' extra AI influencer' . ($q === 1 ? '' : 's') . '.');
    }

    /** Recurring AI credit pack: dollars = a pack from PlanTiers::AI_PACKS, or 0 to stop it. */
    public function billing_set_packAction(){
        $user = $this->require_creator('owner');
        $d = (int) ($this->post['dollars'] ?? -1);
        $this->charge_answer(BillingService::set_pack((int) $user['user_id'], $d), number_format(PlanTiers::pack_credits($d)) . ' AI credits added. They renew monthly.');
    }

    /** The page finished a bank authentication: record the payment's outcome. */
    public function billing_confirmAction(){
        $user = $this->require_creator('owner');
        $this->charge_answer(BillingService::confirm((int) $user['user_id'], (int) ($this->post['charge_id'] ?? 0)), 'Payment confirmed.');
    }

    /** A payment waiting for authentication (the link in the "confirm your payment" email). */
    public function billing_pendingAction(){
        $user = $this->require_creator('owner');
        $row  = (new BillingChargesModel())->get_for_user((int) $user['user_id'], (int) ($this->post['charge_id'] ?? 0));
        if (!$row || (string) $row['status'] !== 'requires_action' || (string) $row['stripe_payment_intent_id'] === '') { $this->jsonError('There is no payment waiting for you.'); }
        $r = StripeService::payment_intent_result((string) $row['stripe_payment_intent_id']);
        if ($r['status'] === 'succeeded') { BillingService::settle((int) $row['id'], $r); $this->jsonError('That payment is already complete.'); }
        $acct = BillingService::account((int) $user['user_id']);
        $this->jsonSuccess(['charge_id' => (int) $row['id'], 'client_secret' => $r['client_secret'], 'payment_method' => (string) $acct['stripe_payment_method_id'],
            'amount' => BillingService::money((int) $row['amount_cents'])]);
    }

    /* ---------- AI credits ($1 = 10 credits, PlanTiers::AI_CREDITS_PER_DOLLAR) ---------- */

    public function buy_ai_creditsAction(){
        $user    = $this->require_creator('owner');
        $dollars = (int) ($this->post['dollars'] ?? 0);
        if (!in_array($dollars, PlanTiers::AI_PACKS, true)) {
            $this->jsonError('Choose a valid credit pack');
        }

        try {
            $stripe = StripeService::client();
            list($user, $customer_id) = $this->ensure_stripe_customer($stripe);
            $cents  = $dollars * 100;
            $intent = $stripe->paymentIntents->create([
                'amount'                    => $cents,
                'currency'                  => 'usd',
                'customer'                  => $customer_id,
                'automatic_payment_methods' => ['enabled' => true],
                'description'               => PlanTiers::pack_credits($dollars) . ' AI credits',
                'metadata'                  => [
                    'user_id' => (string) $user['user_id'],
                    'credits' => (string) PlanTiers::pack_credits($dollars),
                    'type'    => 'ai_credit_purchase',
                ],
            ]);
            $this->jsonSuccess(['client_secret' => $intent->client_secret, 'credits' => PlanTiers::pack_credits($dollars), 'total_cents' => $cents, 'message' => 'Payment ready']);
        } catch (\Throwable $e) {
            error_log('[stripe] buy_ai_credits: ' . $e->getMessage());
            $this->jsonError('Could not start the purchase. Please try again.');
        }
    }

    public function confirm_ai_credit_purchaseAction(){
        $user  = $this->require_creator('owner');
        $pi_id = (string) ($this->post['payment_intent_id'] ?? '');
        if ($pi_id === '') {
            $this->jsonError('Payment reference is required');
        }

        try {
            $user_id = (int) $user['user_id'];
            $intent  = StripeService::client()->paymentIntents->retrieve($pi_id);

            if ((string) ($intent->metadata['type'] ?? '') !== 'ai_credit_purchase'
                || (int) ($intent->metadata['user_id'] ?? 0) !== $user_id
                || (string) $intent->customer !== (string) ($user['stripe_customer_id'] ?? '')) {
                $this->jsonError('This payment could not be verified');
            }
            if ($intent->status !== 'succeeded') {
                $this->jsonError('Payment has not completed yet');
            }

            $credits = (int) ($intent->metadata['credits'] ?? 0);
            $balance = (new AiCreditsModel())->credit_purchase($user_id, $credits, $intent->id, 'Bought ' . $credits . ' AI credits');
            $this->notify($user_id, 'credits', 'AI credits added', $credits . ' AI credits for $' . number_format(((int) $intent->amount) / 100, 2) . '. Balance: ' . (int) $balance . ' AI credits.', '/account/billing', 'fa-wand-magic-sparkles');
            $this->jsonSuccess(['balance' => (int) $balance, 'message' => $credits . ' AI credits added']);

        } catch (\Throwable $e) {
            error_log('[stripe] confirm_ai_credit_purchase: ' . $e->getMessage());
            $this->jsonError('Could not confirm the purchase');
        }
    }

    public function buy_creditsAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $dollars = (int) ($this->post['dollars'] ?? 0);
        $package = CreditsModel::package_for_dollars($dollars);
        if (!$package) {
            $this->jsonError('Choose a valid credit package');
        }

        try {
            $stripe = StripeService::client();
            list($user, $customer_id) = $this->ensure_stripe_customer($stripe);

            // Buyer pays the package price PLUS a processing (merchant service) fee.
            $base_cents  = $package['dollars'] * 100;
            $fee_percent = Main::credit_fee_percent();
            $fee_cents   = (int) round($base_cents * $fee_percent / 100);
            $total_cents = $base_cents + $fee_cents;

            $intent = $stripe->paymentIntents->create([
                'amount'                    => $total_cents,
                'currency'                  => 'usd',
                'customer'                  => $customer_id,
                'automatic_payment_methods' => ['enabled' => true],
                'setup_future_usage'        => 'off_session',   // keep the card on file for auto-replenishment
                'metadata'                  => [
                    'user_id'    => (string) $user['user_id'],
                    'credits'    => (string) $package['credits'],
                    'type'       => 'credit_purchase',
                    'base_cents' => (string) $base_cents,
                    'fee_cents'  => (string) $fee_cents,
                ],
            ]);

            $this->jsonSuccess(['client_secret' => $intent->client_secret, 'credits' => $package['credits'], 'base_cents' => $base_cents, 'fee_cents' => $fee_cents, 'total_cents' => $total_cents, 'fee_percent' => $fee_percent, 'message' => 'Payment ready']);

        } catch (\Throwable $e) {
            error_log('[stripe] buy_credits: ' . $e->getMessage());
            $this->jsonError('Could not start the purchase. Please try again.');
        }
    }

    public function confirm_credit_purchaseAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $pi_id = (string) ($this->post['payment_intent_id'] ?? '');
        if ($pi_id === '') {
            $this->jsonError('Payment reference is required');
        }

        try {
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];
            $stripe  = StripeService::client();
            $intent  = $stripe->paymentIntents->retrieve($pi_id);

            // Trust the PaymentIntent, not the client: verify owner, status, and purpose.
            if ((string) ($intent->metadata['type'] ?? '') !== 'credit_purchase'
                || (int) ($intent->metadata['user_id'] ?? 0) !== $user_id
                || (string) $intent->customer !== (string) ($user['stripe_customer_id'] ?? '')) {
                $this->jsonError('This payment could not be verified');
            }
            if ($intent->status !== 'succeeded') {
                $this->jsonError('Payment has not completed yet');
            }

            $credits     = (int) ($intent->metadata['credits'] ?? 0);
            $creditsModel = new CreditsModel();
            $balance = $creditsModel->credit_purchase($user_id, $credits, $intent->id, 'Purchased ' . $credits . ' credits');
            $this->notify($user_id, 'credits', 'Credits added', Notify::credits($credits) . ' for $' . number_format(((int) $intent->amount) / 100, 2) . '. Balance: ' . Notify::credits((int) $balance) . '.', '/account/settings?section=wallet', 'fa-coins');

            $this->jsonSuccess(['balance' => (int) $balance, 'message' => number_format($credits) . ' credits added', 'value_cents' => (int) $intent->amount]);   // value_cents: GA purchase event

        } catch (\Throwable $e) {
            error_log('[stripe] confirm_credit_purchase: ' . $e->getMessage());
            $this->jsonError('Could not confirm the purchase');
        }
    }

    public function save_autoreplenishmentAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $enabled   = !empty($this->post['enabled']);
        $threshold = (int) ($this->post['threshold'] ?? 0);
        $dollars   = (int) ($this->post['dollars'] ?? 0);
        $pm_id     = (string) ($this->post['payment_method_id'] ?? '');

        if ($enabled) {
            if ($threshold <= 0) {
                $this->jsonError('Set a low-balance threshold above zero');
            }
            if (!CreditsModel::package_for_dollars($dollars)) {
                $this->jsonError('Choose a valid replenishment package');
            }
            if ($pm_id === '') {
                $this->jsonError('Choose a payment method');
            }
        }

        $creditsModel = new CreditsModel();
        $creditsModel->save_autoreplenishment((int) Session::get('user_id'), $enabled, $threshold, $dollars * 100, $pm_id);

        $this->jsonSuccess(['message' => 'Auto-replenishment saved']);
    }

    public function start_payout_onboardingAction(){
        $user = $this->require_creator('owner');

        $account_id = $user['stripe_connect_account_id'] ?? '';
        if (empty($account_id)) {
            $account_id = StripeService::create_connect_account($user);
            if ($account_id === '') {
                $why = trim((string) StripeService::$last_error);
                $this->jsonError('Payouts are not available yet.' . ($why !== '' ? ' Stripe said: ' . $why : ' Please try again later.'));
            }
            $this->billingModel->set_connect_account_id((int) $user['user_id'], $account_id);
        }

        $base    = $this->site_base_url();
        $refresh = $base . '/account/settings?section=wallet&tab=cashout&payout_refresh=1';
        $return  = $base . '/account/settings?section=wallet&tab=cashout&payout_return=1';
        $url = StripeService::account_onboarding_link($account_id, $refresh, $return);
        // The saved account doesn't exist for these keys (typically a test-mode id carried over to live):
        // drop it, create a fresh connected account, and try once more.
        if ($url === '' && StripeService::$last_missing) {
            error_log('[stripe] payout onboarding: account ' . $account_id . ' not found for user ' . (int) $user['user_id'] . '; creating a new one');
            $account_id = StripeService::create_connect_account($user);
            if ($account_id === '') {
                $why = trim((string) StripeService::$last_error);
                $this->jsonError('Payouts are not available yet.' . ($why !== '' ? ' Stripe said: ' . $why : ' Please try again later.'));
            }
            $this->billingModel->set_connect_account_id((int) $user['user_id'], $account_id);
            $url = StripeService::account_onboarding_link($account_id, $refresh, $return);
        }
        if ($url === '') {
            $why = trim((string) StripeService::$last_error);
            $this->jsonError('Could not start payout setup.' . ($why !== '' ? ' Stripe said: ' . $why : ' Please try again.'));
        }

        $this->jsonSuccess(['url' => $url]);
    }

    public function payout_login_linkAction(){
        $user       = $this->require_creator('owner');
        $account_id = $user['stripe_connect_account_id'] ?? '';
        if (empty($account_id)) {
            $this->jsonError('Set up payouts first');
        }
        $url = StripeService::connect_login_link($account_id);
        if ($url === '') {
            $this->jsonError('Could not open the payouts dashboard');
        }
        $this->jsonSuccess(['url' => $url]);
    }

    /** Cash out the creator's earned credits: convert to $, deduct, and send via Stripe. */
    public function request_payoutAction(){
        $user       = $this->require_creator('owner');
        $creator_id = (int) $user['user_id'];
        $account_id = (string) ($user['stripe_connect_account_id'] ?? '');
        if ($account_id === '') {
            $this->jsonError('Set up payouts first');
        }
        // Money leaves the platform here: re-check suspension now, not on the once-a-minute session check.
        $fresh = $this->userModel->get_user_by_id($creator_id);
        if (!is_array($fresh) || count($fresh) !== 1 || (string) ($fresh[0]['user_status'] ?? '') !== 'Active') {
            $this->jsonError('Payouts are unavailable for this account. Contact support.');
        }

        $credits = new CreditsModel();
        $balance = (int) $credits->withdrawable($creator_id);     // earned credits only — purchased credits can't be cashed out
        $min     = 100;                                           // $10.00 minimum ($1 = 10 credits)
        if ($balance < $min) {
            $this->jsonError('You need at least ' . $min . ' earned credits ($' . number_format($min / 10, 2) . ') to cash out.');
        }
        $cents = $balance * 10;                                   // 1 credit = 10 cents

        // Deduct first (this also prevents a double-payout from a double-click: the second
        // request sees a zero balance). Roll the credits back if the Stripe transfer fails.
        if ($credits->apply_delta($creator_id, -$balance, 'payout', 'Cash out to bank') === false) {
            $this->jsonError('Could not start the payout. Please try again.');
        }
        $idem = 'payout_' . $creator_id . '_' . $balance . '_' . bin2hex(random_bytes(8));
        $res  = StripeService::create_transfer($account_id, $cents, 'usd', $idem);
        if (empty($res['ok'])) {
            $credits->apply_delta($creator_id, $balance, 'payout_refund', 'Payout failed — credits returned');
            $this->notify($creator_id, 'credits', 'Payout failed', 'The transfer of $' . number_format($cents / 100, 2) . ' did not go through and your ' . Notify::credits($balance) . ' are back in your balance. Check your bank connection and try again.', '/account/settings?section=wallet&tab=cashout', 'fa-triangle-exclamation');
            $this->jsonError('Could not send the payout. Make sure your bank account is connected.');
        }
        $this->notify($creator_id, 'credits', 'Payout on its way', '$' . number_format($cents / 100, 2) . ' (' . Notify::credits($balance) . ') is being sent to your bank.', '/account/settings?section=wallet&tab=cashout', 'fa-building-columns');

        $this->jsonSuccess(['message' => 'Payout of $' . number_format($cents / 100, 2) . ' is on its way to your bank.', 'balance' => (int) $credits->get_balance($creator_id)]);
    }

    public function disconnect_payout_accountAction(){
        $user       = $this->require_creator('owner');
        $account_id = $user['stripe_connect_account_id'] ?? '';

        if ($account_id !== '') {
            StripeService::delete_connect_account($account_id);
        }
        $this->billingModel->set_connect_account_id((int) $user['user_id'], null);

        $this->jsonSuccess(['message' => 'Payout account disconnected']);
    }

    /* ---------- Credits & wallet ---------- */

    /* ---------- Social publishing (Post for Me) ---------- */

    /** Ensure the logged-in user has a Stripe customer; returns [user, customer_id]. */
    private function ensure_stripe_customer($stripe): array{
        $user_id     = (int) Session::get('user_id');
        $user        = $this->userModel->get_user_by_id($user_id)[0];
        $customer_id = StripeService::ensure_customer($user);   // replaces a stored id Stripe no longer has
        if ($customer_id === '') { throw new RuntimeException('Could not create the Stripe customer'); }
        return [$this->userModel->get_user_by_id($user_id)[0], $customer_id];
    }

    /** Absolute origin for Stripe return URLs — proxy-aware (X-Forwarded-Proto / force_https). */
    private function site_base_url(): string
    {
        return Main::get_base_domain();
    }

}
