<?php
/**
 * READ-ONLY: compare every billing account (billing_accounts + user_accounts Stripe ids) with its live Stripe
 * subscriptions and print the mismatches. Changes nothing, in the database or in Stripe.
 *
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/stripe_reconcile.php          # mismatches only
 *   ... --all        every account, matched ones too
 *   ... --json       machine-readable
 *
 * Plans are app-managed since 2026-09-23 (one PaymentIntent per charge), so the expected state for a paid DB plan is
 * NO active Stripe subscription (the migrated one was canceled). Anything else is listed.
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$opts = getopt('', array('all', 'json'));
$show_all = isset($opts['all']);
$as_json  = isset($opts['json']);

$selling = array();
foreach (PlanTiers::TIERS as $k => $t) { if ((int) ($t['price'] ?? 0) > 0 && empty($t['retired'])) { $selling[] = $k; } }
$live_states = array('active', 'trialing', 'past_due', 'unpaid', 'incomplete', 'paused');

$rows   = (new BillingAccountsModel())->all_for_reconcile();
$stripe = StripeService::client();
$out    = array();
$errors = 0;

foreach ($rows as $r) {
    $uid  = (int) $r['user_id'];
    $cust = (string) ($r['stripe_customer_id'] ?? '');
    $db   = array(
        'plan'       => (string) $r['plan_key'],
        'status'     => (string) $r['status'],
        'period_end' => (string) ($r['current_period_end'] ?? ''),
        'next_charge'=> (string) ($r['next_charge_at'] ?? ''),
    );
    $live = array('subs' => array(), 'error' => '');
    if ($cust !== '') {
        try {
            $list = $stripe->subscriptions->all(array('customer' => $cust, 'status' => 'all', 'limit' => 20));
            foreach ($list->data as $s) {
                $item  = isset($s->items->data[0]) ? $s->items->data[0] : null;
                $price = ($item && isset($item->price)) ? $item->price : null;
                $pend  = !empty($s->current_period_end) ? (int) $s->current_period_end : (($item && !empty($item->current_period_end)) ? (int) $item->current_period_end : 0);   // newer API versions keep it on the item
                $live['subs'][] = array(
                    'id'         => (string) $s->id,
                    'status'     => (string) $s->status,
                    'plan'       => $price ? StripeService::plan_tier_slug((string) $price->id) : '',
                    'price_id'   => $price ? (string) $price->id : '',
                    'period_end' => $pend > 0 ? gmdate('Y-m-d H:i:s', $pend) : '',
                    'cancel_at_period_end' => !empty($s->cancel_at_period_end),
                );
            }
        } catch (\Throwable $e) { $live['error'] = $e->getMessage(); $errors++; }
    }
    $active_subs = array_values(array_filter($live['subs'], function ($s) use ($live_states) { return in_array($s['status'], $live_states, true); }));

    // ---- compare
    $issues = array();
    $db_paid = in_array($db['plan'], $selling, true) && in_array($db['status'], array('active', 'past_due'), true);
    if ($live['error'] !== '') {
        $issues[] = 'stripe error: ' . $live['error'];
    } elseif ($cust === '' && $db_paid) {
        $issues[] = 'paid plan in DB but no Stripe customer id';
    }
    foreach ($active_subs as $s) {
        if ($db_paid) {
            $issues[] = 'live Stripe subscription ' . $s['id'] . ' (' . $s['status'] . ', ' . ($s['plan'] ?: $s['price_id']) . ') while the plan is app-managed: double-billing risk';
        } else {
            $issues[] = 'live Stripe subscription ' . $s['id'] . ' (' . $s['status'] . ', ' . ($s['plan'] ?: $s['price_id']) . ') but DB plan is ' . $db['plan'] . '/' . $db['status'];
        }
        if ($s['plan'] !== '' && $s['plan'] !== $db['plan']) { $issues[] = 'plan differs: DB ' . $db['plan'] . ' vs Stripe ' . $s['plan']; }
        if ($s['period_end'] !== '' && $db['period_end'] !== '' && abs(strtotime($s['period_end']) - strtotime($db['period_end'])) > 86400) {
            $issues[] = 'period end differs: DB ' . $db['period_end'] . ' vs Stripe ' . $s['period_end'];
        }
    }
    // legacy columns on user_accounts that still claim a live subscription
    $legacy = (string) ($r['subscription_status'] ?? '');
    if (in_array($legacy, $live_states, true) && empty($active_subs)) {
        $issues[] = 'user_accounts.subscription_status=' . $legacy . ' but Stripe has no live subscription (stale legacy column)';
    }
    if ((string) ($r['plan_tier'] ?? '') !== '' && (string) $r['plan_tier'] !== $db['plan']) {
        $issues[] = 'user_accounts.plan_tier=' . $r['plan_tier'] . ' differs from billing_accounts.plan_key=' . $db['plan'];
    }
    if ($db_paid && $db['next_charge'] !== '' && strtotime($db['next_charge']) < time() - 2 * 86400 && $db['status'] === 'active') {
        $issues[] = 'next_charge_at is ' . $db['next_charge'] . ' (more than 2 days overdue) while status is active';
    }

    $entry = array(
        'user_id' => $uid, 'handle' => (string) $r['u_name'], 'email' => (string) $r['user_email'],
        'demo' => (int) ($r['is_demo'] ?? 0), 'deleted' => (int) ($r['deleted'] ?? 0),
        'db' => $db, 'stripe_customer' => $cust, 'stripe_subscriptions' => $live['subs'], 'issues' => $issues,
    );
    if ($show_all || !empty($issues)) { $out[] = $entry; }
}

if ($as_json) { echo json_encode(array('checked' => count($rows), 'listed' => count($out), 'stripe_errors' => $errors, 'accounts' => $out), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n"; exit(0); }

echo gmdate('c'), ' checked ', count($rows), ' billing account(s), ', count($out), ' listed', $errors ? ", $errors Stripe error(s)" : '', "\n";
foreach ($out as $e) {
    echo "\n#", $e['user_id'], ' @', $e['handle'], ' <', $e['email'], '>', $e['demo'] ? ' [demo]' : '', $e['deleted'] ? ' [deleted]' : '', "\n";
    echo '  DB:     ', $e['db']['plan'], ' / ', $e['db']['status'], ' / period end ', ($e['db']['period_end'] ?: '-'), ' / next charge ', ($e['db']['next_charge'] ?: '-'), "\n";
    echo '  Stripe: ', $e['stripe_customer'] === '' ? 'no customer' : $e['stripe_customer'], "\n";
    foreach ($e['stripe_subscriptions'] as $s) {
        echo '          ', $s['id'], ' ', $s['status'], ' ', ($s['plan'] ?: $s['price_id']), ' period end ', ($s['period_end'] ?: '-'), $s['cancel_at_period_end'] ? ' (cancels at period end)' : '', "\n";
    }
    foreach ($e['issues'] as $i) { echo '  ! ', $i, "\n"; }
}
if (!$show_all && empty($out)) { echo "no mismatches\n"; }
