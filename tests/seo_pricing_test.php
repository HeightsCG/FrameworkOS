<?php
$root = dirname(__DIR__); putenv('APPLICATION_ENV=development');
if (is_file("$root/vendor/autoload.php")) { require_once "$root/vendor/autoload.php"; }
spl_autoload_register(function ($c) use ($root) { foreach (["$root/libs/Classes/$c.php", "$root/app/models/$c.php", "$root/app/controllers/$c.php", "$root/app/$c.php"] as $f) { if (file_exists($f)) { require_once $f; return; } } });
$rows = PagesController::pricing_rows();
$fail = 0; function check($l, $c) { global $fail; echo ($c ? 'ok   ' : 'FAIL ') . $l . "\n"; if (!$c) { $fail++; } }
check('one row per offered tier', count($rows) === count(PlanTiers::offered()));
check('no retired tier is public', count(array_filter($rows, function ($r) { return !empty($r['tier']['retired']); })) === 0);
check('free is listed', in_array(PlanTiers::FREE_KEY, array_map(function ($r) { return $r['tier']['key']; }, $rows), true));
$ranks = array_map(function ($r) { return $r['tier']['rank']; }, $rows);
check('ordered by rank', $ranks === array_values(array_unique($ranks)) && $ranks === (function ($a) { sort($a); return $a; })($ranks));
foreach ($rows as $r) { check($r['tier']['key'] . ' amount int-or-null', $r['amount'] === null || is_int($r['amount'])); }
exit($fail ? 1 : 0);
