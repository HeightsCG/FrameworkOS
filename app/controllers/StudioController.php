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
        $is_creator = Permissions::has_role('Creator');
        $this->view->is_creator = $is_creator;

        if ($is_creator) {
            $rows = $this->userModel->get_user_by_id((int) Session::get('user_id'));
            $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : array();

            // Active paid subscription tiers, for the composer's audience targeting.
            $plans = array();
            foreach ((new CreatorPlansModel())->get_active_for_user((int) ($user['user_id'] ?? 0)) as $p) {
                if ((int) ($p['price_cents'] ?? 0) > 0) {
                    $plans[] = array('id' => (int) $p['id'], 'name' => (string) $p['name'], 'price_cents' => (int) $p['price_cents']);
                }
            }
            $this->view->plans    = $plans;
            $this->view->s3_ready = S3Service::configured();
            $this->view->creator  = array(
                'user_id'            => (int) ($user['user_id'] ?? 0),
                'display_name'       => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
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
