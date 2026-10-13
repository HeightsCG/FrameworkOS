<?php
/**
 * A public profile lists only events that have not ended (2026-10-13). Against the dev DB: creator #1 gets four throwaway
 * events, which are removed at the end.
 *   APPLICATION_ENV=development php tests/profile_events_upcoming_test.php
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
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }

$creator = 1; $m = new EventsModel();
$at = function ($hours) { return gmdate('Y-m-d H:i:s', time() + (int) ($hours * 3600)); };
$make = function ($title, $start, $end = '') use ($m, $creator) {
    return $m->create($creator, array('title' => $title, 'start_at' => $start, 'end_at' => $end, 'timezone' => 'UTC', 'access_type' => 'free', 'format' => 'virtual', 'status' => 'published'));
};
$ended      = $make('ZZ Ended With End Time', $at(-48), $at(-47));
$ended_open = $make('ZZ Started Yesterday No End Time', $at(-24));
$running    = $make('ZZ Running Now', $at(-0.5), $at(1));
$future     = $make('ZZ Tomorrow', $at(24), $at(25));

$ids = array_map('intval', array_column($m->list_public_for_creator($creator), 'id'));
check('an event that ended is not listed',                       !in_array($ended, $ids, true));
check('an event with no end time that started yesterday is not', !in_array($ended_open, $ids, true));
check('an event running now is listed',                          in_array($running, $ids, true));
check('an event tomorrow is listed',                             in_array($future, $ids, true));
$past = array_map('intval', array_column($m->list_past_public_for_creator($creator, 50), 'id'));
check('upcoming and past never overlap',                         !array_intersect($ids, $past));

foreach (array($ended, $ended_open, $running, $future) as $id) { $m->delete_event($creator, $id); }
check('throwaway events removed', !array_intersect(array($ended, $ended_open, $running, $future), array_map('intval', array_column($m->list_public_for_creator($creator), 'id'))));

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
