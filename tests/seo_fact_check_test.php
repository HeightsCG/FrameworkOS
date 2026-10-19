<?php
/*
 * seo_fact_check.php's "20% next to the brand" rule (SeoDrafter::brand_twenty_percent, 2026-10-19): our plans never
 * charge 20%, so a 20% put on the brand is flagged, but a 20% a comparison gives to a named competitor is not.
 * The two "passes" sentences are the ones the 2026-10-19 report flagged by mistake. php tests/seo_fact_check_test.php
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
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }
$site = 'Creator Link Studio';
$flag = function ($t) use ($site) { return SeoDrafter::brand_twenty_percent($t, $site); };

// fine: the 20% belongs to a competitor
check('passes: /blog/how-can-i-sell-my-photos-online', !$flag('Price PPV posts individually for one-off drops. Creator Link Studio charges a 10% platform fee on the Creator plan and 3% on Studio, versus the 20% that OnlyFans takes, per the [OnlyFans terms of service](https://onlyfans.com/terms). Patreon charges 10%.'));
check('passes: /blog/where-and-how-to-sell-content-online (FAQ)', !$flag('[{"a": "Keep the most money by driving your own traffic and using a lower-fee storefront. Creator Link Studio charges a 10% platform fee on the Creator plan and 3% on Studio, versus the 20% OnlyFans and Fanvue take. Add payment processing on top"}]'));
check('passes: competitor named before the 20%', !$flag('Unlike Creator Link Studio, Fansly keeps 20% of every payment'));
check('passes: an AlternativesPages record', !$flag('Creator Link Studio takes 10% on Creator, while Passes keeps 20%'));
check('passes: no brand in the sentence', !$flag('OnlyFans keeps 20%. Our fee is 10% on Creator.'));

// flagged: the 20% is put on us
check('flags: brand takes 20%', $flag('Creator Link Studio takes 20% of each sale.'));
check('flags: 20% before the brand', $flag('A 20% fee applies on Creator Link Studio Free.'));
check('flags: brand nearer than the competitor', $flag('Creator Link Studio takes 20%, the same as OnlyFans.'));
check('flags: one bad 20% among good ones', $flag('Creator Link Studio charges 20% on Free, versus the 20% OnlyFans takes'));

// the cron uses it
check('seo_fact_check.php uses SeoDrafter::brand_twenty_percent', strpos(file_get_contents($root . '/cron/seo_fact_check.php'), 'SeoDrafter::brand_twenty_percent($text, $site)') !== false);
echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
