<?php
/**
 * Video operations built on an influencer's identity references, shared by the web API
 * (ApiInfluencerVideosController) and the Claude connector (McpTools): frame extraction, Motion
 * Control, Replace Character and long dialogue Scenes. Same contract as InfluencerActions: clean
 * values in, array('ok' => bool, 'error' => string, ...payload) out. Every render is an
 * influencer_jobs row, so charging, refunds, polling and Library lineage are the job engine's.
 */
class InfluencerVideoActions {

    const SCENE_MAX_LINES = 20;
    const MODERATION_FRAMES = 6;

    private static function fail($error, array $extra = array()){ return array_merge(array('ok' => false, 'error' => (string) $error), $extra); }
    private static function okr(array $payload = array()){ return array_merge(array('ok' => true, 'error' => ''), $payload); }
    private static function job_json($cid, $job_id){ return InfluencerJobService::job_json($cid, (new InfluencerJobsModel())->get_by_id($job_id)); }
    private static function one_line($s, $max = 600){ return mb_substr(trim(preg_replace('/\s+/', ' ', (string) $s)), 0, $max); }

    /** A ready, unblocked asset of the creator's of one type, or null. */
    private static function asset($cid, $aid, $type){
        $a = (new MediaAssetsModel())->get_one($cid, (int) $aid);
        return ($a && (string) $a['type'] === $type && (string) $a['status'] === 'ready' && (string) $a['moderation_status'] !== 'blocked') ? $a : null;
    }

    private static function video_url(array $a){ return S3Service::presigned_get_url((string) $a['original_key'], 3600); }

    /** Length and size of a stored video, read from the file itself (the row only keeps whole seconds). */
    public static function probe_asset(array $a){
        $url = self::video_url($a);
        $p = ($url !== '') ? VideoTools::probe($url) : array('duration' => 0.0, 'width' => 0, 'height' => 0, 'has_video' => false, 'has_audio' => false, 'fps' => 0.0);
        if ($p['duration'] <= 0) { $p['duration'] = (float) ($a['duration_sec'] ?? 0); }
        if ($p['width'] <= 0)    { $p['width'] = (int) ($a['width'] ?? 0); $p['height'] = (int) ($a['height'] ?? 0); }
        return $p;
    }

    /* =====================================================================
     * 5. Frame extraction
     * =================================================================== */

    /** Export the frame at $seconds of a Library video as a new image. Returns ['asset_id', 'asset' => thumb/display urls]. */
    public static function extract_frame($cid, array $user, $video_aid, $seconds){
        $v = self::asset($cid, $video_aid, 'video');
        if (!$v) { return self::fail('Pick a ready video.'); }
        if (!VideoTools::available()) { return self::fail('Frame export is not available right now.'); }
        $url = self::video_url($v);
        $tmp = ($url !== '') ? VideoTools::frame_at($url, max(0, (float) $seconds)) : '';
        if ($tmp === '') { return self::fail('Could not read a frame from that video.'); }
        $bytes = (string) @file_get_contents($tmp);
        @unlink($tmp);
        if ($bytes === '') { return self::fail('Could not read a frame from that video.'); }
        // The same storage check the audio and video ingest paths make: the frame is a new Library file.
        $gb = Plan::limit($user, 'storage_gb');
        if ($gb !== null && (int) $gb > 0 && ((int) (new MediaAssetsModel())->total_bytes($cid) + strlen($bytes)) > (int) $gb * 1073741824) {
            return self::fail('Not enough storage left on your plan for this image', array('need_upgrade' => true));
        }
        $name = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', (string) ($v['display_name'] ?: $v['filename']));
        try {
            $r = MediaIngestService::ingest_image($cid, $user, $bytes, 'jpg', 'image/jpeg', 'Frame · ' . mb_substr($name, 0, 40), false);
        } catch (\Throwable $e) {
            return self::fail($e->getMessage());
        }
        $aid = (int) $r['asset_id'];
        $mm  = new MediaAssetsModel();
        // A frame is as AI-made as the video it came from: it carries the video's own provenance (uploaded, generated or edited).
        $mm->set_lineage($cid, $aid, array('provenance' => in_array((string) $v['provenance'], MediaAssetsModel::PROVENANCE, true) ? (string) $v['provenance'] : 'generated', 'source_asset_id' => (int) $v['id'],
            'model_key' => (string) $v['gen_model_key'], 'influencer_id' => (int) $v['gen_influencer_id']));
        $a = $mm->get_one($cid, $aid);
        return self::okr(array('asset_id' => $aid, 'seconds' => round(max(0, (float) $seconds), 2),
            'asset' => array('id' => $aid, 'type' => 'image', 'width' => (int) $a['width'], 'height' => (int) $a['height'],
                'thumb_url' => MediaService::signed_url($a, 'thumb', $cid), 'display_url' => MediaService::signed_url($a, 'display', $cid))));
    }

