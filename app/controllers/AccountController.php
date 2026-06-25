<?php
class AccountController extends Controller {

    public $protected = 1;
    private $userModel;

    public function __construct(){
        parent::__construct();
        $this->userModel = new UsersModel();
    }

    public function profileAction(){
        $user = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (is_array($user) && count($user) === 1) {
            $this->view->user = $user[0];
            $this->view->render();
        } else {
            Header('Location: /');
            exit;
        }
        
    }

}
