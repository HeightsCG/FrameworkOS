<?php
/**
 * AI disclosure: what counts as AI media, how a cross-post is labelled (platform flag or caption line),
 * Stories placement, and what an automated inbox reply may say. Pure logic: nothing is sent anywhere.
 *   APPLICATION_ENV=development php tests/ai_disclosure_test.php
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

/* ---- what is AI media, and when a post is disclosed ---- */
$ai = array(array('provenance' => 'generated')); $ed = array(array('provenance' => 'edited')); $up = array(array('provenance' => 'uploaded'), array());
check('generated and edited media are AI media',        AiDisclosure::has_ai_media($ai) && AiDisclosure::has_ai_media($ed));
check('uploaded media (and rows without the column) are not', !AiDisclosure::has_ai_media($up));
check('on by default for a post with AI media',         AiDisclosure::applies(null, $ai) && AiDisclosure::applies('', $ai));
check('the creator can turn it off',                    !AiDisclosure::applies(0, $ai) && !AiDisclosure::applies('0', $ai));
check('never applies to a post without AI media',       !AiDisclosure::applies(1, $up) && !AiDisclosure::applies(null, $up));

/* ---- the caption line ---- */
$line = AiDisclosure::DEFAULT_LINE;
check('a blank account line falls back to the default', AiDisclosure::line('   ') === $line && AiDisclosure::line('Made with AI') === 'Made with AI');
$c = SocialShareService::caption_with_line('instagram', 'Sunday reset.', '');
check('the line is the last line of the caption',       $c === "Sunday reset.\n\n" . $line, $c);
check('an empty caption becomes the line',              SocialShareService::caption_with_line('instagram', '', '') === $line);
check('a caption that already says it is not repeated', SocialShareService::caption_with_line('instagram', 'New set. ' . $line, '') === 'New set. ' . $line);
$long = trim(str_repeat('walking the boardwalk at sunset ', 20));
$x = SocialShareService::caption_with_line('x', $long, '');
check('on X the caption is shortened, the line is kept', SocialShareService::length_for('x', $x) <= 280 && substr($x, -strlen($line)) === $line, (string) SocialShareService::length_for('x', $x));
$b = SocialShareService::caption_with_line('bluesky', $long, 'Made with AI');
check('same on Bluesky with an account line',           mb_strlen($b) <= 300 && substr($b, -12) === 'Made with AI');

/* ---- the cross-post plan ---- */
$accounts = array(
    array('id' => 'ig1', 'platform' => 'instagram', 'ai_disclosure_text' => ''),
    array('id' => 'tt1', 'platform' => 'tiktok', 'ai_disclosure_text' => 'ignored on TikTok'),
    array('id' => 'yt1', 'platform' => 'youtube', 'ai_disclosure_text' => ''),
    array('id' => 'x1',  'platform' => 'x', 'ai_disclosure_text' => ''),
    array('id' => 'fb1', 'platform' => 'facebook', 'ai_disclosure_text' => ''),
    array('id' => 'fb2', 'platform' => 'facebook', 'ai_disclosure_text' => 'Created with AI tools'),
);
$items = array(array('url' => 'u1', 'video' => false, 'tall' => true), array('url' => 'u2', 'video' => false, 'tall' => false), array('url' => 'u3', 'video' => false, 'tall' => true));
$p = SocialShareService::plan('Sunday reset.', $accounts, $items, array('ai' => true));
$pc = $p['platform_configurations'];
check('TikTok gets its AI flag, not a caption line',    ($pc['tiktok']['is_ai_generated'] ?? null) === true && strpos((string) ($pc['tiktok']['caption'] ?? ''), $line) === false);
check('YouTube gets its synthetic-media flag',          ($pc['youtube']['contains_synthetic_media'] ?? null) === true);
check('Instagram has no flag: the line goes on the caption', !isset($pc['instagram']['is_ai_generated']) && ($pc['instagram']['caption'] ?? '') === "Sunday reset.\n\n" . $line);
check('X gets the line too',                            substr((string) ($pc['x']['caption'] ?? ''), -strlen($line)) === $line);
$by = array(); foreach ($p['account_configurations'] as $ac) { $by[$ac['social_account_id']] = $ac['configuration']; }
check('two Facebook pages with different lines get one each', ($by['fb1']['caption'] ?? '') === "Sunday reset.\n\n" . $line && ($by['fb2']['caption'] ?? '') === "Sunday reset.\n\nCreated with AI tools");
check('every account is sent to',                       count($p['accounts']) === 6 && empty($p['notes']));
check('carousel: Instagram keeps all three, X is within its limit', !isset($pc['instagram']['media']) && !isset($pc['x']['media']) && $p['media'] === array('u1', 'u2', 'u3'));
$one = SocialShareService::plan('Hi', array($accounts[2]), $items, array('ai' => true));
check('a platform that takes one item gets only the cover', count($one['platform_configurations']['youtube']['media'] ?? array()) === 1);