    /* =====================================================================
     * 6. Motion control
     * =================================================================== */

    /** How a subject is framed in a still, as one of: close-up | half body | full body | '' (cannot tell). */
    private static function framing_of(array $images){
        if (!ClaudeService::configured()) { return array(); }
        $r = ClaudeService::vision_multi('You compare how people are framed in images. Reply with ONLY one JSON object.',
            'For each image say how the main person is framed. Return {"framing": ["...", "..."]} with one entry per image, in order, each exactly one of: "close-up" (head and shoulders), "half body" (waist up), "full body" (head to feet), "none" (no person).',
            $images, 120, 45, 'low');
        if (empty($r['ok'])) { return array(); }
        $a = strpos($r['text'], '{'); $b = strrpos($r['text'], '}');
        $d = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($r['text'], $a, $b - $a + 1), true) : null;
        return is_array($d) ? array_values(array_map('strval', (array) ($d['framing'] ?? array()))) : array();
    }

    /** Bytes of a stored image's delivered rendition, or null. */
    private static function image_bytes(array $a){
        $key = (string) ($a['display_key'] ?: $a['original_key']);
        $tmp = tempnam(sys_get_temp_dir(), 'iva');
        if ($key === '' || $tmp === false || !S3Service::get_private_to_file($key, $tmp)) { if ($tmp) { @unlink($tmp); } return null; }
        $bytes = (string) @file_get_contents($tmp);
        @unlink($tmp);
        return ($bytes !== '') ? array('bytes' => $bytes, 'mime' => 'image/jpeg') : null;
    }

    /**
     * Before a motion run: the reference video's length (and what it will cost per quality), and warnings when the
     * first frame's shape or framing does not match the reference video. $image_aid 0 = only the video is checked.
     */
    public static function motion_check($cid, $video_aid, $image_aid = 0){
        $v = self::asset($cid, $video_aid, 'video');
        if (!$v) { return self::fail('Pick a ready video as the motion reference.'); }
        $p = self::probe_asset($v);
        $secs = (int) ceil($p['duration']);
        $warnings = array(); $errors = array(); $prices = array();
        foreach (InfluencerConfig::picker('motion') as $m) {
            $prices[(string) $m['key']] = array('label' => (string) $m['label'], 'credits' => InfluencerConfig::metered_credits($m, $secs),
                'min_seconds' => (int) ($m['min_seconds'] ?? 0), 'max_seconds' => (int) ($m['max_seconds'] ?? 0));
        }
        $first = InfluencerConfig::resolve_model('motion', '');
        $min = (int) ($first['min_seconds'] ?? 3); $max = (int) ($first['max_seconds'] ?? 30);
        if ($p['duration'] < $min) { $errors[] = 'The motion video is ' . round($p['duration'], 1) . ' seconds. It needs to be at least ' . $min . ' seconds.'; }
        if ($p['duration'] > $max + 0.3) { $errors[] = 'The motion video is ' . round($p['duration']) . ' seconds. The longest this model takes is ' . $max . ' seconds.'; }
        $img = $image_aid ? self::asset($cid, $image_aid, 'image') : null;
        if ($image_aid && !$img) { return self::fail('Pick a ready image as the first frame.'); }
        if ($img && $p['width'] > 0 && (int) $img['width'] > 0) {
            $va = Aspect::nearest($p['width'], $p['height']); $ia = Aspect::nearest((int) $img['width'], (int) $img['height']);
            $vr = $p['width'] / max(1, $p['height']); $ir = (int) $img['width'] / max(1, (int) $img['height']);
            if (abs($vr - $ir) > 0.08 * $vr) { $warnings[] = 'The first frame is ' . Aspect::label($ia) . ' and the motion video is ' . Aspect::label($va) . '. The result follows the first frame, so the movement may be cropped.'; }
            if (VideoTools::available()) {
                $f0 = VideoTools::frame_at(self::video_url($v), 0);
                $ib = self::image_bytes($img);
                if ($f0 !== '' && $ib) {
                    $fr = self::framing_of(array(array('bytes' => (string) file_get_contents($f0), 'mime' => 'image/jpeg', 'label' => 'Image 1, the first moment of the motion video:'),
                                                 array('bytes' => $ib['bytes'], 'mime' => $ib['mime'], 'label' => 'Image 2, the first frame to animate:')));
                    if (count($fr) === 2 && $fr[0] !== $fr[1] && $fr[0] !== 'none' && $fr[1] !== 'none') {
                        $warnings[] = 'The motion video starts as a ' . $fr[0] . ' shot and the first frame is a ' . $fr[1] . ' shot. Matching framing gives a closer result.';
                    }
                    if (count($fr) === 2 && $fr[1] === 'none') { $warnings[] = 'No person was found in the first frame.'; }
                }
                if ($f0 !== '') { @unlink($f0); }
            }
        }
        return self::okr(array('seconds' => $secs, 'duration' => round($p['duration'], 2), 'width' => (int) $p['width'], 'height' => (int) $p['height'],
            'aspect' => ($p['width'] > 0) ? Aspect::nearest($p['width'], $p['height']) : '', 'prices' => $prices, 'warnings' => $warnings, 'errors' => $errors));
    }

    /** "Make First Frame": frame 0 of the motion video, recreated with her in it (Replicate, Exact mode). Returns the replicate job. */
    public static function motion_first_frame($cid, array $user, array $infl, $video_aid, $origin = 'studio'){
        $f = self::extract_frame($cid, $user, $video_aid, 0);
        if (empty($f['ok'])) { return $f; }
        $v = (new MediaAssetsModel())->get_one($cid, (int) $video_aid);
        $aspect = ($v && (int) $v['width'] > 0) ? Aspect::nearest((int) $v['width'], (int) $v['height']) : Aspect::DEFAULT_VIDEO;
        $r = InfluencerImageActions::replicate($cid, $infl, array('source_asset_id' => (int) $f['asset_id'], 'mode' => 'exact', 'aspect' => $aspect, 'num_images' => 1), $origin);
        if (!empty($r['ok'])) { $r['frame_asset_id'] = (int) $f['asset_id']; }
        return $r;
    }

    /** Start a motion run. $in: video_asset_id, image_asset_id (the first frame), quality (720p | 1080p) or model_key, prompt. */
    public static function motion_start($cid, array $infl, array $in, $origin = 'studio'){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        $v   = self::asset($cid, (int) ($in['video_asset_id'] ?? 0), 'video');
        $img = self::asset($cid, (int) ($in['image_asset_id'] ?? 0), 'image');
        if (!$v) { return self::fail('Pick a ready video as the motion reference.'); }
        if (!$img) { return self::fail('Pick a first frame, or make one from the motion video.'); }
        $key = (string) ($in['model_key'] ?? '');
        if ($key === '') { foreach (InfluencerConfig::picker('motion') as $m) { if ((string) ($m['quality'] ?? '') === (string) ($in['quality'] ?? '')) { $key = (string) $m['key']; } } }
        $model = InfluencerConfig::resolve_model('motion', $key);
        if (!$model) { return self::fail('No motion model is configured.'); }
        $p = self::probe_asset($v);
        $min = (int) ($model['min_seconds'] ?? 3); $max = (int) ($model['max_seconds'] ?? 30);
        if ($p['duration'] < $min) { return self::fail('The motion video needs to be at least ' . $min . ' seconds long.'); }
        if ($p['duration'] > $max + 0.3) { return self::fail('The motion video can be at most ' . $max . ' seconds long.'); }
        $secs = (int) ceil($p['duration']);
        try {
            $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'motion', array(
                'origin' => $origin, 'model_key' => (string) $model['key'], 'prompt' => self::one_line($in['prompt'] ?? '', 1500), 'input_asset_id' => (int) $img['id'],
                'params' => array('duration' => $secs, 'video_asset_id' => (int) $v['id'], 'quality' => (string) ($model['quality'] ?? '')),
            ));
        } catch (PlanLimitException $e) {
            return self::fail($e->getMessage(), $e->limit);
        }
        if ($job_id <= 0) { return self::fail('Could not start the video.'); }
        return self::okr(array('job_id' => $job_id, 'job' => self::job_json($cid, $job_id), 'seconds' => $secs));
    }

    /* =====================================================================
     * 8. Character replacement in a video
     * =================================================================== */

    /** How a model family names its inputs inside a prompt: [video, image]. */
    public static function ref_tokens($family){
        return ((string) $family === 'wan_ref') ? array('Video 1', 'Image 1') : array('@Video1', '@Image1');
    }

    /**
     * The replacement prompt, from the fixed base. $o: subject (who to replace, e.g. "woman in the red jacket"),
     * outfit (video | reference), lock_others, remove_text, ref_count (identity images sent).
     */
    public static function replace_prompt(array $model, array $infl, array $o){
        list($V, $I) = self::ref_tokens((string) ($model['family'] ?? ''));
        list(, , $ps) = InfluencerService::pronouns($infl);
        $subject = rtrim(self::one_line($o['subject'] ?? '', 200), '.');
        if ($subject === '') { $subject = 'main ' . InfluencerService::noun($infl); }
        $subject = preg_replace('/^the\s+/i', '', $subject);
        $p = 'DIRECTLY EDIT ' . $V . '. Do NOT generate a new scene or new environment. Use ' . $V . ' as the base canvas, preserving the original background, lighting, and camera angle exactly. '
           . 'Apply a full-body and facial identity transfer from ' . $I . ' onto the ' . $subject . ' in ' . $V . '. '
           . ((($o['outfit'] ?? 'video') === 'reference')
                ? 'The subject must wear the clothing shown in ' . $I . ' and perform the exact original motion. '
                : 'The subject must wear the clothing shown in the video and perform the exact original motion. ')
           . ucfirst($ps) . ' face, body proportions, build and skin tone must match ' . $I . '.';
        if ((int) ($o['ref_count'] ?? 1) > 1) { $p .= ' The images after ' . $I . ' show the same person from other angles.'; }
        if (!empty($o['lock_others'])) { $p .= ' Every other person and object in ' . $V . ' stays exactly as in the original: do not change their faces, bodies, clothing, position or movement.'; }
        if (!empty($o['remove_text']))  { $p .= ' Remove all on-screen text, captions and subtitles from ' . $V . ' and rebuild the picture behind them.'; }
        if ((string) ($model['family'] ?? '') === 'wan_ref') { $p = 'Edit video. ' . $p; }
        return $p;
    }

    /**
     * Claude looks at the opening frame and turns the creator's description into a precise target ("the woman in
     * the red jacket on the left"). Returns ['subject' => ..., 'people' => count seen]. Falls back to the description as typed.
     */
    public static function replace_subject($cid, array $infl, array $video, $described){
        $described = self::one_line($described, 200);
        $out = array('subject' => $described, 'people' => 0);
        if (!ClaudeService::configured() || !VideoTools::available()) { return $out; }
        $f0 = VideoTools::frame_at(self::video_url($video), 0.3);
        if ($f0 === '') { return $out; }
        $bytes = (string) file_get_contents($f0); @unlink($f0);
        $r = ClaudeService::vision_multi('You identify one person in a video frame for a video editing instruction. Reply with ONLY one JSON object.',
            'Return {"people": how many people are visible, "subject": a short noun phrase that picks out ONE person so they cannot be confused with anyone else in the frame, by clothing and position, with no article at the start, e.g. "woman in the red jacket on the left"}. '
            . ($described !== '' ? 'The person meant is: ' . $described . '.' : 'The person meant is the main ' . InfluencerService::noun($infl) . ' in the frame.'),
            array(array('bytes' => $bytes, 'mime' => 'image/jpeg', 'label' => 'The opening frame of the video:')), 160, 45, 'low');
        if (empty($r['ok'])) { return $out; }
        $a = strpos($r['text'], '{'); $b = strrpos($r['text'], '}');
        $d = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($r['text'], $a, $b - $a + 1), true) : null;
        if (is_array($d)) {
            $s = self::one_line($d['subject'] ?? '', 200);
            if ($s !== '') { $out['subject'] = $s; }
            $out['people'] = max(0, (int) ($d['people'] ?? 0));
        }
        return $out;
    }

    /** Prepare a replacement: check the video's length, name the target, build the prompt, and price it. Nothing is charged. */
    public static function replace_prepare($cid, array $infl, array $in){
        $v = self::asset($cid, (int) ($in['video_asset_id'] ?? 0), 'video');
        if (!$v) { return self::fail('Pick a ready video as the source.'); }
        $model = InfluencerConfig::resolve_model('replace', (string) ($in['model_key'] ?? ''));
        if (!$model) { return self::fail('No replacement model is configured.'); }
        $refs = InfluencerService::identity_refs($cid, $infl, (int) ($model['max_refs'] ?? 6));
        if (empty($refs)) { return self::fail($infl['name'] . ' has no reference image yet.'); }
        $p = self::probe_asset($v);
        $errors = array();
        $min = (int) ($model['min_seconds'] ?? 2); $max = (int) ($model['max_seconds'] ?? 15);
        if ($p['duration'] < $min) { $errors[] = 'The source video needs to be at least ' . $min . ' seconds long.'; }
        if ($p['duration'] > $max + 0.3) { $errors[] = 'The source video is ' . round($p['duration']) . ' seconds. ' . $model['label'] . ' takes up to ' . $max . ' seconds. Trim it first.'; }
        $seen = self::replace_subject($cid, $infl, $v, (string) ($in['subject'] ?? ''));
        $secs = (int) ceil($p['duration']);
        $prices = array();
        foreach (InfluencerConfig::picker('replace') as $m) { $prices[(string) $m['key']] = InfluencerConfig::metered_credits($m, $secs); }
        $angles = max(0, count($refs) - 1);
        return self::okr(array('seconds' => $secs, 'duration' => round($p['duration'], 2), 'subject' => $seen['subject'], 'people' => $seen['people'], 'errors' => $errors,
            'prompt' => self::replace_prompt($model, $infl, array('subject' => $seen['subject'], 'outfit' => (string) ($in['outfit'] ?? 'video'),
                'lock_others' => !empty($in['lock_others']), 'remove_text' => !empty($in['remove_text']), 'ref_count' => count($refs))),
            'prices' => $prices, 'refs' => count($refs), 'angles' => $angles, 'angles_total' => count(InfluencerService::ANGLES)));
    }

    /**
     * Is a source video safe to edit? Samples frames across it and runs the image moderation on each.
     * Returns ['ok' => bool, 'error' => why not]. Fails closed: an unreachable classifier refuses the video.
     */
    public static function moderate_video($cid, $local_path){
        $frames = VideoTools::sample_frames($local_path, self::MODERATION_FRAMES);
        if (empty($frames)) { return array('ok' => false, 'error' => 'Could not read the source video to check it.'); }
        $verdict = array('ok' => true, 'error' => '');
        foreach ($frames as $f) {
            if ($verdict['ok']) {
                $key = 'vault/' . (int) $cid . '/tmp/modframe_' . bin2hex(random_bytes(8)) . '.jpg';
                if (!S3Service::put_private($key, $f, 'image/jpeg')) { $verdict = array('ok' => false, 'error' => 'Could not check the source video. Try again.'); }
                else {
                    $m = ModerationService::classify_image(S3Service::presigned_get_url($key, 600));
                    S3Service::delete_key($key);
                    if (empty($m['ok'])) { $verdict = array('ok' => false, 'error' => 'The source video could not be checked right now. Try again in a few minutes.'); }
                    elseif (!empty($m['minors'])) { $verdict = array('ok' => false, 'error' => 'That video cannot be used.'); }
                    elseif (!empty($m['adult'])) { $verdict = array('ok' => false, 'error' => 'Explicit source videos cannot be used for character replacement.'); }
                }
            }
            @unlink($f);
        }
        return $verdict;
    }

    /**
     * Start a replacement. $in: video_asset_id, subject, outfit (video | reference), lock_others, remove_text,
     * model_key, prompt (an edited prompt; built when empty), attested (required: the creator owns or has rights to
     * the source video). The attestation time and the source file's SHA-256 are stored on the job.
     */
    public static function replace_start($cid, array $infl, array $in, $origin = 'studio'){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        if (empty($in['attested'])) { return self::fail('Confirm that you own this video or have the rights to use it.', array('need_attestation' => true)); }
        $v = self::asset($cid, (int) ($in['video_asset_id'] ?? 0), 'video');
        if (!$v) { return self::fail('Pick a ready video as the source.'); }
        $model = InfluencerConfig::resolve_model('replace', (string) ($in['model_key'] ?? ''));
        if (!$model) { return self::fail('No replacement model is configured.'); }
        $refs = InfluencerService::identity_refs($cid, $infl, (int) ($model['max_refs'] ?? 6));
        if (empty($refs)) { return self::fail($infl['name'] . ' has no reference image yet.'); }
        if (!VideoTools::available()) { return self::fail('Video checks are not available right now.'); }

        // One local copy serves the length check, the fingerprint and the moderation frames.
        $tmp = tempnam(sys_get_temp_dir(), 'repsrc');
        if ($tmp === false || !S3Service::get_private_to_file((string) $v['original_key'], $tmp)) { if ($tmp) { @unlink($tmp); } return self::fail('Could not read the source video. Try again.'); }
        $p = VideoTools::probe($tmp);
        $hash = VideoTools::file_hash($tmp);
        $min = (int) ($model['min_seconds'] ?? 2); $max = (int) ($model['max_seconds'] ?? 15);
        if ($p['duration'] < $min || $p['duration'] > $max + 0.3) {
            @unlink($tmp);
            return self::fail(($p['duration'] < $min) ? 'The source video needs to be at least ' . $min . ' seconds long.' : $model['label'] . ' takes source videos up to ' . $max . ' seconds. Trim it first.');
        }
        $mod = self::moderate_video($cid, $tmp);
        @unlink($tmp);
        if (empty($mod['ok'])) { return self::fail($mod['error'], array('blocked_source' => true)); }

        $prompt = mb_substr(trim((string) ($in['prompt'] ?? '')), 0, 4000);
        $opts = array('subject' => (string) ($in['subject'] ?? ''), 'outfit' => (($in['outfit'] ?? '') === 'reference') ? 'reference' : 'video',
            'lock_others' => !empty($in['lock_others']), 'remove_text' => !empty($in['remove_text']), 'ref_count' => count($refs));
        if ($prompt === '') {
            $seen = self::replace_subject($cid, $infl, $v, $opts['subject']);
            $opts['subject'] = $seen['subject'];
            $prompt = self::replace_prompt($model, $infl, $opts);
        }
        $secs = (int) ceil($p['duration']);
        try {
            $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'replace', array(
                'origin' => $origin, 'model_key' => (string) $model['key'], 'prompt' => $prompt, 'input_asset_id' => (int) $v['id'],
                'attested_at' => date('Y-m-d H:i:s'), 'source_hash' => $hash,
                'params' => array('duration' => $secs, 'image_asset_ids' => $refs, 'subject' => $opts['subject'], 'outfit' => $opts['outfit'],
                    'lock_others' => $opts['lock_others'], 'remove_text' => $opts['remove_text']),
            ));
        } catch (PlanLimitException $e) {
            return self::fail($e->getMessage(), $e->limit);
        }
        if ($job_id <= 0) { return self::fail('Could not start the replacement.'); }
        return self::okr(array('job_id' => $job_id, 'job' => self::job_json($cid, $job_id), 'seconds' => $secs, 'prompt' => $prompt, 'source_hash' => $hash));
    }

    /* =====================================================================
     * 10. Long dialogue scenes
     * =================================================================== */

    /** GIRL / GUY for a speaker label. */
    public static function speaker_word($gender){ return ((string) $gender === 'man') ? 'GUY' : 'GIRL'; }

    /**
     * The scene prompt. $cast: [['label' => 'GIRL 1', 'who' => "the woman in @Image1", 'pronoun' => 'her'], ...];
     * $lines: [['speaker' => 1|2, 'text', 'cue', 'say'], ...]; $setting: free text; $seconds: length.
     */
    public static function scene_prompt(array $cast, array $lines, $setting, $seconds){
        $p = 'One single continuous take, ' . (int) $seconds . ' seconds long. No cuts, no scene changes, no camera jumps. ';
        $who = array();
        foreach ($cast as $c) { $who[] = $c['label'] . ' is ' . $c['who']; }
        $p .= 'Characters: ' . implode('. ', $who) . '. ';
        $setting = rtrim(self::one_line($setting, 800), '.');
        if ($setting !== '') { $p .= 'Setting: ' . $setting . '. '; }
        $p .= 'Dialogue, spoken in this exact order with exactly these words: ';
        $n = 0;
        foreach ($lines as $l) {
            $n++;
            $label = $cast[max(0, (int) $l['speaker'] - 1)]['label'] ?? $cast[0]['label'];
            $cue = rtrim(self::one_line($l['cue'] ?? '', 160), '.');
            $say = rtrim(self::one_line($l['say'] ?? '', 200), '.');
            $p .= $n . '. ' . $label . ($cue !== '' ? ' (' . $cue . ')' : '') . ': "' . str_replace('"', "'", self::one_line($l['text'], 500)) . '"' . ($say !== '' ? ' [pronunciation: ' . $say . ']' : '') . ' ';
        }
        if (count($cast) > 1) {
            $her = (strpos(implode(' ', array_column($cast, 'label')), 'GUY') === false) ? 'her' : 'their';
            $p .= 'While one character speaks, the listener keeps ' . $her . ' mouth closed and reacts naturally: looking at the speaker, small nods, changes of expression. ';
        }
        $p .= 'Each character keeps one voice for the whole scene. No extra lines, no voice swaps, no cuts. Lip movement matches the words exactly. Photorealistic, natural light, handheld phone video feel.';
        return $p;
    }

    /** How a model family names its n-th reference image inside a prompt. */
    public static function image_token($family, $n){
        return ((string) $family === 'wan_ref') ? 'Image ' . (int) $n : '@Image' . (int) $n;
    }

    /** " (Image 2 shows the same woman from another angle)" / " (Image 2 to Image 4 show ... from other angles)", or '' when there are none. */
    private static function more_refs($family, $from, $to, $noun){
        if ($to < $from) { return ''; }
        return ($to === $from)
            ? ' (' . self::image_token($family, $from) . ' shows the same ' . $noun . ' from another angle)'
            : ' (' . self::image_token($family, $from) . ' to ' . self::image_token($family, $to) . ' show the same ' . $noun . ' from other angles)';
    }

    /** Clean dialogue rows from the page or the connector. */
    public static function scene_lines($rows, $speakers){
        $out = array();
        foreach ((array) $rows as $r) {
            if (!is_array($r)) { continue; }
            $text = self::one_line($r['text'] ?? '', 500);
            if ($text === '') { continue; }
            $out[] = array('speaker' => max(1, min((int) $speakers, (int) ($r['speaker'] ?? 1))), 'text' => $text,
                'cue' => self::one_line($r['cue'] ?? '', 160), 'say' => self::one_line($r['say'] ?? ($r['pronunciation'] ?? ''), 200));
            if (count($out) >= self::SCENE_MAX_LINES) { break; }
        }
        return $out;
    }

    /** A sensible length for a script: about 2.6 words a second plus a beat per line, inside the model's limits. */
    public static function scene_seconds(array $lines, array $model){
        $words = 0;
        foreach ($lines as $l) { $words += str_word_count((string) $l['text']); }
        $secs = (int) ceil($words / 2.6 + count($lines) * 0.6);
        return max((int) ($model['min_seconds'] ?? 4), min((int) ($model['max_seconds'] ?? 30), $secs));
    }

    /**
     * Assemble a scene without starting it: cast, prompt, length, price. $in: lines, setting, second (none |
     * influencer | described), second_influencer_id, second_description, second_gender, seconds, aspect, model_key.
     */
    public static function scene_build($cid, array $infl, array $in){
        $model = InfluencerConfig::resolve_model('scene', (string) ($in['model_key'] ?? ''));
        if (!$model) { return self::fail('No scene model is configured.'); }
        $second = in_array($in['second'] ?? 'none', array('influencer', 'described'), true) ? (string) $in['second'] : 'none';
        $per = ($second === 'influencer') ? 3 : 4;   // identity images per influencer, inside the model's limit
        $refs = InfluencerService::identity_refs($cid, $infl, $per);
        if (empty($refs)) { return self::fail($infl['name'] . ' has no reference image yet.'); }
        $noun = InfluencerService::noun($infl);
        $fam  = (string) ($model['family'] ?? '');
        $cast = array(array('label' => self::speaker_word($infl['gender'] ?? 'woman') . ' 1',
            'who' => 'the ' . $noun . ' shown in ' . self::image_token($fam, 1) . self::more_refs($fam, 2, count($refs), $noun)));
        $images = $refs;
        if ($second === 'influencer') {
            $other = (new InfluencersModel())->get_one($cid, (int) ($in['second_influencer_id'] ?? 0));
            if (!$other || (int) $other['id'] === (int) $infl['id']) { return self::fail('Pick a different influencer as the second character.'); }
            if (Plan::is_locked(InfluencerJobService::user($cid), 'influencers', (int) $other['id'])) { return self::fail('That influencer is over your plan\'s limit.'); }
            $r2 = InfluencerService::identity_refs($cid, $other, $per);
            if (empty($r2)) { return self::fail($other['name'] . ' has no reference image yet.'); }
            $start = count($images) + 1;
            $n2 = InfluencerService::noun($other);
            $cast[] = array('label' => self::speaker_word($other['gender'] ?? 'woman') . ' 2',
                'who' => 'the ' . $n2 . ' shown in ' . self::image_token($fam, $start) . self::more_refs($fam, $start + 1, $start + count($r2) - 1, $n2));
            $images = array_merge($images, $r2);
        } elseif ($second === 'described') {
            $desc = rtrim(self::one_line($in['second_description'] ?? '', 300), '.');
            if ($desc === '') { return self::fail('Describe the second character.'); }
            $cast[] = array('label' => self::speaker_word($in['second_gender'] ?? 'woman') . ' 2', 'who' => preg_replace('/^(a|an|the)\s+/i', 'a ', $desc, 1));
        }
        $lines = self::scene_lines($in['lines'] ?? array(), count($cast));
        if (empty($lines)) { return self::fail('Write at least one line of dialogue.'); }
        $min = (int) ($model['min_seconds'] ?? 4); $max = (int) ($model['max_seconds'] ?? 30);
        $suggested = self::scene_seconds($lines, $model);
        $secs = (int) ($in['seconds'] ?? 0);
        $secs = ($secs > 0) ? max($min, min($max, $secs)) : $suggested;
        $aspect = Aspect::for_model($model, (string) ($in['aspect'] ?? ''), Aspect::DEFAULT_VIDEO);
        $prices = array();
        foreach (InfluencerConfig::picker('scene') as $m) { $prices[(string) $m['key']] = InfluencerConfig::metered_credits($m, $secs); }
        return self::okr(array('model_key' => (string) $model['key'], 'cast' => $cast, 'lines' => $lines, 'images' => $images, 'seconds' => $secs, 'suggested_seconds' => $suggested,
            'min_seconds' => $min, 'max_seconds' => $max, 'aspect' => $aspect, 'prices' => $prices,
            'prompt' => self::scene_prompt($cast, $lines, (string) ($in['setting'] ?? ''), $secs)));
    }

    /** Start a scene (see scene_build for $in). An edited prompt can be passed as $in['prompt']. */
    public static function scene_start($cid, array $infl, array $in, $origin = 'studio'){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        $b = self::scene_build($cid, $infl, $in);
        if (empty($b['ok'])) { return $b; }
        $prompt = mb_substr(trim((string) ($in['prompt'] ?? '')), 0, 6000);
        if ($prompt === '') { $prompt = (string) $b['prompt']; }
        try {
            $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'scene', array(
                'origin' => $origin, 'model_key' => (string) $b['model_key'], 'prompt' => $prompt,
                'params' => array('duration' => (int) $b['seconds'], 'aspect' => (string) $b['aspect'], 'image_asset_ids' => $b['images'],
                    'lines' => $b['lines'], 'cast' => array_column($b['cast'], 'label'), 'setting' => self::one_line($in['setting'] ?? '', 800)),
            ));
        } catch (PlanLimitException $e) {
            return self::fail($e->getMessage(), $e->limit);
        }
        if ($job_id <= 0) { return self::fail('Could not start the scene.'); }
        return self::okr(array('job_id' => $job_id, 'job' => self::job_json($cid, $job_id), 'seconds' => (int) $b['seconds'], 'prompt' => $prompt));
    }
}
