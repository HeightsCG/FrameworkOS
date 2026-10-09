<?php
/**
 * Age verification against Didit's sandbox, end to end, for one dev account. Needs app.ini didit_api_key,
 * didit_workflow_id (the Adaptive Age Verification workflow) and, for a hands-free run, didit_sandbox_scenario
 * (a scenario slug that forces the outcome). Prints the hosted URL (open it in a browser if the scenario needs a
 * person), then waits for the result, which arrives by webhook or by the decision fetch the landing would do.
 *
 *   APPLICATION_ENV=development php tests/age_verification_sandbox_e2e.php <user_id> [expect=verified|failed] [--reset]
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$uid = (int) ($argv[1] ?? 0);
$expect = 'verified'; $reset = false;
foreach (array_slice($argv, 2) as $a) { if (strpos($a, 'expect=') === 0) { $expect = substr($a, 7); } if ($a === '--reset') { $reset = true; } }
if ($uid <= 0) { fwrite(STDERR, "usage: <user_id> [expect=verified|failed] [--reset]\n"); exit(2); }
$cfg = Main::get_config();
foreach (array('didit_api_key', 'didit_workflow_id') as $k) { if (trim((string) ($cfg['global'][$k] ?? '')) === '') { fwrite(STDERR, "app.ini $k is empty\n"); exit(2); } }
echo 'scenario: ', trim((string) ($cfg['global']['didit_sandbox_scenario'] ?? '')) ?: '(none: a person completes the hosted flow)', "\n";

$m = new AgeVerificationsModel();
if ($reset) { $m->remove($uid); echo "row removed for a fresh run\n"; }
echo 'before: ', AgeVerification::status($uid), "\n";
$s = AgeVerification::start($uid, '/account/settings#privacy');
if (empty($s['ok'])) { fwrite(STDERR, 'start failed: ' . $s['error'] . "\n"); exit(1); }
echo "hosted URL: ", $s['url'], "\n";
echo 'after start: ', AgeVerification::status($uid), ' (ref ', substr((string) $m->get($uid)['provider_ref'], 0, 8), "…)\n";

$deadline = time() + 180; $status = 'pending';
while (time() < $deadline) {
    $status = AgeVerification::refresh_if_pending($uid);   // webhook may have landed; else ask Didit for the decision
    if ($status !== 'pending') { break; }
    sleep(5); echo '.';
}
echo "\nresult: $status\n";
if ($status !== $expect) { fwrite(STDERR, "expected $expect\n"); exit(1); }
echo "OK\n";
