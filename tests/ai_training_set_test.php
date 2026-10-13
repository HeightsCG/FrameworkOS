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
    'prompt' => InfluencerService::realistic(InfluencerService::training_prompt($with_body ? $infl : array('gender' => 'woman'), $i - 1))); } return $out; }   // the prompt a real set carries: her build on every shot that shows her
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

// Descriptive training (2026-10-13): off by default, so sets and captions are what they were.
check('descriptive training is off by default', InfluencerService::training_descriptive() === false && InfluencerService::training_vars() === InfluencerService::TRAINING_VARIATIONS && InfluencerService::training_version() === 1);
InfluencerService::$training_descriptive = true;
$v2 = InfluencerService::training_vars();
check('the new shot list has ten shots, close-up at 7 and three body shots at 8 to 10', count($v2) === 10 && stripos($v2[6], 'close-up') !== false && count(array_filter(array_slice($v2, 7), function ($v) { return strpos($v, '{body}') !== false; })) === 3 && count(array_filter($v2, function ($v) { return strpos($v, '{body}') !== false; })) === 3);
check('at most three of the new shots face or look at the camera', count(array_filter($v2, function ($v) { return (bool) preg_match('/looking at the camera|facing the camera|at the camera/', $v); })) <= 3);
check('the new list has both side profiles', count(array_filter($v2, function ($v) { return strpos($v, 'side profile') !== false; })) === 2);
InfluencerService::$training_descriptive = null;
$cap = InfluencerService::training_caption(array('gender' => 'woman'), 'infl_9_ab', 4, 2);
check('a caption names the trigger, the subject and what the picture shows', strpos($cap, 'infl_9_ab, a woman, side profile facing left') === 0 && strpos($cap, 'same person') === false && strpos($cap, '{') === false, $cap);
check('a body shot caption names the outfit, not her build', strpos(InfluencerService::training_caption(array('gender' => 'woman', 'body_description' => 'tall, long legs'), 'x', 8, 2), 'wearing a fitted crop top and short shorts') !== false && strpos(InfluencerService::training_caption(array('gender' => 'woman', 'body_description' => 'tall, long legs'), 'x', 8, 2), 'long legs') === false);
check('an uploaded photo gets the trigger word and the subject', InfluencerService::training_caption(array('gender' => 'man'), 'x') === 'x, a man');

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
