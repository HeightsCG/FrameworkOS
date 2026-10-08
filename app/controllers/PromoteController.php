<?php
/**
 * Cross-promotion (/promote): creators who opted in find each other by niche, request a swap, accept or decline
 * incoming requests and see the clicks each running swap sends. Management is Manager+ on the owner's account
 * (Permissions::creator_id). /promote/click/<swap>/<to> is the public tracked redirect behind a Featured Creators card.
 */
class PromoteController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        if (!Permissions::can_act_as_creator() || !Permissions::team_allows('manage')) { header('Location: /'); exit; }
        $creator_id = Permissions::creator_id();
        $model = new PromoSwapsModel();
        $profile = (new CreatorProfileModel())->get_for_user($creator_id);

        $this->view->eligible  = PromoSwapsModel::eligible($creator_id);
        $this->view->opted_in  = $model->opted_in($creator_id);
        $this->view->my_niche  = (string) ($profile['directory_category'] ?? '');
        $this->view->creators  = ($this->view->eligible && $this->view->opted_in) ? $model->browse($creator_id) : array();
        $this->view->swaps     = $model->for_creator($creator_id);
        $me_rows = (new UsersModel())->get_user_by_id((int) Session::get('user_id'));
        $this->view->timezone  = (string) (((is_array($me_rows) && count($me_rows) === 1) ? $me_rows[0]['content_timezone'] : '') ?: 'UTC');
        $this->view->plan_names = array_map(function ($k) { return PlanTiers::TIERS[$k]['name']; }, PromoSwapsModel::plans());
        $this->view->render();
    }

    /**
     * /promote/click/<swap_id>/<to_id>: count the click, then send the visitor to the partner's page (their own
     * domain when they have one). Signed in or not. Anything that isn't a running swap goes to the home page.
     */
    public function clickAction(){
        $url   = Main::get_url();
        $swap  = (int) ($url[2] ?? 0);
        $to_id = (int) ($url[3] ?? 0);
        header('X-Robots-Tag: noindex');
        $model = new PromoSwapsModel();
        $sw = ($swap > 0 && $to_id > 0) ? $model->active_pair($swap, $to_id) : null;
        $rows = $sw ? (new UsersModel())->get_user_by_id($to_id) : array();
        $to = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$sw || !$to || (int) ($to['deleted'] ?? 0) === 1 || (string) ($to['u_name'] ?? '') === '') {
            header('Location: /', true, 302);
            exit;
        }
        if ((string) ($to['user_status'] ?? '') !== 'Active') { Errors::page_not_found(); return; }   // suspended partner: no page to send anyone to
        $from_id = (int) $sw['requester_id'] === $to_id ? (int) $sw['partner_id'] : (int) $sw['requester_id'];
        $viewer  = (int) Session::get('user_id');
        $acting  = $viewer > 0 ? Permissions::creator_id() : 0;   // a team member clicks as their creator
        if ($acting !== $from_id && $acting !== $to_id) {   // the two creators' own clicks don't count
            // one click per viewer an hour: the signed-in account, else this browser (ip + user agent)
            $hash = $viewer > 0 ? sha1('u:' . $viewer) : sha1('a:' . $this->get_ip_address() . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $model->record_click($swap, $from_id, $to_id, $hash);
        }
        header('Location: ' . CustomDomains::canonical_profile_url($to), true, 302);
        exit;
    }
}
