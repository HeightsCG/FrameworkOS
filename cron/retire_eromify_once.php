<?php
/**
 * ONE-OFF (2026-11-09): retire Eromify "My Character" automations after the Eromify integration was removed.
 * NOT for crontab. Dry run by default (prints what it would do); --apply acts. Safe to run twice: it only
 * touches rules whose image_source is still 'character'.
 *
 * Run on prod AFTER deploying the code that removes Eromify, and BEFORE sql/2026-11-09_drop_eromify.sql:
 *
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/retire_eromify_once.php
 *   APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/retire_eromify_once.php --apply
 *
 * What it does: every scheduler rule with image_source 'character' is switched to Brand Photo and paused.
 * Creators whose rule was active get one in-platform 'system' notice linking to the automation.
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
$line  = function ($s = '') { fwrite(STDOUT, $s . "\n"); };

$line('Retire Eromify — ' . ($apply ? 'APPLY' : 'DRY RUN (add --apply to act)') . ' — ' . gmdate('Y-m-d H:i:s') . ' UTC, env ' . getenv('APPLICATION_ENV'));

$m = new SchedulerRulesModel();
$rules = $m->character_rules();
$done = 0; $active = 0;
foreach ($rules as $r) {
    $was_active = (int) $r['active'] === 1;
    if ($was_active) { $active++; }
    $line('  ' . ($apply ? 'retired' : 'would retire') . ' automation #' . (int) $r['id'] . ' @' . (string) $r['creator_handle']
        . ' "' . (string) $r['name'] . '" (' . ($was_active ? 'active -> paused' : 'already paused') . ', source -> brand)');
    if ($apply && $m->retire_character_rule((int) $r['id'])) {
        $done++;
        if ($was_active) {
            Notify::send((int) $r['creator_id'], 'system', 'An automation was paused',
                'Eromify characters are no longer supported, so your automation "' . (string) $r['name'] . '" was paused. Pick Brand Photo or an Influencer as its source, then turn it back on.',
                '/studio#automation-' . (int) $r['id']);
        }
    }
}
$line('  character rules: ' . count($rules) . ' (' . $active . ' active)' . ($apply ? ", retired: $done" : ''));
