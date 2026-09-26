<?php
/**
 * The influencer operations shared by the web API (ApiInfluencersController) and the Claude
 * connector (McpTools): validation + job creation for uploads, reference, training set,
 * training, image/video generation, enhance and settings. Every method takes clean values
 * (already html-decoded) and returns array('ok' => bool, 'error' => string, ...payload).
 * Nothing here echoes or exits; callers decide how to answer.
 */
class InfluencerActions {

    private static function fail($error, array $extra = array()){
        return array_merge(array('ok' => false, 'error' => (string) $error), $extra);
    }
    private static function okr(array $payload = array()){
        return array_merge(array('ok' => true, 'error' => ''), $payload);
    }
    private static function json($cid, $id){
        return InfluencerService::influencer_json((int) $cid, (new InfluencersModel())->get_one($cid, $id));
    }

    /* ---- create / settings ---- */

    public static function create($cid, $name, $path, $gender = ''){
        $name = mb_substr(trim((string) $name), 0, 120);
        $path = ($path === 'reference') ? 'reference' : 'photos';
        $gender = InfluencerService::gender($gender);
        if ($name === '') { return self::fail('Give your influencer a name.'); }
        if ($gender === '') { return self::fail('Choose Woman or Man.'); }
        $m = new InfluencersModel();
        $cap = Plan::check_count(InfluencerJobService::user($cid), 'influencers', $m->count_for_creator($cid));
        if (empty($cap['ok'])) { return self::fail($cap['message'], array_intersect_key($cap, array_flip(array('need_plan', 'need_upgrade', 'limit', 'used', 'addon', 'addon_price')))); }
        if ($m->name_taken($cid, $name)) { return self::fail('You already have an influencer called ' . $name . '.'); }
        $id = $m->create($cid, $name, $path, 0, $gender);
        if ($id <= 0) { return self::fail('You already have an influencer called ' . $name . '.'); }
        $m->update_fields($cid, $id, array('wizard_step' => ($path === 'photos') ? 'photos' : 'input'));
        return self::okr(array('influencer' => self::json($cid, $id)));
    }

    /**
     * Persist wizard inputs / settings. $in may hold: name, gender, path, input_method, is_public,
     * source_description, reference_model_key, steer_text, prompt_defaults, negative_prompt,
     * share_accounts (array or comma list), step.
     */
    public static function update($cid, array $infl, array $in){
        $m = new InfluencersModel();
        $f = array();
        if (array_key_exists('name', $in)) {
            $name = mb_substr(trim((string) $in['name']), 0, 120);
            if ($name === '') { return self::fail('Give your influencer a name.'); }
            if ($m->name_taken($cid, $name, (int) $infl['id'])) { return self::fail('You already have an influencer called ' . $name . '.'); }
            $f['name'] = $name;
        }
        if (isset($in['path']) && in_array($in['path'], array('photos', 'reference'), true)) {
            if (!in_array((string) $infl['status'], array('draft', 'awaiting_reference', 'failed'), true) || !empty($infl['pending_model_id'])) {
                return self::fail('The path can only change before training starts.');
            }
            $f['path'] = $in['path'];
        }
        if (isset($in['gender']) && isset(InfluencerService::GENDERS[$in['gender']])) { $f['gender'] = $in['gender']; }
        if (isset($in['input_method']) && in_array($in['input_method'], array('text', 'face_photo'), true)) { $f['input_method'] = $in['input_method']; }
        if (array_key_exists('is_public', $in)) { $f['is_public'] = ((string) $in['is_public'] === '1' || $in['is_public'] === true) ? 1 : 0; }
        if (array_key_exists('source_description', $in)) { $f['source_description'] = mb_substr(trim((string) $in['source_description']), 0, 2000); }
        if (array_key_exists('reference_model_key', $in)) {
            $mk = InfluencerConfig::resolve_model('reference', (string) $in['reference_model_key']);
            $f['reference_model_key'] = $mk ? (string) $mk['key'] : InfluencerConfig::default_model_key('reference');
        }
        if (array_key_exists('steer_text', $in))      { $f['steer_text'] = mb_substr(trim((string) $in['steer_text']), 0, 1000); }
        if (array_key_exists('prompt_defaults', $in)) { $f['prompt_defaults'] = mb_substr(trim((string) $in['prompt_defaults']), 0, 2000); }
        if (array_key_exists('negative_prompt', $in)) { $f['negative_prompt'] = mb_substr(trim((string) $in['negative_prompt']), 0, 2000); }
        if (array_key_exists('share_accounts', $in)) {
            $sa = $in['share_accounts'];
            if (is_string($sa)) { $sa = array_filter(array_map('trim', explode(',', $sa)), 'strlen'); }
            $f['share_accounts'] = array_values(array_map('strval', (array) $sa));
        }
        if (isset($in['step'])) {
            $steps = InfluencerService::steps_for($f['path'] ?? (string) $infl['path']);
            $step  = (string) $in['step'];
            if (in_array($step, $steps, true) && !in_array($step, array('training', 'done'), true)) { $f['wizard_step'] = $step; }
        }
        if (!empty($f)) {
            $r = $m->update_fields($cid, $infl['id'], $f);
            if ($r === false) { return self::fail('You already have an influencer with that name.'); }
        }
        return self::okr(array('influencer' => self::json($cid, $infl['id'])));
    }

