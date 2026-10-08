<?php
class IndexController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        // signed out with ?plan= / ?role= / ?ref= (e.g. /?auth=register&plan=creator): keep them for the signup, 30 days.
        if ((int) Session::get('user_id') === 0) {
            $p = UsersModel::signup_params($_GET);
            if (($p['plan'] . $p['role'] . $p['ref']) !== '') {
                $p = array_merge(UsersModel::signup_cookie(), array_filter($p, 'strlen'));
                setcookie('cls_signup', json_encode($p), array('expires' => time() + 30 * 86400, 'path' => '/', 'secure' => strpos(Main::get_base_domain(), 'https:') === 0, 'httponly' => false, 'samesite' => 'Lax'));
            }
            // ?founding=1 (from /founding): kept apart from cls_signup, which is cleared at signup; the billing plan tab reads it
            if ((string) ($_GET['founding'] ?? '') === '1') {
                setcookie('cls_founding', '1', array('expires' => time() + 30 * 86400, 'path' => '/', 'secure' => strpos(Main::get_base_domain(), 'https:') === 0, 'httponly' => false, 'samesite' => 'Lax'));
            }
            // ?aff=<code> (an approved affiliate's link): cls_aff for 30 days, last click wins, plus one click per viewer per hour
            Affiliates::capture($this->get_ip_address());
        }
        $this->view->render();
    }

}
