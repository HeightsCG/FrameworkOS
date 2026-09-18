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
}
