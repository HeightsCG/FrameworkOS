<?php
/**
 * AI influencer API: thin wrappers over InfluencerActions (shared with the Claude connector
 * in McpTools). Routed from /api/<action> by ApiRoutes; extends BaseApiController
 * (require_creator() returns the OWNER row). POST text is html-decoded here before it
 * reaches the shared service.
 */
class ApiInfluencersController extends BaseApiController {

    /** Creator gate + ai_tools plan flag. */
    /** Owner row for influencer actions: content role + an active plan (require_creator checks both). */
    private function ai_user(){
        return $this->require_creator('content');
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

    /** Owned influencer the plan lets you use: one over the plan's limit is kept but locked. */
    private function usable($user, $id){
        $infl = $this->owned((int) $user['user_id'], $id);
        if (Plan::is_locked($user, 'influencers', (int) $infl['id'])) { $this->jsonError(Plan::locked_message($user, 'influencers'), ['need_upgrade' => true, 'locked' => true]); }
        return $infl;
    }

    /** Answer with a shared-service result: error -> jsonError (with need_* flags), ok -> jsonSuccess(payload). */
    private function answer(array $r){
        if (empty($r['ok'])) {
            $extra = array_intersect_key($r, array_flip(['need_upgrade', 'need_plan', 'need_credits', 'price', 'balance', 'addon', 'addon_price', 'locked']));
            $this->jsonError((string) $r['error'], $extra);
        }
        unset($r['ok'], $r['error']);
        $this->jsonSuccess($r);
    }

    /* ---- gallery / wizard ---- */

    public function influencer_listAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $out  = array();
        foreach ((new InfluencersModel())->list_for_creator($cid) as $row) { $out[] = InfluencerService::influencer_json($cid, $row); }
        $this->jsonSuccess(['influencers' => $out]);
    }

