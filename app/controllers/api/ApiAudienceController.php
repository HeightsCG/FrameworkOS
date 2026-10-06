<?php
/** Audience CRM tags and notes. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiAudienceController extends BaseApiController {

    public function audience_tag_addAction(){
        list($me, $fan) = $this->audience_guard();
        $tag = trim(html_entity_decode((string) ($this->post['tag'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($tag === '') { $this->jsonError('Empty tag'); }
        $model = new AudienceModel();
        $model->add_tag($me, $fan, $tag);
        $this->jsonSuccess(['tags' => $model->tags_for($me, $fan)]);
    }

    public function audience_tag_removeAction(){
        list($me, $fan) = $this->audience_guard();
        $tag = trim(html_entity_decode((string) ($this->post['tag'] ?? ''), ENT_QUOTES, 'UTF-8'));
        (new AudienceModel())->remove_tag($me, $fan, $tag);
        $this->jsonSuccess();
    }

    public function audience_note_saveAction(){
        list($me, $fan) = $this->audience_guard();
        $note = trim(html_entity_decode((string) ($this->post['note'] ?? ''), ENT_QUOTES, 'UTF-8'));
        (new AudienceModel())->save_note($me, $fan, $note);
        $this->jsonSuccess();
    }

    /* ---------- Launch Campaign ---------- */

    /** What the campaign window offers: influencers, accounts to post to, audience counts. */
    public function launch_campaign_optionsAction(){
        $user = $this->require_creator('content');
        $cid  = (int) $user['user_id'];
        $tz   = (string) ($user['content_timezone'] ?? 'UTC');
        $infl = array();
        foreach ((new InfluencersModel())->list_for_creator($cid) as $i) {
            if ((string) $i['status'] === 'ready') { $infl[] = array('id' => (int) $i['id'], 'name' => (string) $i['name']); }
        }
        $accounts = array();
        if (Plan::can_social_post($user)) {
            foreach ((new SocialAccountsModel())->get_connected_for_user($cid) as $a) {
                $accounts[] = array('id' => (string) $a['post_for_me_social_account_id'], 'platform' => (string) $a['platform'], 'name' => (string) ($a['username'] ?: $a['platform']));
            }
        }
        $fv = (new FanvueAccountsModel())->get_connected_for_user($cid);
        $bm = new BroadcastsModel();
        $this->jsonSuccess(['influencers' => $infl, 'accounts' => $accounts, 'fanvue' => $fv ? true : false,
            'counts' => $bm->counts($cid), 'labels' => BroadcastsModel::segment_labels(), 'timezone' => $tz,
            'launch_at' => LaunchCampaign::local(strtotime('+3 days', time()) - (time() % 3600) + 3600, $tz), 'max_days' => LaunchCampaign::MAX_DAYS]);
    }

    /** Work out the schedule and write the drafts. Nothing is created. */
    public function launch_campaign_draftAction(){
        $user = $this->require_creator('content');
        $this->answer_campaign(LaunchCampaign::draft((int) $user['user_id'], $user, [
            'what' => html_entity_decode((string) ($this->post['what'] ?? ''), ENT_QUOTES, 'UTF-8'), 'launch_at' => (string) ($this->post['launch_at'] ?? ''),
            'days' => (int) ($this->post['days'] ?? 3), 'influencer_id' => (int) ($this->post['influencer_id'] ?? 0), 'promo_code' => (string) ($this->post['promo_code'] ?? '')]));
    }

    /** Create every post and message of the campaign from the edited drafts. */
    public function launch_campaign_confirmAction(){
        $user = $this->require_creator('content');
        $in = [];
        foreach (['launch_at', 'destination', 'promo_code'] as $k) { $in[$k] = (string) ($this->post[$k] ?? ''); }
        foreach (['days', 'influencer_id', 'asset_id', 'promo_percent', 'promo_days'] as $k) { $in[$k] = (int) ($this->post[$k] ?? 0); }
        $in['items']          = is_array($this->post['items'] ?? null) ? array_values($this->post['items']) : [];
        foreach ($in['items'] as &$it) {   // the edited texts are POST input: decode entities here, as the other actions do
            if (is_array($it) && isset($it['text'])) { $it['text'] = html_entity_decode((string) $it['text'], ENT_QUOTES, 'UTF-8'); }
        }
        unset($it);
        $in['share_accounts'] = is_array($this->post['share_accounts'] ?? null) ? $this->post['share_accounts'] : [];
        $in['segments']       = is_array($this->post['segments'] ?? null) ? $this->post['segments'] : ['followers'];
        $in['also_cls']       = true;
        $this->answer_campaign(LaunchCampaign::confirm((int) $user['user_id'], $user, $in));
    }

    private function answer_campaign(array $r){
        if (empty($r['success'])) { $m = (string) ($r['message'] ?? 'Something went wrong.'); unset($r['success'], $r['message']); $this->jsonError($m, $r); }
        unset($r['success']);
        $this->jsonSuccess($r);
    }

    /* ---------- Notifications (PRD §27) ---------- */

    /** Guard: current user is a creator and the fan is in their audience. Returns [creator_id, fan_id] or exits with JSON error. */
    private function audience_guard(): array{
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (!Permissions::has_role('Creator')) { $this->jsonError('Creators only'); }
        $fan = (int) ($this->post['fan_id'] ?? 0);
        if ($fan <= 0 || !(new AudienceModel())->is_audience_member($me, $fan)) {
            $this->jsonError('Not in your audience');
        }
        return [$me, $fan];
    }

}
