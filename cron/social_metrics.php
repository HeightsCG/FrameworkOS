<?php
/**
 * Social metrics sync — walks every connected social account, pulls its recent feed with
 * engagement metrics from Post for Me, and upserts one row per platform post into
 * social_post_metrics. Items that went out through us are tied back to the studio post.
 * Cron (prod), every 6 hours:
 *
 *   0 * /6 * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/social_metrics.php >> /var/www/creatorlinkstudio.com/www/cron/social_metrics.log 2>&1
 *
 * (Remove the space in "* /6" — it's only there to keep this comment block valid PHP.)
 * Dev runs it from the com.creatorlinkstudio.social-metrics launchd agent.
 *
 * Manual run for one account:  php cron/social_metrics.php spc_xxxxxxxx
 * Counters are lifetime totals, so a few refreshes a day is plenty.
 */

if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');   // same as Bootstrap: every stored timestamp is UTC, whatever the server's clock is set to

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});

const PAGES_PER_ACCOUNT = 3;   // 3 × 50 = the 150 most recent posts per account per run

CronRuns::start('social_metrics');
$only     = isset($argv[1]) ? (string) $argv[1] : '';
$accounts = new SocialAccountsModel();
$metrics  = new SocialPostMetricsModel();

$rows = $only !== ''
    ? array_filter(array($accounts->get_by_pfm_id($only)))
    : (array) $accounts->select("SELECT * FROM user_social_accounts WHERE status = 'connected' ORDER BY user_id, platform");

if (empty($rows)) { CronRuns::finish('social_metrics', true, 'no accounts'); exit(0); }

$post_maps = array();
foreach ($rows as $acct) {
    $uid  = (int) $acct['user_id'];
    $pfm  = (string) $acct['post_for_me_social_account_id'];
    $plat = (string) $acct['platform'];
    if (!isset($post_maps[$uid])) { $post_maps[$uid] = $metrics->pfm_to_post_map($uid); }

    $cursor = null; $seen = 0; $linked = 0; $err = '';
    for ($page = 0; $page < PAGES_PER_ACCOUNT; $page++) {
        $feed = PostForMeService::get_feed($pfm, $cursor, 50);
        if (isset($feed['_error'])) { $err = $feed['_error']; break; }
        foreach ($feed['data'] as $item) {
            $sp  = (string) ($item['social_post_id'] ?? '');
            $pid = ($sp !== '' && isset($post_maps[$uid][$sp])) ? $post_maps[$uid][$sp] : null;
            if ($metrics->upsert_feed_item($uid, $plat, $item, $pid) !== false) {
                $seen++;
                if ($pid !== null) { $linked++; }
            }
        }
        if (empty($feed['has_more'])) { break; }
        $cursor = $feed['cursor'];
    }
    fwrite(STDOUT, date('c') . " user {$uid} {$plat} {$pfm}: {$seen} posts, {$linked} linked to studio posts"
        . ($err !== '' ? " — {$err}" : '') . "\n");
}

CronRuns::finish('social_metrics', true, count($rows) . ' account(s)');
exit(0);
