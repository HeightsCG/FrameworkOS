<?php
/**
 * AI influencers (/influencers). Four destinations: the influencer gallery (index), the
 * create wizard (/influencers/create[/<id>]), Generate Images (/influencers/images/<id>),
 * Generate Videos (/influencers/videos/<id>) and the per-influencer Gallery
 * (/influencers/gallery/<id>). Creator-only, gated on the ai_tools plan flag like the
 * Studio's AI actions. Collaborators act on the owner's account via Permissions::creator_id().
 */
class InfluencersController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    /** Shared gate + page config. Returns the owner row, or redirects. */
    private function gate(){
        if (!Permissions::can_act_as_creator() || !Permissions::team_allows('content')) { header('Location: /'); exit; }
        $creator_id = Permissions::creator_id();
        $rows = (new UsersModel())->get_user_by_id($creator_id);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$user) { header('Location: /'); exit; }
        $this->view->needs_plan = !Plan::can_use_creator_features($user);
        $this->view->can_ai     = Plan::can_use_creator_features($user);
        $this->view->creator_id = (int) $creator_id;
        Plan::grant_monthly($user);
        $cfg = InfluencerService::page_config();
        $cfg['ai_credits'] = (new AiCreditsModel())->get_balance((int) $creator_id);
        $this->view->config     = $cfg;
        $this->view->ready      = array();
        foreach ((new InfluencersModel())->list_ready($creator_id) as $r) {
            $this->view->ready[] = array('id' => (int) $r['id'], 'name' => (string) $r['name']);
        }
        // Connected share targets (same list the Studio composer uses) for her default share targets.
        $accounts = array();
        foreach ((new SocialAccountsModel())->get_connected_for_user((int) $user['user_id']) as $a) {
            $accounts[] = array('id' => (string) $a['post_for_me_social_account_id'], 'platform' => (string) $a['platform'], 'username' => (string) ($a['username'] ?? ''));
        }
        $fv = (new FanvueAccountsModel())->get_connected_for_user((int) $user['user_id']);
        if ($fv) { array_unshift($accounts, array('id' => FanvueShareService::ACCOUNT_ID, 'platform' => 'fanvue', 'username' => (string) (($fv['handle'] ?? '') !== '' ? $fv['handle'] : 'Fanvue'))); }
        $this->view->social = array('accounts' => $accounts, 'can_post' => Plan::can_social_post($user));
        return $user;
    }

    /** Segment 2 of the routed path (/influencers/<action>/<id>), never $_GET. */
    private function id_from_url(){
        $url = Main::get_url();
        return (int) ($url[2] ?? 0);
    }

    /** Your Influencers: the card gallery. */
    public function indexAction(){
        $this->gate();
        $this->view->page = 'index';
        $this->view->render();
    }

    /** Path chooser (no id) or the wizard for one influencer. */
    public function createAction(){
        $user = $this->gate();
        $this->view->page = 'create';
        $id = $this->id_from_url();
        $this->view->influencer = null;
        if ($id > 0) {
            $infl = (new InfluencersModel())->get_one((int) $user['user_id'], $id);
            if (!$infl) { header('Location: /influencers'); exit; }
            $this->view->influencer = InfluencerService::influencer_json((int) $user['user_id'], $infl);
        }
        $this->view->retrain = ((string) (Main::get_url()[3] ?? '') === 'retrain');   // /influencers/create/<id>/retrain
        $this->view->name_suggestions = array('woman' => InfluencerService::name_suggestions((int) $user['user_id'], 4, 'woman'), 'man' => InfluencerService::name_suggestions((int) $user['user_id'], 4, 'man'));
        $this->view->render();
    }

    /** A ready influencer for the generate pages, or a redirect to her wizard. */
    private function ready_influencer($user){
        $id = $this->id_from_url();
        $infl = $id > 0 ? (new InfluencersModel())->get_one((int) $user['user_id'], $id) : null;
        if (!$infl) {
            $ready = (new InfluencersModel())->list_ready((int) $user['user_id']);
            if (!empty($ready)) { header('Location: /influencers/' . strtolower(str_replace('Action', '', Main::method_name())) . '/' . (int) $ready[0]['id']); exit; }
            header('Location: /influencers'); exit;
        }
        if ((string) $infl['status'] !== 'ready' || empty($infl['active_model_id'])) { header('Location: /influencers/create/' . (int) $infl['id']); exit; }
        $this->view->influencer = InfluencerService::influencer_json((int) $user['user_id'], $infl);
        return $infl;
    }

    /** Generate Images, scoped to one trained influencer. */
    public function imagesAction(){
        $user = $this->gate();
        $this->view->page = 'images';
        $this->ready_influencer($user);
        $this->view->render();
    }

    /** Generate Videos from a still of her; /influencers/videos/<id>/<asset_id> preselects the still. */
    public function videosAction(){
        $user = $this->gate();
        $this->view->page = 'videos';
        $infl = $this->ready_influencer($user);
        $aid  = (int) (Main::get_url()[3] ?? 0);
        $this->view->still_asset_id = 0;
        if ($aid > 0) {
            $a = (new MediaAssetsModel())->get_one((int) $user['user_id'], $aid);
            if ($a && (string) $a['type'] === 'image' && (string) $a['status'] === 'ready') { $this->view->still_asset_id = $aid; }
        }
        $this->view->render();
    }

    /** Gallery: everything generated for one influencer, browsable by role. */
    public function galleryAction(){
        $user = $this->gate();
        $this->view->page = 'gallery';
        $this->ready_influencer($user);
        $this->view->render();
    }
}
