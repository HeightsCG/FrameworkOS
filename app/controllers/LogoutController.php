<?php
class LogoutController extends Controller {

    public function indexAction() {
        Main::do_logout();
        header('Location: /');
        exit;
    }

}