<?php
/**
 * Thin wrapper around the Stripe PHP SDK. Reads keys from app.ini (the
 * current environment section) so nothing is hardcoded.
 */
class StripeService {

    /** A configured Stripe API client. */
    public static function client(): \Stripe\StripeClient
    {
        return new \Stripe\StripeClient(self::secret_key());
    }

    public static function secret_key(): string
    {
        return (string) Main::config(Main::get_environment(), 'stripe_secret_key');
    }

    public static function publishable_key(): string
    {
        return (string) Main::config(Main::get_environment(), 'stripe_publishable_key');
    }

    /**
     * Active recurring prices with their product, shaped for the billing view.
     * Returns [] on any API/config error (honest empty state).
     */
    public static function get_plans(): array
    {
        $plans = array();
        try {
            $prices = self::client()->prices->all(array(
                'active'   => true,
                'type'     => 'recurring',
                'limit'    => 50,
                'expand'   => array('data.product'),
            ));
        } catch (\Throwable $e) {
            error_log('[stripe] get_plans failed: ' . $e->getMessage());
            return array();
        }

        foreach ($prices->data as $price) {
            $product = $price->product;
            // Skip prices whose product is archived/deleted.
            if (!is_object($product) || (isset($product->active) && !$product->active)) {
                continue;
            }
            $plans[] = array(
                'price_id'    => $price->id,
                'product_id'  => is_object($product) ? $product->id : (string) $product,
                'name'        => is_object($product) ? $product->name : 'Plan',
                'description' => is_object($product) ? (string) $product->description : '',
                'amount'      => $price->unit_amount,            // cents
                'currency'    => strtoupper((string) $price->currency),
                'interval'    => $price->recurring->interval,    // month / year
            );
        }
        return $plans;
    }

    /** Recent invoices for a customer, shaped for the billing view. */
    public static function get_invoices($customer_id, $limit = 12): array
    {
        if (empty($customer_id)) {
            return array();
        }
        try {
            $invoices = self::client()->invoices->all(array(
                'customer' => $customer_id,
                'limit'    => $limit,
            ));
        } catch (\Throwable $e) {
            error_log('[stripe] get_invoices failed: ' . $e->getMessage());
            return array();
        }

        $out = array();
        foreach ($invoices->data as $inv) {
            $out[] = array(
                'number'   => (string) $inv->number,
                'created'  => (int) $inv->created,
                'amount'   => (int) $inv->total,                 // cents
                'currency' => strtoupper((string) $inv->currency),
                'status'   => (string) $inv->status,             // paid / open / void / draft / uncollectible
                'hosted'   => (string) $inv->hosted_invoice_url,
                'pdf'      => (string) $inv->invoice_pdf,
            );
        }
        return $out;
    }