    public static function delete($cid, array $infl){
        $jobs = new InfluencerJobsModel();
        foreach ($jobs->list_for_influencer($cid, $infl['id'], '', 200) as $j) {
            if (in_array((string) $j['status'], array('queued', 'submitting', 'running'), true)) { InfluencerJobService::cancel_job($cid, (int) $j['id']); }
        }
        (new InfluencersModel())->soft_delete($cid, $infl['id']);
        return self::okr(array('message' => $infl['name'] . ' was removed. Their media stays in your library.'));
    }

    /* ---- photos ---- */

    /** Attach image bytes to her as upload | face (never watermarked; training data). */
    public static function attach_photo($cid, array $user, array $infl, $bytes, $ext, $mime, $role = 'upload'){
        $role = ($role === 'face') ? 'face' : 'upload';
        if (!S3Service::configured()) { return self::fail('Uploads are unavailable right now. Please try again shortly.'); }
        if (!empty($infl['pending_model_id'])) { return self::fail('Training is in progress. Wait for it to finish before changing the photos.'); }
        if (strlen((string) $bytes) > MediaLimits::MAX_IMAGE_BYTES) { return self::fail('That image is too large. Images can be up to 15 MB.'); }
        $gb = Plan::limit($user, 'storage_gb');
        if ($gb !== null && (int) $gb > 0 && ((int) (new MediaAssetsModel())->total_bytes($cid) + strlen((string) $bytes)) > (int) $gb * 1073741824) {
            return self::fail("You've reached your plan's storage limit. Upgrade or remove files to free up space.", array('need_upgrade' => true));
        }
        $im  = new InfluencerImagesModel();
        $max = (int) InfluencerConfig::get('training_max_photos', 50);
        if ($role === 'upload' && $im->count_role($cid, $infl['id'], 'upload') >= $max) {
            return self::fail('You can upload up to ' . $max . ' photos. Remove one to add another.');
        }
        try {
            $r = MediaIngestService::ingest_image($cid, $user, $bytes, $ext, $mime, $infl['name'] . ' · ' . ($role === 'face' ? 'face' : 'photo'), false);
        } catch (\Throwable $e) {
            return self::fail($e->getMessage());
        }
        $aid = (int) $r['asset_id'];
        (new MediaAssetsModel())->set_tags($cid, $aid, 'influencer:' . (int) $infl['id'] . ',' . $role);
        if ($role === 'face') {
            if (!empty($infl['face_asset_id'])) { $im->detach($cid, $infl['id'], $infl['face_asset_id']); }
            $im->attach($infl['id'], $cid, $aid, 'face', null, 0, 0);
            (new InfluencersModel())->update_fields($cid, $infl['id'], array('face_asset_id' => $aid, 'reference_asset_id' => $aid, 'input_method' => 'face_photo'));
        } else {
            $im->attach($infl['id'], $cid, $aid, 'upload', null, 0, $im->count_role($cid, $infl['id'], 'upload') + 1);
        }
        $images = InfluencerService::images_json($cid, (int) $infl['id'], $role);
        $mine = null;
        foreach ($images as $x) { if ((int) $x['id'] === $aid) { $mine = $x; break; } }
        return self::okr(array('image' => $mine, 'count' => count($images)));
    }

