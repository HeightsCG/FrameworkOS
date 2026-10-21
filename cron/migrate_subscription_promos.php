<?php
/**
 * One-time repair (2026-10-21). cron/migrate_subscriptions.php moved plans to app billing without the coupon on the old
 * Stripe subscription, so a migrated account with a free or discounted plan was billed full price at its first app
 * renewal. For every migrated account this reads the old subscription's discount (and the customer's) from Stripe,
 * stores what is left of it on the account (billing_accounts promo fields, as a promo code entered at checkout would),
 * and lists the renewals it already paid in full, to refund in Stripe.
 *
 *   APPLICATION_ENV=production php cron/migrate_subscription_promos.php                 dry run: prints, changes nothing
 *   APPLICATION_ENV=production php cron/migrate_subscription_promos.php --apply --live  stores the promos
 *   ... --user=123                                                                       only that account
 *
 * Never refunds anything itself. Safe to run again: accounts that already have a promo are left alone.
 */

if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');
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
$only = 0;
foreach ($argv as $arg) { if (strpos($arg, '--user=') === 0) { $only = (int) substr($arg, 7); } }

$c       = StripeService::client();
$accts   = new BillingAccountsModel();
$charges = new BillingChargesModel();
$rows    = array_values(array_filter($accts->migrated(), function ($r) use ($only) { return $only === 0 || (int) $r['user_id'] === $only; }));
echo ($apply ? 'REPAIRING' : 'DRY RUN') . ' (' . ($live ? 'live' : 'test') . ' keys) — ' . count($rows) . " migrated account(s)\n";

$found = 0; $refund = 0;
foreach ($rows as $r) {
    $uid = (int) $r['user_id'];
    $who = "user $uid (@" . $r['u_name'] . ', ' . $r['user_email'] . ', ' . $r['plan_key'] . '/' . $r['status'] . ')';
    try {
        $sub = $c->subscriptions->retrieve((string) $r['migrated_subscription_id'], array('expand' => array('discounts', 'customer')));
        $promo = StripeService::subscription_promo($sub, $sub->customer, (string) ($r['next_charge_at'] ?: gmdate('Y-m-d H:i:s')));
        if (!$promo) { continue; }   // no coupon on the old subscription: nothing was lost
        $found++;
        $left = $promo['promo_periods_left'] === null ? 'forever' : $promo['promo_periods_left'] . ' more charge(s)';
        echo "$who: Stripe coupon {$promo['promo_code']} ({$promo['label']}), $left\n";

        // Renewals billed without it.
        foreach ($charges->paid_renewals($uid) as $ch) {
            if (strpos((string) $ch['line_items'], 'Promo ') !== false) { continue; }
            $refund++;
            echo "    charged " . BillingService::money((int) $ch['amount_cents']) . ' on ' . substr((string) $ch['created_at'], 0, 10) . " (charge {$ch['id']}, " . ($ch['stripe_payment_intent_id'] ?: 'no payment intent') . ") without the coupon: refund in Stripe\n";
        }

        if ((string) ($r['promo_code'] ?? '') !== '') { echo "    account already has promo {$r['promo_code']}: left as is\n"; continue; }
        if (!in_array((string) $r['status'], array('active', 'past_due'), true) || (string) $r['plan_key'] === 'free') { echo "    not on a paid plan now: nothing stored\n"; continue; }
        if ($apply) {
            $accts->save($uid, array_diff_key($promo, array('label' => 1)));
            echo "    promo stored: the next renewal gets it\n";
        } else {
            echo "    would store the promo\n";
        }
    } catch (\Throwable $e) {
        echo "$who: ERROR " . $e->getMessage() . "\n";
    }
}
echo "$found account(s) with a coupon, $refund renewal(s) to refund\n";
