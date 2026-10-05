<?php
/**
 * Image operations built on an influencer's identity references, shared by the web API
 * (ApiInfluencerImagesController) and the Claude connector (McpTools): the multi-angle reference
 * set, Replicate Photo, Edit By Instruction and Carousel sets. Same contract as InfluencerActions:
 * clean values in, array('ok' => bool, 'error' => string, ...payload) out, nothing echoed.
 * Every run is an influencer_jobs row, so charging, refunds, polling and Library lineage are the
 * job engine's (InfluencerJobService).
 */
class InfluencerImageActions {

    const CAROUSEL_MIN = 2;
    const CAROUSEL_MAX = 10;

    /** What each carousel focus asks the writer to vary. */
    const CAROUSEL_FOCUS = array(
        'angles'      => array('label' => 'Angles',           'ask' => 'Vary the camera angle and distance from shot to shot (wide, medium, close, from above, from the side, over the shoulder).'),
        'expressions' => array('label' => 'Expressions',      'ask' => 'Keep the framing similar and vary the facial expression and mood from shot to shot.'),
        'poses'       => array('label' => 'Poses',            'ask' => 'Vary the body pose and what the hands are doing from shot to shot.'),
        'details'     => array('label' => 'Detail Shots',     'ask' => 'Make these close detail shots of the same moment: hands, accessories, the outfit, objects being held, textures.'),
        'without_her' => array('label' => 'Shots Without Her', 'ask' => 'No person appears in these shots: the location, the props, the pets and the details of the scene on their own.'),
    );

    private static function fail($error, array $extra = array()){ return array_merge(array('ok' => false, 'error' => (string) $error), $extra); }
    private static function okr(array $payload = array()){ return array_merge(array('ok' => true, 'error' => ''), $payload); }
    private static function job_json($cid, $job_id){ return InfluencerJobService::job_json($cid, (new InfluencerJobsModel())->get_by_id($job_id)); }

    /** A ready, unblocked image of the creator's, or null. */
    private static function image($cid, $aid){
        $a = (new MediaAssetsModel())->get_one($cid, (int) $aid);
        return ($a && (string) $a['type'] === 'image' && (string) $a['status'] === 'ready' && (string) $a['moderation_status'] !== 'blocked') ? $a : null;
    }

    /** The delivered rendition of an image as ['bytes', 'mime'] (what Claude looks at and what a mask is painted on), or null. */
    private static function image_bytes(array $a){
        $key = (string) ($a['display_key'] ?: $a['original_key']);
        $tmp = tempnam(sys_get_temp_dir(), 'iia');
        if ($key === '' || $tmp === false || !S3Service::get_private_to_file($key, $tmp)) { if ($tmp) { @unlink($tmp); } return null; }
        $bytes = (string) @file_get_contents($tmp);
        $mime  = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        @unlink($tmp);
        return ($bytes !== '' && strpos($mime, 'image/') === 0) ? array('bytes' => $bytes, 'mime' => $mime) : null;
    }

    /** First {...} in a model reply, decoded; array() when there is none. */
    private static function json_from($text){
        $s = (string) $text;
        $a = strpos($s, '{'); $b = strrpos($s, '}');
        if ($a === false || $b === false || $b <= $a) { return array(); }
        $d = json_decode(substr($s, $a, $b - $a + 1), true);
        return is_array($d) ? $d : array();
    }

    private static function one_line($s, $max = 600){ return mb_substr(trim(preg_replace('/\s+/', ' ', (string) $s)), 0, $max); }

    /** A description as a bare phrase to drop into a sentence: no leading "She is wearing" / "A woman with", no capital, no full stop. */
    private static function phrase($s, $max = 400){
        $s = self::one_line($s, $max);
        $s = preg_replace('/^(?:(?:she|he|they|the (?:wo)?man|the person|the model)\s+(?:is|are)?\s*(?:wearing|wears|wear|has|have|sits|stands)\s+|(?:a|an|the)\s+(?:young\s+)?(?:wo)?man\s+with\s+|wearing\s+)/i', '', $s);
        $s = rtrim($s, " .");
        return ($s !== '' && !preg_match('/^[A-Z]{2}/', $s)) ? lcfirst($s) : $s;
    }

    /* =====================================================================
     * 9. Multi-angle reference set
     * =================================================================== */

