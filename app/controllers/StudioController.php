<?php
/**
 * Content Studio — the creator's single workspace for media, posts, scheduling,
 * and sharing. One page at /studio (tabs are client-side). Creator-only.
 */
class StudioController extends Controller {

    public $protected = 1;
    private $userModel;

    public function __construct(){
        parent::__construct();
        $this->userModel = new UsersModel();
    }

    public function indexAction(){
        $is_creator = Permissions::can_act_as_creator();
        $this->view->is_creator = $is_creator;

        if ($is_creator) {
            // Collaborators operate on the owner's account; solo creators on their own.
            $rows = $this->userModel->get_user_by_id(Permissions::creator_id());
            $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : array();

            // Creator tools require an active platform plan. We still render the
            // full workspace, but the view blurs it behind a plan-lock overlay.
            $this->view->needs_plan = !Plan::can_use_creator_features($user);

            // Active paid subscription tiers, for the composer's audience targeting.
            $plans = array();
            foreach ((new CreatorPlansModel())->get_active_for_user((int) ($user['user_id'] ?? 0)) as $p) {
                if ((int) ($p['price_cents'] ?? 0) > 0) {
                    $plans[] = array('id' => (int) $p['id'], 'name' => (string) $p['name'], 'price_cents' => (int) $p['price_cents']);
                }
            }
            $this->view->plans    = $plans;

            // Connected social accounts, for the composer's cross-post checkboxes.
            $accounts = array();
            foreach ((new SocialAccountsModel())->get_connected_for_user((int) ($user['user_id'] ?? 0)) as $a) {
                $accounts[] = array(
                    'id'       => (string) $a['post_for_me_social_account_id'],
                    'platform' => (string) $a['platform'],
                    'username' => (string) ($a['username'] ?? ''),
                );
            }
            // Fanvue joins the same picker as a pseudo account (full-post mirror, see FanvueShareService).
            $fv = (new FanvueAccountsModel())->get_connected_for_user((int) ($user['user_id'] ?? 0));
            if ($fv) {
                array_unshift($accounts, array(
                    'id'       => FanvueShareService::ACCOUNT_ID,
                    'platform' => 'fanvue',
                    'username' => (string) ($fv['handle'] !== '' && $fv['handle'] !== null ? $fv['handle'] : 'Fanvue'),
                ));
            }
            $this->view->social = array(
                'accounts' => $accounts,
                'can_post' => Plan::can_social_post($user),
            );

            // Inbox automation (scheduled messages); Fanvue lists only when chat scope was granted.
            $fv_inbox = (new FanvueAccountsModel())->get_connected_for_user((int) ($user['user_id'] ?? 0));
            $this->view->inbox = array(
                'can'          => true,
                'fanvue_ok'    => $fv_inbox ? FanvueAccountsModel::has_chat_scope($fv_inbox) : false,
                'fanvue_lists' => SchedulerRulesModel::FANVUE_LISTS,
                'cls_segments' => SchedulerRulesModel::CLS_SEGMENTS,
            );

            $this->view->can_ai = true;

            // AI influencers: trained ones can drive automations; all of them filter the library.
            $infl_all = array(); $infl_ready = array();
            foreach ((new InfluencersModel())->list_for_creator((int) ($user['user_id'] ?? 0)) as $inf) {
                $row = array('id' => (int) $inf['id'], 'name' => (string) $inf['name'], 'status' => (string) $inf['status'],
                    'share_accounts' => InfluencersModel::share_accounts($inf));
                $infl_all[] = $row;
                if ((string) $inf['status'] === 'ready' && !empty($inf['active_model_id'])) { $infl_ready[] = $row; }
            }
            $this->view->influencers = array('all' => $infl_all, 'ready' => $infl_ready);

            // Brand identity — used to steer AI image generation on-brand.
            $brand = (new CreatorBrandModel())->get_for_user((int) ($user['user_id'] ?? 0));
            $this->view->brand = array(
                'has_brand'  => (!empty($brand['brand_name']) || !empty($brand['colors']) || !empty($brand['voice']) || !empty($brand['keywords'])),
                'brand_name' => (string) ($brand['brand_name'] ?? ''),
            );

            $cp = (new CreatorProfileModel())->get_for_user((int) ($user['user_id'] ?? 0));
            $profile_name = trim((string) ($cp['display_name'] ?? ''));

            $this->view->s3_ready = S3Service::configured();
            $this->view->creator  = array(
                'user_id'            => (int) ($user['user_id'] ?? 0),
                'display_name'       => $profile_name !== '' ? $profile_name : trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                'avatar_url'         => (string) ($cp['avatar_url'] ?? ''),
                'u_name'             => (string) ($user['u_name'] ?? ''),
                'timezone'           => (string) ($user['content_timezone'] ?? 'UTC'),
                'watermark_enabled'  => !empty($user['watermark_enabled']),
                'watermark_text'     => (string) ($user['watermark_text'] ?? ''),
                'watermark_position' => (string) ($user['watermark_position'] ?? 'bottom_right'),
                'watermark_opacity'  => (int) ($user['watermark_opacity'] ?? 40),
            );
        }

        $this->view->render();
    }

}
