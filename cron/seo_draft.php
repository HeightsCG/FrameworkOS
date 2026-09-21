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

$keywords = new SeoKeywordsModel();
$seed = array(
    array('how to monetize content as a creator', 1900, 'doable', 10), array('creator monetization platform', 880, 'hard', 20),
    array('onlyfans alternative', 6600, 'hard', 15), array('fanvue alternative', 720, 'doable', 15),
    array('how to sell pay-per-view content', 320, 'easy', 30), array('creator membership tiers', 260, 'easy', 30),
    array('link in bio for creators', 1300, 'doable', 40), array('best link in bio for creators', 590, 'doable', 45),
    array('ai influencer content', 480, 'doable', 50), array('creator payouts stripe', 140, 'easy', 60),
    array('how to price a subscription tier', 210, 'easy', 25), array('cross-post to social media from one place', 390, 'easy', 35),
    array('monetize digital content', 700, 'doable', 20), array('monetize online content', 590, 'doable', 22), array('online creator platform', 1000, 'hard', 24),
);
$added = $keywords->seed($seed);
if ($added > 0) { echo date('c'), " seeded $added keyword(s)\n"; }
if (isset($opts['seed'])) { exit(0); }

$stale = $keywords->requeue_stale(30);
if ($stale > 0) { echo date('c'), " requeued $stale stale drafting keyword(s)\n"; }

$kw = null;
if (!empty($opts['keyword-id'])) { $kw = $keywords->get((int) $opts['keyword-id']); }
else { $kw = $keywords->next_queued(); }
if (!$kw) { echo date('c'), " nothing queued\n"; exit(0); }
if (!ClaudeService::configured()) { echo date('c'), " Claude not configured\n"; exit(1); }

echo date('c'), " drafting \"{$kw['keyword']}\" (#{$kw['id']})\n";
$t0 = microtime(true);
$r  = SeoDrafter::draft($kw);
printf("%s %s in %.1fs%s\n", date('c'), $r['ok'] ? "drafted article #{$r['article_id']}" : 'FAILED', microtime(true) - $t0, $r['ok'] ? '' : ' — ' . $r['error']);
exit($r['ok'] ? 0 : 1);
