<?php
class AdminController extends Controller {

    public $protected = 1;

    public function __construct() {
        parent::__construct();
    }

    public function indexAction(){
        if (!Permissions::has_role('admin')) {
            header('Location: /');
            exit;
        }
        $this->view->render();
    }

}