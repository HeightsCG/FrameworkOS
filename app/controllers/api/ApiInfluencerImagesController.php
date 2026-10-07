<?php
/**
 * AI image tools built on an influencer's identity: the angle reference set, Replicate Photo,
 * Edit By Instruction, Carousel sets and the scene template library. Thin wrappers over
 * InfluencerImageActions / SceneTemplates (shared with the Claude connector in McpTools).
 * Routed from /api/<action> by ApiRoutes. Gated like the other generators: content role, a paid
 * plan, AI credits. POST text is html-decoded here before it reaches the shared services.
 */
class ApiInfluencerImagesController extends BaseApiController {

    private function ai_user($need_plan = true){
        return $this->require_creator('content', $need_plan);
    }

    private function text($key, $max = 5000){
        return mb_substr(trim(html_entity_decode((string) ($this->post[$key] ?? ''), ENT_QUOTES, 'UTF-8')), 0, $max);
    }

    /** A JSON field posted as text (clean_post_data html-encodes it). */
    private function json_field($key){
        $d = json_decode(html_entity_decode((string) ($this->post[$key] ?? ''), ENT_QUOTES, 'UTF-8'), true);
        return is_array($d) ? $d : null;
    }

    /** Owned influencer the plan lets you use. */
    private function usable($user, $id){
        $infl = (new InfluencersModel())->get_one((int) $user['user_id'], (int) $id);
        if (!$infl) { $this->jsonError('Influencer not found.'); }
        if (Plan::is_locked($user, 'influencers', (int) $infl['id'])) { $this->jsonError(Plan::locked_message($user, 'influencers'), ['need_upgrade' => true, 'locked' => true]); }
        return $infl;
    }

    private function answer(array $r){
        if (empty($r['ok'])) {
            $extra = array_intersect_key($r, array_flip(['need_upgrade', 'need_plan', 'need_credits', 'price', 'balance', 'locked', 'job_ids', 'set_id']));
            $this->jsonError((string) $r['error'], $extra);
        }
        unset($r['ok'], $r['error']);
        $r['ai_credits'] = (int) (new AiCreditsModel())->get_balance(Permissions::creator_id());   // the page shows what is left after a run
        $this->jsonSuccess($r);
    }

    /* ---- angle reference set ---- */

