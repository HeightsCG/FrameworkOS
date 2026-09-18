<?php
/**
 * AI influencer API: wizard CRUD, uploads, reference/training-set/training runs, image and
 * video generation, job status. Routed from /api/<action> by ApiRoutes; extends
 * BaseApiController (require_creator() returns the OWNER row). Every action is creator-scoped
 * through the models; nothing here reaches another creator's influencer.
 */
class ApiInfluencersController extends BaseApiController {

    /** Creator gate + ai_tools plan flag. */
    private function ai_user(){
        $user = $this->require_creator('content');
        if (!Plan::can($user, 'ai_tools')) {
            $this->jsonError('AI influencers are available on Pro and Studio plans.', ['need_upgrade' => true]);
        }
        return $user;
    }

    private function text($key, $max = 5000){
        return mb_substr(trim(html_entity_decode((string) ($this->post[$key] ?? ''), ENT_QUOTES, 'UTF-8')), 0, $max);
    }

    /** Owned influencer or a JSON error. */
    private function owned($creator_id, $id){
        $infl = (new InfluencersModel())->get_one($creator_id, (int) $id);
        if (!$infl) { $this->jsonError('Influencer not found.'); }
        return $infl;
    }

    /* ---- gallery / wizard ---- */

    public function influencer_listAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $out  = array();
        foreach ((new InfluencersModel())->list_for_creator($cid) as $row) {
            $out[] = InfluencerService::influencer_json($cid, $row);
        }
        $this->jsonSuccess(['influencers' => $out]);
    }

    public function influencer_getAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        $this->jsonSuccess(['influencer' => InfluencerService::influencer_json($cid, $infl)]);
    }

    /** Create from the Name step: name + path (the chooser's pick). */
    public function influencer_createAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $name = $this->text('name', 120);
        $path = (($this->post['path'] ?? '') === 'reference') ? 'reference' : 'photos';
        if ($name === '') { $this->jsonError('Give her a name.'); }
        $m = new InfluencersModel();
        if ($m->name_taken($cid, $name)) { $this->jsonError('You already have an influencer called ' . $name . '.'); }
        $id = $m->create($cid, $name, $path, 0);
        if ($id <= 0) { $this->jsonError('You already have an influencer called ' . $name . '.'); }
        $next = ($path === 'photos') ? 'photos' : 'input';
        $m->update_fields($cid, $id, array('wizard_step' => $next));
        $this->jsonSuccess(['influencer' => InfluencerService::influencer_json($cid, $m->get_one($cid, $id))]);
    }

    /**
     * Persist wizard inputs + the step the user is on. Accepts any subset of: name, path,
     * input_method, is_public, source_description, reference_model_key, steer_text,
     * prompt_defaults, negative_prompt, share_accounts, step.
     */
    public function influencer_save_stepAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        $m = new InfluencersModel();
        $f = array();
        if (isset($this->post['name'])) {
            $name = $this->text('name', 120);
            if ($name === '') { $this->jsonError('Give her a name.'); }
            if ($m->name_taken($cid, $name, (int) $infl['id'])) { $this->jsonError('You already have an influencer called ' . $name . '.'); }
            $f['name'] = $name;
        }
        if (isset($this->post['path']) && in_array($this->post['path'], array('photos', 'reference'), true)) {
            if (!in_array((string) $infl['status'], array('draft', 'awaiting_reference', 'failed'), true) || !empty($infl['pending_model_id'])) {
                $this->jsonError('The path can only change before training starts.');
            }
            $f['path'] = $this->post['path'];
        }
        if (isset($this->post['input_method']) && in_array($this->post['input_method'], array('text', 'face_photo'), true)) { $f['input_method'] = $this->post['input_method']; }
        if (isset($this->post['is_public'])) { $f['is_public'] = ((string) $this->post['is_public'] === '1') ? 1 : 0; }
        if (isset($this->post['source_description'])) { $f['source_description'] = $this->text('source_description', 2000); }
        if (isset($this->post['reference_model_key'])) {
            $k = (string) $this->post['reference_model_key'];
            $f['reference_model_key'] = InfluencerConfig::resolve_model('reference', $k) ? (string) InfluencerConfig::resolve_model('reference', $k)['key'] : InfluencerConfig::default_model_key('reference');
        }
        if (isset($this->post['steer_text']))      { $f['steer_text'] = $this->text('steer_text', 1000); }
        if (isset($this->post['prompt_defaults'])) { $f['prompt_defaults'] = $this->text('prompt_defaults', 2000); }
        if (isset($this->post['negative_prompt'])) { $f['negative_prompt'] = $this->text('negative_prompt', 2000); }
        if (isset($this->post['share_accounts'])) {
            $sa = $this->post['share_accounts'];
            if (is_string($sa)) { $sa = array_filter(array_map('trim', explode(',', html_entity_decode($sa, ENT_QUOTES, 'UTF-8'))), 'strlen'); }
            $f['share_accounts'] = array_values(array_map('strval', (array) $sa));
        }
        if (isset($this->post['step'])) {
            $path  = $f['path'] ?? (string) $infl['path'];
            $steps = InfluencerService::steps_for($path);
            $step  = (string) $this->post['step'];
            if (in_array($step, $steps, true) && !in_array($step, array('training', 'done'), true)) { $f['wizard_step'] = $step; }
        }
        if (!empty($f)) {
            $r = $m->update_fields($cid, $infl['id'], $f);
            if ($r === false) { $this->jsonError('You already have an influencer with that name.'); }
        }
        $this->jsonSuccess(['influencer' => InfluencerService::influencer_json($cid, $m->get_one($cid, $infl['id']))]);
    }

    public function influencer_deleteAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        $jobs = new InfluencerJobsModel();
        foreach ($jobs->list_for_influencer($cid, $infl['id'], '', 200) as $j) {
            if (in_array((string) $j['status'], array('queued', 'submitting', 'running'), true)) { InfluencerJobService::cancel_job($cid, (int) $j['id']); }
        }
        (new InfluencersModel())->soft_delete($cid, $infl['id']);
        $this->jsonSuccess(['message' => $infl['name'] . ' was removed. Her media stays in your library.']);
    }

    public function influencer_name_suggestAction(){
        $user = $this->ai_user();
        $this->jsonSuccess(['names' => InfluencerService::name_suggestions((int) $user['user_id'], 4)]);
    }

    /** Images attached to an influencer (by role), signed for display. */
    public function influencer_imagesAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        $role = (string) ($this->post['role'] ?? '');
        if ($role !== '' && !in_array($role, InfluencerImagesModel::ROLES, true)) { $role = ''; }
        $this->jsonSuccess(['images' => InfluencerService::images_json($cid, (int) $infl['id'], $role)]);
    }

    /* ---- uploads (Path A photos, Path B face photo) ---- */

    /**
     * One image per request (FormData `file`), attached to the influencer with role
     * upload | face. Uploads are never watermarked (they are training data).
     */
    public function influencer_uploadAction(){
        @ini_set('memory_limit', '512M');
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        $role = (($this->post['role'] ?? 'upload') === 'face') ? 'face' : 'upload';
        if (!S3Service::configured()) { $this->jsonError('Uploads are unavailable right now. Please try again shortly.'); }
        if (!empty($infl['pending_model_id'])) { $this->jsonError('Training is in progress. Wait for it to finish before changing her photos.'); }

        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->jsonError('No file was received. Please pick a file and try again.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($file['tmp_name']);
        $types = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
        if (!isset($types[$mime])) { $this->jsonError('That file type is not supported here. Use JPG, PNG, or WebP.'); }
        if ((int) $file['size'] > MediaLimits::MAX_IMAGE_BYTES) { $this->jsonError('That image is too large. Images can be up to 15 MB.'); }
        $gb = Plan::limit($user, 'storage_gb');
        if ($gb !== null && (int) $gb > 0 && ((int) (new MediaAssetsModel())->total_bytes($cid) + (int) $file['size']) > (int) $gb * 1073741824) {
            $this->jsonError("You've reached your plan's storage limit. Upgrade or remove files to free up space.", ['need_upgrade' => true]);
        }
        $im  = new InfluencerImagesModel();
        $max = (int) InfluencerConfig::get('training_max_photos', 50);
        if ($role === 'upload' && $im->count_role($cid, $infl['id'], 'upload') >= $max) {
            $this->jsonError('You can upload up to ' . $max . ' photos. Remove one to add another.');
        }
        $bytes = (string) @file_get_contents($file['tmp_name']);
        if ($bytes === '') { $this->jsonError('Could not read the file. Please try again.'); }
        try {
            $r = MediaIngestService::ingest_image($cid, $user, $bytes, $types[$mime], $mime, $infl['name'] . ' · ' . ($role === 'face' ? 'face' : 'photo'), false);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage());
        }
        $aid = (int) $r['asset_id'];
        (new MediaAssetsModel())->set_tags($cid, $aid, 'influencer:' . (int) $infl['id'] . ',' . $role);
        if ($role === 'face') {
            // A face photo replaces the previous one and becomes the reference for the training set.
            if (!empty($infl['face_asset_id'])) { $im->detach($cid, $infl['id'], $infl['face_asset_id']); }
            $im->attach($infl['id'], $cid, $aid, 'face', null, 0, 0);
            (new InfluencersModel())->update_fields($cid, $infl['id'], array('face_asset_id' => $aid, 'reference_asset_id' => $aid, 'input_method' => 'face_photo'));
        } else {
            $im->attach($infl['id'], $cid, $aid, 'upload', null, 0, $im->count_role($cid, $infl['id'], 'upload') + 1);
        }
        $images = InfluencerService::images_json($cid, (int) $infl['id'], $role);
        $mine = null;
        foreach ($images as $x) { if ((int) $x['id'] === $aid) { $mine = $x; break; } }
        $this->jsonSuccess(['image' => $mine, 'count' => count($images)]);
    }

    /** Detach an uploaded/training image from the influencer (the library asset is kept). */
    public function influencer_image_removeAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        if (!empty($infl['pending_model_id'])) { $this->jsonError('Training is in progress. Wait for it to finish before changing her photos.'); }
        $aid  = (int) ($this->post['asset_id'] ?? 0);
        $im   = new InfluencerImagesModel();
        $link = $im->get_link($cid, $infl['id'], $aid);
        if (!$link) { $this->jsonError('That image is not attached to her.'); }
        if ((string) $link['role'] === 'training') {
            $im->set_excluded($cid, $infl['id'], $aid, 1);   // generated slots are excluded, not detached, so the job history stays intact
        } else {
            $im->detach($cid, $infl['id'], $aid);
        }
        $f = array();
        if ((int) $infl['face_asset_id'] === $aid) { $f['face_asset_id'] = null; }
        if ((int) $infl['reference_asset_id'] === $aid) { $f['reference_asset_id'] = null; }
        if (!empty($f)) { (new InfluencersModel())->update_fields($cid, $infl['id'], $f); }
        $this->jsonSuccess(['count' => $im->count_role($cid, $infl['id'], (string) $link['role'])]);
    }

    /* ---- training ---- */

    /**
     * Start training (or a retrain). Photos path: every ready upload; reference path: the
     * complete training set. A retrain keeps the active model until the new one succeeds.
     */
    public function influencer_trainAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        if (!InfluencerConfig::enabled()) { $this->jsonError('Rendering is not configured yet (no provider key).'); }
        if (!empty($infl['pending_model_id'])) { $this->jsonError('A training run is already in progress.'); }
        $im = new InfluencerImagesModel();
        if ((string) $infl['path'] === 'reference') {
            $size = (int) InfluencerConfig::get('training_set_size', 10);
            if (empty($infl['reference_asset_id'])) { $this->jsonError('Approve a reference image first.'); }
            $ids = $im->ready_asset_ids($cid, $infl['id'], 'training');
            if (count($ids) < $size) { $this->jsonError('The training set is not complete yet (' . count($ids) . ' of ' . $size . ').'); }
            $ids = array_slice($ids, 0, max($size, count($ids)));
        } else {
            $ids = $im->ready_asset_ids($cid, $infl['id'], 'upload');
            $min = (int) InfluencerConfig::get('training_min_photos', 10);
            $max = (int) InfluencerConfig::get('training_max_photos', 50);
            if (count($ids) < $min) { $this->jsonError('Upload at least ' . $min . ' photos to train (you have ' . count($ids) . ').'); }
            if (count($ids) > $max) { $this->jsonError('Training accepts at most ' . $max . ' photos. Remove some to continue.'); }
        }
        $r = InfluencerTrainingService::start($cid, $infl, $ids, 'wizard');
        if (empty($r['ok'])) { $this->jsonError((string) $r['error']); }
        $this->jsonSuccess(['job_id' => (int) $r['job_id'], 'model_id' => (int) $r['model_id'],
            'influencer' => InfluencerService::influencer_json($cid, (new InfluencersModel())->get_one($cid, $infl['id']))]);
    }

    /** Trained models for an influencer (history), newest first. */
    public function influencer_modelsAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        $out = array();
        foreach ((new InfluencerModelsModel())->list_for_influencer($cid, $infl['id']) as $m) {
            $out[] = array('id' => (int) $m['id'], 'status' => (string) $m['status'], 'is_active' => (int) $m['is_active'], 'trigger_word' => (string) $m['trigger_word'],
                'provider' => (string) $m['provider'], 'steps' => (int) $m['steps'], 'image_count' => (int) $m['image_count'], 'error' => (string) $m['error'],
                'created_at' => (string) $m['created_at'], 'trained_at' => (string) $m['trained_at']);
        }
        $this->jsonSuccess(['models' => $out]);
    }

    /* ---- Path B: reference image, training set ---- */

    /** Generate one reference image from the description (text input). Returns the job id to poll. */
    public function influencer_reference_generateAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        if (!InfluencerConfig::enabled()) { $this->jsonError('Rendering is not configured yet (no provider key).'); }
        if ((string) $infl['path'] !== 'reference') { $this->jsonError('This influencer is trained from photos.'); }
        $f = array();
        if (isset($this->post['source_description'])) { $f['source_description'] = $this->text('source_description', 2000); }
        if (isset($this->post['reference_model_key'])) {
            $mk = InfluencerConfig::resolve_model('reference', (string) $this->post['reference_model_key']);
            $f['reference_model_key'] = $mk ? (string) $mk['key'] : '';
        }
        $f['input_method'] = 'text';
        (new InfluencersModel())->update_fields($cid, $infl['id'], $f);
        $infl = $this->owned($cid, $infl['id']);
        $desc = trim((string) $infl['source_description']);
        if ($desc === '') { $this->jsonError('Describe her face first.'); }
        $model = InfluencerConfig::resolve_model('reference', (string) $infl['reference_model_key']);
        if (!$model) { $this->jsonError('No reference model is configured.'); }
        $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'reference', array(
            'origin' => 'wizard', 'model_key' => (string) $model['key'], 'prompt' => $desc,
            'params' => array('image_size' => 'square', 'num_images' => 1, 'level' => 'safe'),
        ));
        if ($job_id <= 0) { $this->jsonError('Could not start the reference image.'); }
        $this->jsonSuccess(['job_id' => $job_id]);
    }

    /** Approve a reference (a generated candidate or the uploaded face photo). */
    public function influencer_reference_pickAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        $aid  = (int) ($this->post['asset_id'] ?? 0);
        $link = (new InfluencerImagesModel())->get_link($cid, $infl['id'], $aid);
        if (!$link || !in_array((string) $link['role'], array('reference', 'face'), true)) { $this->jsonError('Pick one of her reference images.'); }
        $a = (new MediaAssetsModel())->get_one($cid, $aid);
        if (!$a || (string) $a['status'] !== 'ready') { $this->jsonError('That image is not ready yet.'); }
        $m = new InfluencersModel();
        $m->update_fields($cid, $infl['id'], array('reference_asset_id' => $aid, 'wizard_step' => 'set'));
        $m->transition($infl['id'], array('status' => 'draft'), "status = 'awaiting_reference'");
        $this->jsonSuccess(['influencer' => InfluencerService::influencer_json($cid, $m->get_one($cid, $infl['id']))]);
    }

    /** Create the 10-image training set from the approved reference (one job per image, seeds recorded). */
    public function influencer_training_set_startAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        if (!InfluencerConfig::enabled()) { $this->jsonError('Rendering is not configured yet (no provider key).'); }
        if (empty($infl['reference_asset_id'])) { $this->jsonError('Approve a reference image first.'); }
        if (!empty($infl['pending_model_id'])) { $this->jsonError('Training is in progress.'); }
        $m = new InfluencersModel();
        if (isset($this->post['steer_text'])) { $m->update_fields($cid, $infl['id'], array('steer_text' => $this->text('steer_text', 1000))); $infl = $this->owned($cid, $infl['id']); }
        $model = InfluencerConfig::resolve_model('training_set', '');
        if (!$model) { $this->jsonError('No training-set model is configured.'); }
        $size  = (int) InfluencerConfig::get('training_set_size', 10);
        $steer = trim((string) $infl['steer_text']);
        // Any earlier set is superseded: its images are excluded so only the current set trains.
        $im = new InfluencerImagesModel();
        if (!empty($infl['training_set_group'])) {
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
                'params' => array('aspect_ratio' => '1:1', 'num_images' => 1, 'level' => 'safe', 'image_size' => 'square'),
            ));
        }
        $this->jsonSuccess(['group_key' => $group, 'job_ids' => $ids]);
    }

    /** Progress of the current training set: counts + every slot with its image or error. */
    public function influencer_training_set_statusAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        $this->jsonSuccess($this->training_set_payload($cid, $infl));
    }

    private function training_set_payload($cid, array $infl){
        $group = (string) ($infl['training_set_group'] ?? '');
        $size  = (int) InfluencerConfig::get('training_set_size', 10);
        if ($group === '') { return array('group_key' => '', 'size' => $size, 'done' => 0, 'failed' => 0, 'active' => 0, 'slots' => array(), 'complete' => false); }
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
        return array('group_key' => $group, 'size' => $size, 'done' => $done, 'failed' => $failed, 'active' => $active, 'slots' => $slots, 'complete' => ($done >= $size));
    }

    /** Retry a failed slot (same prompt + seed) or regenerate a finished one (new seed). */
    public function influencer_training_set_retryAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        if (!empty($infl['pending_model_id'])) { $this->jsonError('Training is in progress.'); }
        $jobs = new InfluencerJobsModel();
        $job  = $jobs->get_one($cid, (int) ($this->post['job_id'] ?? 0));
        if (!$job || (int) $job['influencer_id'] !== (int) $infl['id'] || (string) $job['group_key'] !== (string) $infl['training_set_group']) { $this->jsonError('That slot is not part of her current set.'); }
        if (in_array((string) $job['status'], array('failed', 'cancelled'), true)) {
            $r = InfluencerJobService::retry($cid, (int) $job['id']);
            if (empty($r['ok'])) { $this->jsonError((string) $r['error']); }
            $this->jsonSuccess(['job_id' => (int) $job['id']]);
        }
        if ((string) $job['status'] !== 'done') { $this->jsonError('That image is still generating.'); }
        $new_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'training_set', array(
            'origin' => 'wizard', 'model_key' => (string) $job['model_key'], 'prompt' => (string) $job['prompt'], 'input_asset_id' => (int) $job['input_asset_id'],
            'group_key' => (string) $job['group_key'], 'group_index' => (int) $job['group_index'], 'params' => InfluencerJobsModel::params($job),
        ));
        if ($new_id <= 0) { $this->jsonError('Could not regenerate that image.'); }
        $jobs->set_superseded((int) $job['id'], $new_id);
        if (!empty($job['result_asset_id'])) { (new InfluencerImagesModel())->set_excluded($cid, $infl['id'], (int) $job['result_asset_id'], 1); }
        $this->jsonSuccess(['job_id' => $new_id]);
    }

    /** One job with its landed assets (polling from the wizard / generate pages). */
    public function influencer_job_getAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $job  = (new InfluencerJobsModel())->get_one($cid, (int) ($this->post['job_id'] ?? 0));
        if (!$job) { $this->jsonError('Job not found.'); }
        $this->jsonSuccess(['job' => InfluencerJobService::job_json($cid, $job)]);
    }

    /* ---- generation (Generate Images / Videos) ---- */

    /** Start an image run with her active weights. Returns the job id to poll. */
    public function influencer_generate_imageAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        if (!InfluencerConfig::enabled()) { $this->jsonError('Rendering is not configured yet (no provider key).'); }
        if ((string) $infl['status'] !== 'ready' || empty($infl['active_model_id'])) { $this->jsonError('She has no trained model yet.'); }
        $model = (new InfluencerModelsModel())->get_by_id($infl['active_model_id']);
        if (!$model || (string) $model['status'] !== 'ready') { $this->jsonError('Her active model is not ready.'); }
        $user_prompt = $this->text('prompt', 4000);
        if ($user_prompt === '') { $this->jsonError('Write a prompt first.'); }
        $defaults = trim((string) ($infl['prompt_defaults'] ?? ''));
        $prompt   = trim(($defaults !== '' ? $defaults . ' ' : '') . $user_prompt);   // defaults + what she typed, nothing else
        $mk   = InfluencerConfig::resolve_model('image', (string) ($this->post['model_key'] ?? ''));
        if (!$mk) { $this->jsonError('No image model is configured.'); }
        $size = in_array($this->post['image_size'] ?? '', array('square', 'portrait', 'landscape'), true) ? $this->post['image_size'] : 'square';
        $n    = max(1, min(4, (int) ($this->post['num_images'] ?? 1)));
        $seed = (int) ($this->post['seed'] ?? 0);
        $level = (($this->post['level'] ?? 'safe') === 'spicy') ? 'spicy' : 'safe';
        $overrides = array();
        if (isset($this->post['guidance']) && $this->post['guidance'] !== '') { $overrides['guidance_scale'] = max(1, min(20, (float) $this->post['guidance'])); }
        if (isset($this->post['steps']) && $this->post['steps'] !== '')       { $overrides['num_inference_steps'] = max(4, min(50, (int) $this->post['steps'])); }
        $params = array('image_size' => $size, 'num_images' => $n, 'level' => $level, 'user_prompt' => $user_prompt, 'overrides' => $overrides,
            'lora_scale' => (isset($this->post['lora_scale']) && $this->post['lora_scale'] !== '') ? max(0.1, min(2.0, (float) $this->post['lora_scale'])) : (float) InfluencerConfig::get('training_lora_scale', 1.0));
        $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'image', array(
            'origin' => 'studio', 'model_key' => (string) $mk['key'], 'model_id' => (int) $model['id'], 'prompt' => $prompt,
            'negative_prompt' => (string) ($infl['negative_prompt'] ?? ''), 'seed' => $seed > 0 ? $seed : null, 'params' => $params,
        ));
        if ($job_id <= 0) { $this->jsonError('Could not start the run.'); }
        $this->jsonSuccess(['job_id' => $job_id, 'job' => InfluencerJobService::job_json($cid, (new InfluencerJobsModel())->get_by_id($job_id))]);
    }

    /** Recent jobs of one type for an influencer (result strips). */
    public function influencer_jobs_listAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        $types = array_values(array_intersect(array_map('trim', explode(',', (string) ($this->post['type'] ?? ''))), InfluencerJobsModel::TYPES));
        $out   = array();
        foreach ((new InfluencerJobsModel())->list_for_influencer($cid, $infl['id'], $types, (int) ($this->post['limit'] ?? 24)) as $j) {
            $out[] = InfluencerJobService::job_json($cid, $j);
        }
        $this->jsonSuccess(['jobs' => $out]);
    }

    /** Retry a failed generation (same prompt and seed). */
    public function influencer_job_retryAction(){
        $user = $this->ai_user();
        $r = InfluencerJobService::retry((int) $user['user_id'], (int) ($this->post['job_id'] ?? 0));
        if (empty($r['ok'])) { $this->jsonError((string) $r['error']); }
        $this->jsonSuccess(['job_id' => (int) $r['job_id']]);
    }

    /** Let Claude write a scene prompt for her (returned to the field; nothing is sent to the renderer). */
    public function influencer_prompt_autoAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        $trigger = '';
        if (!empty($infl['active_model_id'])) { $mdl = (new InfluencerModelsModel())->get_by_id($infl['active_model_id']); $trigger = $mdl ? (string) $mdl['trigger_word'] : ''; }
        if (!ClaudeService::configured()) { $this->jsonError('Prompt writing is not available right now.'); }
        $hint = $this->text('hint', 500);
        $system = 'You write one image-generation prompt for a photorealistic social-media photo of a specific woman. '
            . 'Output ONLY the prompt text, one line, 25 to 60 words, no quotes, no preamble. '
            . ($trigger !== '' ? 'The prompt MUST start with the exact token "' . $trigger . '" (this identifies her). ' : '')
            . 'Describe setting, outfit, pose, lighting and camera feel. Keep it within what a mainstream social platform allows: no nudity, no explicit or sexual language.';
        $ask = 'Write a prompt for a new post by ' . $infl['name'] . '.' . ($hint !== '' ? ' Theme: ' . $hint : ' Pick a fresh everyday scene.');
        $r = ClaudeService::chat($system, array(array('role' => 'user', 'content' => $ask)), 200, 30, 'low');
        if (empty($r['ok']) || trim((string) $r['text']) === '') { $this->jsonError('Could not write a prompt right now.'); }
        $text = trim(preg_replace('/\s+/', ' ', (string) $r['text']));
        if ($trigger !== '' && stripos($text, $trigger) === false) { $text = $trigger . ' ' . $text; }
        $this->jsonSuccess(['prompt' => $text]);
    }

    /** Short-lived download URL for one of her assets (original rendition). */
    public function influencer_asset_urlAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $a = (new MediaAssetsModel())->get_one($cid, (int) ($this->post['asset_id'] ?? 0));
        if (!$a || (string) $a['status'] !== 'ready') { $this->jsonError('That file is not ready.'); }
        $variant = (($this->post['variant'] ?? 'original') === 'display') ? 'display' : 'original';
        $url = MediaService::signed_variant($a, $variant, 900);
        if ($url === '') { $this->jsonError('Could not sign that file.'); }
        $this->jsonSuccess(['url' => $url, 'type' => (string) $a['type']]);
    }

    /** Image-to-video from one of her stills. Returns the job id to poll. */
    public function influencer_generate_videoAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        if (!InfluencerConfig::enabled()) { $this->jsonError('Rendering is not configured yet (no provider key).'); }
        $aid = (int) ($this->post['asset_id'] ?? 0);
        $a   = (new MediaAssetsModel())->get_one($cid, $aid);
        if (!$a || (string) $a['type'] !== 'image' || (string) $a['status'] !== 'ready') { $this->jsonError('Pick a ready image of her first.'); }
        if (!(new InfluencerImagesModel())->get_link($cid, $infl['id'], $aid)) { $this->jsonError('That image is not one of hers.'); }
        $mk = InfluencerConfig::resolve_model('video', (string) ($this->post['model_key'] ?? ''));
        if (!$mk) { $this->jsonError('No video model is configured.'); }
        $user_prompt = $this->text('prompt', 2000);
        $defaults = trim((string) ($infl['prompt_defaults'] ?? ''));
        $prompt   = trim(($defaults !== '' ? $defaults . ' ' : '') . $user_prompt);
        $durs = array_values((array) ($mk['durations'] ?? array()));
        $dur  = (string) ($this->post['duration'] ?? ($durs[0] ?? '5'));
        if (!empty($durs) && !in_array($dur, $durs, true)) { $dur = (string) $durs[0]; }
        $level = (($this->post['level'] ?? 'safe') === 'spicy') ? 'spicy' : 'safe';
        $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'video', array(
            'origin' => 'studio', 'model_key' => (string) $mk['key'], 'model_id' => (int) ($infl['active_model_id'] ?? 0), 'prompt' => $prompt,
            'negative_prompt' => (string) ($infl['negative_prompt'] ?? ''), 'input_asset_id' => $aid,
            'params' => array('duration' => $dur, 'level' => $level, 'user_prompt' => $user_prompt),
        ));
        if ($job_id <= 0) { $this->jsonError('Could not start the video.'); }
        $this->jsonSuccess(['job_id' => $job_id, 'job' => InfluencerJobService::job_json($cid, (new InfluencerJobsModel())->get_by_id($job_id))]);
    }

    /** Enhance (upscale) one of her images into a new library asset. */
    public function influencer_enhanceAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->owned($cid, (int) ($this->post['id'] ?? 0));
        if (!InfluencerConfig::enabled()) { $this->jsonError('Rendering is not configured yet (no provider key).'); }
        $aid = (int) ($this->post['asset_id'] ?? 0);
        $a   = (new MediaAssetsModel())->get_one($cid, $aid);
        if (!$a || (string) $a['type'] !== 'image' || (string) $a['status'] !== 'ready') { $this->jsonError('Pick a ready image first.'); }
        if (!(new InfluencerImagesModel())->get_link($cid, $infl['id'], $aid)) { $this->jsonError('That image is not one of hers.'); }
        $mk = InfluencerConfig::resolve_model('enhance', (string) ($this->post['model_key'] ?? ''));
        if (!$mk) { $this->jsonError('No enhance model is configured.'); }
        // The prompt that produced the source (if any) rides along; nothing else is added.
        $src_prompt = '';
        $link = (new InfluencerImagesModel())->get_link($cid, $infl['id'], $aid);
        if (!empty($link['job_id'])) { $sj = (new InfluencerJobsModel())->get_one($cid, (int) $link['job_id']); $src_prompt = $sj ? (string) $sj['prompt'] : ''; }
        $job_id = InfluencerJobService::create_job($cid, (int) $infl['id'], 'enhance', array(
            'origin' => 'studio', 'model_key' => (string) $mk['key'], 'prompt' => $src_prompt, 'negative_prompt' => (string) ($infl['negative_prompt'] ?? ''),
            'input_asset_id' => $aid, 'params' => array('level' => (($this->post['level'] ?? 'safe') === 'spicy') ? 'spicy' : 'safe', 'num_images' => 1),
        ));
        if ($job_id <= 0) { $this->jsonError('Could not start the enhancement.'); }
        $this->jsonSuccess(['job_id' => $job_id, 'job' => InfluencerJobService::job_json($cid, (new InfluencerJobsModel())->get_by_id($job_id))]);
    }
}
