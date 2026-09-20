<?php
/**
 * The Inbox (/inbox): every signed-in account's direct messages on one full page —
 * conversation list, thread with media and paid unlocks, compose, broadcasts and
 * the people picker. Deep links: /inbox/thread/<conversation id>, /inbox/with/<user id>
 * (open or start a thread with someone), /inbox/new (people picker open).
 */
class InboxController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        $this->render_page(array());
    }

    public function threadAction(){
        $url = Main::get_url();
        $id  = (int) ($url[2] ?? 0);
        $me  = (int) Session::get('user_id');
        $model = new MessagesModel();
        if ($id <= 0 || !$model->is_participant($id, $me)) { header('Location: /inbox'); exit; }
        $this->render_page(array('conversation_id' => $id));
    }

    public function withAction(){
        $url = Main::get_url();
        $uid = (int) ($url[2] ?? 0);
        if ($uid <= 0 || $uid === (int) Session::get('user_id')) { header('Location: /inbox'); exit; }
        $this->render_page(array('to_user' => $uid));
    }

    public function newAction(){
        $this->render_page(array('compose' => true));
    }

    private function render_page(array $init){
        $me = (int) Session::get('user_id');
        $is_creator = Permissions::has_role('Creator');
        $this->view->init = json_encode(array_merge(array(
            'me'             => $me,
            'is_creator'     => $is_creator,
            'segment_labels' => BroadcastsModel::segment_labels(),
            'segments'       => BroadcastsModel::segments(),
            'wallet_url'     => '/account/settings?section=wallet',
        ), $init));
        $this->view->is_creator = $is_creator;
        $this->view->render();
    }

}
