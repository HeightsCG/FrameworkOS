<?php
/**
 * One-time move of creator plans from Stripe Subscriptions to app-managed billing (BillingService).
 * For every account with a platform Stripe subscription: keep the customer and its card, map the
 * plan (legacy Pro included) and extra AI influencer slots, set next_charge_at to the subscription's
 * current period end, then set the Stripe subscription to cancel at period end so the app's first
 * charge on that date is the only one.
 *
 *   php cron/migrate_subscriptions.php            dry run: prints what it would do, changes nothing
 *   php cron/migrate_subscriptions.php --apply    migrates (test-mode keys only)
 *   ... --user=123                                  only that account (try one first)
 *   APPLICATION_ENV=production php cron/migrate_subscriptions.php --apply --live   production
 *
 * Safe to run again: accounts already migrated are skipped.
 */

if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});

$apply = in_array('--apply', $argv, true);
$live  = strpos(StripeService::secret_key(), '_live_') !== false;
if ($apply && $live && !in_array('--live', $argv, true)) { fwrite(STDERR, "Live Stripe keys: add --live to confirm.\n"); exit(1); }

$rows  = (new BillingModel())->users_with_stripe_subscription();
foreach ($argv as $arg) { if (strpos($arg, '--user=') === 0) { $only = (int) substr($arg, 7); $rows = array_values(array_filter($rows, function ($id) use ($only) { return (int) $id === $only; })); } }
$c     = StripeService::client();
$accts = new BillingAccountsModel();
$slot  = PlanTiers::addon('influencer_slot');
echo ($apply ? 'MIGRATING' : 'DRY RUN') . ' — ' . count($rows) . " account(s) with a Stripe subscription\n";

foreach ($rows as $uid) {
    $uid = (int) $uid;
    try {
        $u = BillingService::user($uid);
        $existing = $accts->get($uid);
        if ($existing && (string) ($existing['migrated_subscription_id'] ?? '') !== '') { echo "user $uid: already migrated, skipped\n"; continue; }
        $sub = $c->subscriptions->retrieve((string) $u['stripe_subscription_id'], array('expand' => array('items.data.price.product', 'default_payment_method', 'customer')));
        if (!in_array((string) $sub->status, array('active', 'trialing', 'past_due'), true)) {
            echo "user $uid: subscription {$sub->id} is {$sub->status}, left on Free\n";
            if ($apply) { (new BillingModel())->save_plan_mirror($uid, null, null, null, 0); }
            continue;
        }
        $item = StripeService::plan_item($sub);
        $plan = PlanTiers::tier_for_price((string) $item->price->id);
        if ($plan === '') { $plan = PlanTiers::match(is_object($item->price->product) ? (string) $item->price->product->name : ''); }
        if ($plan === '' || $plan === PlanTiers::FREE_KEY) { echo "user $uid: could not map price {$item->price->id}, skipped\n"; continue; }
        $slots = 0;
        if ($slot && !empty($slot['stripe_price_id'])) { $si = StripeService::addon_item($sub, $slot['stripe_price_id']); $slots = $si ? (int) $si->quantity : 0; }
        $customer = is_object($sub->customer) ? $sub->customer : $c->customers->retrieve((string) $sub->customer);
        $pm = $sub->default_payment_method ?: ($customer->invoice_settings->default_payment_method ?? null);
        if (!$pm) { $cards = $c->paymentMethods->all(array('customer' => $customer->id, 'type' => 'card', 'limit' => 1))->data; $pm = $cards[0] ?? null; }
        $card = $pm ? StripeService::card_info($pm) : array();
        $start = gmdate('Y-m-d H:i:s', (int) $item->current_period_start);
        $end   = gmdate('Y-m-d H:i:s', (int) $item->current_period_end);
        echo "user $uid: {$sub->id} → plan $plan" . ($slots ? " + $slots slot(s)" : '') . ", next charge $end UTC, card " . ($card ? $card['brand'] . ' ' . $card['last4'] : 'NONE (they must add one)')
            . ((int) $sub->cancel_at_period_end ? ', already canceling (moves to Free at period end)' : '') . "\n";
        if (!$apply) { continue; }

        $accts->save($uid, array('plan_key' => $plan, 'status' => (string) $sub->status === 'past_due' ? 'past_due' : 'active',
            'current_period_start' => $start, 'current_period_end' => $end, 'next_charge_at' => $end,
            'cancel_at_period_end' => (int) $sub->cancel_at_period_end ? 1 : 0, 'influencer_slots' => $slots,
            'stripe_payment_method_id' => $card['id'] ?? null, 'card_brand' => $card['brand'] ?? null, 'card_last4' => $card['last4'] ?? null, 'card_exp' => $card['exp'] ?? null,
            'next_retry_at' => (string) $sub->status === 'past_due' ? gmdate('Y-m-d H:i:s') : null, 'past_due_since' => (string) $sub->status === 'past_due' ? gmdate('Y-m-d H:i:s') : null,
            'migrated_subscription_id' => (string) $sub->id));
        if ((string) $customer->id !== (string) ($u['stripe_customer_id'] ?? '')) { (new BillingModel())->set_customer_id($uid, (string) $customer->id); }
        // This period's plan credits were granted under the old system: count them as the plan bucket.
        $t = PlanTiers::get($plan);
        (new AiCreditsModel())->adopt_plan_bucket($uid, (int) ($t['limits']['ai_credits'] ?? 0));
        // Stop Stripe billing: the subscription ends when the period the customer paid for ends.
        if (!(int) $sub->cancel_at_period_end) {
            $c->subscriptions->update($sub->id, array('cancel_at_period_end' => true, 'metadata' => array('migrated_to_app_billing' => gmdate('c'))));
        }
        (new BillingModel())->forget_stripe_subscription($uid);
        BillingService::mirror($uid);
        echo "user $uid: migrated\n";
    } catch (\Throwable $e) {
        echo "user $uid: ERROR " . $e->getMessage() . "\n";
    }
}
