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
        if (!Permissions::can_act_as_creator()) { header('Location: /'); exit; }
        $user_id = Permissions::creator_id();   // owner account for collaborators, self otherwise

        $rows = (new UsersModel())->get_user_by_id($user_id);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;

        // Analytics is a creator feature — requires an active platform plan. We still
        // render the dashboard, but the view blurs it behind a plan-lock overlay.
        $this->view->needs_plan = !Plan::can_use_creator_features($user);

        $name = $user ? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) : '';
        $this->view->display_name = $name !== '' ? $name : ('@' . ($user['u_name'] ?? ''));
        $this->view->is_creator   = true;

        $tz = (string) ($user['content_timezone'] ?? 'UTC');

        // Date range from the URL (/dashboard/index/<days>), 30 by default. No $_GET —
        // this is the routed path segment. Only a fixed set of ranges is allowed.
        $url   = Main::get_url();
        $range = (int) ($url[2] ?? 30);
        if (!in_array($range, array(7, 30, 90), true)) { $range = 30; }

        $a = new AnalyticsModel();
        $start_utc = $a->range_start_utc($range, $tz);
        $this->view->range             = $range;
        $this->view->stats             = $a->overview($user_id);
        $this->view->compare           = $a->compare_periods($user_id, $range, $tz);
        $this->view->views_series      = $a->views_series($user_id, $range, $tz);
        $this->view->revenue_series    = $a->revenue_series($user_id, $range, $tz);
        $this->view->follower_series   = $a->follower_series($user_id, $range, $tz);
        $this->view->revenue_breakdown = $a->revenue_breakdown($user_id, $start_utc);
        $this->view->revenue_lifetime  = $a->revenue_breakdown($user_id);   // all-time total for KPI context
        $this->view->top_posts         = $a->top_posts($user_id, 5);
        $this->view->recent_sales      = $a->recent_sales($user_id, 8);
        $this->view->customers         = $a->customer_stats($user_id);
        $this->view->sub_movement      = $a->subscriber_movement($user_id, $range, $tz);
        $this->view->timezone          = $tz;

        $this->view->render();
    }

}
