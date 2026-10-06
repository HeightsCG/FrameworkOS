<?php
/**
 * Stage 3 video tools: frame extraction, Motion Control, Replace Character (prompt, attestation, source
 * moderation, fingerprint) and dialogue Scenes. Dev DB (creator #1 "admin": a trained influencer and a short
 * Library video) with a fake provider, so nothing is rendered at fal. Replace Character really downloads the
 * source from storage and runs the image moderation on its frames.
 *   APPLICATION_ENV=development php tests/ai_video_test.php
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

class FakeVidProvider implements InfluencerProvider {
    public static $seen = array();
    public static function key(): string { return 'fake'; }
    public static function capabilities(): array { return array('ops' => array('motion' => true, 'replace' => true, 'scene' => true, 'replicate' => true)); }
    private static function submit($kind, array $req): array {
        $req['_kind'] = $kind; self::$seen[] = $req; $id = 'fake_' . bin2hex(random_bytes(6));
        return array('ok' => true, 'error' => '', 'error_code' => '', 'retryable' => false, 'http_code' => 200,
            'handle' => array('provider' => 'fake', 'provider_job_id' => $id, 'status_url' => 'fake://s/' . $id, 'response_url' => 'fake://r/' . $id, 'cancel_url' => ''));
    }
    public static function generate_image(array $req): array { return self::submit('image', $req); }
    public static function generate_video(array $req): array { return self::submit('video', $req); }
    public static function train_model(array $req): array { return self::submit('train', $req); }
    public static function get_job_status(array $handle): array { return array('ok' => true, 'state' => 'running', 'error' => '', 'error_code' => '', 'retryable' => false, 'raw' => array()); }
    public static function fetch_result(array $handle): array { return array('ok' => false, 'error' => 'none', 'error_code' => 'provider', 'retryable' => false, 'outputs' => array()); }
    public static function cancel(array $handle): bool { return true; }
    public static function output_url_allowed($url): bool { return false; }
}
InfluencerConfig::register_provider('fake', 'FakeVidProvider');
foreach (array('motion', 'replace', 'scene', 'replicate') as $op) { InfluencerConfig::set_override('providers_' . $op, 'fake'); }
foreach (array('kling_v3_motion_std', 'kling_v3_motion_pro', 'wan_30_ref', 'seedance_20_ref', 'wan_30_scene_final', 'wan_30_scene_draft', 'nano_banana_pro_edit') as $k) { InfluencerConfig::set_override('endpoint_' . $k . '_fake', 'fake/' . $k); }

$cid = 1;
$jobs = new InfluencerJobsModel(); $media = new MediaAssetsModel(); $credits = new AiCreditsModel();
$bal = function () use ($credits, $cid) { return (int) $credits->get_balance($cid); };
$queue_clean = function ($job_id) { (new JobsModel())->sql("DELETE FROM jobs WHERE dedupe_key LIKE :k", array(':k' => 'infl_job:' . (int) $job_id . ':%')); };
$drop = function ($job_id) use ($cid, $jobs, $queue_clean) {
    $queue_clean($job_id);
    InfluencerJobService::cancel_job($cid, (int) $job_id);
    $jobs->sql("DELETE FROM influencer_jobs WHERE id = :id AND creator_id = :c", array(':id' => (int) $job_id, ':c' => $cid));
};
$user = InfluencerJobService::user($cid);
$infl = null;
foreach ((new InfluencersModel())->list_ready($cid) as $r) { $infl = (new InfluencersModel())->get_one($cid, (int) $r['id']); break; }
$video = null;
foreach ((array) $media->get_for_creator($cid, array('type' => 'video')) as $a) {
    if ((string) $a['status'] === 'ready' && (string) $a['moderation_status'] !== 'blocked' && (int) $a['duration_sec'] >= 3 && (int) $a['duration_sec'] <= 15) { $video = $a; break; }
}
$image = (int) InfluencerService::base_reference($cid, $infl ?: array('id' => 0));
check('fixture: a trained influencer, a 3 to 15 second video and a reference image', $infl && $video && $image > 0);
if (!$infl || !$video || $image <= 0) { echo "1 FAILED\n"; exit(1); }
$vid = (int) $video['id'];
$start = $bal();
$made_assets = array();

/* ---- prices: per second, from provider cost ---- */
check('motion is priced per second of the reference',   Plan::ai_price('motion', array('model_key' => 'kling_v3_motion_std', 'params' => array('duration' => 10))) === 880
    && Plan::ai_price('motion', array('model_key' => 'kling_v3_motion_pro', 'params' => array('duration' => 10))) === 1180);