    /**
     * Add one of her own gallery images (generated, enhanced, reference) as a training photo. An asset belongs to one
     * influencer role only, so the unwatermarked original is copied into a new upload; the gallery keeps its image.
     */
    public static function attach_from_gallery($cid, array $user, array $infl, $aid){
        $aid  = (int) $aid;
        $link = (new InfluencerImagesModel())->get_link($cid, $infl['id'], $aid);
        if (!$link || !in_array((string) $link['role'], array('generated', 'enhanced', 'reference'), true)) { return self::fail('That image is not in her gallery.'); }
        $a = (new MediaAssetsModel())->get_one($cid, $aid);
        if (!$a || (string) $a['type'] !== 'image' || (string) $a['status'] !== 'ready') { return self::fail('That image is not ready.'); }
        $key = (string) ($a['original_key'] ?: $a['display_key']);
        $tmp = tempnam(sys_get_temp_dir(), 'infg');
        if ($tmp === false || !S3Service::get_private_to_file($key, $tmp)) { if ($tmp) { @unlink($tmp); } return self::fail('Could not read that image. Try again.'); }
        $bytes = (string) @file_get_contents($tmp);
        $mime  = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        @unlink($tmp);
        $types = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
        if ($bytes === '' || !isset($types[$mime])) { return self::fail('That image cannot be used for training.'); }
        return self::attach_photo($cid, $user, $infl, $bytes, $types[$mime], $mime, 'upload');
    }

    /** Detach an uploaded image (training-set slots are excluded, keeping job history). */
    public static function remove_image($cid, array $infl, $aid){
        if (!empty($infl['pending_model_id'])) { return self::fail('Training is in progress. Wait for it to finish before changing the photos.'); }
        $aid  = (int) $aid;
        $im   = new InfluencerImagesModel();
        $link = $im->get_link($cid, $infl['id'], $aid);
        if (!$link) { return self::fail('That image is not attached to this influencer.'); }
        if ((string) $link['role'] === 'training') { $im->set_excluded($cid, $infl['id'], $aid, 1); }
        else { $im->detach($cid, $infl['id'], $aid); }
        $f = array();
        if ((int) $infl['face_asset_id'] === $aid) { $f['face_asset_id'] = null; }
        if ((int) $infl['reference_asset_id'] === $aid) { $f['reference_asset_id'] = null; }
        if (!empty($f)) { (new InfluencersModel())->update_fields($cid, $infl['id'], $f); }
        return self::okr(array('count' => $im->count_role($cid, $infl['id'], (string) $link['role'])));
    }

    /* ---- training ---- */

    /** Train (or retrain): photos path uses her ready uploads, reference path the complete set. */
    public static function train($cid, array $infl){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        if (!empty($infl['pending_model_id'])) { return self::fail('A training run is already in progress.'); }
        $im = new InfluencerImagesModel();
        if ((string) $infl['path'] === 'reference') {
            $size = (int) InfluencerConfig::get('training_set_size', 10);
            if (empty($infl['reference_asset_id'])) { return self::fail('Approve a reference image first.'); }
            $ids = $im->ready_asset_ids($cid, $infl['id'], 'training');
            if (count($ids) < $size) { return self::fail('The training set is not complete yet (' . count($ids) . ' of ' . $size . ').'); }
        } else {
            $ids = $im->ready_asset_ids($cid, $infl['id'], 'upload');
            $min = (int) InfluencerConfig::get('training_min_photos', 10);
            $max = (int) InfluencerConfig::get('training_max_photos', 50);
            if (count($ids) < $min) { return self::fail('Upload at least ' . $min . ' photos to train (you have ' . count($ids) . ').'); }
            if (count($ids) > $max) { return self::fail('Training accepts at most ' . $max . ' photos. Remove some to continue.'); }
        }
        $r = InfluencerTrainingService::start($cid, $infl, $ids, 'wizard');
        if (empty($r['ok'])) { return self::fail((string) $r['error']); }
        return self::okr(array('job_id' => (int) $r['job_id'], 'model_id' => (int) $r['model_id'], 'influencer' => self::json($cid, $infl['id'])));
    }

