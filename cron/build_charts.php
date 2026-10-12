<?php
/**
 * Dev step: regenerate every chart and diagram SVG under public/images/charts/ by rendering the pages that carry one, then
 * delete files no page references any more. Run on dev after the sourced fee data (PagesController::COMPETITORS, PlanTiers)
 * changes, and ship the directory with the deploy. Production never writes these files.
 * Usage: APPLICATION_ENV=development php cron/build_charts.php [--base=http://framework.contentos.cvk]
 */
$path = dirname(__DIR__);
$opts = getopt('', array('base::'));
$base = rtrim((string) ($opts['base'] ?? 'http://framework.contentos.cvk'), '/');
$pages = array('/compare/fanvue', '/compare/onlyfans', '/compare/patreon', '/compare/fansly', '/compare/kofi', '/compare/linktree', '/compare/beacons', '/compare/stan', '/compare/eromify',
               '/onlyfans-alternatives', '/fanvue-alternatives', '/best-creator-monetization-platforms', '/monetize-your-content');
$dir = $path . '/public/images/charts/';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
$referenced = array(); $fail = 0;
foreach ($pages as $p) {
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'build_charts'));
    $html = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    preg_match_all('#/images/charts/([a-z0-9-]+\.svg)#', $html, $m);
    foreach ($m[1] as $f) { $referenced[$f] = true; }
    $missing = array_filter($m[1], function ($f) use ($dir) { return !is_file($dir . $f); });
    printf("%-42s HTTP %d  files: %d%s\n", $p, $code, count($m[1]), $missing ? '  MISSING: ' . implode(', ', $missing) : '');
    if ($code !== 200 || $missing) { $fail++; }
}
$stale = 0;
foreach (glob($dir . '*.svg') as $f) { if (!isset($referenced[basename($f)])) { unlink($f); $stale++; } }
echo count($referenced), " chart file(s) in public/images/charts, $stale stale file(s) removed\n";
exit($fail ? 1 : 0);