check('replacement is priced per second of the source', Plan::ai_price('replace', array('model_key' => 'wan_30_ref', 'params' => array('duration' => 5))) === 350);
check('a scene is priced per second, Draft under Final', Plan::ai_price('scene', array('model_key' => 'wan_30_scene_draft', 'params' => array('duration' => 10))) === 350
    && Plan::ai_price('scene', array('model_key' => 'wan_30_scene_final', 'params' => array('duration' => 10))) === 1400);
check('only models that accept her references are offered', array_column(InfluencerConfig::picker_options('scene'), 'key') === array('wan_30_scene_final', 'wan_30_scene_draft')
    && array_column(InfluencerConfig::picker_options('replace'), 'key') === array('wan_30_ref'));

/* ---- 5. frame extraction ---- */
$f = InfluencerVideoActions::extract_frame($cid, $user, $vid, 1.5);
check('a frame exports as a new Library image',         !empty($f['ok']) && $f['asset_id'] > 0, json_encode($f));
if (!empty($f['ok'])) {
    $made_assets[] = (int) $f['asset_id'];
    $fa = $media->get_one($cid, (int) $f['asset_id']);
    check('the frame is a ready image the size of the video', (string) $fa['type'] === 'image' && (string) $fa['status'] === 'ready' && (int) $fa['width'] > 0
        && abs((int) $fa['width'] / (int) $fa['height'] - (int) $video['width'] / max(1, (int) $video['height'])) < 0.02, $fa['width'] . 'x' . $fa['height'] . ' vs ' . $video['width'] . 'x' . $video['height']);
    check('the frame records the video it came from',   (int) $fa['source_asset_id'] === $vid && (string) $fa['provenance'] === (in_array((string) $video['provenance'], MediaAssetsModel::PROVENANCE, true) ? (string) $video['provenance'] : 'generated'), $fa['provenance'] . ' vs ' . $video['provenance']);
    check('exporting a frame costs nothing',            $bal() === $start);
}
check('a frame from an image is refused',               empty(InfluencerVideoActions::extract_frame($cid, $user, $image, 0)['ok']));

/* ---- 6. motion control ---- */
$mc = InfluencerVideoActions::motion_check($cid, $vid, 0);
check('the check reports length and a price per quality', !empty($mc['ok']) && $mc['seconds'] >= 3 && isset($mc['prices']['kling_v3_motion_std']['credits'], $mc['prices']['kling_v3_motion_pro']['credits'])
    && $mc['prices']['kling_v3_motion_std']['credits'] === InfluencerConfig::metered_credits(InfluencerConfig::model('kling_v3_motion_std'), $mc['seconds']), json_encode($mc));