    public static function models($cid, array $infl){
        $out = array();
        foreach ((new InfluencerModelsModel())->list_for_influencer($cid, $infl['id']) as $m) {
            $out[] = array('id' => (int) $m['id'], 'status' => (string) $m['status'], 'is_active' => (int) $m['is_active'], 'trigger_word' => (string) $m['trigger_word'],
                'provider' => (string) $m['provider'], 'steps' => (int) $m['steps'], 'image_count' => (int) $m['image_count'], 'error' => (string) $m['error'],
                'created_at' => (string) $m['created_at'], 'trained_at' => (string) $m['trained_at']);
        }
        return self::okr(array('models' => $out));
    }

    /* ---- Path B: reference + training set ---- */

    /** Generate a reference image from her description. $in: source_description?, reference_model_key? */
    public static function reference_generate($cid, array $infl, array $in){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        if ((string) $infl['path'] !== 'reference') { return self::fail('This influencer is trained from photos.'); }
        $f = array('input_method' => 'text');
        if (array_key_exists('source_description', $in)) { $f['source_description'] = mb_substr(trim((string) $in['source_description']), 0, 2000); }
        if (array_key_exists('reference_model_key', $in)) { $mk = InfluencerConfig::resolve_model('reference', (string) $in['reference_model_key']); $f['reference_model_key'] = $mk ? (string) $mk['key'] : ''; }
        $m = new InfluencersModel();
        $m->update_fields($cid, $infl['id'], $f);
        $infl = $m->get_one($cid, $infl['id']);
        $desc = trim((string) $infl['source_description']);
        if ($desc === '') { return self::fail('Describe the face first.'); }
        $model = InfluencerConfig::resolve_model('reference', (string) $infl['reference_model_key']);
        if (!$model) { return self::fail('No reference model is configured.'); }
        $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'reference', array(
            'origin' => 'wizard', 'model_key' => (string) $model['key'], 'prompt' => $desc,
            'params' => array('image_size' => 'square', 'num_images' => 1),
        ));
        if ($job_id <= 0) { return self::fail('Could not start the reference image.'); }
        return self::okr(array('job_id' => $job_id));
    }

    /** Approve a reference (a generated candidate or her face photo). */
    public static function reference_pick($cid, array $infl, $aid){
        $aid  = (int) $aid;
        $link = (new InfluencerImagesModel())->get_link($cid, $infl['id'], $aid);
        if (!$link || !in_array((string) $link['role'], array('reference', 'face'), true)) { return self::fail('Pick one of the reference images.'); }
        $a = (new MediaAssetsModel())->get_one($cid, $aid);
        if (!$a || (string) $a['status'] !== 'ready') { return self::fail('That image is not ready yet.'); }
        $m = new InfluencersModel();
        $m->update_fields($cid, $infl['id'], array('reference_asset_id' => $aid, 'wizard_step' => 'set'));
        $m->transition($infl['id'], array('status' => 'draft'), "status = 'awaiting_reference'");
        return self::okr(array('influencer' => self::json($cid, $infl['id'])));
    }

    /** Create the training set from the approved reference: one job per image, seeds recorded. */
    public static function training_set_start($cid, array $infl, $steer_text = null){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        if (empty($infl['reference_asset_id'])) { return self::fail('Approve a reference image first.'); }
        if (!empty($infl['pending_model_id'])) { return self::fail('Training is in progress.'); }
        $m = new InfluencersModel();
        if ($steer_text !== null) { $m->update_fields($cid, $infl['id'], array('steer_text' => mb_substr(trim((string) $steer_text), 0, 1000))); $infl = $m->get_one($cid, $infl['id']); }
        $model = InfluencerConfig::resolve_model('training_set', '');
        if (!$model) { return self::fail('No training-set model is configured.'); }
        $size  = (int) InfluencerConfig::get('training_set_size', 10);
        $steer = trim((string) $infl['steer_text']);
        $im = new InfluencerImagesModel();
        if (!empty($infl['training_set_group'])) {   // an earlier set is set aside so only the current one trains
            foreach ($im->list_for_influencer($cid, $infl['id'], 'training') as $old) { $im->set_excluded($cid, $infl['id'], $old['id'], 1); }
        }
        $group = 'ts_' . (int) $infl['id'] . '_' . bin2hex(random_bytes(4));
        $m->update_fields($cid, $infl['id'], array('training_set_group' => $group, 'wizard_step' => 'set'));
        $vars = InfluencerService::TRAINING_VARIATIONS;
        $ids  = array();
        for ($i = 1; $i <= $size; $i++) {
            $prompt = $vars[($i - 1) % count($vars)] . ($steer !== '' ? ', ' . $steer : '');
            $ids[] = InfluencerJobService::create_job($cid, (int) $infl['id'], 'training_set', array(
                'origin' => 'wizard', 'model_key' => (string) $model['key'], 'prompt' => $prompt, 'input_asset_id' => (int) $infl['reference_asset_id'],
                'group_key' => $group, 'group_index' => $i,
                'params' => array('aspect_ratio' => '1:1', 'num_images' => 1, 'image_size' => 'square'),
            ));
        }
        return self::okr(array('group_key' => $group, 'job_ids' => $ids));
    }

    /** Progress of the current set: counts + every slot with its image or error. */
    public static function training_set_status($cid, array $infl){
        $group = (string) ($infl['training_set_group'] ?? '');
        $size  = (int) InfluencerConfig::get('training_set_size', 10);
        if ($group === '') { return self::okr(array('group_key' => '', 'size' => $size, 'done' => 0, 'failed' => 0, 'active' => 0, 'slots' => array(), 'complete' => false)); }
        $jobs = new InfluencerJobsModel();
        $mm   = new MediaAssetsModel();
        $slots = array(); $done = 0; $failed = 0; $active = 0;
        foreach ($jobs->list_group($group) as $j) {
            $slot = array('job_id' => (int) $j['id'], 'index' => (int) $j['group_index'], 'status' => (string) $j['status'], 'wait_reason' => (string) $j['wait_reason'],
                'seed' => (int) $j['seed'], 'prompt' => (string) $j['prompt'], 'error' => (string) $j['error'], 'asset_id' => 0, 'thumb_url' => '', 'display_url' => '');
            $aid = (int) $j['result_asset_id'];
            if ((string) $j['status'] === 'done' && $aid > 0) {
                $a = $mm->get_one($cid, $aid);
                if ($a && (string) $a['status'] === 'ready') {
                    $slot['asset_id'] = $aid; $slot['thumb_url'] = MediaService::signed_url($a, 'thumb', $cid); $slot['display_url'] = MediaService::signed_url($a, 'display', $cid);
                    $done++;
                } else { $slot['status'] = 'failed'; $slot['error'] = 'Image is not ready'; $failed++; }
            } elseif (in_array((string) $j['status'], array('failed', 'cancelled'), true)) { $failed++; }
            else { $active++; }
            $slots[] = $slot;
        }
        usort($slots, function ($a, $b) { return $a['index'] <=> $b['index']; });
        return self::okr(array('group_key' => $group, 'size' => $size, 'done' => $done, 'failed' => $failed, 'active' => $active, 'slots' => $slots, 'complete' => ($done >= $size)));
    }

    /** Retry a failed slot (same prompt + seed) or regenerate a finished one (new seed). */
    public static function training_set_retry($cid, array $infl, $job_id){
        if (!empty($infl['pending_model_id'])) { return self::fail('Training is in progress.'); }
        $jobs = new InfluencerJobsModel();
        $job  = $jobs->get_one($cid, (int) $job_id);
        if (!$job || (int) $job['influencer_id'] !== (int) $infl['id'] || (string) $job['group_key'] !== (string) $infl['training_set_group']) { return self::fail('That slot is not part of the current training set.'); }
        if (in_array((string) $job['status'], array('failed', 'cancelled'), true)) {
            $r = InfluencerJobService::retry($cid, (int) $job['id']);
            return empty($r['ok']) ? self::fail((string) $r['error']) : self::okr(array('job_id' => (int) $job['id']));
        }
        if ((string) $job['status'] !== 'done') { return self::fail('That image is still generating.'); }
        $new_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'training_set', array(
            'origin' => 'wizard', 'model_key' => (string) $job['model_key'], 'prompt' => (string) $job['prompt'], 'input_asset_id' => (int) $job['input_asset_id'],
            'group_key' => (string) $job['group_key'], 'group_index' => (int) $job['group_index'], 'params' => InfluencerJobsModel::params($job),
        ));
        if ($new_id <= 0) { return self::fail('Could not regenerate that image.'); }
        $jobs->set_superseded((int) $job['id'], $new_id);
        if (!empty($job['result_asset_id'])) { (new InfluencerImagesModel())->set_excluded($cid, $infl['id'], (int) $job['result_asset_id'], 1); }
        return self::okr(array('job_id' => $new_id));
    }

    /* ---- generation ---- */

    /**
     * Image run with her active weights. $in: prompt (required), model_key, image_size,
     * num_images, seed, level, guidance, steps, lora_scale. The prompt sent is her defaults +
     * the prompt, with her trigger word put in front when neither carries it.
     */
    public static function generate_image($cid, array $infl, array $in, $origin = 'studio'){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        if ((string) $infl['status'] !== 'ready' || empty($infl['active_model_id'])) { return self::fail($infl['name'] . ' has no trained model yet.'); }
        $model = (new InfluencerModelsModel())->get_by_id($infl['active_model_id']);
        if (!$model || (string) $model['status'] !== 'ready') { return self::fail($infl['name'] . '\'s active model is not ready.'); }
        $user_prompt = mb_substr(trim((string) ($in['prompt'] ?? '')), 0, 4000);
        if ($user_prompt === '') { return self::fail('Write a prompt first.'); }
        $defaults = trim((string) ($infl['prompt_defaults'] ?? ''));
        $trigger  = (string) $model['trigger_word'];
        if ($defaults !== '' && $trigger !== '' && stripos($defaults, $trigger) !== false && stripos($user_prompt, $trigger) !== false) {
            $defaults = trim(str_ireplace($trigger, '', $defaults));
        }
        // The trigger word identifies her to the model; the user never has to type it.
        $lead = ($trigger !== '' && stripos($defaults . ' ' . $user_prompt, $trigger) === false) ? $trigger . ' ' : '';
        $prompt = trim($lead . ($defaults !== '' ? $defaults . ' ' : '') . $user_prompt);
        $mk = InfluencerConfig::resolve_model('image', (string) ($in['model_key'] ?? ''));
        if (!$mk) { return self::fail('No image model is configured.'); }
        $size  = in_array($in['image_size'] ?? '', array('square', 'portrait', 'landscape'), true) ? $in['image_size'] : 'square';
        $n     = max(1, min(4, (int) ($in['num_images'] ?? 1)));
        $seed  = (int) ($in['seed'] ?? 0);
        $overrides = array();
        if (isset($in['guidance']) && $in['guidance'] !== '') { $overrides['guidance_scale'] = max(1, min(20, (float) $in['guidance'])); }
        if (isset($in['steps']) && $in['steps'] !== '')       { $overrides['num_inference_steps'] = max(4, min(50, (int) $in['steps'])); }
        $params = array('image_size' => $size, 'num_images' => $n, 'user_prompt' => $user_prompt, 'overrides' => $overrides,
            'lora_scale' => (isset($in['lora_scale']) && $in['lora_scale'] !== '') ? max(0.1, min(2.0, (float) $in['lora_scale'])) : (float) InfluencerConfig::get('training_lora_scale', 1.0));
        try {
            $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'image', array(
                'origin' => $origin, 'model_key' => (string) $mk['key'], 'model_id' => (int) $model['id'], 'prompt' => $prompt,
                'negative_prompt' => (string) ($infl['negative_prompt'] ?? ''), 'seed' => $seed > 0 ? $seed : null, 'params' => $params,
            ));
        } catch (PlanLimitException $e) {
            return self::fail($e->getMessage(), $e->limit);
        }
        if ($job_id <= 0) { return self::fail('Could not start the run.'); }
        return self::okr(array('job_id' => $job_id, 'job' => InfluencerJobService::job_json($cid, (new InfluencerJobsModel())->get_by_id($job_id)), 'trigger_word' => $trigger));
    }

    /** Image-to-video from one of her stills. $in: asset_id (required), prompt, model_key, duration. */
    public static function generate_video($cid, array $infl, array $in, $origin = 'studio'){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        $aid = (int) ($in['asset_id'] ?? 0);
        $a   = (new MediaAssetsModel())->get_one($cid, $aid);
        if (!$a || (string) $a['type'] !== 'image' || (string) $a['status'] !== 'ready') { return self::fail('Pick a ready image first.'); }
        // Any ready image in the creator's Studio Library can be animated (get_one already scopes it to them).
        $mk = InfluencerConfig::resolve_model('video', (string) ($in['model_key'] ?? ''));
        if (!$mk) { return self::fail('No video model is configured.'); }
        $user_prompt = mb_substr(trim((string) ($in['prompt'] ?? '')), 0, 2000);
        $defaults = trim((string) ($infl['prompt_defaults'] ?? ''));
        $prompt   = trim(($defaults !== '' ? $defaults . ' ' : '') . $user_prompt);
        $durs = array_values((array) ($mk['durations'] ?? array()));
        $dur  = (string) ($in['duration'] ?? ($durs[0] ?? '5'));
        if (!empty($durs) && !in_array($dur, $durs, true)) { $dur = (string) $durs[0]; }
        try {
            $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'video', array(
                'origin' => $origin, 'model_key' => (string) $mk['key'], 'model_id' => (int) ($infl['active_model_id'] ?? 0), 'prompt' => $prompt,
                'negative_prompt' => (string) ($infl['negative_prompt'] ?? ''), 'input_asset_id' => $aid,
                'params' => array('duration' => $dur, 'user_prompt' => $user_prompt),
            ));
        } catch (PlanLimitException $e) {
            return self::fail($e->getMessage(), $e->limit);
        }
        if ($job_id <= 0) { return self::fail('Could not start the video.'); }
        return self::okr(array('job_id' => $job_id, 'job' => InfluencerJobService::job_json($cid, (new InfluencerJobsModel())->get_by_id($job_id))));
    }

    /** Enhance (upscale) one of her images into a new asset. */
    public static function enhance($cid, array $infl, $aid, $model_key = '', $origin = 'studio'){
        if (!InfluencerConfig::enabled()) { return self::fail('Rendering is not configured yet (no provider key).'); }
        $aid = (int) $aid;
        $a   = (new MediaAssetsModel())->get_one($cid, $aid);
        if (!$a || (string) $a['type'] !== 'image' || (string) $a['status'] !== 'ready') { return self::fail('Pick a ready image first.'); }
        $link = (new InfluencerImagesModel())->get_link($cid, $infl['id'], $aid);
        if (!$link) { return self::fail('That image does not belong to this influencer.'); }
        $mk = InfluencerConfig::resolve_model('enhance', (string) $model_key);
        if (!$mk) { return self::fail('No enhance model is configured.'); }
        $src_prompt = '';   // the prompt that produced the source rides along; nothing else is added
        if (!empty($link['job_id'])) { $sj = (new InfluencerJobsModel())->get_one($cid, (int) $link['job_id']); $src_prompt = $sj ? (string) $sj['prompt'] : ''; }
        try {
            $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'enhance', array(
                'origin' => $origin, 'model_key' => (string) $mk['key'], 'prompt' => $src_prompt, 'negative_prompt' => (string) ($infl['negative_prompt'] ?? ''),
                'input_asset_id' => $aid, 'params' => array('num_images' => 1),
            ));
        } catch (PlanLimitException $e) {
            return self::fail($e->getMessage(), $e->limit);
        }
        if ($job_id <= 0) { return self::fail('Could not start the enhancement.'); }
        return self::okr(array('job_id' => $job_id, 'job' => InfluencerJobService::job_json($cid, (new InfluencerJobsModel())->get_by_id($job_id))));
    }

    /** Claude writes a scene prompt (image) or a motion prompt (video) for the influencer; returned as text, nothing is rendered. */
    public static function prompt_auto($cid, array $infl, $hint = '', $kind = 'image'){
        $trigger = '';
        if (!empty($infl['active_model_id'])) { $mdl = (new InfluencerModelsModel())->get_by_id($infl['active_model_id']); $trigger = $mdl ? (string) $mdl['trigger_word'] : ''; }
        if (!ClaudeService::configured()) { return self::fail('Prompt writing is not available right now.'); }
        $hint = mb_substr(trim((string) $hint), 0, 500);
        $noun = InfluencerService::noun($infl);
        list($pr, $po, $ps) = InfluencerService::pronouns($infl);
        if ($kind === 'video') {
            $system = 'You write one motion prompt for an image-to-video model. The starting image already shows a specific ' . $noun . ' and the scene; '
                . 'describe ONLY what happens over 5 to 10 seconds: ' . $ps . ' movement and expression, then one simple camera move (slow push in, gentle dolly, handheld, static). '
                . 'Refer to ' . $po . ' as "the ' . $noun . '" or "' . $pr . '". '
                . 'Output ONLY the prompt text, one line, 15 to 35 words, present tense, no quotes, no preamble, no scene description, no outfit. '
                . 'Keep it within what a mainstream social platform allows: no nudity, no explicit or sexual language.';
            $ask = 'Write a motion prompt for a short clip of ' . $infl['name'] . '.' . ($hint !== '' ? ' Idea: ' . $hint : ' Pick a natural, subtle movement.');
        } else {
            $system = 'You write one image-generation prompt for a photorealistic social-media photo of a specific ' . $noun . '. '
                . 'Output ONLY the prompt text, one line, 25 to 60 words, no quotes, no preamble. '
                . 'Always name the subject ("photo of a ' . $noun . ' ..."). Describe setting, outfit, pose, lighting and camera feel. Keep it within what a mainstream social platform allows: no nudity, no explicit or sexual language.';
            $ask = 'Write a prompt for a new post by ' . $infl['name'] . '.' . ($hint !== '' ? ' Theme: ' . $hint : ' Pick a fresh everyday scene.');
        }
        $r = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $ask)), 200, 30, 'low');
        if (empty($r['ok']) || trim((string) $r['text']) === '') { return self::fail('Could not write a prompt right now.'); }
        $text = trim(preg_replace('/\s+/', ' ', (string) $r['text']));
        if ($trigger !== '') { $text = trim(str_ireplace($trigger, '', $text)); }   // added at render time, never shown
        return self::okr(array('prompt' => $text));
    }

    /** Delete one of her assets from the library (soft delete, same as the Studio) and detach it from her. */
    public static function delete_asset($cid, array $infl, $aid){
        $aid  = (int) $aid;
        $im   = new InfluencerImagesModel();
        $link = $im->get_link($cid, $infl['id'], $aid);
        if (!$link) { return self::fail('That file does not belong to this influencer.'); }
        $mm = new MediaAssetsModel();
        $a  = $mm->get_one($cid, $aid);
        if ($a) {
            $mm->soft_delete($cid, $aid);
            (new CollectionsModel())->remove_asset_everywhere($aid);
        }
        $im->detach($cid, $infl['id'], $aid);
        $f = array();
        if ((int) $infl['face_asset_id'] === $aid) { $f['face_asset_id'] = null; }
        if ((int) $infl['reference_asset_id'] === $aid) { $f['reference_asset_id'] = null; }
        if (!empty($f)) { (new InfluencersModel())->update_fields($cid, $infl['id'], $f); }
        return self::okr(array('message' => 'File removed.', 'asset_id' => $aid));
    }

    /** Recent jobs (by type list) as job_json rows. */
    public static function jobs($cid, array $infl, $types = '', $limit = 24){
        $types = array_values(array_intersect(array_map('trim', explode(',', (string) $types)), InfluencerJobsModel::TYPES));
        $out = array();
        foreach ((new InfluencerJobsModel())->list_for_influencer($cid, $infl['id'], $types, (int) $limit) as $j) { $out[] = InfluencerJobService::job_json($cid, $j); }
        return self::okr(array('jobs' => $out));
    }
}
