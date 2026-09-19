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

            $period_end = $subscription->items->data[0]->current_period_end ?? null;
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
        $known = false;
        foreach (StripeService::get_plans() as $p) {
            if ($p['price_id'] === $price_id) { $known = true; break; }
        }
        if (!$known) {
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

        $period_end = $sub->items->data[0]->current_period_end ?? null;
        $this->billingModel->save_subscription($user_id, $sub->id, $price_id, $sub->status, $period_end, $sub->cancel_at_period_end ? 1 : 0);

        // Moving up mid-period: top the balance up to the new plan's monthly credits right away.
        $fresh = $this->userModel->get_user_by_id($user_id)[0];
        $old_n = (int) (PlanTiers::get((string) ($user['plan_tier'] ?? ''))['limits']['ai_credits'] ?? 0);
        $new_n = (int) Plan::limit($fresh, 'ai_credits');
        if ($new_n > $old_n && (string) ($fresh['ai_credit_grant_period'] ?? '') === Plan::period_key($fresh)) {
            (new AiCreditsModel())->apply_delta($user_id, $new_n - $old_n, 'plan_grant', Plan::tier_name($fresh) . ' plan: ' . ($new_n - $old_n) . ' extra AI credits for this period');
        }

        $this->jsonSuccess(['message' => 'Your plan is now ' . Plan::tier_name($fresh), 'tier' => Plan::tier($fresh)]);
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
            $price_id     = $subscription->items->data[0]->price->id ?? ($user['stripe_price_id'] ?? '');
            $period_end   = $subscription->items->data[0]->current_period_end ?? null;

            // A dead subscription is not a plan — drop it rather than caching its status.
            if (in_array((string) $subscription->status, ['canceled', 'incomplete_expired'], true)) {
                $this->billingModel->clear_subscription($user_id);
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

        $base = $this->site_base_url();
        $url  = StripeService::account_onboarding_link(
            $account_id,
            $base . '/account/settings?section=wallet&tab=cashout&payout_refresh=1',
            $base . '/account/settings?section=wallet&tab=cashout&payout_return=1'
        );
        if ($url === '') {
            $this->jsonError('Could not start payout setup. Please try again.');
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
            $this->jsonError('Could not send the payout. Make sure your bank account is connected.');
        }

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
            $price_id     = $subscription->items->data[0]->price->id ?? ($user['stripe_price_id'] ?? '');
            $period_end   = $subscription->items->data[0]->current_period_end ?? null;

            $this->billingModel->save_subscription($user_id, $subscription->id, $price_id, $subscription->status, $period_end, $subscription->cancel_at_period_end ? 1 : 0);

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
