<?php
/**
 * Clip editor: timeline rules, text drawing, and a real export through ffmpeg from files in the dev Library
 * (creator #1 "admin": a video, an image stored as PNG, an audio file). No provider is called and no AI credits
 * are involved. Skips the export when ffmpeg or fonts are missing.
 *   APPLICATION_ENV=development php tests/ai_clip_editor_test.php
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

$cid = 1; $mm = new MediaAssetsModel();
$pick = function ($type, $test = null) use ($mm, $cid) {
    foreach ((array) $mm->get_for_creator($cid, array('type' => $type)) as $a) {
        if ((string) $a['status'] === 'ready' && (string) $a['moderation_status'] !== 'blocked' && ($test === null || $test($a))) { return $a; }
    }
    return null;
};
$video = $pick('video', function ($a) { return (int) $a['duration_sec'] >= 4 && (int) $a['duration_sec'] <= 20; });
$png   = $pick('image', function ($a) { return stripos((string) $a['mime'], 'png') !== false; });
$audio = $pick('audio');
check('fixture: a video, a PNG image and an audio file', $video && $png && $audio);
if (!$video || !$png || !$audio) { echo "1 FAILED\n"; exit(1); }
$credits = (int) (new AiCreditsModel())->get_balance($cid);

/* ---- timeline rules ---- */
$n = ClipRenderer::normalize($cid, array());
check('an empty timeline is refused',                   in_array('Add at least one clip or image.', $n['errors'], true));
$n = ClipRenderer::normalize($cid, array('clips' => array(array('asset_id' => (int) $video['id'], 'start' => 1, 'end' => 3, 'mute' => true), array('asset_id' => (int) $png['id'], 'duration' => 2))));
check('a trimmed clip and a still add up',              empty($n['errors']) && $n['duration'] === 4.0 && $n['timeline']['clips'][0]['duration'] === 2.0 && $n['timeline']['clips'][0]['mute'] === true && $n['timeline']['clips'][1]['type'] === 'image');
$n2 = ClipRenderer::normalize($cid, array('clips' => array(array('asset_id' => (int) $video['id'], 'start' => 999, 'end' => -5), array('asset_id' => (int) $png['id']))));
check('trim times are clamped to the file',             $n2['timeline']['clips'][0]['end'] <= (float) $video['duration_sec'] + 1 && $n2['timeline']['clips'][0]['duration'] > 0);
check('a still defaults to 3 seconds',                  $n2['timeline']['clips'][1]['duration'] === ClipRenderer::STILL_DEFAULT);
check('a still is clamped to 0.5 to 30 seconds',        ClipRenderer::normalize($cid, array('clips' => array(array('asset_id' => (int) $png['id'], 'duration' => 500))))['timeline']['clips'][0]['duration'] === 30.0);
check('a missing clip is reported',                     !empty(ClipRenderer::normalize($cid, array('clips' => array(array('asset_id' => 999999999))))['errors']));
check('an audio file is not accepted as a clip',        !empty(ClipRenderer::normalize($cid, array('clips' => array(array('asset_id' => (int) $audio['id']))))['errors']));
$long = array(); for ($i = 0; $i < 12; $i++) { $long[] = array('asset_id' => (int) $png['id'], 'duration' => 30); }
check('an edit over five minutes is refused',           count(array_filter(ClipRenderer::normalize($cid, array('clips' => $long))['errors'], function ($e) { return strpos($e, 'up to 300 seconds') !== false; })) === 1);
$tl = array('clips' => array(array('asset_id' => (int) $video['id'], 'start' => 1, 'end' => 3), array('asset_id' => (int) $png['id'], 'duration' => 2)),
    'texts' => array(array('text' => 'First line of text', 'start' => 0, 'end' => 2), array('text' => '   '), array('text' => 'Second', 'font' => 'nope', 'size' => 'nope', 'position' => 'nope', 'color' => 'red', 'shadow' => false, 'start' => 3, 'end' => 99)),
    'overlays' => array(array('asset_id' => (int) $png['id'], 'anchor' => 'bottom_left', 'scale' => 500, 'start' => 1, 'end' => 0), array('asset_id' => (int) $video['id'])),
    'audio' => array('asset_id' => (int) $audio['id'], 'volume' => 250));
