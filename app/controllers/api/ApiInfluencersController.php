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
}
