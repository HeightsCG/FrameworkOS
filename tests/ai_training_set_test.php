<?php
/**
 * Training set: is it out of date after the face or body changed? (InfluencerActions::training_set_stale)
 * Reads the dev database (influencer Nova, #14); writes nothing.
 *   APPLICATION_ENV=development php tests/ai_training_set_test.php
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
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $extra === '' ? '' : '  -> ' . $extra) . "
"; if (!$ok) { $fail++; } }

function jobs($infl, $ref, $with_body = true){ $out = array(); for ($i = 1; $i <= 10; $i++) { $out[] = array('status' => 'done', 'input_asset_id' => $ref, 'group_index' => $i, 'params_json' => '{}',
    'prompt' => InfluencerService::realistic(InfluencerService::training_variation($with_body ? $infl : array('gender' => 'woman'), $i - 1))); } return $out; }
$infl = array('id' => 0, 'gender' => 'woman', 'reference_asset_id' => 500, 'body_description' => 'tall, athletic, long legs');
$t = function ($label, $got, $want) { echo ($got === $want ? 'ok   ' : 'FAIL ') . $label . "\n"; };
$t('same face, same body words: current',            InfluencerActions::training_set_stale(1, $infl, jobs($infl, 500)), false);
$t('face changed: out of date',                      InfluencerActions::training_set_stale(1, $infl, jobs($infl, 499)), true);
$t('body words added after the set: out of date',    InfluencerActions::training_set_stale(1, $infl, jobs($infl, 500, false)), true);
$t('body words edited: out of date',                 InfluencerActions::training_set_stale(1, array('body_description' => 'petite, curvy') + $infl, jobs($infl, 500)), true);
$t('no body set at all: current',                    InfluencerActions::training_set_stale(1, array('body_description' => '') + $infl, jobs($infl, 500, false)), false);
$f = jobs($infl, 499); foreach ($f as &$j) { $j['status'] = 'failed'; } unset($j);
$t('failed photos are ignored',                      InfluencerActions::training_set_stale(1, $infl, $f), false);
$u = jobs($infl, 500); foreach ($u as &$j) { if (in_array((int) $j['group_index'], array(8, 9, 10), true)) { $j['params_json'] = '{"image_asset_ids":[9999999]}'; } } unset($j);
$t('made with a body reference she no longer has: ' . (InfluencerService::body_reference(1, (new InfluencersModel())->get_one(1, 14)) > 0 ? 'out of date' : 'n/a'), InfluencerActions::training_set_stale(1, array('id' => 14) + $infl, $u), true);
$nova = (new InfluencersModel())->get_one(1, 14); $st = InfluencerActions::training_set_status(1, $nova);
$t('Nova (unchanged) reads as current',              $st['stale'], false);

check('body words reach the three body shots only', count(array_filter(InfluencerService::TRAINING_VARIATIONS, function ($v) { return strpos($v, '{body}') !== false; })) === 3);
check('a variation without body words has no leftover token', strpos(InfluencerService::training_variation(array('gender' => 'woman'), 7), '{body}') === false);
check('body text is stored as one clean line', InfluencerService::body_text("  tall,\n athletic ,, ") === 'tall, athletic');

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
