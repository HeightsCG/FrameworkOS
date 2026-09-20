<?php
/**
 * Queue worker — runs background jobs dispatched by the web app through DatabaseJobQueue.
 * Claims jobs until the queue has been idle for --idle-exit seconds (default 50) or --max-jobs
 * (default 100) have run, then exits so launchd (StartInterval 60) starts a fresh one; launchd
 * never overlaps two instances of the same label. `--once` runs at most one job (for testing).
 *
 *   /opt/homebrew/opt/php@8.2/bin/php /var/www/contentos.cvk/framework/cron/queue_worker.php >> /tmp/cls-queue.log 2>&1
 */

if (php_sapi_name() !== 'cli') { exit(1); }

// Match the web app's environment so Database picks the right app.ini section.
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (["$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php"] as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});

$opts      = getopt('', ['once', 'idle-exit::', 'max-jobs::']);
$once      = isset($opts['once']);
$idle_exit = (int) ($opts['idle-exit'] ?? 50);
$max_jobs  = (int) ($opts['max-jobs'] ?? 100);

// job type => handler class with static handle(array $payload): string
$handlers = [
    'scheduler_run'  => 'SchedulerRunJob',
    'media_generate' => 'MediaGenerateJob',
    'influencer_job' => 'InfluencerJob',
    'broadcast_send' => 'BroadcastSendJob',
];

$queue     = new DatabaseJobQueue();
$worker_id = substr(gethostname() . ':' . getmypid(), 0, 64);
$released  = $queue->release_stale(900);
if ($released > 0) { fwrite(STDOUT, date('c') . " released $released stale job(s)\n"); }

$done = 0;
$idle_since = time();
while (true) {
    $job = $queue->claim($worker_id);
    if ($job === null) {
        if ($once || (time() - $idle_since) >= $idle_exit) { break; }
        sleep(2);
        continue;
    }
    $idle_since = time();
    $payload = json_decode((string) $job['payload_json'], true);
    if (!is_array($payload)) { $payload = []; }
    $t0 = microtime(true);
    try {
        if (!isset($handlers[$job['type']])) { throw new RuntimeException('no handler for job type ' . $job['type']); }
        $class  = $handlers[$job['type']];
        $result = $class::handle($payload);
        $queue->complete((int) $job['id']);
        fwrite(STDOUT, date('c') . " job {$job['id']} {$job['type']}: " . $result . ' (' . round(microtime(true) - $t0, 1) . "s)\n");
    } catch (\Throwable $e) {
        $queue->fail((int) $job['id'], $e->getMessage());
        fwrite(STDOUT, date('c') . " job {$job['id']} {$job['type']}: ERROR " . $e->getMessage() . "\n");
    }
    $done++;
    if ($once || $done >= $max_jobs) { break; }
}
exit(0);
