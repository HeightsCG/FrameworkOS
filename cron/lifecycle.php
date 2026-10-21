<?php
/**
 * Lifecycle email worker (LifecycleEmails): verification reminders to accounts that never confirmed (up to 3, each with
 * a fresh link) and the "your plan didn't start" follow-up about an hour after a plan checkout that never finished.
 * Then the Free-creator nurture emails (NurtureEmails, marketing, Postmark broadcast stream, at most NurtureEmails::CAP a run).
 * The trial-ending email runs from cron/billing.php. Every email is claimed before it is sent, so overlapping runs never
 * double-send; at most LifecycleEmails::REMINDER_CAP of each kind per run.
 * Cron (prod), every 15 minutes:
 *
 *   * /15 * * * *  APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/lifecycle.php >> /tmp/cls-lifecycle.log 2>&1
 *
 * (Remove the space in "* /15" — it's only there to keep this comment block valid PHP.)
 * Needs sql/2026-10-18_lifecycle_emails.sql.
 */

if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');   // same as Bootstrap: every stored timestamp is UTC

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});

// One run at a time.
if (!CronRuns::lock('lifecycle')) { exit(0); }
CronRuns::start('lifecycle');

$ok = true; $notes = array();
try {
    $n = LifecycleEmails::run_verify_reminders();
    $notes[] = 'verify reminders ' . $n;
} catch (\Throwable $e) { $ok = false; error_log('[lifecycle] verify reminders: ' . $e->getMessage()); $notes[] = 'verify reminders failed'; }
try {
    $n = LifecycleEmails::run_abandoned_checkouts();
    $notes[] = 'checkout follow-ups ' . $n;
} catch (\Throwable $e) { $ok = false; error_log('[lifecycle] abandoned checkouts: ' . $e->getMessage()); $notes[] = 'checkout follow-ups failed'; }
try {
    $n = LifecycleEmails::run_checkout_followups();
    $notes[] = 'checkout reminders ' . $n;
} catch (\Throwable $e) { $ok = false; error_log('[lifecycle] checkout reminders: ' . $e->getMessage()); $notes[] = 'checkout reminders failed'; }
try {
    $n = NurtureEmails::run();
    $notes[] = 'nurture ' . $n;
} catch (\Throwable $e) { $ok = false; error_log('[lifecycle] nurture: ' . $e->getMessage()); $notes[] = 'nurture failed'; }

fwrite(STDOUT, date('c') . ' ' . implode(', ', $notes) . "\n");
CronRuns::finish('lifecycle', $ok, implode(', ', $notes));
exit(0);
