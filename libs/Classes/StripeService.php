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

    /** True if the plan behind $price_id has product metadata social_posting = "true". */
    public static function plan_allows_social_posting($price_id): bool
    {
        if (empty($price_id)) {
            return false;
        }
        try {
            $price = self::client()->prices->retrieve($price_id, array('expand' => array('product')));
            $meta  = (isset($price->product) && isset($price->product->metadata)) ? $price->product->metadata : null;
            return $meta && isset($meta['social_posting']) && (string) $meta['social_posting'] === 'true';
        } catch (\Throwable $e) {
            error_log('[stripe] plan_allows_social_posting: ' . $e->getMessage());
            return false;
        }
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
}
