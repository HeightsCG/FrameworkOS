<?php
/**
 * Creator analytics dashboard (/dashboard). Creator-only; non-creators are redirected
 * home. Aggregations come from AnalyticsModel.
 */
class DashboardController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        $user_id    = (int) Session::get('user_id');
        $is_creator = Permissions::has_role('Creator');
        if (!$is_creator) { header('Location: /'); exit; }

        $rows = (new UsersModel())->get_user_by_id($user_id);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;

        // Analytics is a creator feature — requires an active platform plan. We still
        // render the dashboard, but the view blurs it behind a plan-lock overlay.
        $this->view->needs_plan = !Plan::can_use_creator_features($user);

        $name = $user ? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) : '';
        $this->view->display_name = $name !== '' ? $name : ('@' . ($user['u_name'] ?? ''));
        $this->view->is_creator   = true;

        $a = new AnalyticsModel();
        $this->view->stats          = $a->overview($user_id);
        $this->view->views_series   = $a->views_series($user_id, 30);
        $this->view->top_posts      = $a->top_posts($user_id, 5);
        $this->view->recent_unlocks = $a->recent_unlocks($user_id, 8);
        $this->view->timezone       = (string) ($user['content_timezone'] ?? 'UTC');

        $this->view->render();
    }

}
