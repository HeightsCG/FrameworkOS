<?php
/**
 * Stage 4 voice: the Voice Design prompt, saved voices (one active, a per-account cap), speech pricing and
 * refusals, script splitting and packing for talking video. Dev DB (creator #1 "admin"). Nothing is sent to
 * the voice service or to fal: voices here are rows with made-up ids, removed at the end.
 *   APPLICATION_ENV=development php tests/ai_voice_test.php
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

$cid = 1;
$infl = null;
foreach ((new InfluencersModel())->list_ready($cid) as $r) { $infl = (new InfluencersModel())->get_one($cid, (int) $r['id']); break; }
check('fixture: creator #1 has a trained influencer', (bool) $infl);
if (!$infl) { echo "1 FAILED\n"; exit(1); }
$user = InfluencerJobService::user($cid);
$credits = new AiCreditsModel();
$start = (int) $credits->get_balance($cid);
$vm = new InfluencerVoicesModel();

/* ---- the Voice Design prompt ---- */
$p = InfluencerVoiceActions::design_prompt($infl, array('age_vibe' => '24 year old, warm and playful.', 'keyword' => 'supermodel', 'city' => 'Miami', 'country' => 'United States', 'tone' => 'a little teasing'));
check('the prompt carries age and vibe, keyword, accent and tone', strpos($p, '24 year old, warm and playful. A ' . InfluencerService::noun($infl) . ' supermodel from Miami, United States with a natural Miami accent. A little teasing.') === 0, $p);
check('an unknown keyword falls back to influencer',    strpos(InfluencerVoiceActions::design_prompt($infl, array('age_vibe' => 'x', 'keyword' => 'astronaut')), ' influencer.') !== false);
check('tone and accent are optional',                   InfluencerVoiceActions::design_prompt($infl, array('age_vibe' => 'Young and bright', 'keyword' => 'blogger')) === 'Young and bright. A ' . InfluencerService::noun($infl) . ' blogger. Conversational, close to the microphone, clear studio quality.');
check('the five social keywords are offered',           InfluencerVoiceActions::KEYWORDS === array('influencer', 'supermodel', 'blogger', 'youtuber', 'instagrammer'));
check('audio tags come in four groups',                 array_keys(InfluencerVoiceActions::TAGS) === array('Emotion', 'Non-Verbal', 'Volume', 'Pacing') && in_array('whispers', InfluencerVoiceActions::TAGS['Volume'], true));

/* ---- design: validation happens before anything is charged or sent ---- */
$r = InfluencerVoiceActions::design($cid, $user, $infl, array('age_vibe' => '', 'preview_text' => str_repeat('a', 150)));
check('age and vibe is required',                       empty($r['ok']) && ($r['field'] ?? '') === 'age_vibe');
$r = InfluencerVoiceActions::design($cid, $user, $infl, array('age_vibe' => 'x', 'preview_text' => 'too short'));
check('preview text under 100 characters is refused',   empty($r['ok']) && ($r['field'] ?? '') === 'preview_text');
$r = InfluencerVoiceActions::design($cid, $user, $infl, array('age_vibe' => 'x', 'preview_text' => str_repeat('a', 1001)));
check('preview text over 1000 characters is refused',   empty($r['ok']) && ($r['field'] ?? '') === 'preview_text');
check('refused designs cost nothing',                   (int) $credits->get_balance($cid) === $start);
check('saving with a made-up token is refused',         empty(InfluencerVoiceActions::save_voice($cid, $infl, 'not-a-token', 0, 'x')['ok']) && empty(InfluencerVoiceActions::save_voice($cid, $infl, str_repeat('a', 24), 0, 'x')['ok']));

/* ---- prices ---- */
check('voice design is priced by preview length x 3',   InfluencerVoiceActions::design_price(str_repeat('a', 500)) === InfluencerConfig::credits_from_usd(0.20 * 1500 / 1000) && InfluencerVoiceActions::design_price(str_repeat('a', 100)) === 40);
check('speech is priced by characters x 2 takes',       InfluencerVoiceActions::speech_price(str_repeat('a', 1000)) === 280 && InfluencerVoiceActions::speech_price('Hi.') === 10);
$tp = InfluencerVoiceActions::talking_price(10, 150);
check('a talking video is lip sync per second plus speech', $tp['video'] === 700 && $tp['speech'] === 20 && $tp['total'] === 720 && InfluencerVoiceActions::talking_price(10, 0)['speech'] === 0);

/* ---- saved voices: one active, cap per account ---- */
$before = array_column($vm->list_for_influencer($cid, (int) $infl['id']), 'id');
$had_active = $vm->active($cid, (int) $infl['id']);
$a = $vm->add($cid, (int) $infl['id'], array('name' => 'Test A', 'voice_id' => 'test_voice_a', 'prompt' => 'p', 'settings' => array('keyword' => 'blogger')));
$b = $vm->add($cid, (int) $infl['id'], array('name' => 'Test B', 'voice_id' => 'test_voice_b', 'prompt' => 'p'));
check('a later voice is saved without taking over',     (int) $vm->get_one($cid, $b)['is_active'] === 0 && ($had_active ? (int) $vm->active($cid, (int) $infl['id'])['id'] === (int) $had_active['id'] : (int) $vm->get_one($cid, $a)['is_active'] === 1));
$r = InfluencerVoiceActions::set_active($cid, $infl, $b);
$actives = array_filter($vm->list_for_influencer($cid, (int) $infl['id']), function ($v) { return (int) $v['is_active'] === 1; });
check('setting a voice active leaves exactly one active', !empty($r['ok']) && count($actives) === 1 && (int) array_values($actives)[0]['id'] === $b);
check('settings round-trip',                            InfluencerVoicesModel::settings($vm->get_one($cid, $a)) === array('keyword' => 'blogger'));
check('another creator cannot read or activate it',     $vm->get_one(27, $a) === null && empty(InfluencerVoiceActions::set_active(27, $infl, $a)['ok']));
$vm->soft_delete($cid, $b);
check('removing the active voice hands over to another', $vm->get_one($cid, $b) === null && $vm->active($cid, (int) $infl['id']) !== null);
InfluencerConfig::set_override('voices_per_creator', $vm->count_for_creator($cid));
$l = InfluencerVoiceActions::voices($cid, $infl);
check('the voices list reports the cap',                !empty($l['ok']) && $l['cap'] === $l['saved'] && $l['takes'] === 2);
InfluencerConfig::set_override('voices_per_creator', null);

