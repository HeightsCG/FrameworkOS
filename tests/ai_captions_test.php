<?php
/**
 * Caption modes and the persona block. Pure logic unless LIVE=1, which also asks the model for one caption per mode.
 *   APPLICATION_ENV=development php tests/ai_captions_test.php
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

check('four modes',                               array_keys(BrandService::CAPTION_MODES) === array('standard', 'continuation', 'comment_bait', 'hook_overlay'));
check('labels and loose spellings are understood', BrandService::caption_mode('Comment Bait') === 'comment_bait' && BrandService::caption_mode('hook-overlay') === 'hook_overlay');
check('unknown falls back to standard',           BrandService::caption_mode('banana') === 'standard' && BrandService::caption_mode('') === 'standard');
check('standard adds no rule',                    BrandService::mode_rule('standard') === '');
check('each other mode has its own rule',         count(array_unique(array(BrandService::mode_rule('continuation'), BrandService::mode_rule('comment_bait'), BrandService::mode_rule('hook_overlay')))) === 3);
check('hook overlay asks for the pointer',        strpos(BrandService::mode_rule('hook_overlay'), BrandService::HOOK_POINTER) !== false);

$p = BrandService::split_hook("HOOK: I almost didn't post this. Read below 👇\nCAPTION: So here is what happened.");
check('a hook answer is split in two',            $p['hook'] === "I almost didn't post this. Read below 👇" && $p['caption'] === 'So here is what happened.');
$p = BrandService::split_hook("HOOK: Nobody warned me\n\nCAPTION: Turns out it matters.");
check('a hook without the pointer gets it',       $p['hook'] === 'Nobody warned me ' . BrandService::HOOK_POINTER);
$p = BrandService::split_hook('Just a caption.');
check('a plain answer is the caption, no hook',   $p['hook'] === '' && $p['caption'] === 'Just a caption.');

$infl = array('name' => 'Nova', 'gender' => 'woman', 'persona_description' => '24, nursing student in Miami', 'persona_personality' => '', 'persona_speaking' => 'lowercase, short', 'persona_niche' => '', 'persona_vulnerability' => '');
$b = InfluencerService::persona_block($infl);
check('persona block names her and her voice',    strpos($b, 'Nova') !== false && strpos($b, 'nursing student') !== false && strpos($b, 'lowercase, short') !== false);
check('empty persona fields are left out',        strpos($b, 'Personality:') === false && strpos($b, 'Niche:') === false);
check('no persona, no block',                     InfluencerService::persona_block(array('name' => 'X', 'gender' => 'man')) === '' && InfluencerService::persona_block(null) === '');

if (getenv('LIVE') === '1') {
    foreach (array_keys(BrandService::CAPTION_MODES) as $mode) {
        $c = BrandService::caption_for('a woman on a rooftop cafe at golden hour with an iced coffee. Write it in the first person as Nova', array(), '', array(), array('mode' => $mode, 'persona' => $b));
        check("live: $mode returns a caption", $c !== '', BrandService::$last_error);
        echo "     [$mode] " . ($mode === 'hook_overlay' ? 'HOOK: ' . BrandService::$last_hook . ' | ' : '') . str_replace("\n", ' ', $c) . "\n";
        if ($mode === 'hook_overlay') { check('live: hook overlay returns the on-video line', BrandService::$last_hook !== '' && mb_stripos(BrandService::$last_hook, 'read below') !== false); }
        if ($mode === 'comment_bait') { check('live: comment bait ends on a question', substr(rtrim($c, " \n🙂😊😉✨💭🤔👀☕️"), -1) === '?' || strpos($c, '?') !== false); }
    }
}
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
