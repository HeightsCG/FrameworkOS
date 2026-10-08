<?php
/**
 * SEO content engine — draft ONE article per run from the top queued keyword (spec §5).
 * Prod: 0 9 * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/seo_draft.php >> /var/www/creatorlinkstudio.com/www/cron/seo_draft.log 2>&1
 * Options: --keyword-id=N (draft that keyword regardless of status), --seed (insert the starter keyword list only),
 *   --load-queue (once, 2026-10-09: re-cluster keywords and articles, load the P1/P2/P3 queue below, print counts),
 *   --dry-run (one Claude call, no cover, saved as a draft article and never published; prints the validator output).
 * Picks the queued keyword with the lowest priority (P1 = 1, P2 = 2, everything else 3), oldest first.
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
$opts = getopt('', array('keyword-id::', 'seed', 'load-queue', 'dry-run'));
if (!CronRuns::lock('seo_draft')) { echo date('c'), " another run is active\n"; exit(0); }

CronRuns::start('seo_draft');
$keywords = new SeoKeywordsModel();
$seed = array(
    // keyword, monthly volume, difficulty, priority, topic cluster (SeoDrafter::CLUSTERS)
    array('how to monetize content as a creator', 1900, 'doable', 10, 'creator-monetization'),
    array('creator monetization platform', 880, 'hard', 20, 'platform-comparisons'),
    array('onlyfans alternative', 6600, 'hard', 15, 'platform-comparisons'),
    array('fanvue alternative', 720, 'doable', 15, 'platform-comparisons'),
    array('how to sell pay-per-view content', 320, 'easy', 30, 'memberships-and-ppv'),
    array('creator membership tiers', 260, 'easy', 30, 'memberships-and-ppv'),
    array('link in bio for creators', 1300, 'doable', 40, 'creator-monetization'),
    array('best link in bio for creators', 590, 'doable', 45, 'platform-comparisons'),
    array('ai influencer content', 480, 'doable', 50, 'ai-influencer-monetization'),
    array('how to price a subscription tier', 210, 'easy', 25, 'memberships-and-ppv'),
    array('cross-post to social media from one place', 390, 'easy', 35, 'social-publishing'),
    array('monetize digital content', 700, 'doable', 20, 'creator-monetization'),
    array('monetize online content', 590, 'doable', 22, 'creator-monetization'),
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

if (isset($opts['load-queue'])) {
    // the topic queue, in drafting order: P1 = 1, P2 = 2, then long-tail variants, questions and comparisons at 3
    $queue = array(
        // P1 (Daniel's list, in his order)
        array('Best AI tools for consistent AI influencer characters', null, 'doable', 1, 'lora-character-training'),
        array('LoRA training for AI influencers', null, 'doable', 1, 'lora-character-training'),
        array('Custom AI influencer training, when to invest', null, 'doable', 1, 'lora-character-training'),
        array('Character consistency techniques', null, 'doable', 1, 'lora-character-training'),
        array('AI image tools that actually work for influencers', null, 'doable', 1, 'ai-influencer-monetization'),
        array('Stable Diffusion for AI influencers, or skipping it', null, 'doable', 1, 'lora-character-training'),
        array('Free vs paid tools for AI influencer creation', null, 'doable', 1, 'ai-influencer-monetization'),
        array('Running multiple AI influencers', null, 'doable', 1, 'ai-influencer-monetization'),
        array('Managing AI influencers for clients', null, 'doable', 1, 'ai-influencer-monetization'),
        array('Handling fan conversations for an AI influencer', null, 'doable', 1, 'ai-dm-chatter'),
        array('Voice and personality for text-based AI interactions', null, 'doable', 1, 'ai-dm-chatter'),
        array('Making money with an AI influencer without OnlyFans', null, 'doable', 1, 'ai-influencer-monetization'),
        array('AI influencer monetization methods compared', null, 'doable', 1, 'ai-influencer-monetization'),
        array('Multiple revenue streams from one AI influencer', null, 'doable', 1, 'ai-influencer-monetization'),
        array('How to price AI influencer content and subscriptions', null, 'doable', 1, 'ai-influencer-monetization'),
        array('Getting fans to pay more', null, 'doable', 1, 'memberships-and-ppv'),
        array('Writing engaging prompts for AI influencer content', null, 'doable', 1, 'ai-influencer-monetization'),
        // P2 (Daniel's list, in his order)
        array('Starting an AI influencer from zero', null, 'doable', 2, 'ai-influencer-monetization'),
        array('What it costs to create an AI influencer', null, 'doable', 2, 'ai-influencer-monetization'),
        array('AI influencer business plan', null, 'doable', 2, 'ai-influencer-monetization'),
        array('How often to post as an AI influencer', null, 'doable', 2, 'social-publishing'),
        array('AI influencer analytics that track what converts', null, 'doable', 2, 'ai-influencer-monetization'),
        array('Getting your first 1,000 followers', null, 'doable', 2, 'social-publishing'),
        // P3: long-tail variants, questions, comparisons
        array('how much should i charge for a membership', null, 'easy', 3, 'memberships-and-ppv'),
        array('how many membership tiers should a creator have', null, 'easy', 3, 'memberships-and-ppv'),
        array('free trial for creator memberships', null, 'easy', 3, 'memberships-and-ppv'),
        array('promo codes for creator subscriptions', null, 'easy', 3, 'memberships-and-ppv'),
        array('how to price pay-per-view posts', null, 'easy', 3, 'memberships-and-ppv'),
        array('paid dms for creators', null, 'easy', 3, 'memberships-and-ppv'),
        array('pay per view vs subscription', null, 'doable', 3, 'memberships-and-ppv'),
        array('how to keep members from cancelling', null, 'doable', 3, 'memberships-and-ppv'),
        array('what is a creator paywall', null, 'easy', 3, 'memberships-and-ppv'),
        array('how to sell content bundles', null, 'easy', 3, 'creator-monetization'),
        array('how to sell digital downloads as a creator', null, 'doable', 3, 'creator-monetization'),
        array('how to sell 1:1 video calls', null, 'easy', 3, 'creator-monetization'),
        array('how to run a paid live event', null, 'easy', 3, 'creator-monetization'),
        array('how to monetize a small audience', null, 'doable', 3, 'creator-monetization'),
        array('how to monetize a podcast audience', null, 'doable', 3, 'creator-monetization'),
        array('beauty creator monetization', null, 'doable', 3, 'creator-monetization'),
        array('how coaches can sell sessions online', null, 'doable', 3, 'creator-monetization'),
        array('how musicians can sell memberships', null, 'doable', 3, 'creator-monetization'),
        array('tracked links for creators', null, 'easy', 3, 'creator-monetization'),
        array('what should a creator link in bio include', null, 'easy', 3, 'creator-monetization'),
        array('how to schedule social media posts as a creator', null, 'doable', 3, 'social-publishing'),
        array('ai captions for social media posts', null, 'doable', 3, 'social-publishing'),
        array('how often should creators post', null, 'doable', 3, 'social-publishing'),
        array('cross-posting without losing reach', null, 'easy', 3, 'social-publishing'),
        array('how to get paid as a content creator', null, 'doable', 3, 'creator-payouts'),
        array('how long do creator payouts take', null, 'easy', 3, 'creator-payouts'),
        array('minimum payout for creators', null, 'easy', 3, 'creator-payouts'),
        array('how creators handle refunds and disputes', null, 'easy', 3, 'creator-payouts'),
        array('creator income records for taxes', null, 'doable', 3, 'creator-payouts'),
        array('what fees do creator platforms charge', null, 'doable', 3, 'pricing-and-fees'),
        array('platform fee vs card processing fee', null, 'easy', 3, 'pricing-and-fees'),
        array('is a paid creator plan worth it', null, 'easy', 3, 'pricing-and-fees'),
        array('how to compare creator platform pricing', null, 'easy', 3, 'pricing-and-fees'),
        array('onlyfans alternatives for non-adult creators', null, 'doable', 3, 'platform-comparisons'),
        array('patreon vs onlyfans for creators', null, 'doable', 3, 'platform-comparisons'),
        array('linktree alternative with payments', null, 'doable', 3, 'platform-comparisons'),
        array('best platform to sell content subscriptions', null, 'hard', 3, 'platform-comparisons'),
        array('how to move subscribers to a new platform', null, 'doable', 3, 'platform-comparisons'),
        array('how to create an ai influencer', null, 'doable', 3, 'ai-influencer-monetization'),
        array('ai influencer platform', null, 'doable', 3, 'ai-influencer-monetization'),
        array('how to label ai generated content', null, 'easy', 3, 'ai-influencer-monetization'),
        array('ai influencer content ideas', null, 'easy', 3, 'ai-influencer-monetization'),
        array('how many images to train a lora', null, 'easy', 3, 'lora-character-training'),
        array('how to keep an ai face consistent', null, 'doable', 3, 'lora-character-training'),
        array('lora vs reference images for ai characters', null, 'doable', 3, 'lora-character-training'),
        array('ai dm replies for fan messages', null, 'easy', 3, 'ai-dm-chatter'),
        array('how to write welcome messages for new subscribers', null, 'easy', 3, 'ai-dm-chatter'),
        array('should creators use ai chatters', null, 'easy', 3, 'ai-dm-chatter'),
    );
    // rows this loader may demote to 3 when they are no longer listed: the starter seed and the first (audit) P1/P2 list
    $demotable = array_merge(array_column($seed, 0), array(
        'creator subscription platform', 'how to sell content subscriptions', 'creator paywall', 'link in bio with payments', 'link in bio for creators',
        'how to sell coaching from a creator page', 'sell tickets to live sessions', 'creator platform fees compared', 'patreon alternative', 'fansly alternative',
        'how creators get paid on subscription platforms', 'creator membership tiers', 'sell photo sets online', 'custom domain for a creator page',
        'cross-post to social media from one place', 'stan store alternative', 'beacons alternative', 'ko-fi alternative', 'fitness creator monetization', 'ai influencer generator',
    ), array_column($queue, 0));
    $before = $keywords->cluster_counts();
    $moved = $keywords->recluster();
    list($q_added, $q_updated, $q_rested) = $keywords->load_queue($queue, 3, $demotable, array_combine(array_column($seed, 0), array_column($seed, 3)));
    foreach ($keywords->all() as $k) {   // names the payment processor, which no article may do
        if ((string) $k['keyword'] === 'creator payouts stripe' && (string) $k['status'] === 'queued') { $keywords->set_status((int) $k['id'], 'skipped', null, 'keyword names the payment processor'); }
    }
    $after = $keywords->cluster_counts();
    echo date('c'), " re-clustered $moved row(s); queue: $q_added added, $q_updated re-prioritised, $q_rested set to priority 3\n";
    echo "cluster counts (keywords, all statuses) before -> after:\n";
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $c) { printf("  %-28s %3d -> %3d\n", $c === '' ? '(none)' : $c, $before[$c] ?? 0, $after[$c] ?? 0); }
    echo "first 25 in the queue:\n";
    foreach ($keywords->queue(25) as $i => $q) { printf("  %2d. P%d #%d %s [%s]\n", $i + 1, $q['priority'], $q['id'], $q['keyword'], $q['cluster']); }
    CronRuns::finish('seo_draft', true, 'queue loaded');
    exit(0);
}

$stale = $keywords->requeue_stale(30);
if ($stale > 0) { echo date('c'), " requeued $stale stale drafting keyword(s)\n"; }

$kw = null;
if (!empty($opts['keyword-id'])) { $kw = $keywords->get((int) $opts['keyword-id']); }
else { $kw = $keywords->next_queued(); }
if (!$kw) { echo date('c'), " nothing queued\n"; CronRuns::finish('seo_draft', true, 'nothing queued'); exit(0); }
if (!ClaudeService::configured()) { echo date('c'), " Claude not configured\n"; CronRuns::finish('seo_draft', false, 'Claude not configured'); exit(1); }

echo date('c'), " drafting \"{$kw['keyword']}\" (#{$kw['id']})\n";
$t0 = microtime(true);
$r  = SeoDrafter::draft($kw, '', 0, isset($opts['dry-run']));
printf("%s %s in %.1fs%s\n", date('c'), $r['ok'] ? "drafted article #{$r['article_id']}" . (isset($opts['dry-run']) ? ' (dry run, saved as a draft)' : '') : 'FAILED' . (!empty($r['article_id']) ? " (draft #{$r['article_id']} kept for reading)" : ''), microtime(true) - $t0, $r['ok'] ? '' : ': ' . $r['error']);
CronRuns::finish('seo_draft', (bool) $r['ok'], $r['ok'] ? 'article #' . $r['article_id'] : (string) $r['error']);
exit($r['ok'] ? 0 : 1);
