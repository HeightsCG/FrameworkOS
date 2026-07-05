<?php
/**
 * Content Studio — the creator's on-platform content workspace (top-level page,
 * left-sidebar item). Creator-only; the public-facing display of this content
 * is the Content tab on /@handle.
 */
class StudioController extends Controller {

    public $protected = 1;
    private $userModel;

    public function __construct(){
        parent::__construct();
        $this->userModel = new UsersModel();
    }

    public function indexAction(){
        $user = $this->userModel->get_user_by_id(Session::get('user_id'));
        $user = (is_array($user) && count($user) === 1) ? $user[0] : null;

        $creator_role_id = $this->userModel->get_role_id_by_name('Creator');
        if (!$user || (int) $user['role_id'] !== $creator_role_id) {
            header('Location: /');
            exit;
        }

        $itemsModel = new ContentItemsModel();
        $itemsModel->publish_due($user['user_id']); // any scheduled posts now due go live

        $this->view->content_items = $itemsModel->get_for_creator($user['user_id']);
        $this->view->creator_plans = (new CreatorPlansModel())->get_for_user($user['user_id']);
        $this->view->render();
    }
}
