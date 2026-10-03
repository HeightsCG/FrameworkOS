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
        $is_creator = Permissions::can_act_as_creator() || Plan::cover('studio') !== null;   // a Free account sees the page behind an upgrade cover (Plan::COVERS)
        $this->view->is_creator = $is_creator;

        if ($is_creator) {
            // Collaborators operate on the owner's account; solo creators on their own.
            $rows = $this->userModel->get_user_by_id(Permissions::creator_id());
            $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : array();

            // Creator tools require an active platform plan. We still render the
            // full workspace, but the view blurs it behind a plan-lock overlay.
            $this->view->needs_plan = !Plan::can_use_creator_features($user) && !Plan::cover('studio');   // behind the cover the full workspace shows, create buttons included

            // Every active membership tier (free ones too), for the composer's tier checkboxes.
            $plans = array();
            foreach ((new CreatorPlansModel())->get_active_for_user((int) ($user['user_id'] ?? 0)) as $p) {
                $plans[] = array('id' => (int) $p['id'], 'name' => (string) $p['name'], 'price_cents' => (int) $p['price_cents']);
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

            // Scheduled messages go to Creator Link Studio audience segments.
            $this->view->inbox = array(
                'can'          => true,
                'cls_segments' => SchedulerRulesModel::CLS_SEGMENTS,
                'segment_labels' => BroadcastsModel::segment_labels(),
            );

            $this->view->can_ai = true;

            // AI influencers: trained ones can drive automations; all of them filter the library.
            $infl_all = array(); $infl_ready = array();
            foreach ((new InfluencersModel())->list_for_creator((int) ($user['user_id'] ?? 0)) as $inf) {
                $row = array('id' => (int) $inf['id'], 'name' => (string) $inf['name'], 'status' => (string) $inf['status'],
                    'share_accounts' => InfluencersModel::share_accounts($inf));
                $infl_all[] = $row;
                if ((string) $inf['status'] === 'ready' && !empty($inf['active_model_id']) && !Plan::is_locked($user, 'influencers', (int) $inf['id'])) { $infl_ready[] = $row; }
            }
            $this->view->influencers = array('all' => $infl_all, 'ready' => $infl_ready);

            // Automations the plan allows (Free has none): the Scheduler shows an upgrade prompt instead of the form.
            $acap = Plan::check_count($user, 'automations', (new SchedulerRulesModel())->count_for_creator((int) ($user['user_id'] ?? 0)));
            $alim = Plan::limit($user, 'automations');
            $aup  = PlanTiers::lowest_including('automations');
            $this->view->automation_plan = array('can_create' => !empty($acap['ok']), 'included' => $alim !== null && (int) $alim >= 0,
                'message' => (string) ($acap['message'] ?? ''), 'upgrade_name' => $aup ? (string) $aup['name'] : '');

            // AI credits: the Generate window checks these before anyone types a prompt.
            Plan::grant_monthly($user);
            $video_models = array();
            foreach (InfluencerConfig::picker_options('video') as $vm) {
                $video_models[] = array('key' => $vm['key'], 'label' => $vm['label'], 'purpose' => $vm['purpose'], 'durations' => $vm['durations'],
                    'credits' => Plan::ai_price('video', array('model_key' => $vm['key'])), 'credits_by_duration' => $vm['credits_by_duration']);
            }
            $this->view->ai = array('balance' => (int) (new AiCreditsModel())->get_balance((int) ($user['user_id'] ?? 0)), 'image_price' => Plan::ai_price('image'),
                'video_models' => $video_models);

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


    /** Download several Library files as one zip: /studio/download?ids=1,2,3 (originals, up to MAX_ZIP_FILES). */
    const MAX_ZIP_FILES = 100;
    public function downloadAction(){
        if (!Permissions::can_act_as_creator() || !Permissions::team_allows('content')) { http_response_code(403); exit('Not allowed.'); }
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? '')))), 0, self::MAX_ZIP_FILES);
        $assets = (new MediaAssetsModel())->get_ready_many(Permissions::creator_id(), $ids);
        if (empty($assets)) { http_response_code(404); exit('Nothing to download.'); }
        @set_time_limit(0);
        $zip = tempnam(sys_get_temp_dir(), 'zip');
        if (MediaService::build_zip($zip, $assets, 'original') === 0) { @unlink($zip); http_response_code(500); exit('Could not prepare the download.'); }
        MediaService::send_zip($zip, 'Library ' . gmdate('Y-m-d') . '.zip');
    }
}
