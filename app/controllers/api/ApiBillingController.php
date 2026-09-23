<?php
/** Platform subscriptions (Stripe), credit purchases and creator payouts. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiBillingController extends BaseApiController {

    public function create_subscriptionAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        if (empty($this->post['price_id'])) {
            $this->jsonError('Please choose a plan');
        }
        // Only a price one of our plans defines can be subscribed to (app/config/plans.php), and never a retired plan.
        $new_tier = PlanTiers::tier_for_price((string) $this->post['price_id']);
        if ($new_tier === '' || PlanTiers::retired($new_tier)) {
            $this->jsonError('That plan is not available.');
        }

        try {
            $stripe  = StripeService::client();
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];

            // A live plan is changed with change_subscription (prorated); a second
            // subscription here would bill twice.
            if (!empty($user['stripe_subscription_id']) && in_array((string) ($user['subscription_status'] ?? ''), ['active', 'trialing', 'past_due'], true)) {
                $this->jsonError('You already have a plan. Use Change plan instead.');
            }

            $customer_id = $user['stripe_customer_id'] ?? '';
            if (empty($customer_id)) {
                $customer = $stripe->customers->create([
                    'email'    => $user['user_email'],
                    'name'     => trim($user['first_name'] . ' ' . $user['last_name']),
                    'metadata' => ['user_id' => (string) $user_id],
                ]);
                $customer_id = $customer->id;
                $this->billingModel->set_customer_id($user_id, $customer_id);
            }

            // Optional promo code, resolved against the platform account. A code that
            // doesn't match is an error (not silently ignored) so the user knows.
            $promo_code = trim((string) ($this->post['promo_code'] ?? ''));
            $promo      = [];
            if ($promo_code !== '') {
                $promo = StripeService::resolve_promo_code($promo_code);
                if (empty($promo)) {
                    $this->jsonError('That promo code is not valid.');
                }
            }

            // Choosing a plan again (or applying a code) while an earlier attempt was
            // never paid must not pile up incomplete subscriptions in Stripe.
            $this->cancel_incomplete_subscription($user);

            $params = [
                'customer'         => $customer_id,
                'items'            => [['price' => $this->post['price_id']]],
                'payment_behavior' => 'default_incomplete',
                'payment_settings' => ['save_default_payment_method' => 'on_subscription'],
                'expand'           => ['latest_invoice.confirmation_secret', 'pending_setup_intent'],
            ];
            if (!empty($promo)) {
                $params['discounts'] = [$promo['discount']];
            }
            $subscription = $stripe->subscriptions->create($params);

            // Normal case: the first invoice needs a payment → confirmPayment on the client.
            // $0 first invoice (100% promo): Stripe activates the subscription with no
            // PaymentIntent and instead offers a SetupIntent so a card can be saved for
            // renewals → confirmSetup on the client. No SetupIntent either → nothing to
            // collect; the subscription is simply live.
            $mode          = 'payment';
            $client_secret = $subscription->latest_invoice->confirmation_secret->client_secret ?? null;
            if (empty($client_secret)) {
                $client_secret = $subscription->pending_setup_intent->client_secret ?? null;
                $mode          = !empty($client_secret) ? 'setup' : 'none';
            }
            if ($mode === 'none' && !in_array((string) $subscription->status, ['active', 'trialing'], true)) {
                // Nothing to confirm and not live: don't leave a stray subscription behind.
                error_log('[stripe] create_subscription: no client secret, status=' . $subscription->status . ' sub=' . $subscription->id);
                try { $stripe->subscriptions->cancel($subscription->id); } catch (\Throwable $e) {}
                $this->jsonError('Could not initialize payment');
            }

            $period_end = StripeService::plan_item($subscription)->current_period_end ?? null;
            $this->billingModel->save_subscription($user_id, $subscription->id, $this->post['price_id'], $subscription->status, $period_end, $subscription->cancel_at_period_end ? 1 : 0);

            $this->jsonSuccess(['mode' => $mode, 'client_secret' => (string) $client_secret, 'subscription_id' => $subscription->id, 'amount_due' => (int) ($subscription->latest_invoice->amount_due ?? 0), 'currency' => (string) ($subscription->latest_invoice->currency ?? 'usd'), 'promo_label' => !empty($promo) ? $promo['label'] : '', 'message' => 'Subscription started']);

        } catch (\Throwable $e) {
            error_log('[stripe] create_subscription: ' . $e->getMessage());
            $this->jsonError('Could not start the subscription. Please try again.');
        }
    }

    /** Upgrade or downgrade the live platform plan in place (prorated, same renewal date). */
    public function change_subscriptionAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $price_id = trim((string) ($this->post['price_id'] ?? ''));
        if ($price_id === '') {
            $this->jsonError('Please choose a plan');
        }

        $user_id = (int) Session::get('user_id');
        $user    = $this->userModel->get_user_by_id($user_id)[0];
        $sub_id  = (string) ($user['stripe_subscription_id'] ?? '');
        $status  = (string) ($user['subscription_status'] ?? '');

        if ($sub_id === '' || !in_array($status, ['active', 'trialing'], true)) {
            $this->jsonError($status === 'past_due' ? 'Update your payment method before changing plans.' : 'You don\'t have a plan to change. Choose a plan first.');
        }
        if ($price_id === (string) ($user['stripe_price_id'] ?? '')) {
            $this->jsonError('That is already your plan.');
        }
        // The price must belong to a plan we define (app/config/plans.php) that is still offered.
        $to_tier = PlanTiers::tier_for_price($price_id);
        if ($to_tier === '' || PlanTiers::retired($to_tier)) {
            $this->jsonError('That plan is not available.');
        }

        try {
            $sub = StripeService::change_subscription_price($sub_id, $price_id);
        } catch (\Stripe\Exception\CardException $e) {
            error_log('[stripe] change_subscription card: ' . $e->getMessage());
            $this->jsonError('We couldn\'t charge your card for the upgrade. Update your payment method and try again.');
        } catch (\Throwable $e) {
            error_log('[stripe] change_subscription: ' . $e->getMessage());
            $this->jsonError('Could not change the plan. Please try again.');
        }

        $period_end = StripeService::plan_item($sub)->current_period_end ?? null;
        $this->billingModel->save_subscription($user_id, $sub->id, $price_id, $sub->status, $period_end, $sub->cancel_at_period_end ? 1 : 0);
        // Add-ons the new plan doesn't take were removed from the subscription above; forget them here too.
        $new_tier = PlanTiers::tier_for_price($price_id);
        foreach (PlanTiers::addons() as $a) {
            if (!in_array($new_tier, (array) ($a['plans'] ?? array()), true)) { (new AccountAddonsModel())->clear($user_id, $a['key']); }
        }

        // Moving up mid-period: the grant tops the balance up to the new plan's amount right away.
        $fresh = $this->userModel->get_user_by_id($user_id)[0];
        Plan::grant_monthly($fresh);

        $this->jsonSuccess(['message' => 'Your plan is now ' . Plan::tier_name($fresh), 'tier' => Plan::tier($fresh)]);
    }

    /**
     * Set how many of an add-on (plans.php '_addons', e.g. extra AI influencer slots) the account
     * holds. Adding charges the prorated difference now; removing lowers what the next invoice
     * bills and keeps the slots until the period ends. Owner only, on a plan that offers it.
     */
    public function addon_setAction(){
        $user = $this->require_creator('owner');
        $uid  = (int) $user['user_id'];
        $a    = PlanTiers::addon((string) ($this->post['addon'] ?? ''));
        if (!$a || empty($a['stripe_price_id'])) { $this->jsonError('That add-on is not available.'); }
        $tier = Plan::tier($user);
        if (!Plan::has_paid_plan($user) || !in_array($tier, (array) ($a['plans'] ?? array()), true)) {
            $this->jsonError($a['name'] . ' is only available on the ' . implode(', ', array_map(function ($k) { $t = PlanTiers::get($k); return $t ? $t['name'] : $k; }, (array) $a['plans'])) . ' plan.');
        }
        if (!empty($user['subscription_cancel_at_period_end'])) { $this->jsonError('Resume your plan before changing add-ons.'); }
        $max    = (int) ($a['max'] ?? 0);
        $target = (int) ($this->post['quantity'] ?? -1);
        if ($target < 0 || $target > $max) { $this->jsonError('Choose between 0 and ' . $max . '.'); }

        $m       = new AccountAddonsModel();
        $row     = $m->get($uid, $a['key']);
        $current = Plan::addon_quantity($user, $a['key']);                                            // entitled now
        $billed  = $row ? (int) ($row['quantity_next'] !== null ? $row['quantity_next'] : $row['quantity']) : 0;   // on the next invoice
        if ($target === $billed) { $this->jsonError('No change.'); }
        $sub_id  = (string) $user['stripe_subscription_id'];

        try {
            if ($target > $current) {
                // Undo a pending removal first without charging (those slots are already paid for), then prorate the rest.
                if ($billed < $current) { StripeService::set_addon_quantity($sub_id, $a['stripe_price_id'], $current, false); }
                $sub = StripeService::set_addon_quantity($sub_id, $a['stripe_price_id'], $target, true);
                $m->save($uid, $a['key'], array('quantity' => $target, 'quantity_next' => null, 'next_at' => null,
                    'stripe_item_id' => ($it = StripeService::addon_item($sub, $a['stripe_price_id'])) ? $it->id : null));
                $msg = 'Added ' . ($target - $current) . ' slot' . ($target - $current === 1 ? '' : 's') . '. You now have ' . $target . ' extra.';
            } else {
                $sub = StripeService::set_addon_quantity($sub_id, $a['stripe_price_id'], $target, false);
                $end = !empty($user['subscription_current_period_end']) ? (string) $user['subscription_current_period_end'] : null;
                $it  = StripeService::addon_item($sub, $a['stripe_price_id']);
                if ($target === $current) {
                    $m->save($uid, $a['key'], array('quantity' => $current, 'quantity_next' => null, 'next_at' => null, 'stripe_item_id' => $it ? $it->id : null));
                    $msg = 'Removal cancelled';
                } else {
                    $m->save($uid, $a['key'], array('quantity' => $current, 'quantity_next' => $target, 'next_at' => $end, 'stripe_item_id' => $it ? $it->id : null));
                    $msg = 'You keep ' . $current . ' until ' . ($end ? date('M j', strtotime($end . ' UTC')) : 'the end of the period') . ', then ' . $target;
                }
            }
        } catch (\Stripe\Exception\CardException $e) {
            error_log('[stripe] addon_set card: ' . $e->getMessage());
            $this->jsonError('We couldn\'t charge your card. Update your payment method and try again.');
        } catch (\Throwable $e) {
            error_log('[stripe] addon_set: ' . $e->getMessage());
            $this->jsonError('Could not change the add-on. Please try again.');
        }
        Plan::forget_addons();
        $fresh = $this->userModel->get_user_by_id($uid)[0];
        $this->jsonSuccess(['message' => $msg, 'quantity' => $target, 'limit' => Plan::limit($fresh, (string) $a['limit'])]);
    }

    /* ---------- AI credits ($1 = 1 credit) ---------- */

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
                'description'               => $dollars . ' AI credits',
                'metadata'                  => [
                    'user_id' => (string) $user['user_id'],
                    'credits' => (string) $dollars,
                    'type'    => 'ai_credit_purchase',
                ],
            ]);
            $this->jsonSuccess(['client_secret' => $intent->client_secret, 'credits' => $dollars, 'total_cents' => $cents, 'message' => 'Payment ready']);
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

    /**
     * The user closed the payment form without paying. Cancel the never-paid
     * subscription in Stripe and forget it locally, so it can't show up as a plan.
     */
    public function abandon_subscriptionAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $user_id = (int) Session::get('user_id');
        $user    = $this->userModel->get_user_by_id($user_id)[0];
        $this->cancel_incomplete_subscription($user);

        $this->jsonSuccess(['message' => 'Payment cancelled']);
    }

    public function sync_subscriptionAction(){

        $response = ['success' => false, 'message' => 'Something went wrong', 'status' => ''];

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        try {
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];
            $sub_id  = $user['stripe_subscription_id'] ?? '';

            if (empty($sub_id)) {
                $response['success'] = true;
                $response['status']  = '';
                echo json_encode($response);
                exit;
            }

            $stripe       = StripeService::client();
            $subscription = $stripe->subscriptions->retrieve($sub_id);
            $item         = StripeService::plan_item($subscription);
            $price_id     = $item->price->id ?? ($user['stripe_price_id'] ?? '');
            $period_end   = $item->current_period_end ?? null;

            // A dead subscription is not a plan — drop it rather than caching its status.
            if (in_array((string) $subscription->status, ['canceled', 'incomplete_expired'], true)) {
                $this->billingModel->clear_subscription($user_id);
                (new AccountAddonsModel())->clear($user_id);
                $response['success'] = true;
                $response['status']  = '';
                $response['message'] = 'Subscription updated';
                echo json_encode($response);
                exit;
            }

            $this->billingModel->save_subscription($user_id, $subscription->id, $price_id, $subscription->status, $period_end, $subscription->cancel_at_period_end ? 1 : 0);

            $response['success'] = true;
            $response['status']  = $subscription->status;
            $response['message'] = 'Subscription updated';
            echo json_encode($response);
            exit;

        } catch (\Throwable $e) {
            error_log('[stripe] sync_subscription: ' . $e->getMessage());
            $response['message'] = 'Could not refresh subscription';
            echo json_encode($response);
            exit;
        }
    }

    public function cancel_subscriptionAction(){
        echo json_encode($this->set_cancel_at_period_end(true, 'Your subscription will cancel at the end of the period'));
        exit;
    }

    public function resume_subscriptionAction(){
        echo json_encode($this->set_cancel_at_period_end(false, 'Your subscription has been resumed'));
        exit;
    }

    public function cancel_now_subscriptionAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        try {
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];
            $sub_id  = $user['stripe_subscription_id'] ?? '';

            if (empty($sub_id)) {
                $this->jsonError('No active subscription');
            }

            StripeService::client()->subscriptions->cancel($sub_id);
            $this->billingModel->clear_subscription($user_id);
            (new AccountAddonsModel())->clear($user_id);

            $this->jsonSuccess(['message' => 'Your subscription has been canceled']);

        } catch (\Throwable $e) {
            error_log('[stripe] cancel_now_subscription: ' . $e->getMessage());
            $this->jsonError('Could not cancel the subscription. Please try again.');
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

            $this->jsonSuccess(['balance' => (int) $balance, 'message' => number_format($credits) . ' credits added']);

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

        $credits = new CreditsModel();
        $balance = (int) $credits->get_balance($creator_id);      // credits
        $min     = 100;                                           // $10.00 minimum ($1 = 10 credits)
        if ($balance < $min) {
            $this->jsonError('You need at least ' . $min . ' credits ($' . number_format($min / 10, 2) . ') to cash out.');
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

        $this->jsonSuccess(['message' => 'Payout of $' . number_format($cents / 100, 2) . ' is on its way to your bank.', 'balance' => 0]);
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

    /**
     * If the account's stored subscription was never paid (incomplete), cancel it in
     * Stripe and clear the local record. Live subscriptions are left untouched.
     */
    private function cancel_incomplete_subscription(array $user): void{
        $sub_id = (string) ($user['stripe_subscription_id'] ?? '');
        $status = (string) ($user['subscription_status'] ?? '');
        if ($sub_id === '' || !in_array($status, ['incomplete', 'incomplete_expired'], true)) {
            return;
        }
        try {
            $sub = StripeService::client()->subscriptions->retrieve($sub_id);
            if ($sub && $sub->status === 'incomplete') {
                StripeService::client()->subscriptions->cancel($sub_id);
            }
        } catch (\Throwable $e) {
            error_log('[stripe] cancel_incomplete_subscription: ' . $e->getMessage());
        }
        $this->billingModel->clear_subscription((int) $user['user_id']);
    }

    private function set_cancel_at_period_end(bool $cancel, string $success_message): array{
        $response = ['success' => false, 'message' => 'Something went wrong'];

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            return $response;
        }

        try {
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];
            $sub_id  = $user['stripe_subscription_id'] ?? '';

            if (empty($sub_id)) {
                $response['message'] = 'No active subscription';
                return $response;
            }

            $stripe       = StripeService::client();
            $subscription = $stripe->subscriptions->update($sub_id, ['cancel_at_period_end' => (bool) $cancel]);
            $item         = StripeService::plan_item($subscription);
            $price_id     = $item->price->id ?? ($user['stripe_price_id'] ?? '');
            $period_end   = $item->current_period_end ?? null;

            $this->billingModel->save_subscription($user_id, $subscription->id, $price_id, $subscription->status, $period_end, $subscription->cancel_at_period_end ? 1 : 0);
            $this->notify($user_id, 'subscriptions', $cancel ? 'Plan will cancel' : 'Plan resumed',
                $cancel ? ('Your ' . Plan::tier_name($user) . ' plan ends ' . ($period_end ? 'on ' . date('M j, Y', (int) $period_end) : 'at the end of the billing period') . '. After that you\'re on Free. Resume anytime before then.') : 'Your Creator Link Studio plan will renew as usual.',
                '/account/billing', $cancel ? 'fa-heart-crack' : 'fa-heart');

            $response['success'] = true;
            $response['message'] = $success_message;
            return $response;

        } catch (\Throwable $e) {
            error_log('[stripe] set_cancel_at_period_end: ' . $e->getMessage());
            $response['message'] = 'Could not update the subscription. Please try again.';
            return $response;
        }
    }

    /* ---------- Social publishing (Post for Me) ---------- */

    /** Ensure the logged-in user has a Stripe customer; returns [user, customer_id]. */
    private function ensure_stripe_customer($stripe): array{
        $user_id     = (int) Session::get('user_id');
        $user        = $this->userModel->get_user_by_id($user_id)[0];
        $customer_id = $user['stripe_customer_id'] ?? '';
        if (empty($customer_id)) {
            $customer = $stripe->customers->create([
                'email'    => $user['user_email'],
                'name'     => trim($user['first_name'] . ' ' . $user['last_name']),
                'metadata' => ['user_id' => (string) $user_id],
            ]);
            $customer_id = $customer->id;
            $this->billingModel->set_customer_id($user_id, $customer_id);
        }
        return [$user, $customer_id];
    }

    /** Absolute origin for Stripe return URLs — proxy-aware (X-Forwarded-Proto / force_https). */
    private function site_base_url(): string
    {
        return Main::get_base_domain();
    }

}