    /** Cards saved on a customer, with the default flagged. */
    public static function get_payment_methods($customer_id): array
    {
        if (empty($customer_id)) {
            return array();
        }
        try {
            $stripe     = self::client();
            $customer   = $stripe->customers->retrieve($customer_id);
            $default_pm = $customer->invoice_settings->default_payment_method ?? null;
            $methods    = $stripe->customers->allPaymentMethods($customer_id, array('limit' => 20));
        } catch (\Throwable $e) {
            error_log('[stripe] get_payment_methods failed: ' . $e->getMessage());
            return array();
        }

        $out  = array();
        $seen = array();
        foreach ($methods->data as $pm) {
            // Collapse duplicates (e.g. the same Link account or card attached more than once).
            if ($pm->type === 'card' && isset($pm->card)) {
                $key = 'card:' . ($pm->card->fingerprint ?? ($pm->card->last4 . $pm->card->exp_month . $pm->card->exp_year));
            } elseif ($pm->type === 'link') {
                $key = 'link:' . ($pm->link->email ?? $pm->id);
            } else {
                $key = $pm->type . ':' . $pm->id;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $row = array(
                'id'         => $pm->id,
                'type'       => (string) $pm->type,
                'brand'      => '',
                'last4'      => '',
                'exp_month'  => 0,
                'exp_year'   => 0,
                'detail'     => '',
                'is_default' => ($pm->id === $default_pm),
            );
            if ($pm->type === 'card' && isset($pm->card)) {
                $row['brand']     = ucfirst((string) $pm->card->brand);
                $row['last4']     = (string) $pm->card->last4;
                $row['exp_month'] = (int) $pm->card->exp_month;
                $row['exp_year']  = (int) $pm->card->exp_year;
            } elseif ($pm->type === 'link') {
                $row['brand']  = 'Link';
                $row['detail'] = (string) ($pm->link->email ?? '');
            } else {
                $row['brand'] = ucwords(str_replace('_', ' ', (string) $pm->type));
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Resolve which code-defined tier (PlanTiers) a Stripe price belongs to, by the
     * product NAME — Stripe owns pricing only, never feature semantics. Cached per
     * request. Returns a tier key ('creator'|'pro'|'studio') or '' if unmatched.
     */
    public static function plan_tier_slug($price_id): string
    {
        if (empty($price_id)) { return ''; }
        static $cache = array();
        if (array_key_exists($price_id, $cache)) { return $cache[$price_id]; }
        $slug = '';
        try {
            $price = self::client()->prices->retrieve($price_id, array('expand' => array('product')));
            $name  = (isset($price->product) && is_object($price->product)) ? (string) $price->product->name : '';
            $slug  = PlanTiers::match($name);
        } catch (\Throwable $e) {
            error_log('[stripe] plan_tier_slug: ' . $e->getMessage());
        }
        return $cache[$price_id] = $slug;
    }

    /* ---------- Connect (creator payouts) ---------- */

    /** Create an Express connected account for a creator. Returns account id or ''. */
    public static function create_connect_account($user): string
    {
        try {
            // @-suppress the SDK's "use Accounts v2" E_USER_WARNING (we intentionally
            // use v1 Express); real failures still throw and are caught below.
            $account = @self::client()->accounts->create(array(
                'type'         => 'express',
                'email'        => $user['user_email'] ?? null,
                'capabilities' => array('transfers' => array('requested' => true)),
                'business_type'=> 'individual',
                'metadata'     => array('user_id' => (string) ($user['user_id'] ?? '')),
            ));
            return $account->id;
        } catch (\Throwable $e) {
            error_log('[stripe] create_connect_account: ' . $e->getMessage());
            return '';
        }
    }

    /** Hosted onboarding link for a connected account. Returns URL or ''. */
    public static function account_onboarding_link($account_id, $refresh_url, $return_url): string
    {
        try {
            $link = self::client()->accountLinks->create(array(
                'account'     => $account_id,
                'refresh_url' => $refresh_url,
                'return_url'  => $return_url,
                'type'        => 'account_onboarding',
            ));
            return $link->url;
        } catch (\Throwable $e) {
            error_log('[stripe] account_onboarding_link: ' . $e->getMessage());
            return '';
        }
    }

    /** Onboarding/capability status for a connected account. */
    public static function connect_account_status($account_id): array
    {
        $out = array('exists' => false, 'details_submitted' => false, 'payouts_enabled' => false, 'requirements_due' => false);
        if (empty($account_id)) {
            return $out;
        }
        try {
            $acct = @self::client()->accounts->retrieve($account_id, array());
            $due  = isset($acct->requirements->currently_due) ? $acct->requirements->currently_due : array();
            $out['exists']            = true;
            $out['details_submitted'] = (bool) $acct->details_submitted;
            $out['payouts_enabled']   = (bool) $acct->payouts_enabled;
            $out['requirements_due']  = is_array($due) ? count($due) > 0 : (count((array) $due) > 0);
        } catch (\Throwable $e) {
            error_log('[stripe] connect_account_status: ' . $e->getMessage());
        }
        return $out;
    }

    /** Delete a connected account (best effort). Returns true if deleted. */
    public static function delete_connect_account($account_id): bool
    {
        if (empty($account_id)) {
            return false;
        }
        try {
            @self::client()->accounts->delete($account_id);
            return true;
        } catch (\Throwable $e) {
            error_log('[stripe] delete_connect_account: ' . $e->getMessage());
            return false;
        }
    }

    /** Express dashboard login link for a connected account. Returns URL or ''. */
    public static function connect_login_link($account_id): string
    {
        try {
            $link = @self::client()->accounts->createLoginLink($account_id);
            return $link->url;
        } catch (\Throwable $e) {
            error_log('[stripe] connect_login_link: ' . $e->getMessage());
            return '';
        }
    }

    /** Available + pending balance (cents) held for a connected account. */
    public static function connect_balance($account_id): array
    {
        $out = array('available' => 0, 'pending' => 0, 'currency' => 'USD');
        if (empty($account_id)) {
            return $out;
        }
        try {
            $bal = self::client()->balance->retrieve(array(), array('stripe_account' => $account_id));
            if (!empty($bal->available[0])) {
                $out['available'] = (int) $bal->available[0]->amount;
                $out['currency']  = strtoupper((string) $bal->available[0]->currency);
            }
            if (!empty($bal->pending[0])) {
                $out['pending'] = (int) $bal->pending[0]->amount;
            }
        } catch (\Throwable $e) {
            error_log('[stripe] connect_balance: ' . $e->getMessage());
        }
        return $out;
    }

    /** Create a manual payout of $amount_cents to the connected account's bank. Returns [ok, error]. */
    public static function create_payout($account_id, $amount_cents, $currency = 'usd'): array
    {
        try {
            @self::client()->payouts->create(
                array('amount' => (int) $amount_cents, 'currency' => strtolower($currency)),
                array('stripe_account' => $account_id)
            );
            return array('ok' => true, 'error' => '');
        } catch (\Throwable $e) {
            error_log('[stripe] create_payout: ' . $e->getMessage());
            return array('ok' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Move money from the platform balance to a connected account (separate charges
     * & transfers). Used to cash out a creator's earned credits as real money — Stripe
     * then pays the connected account out to its bank on its schedule.
     */
    public static function create_transfer($account_id, $amount_cents, $currency = 'usd', $idempotency_key = ''): array
    {
        try {
            $opts = array();
            if ($idempotency_key !== '') { $opts['idempotency_key'] = $idempotency_key; }
            self::client()->transfers->create(
                array(
                    'amount'      => (int) $amount_cents,
                    'currency'    => strtolower($currency),
                    'destination' => $account_id,
                ),
                $opts
            );
            return array('ok' => true, 'error' => '');
        } catch (\Throwable $e) {
            error_log('[stripe] create_transfer: ' . $e->getMessage());
            return array('ok' => false, 'error' => $e->getMessage());
        }
    }

    /** Recent payouts for a connected account, shaped for the view. */
    public static function connect_payouts($account_id, $limit = 10): array
    {
        if (empty($account_id)) {
            return array();
        }
        try {
            $payouts = self::client()->payouts->all(array('limit' => $limit), array('stripe_account' => $account_id));
        } catch (\Throwable $e) {
            error_log('[stripe] connect_payouts: ' . $e->getMessage());
            return array();
        }
        $out = array();
        foreach ($payouts->data as $p) {
            $out[] = array(
                'amount'   => (int) $p->amount,
                'currency' => strtoupper((string) $p->currency),
                'status'   => (string) $p->status,
                'arrival'  => (int) $p->arrival_date,
                'created'  => (int) $p->created,
            );
        }
        return $out;
    }

    /* ---------- Creator subscriptions (direct charges on the connected account) ---------- */

    /** Create a recurring Product + Price on the creator's connected account. */
    public static function create_connect_price($account_id, $plan): array
    {
        try {
            $opts = array('stripe_account' => $account_id);
            // Idempotency keys dedupe concurrent first-subscribes so a plan can't
            // spawn duplicate products/prices; keyed on the values that define them.
            $sig     = $plan['id'] . '_' . $plan['price_cents'] . '_' . $plan['billing_interval'];
            $product = self::client()->products->create(
                array('name' => (string) $plan['name']),
                $opts + array('idempotency_key' => 'clsprod_' . $sig)
            );
            $price = self::client()->prices->create(array(
                'unit_amount' => (int) $plan['price_cents'],
                'currency'    => 'usd',
                'recurring'   => array('interval' => (string) $plan['billing_interval']),
                'product'     => $product->id,
            ), $opts + array('idempotency_key' => 'clsprice_' . $sig));
            return array('product_id' => (string) $product->id, 'price_id' => (string) $price->id);
        } catch (\Throwable $e) {
            error_log('[stripe] create_connect_price: ' . $e->getMessage());
            return array('product_id' => '', 'price_id' => '', 'error' => $e->getMessage());
        }
    }

    /**
     * Hosted Checkout for a subscription on the connected account, collecting the
     * platform's application fee. $metadata ties the session back to a subscriber/plan.
     */
    public static function create_subscription_checkout($account_id, $price_id, $fee_percent, $success_url, $cancel_url, $metadata = array(), $email = '', $trial_end = 0): array
    {
        try {
            $sub_data = array(
                'application_fee_percent' => (float) $fee_percent,
                'metadata'                => $metadata,
            );
            if ((int) $trial_end > time()) {
                $sub_data['trial_end'] = (int) $trial_end;   // exact end date, not a day count
            }
            $params = array(
                'mode'       => 'subscription',
                'line_items' => array(array('price' => $price_id, 'quantity' => 1)),
                'success_url' => $success_url,
                'cancel_url'  => $cancel_url,
                'subscription_data' => $sub_data,
                'metadata' => $metadata,
            );
            if ($email !== '') {
                $params['customer_email'] = $email;
            }
            $session = self::client()->checkout->sessions->create($params, array('stripe_account' => $account_id));
            return array('id' => (string) $session->id, 'url' => (string) $session->url);
        } catch (\Throwable $e) {
            error_log('[stripe] create_subscription_checkout: ' . $e->getMessage());
            return array('id' => '', 'url' => '', 'error' => $e->getMessage());
        }
    }

    /** Schedule (or undo) cancellation of a subscription at period end, on the connected account. */
    public static function set_subscription_cancel_at_period_end($account_id, $subscription_id, $cancel): bool
    {
        try {
            self::client()->subscriptions->update(
                $subscription_id,
                array('cancel_at_period_end' => (bool) $cancel),
                array('stripe_account' => $account_id)
            );
            return true;
        } catch (\Throwable $e) {
            error_log('[stripe] set_subscription_cancel_at_period_end: ' . $e->getMessage());
            return false;
        }
    }

    /** Retrieve a completed Checkout session (with its subscription) from the connected account. */
    public static function retrieve_checkout_session($account_id, $session_id): array
    {
        try {
            $session = self::client()->checkout->sessions->retrieve(
                $session_id,
                array('expand' => array('subscription')),
                array('stripe_account' => $account_id)
            );
            $sub    = $session->subscription;
            $sub_id = is_object($sub) ? (string) $sub->id : (string) $sub;
            $period = (is_object($sub) && isset($sub->current_period_end)) ? (int) $sub->current_period_end : 0;
            return array(
                'status'             => (string) $session->status,
                'payment_status'     => (string) $session->payment_status,
                'subscription_id'    => $sub_id,
                'customer_id'        => (string) $session->customer,
                'metadata'           => $session->metadata ? $session->metadata->toArray() : array(),
                'current_period_end' => $period,
            );
        } catch (\Throwable $e) {
            error_log('[stripe] retrieve_checkout_session: ' . $e->getMessage());
            return array('status' => '', 'error' => $e->getMessage());
        }
    }
}
