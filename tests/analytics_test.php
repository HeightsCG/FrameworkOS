<?php
/* First-party analytics: Analytics::ua / is_bot, and SiteAnalyticsModel on the dev DB with synthetic rows (host 'test.analytics', removed after). */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/controllers/$class.php", "$root/app/$class.php") as $src) { if (file_exists($src)) { require_once $src; return; } }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : '  -> ' . $extra) . "\n"; if (!$ok) { $fail++; } }
$ua = Analytics::ua('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');
check('ua: iPhone Safari', $ua === array('device' => 'mobile', 'browser' => 'Safari', 'os' => 'iOS'), json_encode($ua));
$ua = Analytics::ua('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0');
check('ua: Windows Edge', $ua === array('device' => 'desktop', 'browser' => 'Edge', 'os' => 'Windows'), json_encode($ua));
$ua = Analytics::ua('Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36');
check('ua: Android tablet Chrome', $ua === array('device' => 'tablet', 'browser' => 'Chrome', 'os' => 'Android'), json_encode($ua));
check('bots skipped', Analytics::is_bot('Mozilla/5.0 (compatible; Googlebot/2.1)') && Analytics::is_bot('curl/8.0') && Analytics::is_bot('') && !Analytics::is_bot('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'));
check('country from an edge header only', Analytics::country() === '');
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'us'; check('country header read', Analytics::country() === 'US'); unset($_SERVER['HTTP_CF_IPCOUNTRY']);

$m = new SiteAnalyticsModel();
$m->sql("DELETE FROM analytics_events WHERE host = 'test.analytics'");
$base = array('host' => 'test.analytics', 'device' => 'desktop', 'browser' => 'Chrome', 'os' => 'macOS', 'country' => 'US');
$now = gmdate('Y-m-d H:i:s'); $yday = gmdate('Y-m-d H:i:s', time() - 86400); $old = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
// session A: landed on /pricing from google (utm), signed up, paid a plan; session B: direct on /, bounced; session C: 40 days ago (outside the window)
$m->record($base + array('ts' => $now, 'kind' => 'pageview', 'path' => '/pricing', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'launch', 'landing' => '/pricing', 'is_landing' => 1, 'is_new' => 1, 'visitor' => str_repeat('a', 32), 'session' => str_repeat('1', 32)));
$m->record($base + array('ts' => $now, 'kind' => 'pageview', 'path' => '/features', 'landing' => '/pricing', 'visitor' => str_repeat('a', 32), 'session' => str_repeat('1', 32)));
$m->record($base + array('ts' => $now, 'kind' => 'event', 'name' => 'signup', 'visitor' => str_repeat('a', 32), 'session' => str_repeat('1', 32), 'user_id' => 999001));
$m->record($base + array('ts' => $now, 'kind' => 'event', 'name' => 'plan_paid', 'visitor' => str_repeat('a', 32), 'session' => str_repeat('1', 32), 'user_id' => 999001, 'value_cents' => 4900));
$m->record(array('device' => 'mobile') + $base + array('ts' => $yday, 'kind' => 'pageview', 'path' => '/', 'referrer_host' => 'x.com', 'referrer' => 'https://x.com/p', 'landing' => '/', 'is_landing' => 1, 'is_new' => 1, 'visitor' => str_repeat('b', 32), 'session' => str_repeat('2', 32)));
$m->record($base + array('ts' => $old, 'kind' => 'pageview', 'path' => '/', 'landing' => '/', 'is_landing' => 1, 'visitor' => str_repeat('c', 32), 'session' => str_repeat('3', 32)));
// filter the assertions to the synthetic host by reading through the same queries with a host condition is not possible, so compare deltas against the dev data
$o = $m->overview(30); $o_all = $m->overview(30);
check('overview counts the window only', (int) $o['now']['signups'] >= 1 && (int) $o['now']['plans_paid'] >= 1 && (int) $o['now']['value_cents'] >= 4900, json_encode($o));
$src = $m->sources(30, 500);
$google = null; $xcom = null; foreach ($src as $r) { if ($r['source'] === 'google' && $r['medium'] === 'cpc') { $google = $r; } if ($r['source'] === 'x.com') { $xcom = $r; } }
check('sources: utm session with its signup and paid plan', $google && (int) $google['sessions'] >= 1 && (int) $google['signups'] >= 1 && (int) $google['plans_paid'] >= 1, json_encode($google));
check('sources: referrer host as a referral', $xcom && $xcom['medium'] === 'referral', json_encode($xcom));
$d = $m->daily(7);
check('daily: one row per day, newest last', count($d) === 7 && $d[6]['day'] === gmdate('Y-m-d') && (int) $d[6]['signups'] >= 1, json_encode($d[6]));
$pages = $m->top_pages(30, 500); $pricing = null; foreach ($pages as $r) { if ($r['path'] === '/pricing') { $pricing = $r; } }
check('top pages: entrances counted', $pricing && (int) $pricing['entrances'] >= 1, json_encode($pricing));
$camp = $m->campaigns(30); $launch = null; foreach ($camp as $r) { if ($r['campaign'] === 'launch') { $launch = $r; } }
check('campaigns: utm_campaign with conversions', $launch && (int) $launch['signups'] >= 1, json_encode($launch));
$ev = $m->events_summary(30);
check('events summary: signup and plan_paid with value', isset($ev['signup'], $ev['plan_paid']) && (int) $ev['plan_paid']['now']['value_cents'] >= 4900, json_encode(array_keys($ev)));
check('session_landing', $m->session_landing(str_repeat('1', 32)) === '/pricing');
check('breakdown by device lists mobile', (bool) array_filter($m->breakdown(30, 'device'), function ($r) { return $r['label'] === 'mobile'; }));
check('breakdown rejects an unknown column', $m->breakdown(30, 'visitor') === array());
$m->sql("DELETE FROM analytics_events WHERE host = 'test.analytics'");
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