    /** Every slot with its current image (approved or not), a running job, or the last error. */
    public static function angle_set_status($cid, array $infl){
        $by_img = array();
        foreach ((new InfluencerImagesModel())->list_for_influencer($cid, (int) $infl['id'], 'angle') as $r) { $by_img[(string) $r['angle']] = $r; }   // ordered oldest first: the newest wins
        $by_job = array();
        foreach ((new InfluencerJobsModel())->list_for_influencer($cid, (int) $infl['id'], 'angle', 80) as $j) {   // newest first
            $slot = (string) (InfluencerJobsModel::params($j)['angle'] ?? '');
            if ($slot !== '' && !isset($by_job[$slot])) { $by_job[$slot] = $j; }
        }
        $slots = array(); $approved = 0; $active = 0;
        foreach (InfluencerService::ANGLES as $slot => $def) {
            $row = array('slot' => $slot, 'label' => $def['label'], 'aspect' => $def['aspect'], 'status' => 'empty', 'approved' => false,
                'asset_id' => 0, 'thumb_url' => '', 'display_url' => '', 'job_id' => 0, 'error' => '');
            $j = $by_job[$slot] ?? null;
            if ($j && in_array((string) $j['status'], array('queued', 'submitting', 'running', 'landing'), true)) {
                $row['status'] = 'working'; $row['job_id'] = (int) $j['id']; $active++;
            } elseif (isset($by_img[$slot]) && (string) $by_img[$slot]['status'] === 'ready') {
                $a = $by_img[$slot];
                $row['status'] = 'ready'; $row['approved'] = !empty($a['approved']); $row['asset_id'] = (int) $a['id'];
                $row['thumb_url'] = MediaService::signed_url($a, 'thumb', $cid); $row['display_url'] = MediaService::signed_url($a, 'display', $cid);
                if ($row['approved']) { $approved++; }
            } elseif ($j && in_array((string) $j['status'], array('failed', 'cancelled'), true)) {
                $row['status'] = 'failed'; $row['job_id'] = (int) $j['id']; $row['error'] = (string) $j['error'];
            }
            $slots[] = $row;
        }
        $model = InfluencerConfig::resolve_model('angle', '');
        $base  = InfluencerService::base_reference($cid, $infl);
        $ba    = $base > 0 ? (new MediaAssetsModel())->get_one($cid, $base) : null;
        return self::okr(array('slots' => $slots, 'approved' => $approved, 'active' => $active, 'total' => count($slots),
            'price_each' => $model ? Plan::ai_price('angle', array('model_key' => $model['key'])) : 0,
            'reference' => $ba ? array('asset_id' => $base, 'thumb_url' => MediaService::signed_url($ba, 'thumb', $cid)) : null));
    }

