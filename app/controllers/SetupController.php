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
        $plan = in_array((string) ($_GET['plan'] ?? ''), array('creator', 'studio'), true) ? (string) $_GET['plan'] : '';
        if ((int) Session::get('user_id') === 0) { self::bounce(); }   // signed out: sign in, then back here
        if (!Permissions::can_act_as_creator()) {
            // signed up as a creator without accepting the Creator Agreement (Google): accept it in Settings first.
            $rows = (new UsersModel())->get_user_by_id((int) Session::get('user_id'));
            $signup_role = (is_array($rows) && count($rows) === 1) ? (string) ($rows[0]['signup_role'] ?? '') : '';
            header('Location: ' . ($signup_role === 'creator' ? '/account/settings?section=creator' : '/'));
            exit;
        }
        if (!SetupService::eligible()) { header('Location: /account/billing' . ($plan !== '' ? '?tab=plan&plan=' . $plan : '')); exit; }

        $creator_id = Permissions::creator_id();
        $rows = (new UsersModel())->get_user_by_id($creator_id);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;

        // First arrival from the verify link (?plan=) as a Free creator who signed up with the trial: straight into that
        // plan's checkout, once per session. Coming back to /setup later shows the checklist (its first step is the trial).
        if ($plan !== '' && $user && !Permissions::is_team_member() && !Session::get('setup_checkout_sent') && UpgradePrompt::founding($user)) {
            Session::set('setup_checkout_sent', 1);
            header('Location: ' . UpgradePrompt::checkout_url($plan, true));
            exit;
        }

        $this->view->progress = SetupService::progress($creator_id, true);
        $this->view->timezone = (string) ($user['content_timezone'] ?? 'UTC');
        $this->view->render();
    }
}