$n = ClipRenderer::normalize($cid, $tl);
$tx = $n['timeline']['texts'];
check('text overlays: shadow is on by default',         count($tx) === 2 && $tx[0]['shadow'] === true && $tx[1]['shadow'] === false);
check('unknown text options fall back to defaults',     $tx[1]['size'] === 'medium' && $tx[1]['position'] === 'lower' && $tx[1]['color'] === '#FFFFFF' && isset(ClipRenderer::fonts()[$tx[1]['font']]));
check('overlay timing is clamped to the edit',          $tx[1]['end'] === 4.0 && $n['timeline']['overlays'][0]['end'] === 4.0 && $n['timeline']['overlays'][0]['scale'] === 100);
check('a video is not accepted as an image overlay',    count($n['timeline']['overlays']) === 1 && count(array_filter($n['errors'], function ($e) { return strpos($e, 'image overlay') !== false; })) === 1);
check('the audio track volume is clamped',              $n['timeline']['audio']['asset_id'] === (int) $audio['id'] && $n['timeline']['audio']['volume'] === 100);
check('export sizes are 1080 wide at 9:16 and 3:4',     ClipRenderer::SIZES === array('9:16' => array(1080, 1920), '3:4' => array(1080, 1440)));

/* ---- projects ---- */
$m = new EditProjectsModel();
$c = ClipEditActions::create($cid, '', '3:4');
$pid = (int) ($c['project']['id'] ?? 0);
check('a project is created as a draft',                $pid > 0 && $c['project']['status'] === 'draft' && $c['project']['aspect'] === '3:4' && $c['project']['name'] === 'Untitled Edit');
check('exporting an empty project is refused',          empty(ClipEditActions::export($cid, $pid)['ok']));
unset($tl['overlays'][1]);
$s = ClipEditActions::save($cid, $pid, array('name' => 'Test Edit', 'aspect' => '9:16', 'timeline' => $tl));
$g = ClipEditActions::get($cid, $pid);
check('a saved project reopens with its timeline',      !empty($s['ok']) && $g['project']['name'] === 'Test Edit' && $g['project']['aspect'] === '9:16' && count($g['project']['timeline']['clips']) === 2
    && count($g['project']['timeline']['texts']) === 2 && $g['project']['duration'] === 4.0 && isset($g['project']['assets'][(int) $video['id']]['preview_url']) && !empty($g['fonts']));
check('an unknown shape is refused',                    empty(ClipEditActions::save($cid, $pid, array('aspect' => '16:9'))['ok']));
check('another creator cannot open, save or export it', empty(ClipEditActions::get(27, $pid)['ok']) && empty(ClipEditActions::save(27, $pid, array('name' => 'x'))['ok']) && empty(ClipEditActions::export(27, $pid)['ok']));
check('it is listed',                                   in_array($pid, array_column(ClipEditActions::listing($cid)['projects'], 'id'), true));