check('a motion run needs a first frame',               empty(InfluencerVideoActions::motion_start($cid, $infl, array('video_asset_id' => $vid, 'image_asset_id' => 0))['ok']));
check('a motion run needs a video, not an image',       empty(InfluencerVideoActions::motion_start($cid, $infl, array('video_asset_id' => $image, 'image_asset_id' => $image))['ok']));
$r = InfluencerVideoActions::motion_start($cid, $infl, array('video_asset_id' => $vid, 'image_asset_id' => $image, 'quality' => '1080p', 'prompt' => 'dancing'));
check('a motion job starts',                            !empty($r['ok']), json_encode(array_diff_key($r, array('job' => 1))));
$mj = $jobs->get_by_id($r['job_id']); $queue_clean($mj['id']);
check('1080p picks the pro model',                      (string) $mj['model_key'] === 'kling_v3_motion_pro' && (string) $mj['type'] === 'motion');
check('charged for the reference video\'s length',      (int) $mj['credits_charged'] === InfluencerConfig::metered_credits(InfluencerConfig::model('kling_v3_motion_pro'), $r['seconds']) && $bal() === $start - (int) $mj['credits_charged']);
InfluencerJobService::step($mj['id'], array('inline' => true));
$sent = end(FakeVidProvider::$seen);
check('sent as a video job with frame and motion video', $sent['_kind'] === 'video' && !empty($sent['image_url']) && !empty($sent['video_url']) && $sent['family'] === 'kling_motion' && (string) $sent['duration'] === (string) $r['seconds']);
$drop($mj['id']);
check('a cancelled motion run is refunded',             $bal() === $start);
$short = InfluencerConfig::model('kling_v3_motion_std');
check('motion limits: 3 to 30 seconds',                 (int) $short['min_seconds'] === 3 && (int) $short['max_seconds'] === 30);

/* ---- 8. replace character: the prompt ---- */
$wan = InfluencerConfig::model('wan_30_ref'); $sd = InfluencerConfig::model('seedance_20_ref');
$pw = InfluencerVideoActions::replace_prompt($wan, $infl, array('subject' => 'the woman in the red jacket', 'outfit' => 'video', 'ref_count' => 1));
check('Wan prompt starts with "Edit video" and the base', strpos($pw, 'Edit video. DIRECTLY EDIT Video 1. Do NOT generate a new scene or new environment. Use Video 1 as the base canvas, preserving the original background, lighting, and camera angle exactly. Apply a full-body and facial identity transfer from Image 1 onto the woman in the red jacket in Video 1. The subject must wear the clothing shown in the video and perform the exact original motion. Her face, body proportions, build and skin tone must match Image 1.') === 0, $pw);
$ps = InfluencerVideoActions::replace_prompt($sd, $infl, array('subject' => 'woman in the red jacket', 'outfit' => 'reference', 'lock_others' => true, 'remove_text' => true, 'ref_count' => 3));
check('Seedance prompt uses its own reference names',   strpos($ps, 'DIRECTLY EDIT @Video1.') === 0 && strpos($ps, 'from @Image1 onto the woman in the red jacket in @Video1') !== false && strpos($ps, 'Edit video') === false);
check('reference outfit swaps the clothing sentence',   strpos($ps, 'must wear the clothing shown in @Image1 and perform the exact original motion') !== false && strpos($ps, 'clothing shown in the video') === false);
check('the lock and text rules are added when on',      strpos($ps, 'Every other person and object in @Video1 stays exactly as in the original') !== false && strpos($ps, 'Remove all on-screen text, captions and subtitles') !== false
    && strpos($pw, 'Every other person') === false && strpos($pw, 'Remove all on-screen text') === false);
check('extra angle images are explained',               strpos($ps, 'The images after @Image1 show the same person from other angles.') !== false && strpos($pw, 'other angles') === false);
check('a man gets "His"',                               strpos(InfluencerVideoActions::replace_prompt($wan, array('gender' => 'man') + $infl, array('subject' => 'man')), 'His face, body proportions') !== false);