/* ---- speech refusals ---- */
check('speech needs a script',                          empty(InfluencerVoiceActions::speech_start($cid, $infl, '   ')['ok']));
check('speech over 3000 characters is refused',         empty(InfluencerVoiceActions::speech_start($cid, $infl, str_repeat('word ', 700))['ok']));
$other = array('id' => 999999, 'name' => 'Nobody', 'gender' => 'woman');
$r = InfluencerVoiceActions::speech_start($cid, $other, 'Hello there.');
check('an influencer with no voice is told to design one', empty($r['ok']) && !empty($r['need_voice']));
check('a voice of another influencer cannot be used',   empty(InfluencerVoiceActions::speech_start($cid, $other, 'Hello there.', $a)['ok']));
check('saving a take of a job that is not speech is refused', empty(InfluencerVoiceActions::speech_save($cid, $user, 0, 0)['ok']));
check('refused speech costs nothing',                   (int) $credits->get_balance($cid) === $start);

/* ---- talking video: script splitting and packing ---- */
$paras = InfluencerVoiceActions::paragraphs("First line.\nstill the first paragraph.\n\n\nSecond paragraph.\r\n\r\n   \r\nThird.");
check('paragraphs split on blank lines',                $paras === array('First line. still the first paragraph.', 'Second paragraph.', 'Third.'), json_encode($paras));
$long = trim(str_repeat('This is a sentence that goes on for a while. ', 120));
$lp = InfluencerVoiceActions::paragraphs($long);
check('a paragraph over the speech limit breaks at a sentence', count($lp) >= 2 && max(array_map('mb_strlen', $lp)) <= ElevenLabsService::SPEECH_MAX && substr($lp[0], -1) === '.' && implode(' ', $lp) === $long);
$clips = array(array('path' => 'a', 'duration' => 20), array('path' => 'b', 'duration' => 30), array('path' => 'c', 'duration' => 25), array('path' => 'd', 'duration' => 90), array('path' => 'e', 'duration' => 5));
$parts = InfluencerVoiceActions::pack_parts($clips, 60);
check('clips pack into parts in order, within the limit', array_map(function ($g) { return implode('', array_column($g, 'path')); }, $parts) === array('ab', 'c', 'd', 'e'));
check('one short clip is one part',                     count(InfluencerVoiceActions::pack_parts(array($clips[0]), 60)) === 1 && InfluencerVoiceActions::pack_parts(array(), 60) === array());
check('a talking video needs a close-up image',         empty(InfluencerVoiceActions::talking_start($cid, $user, $infl, array('image_asset_id' => 0, 'script' => 'Hi.'))['ok']));
$img = (int) InfluencerService::base_reference($cid, $infl);
check('a talking video needs a script or an audio file', empty(InfluencerVoiceActions::talking_start($cid, $user, $infl, array('image_asset_id' => $img))['ok']));
check('an image is not accepted as the audio',          empty(InfluencerVoiceActions::talking_start($cid, $user, $infl, array('image_asset_id' => $img, 'audio_asset_id' => $img))['ok']));
$e = InfluencerVoiceActions::talking_estimate($cid, $infl, array('script' => str_repeat('a', 300)));
check('the estimate prices video and speech',           !empty($e['ok']) && $e['seconds'] === 20 && $e['video'] === 1400 && $e['speech'] === InfluencerConfig::credits_from_usd(0.20 * 300 / 1000) && $e['total'] === $e['video'] + $e['speech']);
check('a script over five minutes is flagged',          !empty(InfluencerVoiceActions::talking_estimate($cid, $infl, array('script' => str_repeat('a', 5000)))['too_long']));
check('status of an unknown talking video',             InfluencerVoiceActions::talking_status($cid, 'talk_nope')['state'] === 'missing');
check('nothing above was charged',                      (int) $credits->get_balance($cid) === $start);

/* ---- audio files ---- */
check('audio is a listable Library type',               is_array((new MediaAssetsModel())->get_for_creator($cid, array('type' => 'audio'))));

foreach (array($a, $b) as $v) { $vm->sql("DELETE FROM influencer_voices WHERE id = :id AND creator_id = :c", array(':id' => $v, ':c' => $cid)); }
if ($had_active) { $vm->set_active($cid, (int) $infl['id'], (int) $had_active['id']); }
check('her voices are as they were',                    array_column($vm->list_for_influencer($cid, (int) $infl['id']), 'id') == $before || count($vm->list_for_influencer($cid, (int) $infl['id'])) === count($before));

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
