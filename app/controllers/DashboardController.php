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
        if (!Permissions::can_act_as_creator() && !Plan::cover('dashboard')) { self::bounce(); }   // a Free account sees the page behind an upgrade cover (Plan::COVERS)
        $user_id = Permissions::creator_id();   // owner account for collaborators, self otherwise

        $rows = (new UsersModel())->get_user_by_id($user_id);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;

        // Analytics is a creator feature — requires an active platform plan. We still
        // render the dashboard, but the view blurs it behind a plan-lock overlay.
        $this->view->needs_plan = !Plan::can_use_creator_features($user);

        // the signed-in person's own email (a collaborator's, not the owner's): unconfirmed shows the Resend Email bar
        $me = (new UsersModel())->get_user_by_id((int) Session::get('user_id'));
        $me = (is_array($me) && count($me) === 1) ? $me[0] : null;
        $this->view->verify_email   = ($me && (int) ($me['email_verified'] ?? 0) === 0) ? (string) $me['user_email'] : '';
        $this->view->verify_bounced = $me && LifecycleEmails::bounced($me);

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
        $this->view->follower_series   = $a->follower_series($user_id, $range, $tz);
        $this->view->top_posts         = $a->top_posts($user_id, 5);
        $this->view->profile_views     = $a->profile_view_stats($user_id, $range, $tz);
        $this->view->link_clicks       = $a->link_click_stats($user_id, $range, $tz);
        $this->view->traffic = null;   // first-party page traffic for this creator's page (analytics_events), UTC days
        try { $this->view->traffic = (new SiteAnalyticsModel())->creator_traffic($user_id, $range); } catch (\Throwable $e) { error_log('[dashboard] traffic: ' . $e->getMessage()); }
        try { $this->view->tracking_links = (new TrackingLinksModel())->stats($user_id, $start_utc); }
        catch (\Throwable $e) { error_log('[dashboard] tracking_links: ' . $e->getMessage()); $this->view->tracking_links = array(); }   // before the tracking_links sql has run
        $this->view->heatmap           = $a->activity_heatmap($user_id, $tz);
        $this->view->posts_table       = $a->posts_table($user_id, $start_utc);
        $this->view->share_stats       = $a->share_stats($user_id, $start_utc);
        try { $this->view->referred = $a->referred_signups($user_id); }
        catch (\Throwable $e) { error_log('[dashboard] referred_signups: ' . $e->getMessage()); $this->view->referred = array('signups' => 0, 'paid' => 0); }   // before the signup_params sql has run
        $this->view->timezone          = $tz;

        // Behind the upgrade cover a Free account sees sample numbers, not an empty page.
        if (Plan::cover('dashboard')) { foreach (PlanCoverSample::dashboard($range) as $key => $value) { $this->view->$key = $value; } }

        $this->view->render();
    }

}
