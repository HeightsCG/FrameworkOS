<?php
/**
 * AI video tools built on an influencer's identity: frame extraction, Motion Control, Replace
 * Character and long dialogue Scenes. Thin wrappers over InfluencerVideoActions (shared with the
 * Claude connector in McpTools). Routed from /api/<action> by ApiRoutes. Gated like the other
 * generators: content role, a paid plan, AI credits. POST text is html-decoded here.
 */
class ApiInfluencerVideosController extends BaseApiController {

    private function ai_user($need_plan = true){
        return $this->require_creator('content', $need_plan);
    }

    private function text($key, $max = 5000){
        return mb_substr(trim(html_entity_decode((string) ($this->post[$key] ?? ''), ENT_QUOTES, 'UTF-8')), 0, $max);
    }

    private function json_field($key){
        $d = json_decode(html_entity_decode((string) ($this->post[$key] ?? ''), ENT_QUOTES, 'UTF-8'), true);
        return is_array($d) ? $d : null;
    }

    private function on($key){ return (string) ($this->post[$key] ?? '0') === '1'; }

    private function usable($user, $id){
        $infl = (new InfluencersModel())->get_one((int) $user['user_id'], (int) $id);
        if (!$infl) { $this->jsonError('Influencer not found.'); }
        if (Plan::is_locked($user, 'influencers', (int) $infl['id'])) { $this->jsonError(Plan::locked_message($user, 'influencers'), ['need_upgrade' => true, 'locked' => true]); }
        return $infl;
    }

    private function answer(array $r){
        if (empty($r['ok'])) {
            $extra = array_intersect_key($r, array_flip(['need_upgrade', 'need_plan', 'need_credits', 'price', 'balance', 'locked', 'need_attestation', 'blocked_source']));
            $this->jsonError((string) $r['error'], $extra);
        }
        unset($r['ok'], $r['error']);
        $r['ai_credits'] = (int) (new AiCreditsModel())->get_balance(Permissions::creator_id());
        $this->jsonSuccess($r);
    }

    /* ---- frame extraction (any Library video; a paid plan like the other generators, no AI credits) ---- */

    public function media_extract_frameAction(){
        $user = $this->ai_user();
        set_time_limit(180);
        $this->answer(InfluencerVideoActions::extract_frame((int) $user['user_id'], $user, (int) ($this->post['asset_id'] ?? 0), (float) ($this->post['seconds'] ?? 0)));
    }

    /* ---- motion control ---- */

    public function influencer_motion_checkAction(){
        $user = $this->ai_user(false);
        set_time_limit(120);
        $this->answer(InfluencerVideoActions::motion_check((int) $user['user_id'], (int) ($this->post['video_asset_id'] ?? 0), (int) ($this->post['image_asset_id'] ?? 0)));
    }

    public function influencer_motion_first_frameAction(){
        $user = $this->ai_user();
        set_time_limit(180);
        $this->answer(InfluencerVideoActions::motion_first_frame((int) $user['user_id'], $user, $this->usable($user, (int) ($this->post['id'] ?? 0)), (int) ($this->post['video_asset_id'] ?? 0), 'studio'));
    }

    public function influencer_motion_startAction(){
        $user = $this->ai_user();
        set_time_limit(120);
        $in = array('video_asset_id' => (int) ($this->post['video_asset_id'] ?? 0), 'image_asset_id' => (int) ($this->post['image_asset_id'] ?? 0),
            'quality' => (string) ($this->post['quality'] ?? ''), 'model_key' => (string) ($this->post['model_key'] ?? ''), 'prompt' => $this->text('prompt', 1500));
        $this->answer(InfluencerVideoActions::motion_start((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), $in, 'studio'));
    }

    /* ---- character replacement ---- */

    private function replace_in(){
        return array('video_asset_id' => (int) ($this->post['video_asset_id'] ?? 0), 'subject' => $this->text('subject', 200),
            'outfit' => ((string) ($this->post['outfit'] ?? 'video') === 'reference') ? 'reference' : 'video',
            'lock_others' => $this->on('lock_others'), 'remove_text' => $this->on('remove_text'), 'model_key' => (string) ($this->post['model_key'] ?? ''));
    }

    public function influencer_replace_prepareAction(){
        $user = $this->ai_user();
        set_time_limit(120);
        $this->answer(InfluencerVideoActions::replace_prepare((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), $this->replace_in()));
    }

    public function influencer_replace_startAction(){
        $user = $this->ai_user();
        set_time_limit(300);
        $in = $this->replace_in();
        $in['prompt']   = $this->text('prompt', 4000);
        $in['attested'] = $this->on('attested');
        $this->answer(InfluencerVideoActions::replace_start((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), $in, 'studio'));
    }

    /* ---- long dialogue scenes ---- */

    private function scene_in(){
        $in = array('lines' => (array) $this->json_field('lines'), 'setting' => $this->text('setting', 800), 'second' => (string) ($this->post['second'] ?? 'none'),
            'second_influencer_id' => (int) ($this->post['second_influencer_id'] ?? 0), 'second_description' => $this->text('second_description', 300),
            'second_gender' => (string) ($this->post['second_gender'] ?? 'woman'), 'seconds' => (int) ($this->post['seconds'] ?? 0));
        foreach (['aspect', 'model_key'] as $k) { if (isset($this->post[$k])) { $in[$k] = (string) $this->post[$k]; } }
        return $in;
    }

    public function influencer_scene_buildAction(){
        $user = $this->ai_user(false);
        $this->answer(InfluencerVideoActions::scene_build((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), $this->scene_in()));
    }

    public function influencer_scene_startAction(){
        $user = $this->ai_user();
        $in = $this->scene_in();
        $in['prompt'] = $this->text('prompt', 6000);
        $this->answer(InfluencerVideoActions::scene_start((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), $in, 'studio'));
    }
}
