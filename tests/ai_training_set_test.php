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
$t = function ($label, $got, $want) { check($label, $got === $want, 'got ' . var_export($got, true) . ', want ' . var_export($want, true)); };
$t('same face, same body words: current',            InfluencerActions::training_set_stale(1, $infl, jobs($infl, 500)), false);
$t('face changed: out of date',                      InfluencerActions::training_set_stale(1, $infl, jobs($infl, 499)), true);
$t('body words added after the set: out of date',    InfluencerActions::training_set_stale(1, $infl, jobs($infl, 500, false)), true);
$t('body words edited: out of date',                 InfluencerActions::training_set_stale(1, array('body_description' => 'petite, curvy') + $infl, jobs($infl, 500)), true);
$t('no body set at all: current',                    InfluencerActions::training_set_stale(1, array('body_description' => '') + $infl, jobs($infl, 500, false)), false);
$f = jobs($infl, 499); foreach ($f as &$j) { $j['status'] = 'failed'; } unset($j);
$t('failed photos are ignored',                      InfluencerActions::training_set_stale(1, $infl, $f), false);
// Nova's real face reference, so body_reference() finds her full-body picture (one made from a different face reads as none).
$nova = (new InfluencersModel())->get_one(1, 14);
check('Nova (#14) exists in the dev database', is_array($nova) && (int) $nova['reference_asset_id'] > 0);
$n = array('id' => 14, 'reference_asset_id' => (int) $nova['reference_asset_id']) + $infl;
$bref = InfluencerService::body_reference(1, $n);
check('Nova has a body reference made from her current face', $bref > 0);
$u = jobs($n, (int) $nova['reference_asset_id']); foreach ($u as &$j) { if (in_array((int) $j['group_index'], array(8, 9, 10), true)) { $j['params_json'] = '{"image_asset_ids":[9999999]}'; } } unset($j);
$t('made with a body reference she no longer has: out of date', InfluencerActions::training_set_stale(1, $n, $u), true);
$c = jobs($n, (int) $nova['reference_asset_id']); foreach ($c as &$j) { if (in_array((int) $j['group_index'], array(8, 9, 10), true)) { $j['params_json'] = '{"image_asset_ids":[' . $bref . ']}'; } } unset($j);
$t('made with her current body reference: current',   InfluencerActions::training_set_stale(1, $n, $c), false);
$st = InfluencerActions::training_set_status(1, $nova);
$t('Nova (unchanged) reads as current',              $st['stale'], false);

check('body words reach the three body shots only', count(array_filter(InfluencerService::TRAINING_VARIATIONS, function ($v) { return strpos($v, '{body}') !== false; })) === 3);
check('a variation without body words has no leftover token', strpos(InfluencerService::training_variation(array('gender' => 'woman'), 7), '{body}') === false);
check('body text is stored as one clean line', InfluencerService::body_text("  tall,\n athletic ,, ") === 'tall, athletic');

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
