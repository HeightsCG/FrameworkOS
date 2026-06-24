<?php
class AccountController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function profileAction(){
        $this->view->render();
    }

}