    /**
     * Generate angle references. $slots: slot keys to (re)make; empty = every slot that has no image yet.
     * A slot that already has an image is rerolled: the new image shows in its place, and an approved old one stays
     * in use as an identity input until the new one is approved.
     */
    public static function angle_set_generate($cid, array $infl, array $slots = array(), $model_key = '', $origin = 'studio'){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        $base = InfluencerService::base_reference($cid, $infl);
        if ($base <= 0) { return self::fail($infl['name'] . ' has no reference image yet.'); }
        $model = InfluencerConfig::resolve_model('angle', (string) $model_key);
        if (!$model) { return self::fail('No model is configured for angle references.'); }
        $st = self::angle_set_status($cid, $infl);
        $by = array();
        foreach ($st['slots'] as $s) { $by[$s['slot']] = $s; }
        $slots = array_values(array_intersect(array_map('strval', $slots), array_keys(InfluencerService::ANGLES)));
        if (empty($slots)) {
            foreach ($by as $slot => $s) { if (in_array($s['status'], array('empty', 'failed'), true)) { $slots[] = $slot; } }
        }
        if (empty($slots)) { return self::fail('Every angle already has an image. Reroll the ones you want to replace.'); }
        $ids = array();
        foreach ($slots as $slot) {
            if (($by[$slot]['status'] ?? '') === 'working') { continue; }
            $def = InfluencerService::ANGLES[$slot];
            try {
                $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'angle', array(
                    'origin' => $origin, 'model_key' => (string) $model['key'], 'prompt' => InfluencerService::angle_prompt($infl, $slot),
                    'input_asset_id' => $base, 'group_key' => 'ang_' . (int) $infl['id'], 'group_index' => array_search($slot, array_keys(InfluencerService::ANGLES), true) + 1,
                    'params' => array('angle' => $slot, 'aspect' => $def['aspect'], 'num_images' => 1),
                ));
            } catch (PlanLimitException $e) {
                return self::fail($e->getMessage(), array_merge($e->limit, array('job_ids' => $ids)));
            }
            if ($job_id <= 0) { continue; }
            $ids[] = $job_id;
        }
        if (empty($ids)) { return self::fail('Those angles are already generating.'); }
        return self::okr(array('job_ids' => $ids, 'count' => count($ids)));
    }

    /** Approve (or un-approve) one angle reference; only approved ones are used as identity inputs. */
    public static function angle_approve($cid, array $infl, $aid, $approved = true){
        $im   = new InfluencerImagesModel();
        $link = $im->get_link($cid, (int) $infl['id'], (int) $aid);
        if (!$link || (string) $link['role'] !== 'angle' || !empty($link['is_excluded'])) { return self::fail('That is not one of her angle references.'); }
        if (!self::image($cid, $aid)) { return self::fail('That image is not ready.'); }
        $im->set_approved($cid, (int) $aid, $approved);
        if ($approved) {   // the earlier takes of this angle are set aside: one reference per angle
            foreach ($im->list_for_influencer($cid, (int) $infl['id'], 'angle') as $r) {
                if ((string) $r['angle'] === (string) $link['angle'] && (int) $r['id'] !== (int) $aid) { $im->set_excluded($cid, (int) $infl['id'], (int) $r['id'], 1); }
            }
        }
        return self::okr(array('asset_id' => (int) $aid, 'approved' => (bool) $approved));
    }

    /* =====================================================================
     * 1. Replicate a photo
     * =================================================================== */

    /**
     * What the image model is sent for a prompt written with @img tokens. The tokens are our own shorthand for
     * "the Nth image attached": no model defines them. So each becomes "image N", and the prompt opens with a line
     * saying what every numbered image is ($labels: number => what it shows), so the model is never left guessing.
     */
    public static function with_image_key($prompt, array $labels){
        $prompt = preg_replace('/@img\s*(\d+)/i', 'image $1', (string) $prompt);
        ksort($labels);
        $key = array();
        foreach ($labels as $n => $what) { $key[] = 'Image ' . (int) $n . ' is ' . rtrim((string) $what, '.') . '.'; }
        return trim(implode(' ', $key) . ' ' . $prompt);
    }

    /** The Style prompt: the spec's template, filled from what Claude saw in the two images. */
    public static function style_prompt(array $infl, array $seen, $extra = ''){
        list($pr) = InfluencerService::pronouns($infl);
        $She = ucfirst($pr);
        $features = self::phrase($seen['features'] ?? '', 300);
        $outfit   = self::phrase($seen['outfit'] ?? '', 300);
        $setting  = self::phrase($seen['setting'] ?? '', 500);
        $p = 'Recreate the scene and pose from @img1 featuring the exact face and physical features of the model in @img2' . ($features !== '' ? ' (' . rtrim($features, '.') . ')' : '') . '. '
           . $She . ' is posing EXACTLY like the model in @img1. '
           . $She . ' is wearing ' . ($outfit !== '' ? rtrim($outfit, '.') : 'the same outfit as in @img1') . '. '
           . 'The camera angle is exactly the same as @img1. '
           . 'Setting: ' . ($setting !== '' ? rtrim($setting, '.') : 'the same environment, background and lighting as @img1') . '. '
           . 'Photorealistic, UGC style, raw unedited photo, natural skin texture.';
        $extra = self::one_line($extra, 500);
        return $p . ($extra !== '' ? ' ' . rtrim($extra, '.') . '.' : '');
    }

    /** The Exact instruction: swap her in and keep the composition. */
    public static function exact_prompt(array $infl, array $seen, $extra = ''){
        $features = self::phrase($seen['features'] ?? '', 300);
        $p = 'Replace the person in @img1 with the model in @img2, using the exact face and physical features of the model in @img2' . ($features !== '' ? ' (' . rtrim($features, '.') . ')' : '') . '. '
           . 'Keep the pose, outfit, composition, background, lighting and camera angle of @img1 exactly as they are. Photorealistic, natural skin texture.';
        $extra = self::one_line($extra, 500);
        return $p . ($extra !== '' ? ' ' . rtrim($extra, '.') . '.' : '');
    }

    /**
     * Look at the source photo next to her reference: where the face is (to mask it), what is worn, the setting, her
     * features. Returns ['face' => box|null, 'seen' => [...], 'prompt' => the Style or Exact prompt, ready to edit].
     */
    public static function replicate_prepare($cid, array $infl, $source_aid, $mode = 'style', $extra = ''){
        $src = self::image($cid, $source_aid);
        if (!$src) { return self::fail('Pick a ready image as the source.'); }
        $refs = InfluencerService::identity_refs($cid, $infl);
        if (empty($refs)) { return self::fail($infl['name'] . ' has no reference image yet.'); }
        if (!ClaudeService::configured()) { return self::fail('Photo analysis is not available right now.'); }
        $ref = self::image($cid, $refs[0]);
        $sb  = self::image_bytes($src);
        $rb  = $ref ? self::image_bytes($ref) : null;
        if (!$sb || !$rb) { return self::fail('Could not read the source photo. Try again.'); }
        $noun = InfluencerService::noun($infl);
        $r = ClaudeService::vision_multi(
            'You analyse photos for an image generation workflow. Reply with ONLY one JSON object and nothing else.',
            'Return JSON with exactly these keys. '
            . '"face": the bounding box of the main person\'s face in image 1 as fractions of the image width and height, {"x": left, "y": top, "w": width, "h": height}, or null when no face is visible. '
            . '"outfit": the clothes and accessories of the person in image 1, as a phrase that completes "is wearing ...", starting with "a" or "an" and with no subject or verb. '
            . '"setting": the environment, background and lighting of image 1, as one phrase with no subject, starting with "a" or "an". '
            . '"features": the hair, eyes, skin tone and build of the ' . $noun . ' in image 2, as a short comma-separated phrase with no subject, verb or name.',
            array(array('bytes' => $sb['bytes'], 'mime' => $sb['mime'], 'label' => 'Image 1, the source photo:'),
                  array('bytes' => $rb['bytes'], 'mime' => $rb['mime'], 'label' => 'Image 2, the reference of the ' . $noun . ':')),
            500, 60, 'low');
        if (empty($r['ok'])) { return self::fail('Could not analyse the source photo right now.'); }
        $seen = self::json_from($r['text']);
        $face = FaceMask::clean_box($seen['face'] ?? null);
        $mode = ($mode === 'exact') ? 'exact' : 'style';
        return self::okr(array(
            'mode' => $mode, 'face' => $face, 'refs' => count($refs),
            'seen' => array('outfit' => self::one_line($seen['outfit'] ?? '', 300), 'setting' => self::one_line($seen['setting'] ?? '', 500), 'features' => self::one_line($seen['features'] ?? '', 300)),
            'prompt' => ($mode === 'exact') ? self::exact_prompt($infl, $seen, $extra) : self::style_prompt($infl, $seen, $extra),
        ));
    }

    /**
     * Run a replica. $in: source_asset_id (required), mode (style | exact), prompt (the edited Style prompt; built when
     * empty), instruction (extra), aspect, num_images (1 to 4), model_key, face (box to mask, from replicate_prepare),
     * strokes (brush mask), mask (false to send the source unmasked).
     */
    public static function replicate($cid, array $infl, array $in, $origin = 'studio'){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        $src = self::image($cid, (int) ($in['source_asset_id'] ?? 0));
        if (!$src) { return self::fail('Pick a ready image as the source.'); }
        $refs = InfluencerService::identity_refs($cid, $infl);
        if (empty($refs)) { return self::fail($infl['name'] . ' has no reference image yet.'); }
        $model = InfluencerConfig::resolve_model('replicate', (string) ($in['model_key'] ?? ''));
        if (!$model) { return self::fail('No replicate model is configured.'); }
        $mode   = (($in['mode'] ?? '') === 'exact') ? 'exact' : 'style';
        $extra  = self::one_line($in['instruction'] ?? '', 500);
        $prompt = mb_substr(trim((string) ($in['prompt'] ?? '')), 0, 4000);
        $face   = FaceMask::clean_box($in['face'] ?? null);
        $want_mask = !array_key_exists('mask', $in) || !empty($in['mask']);
        if ($prompt === '' || ($want_mask && $face === null && empty($in['strokes']) && !array_key_exists('face', $in))) {
            // Called without a prepared prompt or face box (the connector, or the page skipping Prepare): look at the photo now.
            $prep = self::replicate_prepare($cid, $infl, (int) $src['id'], $mode, $extra);
            if (empty($prep['ok'])) { return $prep; }
            if ($prompt === '') { $prompt = (string) $prep['prompt']; }
            if ($face === null) { $face = $prep['face']; }
        } elseif ($mode === 'exact' && $extra !== '' && stripos($prompt, rtrim($extra, '.')) === false) {
            $prompt .= ' ' . rtrim($extra, '.') . '.';
        }
        $strokes = FaceMask::clean_strokes($in['strokes'] ?? array());
        $params = array('mode' => $mode, 'aspect' => Aspect::for_model($model, (string) ($in['aspect'] ?? '')), 'num_images' => max(1, min(4, (int) ($in['num_images'] ?? 1))),
            'image_asset_ids' => $refs, 'user_prompt' => $prompt, 'instruction' => $extra, 'masked' => false);
        if ($want_mask && ($face !== null || !empty($strokes))) {
            $sb = self::image_bytes($src);
            $key = $sb ? FaceMask::store($cid, FaceMask::apply($sb['bytes'], $face, $strokes)) : '';
            if ($key === '') { return self::fail('Could not mask the source photo. Try again.'); }
            $params['source_key'] = $key; $params['masked'] = true; $params['face'] = $face;
            $prompt .= ' The face in @img1 is covered by a grey mask: take the face only from @img2.';
        }
        if (count($refs) > 1) { $prompt .= ' The images after @img2 show the same model from other angles.'; }
        // What the model reads: the images named in order (source first, then her reference, then her other angles).
        $labels = array(1 => 'the source photo: copy its scene, pose, outfit and framing', 2 => 'the model, ' . (string) $infl['name'] . ': use ' . ($infl['gender'] === 'man' ? 'his' : 'her') . ' face and physical features');
        for ($i = 3; $i <= count($refs) + 1; $i++) { $labels[$i] = 'the same model from another angle'; }
        $prompt = self::with_image_key($prompt, $labels);
        try {
            $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'replicate', array(
                'origin' => $origin, 'model_key' => (string) $model['key'], 'prompt' => $prompt, 'input_asset_id' => (int) $src['id'], 'params' => $params,
            ));
        } catch (PlanLimitException $e) {
            return self::fail($e->getMessage(), $e->limit);
        }
        if ($job_id <= 0) { return self::fail('Could not start the replica.'); }
        return self::okr(array('job_id' => $job_id, 'job' => self::job_json($cid, $job_id), 'prompt' => $prompt, 'masked' => $params['masked']));
    }

    /* =====================================================================
     * 2. Edit by instruction
     * =================================================================== */

    /** The instruction as the edit model receives it. */
    public static function edit_prompt($instruction){
        return 'Maintain the photo exactly as shown in the reference. Only change the following: ' . trim((string) $instruction);
    }

    /**
     * The edit models for one image, the ones that keep its shape first. An edit model can only return the shapes it
     * supports, so one that lacks the image's ratio reshapes it: 'keeps_shape' is false and 'reshapes_to' says to what.
     * $asset null = no image yet (every model is listed as keeping the shape).
     */
    public static function edit_models($asset = null){
        $ratio = ($asset && (int) $asset['width'] > 0 && (int) $asset['height'] > 0) ? Aspect::nearest((int) $asset['width'], (int) $asset['height']) : '';
        $keep = array(); $reshape = array();
        foreach (InfluencerConfig::picker('edit') as $m) {
            $o = array('key' => (string) $m['key'], 'label' => (string) $m['label'], 'purpose' => (string) $m['purpose'],
                'credits' => InfluencerConfig::metered_credits($m), 'keeps_shape' => true, 'reshapes_to' => '');
            if ($ratio !== '' && !Aspect::supports($m, $ratio)) {
                $o['keeps_shape'] = false; $o['reshapes_to'] = Aspect::for_model($m, $ratio);
                $reshape[] = $o;
            } else { $keep[] = $o; }
        }
        return array_merge($keep, $reshape);
    }

    /** Edit any Library or Gallery image by instruction; the result is a new asset whose parent is the source. */
    public static function edit_image($cid, $aid, $instruction, $model_key = '', $origin = 'studio'){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        $src = self::image($cid, $aid);
        if (!$src) { return self::fail('Pick a ready image to edit.'); }
        $instruction = mb_substr(trim((string) $instruction), 0, 1500);
        if ($instruction === '') { return self::fail('Describe the change you want.'); }
        if ((string) $model_key === '') { $first = self::edit_models($src); $model_key = (string) ($first[0]['key'] ?? ''); }   // unless asked, the model that keeps this image's shape
        $model = InfluencerConfig::resolve_model('edit', (string) $model_key);
        if (!$model) { return self::fail('No edit model is configured.'); }
        $link = (new InfluencerImagesModel())->influencer_for_asset($cid, (int) $src['id']);   // an edit of her image stays in her gallery
        $infl_id = 0;
        if ($link) {
            $infl = (new InfluencersModel())->get_one($cid, (int) $link['influencer_id']);
            if ($infl && !Plan::is_locked(InfluencerJobService::user($cid), 'influencers', (int) $infl['id'])) { $infl_id = (int) $infl['id']; }
        }
        try {
            $job_id = InfluencerJobService::create_job($cid, $infl_id, 'edit', array(
                'origin' => $origin, 'model_key' => (string) $model['key'], 'prompt' => self::edit_prompt($instruction), 'input_asset_id' => (int) $src['id'],
                'params' => array('aspect' => 'source', 'num_images' => 1, 'instruction' => $instruction),
            ));
        } catch (PlanLimitException $e) {
            return self::fail($e->getMessage(), $e->limit);
        }
        if ($job_id <= 0) { return self::fail('Could not start the edit.'); }
        return self::okr(array('job_id' => $job_id, 'job' => self::job_json($cid, $job_id)));
    }

    /** The version history of an asset, oldest first, with what made each one. */
    public static function versions($cid, $aid){
        $out = array();
        foreach ((new MediaAssetsModel())->versions($cid, (int) $aid) as $a) {
            $ready = ((string) $a['status'] === 'ready');
            $instruction = (string) $a['gen_prompt'];
            $lead = 'Only change the following: ';
            if (($p = strpos($instruction, $lead)) !== false) { $instruction = substr($instruction, $p + strlen($lead)); }
            $out[] = array('id' => (int) $a['id'], 'parent_id' => (int) $a['parent_asset_id'], 'provenance' => (string) $a['provenance'], 'status' => (string) $a['status'],
                'model_key' => (string) $a['gen_model_key'], 'change' => ((string) $a['provenance'] === 'edited') ? self::one_line($instruction, 200) : '',
                'thumb_url' => $ready ? MediaService::signed_url($a, 'thumb', $cid) : '', 'created_at' => (string) $a['created_at'], 'current' => ((int) $a['id'] === (int) $aid));
        }
        return self::okr(array('versions' => $out));
    }

    /* =====================================================================
     * 3. Carousel sets
     * =================================================================== */

    /**
     * Claude writes $count shot prompts that keep outfit, location, props and pets constant.
     * Returns ['constants' => string, 'prompts' => string[]] or a failure.
     */
    public static function carousel_plan(array $infl, $count, $focus, $seed_text, $seed_image = null){
        if (!ClaudeService::configured()) { return self::fail('Carousel writing is not available right now.'); }
        $noun = InfluencerService::noun($infl);
        $without = ($focus === 'without_her');
        $system = 'You plan a photo carousel for a social media post: several shots of ONE moment that will be shown together. '
            . 'Reply with ONLY one JSON object: {"constants": "...", "prompts": ["...", ...]}. '
            . '"constants" is one sentence listing what stays identical in every shot: the outfit, the location, the props, any pets, the time of day and the light. '
            . '"prompts" has exactly ' . (int) $count . ' entries. Each is one image prompt of 25 to 50 words that repeats those constants in its own words and then describes only what is different in that shot. '
            . (self::CAROUSEL_FOCUS[$focus]['ask'] ?? self::CAROUSEL_FOCUS['angles']['ask']) . ' '
            . ($without ? 'Do not mention any person in any prompt. ' : 'Refer to the subject as "the ' . $noun . '" and never describe ' . InfluencerService::pronouns($infl)[2] . ' face. ')
            . 'Photorealistic, natural light, looks like a phone photo. Keep it within what a mainstream social platform allows: no nudity, no explicit or sexual language.';
        $ask = 'Plan the carousel.' . (trim((string) $seed_text) !== '' ? ' The scene: ' . self::one_line($seed_text, 1500) : '') . ($seed_image ? ' The attached image is the seed scene: take the outfit, location, props and pets from it.' : '');
        $r = $seed_image
            ? ClaudeService::vision_multi($system, $ask, array(array('bytes' => $seed_image['bytes'], 'mime' => $seed_image['mime'], 'label' => 'The seed scene:')), 1800, 75, 'low')
            : ClaudeService::chat($system, array(array('role' => 'user', 'content' => $ask)), 1800, 60, 'low');
        if (empty($r['ok'])) { return self::fail('Could not plan the carousel right now.'); }
        $d = self::json_from($r['text']);
        $prompts = array();
        foreach ((array) ($d['prompts'] ?? array()) as $p) { $p = self::one_line($p, 900); if ($p !== '') { $prompts[] = $p; } }
        if (count($prompts) < (int) $count) { return self::fail('The carousel plan came back incomplete. Try again.'); }
        return self::okr(array('constants' => self::one_line($d['constants'] ?? '', 600), 'prompts' => array_slice($prompts, 0, (int) $count)));
    }

    /**
     * Read a seed image for the Carousel form: the scene in one or two plain sentences (outfit, place, props, pets,
     * light), which the creator can edit before generating. Never describes the person's face or body: the
     * carousel is of the influencer, not of whoever is in the seed.
     */
    public static function carousel_read($cid, array $infl, $asset_id){
        $a = self::image($cid, (int) $asset_id);
        if (!$a) { return self::fail('Pick a ready image as the seed.'); }
        if (!ClaudeService::configured()) { return self::fail('Reading images is not available right now. Describe the scene yourself.'); }
        $img = self::image_bytes($a);
        if (!$img) { return self::fail('Could not open that image. Describe the scene yourself.'); }
        $system = 'You describe the scene of a photo so it can be recreated with a different person. '
            . 'Output ONLY one or two plain sentences, 20 to 45 words, no quotes, no preamble. '
            . 'Cover: the outfit, the place, the props, any pets, and the light. '
            . 'Do not describe the person\'s face, hair, skin, age or body, and do not name anyone.';
        $r = ClaudeService::vision($system, 'Describe the scene.', $img['bytes'], $img['mime'], 220, 40, 'low');
        if (empty($r['ok']) || trim((string) $r['text']) === '') { return self::fail('Could not read that image. Describe the scene yourself.'); }
        return self::okr(array('scene' => self::one_line(BrandService::unquote((string) $r['text']), 600)));
    }

    /** The prompt one slot is rendered with: how to read the reference images, then the shot. */
    private static function carousel_slot_prompt($shot, $has_seed, $with_her, $ref_count){
        $lead = '';
        if ($has_seed && $with_her) {
            $lead = 'Use @img1 as the continuity reference for the outfit, location, props and pets. The model is the person in @img2' . ($ref_count > 1 ? ' (the images after it show the same model from other angles)' : '') . ': keep the exact face and physical features. ';
        } elseif ($has_seed) {
            $lead = 'Use @img1 as the continuity reference for the location, props and pets. Nobody appears in this shot. ';
        } else {
            $lead = 'The model is the person in @img1' . ($ref_count > 1 ? ' (the other images show the same model from other angles)' : '') . ': keep the exact face and physical features. ';
        }
        return preg_replace('/@img\s*(\d+)/i', 'image $1', $lead) . 'New photo: ' . $shot . ' Photorealistic, UGC style, raw unedited photo, natural skin texture.';
    }

    /**
     * Plan and start a carousel. $in: seed_asset_id and/or seed_text, count (2 to 10), focus, aspect, model_key.
     * One job per slot (type carousel, sharing the set's group_key), charged per slot.
     */
    public static function carousel_start($cid, array $infl, array $in, $origin = 'studio'){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        $count = (int) ($in['count'] ?? 0);
        if ($count < self::CAROUSEL_MIN || $count > self::CAROUSEL_MAX) { return self::fail('A carousel has ' . self::CAROUSEL_MIN . ' to ' . self::CAROUSEL_MAX . ' images.'); }
        $focus = isset(self::CAROUSEL_FOCUS[$in['focus'] ?? '']) ? (string) $in['focus'] : 'angles';
        $with_her = ($focus !== 'without_her');
        $seed_text = mb_substr(trim((string) ($in['seed_text'] ?? '')), 0, 2000);
        $seed = !empty($in['seed_asset_id']) ? self::image($cid, (int) $in['seed_asset_id']) : null;
        if (!empty($in['seed_asset_id']) && !$seed) { return self::fail('Pick a ready image as the seed scene.'); }
        if (!$seed && $seed_text === '') { return self::fail('Give the carousel a seed scene: an image or a description.'); }
        if (!$with_her && !$seed) { return self::fail('Shots without her need a seed image to keep the location and props consistent.'); }
        $refs = $with_her ? InfluencerService::identity_refs($cid, $infl) : array();
        if ($with_her && empty($refs)) { return self::fail($infl['name'] . ' has no reference image yet.'); }
        $model = InfluencerConfig::resolve_model('replicate', (string) ($in['model_key'] ?? ''));
        if (!$model) { return self::fail('No carousel model is configured.'); }
        // Nothing is planned or spent when the account cannot pay for the whole set.
        $user  = InfluencerJobService::user($cid);
        if (!Plan::can_use_creator_features($user)) { return self::fail('Choose a plan to generate with AI.', array('need_plan' => true)); }
        Plan::grant_monthly($user);
        $each  = Plan::ai_price('carousel', array('model_key' => (string) $model['key']));
        $bal   = (int) (new AiCreditsModel())->get_balance($cid);
        if ($bal < $each * $count) { return self::fail(Plan::credits_message('carousel', $each * $count, $bal), array('need_credits' => true, 'price' => $each * $count, 'balance' => $bal)); }

        $plan = self::carousel_plan($infl, $count, $focus, $seed_text, $seed ? self::image_bytes($seed) : null);
        if (empty($plan['ok'])) { return $plan; }
        $aspect = Aspect::for_model($model, (string) ($in['aspect'] ?? ''));
        $group  = 'car_' . (int) $infl['id'] . '_' . bin2hex(random_bytes(5));
        $set_id = (new CarouselSetsModel())->create($cid, (int) $infl['id'], array('group_key' => $group, 'seed_asset_id' => $seed ? (int) $seed['id'] : 0, 'seed_text' => $seed_text,
            'focus' => $focus, 'aspect' => $aspect, 'model_key' => (string) $model['key'], 'slot_count' => $count, 'constants' => (string) $plan['constants']));
        if ($set_id <= 0) { return self::fail('Could not start the carousel.'); }
        $ids = array();
        foreach ($plan['prompts'] as $i => $shot) {
            try {
                $ids[] = InfluencerJobService::create_job($cid, (int) $infl['id'], 'carousel', array(
                    'origin' => $origin, 'model_key' => (string) $model['key'], 'prompt' => self::carousel_slot_prompt($shot, (bool) $seed, $with_her, count($refs)),
                    'input_asset_id' => $seed ? (int) $seed['id'] : null, 'group_key' => $group, 'group_index' => $i + 1,
                    'params' => array('aspect' => $aspect, 'num_images' => 1, 'image_asset_ids' => $refs, 'shot' => $shot, 'set_id' => $set_id),
                ));
            } catch (PlanLimitException $e) {
                return self::fail($e->getMessage(), array_merge($e->limit, array('set_id' => $set_id, 'group_key' => $group)));
            }
        }
        return self::okr(array('set_id' => $set_id, 'group_key' => $group, 'job_ids' => $ids, 'constants' => (string) $plan['constants']));
    }

    /** The review grid: every slot of a set in order, with its image or error. */
    public static function carousel_status($cid, $set_id){
        $set = (new CarouselSetsModel())->get_one($cid, (int) $set_id);
        if (!$set) { return self::fail('Carousel not found.'); }
        $mm = new MediaAssetsModel();
        $slots = array(); $done = 0; $active = 0; $failed = 0;
        foreach ((new InfluencerJobsModel())->list_group((string) $set['group_key']) as $j) {
            if ((int) $j['creator_id'] !== (int) $cid) { continue; }
            $slot = array('job_id' => (int) $j['id'], 'index' => (int) $j['group_index'], 'status' => (string) $j['status'], 'error' => (string) $j['error'],
                'shot' => (string) (InfluencerJobsModel::params($j)['shot'] ?? ''), 'asset_id' => 0, 'thumb_url' => '', 'display_url' => '');
            $aid = (int) $j['result_asset_id'];
            $a = ((string) $j['status'] === 'done' && $aid > 0) ? $mm->get_one($cid, $aid) : null;
            if ($a && (string) $a['status'] === 'ready') {
                $slot['asset_id'] = $aid; $slot['thumb_url'] = MediaService::signed_url($a, 'thumb', $cid); $slot['display_url'] = MediaService::signed_url($a, 'display', $cid); $done++;
            } elseif (in_array((string) $j['status'], array('failed', 'cancelled'), true) || (string) $j['status'] === 'done') {
                $slot['status'] = 'failed'; if ($slot['error'] === '') { $slot['error'] = 'The image was removed.'; } $failed++;
            } else { $active++; }
            $slots[] = $slot;
        }
        usort($slots, function ($a, $b) { return $a['index'] <=> $b['index']; });
        return self::okr(array('set' => array('id' => (int) $set['id'], 'influencer_id' => (int) $set['influencer_id'], 'focus' => (string) $set['focus'], 'aspect' => (string) $set['aspect'],
            'model_key' => (string) $set['model_key'], 'count' => (int) $set['slot_count'], 'constants' => (string) $set['constants'], 'seed_text' => (string) $set['seed_text'],
            'seed_asset_id' => (int) $set['seed_asset_id'], 'created_at' => (string) $set['created_at']),
            'slots' => $slots, 'done' => $done, 'active' => $active, 'failed' => $failed,
            'price_each' => Plan::ai_price('carousel', array('model_key' => (string) $set['model_key']))));
    }

    /** Regenerate one slot: a failed one is retried as is, a finished one is rendered again and replaced. */
    public static function carousel_regenerate($cid, $job_id){
        $jobs = new InfluencerJobsModel();
        $job  = $jobs->get_one($cid, (int) $job_id);
        if (!$job || (string) $job['type'] !== 'carousel' || !empty($job['superseded_by'])) { return self::fail('That slot is not part of a carousel.'); }
        if (in_array((string) $job['status'], array('failed', 'cancelled'), true)) {
            $r = InfluencerJobService::retry($cid, (int) $job['id']);
            return empty($r['ok']) ? self::fail((string) $r['error'], !empty($r['need_credits']) ? array('need_credits' => true) : array()) : self::okr(array('job_id' => (int) $job['id']));
        }
        if ((string) $job['status'] !== 'done') { return self::fail('That image is still generating.'); }
        try {
            $new_id = InfluencerJobService::create_job($cid, (int) $job['influencer_id'], 'carousel', array(
                'origin' => (string) $job['origin'], 'model_key' => (string) $job['model_key'], 'prompt' => (string) $job['prompt'], 'input_asset_id' => (int) $job['input_asset_id'],
                'group_key' => (string) $job['group_key'], 'group_index' => (int) $job['group_index'], 'params' => InfluencerJobsModel::params($job),
            ));
        } catch (PlanLimitException $e) {
            return self::fail($e->getMessage(), $e->limit);
        }
        if ($new_id <= 0) { return self::fail('Could not regenerate that image.'); }
        $jobs->set_superseded((int) $job['id'], $new_id);
        return self::okr(array('job_id' => $new_id));
    }

    /** Use In Post: a draft post with the chosen images in the order given (the first is the cover). */
    public static function carousel_to_post($cid, array $asset_ids, $caption = ''){
        $ids = array();
        foreach ($asset_ids as $aid) { if (self::image($cid, (int) $aid) && !in_array((int) $aid, $ids, true)) { $ids[] = (int) $aid; } }
        if (count($ids) < 1) { return self::fail('Keep at least one image for the post.'); }
        if (count($ids) > self::CAROUSEL_MAX) { return self::fail('A post takes up to ' . self::CAROUSEL_MAX . ' images.'); }
        $posts = new PostsModel();
        $pid = (int) $posts->create_draft($cid, mb_substr((string) $caption, 0, 3000), 'free');
        if ($pid <= 0) { return self::fail('Could not create the draft post.'); }
        $posts->set_assets($cid, $pid, $ids, $ids[0]);
        return self::okr(array('post_id' => $pid, 'asset_ids' => $ids));
    }
}
