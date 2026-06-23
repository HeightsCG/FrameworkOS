<?php
class AccountController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        $this->view->render();
    }

    public function resetAction(){
        $this->view->reset_password();
    }

    // Forced password change (reset_pw=1). Standalone page; the base Controller's
    // gate routes flagged users here until they change their password.
    public function force_resetAction(){
        $this->view->force_reset_form();
    }

}
