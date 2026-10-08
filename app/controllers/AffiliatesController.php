<?php
/**
 * Signed-in affiliate pages (app shell): /affiliates/apply (the application, or its status) and /affiliates/dashboard
 * (link, numbers, commissions, payouts). Reached through PagesController::affiliatesAction, which owns /affiliates.
 */
class AffiliatesController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        header('Location: /affiliates'); exit;
    }

    /** /affiliates/apply: the form, or where the application stands. Approved affiliates go to their dashboard. */
    public function applyAction(){
        $uid = (int) Session::get('user_id');
        if ($uid <= 0) { header('Location: /?auth=register&next=' . rawurlencode('/affiliates/apply')); exit; }
        $a = (new AffiliatesModel())->for_user($uid);
        if ($a && (string) $a['status'] === 'approved') { header('Location: /affiliates/dashboard'); exit; }
        $this->view->affiliate = $a;
        $this->view->render();
    }

    /** /affiliates/dashboard: approved affiliates only. ?from= / ?to= (YYYY-MM-DD) filter the commissions. */
    public function dashboardAction(){
        $uid = (int) Session::get('user_id');
        if ($uid <= 0) { header('Location: /?auth=login&next=' . rawurlencode('/affiliates/dashboard')); exit; }
        $a = Affiliates::approved_for_user($uid);
        if (!$a) { header('Location: /affiliates/apply'); exit; }
        Session::destroyValue('aff_menu');   // the account menu re-checks (e.g. just approved)
        $day = function ($v) { $v = (string) $v; return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v . ' UTC') ? $v : ''; };
        $from = $day($_GET['from'] ?? ''); $to = $day($_GET['to'] ?? '');
        $me = (new UsersModel())->get_user_by_id($uid);
        $this->view->timezone    = (is_array($me) && count($me) === 1) ? (string) ($me[0]['content_timezone'] ?? 'UTC') : 'UTC';
        $this->view->affiliate   = $a;
        $this->view->link        = Affiliates::link($a['code']);
        $this->view->stats       = Affiliates::stats($a);
        $this->view->from        = $from;
        $this->view->to          = $to;
        $this->view->commissions = (new AffiliateCommissionsModel())->list_for((int) $a['id'], $from, $to);
        $this->view->payouts     = (new AffiliatePayoutsModel())->list_for((int) $a['id']);
        $this->view->open_payout = (new AffiliatePayoutsModel())->open_for((int) $a['id']);
        $this->view->render();
    }
}
