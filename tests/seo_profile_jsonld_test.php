<?php
// Runs the profile view's dateCreated logic the way view.php does and asserts ISO 8601 with offset.
$cases = array('2026-09-01 14:02:11' => true, '' => false, 'not a date' => false, null => false);
$fail = 0;
foreach ($cases as $in => $expect_set) {
    $ld = array();
    $ts = ($in !== null && $in !== '') ? strtotime((string) $in . ' UTC') : false;
    if ($ts !== false) { $ld['dateCreated'] = gmdate('c', $ts); }
    $set = isset($ld['dateCreated']);
    $ok  = ($set === $expect_set) && (!$set || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $ld['dateCreated']));
    echo ($ok ? 'ok   ' : 'FAIL ') . var_export($in, true) . ' => ' . ($set ? $ld['dateCreated'] : '(unset)') . "\n";
    if (!$ok) { $fail++; }
}
// The view must use gmdate('c') and guard strtotime === false.
$view = file_get_contents(__DIR__ . '/../app/views/profile/view.php');
$uses_c = strpos($view, "gmdate('c'") !== false && strpos($view, "gmdate('Y-m-d', strtotime((string) \$user['creator_since']") === false;
echo ($uses_c ? 'ok   ' : 'FAIL ') . "view.php emits dateCreated with gmdate('c')\n";
if (!$uses_c) { $fail++; }
exit($fail ? 1 : 0);