    public function influencer_angle_statusAction(){
        $user = $this->ai_user(false);
        $this->answer(InfluencerImageActions::angle_set_status((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0))));
    }

    public function influencer_angle_generateAction(){
        $user  = $this->ai_user();
        $slots = array_values(array_filter(array_map('trim', explode(',', (string) ($this->post['slots'] ?? ''))), 'strlen'));
        $this->answer(InfluencerImageActions::angle_set_generate((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), $slots, (string) ($this->post['model_key'] ?? ''), 'studio'));
    }

    /* ---- replicate a photo ---- */

    public function influencer_replicate_prepareAction(){
        $user = $this->ai_user();
        set_time_limit(120);
        $this->answer(InfluencerImageActions::replicate_prepare((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)),
            (int) ($this->post['source_asset_id'] ?? 0), (string) ($this->post['mode'] ?? 'style'), $this->text('instruction', 500)));
    }

    public function influencer_replicateAction(){
        $user = $this->ai_user();
        set_time_limit(120);
        $in = array('source_asset_id' => (int) ($this->post['source_asset_id'] ?? 0), 'mode' => (string) ($this->post['mode'] ?? 'style'),
            'prompt' => $this->text('prompt', 4000), 'instruction' => $this->text('instruction', 500),
            'mask' => (string) ($this->post['mask'] ?? '1') !== '0', 'face' => $this->json_field('face'), 'strokes' => (array) $this->json_field('strokes'));
        foreach (['aspect', 'num_images', 'model_key'] as $k) { if (isset($this->post[$k])) { $in[$k] = (string) $this->post[$k]; } }
        $this->answer(InfluencerImageActions::replicate((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), $in, 'studio'));
    }

    /* ---- edit by instruction ---- */

    public function media_editAction(){
        $user = $this->ai_user();
        $this->answer(InfluencerImageActions::edit_image((int) $user['user_id'], (int) ($this->post['asset_id'] ?? 0), $this->text('instruction', 1500), (string) ($this->post['model_key'] ?? ''), 'studio'));
    }

    public function media_versionsAction(){
        $user = $this->ai_user(false);
        $this->answer(InfluencerImageActions::versions((int) $user['user_id'], (int) ($this->post['asset_id'] ?? 0)));
    }

    /** What the Edit window needs before anything is typed: the models, their prices, the balance. */
    public function media_edit_optionsAction(){
        $user = $this->ai_user(false);
        Plan::grant_monthly($user);
        $asset = !empty($this->post['asset_id']) ? (new MediaAssetsModel())->get_one((int) $user['user_id'], (int) $this->post['asset_id']) : null;
        $this->jsonSuccess(['models' => InfluencerImageActions::edit_models($asset), 'ai_credits' => (int) (new AiCreditsModel())->get_balance((int) $user['user_id']),
            'can_ai' => Plan::can_use_creator_features($user)]);
    }

    /* ---- carousel sets ---- */

    /** Read a chosen seed image into a scene description for the Carousel form. */
    public function influencer_carousel_readAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $infl = $this->usable($user, (int) ($this->post['id'] ?? 0));
        $this->answer(InfluencerImageActions::carousel_read($cid, $infl, (int) ($this->post['seed_asset_id'] ?? 0)));
    }

    public function influencer_carousel_startAction(){
        $user = $this->ai_user();
        set_time_limit(180);
        $in = array('seed_asset_id' => (int) ($this->post['seed_asset_id'] ?? 0), 'seed_text' => $this->text('seed_text', 2000), 'count' => (int) ($this->post['count'] ?? 0));
        foreach (['focus', 'aspect', 'model_key'] as $k) { if (isset($this->post[$k])) { $in[$k] = (string) $this->post[$k]; } }
        $this->answer(InfluencerImageActions::carousel_start((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), $in, 'studio'));
    }

    public function influencer_carousel_statusAction(){
        $user = $this->ai_user(false);
        $this->answer(InfluencerImageActions::carousel_status((int) $user['user_id'], (int) ($this->post['set_id'] ?? 0)));
    }

    public function influencer_carousel_listAction(){
        $user = $this->ai_user(false);
        $cid  = (int) $user['user_id'];
        $infl = $this->usable($user, (int) ($this->post['id'] ?? 0));
        $out  = array();
        foreach ((new CarouselSetsModel())->list_for_influencer($cid, (int) $infl['id'], 12) as $s) {
            $out[] = array('id' => (int) $s['id'], 'focus' => (string) $s['focus'], 'count' => (int) $s['slot_count'], 'created_at' => (string) $s['created_at']);
        }
        $this->jsonSuccess(['sets' => $out]);
    }

    /** The influencer plan lock is checked inside carousel_regenerate (it loads her from the slot's job), as on the connector path. */
    public function influencer_carousel_regenerateAction(){
        $user = $this->ai_user();
        $this->answer(InfluencerImageActions::carousel_regenerate((int) $user['user_id'], (int) ($this->post['job_id'] ?? 0)));
    }

    public function influencer_carousel_to_postAction(){
        $user = $this->ai_user();
        $ids  = array_values(array_filter(array_map('intval', explode(',', (string) ($this->post['asset_ids'] ?? '')))));
        $this->answer(InfluencerImageActions::carousel_to_post((int) $user['user_id'], $ids));
    }

    /* ---- scene template library ---- */

    public function scenes_listAction(){
        $user  = $this->ai_user(false);
        $admin = Permissions::is_admin();   // an admin edits / turns off / deletes the platform scenes right here (admin_scene_* actions)
        $this->jsonSuccess(SceneTemplates::for_user($user, $admin) + array('is_admin' => $admin));
    }

    public function scene_runAction(){
        $user = $this->ai_user();
        $cid  = (int) $user['user_id'];
        $this->answer(SceneTemplates::run($cid, $user, $this->usable($user, (int) ($this->post['id'] ?? 0)), (int) ($this->post['template_id'] ?? 0),
            (string) ($this->post['aspect'] ?? ''), (string) ($this->post['model_key'] ?? ''), 'studio'));
    }

    public function scene_voteAction(){
        $user = $this->ai_user(false);
        $this->answer(SceneTemplates::vote((int) $user['user_id'], (int) ($this->post['asset_id'] ?? 0), (int) ($this->post['vote'] ?? 0)));
    }

    /* ---- the creator's own scenes (platform scenes are read-only here; ownership is checked on every id) ---- */

    /** Create (id 0) or update one of the creator's own scenes. Field errors come back as errors[] like the admin dialog. */
    public function scene_saveAction(){
        $user = $this->ai_user();
        $f = array('title' => $this->text('title', 120), 'category' => $this->text('category', 60), 'base_prompt' => $this->text('base_prompt', 4000),
            'is_adult' => (string) ($this->post['is_adult'] ?? '0') === '1', 'default_aspect' => (string) ($this->post['default_aspect'] ?? ''),
            'sort_order' => (int) ($this->post['sort_order'] ?? 0));
        $r = SceneTemplates::save((int) $user['user_id'], (int) ($this->post['id'] ?? 0), $f);
        if (empty($r['ok'])) { $this->jsonError((string) $r['error'], ['errors' => (array) ($r['errors'] ?? array())]); }
        unset($r['ok'], $r['error']);
        $this->jsonSuccess($r);
    }

    public function scene_set_activeAction(){
        $user = $this->ai_user();
        $this->answer(SceneTemplates::set_active((int) $user['user_id'], (int) ($this->post['id'] ?? 0), (string) ($this->post['active'] ?? '1') === '1'));
    }

    public function scene_deleteAction(){
        $user = $this->ai_user();
        $this->answer(SceneTemplates::delete((int) $user['user_id'], (int) ($this->post['id'] ?? 0)));
    }

    /** A platform scene (or one of the creator's own) copied into the creator's own scenes, where it can be edited. */
    public function scene_duplicateAction(){
        $user = $this->ai_user();
        $this->answer(SceneTemplates::duplicate((int) $user['user_id'], (int) ($this->post['id'] ?? 0)));
    }

    /** Multipart thumbnail upload for one of the creator's own scenes (same sniff + re-encode as the admin path). */
    public function scene_thumbAction(){
        $user = $this->ai_user();
        $this->answer(SceneTemplates::thumb((int) $user['user_id'], (int) ($this->post['id'] ?? 0), $_FILES['file'] ?? null));
    }
}