/* ---- 8. replace character: attestation, fingerprint, moderation ---- */
$seen_before = count(FakeVidProvider::$seen);
$r = InfluencerVideoActions::replace_start($cid, $infl, array('video_asset_id' => $vid, 'subject' => 'the woman'));
check('without the rights attestation it is refused',   empty($r['ok']) && !empty($r['need_attestation']), json_encode($r));
$r = InfluencerVideoActions::replace_start($cid, $infl, array('video_asset_id' => $vid, 'subject' => 'the woman', 'attested' => false));
check('attested = false is refused',                    empty($r['ok']) && !empty($r['need_attestation']));
check('a refused request charges nothing and sends nothing', $bal() === $start && count(FakeVidProvider::$seen) === $seen_before);
$threw = '';
try { McpTools::call('replace_character_in_video', $cid, array('influencer_id' => (int) $infl['id'], 'video_asset_id' => $vid, 'attested' => 'yes')); } catch (\Throwable $e) { $threw = $e->getMessage(); }
check('the connector accepts only attested = true',     strpos($threw, 'own this video') !== false, $threw);
$threw = '';
try { McpTools::call('replace_character_in_video', $cid, array('influencer_id' => (int) $infl['id'], 'video_asset_id' => $vid)); } catch (\Throwable $e) { $threw = $e->getMessage(); }
check('the connector refuses when attested is missing', strpos($threw, 'own this video') !== false, $threw);
$defs = array_column(McpTools::definitions(), null, 'name');
check('the connector schema requires attested',         in_array('attested', $defs['replace_character_in_video']['inputSchema']['required'], true));

$mv = InfluencerVideoActions::moderate_video($cid, '/nonexistent/file.mp4');
check('an unreadable source fails the safety check',    empty($mv['ok']));
$r = InfluencerVideoActions::replace_start($cid, $infl, array('video_asset_id' => $vid, 'subject' => 'the woman', 'attested' => true, 'prompt' => $pw, 'lock_others' => true));
check('with the attestation a replacement starts',      !empty($r['ok']), json_encode(array_diff_key($r, array('job' => 1))));
if (!empty($r['ok'])) {
    $rj = $jobs->get_by_id($r['job_id']); $queue_clean($rj['id']);
    check('the attestation time is stored on the job',  !empty($rj['attested_at']) && abs(strtotime((string) $rj['attested_at']) - time()) < 600);
    check('the source file hash is stored on the job',  preg_match('/^[0-9a-f]{64}$/', (string) $rj['source_hash']) === 1 && (string) $rj['source_hash'] === $r['source_hash']);
    check('the source video is the recorded input',     (int) $rj['input_asset_id'] === $vid && (string) $rj['type'] === 'replace' && (string) $rj['model_key'] === 'wan_30_ref');
    InfluencerJobService::step($rj['id'], array('inline' => true));
    $sent = end(FakeVidProvider::$seen);
    check('sent with the source video and her references', $sent['_kind'] === 'video' && !empty($sent['video_url']) && count($sent['image_urls']) >= 1 && $sent['family'] === 'wan_ref' && $sent['prompt'] === $pw);
    $drop($rj['id']);
}
check('balance restored after the replacement test',    $bal() === $start);
$row = $media->get_one($cid, $vid);
$media->set_moderation($vid, 'blocked', $row['moderation_score'], array(), null);
$r = InfluencerVideoActions::replace_start($cid, $infl, array('video_asset_id' => $vid, 'attested' => true));
$media->set_moderation($vid, (string) $row['moderation_status'], $row['moderation_score'], array_filter(explode(',', (string) $row['moderation_labels']), 'strlen'), null);
check('a blocked source video cannot be used',          empty($r['ok']) && $bal() === $start);

/* ---- 10. dialogue scenes ---- */
$lines = InfluencerVideoActions::scene_lines(array(array('speaker' => 1, 'text' => ' Did you see it? ', 'cue' => 'whispering', 'say' => 'see as "SEE"'), array('speaker' => 9, 'text' => 'No way.'), array('speaker' => 1, 'text' => ''), 'junk'), 2);
check('dialogue rows are cleaned and kept in order',    count($lines) === 2 && $lines[0]['text'] === 'Did you see it?' && $lines[1]['speaker'] === 2 && $lines[1]['cue'] === '');
$cast = array(array('label' => 'GIRL 1', 'who' => 'the woman shown in @Image1'), array('label' => 'GIRL 2', 'who' => 'a tall blonde woman in a denim jacket'));
$sp = InfluencerVideoActions::scene_prompt($cast, $lines, 'a kitchen at night.', 12);
check('the scene is a single take of the given length', strpos($sp, 'One single continuous take, 12 seconds long. No cuts') === 0);
check('speakers are labelled and lines are exact',      strpos($sp, '1. GIRL 1 (whispering): "Did you see it?" [pronunciation: see as "SEE"]') !== false && strpos($sp, '2. GIRL 2: "No way."') !== false);
check('the listener rule and the no-extras rule are added', strpos($sp, 'the listener keeps her mouth closed and reacts naturally') !== false && strpos($sp, 'No extra lines, no voice swaps, no cuts.') !== false);
check('one character has no listener rule',             strpos(InfluencerVideoActions::scene_prompt(array($cast[0]), array($lines[0]), '', 6), 'listener') === false);
$b = InfluencerVideoActions::scene_build($cid, $infl, array('lines' => array(array('speaker' => 1, 'text' => 'Okay so I have to tell you something and you cannot laugh.'), array('speaker' => 2, 'text' => 'I am already laughing.')),
    'second' => 'described', 'second_description' => 'A tall blonde woman in a denim jacket', 'setting' => 'a parked car at dusk', 'aspect' => '4:5'));
