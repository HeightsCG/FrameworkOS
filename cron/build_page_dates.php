<?php
/**
 * Dev step: record the date each hand-written public page last changed, in libs/Classes/PageDates.php (the sitemap's
 * <lastmod> and the WebPage dateModified read it). Renders every page in SeoController::public_pages() plus the home page,
 * hashes the visible text of <main>, and moves a page's date to today only when that text differs from the stored hash.
 * Run on dev before a deploy that changes public copy, and ship PageDates.php with it. Production never writes the file.
 * Usage: APPLICATION_ENV=development php cron/build_page_dates.php [--base=http://framework.contentos.cvk] [--dry-run]
 */
$path = dirname(__DIR__);
putenv('APPLICATION_ENV=' . (getenv('APPLICATION_ENV') ?: 'development'));
if (getenv('APPLICATION_ENV') !== 'development') { fwrite(STDERR, "build_page_dates is a dev step; production reads PageDates.php and never writes it\n"); exit(1); }
if (is_file($path . '/vendor/autoload.php')) { require_once $path . '/vendor/autoload.php'; }
spl_autoload_register(function ($c) use ($path) { foreach (array("/app/models/$c.php", "/libs/Classes/$c.php", "/app/controllers/$c.php", "/app/$c.php") as $s) { if (file_exists($path . $s)) { require_once $path . $s; return; } } });
$opts = getopt('', array('base::', 'dry-run'));
$base = rtrim((string) ($opts['base'] ?? 'http://framework.contentos.cvk'), '/');
$dry  = isset($opts['dry-run']);

// the visible text of <main>, without the parts that change by themselves: scripts, the live posts feed, creator rows, the year
$text_of = function ($html) {
    if (preg_match('#<main\b[^>]*>(.*)</main>#s', $html, $m)) { $html = $m[1]; }
    $html = preg_replace('#<(script|style|svg|noscript)\b.*?</\1>#s', ' ', $html);
    $html = preg_replace('#<section\b(?:(?!</section>).)*?class="(?:lm|fc-row|cc|ts-row)[ "](?:(?!</section>).)*</section>#s', ' ', $html);   // home: latest posts, founding creators, creator grid, testimonials
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
    $t = preg_replace('/\b20\d\d\b/', 'YEAR', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
};

$paths = array('/');
foreach (SeoController::public_pages() as $p) { $paths[] = (string) $p['path']; }
$paths[] = SeoMeta::AUTHOR_PATH;
$skip = '#^/creators(?:/|$)#';   // the directory changes with its listings, not with a deploy
$dates = PageDates::DATES; $today = gmdate('Y-m-d'); $changed = 0; $fail = 0;
foreach (array_values(array_unique($paths)) as $p) {
    if (preg_match($skip, $p)) { continue; }
    $ch = curl_init($base . $p); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'build_page_dates'));
    $html = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || $html === '') { printf("%-44s HTTP %d  SKIPPED\n", $p, $code); $fail++; continue; }
    $hash = substr(sha1($text_of($html)), 0, 12);
    $old = $dates[$p] ?? null;
    if ($old === null || (string) $old[1] !== $hash) { $dates[$p] = array($today, $hash); $changed++; printf("%-44s %s  %s\n", $p, $today, $old === null ? 'new' : 'changed (was ' . $old[0] . ')'); }
}
foreach (array_keys($dates) as $p) { if (!in_array($p, $paths, true)) { unset($dates[$p]); $changed++; echo "$p removed (no longer a public page)\n"; } }
ksort($dates);
echo count($dates), " page(s), $changed changed\n";
if ($dry || $changed === 0) { echo $dry ? "dry run only\n" : "nothing to write\n"; exit($fail ? 1 : 0); }

$lines = array();
foreach ($dates as $p => $d) { $lines[] = "        " . var_export($p, true) . " => array(" . var_export($d[0], true) . ", " . var_export($d[1], true) . "),"; }
$file = $path . '/libs/Classes/PageDates.php'; $src = file_get_contents($file);
$new = preg_replace('/    const DATES = array\(.*?\);\n/s', "    const DATES = array(\n" . implode("\n", $lines) . "\n    );\n", $src, 1, $n);
if ($n !== 1) { fwrite(STDERR, "could not find the DATES constant in PageDates.php\n"); exit(1); }
file_put_contents($file, $new);
echo "wrote libs/Classes/PageDates.php\n";
exit($fail ? 1 : 0);
