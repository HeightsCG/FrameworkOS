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
        $this->view->profile_views     = $a->profile_view_stats($user_id, $range, $tz);
        $this->view->link_clicks       = $a->link_click_stats($user_id, $range, $tz);
        $this->view->heatmap           = $a->activity_heatmap($user_id, $tz);
        $this->view->content_mix       = $a->content_mix($user_id);
        $this->view->posts_table       = $a->posts_table($user_id, $start_utc);
        $this->view->share_stats       = $a->share_stats($user_id, $start_utc);
        $this->view->timezone          = $tz;

        // Upgrade nudge: last 30 days of sales priced on the current plan vs each higher plan.
        $this->view->nudge = null;
        if ($user && !Permissions::is_team_member()) {
            $gross = $a->gross_sales_cents($user_id, gmdate('Y-m-d H:i:s', time() - 30 * 86400));
            $this->view->nudge = Plan::upgrade_savings($user, $gross);
            if ($this->view->nudge) { $this->view->nudge['gross_cents'] = $gross; }
        }

        $this->view->render();
    }

    /**
     * CSV export of the creator's credit ledger (PRD §41 "revenue by date range" —
     * downloadable for accounting). Streams a file download, not a rendered view.
     */
    public function exportAction(){
        if (!Permissions::can_act_as_creator()) { header('Location: /'); exit; }
        $user_id = Permissions::creator_id();
        $rows = (new AnalyticsModel())->export_rows($user_id);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="creator-transactions.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, array('Date (UTC)', 'Type', 'Credits', 'Amount (USD)', 'Balance (credits)', 'Description'), ',', '"', '\\');
        foreach ($rows as $r) {
            $credits = (int) $r['credits'];
            fputcsv($out, array(
                (string) $r['created_at'],
                (string) $r['type'],
                $credits,
                number_format($credits / 10, 2, '.', ''),   // 1 credit = 10 cents
                (int) $r['balance_after'],
                html_entity_decode((string) $r['description'], ENT_QUOTES, 'UTF-8'),
            ), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

}
