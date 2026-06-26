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
}