/* ---- drawing and a real export ---- */
if (!ClipRenderer::available()) { echo "SKIP export: ffmpeg or fonts not available here\n"; }
else {
    $png_path = ClipRenderer::text_png(array('text' => 'Hook line that is long enough to wrap onto a second line for sure', 'font' => array_key_first(ClipRenderer::fonts()), 'size' => 'large', 'color' => '#FFFFFF', 'position' => 'lower', 'shadow' => true), 1080, 1920);
    $info = $png_path !== '' ? getimagesize($png_path) : false;
    check('text is drawn to a frame-sized transparent PNG', $info && $info[0] === 1080 && $info[1] === 1920 && $info[2] === IMAGETYPE_PNG);
    if ($info) {
        $im = imagecreatefrompng($png_path); $corner = imagecolorsforindex($im, imagecolorat($im, 5, 5)); $inked = 0;
        for ($y = 1100; $y < 1700; $y += 6) { for ($x = 80; $x < 1000; $x += 6) { if (imagecolorsforindex($im, imagecolorat($im, $x, $y))['alpha'] < 100) { $inked++; } } }
        imagedestroy($im); @unlink($png_path);
        check('the PNG is clear except where the text is',  $corner['alpha'] === 127 && $inked > 50, 'inked ' . $inked);
    }
    $ex = ClipEditActions::export($cid, $pid);
    check('an export is queued and the project marked rendering', !empty($ex['ok']) && $ex['project']['status'] === 'rendering' && $ex['seconds'] === 4.0, json_encode($ex));
    $row = $m->get_one($cid, $pid);
    (new JobsModel())->sql("DELETE FROM jobs WHERE dedupe_key = :k", array(':k' => 'clip_render:' . $pid . ':' . $row['render_token']));   // rendered here, not by the worker
    check('a stale export token does nothing',          ClipEditActions::run_export($pid, $cid, 'not-the-token') === 'SKIP superseded or gone');
    $out = ClipEditActions::run_export($pid, $cid, (string) $row['render_token']);
    check('the render completes',                       strpos($out, 'DONE') === 0, $out);
    $st = ClipEditActions::status($cid, $pid);
    $res = $st['project']['result'] ?? null;
    check('the project is done with a result video',    $st['project']['status'] === 'done' && $res && $res['id'] > 0);
    if ($res) {
        $a = $mm->get_one($cid, (int) $res['id']);
        $p = VideoTools::probe(S3Service::presigned_get_url((string) $a['original_key'], 600));
        check('the export is 1080x1920, about 4 seconds, with sound', $p['width'] === 1080 && $p['height'] === 1920 && abs($p['duration'] - 4) < 0.4 && $p['has_audio'] && abs($p['fps'] - 30) < 0.5, json_encode($p));
        check('it is in the Library with lineage',      (string) $a['type'] === 'video' && (string) $a['status'] === 'ready' && (int) $a['source_asset_id'] === (int) $video['id'] && (string) $a['gen_model_key'] === 'clip_editor'
            && in_array((string) $a['provenance'], array('edited', 'uploaded'), true));
        $mm->soft_delete($cid, (int) $res['id']);
    }
    check('the project can be exported again',          !empty(ClipEditActions::export($cid, $pid)['ok']));
    (new JobsModel())->sql("DELETE FROM jobs WHERE dedupe_key LIKE :k", array(':k' => 'clip_render:' . $pid . ':%'));

    /* a render that never finishes (the worker died) is given up after 20 minutes, and a new export can start */
    $m->sql("UPDATE edit_projects SET updated_at = :t WHERE id = :id", array(':t' => date('Y-m-d H:i:s', time() - (ClipEditActions::STALE_MINUTES + 1) * 60), ':id' => $pid));
    $st = ClipEditActions::status($cid, $pid);
    check('a render stuck for over 20 minutes is reported failed', $st['project']['status'] === 'failed' && $st['project']['error'] === ClipEditActions::STALE_ERROR && (string) $m->get_one($cid, $pid)['status'] === 'failed', json_encode($st['project']));
    $ex = ClipEditActions::export($cid, $pid);
    check('a stalled project can be exported again',    !empty($ex['ok']) && $ex['project']['status'] === 'rendering' && ClipEditActions::status($cid, $pid)['project']['status'] === 'rendering');
    (new JobsModel())->sql("DELETE FROM jobs WHERE dedupe_key LIKE :k", array(':k' => 'clip_render:' . $pid . ':%'));

    /* a GIF still: ffmpeg's gif demuxer has no -loop, so it goes in as a looped stream */
    $gif_tmp = tempnam(sys_get_temp_dir(), 'cgif'); @unlink($gif_tmp); $gif_tmp .= '.gif';
    MediaService::run_with_timeout(escapeshellarg(MediaService::bin('ffmpeg')) . ' -y -loglevel error -f lavfi -i testsrc=size=64x64:rate=5 -t 1 ' . escapeshellarg($gif_tmp), 30);
    $gif_id = is_file($gif_tmp) ? (int) $mm->add($cid, 'gif', 'test.gif', 'image/gif', 'processing') : 0;
    $gif_key = $gif_id > 0 ? MediaService::key($cid, $gif_id, 'original', 'gif') : '';
    if ($gif_id > 0 && S3Service::put_private($gif_key, $gif_tmp, 'image/gif')) {
        $mm->set_ready($cid, $gif_id, array('original_key' => $gif_key, 'width' => 64, 'height' => 64, 'bytes' => (int) filesize($gif_tmp), 'moderation_status' => 'clean'));
        $gn = ClipRenderer::normalize($cid, array('clips' => array(array('asset_id' => $gif_id, 'duration' => 1.5))));
        check('a GIF is accepted as a still',            empty($gn['errors']) && $gn['timeline']['clips'][0]['type'] === 'image' && $gn['duration'] === 1.5, json_encode($gn['errors']));
        $gr = ClipRenderer::render($cid, $gn, '9:16');
        $gp = !empty($gr['ok']) ? VideoTools::probe($gr['path']) : null;
        check('a GIF still renders to video',            $gp && $gp['width'] === 1080 && $gp['height'] === 1920 && abs($gp['duration'] - 1.5) < 0.3 && $gp['has_audio'], json_encode($gr));
        if (!empty($gr['path']) && is_file($gr['path'])) { @unlink($gr['path']); }
        S3Service::delete_key($gif_key);
    } else { check('fixture: a GIF could be made and stored', false); }
    if ($gif_id > 0) { $mm->sql("DELETE FROM media_assets WHERE id = :id AND creator_id = :c", array(':id' => $gif_id, ':c' => $cid)); }
    @unlink($gif_tmp);
}
check('exports cost no AI credits',                     (int) (new AiCreditsModel())->get_balance($cid) === $credits);
check('a project can be deleted',                       !empty(ClipEditActions::delete($cid, $pid)['ok']) && empty(ClipEditActions::get($cid, $pid)['ok']));
$m->sql("DELETE FROM edit_projects WHERE id = :id AND creator_id = :c", array(':id' => $pid, ':c' => $cid));

echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
