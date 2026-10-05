<?php
/**
 * Aspect ratios: the shared list, the older keys, and what each catalog model is sent. Pure logic, no DB.
 *   APPLICATION_ENV=development php tests/ai_aspect_test.php
 */
if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});
$fail = 0;
function check($label, $ok, $extra = ''){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $extra === '' ? '' : '  -> ' . $extra) . "\n"; if (!$ok) { $fail++; } }

/* ---- the list and the older keys ---- */
check('the new ratios are offered',                 !array_diff(array('9:16', '3:4', '4:5'), Aspect::keys()));
check('square reads as 1:1',                        Aspect::normalize('square') === '1:1');
check('portrait reads as 3:4',                      Aspect::normalize('portrait') === '3:4');
check('landscape reads as 4:3',                     Aspect::normalize('landscape') === '4:3');
check('a ratio key passes through',                 Aspect::normalize('9:16') === '9:16');
check('unknown falls back to the feed default 3:4', Aspect::normalize('banana') === '3:4' && Aspect::normalize('') === '3:4');
check('unknown honours a given default',            Aspect::normalize('', 'square') === '1:1' && Aspect::normalize('x', '9:16') === '9:16');
check('defaults: 3:4 images, 9:16 video',           Aspect::DEFAULT_IMAGE === '3:4' && Aspect::DEFAULT_VIDEO === '9:16');
check('matches() accepts a 1080x1920 frame as 9:16', Aspect::matches(1080, 1920, '9:16') && !Aspect::matches(1080, 1350, '9:16'));
check('nearest() reads 1080x1350 as 4:5',           Aspect::nearest(1080, 1350) === '4:5');

/* ---- every catalog model maps each ratio it claims, and only those ---- */
foreach (InfluencerConfig::MODELS as $key => $raw) {
    $m = InfluencerConfig::model($key);
    if (empty($m['aspects'])) { continue; }
    $sup = Aspect::supported($m);
    check($key . ': claims at least one ratio', !empty($sup));
    foreach (Aspect::keys() as $k) {
        $v = Aspect::value_for($m, $k);
        if (in_array($k, $sup, true)) {
            $ok = is_string($v) ? ($v !== '') : (is_array($v) && (int) ($v['width'] ?? 0) > 0 && (int) ($v['height'] ?? 0) > 0);
            if (is_array($v)) { $ok = $ok && Aspect::matches($v['width'], $v['height'], $k, 0.01); }   // explicit sizes really are that ratio
            check($key . ' ' . $k . ': has a value the endpoint takes', $ok, json_encode($v));
        } else {
            check($key . ' ' . $k . ': unsupported has no value', $v === null);
        }
    }
}

/* ---- the documented per-model limits ---- */
$flux = InfluencerConfig::model('flux_lora_quality');
check('Flux: all five ratios',                      count(Aspect::supported($flux)) === 5);
check('Flux portrait is unchanged (3:4 preset)',    Aspect::value_for($flux, 'portrait') === 'portrait_4_3' && FalProvider::flux_size('portrait') === 'portrait_4_3');
check('Flux square and landscape are unchanged',    FalProvider::flux_size('square') === 'square_hd' && FalProvider::flux_size('landscape') === 'landscape_4_3');
check('Flux 9:16 uses the 16:9 portrait preset',    Aspect::value_for($flux, '9:16') === 'portrait_16_9');
check('Flux 4:5 is an explicit size',               Aspect::value_for($flux, '4:5') === array('width' => 896, 'height' => 1120));
$grok = InfluencerConfig::model('grok_edit');
check('Grok edit: no 4:5',                          !Aspect::supports($grok, '4:5') && Aspect::supports($grok, '9:16'));
check('Grok edit: 4:5 falls back to a supported ratio', in_array(Aspect::for_model($grok, '4:5'), Aspect::supported($grok), true) && Aspect::for_model($grok, '4:5') === '3:4');
$seed = InfluencerConfig::model('seedream_45_edit');
foreach (Aspect::supported($seed) as $k) {
    $v = Aspect::value_for($seed, $k);
    $px = $v['width'] * $v['height'];
    check('Seedream ' . $k . ': inside the endpoint size limits', $v['width'] >= 1920 && $v['height'] >= 1920 && $v['width'] <= 4096 && $v['height'] <= 4096 && $px >= 3686400 && $px <= 16777216, json_encode($v));
}
$i2v = InfluencerConfig::model('kling_v3');
check('image-to-video follows its source (no ratio list)', Aspect::supported($i2v) === array());
check('picker options flag unsupported ratios',     count(array_filter(Aspect::options($grok), function ($o) { return !$o['supported']; })) === 1);
$opt = InfluencerConfig::picker_options('edit');
check('model options expose supported ratios',      isset($opt[0]['aspects']) && in_array('9:16', $opt[0]['aspects'], true));

/* ---- pricing rule: about 700 credits per provider dollar, steps of 10, floor 10 ---- */
check('$0.15 -> 110 credits',                       InfluencerConfig::credits_from_usd(0.15) === 110, (string) InfluencerConfig::credits_from_usd(0.15));
check('$0.04 -> 30 credits',                        InfluencerConfig::credits_from_usd(0.04) === 30);
check('$0.022 -> 20 credits',                       InfluencerConfig::credits_from_usd(0.022) === 20, (string) InfluencerConfig::credits_from_usd(0.022));
check('tiny costs hit the floor of 10',             InfluencerConfig::credits_from_usd(0.003) === 10);
check('free is free',                               InfluencerConfig::credits_from_usd(0) === 0);
check('existing prices are unchanged',              Plan::ai_price('image') === 50 && Plan::ai_price('enhance') === 10
    && Plan::ai_price('video', array('model_key' => 'hailuo_02', 'duration' => '6')) === 200 && Plan::ai_price('video', array('model_key' => 'kling_v3', 'duration' => '10')) === 600);
check('reference, training set and training stay free', Plan::ai_price('reference') === 0 && Plan::ai_price('training_set') === 0 && Plan::ai_price('training') === 0);
check('a replica is priced per image',              Plan::ai_price('replicate', array('model_key' => 'nano_banana_pro_edit', 'params' => array('num_images' => 3))) === 330);
check('a carousel slot is priced as a replicate run', Plan::ai_price('carousel', array('model_key' => 'seedream_45_edit')) === 30);

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
