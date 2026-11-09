<?php
/**
 * ONE-OFF (2026-11-09): retire creator-to-fan selling after the code that sold things was deleted.
 * NOT for crontab. Dry run by default (prints what it would do); --apply acts. Safe to run twice:
 * every step only touches rows that are still open / published / not archived.
 *
 * Run on prod AFTER deploying the code that removes selling:
 *
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/retire_selling_once.php
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/retire_selling_once.php --apply
 *
 * What it does:
 *   a. Fan memberships: cancels every open paid membership in Stripe immediately (subscription on the
 *      creator's Connect account, Stripe-Account header), marks the row canceled and sends the fan an
 *      in-platform notice (Notify 'subscriptions'). Free memberships are just closed.
 *   b. Posts: every Subscribers-only / Pay-per-view post that is not archived becomes archived
 *      (never made public). Counts per creator.
 *   b2. Automations: active scheduler rules that posted Subscribers-only / Pay-per-view are paused
 *      (they would otherwise post publicly) and the creator gets a 'system' notice.
 *   c. Blog: unpublishes (status back to 'review', like the admin Unpublish button) the listed
 *      selling articles by slug, plus any published article whose title/slug matches the selling
 *      pattern (all printed for review first). Blog pages carry a 5-minute HTTP cache only.
 *   e. Adult content: media a moderator approved as adult goes back to 'flagged' (held by the content
 *      check); every published or scheduled post with flagged/adult media is archived; drafts held by
 *      the old age check get the content check's reason ('moderation'); adult scene templates are
 *      deleted (soft); the "Show adult content" toggle is turned off for everyone.
 *   d. REPORT ONLY, never pays or refunds: wallet balances, open payouts, negative balances,
 *      unreleased event/service earnings, upcoming paid tickets, open paid bookings.
 *
 * Depends only on the Stripe SDK, Main (config), Model/Database, RetireSellingModel, UsersModel,
 * Notify and PublicThumbService — none of the deleted selling classes.
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
$m     = new RetireSellingModel();
$line  = function ($s = '') { fwrite(STDOUT, $s . "\n"); };
$cr    = function ($c) { return number_format((int) $c) . ' cr ($' . number_format(((int) $c) / 10, 2) . ')'; };   // $1 = 10 credits

$line('Retire selling — ' . ($apply ? 'APPLY' : 'DRY RUN (add --apply to act)') . ' — ' . gmdate('Y-m-d H:i:s') . ' UTC, env ' . getenv('APPLICATION_ENV'));

// ---------------------------------------------------------------- a. memberships
$line(); $line('== a. Fan memberships');
try {
    $rows   = $m->open_memberships();
    $stripe = null;
    $done = 0; $fail = 0;
    foreach ($rows as $r) {
        $paid = (int) $r['is_free'] === 0 && (string) $r['stripe_subscription_id'] !== '';
        $desc = 'membership #' . (int) $r['id'] . ' fan ' . (int) $r['subscriber_id'] . ' -> @' . (string) $r['creator_handle']
              . ' (' . $r['status'] . ', ' . ($paid ? 'paid ' . $r['stripe_subscription_id'] . ' on ' . ($r['stripe_connect_account_id'] ?: 'NO CONNECT ACCOUNT') : 'free') . ')';
        if (!$apply) { $line('  would cancel ' . $desc); continue; }
        if ($paid) {
            try {
                if ($stripe === null) { $stripe = new \Stripe\StripeClient((string) Main::config(Main::get_environment(), 'stripe_secret_key')); }
                $opts = (string) $r['stripe_connect_account_id'] !== '' ? array('stripe_account' => (string) $r['stripe_connect_account_id']) : array();
                $sub  = $stripe->subscriptions->retrieve((string) $r['stripe_subscription_id'], array(), $opts);
                if ((string) $sub->status !== 'canceled') { $stripe->subscriptions->cancel((string) $r['stripe_subscription_id'], array(), $opts); }
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Already gone in Stripe (deleted account / subscription): nothing left to charge, close the row.
                $line('  note: Stripe says ' . $e->getMessage() . ' — closing the row');
            } catch (\Throwable $e) {
                $line('  FAILED ' . $desc . ': ' . $e->getMessage()); $fail++; continue;   // row stays open, a rerun retries it
            }
        }
        if ($m->close_membership((int) $r['id']) && $paid) {
            Notify::send((int) $r['subscriber_id'], 'subscriptions', 'Your membership has ended',
                'This creator no longer offers memberships, so your membership has ended and you will not be charged again.');
        }
        $line('  canceled ' . $desc); $done++;
    }
    $line('  open memberships: ' . count($rows) . ($apply ? ", canceled: $done, failed: $fail" : ''));
} catch (\Throwable $e) { $line('  n/a (' . $e->getMessage() . ')'); }

// ---------------------------------------------------------------- b. posts
$line(); $line('== b. Subscribers-only / Pay-per-view posts -> archived');
try {
    $posts = $m->paid_posts(); $per = array();
    foreach ($posts as $p) {
        $k = '@' . (string) $p['creator_handle'] . ' (' . (int) $p['creator_id'] . ')';
        if (!isset($per[$k])) { $per[$k] = array('subscribers' => 0, 'ppv' => 0); }
        $per[$k][(string) $p['audience']]++;
        if ($apply && $m->archive_post((int) $p['id']) && class_exists('PublicThumbService')) { PublicThumbService::queue_purge(array('post' => (int) $p['id'])); }
    }
    foreach ($per as $k => $c) { $line('  ' . $k . ': ' . $c['subscribers'] . ' subscribers-only, ' . $c['ppv'] . ' pay-per-view'); }
    $line('  ' . ($apply ? 'archived: ' : 'would archive: ') . count($posts));
} catch (\Throwable $e) { $line('  n/a (' . $e->getMessage() . ')'); }

$line(); $line('== b2. Automations posting Subscribers-only / Pay-per-view -> paused');
try {
    $rules = $m->paid_automations(); $n = 0;
    foreach ($rules as $r) {
        $line('  ' . ($apply ? 'paused' : 'would pause') . ' automation #' . (int) $r['id'] . ' @' . $r['creator_handle'] . ' "' . $r['name'] . '" (' . $r['audience'] . ')');
        if ($apply && $m->pause_automation((int) $r['id'])) {
            $n++;
            Notify::send((int) $r['creator_id'], 'system', 'An automation was paused',
                'Your automation "' . $r['name'] . '" made subscriber-only or pay-per-view posts, which are no longer offered. It is paused; turn it back on to post publicly.', '/studio');
        }
    }
    $line('  ' . ($apply ? "paused: $n of " : 'would pause: ') . count($rules));
} catch (\Throwable $e) { $line('  n/a (' . $e->getMessage() . ')'); }

// ---------------------------------------------------------------- c. blog
$line(); $line('== c. Selling blog articles -> unpublished (review)');
$slugs = array('how-to-price-a-subscription-tier', 'how-to-monetize-content-as-a-creator', 'onlyfans-alternative-how-to-choose',
    'fanvue-alternative-how-to-compare-and-switch', 'how-to-make-money-with-an-ai-influencer', 'ai-influencer-monetization',
    'monetize-digital-content', 'what-an-online-creator-platform-has-to-do', 'virtual-influencer-income', 'how-creators-get-paid-online',
    'how-to-sell-pay-per-view-content', 'link-in-bio-for-creators', 'best-place-to-sell-photos-online-free', 'how-can-i-sell-my-photos-online',
    'where-and-how-to-sell-content-online', 'selling-pay-per-view-content-pricing-and-fees', 'ai-influencer-monetization-methods-compared',
    // on-topic AI/social articles whose bodies still have selling lines (owner, 2026-11-09: unpublish them)
    'how-to-train-a-lora-character', 'ai-chat-for-creators', 'consistent-ai-character-across-images', 'lora-training-dataset-tips',
    'ai-chatter-for-fan-messages', 'best-ai-tools-consistent-ai-influencer-characters', 'lora-training-for-ai-influencers',
    'stable-diffusion-for-ai-influencers-or-skipping-it', 'free-vs-paid-tools-ai-influencer-creation', 'all-in-one-social-media-scheduler',
    'running-multiple-ai-influencers', 'managing-ai-influencers-for-clients', 'handling-fan-conversations-ai-influencer',
    'voice-and-personality-text-based-ai-interactions');
$pattern = 'onlyfans|fanvue|fansly|patreon|pay-per-view|pay per view|ppv|monetiz|get paid|payout';
try {
    $arts = $m->selling_articles($slugs, $pattern); $n = 0;
    foreach ($arts as $a) {
        $why = in_array((string) $a['slug'], $slugs, true) ? 'listed' : 'pattern match';
        $line('  ' . ($apply ? 'unpublished ' : 'would unpublish ') . '/blog/' . $a['slug'] . '  [' . $why . ']  ' . $a['title']);
        if ($apply && $m->unpublish_article((int) $a['id'])) { $n++; }
    }
    $line('  published selling articles: ' . count($arts) . ($apply ? ", unpublished: $n" : ''));
} catch (\Throwable $e) { $line('  n/a (' . $e->getMessage() . ')'); }

// ---------------------------------------------------------------- e. adult content
$line(); $line('== e. Adult content');
try {
    $media = $m->approved_adult_media(); $n = 0;
    if ($apply) { foreach ($media as $r) { if ($m->flag_media((int) $r['id'])) { $n++; PublicThumbService::queue_purge(array('assets' => array((int) $r['id']))); } } }
    $line('  media approved as adult -> flagged: ' . ($apply ? "$n of " : 'would flag ') . count($media));
} catch (\Throwable $e) { $line('  media: n/a (' . $e->getMessage() . ')'); }
try {
    $posts = $m->adult_posts(); $per = array(); $n = 0;
    foreach ($posts as $p) {
        $k = '@' . (string) $p['creator_handle'] . ' (' . (int) $p['creator_id'] . ')';
        $per[$k] = ($per[$k] ?? 0) + 1;
        if ($apply && $m->archive_post((int) $p['id'])) { $n++; PublicThumbService::queue_purge(array('post' => (int) $p['id'])); }
    }
    foreach ($per as $k => $c) { $line('  ' . $k . ': ' . $c . ' post' . ($c === 1 ? '' : 's') . ' with adult media'); }
    $line('  live/scheduled posts with adult media -> archived: ' . ($apply ? "$n of " : 'would archive ') . count($posts));
} catch (\Throwable $e) { $line('  posts: n/a (' . $e->getMessage() . ')'); }
try {
    $held = $m->age_held_posts(); $n = 0;
    if ($apply) { foreach ($held as $r) { if ($m->reheld_post((int) $r['id'])) { $n++; } } }
    $line('  drafts held by the age check -> held by the content check: ' . ($apply ? "$n of " : 'would move ') . count($held));
} catch (\Throwable $e) { $line('  held drafts: n/a (' . $e->getMessage() . ')'); }
try {
    $scenes = $m->adult_scenes(); $n = 0;
    foreach ($scenes as $r) {
        $line('  ' . ($apply ? 'deleted' : 'would delete') . ' scene #' . (int) $r['id'] . ' "' . $r['title'] . '" (' . ($r['creator_id'] === null ? 'starter' : 'creator ' . (int) $r['creator_id']) . ')');
        if ($apply && $m->delete_scene((int) $r['id'])) { $n++; }
    }
    $line('  adult scene templates -> deleted: ' . ($apply ? "$n of " : 'would delete ') . count($scenes));
} catch (\Throwable $e) { $line('  scenes: n/a (' . $e->getMessage() . ')'); }
try {
    $on = $m->adult_toggle_on_count();
    $off = $apply ? $m->adult_toggle_off_all() : 0;
    $line('  accounts with "Show adult content" on -> off: ' . ($apply ? "$off of " : 'would turn off ') . $on);
} catch (\Throwable $e) { $line('  adult toggle: n/a (' . $e->getMessage() . ')'); }

// ---------------------------------------------------------------- d. report
$line(); $line('== d. REPORT ONLY (nothing is paid or refunded)');
$section = function ($title, callable $fn, callable $fmt) use ($line) {
    $line('-- ' . $title);
    try {
        $rows = $fn();
        if (!$rows) { $line('  none'); return; }
        foreach ($rows as $r) { $line('  ' . $fmt($r)); }
    } catch (\Throwable $e) { $line('  n/a (' . $e->getMessage() . ')'); }
};
$sum = function (array $rows, $col) { $t = 0; foreach ($rows as $r) { $t += (int) $r[$col]; } return $t; };

$section('Creators with a wallet balance (owed payouts)', function () use ($m, $line, $sum, $cr) {
    $r = $m->wallet_balances(true); if ($r) { $line('  total ' . $cr($sum($r, 'credit_balance')) . ' across ' . count($r)); } return $r;
}, function ($r) use ($cr) { return '@' . $r['u_name'] . ' (' . (int) $r['user_id'] . '): ' . $cr($r['credit_balance']); });
$section('Fans with a wallet balance', function () use ($m, $line, $sum, $cr) {
    $r = $m->wallet_balances(false); if ($r) { $line('  total ' . $cr($sum($r, 'credit_balance')) . ' across ' . count($r)); } return $r;
}, function ($r) use ($cr) { return '@' . $r['u_name'] . ' (' . (int) $r['user_id'] . '): ' . $cr($r['credit_balance']); });
$section('Payouts not paid/failed/canceled', function () use ($m) { return $m->open_payouts(); },
    function ($r) { return 'payout #' . (int) $r['id'] . ' @' . $r['u_name'] . ' $' . number_format(((int) $r['amount_cents']) / 100, 2) . ' ' . $r['status'] . ' since ' . $r['created_at']; });
$section('Negative wallet balances', function () use ($m) { return $m->negative_balances(); },
    function ($r) use ($cr) { return '@' . $r['u_name'] . ' (' . (int) $r['user_id'] . '): ' . $cr($r['credit_balance']); });
$section('Unreleased event earnings', function () use ($m) { return $m->unreleased_event_earnings(); },
    function ($r) use ($cr) { return 'creator ' . (int) $r['creator_id'] . ': ' . (int) $r['n'] . ' registrations, ' . $cr($r['credits']); });
$section('Upcoming paid event tickets', function () use ($m) { return $m->upcoming_paid_tickets(); },
    function ($r) use ($cr) { return 'event #' . (int) $r['event_id'] . ' (creator ' . (int) $r['creator_id'] . ') ' . $r['title'] . ': ' . (int) $r['n'] . ' tickets, ' . $cr($r['credits']); });
$section('Unreleased service earnings', function () use ($m) { return $m->unreleased_service_earnings(); },
    function ($r) use ($cr) { return 'creator ' . (int) $r['creator_id'] . ': ' . (int) $r['n'] . ' purchases, ' . $cr($r['credits']); });
$section('Open paid service bookings (not delivered)', function () use ($m) { return $m->open_paid_bookings(); },
    function ($r) use ($cr) { return 'booking #' . (int) $r['id'] . ' creator ' . (int) $r['creator_id'] . ' buyer ' . (int) $r['buyer_id'] . ': ' . $cr($r['price_credits']) . ' since ' . $r['created_at']; });

$line(); $line($apply ? 'Done.' : 'Dry run only. Re-run with --apply to act.');