    public function influencer_getAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->jsonSuccess(['influencer' => InfluencerService::influencer_json($cid, $this->owned($cid, (int) ($this->post['id'] ?? 0)))]);
    }

    public function influencer_createAction(){
        $user = $this->ai_user();
        $this->answer(InfluencerActions::create((int) $user['user_id'], $this->text('name', 120), (string) ($this->post['path'] ?? 'photos'), (string) ($this->post['gender'] ?? '')));
    }

    /** Persist wizard inputs + the step the user is on (any subset of the settable fields). */
    public function influencer_save_stepAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->usable($user, (int) ($this->post['id'] ?? 0));
        $in = array();
        foreach (['name', 'source_description', 'steer_text', 'prompt_defaults', 'negative_prompt'] as $k) { if (isset($this->post[$k])) { $in[$k] = $this->text($k); } }
        foreach (['gender', 'path', 'input_method', 'is_public', 'reference_model_key', 'step'] as $k) { if (isset($this->post[$k])) { $in[$k] = (string) $this->post[$k]; } }
        if (isset($this->post['share_accounts'])) {
            $sa = $this->post['share_accounts'];
            $in['share_accounts'] = is_string($sa) ? html_entity_decode($sa, ENT_QUOTES, 'UTF-8') : (array) $sa;
        }
        $this->answer(InfluencerActions::update($cid, $infl, $in));
    }

    public function influencer_deleteAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->answer(InfluencerActions::delete($cid, $this->owned($cid, (int) ($this->post['id'] ?? 0))));
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

    /** One image per request (FormData `file`), attached as upload | face. */
    public function influencer_uploadAction(){
        @ini_set('memory_limit', '512M');
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->usable($user, (int) ($this->post['id'] ?? 0));
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->jsonError('No file was received. Please pick a file and try again.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($file['tmp_name']);
        $types = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
        if (!isset($types[$mime])) { $this->jsonError('That file type is not supported here. Use JPG, PNG, or WebP.'); }
        $bytes = (string) @file_get_contents($file['tmp_name']);
        if ($bytes === '') { $this->jsonError('Could not read the file. Please try again.'); }
        $this->answer(InfluencerActions::attach_photo($cid, $user, $infl, $bytes, $types[$mime], $mime, (string) ($this->post['role'] ?? 'upload')));
    }

    /** Copy one of her gallery images into her training photos (retrain from the gallery). */
    public function influencer_photo_from_galleryAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->answer(InfluencerActions::attach_from_gallery($cid, $user, $this->usable($user, (int) ($this->post['id'] ?? 0)), (int) ($this->post['asset_id'] ?? 0)));
    }

    public function influencer_image_removeAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->answer(InfluencerActions::remove_image($cid, $this->owned($cid, (int) ($this->post['id'] ?? 0)), (int) ($this->post['asset_id'] ?? 0)));
    }

    /* ---- training ---- */

    public function influencer_trainAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->answer(InfluencerActions::train($cid, $this->usable($user, (int) ($this->post['id'] ?? 0))));
    }

    public function influencer_modelsAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->answer(InfluencerActions::models($cid, $this->owned($cid, (int) ($this->post['id'] ?? 0))));
    }

    /* ---- Path B: reference image, training set ---- */

    public function influencer_reference_generateAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->usable($user, (int) ($this->post['id'] ?? 0));
        $in = array();
        if (isset($this->post['source_description']))  { $in['source_description'] = $this->text('source_description', 2000); }
        if (isset($this->post['reference_model_key'])) { $in['reference_model_key'] = (string) $this->post['reference_model_key']; }
        $this->answer(InfluencerActions::reference_generate($cid, $infl, $in));
    }

    public function influencer_reference_pickAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->answer(InfluencerActions::reference_pick($cid, $this->usable($user, (int) ($this->post['id'] ?? 0)), (int) ($this->post['asset_id'] ?? 0)));
    }

    public function influencer_training_set_startAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->usable($user, (int) ($this->post['id'] ?? 0));
        $this->answer(InfluencerActions::training_set_start($cid, $infl, isset($this->post['steer_text']) ? $this->text('steer_text', 1000) : null));
    }

    public function influencer_training_set_statusAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->answer(InfluencerActions::training_set_status($cid, $this->owned($cid, (int) ($this->post['id'] ?? 0))));
    }

    public function influencer_training_set_retryAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->answer(InfluencerActions::training_set_retry($cid, $this->usable($user, (int) ($this->post['id'] ?? 0)), (int) ($this->post['job_id'] ?? 0)));
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

    public function influencer_generate_imageAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->usable($user, (int) ($this->post['id'] ?? 0));
        $in = array('prompt' => $this->text('prompt', 4000));
        foreach (['model_key', 'image_size', 'num_images', 'seed', 'guidance', 'steps', 'lora_scale'] as $k) { if (isset($this->post[$k])) { $in[$k] = (string) $this->post[$k]; } }
        $this->answer(InfluencerActions::generate_image($cid, $infl, $in, 'studio'));
    }

    public function influencer_jobs_listAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->answer(InfluencerActions::jobs($cid, $this->owned($cid, (int) ($this->post['id'] ?? 0)), (string) ($this->post['type'] ?? ''), (int) ($this->post['limit'] ?? 24)));
    }

    /** Retry a failed generation (same prompt and seed). */
    public function influencer_job_retryAction(){
        $user = $this->ai_user();
        $r = InfluencerJobService::retry((int) $user['user_id'], (int) ($this->post['job_id'] ?? 0));
        if (empty($r['ok'])) { $this->jsonError((string) $r['error'], array_intersect_key($r, array_flip(['need_credits']))); }
        $this->jsonSuccess(['job_id' => (int) $r['job_id']]);
    }

    public function influencer_prompt_autoAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $kind = ((string) ($this->post['kind'] ?? 'image') === 'video') ? 'video' : 'image';
        $this->answer(InfluencerActions::prompt_auto($cid, $this->usable($user, (int) ($this->post['id'] ?? 0)), $this->text('hint', 500), $kind));
    }

    /** Delete one of her generated/uploaded files from the library. */
    public function influencer_asset_deleteAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->answer(InfluencerActions::delete_asset($cid, $this->owned($cid, (int) ($this->post['id'] ?? 0)), (int) ($this->post['asset_id'] ?? 0)));
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

    public function influencer_generate_videoAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->usable($user, (int) ($this->post['id'] ?? 0));
        $in = array('asset_id' => (int) ($this->post['asset_id'] ?? 0), 'prompt' => $this->text('prompt', 2000));
        foreach (['model_key', 'duration'] as $k) { if (isset($this->post[$k])) { $in[$k] = (string) $this->post[$k]; } }
        $this->answer(InfluencerActions::generate_video($cid, $infl, $in, 'studio'));
    }

    public function influencer_enhanceAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->usable($user, (int) ($this->post['id'] ?? 0));
        $this->answer(InfluencerActions::enhance($cid, $infl, (int) ($this->post['asset_id'] ?? 0), (string) ($this->post['model_key'] ?? ''), 'studio'));
    }
}
