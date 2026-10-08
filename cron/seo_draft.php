<?php
/**
 * SEO content engine — draft ONE article per run from the top queued keyword (spec §5).
 * Prod: 0 9 * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/seo_draft.php >> /var/www/creatorlinkstudio.com/www/cron/seo_draft.log 2>&1
 * Options: --keyword-id=N (draft that keyword regardless of status), --seed (insert the starter keyword list only).
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
$opts = getopt('', array('keyword-id::', 'seed'));
$lock = fopen(sys_get_temp_dir() . '/cls-seo-draft.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo date('c'), " another run is active\n"; exit(0); }

CronRuns::start('seo_draft');
$keywords = new SeoKeywordsModel();
$seed = array(
    // keyword, monthly volume, difficulty, priority, topic cluster (SeoDrafter::CLUSTERS)
    array('how to monetize content as a creator', 1900, 'doable', 10, 'ai-influencer-monetization'),
    array('creator monetization platform', 880, 'hard', 20, 'platform-comparisons'),
    array('onlyfans alternative', 6600, 'hard', 15, 'platform-comparisons'),
    array('fanvue alternative', 720, 'doable', 15, 'platform-comparisons'),
    array('how to sell pay-per-view content', 320, 'easy', 30, 'ai-influencer-monetization'),
    array('creator membership tiers', 260, 'easy', 30, 'ai-influencer-monetization'),
    array('link in bio for creators', 1300, 'doable', 40, 'platform-comparisons'),
    array('best link in bio for creators', 590, 'doable', 45, 'platform-comparisons'),
    array('ai influencer content', 480, 'doable', 50, 'ai-influencer-monetization'),
    array('creator payouts stripe', 140, 'easy', 60, 'creator-payouts'),
    array('how to price a subscription tier', 210, 'easy', 25, 'ai-influencer-monetization'),
    array('cross-post to social media from one place', 390, 'easy', 35, 'platform-comparisons'),
    array('monetize digital content', 700, 'doable', 20, 'ai-influencer-monetization'),
    array('monetize online content', 590, 'doable', 22, 'ai-influencer-monetization'),
    array('online creator platform', 1000, 'hard', 24, 'platform-comparisons'),
    // creator-side clusters the growth plan targets
    array('how to make money with an ai influencer', 590, 'doable', 12, 'ai-influencer-monetization'),
    array('ai influencer monetization', 320, 'doable', 14, 'ai-influencer-monetization'),
    array('virtual influencer income', 210, 'easy', 26, 'ai-influencer-monetization'),
    array('ai chat for creators', 480, 'doable', 18, 'ai-dm-chatter'),
    array('automated dm replies for creators', 260, 'easy', 20, 'ai-dm-chatter'),
    array('ai chatter for fan messages', 170, 'easy', 28, 'ai-dm-chatter'),
    array('how to sell content in dms', 320, 'doable', 30, 'ai-dm-chatter'),
    array('how to train a lora character', 880, 'doable', 16, 'lora-character-training'),
    array('lora training dataset tips', 390, 'doable', 24, 'lora-character-training'),
    array('consistent ai character across images', 720, 'doable', 18, 'lora-character-training'),
    array('flux lora training', 1300, 'hard', 22, 'lora-character-training'),
    array('how creators get paid online', 590, 'doable', 26, 'creator-payouts'),
    array('creator payout schedule', 210, 'easy', 34, 'creator-payouts'),
    array('taxes for online creators', 1600, 'hard', 40, 'creator-payouts'),
    array('chargebacks for digital content', 170, 'easy', 42, 'creator-payouts'),
);
$added = $keywords->seed($seed);
if ($added > 0) { echo date('c'), " seeded $added keyword(s)\n"; }
if (isset($opts['seed'])) { CronRuns::finish('seo_draft', true, 'seeded'); exit(0); }

$stale = $keywords->requeue_stale(30);
if ($stale > 0) { echo date('c'), " requeued $stale stale drafting keyword(s)\n"; }

$kw = null;
if (!empty($opts['keyword-id'])) { $kw = $keywords->get((int) $opts['keyword-id']); }
else { $kw = $keywords->next_queued(); }
if (!$kw) { echo date('c'), " nothing queued\n"; CronRuns::finish('seo_draft', true, 'nothing queued'); exit(0); }
if (!ClaudeService::configured()) { echo date('c'), " Claude not configured\n"; CronRuns::finish('seo_draft', false, 'Claude not configured'); exit(1); }

echo date('c'), " drafting \"{$kw['keyword']}\" (#{$kw['id']})\n";
$t0 = microtime(true);
$r  = SeoDrafter::draft($kw);
printf("%s %s in %.1fs%s\n", date('c'), $r['ok'] ? "drafted article #{$r['article_id']}" : 'FAILED', microtime(true) - $t0, $r['ok'] ? '' : ' — ' . $r['error']);
CronRuns::finish('seo_draft', (bool) $r['ok'], $r['ok'] ? 'article #' . $r['article_id'] : (string) $r['error']);
exit($r['ok'] ? 0 : 1);
