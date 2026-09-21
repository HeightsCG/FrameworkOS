<?php
/**
 * Creator onboarding checklist (/setup). Creators with an active plan only: fans go home,
 * creators without a plan go to billing. Always re-evaluates incomplete steps so a return
 * from Settings or Stripe shows the tick immediately.
 */
class SetupController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        if (!Permissions::can_act_as_creator()) { header('Location: /'); exit; }
        if (!SetupService::eligible()) { header('Location: /account/billing'); exit; }

        $creator_id = Permissions::creator_id();
        $rows = (new UsersModel())->get_user_by_id($creator_id);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;

        $this->view->progress = SetupService::progress($creator_id, true);
        $this->view->timezone = (string) ($user['content_timezone'] ?? 'UTC');
        $this->view->render();
    }
}
