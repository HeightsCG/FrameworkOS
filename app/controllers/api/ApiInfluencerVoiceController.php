<?php
/**
 * An influencer's voice: Voice Design, saved voices, text to speech and talking video. Thin
 * wrappers over InfluencerVoiceActions (shared with the Claude connector in McpTools). Routed from
 * /api/<action> by ApiRoutes. Gated like the other generators: content role, a paid plan, AI credits.
 */
class ApiInfluencerVoiceController extends BaseApiController {

    private function ai_user($need_plan = true){ return $this->require_creator('content', $need_plan); }

    private function text($key, $max = 5000){
        return mb_substr(trim(html_entity_decode((string) ($this->post[$key] ?? ''), ENT_QUOTES, 'UTF-8')), 0, $max);
    }

    /** Script text keeps its line breaks (paragraphs decide how a long script is split). */
    private function script($key, $max = 20000){
        return mb_substr(trim(str_replace("\r\n", "\n", html_entity_decode((string) ($this->post[$key] ?? ''), ENT_QUOTES, 'UTF-8'))), 0, $max);
    }

    private function usable($user, $id){
        $infl = (new InfluencersModel())->get_one((int) $user['user_id'], (int) $id);
        if (!$infl) { $this->jsonError('Influencer not found.'); }
        if (Plan::is_locked($user, 'influencers', (int) $infl['id'])) { $this->jsonError(Plan::locked_message($user, 'influencers'), ['need_upgrade' => true, 'locked' => true]); }
        return $infl;
    }

    private function answer(array $r){
        if (empty($r['ok'])) {
            $extra = array_intersect_key($r, array_flip(['need_upgrade', 'need_plan', 'need_credits', 'price', 'balance', 'locked', 'need_voice', 'at_cap', 'field']));
            $this->jsonError((string) $r['error'], $extra);
        }
        unset($r['ok'], $r['error']);
        $r['ai_credits'] = (int) (new AiCreditsModel())->get_balance(Permissions::creator_id());
        $this->jsonSuccess($r);
    }

    /* ---- voices ---- */

    public function influencer_voicesAction(){
        $user = $this->ai_user(false);
        Plan::grant_monthly($user);
        $this->answer(InfluencerVoiceActions::voices((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0))));
    }

    /** Fill in the Design A Voice form from her persona. */
    public function influencer_voice_suggestAction(){
        $user = $this->ai_user();
        $this->answer(InfluencerVoiceActions::suggest($this->usable($user, (int) ($this->post['id'] ?? 0))));
    }

    public function influencer_voice_designAction(){
        $user = $this->ai_user();
        set_time_limit(240);
        $f = array('preview_text' => $this->script('preview_text', 1200));
        foreach (['age_vibe', 'keyword', 'city', 'country', 'tone'] as $k) { $f[$k] = $this->text($k, 200); }
        $this->answer(InfluencerVoiceActions::design((int) $user['user_id'], $user, $this->usable($user, (int) ($this->post['id'] ?? 0)), $f));
    }

    public function influencer_voice_saveAction(){
        $user = $this->ai_user();
        $this->answer(InfluencerVoiceActions::save_voice((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)),
            (string) ($this->post['token'] ?? ''), (int) ($this->post['index'] ?? -1), $this->text('name', 120)));
    }

    public function influencer_voice_activateAction(){
        $user = $this->ai_user();
        $this->answer(InfluencerVoiceActions::set_active((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), (int) ($this->post['voice_id'] ?? 0)));
    }

    public function influencer_voice_deleteAction(){
        $user = $this->ai_user(false);
        $this->answer(InfluencerVoiceActions::delete_voice((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), (int) ($this->post['voice_id'] ?? 0)));
    }

    /* ---- text to speech ---- */

    public function influencer_speech_priceAction(){
        $user = $this->ai_user(false);
        $this->answer(array('ok' => true, 'error' => '', 'price' => InfluencerVoiceActions::speech_price($this->script('text')), 'design_price' => InfluencerVoiceActions::design_price($this->script('preview_text'))));
    }

    public function influencer_speech_enhanceAction(){
        $user = $this->ai_user();
        set_time_limit(90);
        $this->answer(InfluencerVoiceActions::enhance($this->usable($user, (int) ($this->post['id'] ?? 0)), $this->script('text', ElevenLabsService::SPEECH_MAX)));
    }

    public function influencer_speech_startAction(){
        $user = $this->ai_user();
        $this->answer(InfluencerVoiceActions::speech_start((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), $this->script('text'), (int) ($this->post['voice_id'] ?? 0), 'studio'));
    }

    public function influencer_speech_saveAction(){
        $user = $this->ai_user(false);
        $this->answer(InfluencerVoiceActions::speech_save((int) $user['user_id'], $user, (int) ($this->post['job_id'] ?? 0), (int) ($this->post['take'] ?? -1)));
    }

    /* ---- talking video ---- */

    private function talking_in(){
        return array('image_asset_id' => (int) ($this->post['image_asset_id'] ?? 0), 'audio_asset_id' => (int) ($this->post['audio_asset_id'] ?? 0), 'script' => $this->script('script'));
    }

    public function influencer_talking_estimateAction(){
        $user = $this->ai_user(false);
        $this->answer(InfluencerVoiceActions::talking_estimate((int) $user['user_id'], $this->usable($user, (int) ($this->post['id'] ?? 0)), $this->talking_in()));
    }

    public function influencer_talking_startAction(){
        $user = $this->ai_user();
        set_time_limit(600);
        $this->answer(InfluencerVoiceActions::talking_start((int) $user['user_id'], $user, $this->usable($user, (int) ($this->post['id'] ?? 0)), $this->talking_in(), 'studio'));
    }

    public function influencer_talking_statusAction(){
        $user = $this->ai_user(false);
        $this->answer(array('ok' => true, 'error' => '', 'status' => InfluencerVoiceActions::talking_status((int) $user['user_id'], (string) ($this->post['group_key'] ?? ''))));
    }
}
