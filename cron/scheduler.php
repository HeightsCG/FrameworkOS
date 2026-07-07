<?php
/**
 * Scheduler worker — runs due creator automations (generate image + caption, publish,
 * cross-post). Invoke from cron every minute:
 *
 *   * * * * * /opt/homebrew/opt/php@8.2/bin/php /private/var/www/contentos.cvk/framework/bin/scheduler.php >> /tmp/cls-scheduler.log 2>&1
 *
 * Idle and near-instant when nothing is due. Generation only runs for actually-due rules.
 */

if (php_sapi_name() !== 'cli') { exit(1); }

// Match the web app's environment so Database picks the right app.ini section.
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array(
        "$root/app/models/$class.php",
        "$root/libs/Classes/$class.php",
        "$root/app/$class.php",
    ) as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});

$rulesM = new SchedulerRulesModel();
$runsM  = new SchedulerRunsModel();
$usersM = new UsersModel();

$now = gmdate('Y-m-d H:i:s');
$due = $rulesM->due_rules($now);
if (empty($due)) { exit(0); }

foreach ($due as $rule) {
    // Advance next_run_at BEFORE executing, so an overlapping tick (or the next
    // minute's cron) can't pick the same rule up again mid-run.
    try {
        $rulesM->set_next_run((int) $rule['id'], $rulesM->compute_next_run($rule));
    } catch (\Throwable $e) {
        error_log('[scheduler] next-run calc failed for rule ' . $rule['id'] . ': ' . $e->getMessage());
    }

    try {
        $rows = $usersM->get_user_by_id((int) $rule['creator_id']);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$user) { throw new RuntimeException('creator ' . $rule['creator_id'] . ' not found'); }

        $res = AutoPostService::run_rule($rule, $user);
        $runsM->add((int) $rule['id'], (int) $rule['creator_id'], $res['ok'] ? 'success' : 'failed', $res['post_id'], $res['message']);
        $rulesM->set_last_run((int) $rule['id'], $res['ok'] ? 'success' : 'failed');
        fwrite(STDOUT, date('c') . " rule {$rule['id']} \"{$rule['name']}\": " . ($res['ok'] ? 'OK' : 'FAIL') . ' — ' . $res['message'] . "\n");
    } catch (\Throwable $e) {
        $runsM->add((int) $rule['id'], (int) $rule['creator_id'], 'failed', null, 'Worker error: ' . $e->getMessage());
        $rulesM->set_last_run((int) $rule['id'], 'failed');
        error_log('[scheduler] rule ' . $rule['id'] . ' failed: ' . $e->getMessage());
    }
}

exit(0);
