<?php
/**
 * Founding Creator price promotion (CreatorPromo, 2026-10-15): the Eastern deadline boundary, the saving, and the /pricing card
 * before and after the deadline (dev only: ?promo_at= previews another moment). Run: php tests/creator_promo_test.php
 */
$root = dirname(__DIR__);
spl_autoload_register(function ($c) use ($root) { foreach (array("$root/libs/Classes/$c.php", "$root/app/models/$c.php") as $f) { if (is_file($f)) { require_once $f; return; } } });
$base = getenv('SEO_TEST_BASE') ?: 'http://framework.contentos.cvk';
$fail = 0; $ok = function ($c, $label) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$c) { $fail++; } };
$tz = new DateTimeZone(CreatorPromo::TZ); $at = function ($s) use ($tz) { return (new DateTime($s, $tz))->getTimestamp(); };

$ok(CreatorPromo::active($at('2026-10-31 23:59:59')) === true, 'active at Oct 31 23:59:59 Eastern');
$ok(CreatorPromo::active($at('2026-11-01 00:00:00')) === false, 'ended at Nov 1 00:00:00 Eastern');
$ok(gmdate('Y-m-d H:i:s', CreatorPromo::ends_at()) === '2026-11-01 03:59:59', 'deadline is 03:59:59 UTC (EDT, UTC-4)');
$ok(CreatorPromo::saving() === 50 && CreatorPromo::REGULAR === 99 && CreatorPromo::PRICE === 49, 'prices: $99 regular, $49 offer, save $50');
$ok((int) PlanTiers::price('studio') === 199 && (int) PlanTiers::price('free') === 0, 'Free and Studio prices unchanged');

$get = function ($q) use ($base) { $ch = curl_init($base . '/pricing' . $q); curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true)); $b = (string) curl_exec($ch); curl_close($ch); return $b; };
$before = $get('?promo_at=' . rawurlencode('2026-10-31 23:59:59'));
$card = preg_match('#sx-plan--creator[^"]*sx-plan--promo.*?sx-plan__body#s', $before, $m) ? $m[0] : '';
$ok($card !== '' && strpos($card, 'Founding Creator Offer') !== false && strpos($card, 'Regular price starting Nov 1') !== false && strpos($card, '<s>$99/month</s>') !== false
    && strpos($card, '$49 <small>') !== false && strpos($card, 'Save $50/month') !== false && strpos($card, 'Offer ends October 31, 2026.') !== false
    && strpos($card, 'Claim $49 Founding Creator Price') !== false, '/pricing before the deadline: badge, $99 regular above $49, saving, deadline, CTA');
$ok(strpos($card, '$99/month') < strpos($card, '$49 <small>'), '$99 sits above $49');
$cc = preg_match('#sx-plan--creator.*?sx-plan--studio#s', $before, $m2) ? $m2[0] : '';
preg_match_all('#<dt>([^<]*)</dt><dd>([^<]*)</dd>#', $cc, $rows); $f = array_combine($rows[1], $rows[2]);
$ok(($f['Platform take rate'] ?? '') === '10%' && ($f['Team seats'] ?? '') === '1' && ($f['AI influencers'] ?? '') === '1' && ($f['AI credits'] ?? '') === '500 / month'
    && ($f['Scheduled automations'] ?? '') === '5' && ($f['Storage'] ?? '') === '25 GB' && ($f['Inbox automation &amp; AI replies'] ?? $f['Inbox automation & AI replies'] ?? '') === 'Included', 'Creator benefits intact: 10%, 1 seat, 1 AI influencer, 500 credits, 5 automations, 25 GB, inbox AI');
$ok(preg_match('#sx-plan--studio.*?\$199#s', $before) === 1, 'Studio card still $199');
$after = $get('?promo_at=' . rawurlencode('2026-11-01 00:00:00'));
$ok(strpos($after, 'sx-plan--promo') === false && strpos($after, 'Founding Creator Offer') === false && strpos($after, 'Save $50') === false && strpos($after, '<s>$99') === false && strpos($after, 'priceValidUntil') === false, '/pricing after the deadline: no badge, no saving, no strikethrough, no end date');
exit($fail ? 1 : 0);