$off = SocialShareService::plan('Sunday reset.', $accounts, $items, array('ai' => false));
check('disclosure off: no flag and no line anywhere',   strpos(json_encode($off), $line) === false && strpos(json_encode($off), 'is_ai_generated') === false && strpos(json_encode($off), 'synthetic') === false);

/* ---- Stories ---- */
$s = SocialShareService::plan('Sunday reset.', $accounts, $items, array('ai' => true, 'stories' => array('ig1', 'tt1')));
$by = array(); foreach ($s['account_configurations'] as $ac) { $by[$ac['social_account_id']] = $ac['configuration']; }
check('an Instagram Story is placed as a story',        ($by['ig1']['placement'] ?? '') === 'stories');
check('a Story takes only the 9:16 media',              array_column($by['ig1']['media'] ?? array(), 'url') === array('u1', 'u3'));
check('a platform without Stories posts to the feed as usual', !isset($by['tt1']) && in_array('tt1', $s['accounts'], true));
$none = SocialShareService::plan('Hi', array($accounts[0]), array(array('url' => 'u2', 'video' => false, 'tall' => false)), array('stories' => array('ig1')));
check('a Story with no 9:16 media is skipped with a reason', empty($none['accounts']) && count($none['notes']) === 1 && strpos($none['notes'][0], '9:16') !== false);

/* ---- inbox: first-reply disclosure ---- */
check('the first-reply disclosure is never blank',      AiDisclosure::first_reply_text('  ') === AiDisclosure::DEFAULT_FIRST_REPLY && AiDisclosure::first_reply_text('<b>Heads up:</b> AI helps here') === 'Heads up: AI helps here');

/* ---- inbox: is the fan asking, and what does the draft say ---- */
foreach (array('are you real?', 'r u a bot', 'is this an AI', 'am i talking to a real person', 'wait are you actually real or fake', 'Is this really you?', 'do you write these yourself', 'bot or human?') as $q) {
    check('asks if real: "' . $q . '"', AiDisclosure::asks_if_real($q));
}
foreach (array('you look amazing today', 'what are you up to tonight', 'that real estate job sounds hard', 'send me the real one') as $q) {
    check('not a question about being real: "' . $q . '"', !AiDisclosure::asks_if_real($q));
}
check('"I am an AI assistant" confirms it',             AiDisclosure::confirms_ai("Fair question. I'm an AI assistant that helps with replies here."));
check('"replies here may be automated" confirms it',    AiDisclosure::confirms_ai('Some replies here may be automated.'));
check('"of course I\'m real" denies it',                AiDisclosure::denies_ai("of course i'm real babe") && !AiDisclosure::confirms_ai("of course i'm real babe"));
check('"I\'m not a bot" denies it',                     AiDisclosure::denies_ai("lol I'm not a bot"));
check('small talk neither confirms nor denies',         !AiDisclosure::confirms_ai('just got back from the gym') && !AiDisclosure::denies_ai('just got back from the gym'));

/* ---- inbox: hold for approval ---- */
check('asked + dodged = held for the creator',          AiDisclosure::should_hold('are you a real person?', 'haha why do you ask'));
check('asked + confirms AI = sent',                     !AiDisclosure::should_hold('are you a real person?', "Honest answer: I'm an AI assistant helping with messages here."));
check('a denial is always held, even unasked',          AiDisclosure::should_hold('good morning', "morning! and yes i'm a real person lol"));
check('ordinary chat is not held',                      !AiDisclosure::should_hold('good morning', 'morning! how did you sleep'));

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