check('a two character scene builds',                   !empty($b['ok']) && count($b['cast']) === 2 && $b['cast'][1]['label'] === 'GIRL 2', json_encode($b));
check('length is estimated from the script, in limits', !empty($b['ok']) && $b['seconds'] >= 4 && $b['seconds'] <= 30 && $b['seconds'] === $b['suggested_seconds']);
check('a shape the model lacks falls back to 9:16',     !empty($b['ok']) && $b['aspect'] === '9:16');
check('a scene needs dialogue',                         empty(InfluencerVideoActions::scene_build($cid, $infl, array('lines' => array()))['ok']));
check('a described extra needs a description',          empty(InfluencerVideoActions::scene_build($cid, $infl, array('lines' => array(array('text' => 'hi')), 'second' => 'described'))['ok']));
check('she cannot be her own second character',         empty(InfluencerVideoActions::scene_build($cid, $infl, array('lines' => array(array('text' => 'hi')), 'second' => 'influencer', 'second_influencer_id' => (int) $infl['id']))['ok']));
check('length is capped at 30 seconds',                 InfluencerVideoActions::scene_build($cid, $infl, array('lines' => array(array('text' => 'hi')), 'seconds' => 90))['seconds'] === 30);
$r = InfluencerVideoActions::scene_start($cid, $infl, array('lines' => array(array('speaker' => 1, 'text' => 'Hi.')), 'seconds' => 4, 'model_key' => 'wan_30_scene_draft'));
check('a scene job starts on the Draft model',          !empty($r['ok']), json_encode(array_diff_key($r, array('job' => 1))));
if (!empty($r['ok'])) {
    $sj = $jobs->get_by_id($r['job_id']); $queue_clean($sj['id']);
    check('charged for its length',                     (int) $sj['credits_charged'] === InfluencerConfig::metered_credits(InfluencerConfig::model('wan_30_scene_draft'), 4) && $bal() === $start - (int) $sj['credits_charged']);
    InfluencerJobService::step($sj['id'], array('inline' => true));
    $sent = end(FakeVidProvider::$seen);
    check('sent with her references, length and 480p',  $sent['_kind'] === 'video' && count($sent['image_urls']) >= 1 && (int) $sent['duration'] === 4 && $sent['params']['resolution'] === '480p' && $sent['family'] === 'wan_ref' && strpos($sent['prompt'], 'shown in Image 1') !== false && $sent['aspect_value'] === '9:16' && empty($sent['video_url']));
    $drop($sj['id']);
}

foreach ($made_assets as $a) { $media->sql("DELETE FROM media_assets WHERE id = :id AND creator_id = :c", array(':id' => $a, ':c' => $cid)); }
check('balance is back where it started',               $bal() === $start, $bal() . ' vs ' . $start);
check('no test jobs left behind',                       count(array_filter($jobs->list_for_influencer($cid, (int) $infl['id'], 'motion,replace,scene', 50), function ($j) { return (string) $j['provider'] === 'fake'; })) === 0);

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
