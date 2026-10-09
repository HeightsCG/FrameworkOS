<?php
/**
 * Platform admin dashboard (/admin), PRD §38. Gated on the is_admin staff flag
 * (independent of role, so the platform owner can also be a Creator). Overview KPIs,
 * a moderation queue for flagged/unscanned images, and a searchable users table with
 * suspend/reactivate. Mutations go through ApiAdminController (admin_*).
 */
class AdminController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        if (!Permissions::is_admin()) { self::bounce(); }

        $me   = (int) Session::get('user_id');
        $rows = (new UsersModel())->get_user_by_id($me);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;

        $model = new AdminModel();

        $queue = array();
        foreach ($model->moderation_queue(40) as $a) {
            $queue[] = array(
                'id'             => (int) $a['id'],
                'thumb'          => MediaService::signed_variant($a, 'thumb', 900),
                'full'           => MediaService::signed_variant($a, 'display', 900) ?: (MediaService::signed_variant($a, 'original', 900) ?: MediaService::signed_variant($a, 'thumb', 900)),
                'creator_id'     => (int) $a['creator_id'],
                'creator_handle' => (string) $a['creator_handle'],
                'creator_name'   => trim((string) $a['creator_name']) !== '' ? (string) $a['creator_name'] : ('@' . $a['creator_handle']),
                'status'         => (string) $a['moderation_status'],
                'score'          => $a['moderation_score'] !== null ? (float) $a['moderation_score'] : null,
                'labels'         => (string) ($a['moderation_labels'] ?? ''),
                'created_at'     => (string) $a['created_at'],
            );
        }

        $refunds = new RefundsModel();
        $reports = new ReportsModel();
        $verifs  = new VerificationsModel();

        $this->view->reports_queue = $reports->open_for_admin(40);
        $this->view->reports_open  = $reports->open_count();
        $this->view->verifications = $verifs->pending_for_admin(40);
        $this->view->verif_pending = $verifs->pending_count();
        $this->view->stats        = $model->overview();
        $plan_prices = array();
        foreach (PagesController::pricing_rows() as $pr) { if ($pr['amount'] !== null) { $plan_prices[$pr['tier']['key']] = (int) $pr['amount']; } }
        $this->view->fin          = $model->financials($plan_prices);
        $live = self::plan_subscriptions();
        if ($live !== null) {   // app billing (billing_accounts) is the source of truth for what creators pay
            $this->view->fin['plan_mrr']   = $live['mrr'];
            $this->view->fin['plan_count'] = $live['count'];
            $this->view->fin['plans']      = $live['plans'];
        }
        $plan_inv = self::plan_invoice_buckets($model);
        $this->view->series       = $model->money_series($plan_inv['month'], self::membership_fee_buckets());
        $this->view->plan_all     = $plan_inv['all'];
        $this->view->queue        = $queue;
        $this->view->billing      = array();
        try { $this->view->billing = (new BillingAccountsModel())->admin_list(300); } catch (\Throwable $e) { error_log('[admin] billing: ' . $e->getMessage()); }
        $this->view->sales        = $model->recent_sales(25);
        $this->view->refunds      = $refunds->totals();
        $this->view->chargebacks  = $refunds->recent_chargebacks(10);
        $this->view->users        = $model->users('', '', 60);
        $this->view->me           = $me;
        $this->view->timezone     = (string) ($user['content_timezone'] ?? 'UTC');
        $this->view->audit        = array();
        try { $this->view->audit = (new AuditModel())->recent(300); } catch (\Throwable $e) { error_log('[admin] audit: ' . $e->getMessage()); }
        $this->view->support      = array(); $this->view->support_open = 0;
        try { $sm = new SupportModel(); $this->view->support = $sm->for_staff('', 300); $this->view->support_open = $sm->count_open(); }
        catch (\Throwable $e) { error_log('[admin] support: ' . $e->getMessage()); }   // /admin still loads if the support tables are missing
        $this->view->leads = array();   // Leads tab: the free tools (/tools/*)
        try { $this->view->leads = (new LeadsModel())->recent('', 500); } catch (\Throwable $e) { error_log('[admin] leads: ' . $e->getMessage()); }

        $this->view->seo_keywords = array(); $this->view->seo_review = array(); $this->view->seo_published = array(); $this->view->seo_archived = 0;
        try {   // /admin must still load if the SEO tables aren't there yet
            $art = new SeoArticlesModel();
            $this->view->seo_keywords  = (new SeoKeywordsModel())->all();
            $this->view->seo_review    = $art->by_status(array('review', 'draft'));
            $this->view->seo_published = $art->by_status(array('published'));
            $this->view->seo_archived  = count($art->by_status(array('archived')));
        } catch (\Throwable $e) { error_log('[seo] admin content tab: ' . $e->getMessage()); }

        $this->view->cron_jobs = CronRuns::status();

        $this->view->growth = array();   // Growth tab: each period switch (7 / 30 / 90 days) is rendered up front
        try { foreach (array(7, 30, 90) as $gd) { $this->view->growth[$gd] = array('days' => $model->funnel($gd), 'sources' => $model->funnel_by_source($gd), 'referred' => $model->referred_signups($gd), 'builders' => $model->free_builders($gd)); } }
        catch (\Throwable $e) { error_log('[admin] growth: ' . $e->getMessage()); }   // /admin still loads before the signup params SQL runs

        $this->view->cross_promo_plans = '';   // Growth tab: which plans may cross-promote (/promote)
        try { $this->view->cross_promo_plans = implode(', ', PromoSwapsModel::plans()); } catch (\Throwable $e) { error_log('[admin] cross promo: ' . $e->getMessage()); }
        $this->view->scenes = array();
        try { $this->view->scenes = (new SceneTemplatesModel())->list_all(); } catch (\Throwable $e) { error_log('[admin] scenes: ' . $e->getMessage()); }   // /admin still loads before the stage 2 SQL runs

        $this->view->niches = array(); $this->view->niche_counts = array();
        try { $nm = new NichesModel(); $this->view->niches = $nm->all(); $this->view->niche_counts = $nm->listed_counts(); }
        catch (\Throwable $e) { error_log('[admin] niches: ' . $e->getMessage()); }   // /admin still loads before the niches SQL runs

        $this->view->founding = array(); $this->view->founding_taken = 0;   // Founding tab (/founding offer)
        try { $fm = new FoundingClaimsModel(); $this->view->founding = $fm->admin_list(300); $this->view->founding_taken = $fm->taken(); }
        catch (\Throwable $e) { error_log('[admin] founding: ' . $e->getMessage()); }   // /admin still loads before the founding SQL runs

        $this->view->affiliates = array(); $this->view->aff_ledger = array(); $this->view->aff_payouts = array();   // Affiliates tab (/affiliates program)
        try { $this->view->affiliates = (new AffiliatesModel())->admin_list(300); $this->view->aff_ledger = (new AffiliateCommissionsModel())->ledger(300); $this->view->aff_payouts = (new AffiliatePayoutsModel())->admin_list(200); $this->view->aff_owed = (new AffiliateCommissionsModel())->owed_cents(); }
        catch (\Throwable $e) { error_log('[admin] affiliates: ' . $e->getMessage()); }   // /admin still loads before the affiliates SQL runs

        $this->view->render();
    }

    /** /admin/article/<id> — full-page editor for one article (Content tab → Edit). */
    public function articleAction(){
        if (!Permissions::is_admin()) { self::bounce(); }
        $url = Main::get_url();
        $a = (new SeoArticlesModel())->get((int) ($url[2] ?? 0));
        if (!$a) { Errors::page_not_found(); return; }
        $this->view->article  = $a;
        $this->view->faq      = (array) json_decode((string) ($a['faq'] ?? '[]'), true);
        $this->view->errors   = SeoDrafter::validate(array('title' => $a['title'], 'slug' => $a['slug'], 'meta_description' => $a['meta_description'], 'excerpt' => (string) $a['excerpt'], 'body_md' => $a['body_md'], 'faq' => $this->view->faq, 'cluster' => (string) $a['cluster'], 'intent' => SeoDrafter::intent_for((string) $a['target_keyword'], (string) $a['cluster'])), (int) $a['id']);
        $this->view->render();
    }

    /** /admin/user/<id>: one account, with the tools to fix a user's problem (see ApiAdminController admin_* actions). */
    public function userAction(){
        if (!Permissions::is_admin()) { self::bounce(); }
        $url = Main::get_url();
        $model = new AdminModel();
        $u = $model->user_detail((int) ($url[2] ?? 0));
        if (!$u) { Errors::page_not_found(); return; }
        $uid = (int) $u['user_id'];
        $me_rows = (new UsersModel())->get_user_by_id((int) Session::get('user_id'));
        $tz = (is_array($me_rows) && count($me_rows) === 1) ? (string) ($me_rows[0]['content_timezone'] ?? 'UTC') : 'UTC';
        $this->view->u            = $u;
        $this->view->age_verification = AgeVerification::record($uid);   // status, provider, dates only (never documents)
        $this->view->timezone     = $tz;
        $this->view->purchases    = $model->purchases_for($uid);
        $this->view->refunds      = $model->refunds_for($uid);
        $this->view->memberships  = (array) (new CreatorSubscriptionsModel())->get_for_subscriber($uid);
        $this->view->credit_tx    = (array) (new CreditsModel())->get_transactions($uid, 50);
        $this->view->ai_tx        = (array) (new AiCreditsModel())->get_transactions($uid, 50);
        $this->view->sign_ins     = $model->sign_in_history((string) $u['u_name'], (string) $u['user_email']);
        $this->view->tickets      = array();
        try { $this->view->tickets = (new SupportModel())->for_user($uid); } catch (\Throwable $e) { error_log('[admin] user tickets: ' . $e->getMessage()); }
        $this->view->is_me        = $uid === (int) Session::get('user_id');
        $this->view->audit        = array();
        try { $this->view->audit = (new AuditModel())->recent(200, $uid); } catch (\Throwable $e) { error_log('[admin] user audit: ' . $e->getMessage()); }
        $this->view->activity     = self::activity_feed($this->view->credit_tx, $this->view->purchases, $this->view->sign_ins, $this->view->tickets, $this->view->refunds);
        $this->view->render();
    }

    /** Newest-first mix of a user's credit movements, purchases, refunds, sign-ins and support requests (for the Overview timeline). */
    public static function activity_feed(array $tx, array $purchases, array $sign_ins, array $tickets, array $refunds = array(), int $limit = 14): array {
        $out = array();
        $labels = array('purchase' => 'Bought credits', 'payout' => 'Cashed out', 'admin_adjust' => 'Balance adjusted by support', 'refund' => 'Refund received', 'refund_reversal' => 'Refund reversed',
                        'ppv_unlock' => 'Unlocked a post', 'bundle_unlock' => 'Bought a bundle', 'message_unlock' => 'Unlocked a message', 'service_purchase' => 'Bought a service', 'event_ticket' => 'Bought an event ticket', 'live_tip' => 'Sent a tip', 'replay_unlock' => 'Bought a replay',
                        'ppv_earning' => 'Earned from a post', 'bundle_earning' => 'Earned from a bundle', 'message_earning' => 'Earned from a message', 'service_earning' => 'Earned from a service', 'event_earning' => 'Earned from an event', 'tip_earning' => 'Tip received', 'replay_earning' => 'Sold a replay');
        foreach ($tx as $t) {
            $c = (int) $t['credits'];
            $out[] = array('at' => $t['created_at'], 'icon' => $c >= 0 ? 'fa-arrow-down' : 'fa-arrow-up', 'tone' => $c >= 0 ? 'pos' : 'neg',
                'title' => $labels[$t['type']] ?? ucwords(str_replace('_', ' ', (string) $t['type'])), 'detail' => ($c > 0 ? '+' : '') . number_format($c) . ' credits', 'sub' => (string) $t['description']);
        }
        foreach ($sign_ins as $si) {
            $map = array('login' => 'Sign-in attempt', 'forgot' => 'Requested a password reset', 'mfa' => 'Entered a two-step code');
            $out[] = array('at' => $si['created_at'], 'icon' => 'fa-right-to-bracket', 'tone' => 'muted', 'title' => $map[$si['action']] ?? $si['action'], 'detail' => (string) $si['ip_address'], 'sub' => '');
        }
        foreach ($tickets as $tk) {
            $out[] = array('at' => $tk['created_at'], 'icon' => 'fa-life-ring', 'tone' => 'violet', 'title' => 'Opened a support request', 'detail' => '', 'sub' => (string) $tk['subject'], 'link' => '/support/ticket/' . (int) $tk['id']);
        }
        foreach ($refunds as $rf) {
            $out[] = array('at' => $rf['created_at'], 'icon' => 'fa-rotate-left', 'tone' => 'warn', 'title' => 'Refund issued by support', 'detail' => number_format((int) $rf['amount_credits']) . ' credits', 'sub' => (string) $rf['reason']);
        }
        usort($out, function ($a, $b) { return strcmp((string) $b['at'], (string) $a['at']); });
        return array_slice($out, 0, $limit);
    }

    /**
     * Paid creator-plan invoices from Stripe, bucketed by month and day (cents), plus the all-time total.
     * Cached for 10 minutes so the admin page does not call Stripe for every creator on every load.
     */
    private static function plan_invoice_buckets(AdminModel $model): array {
        $cache = sys_get_temp_dir() . '/cls_admin_plan_invoices.json';
        if (is_file($cache) && filemtime($cache) > time() - 600) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c)) { return $c; }
        }
        $out = array('month' => array(), 'day' => array(), 'all' => 0);
        foreach ($model->plan_customers() as $cu) {
            foreach (StripeService::get_invoices((string) $cu['stripe_customer_id'], 100) as $inv) {
                if ($inv['status'] !== 'paid' || (int) $inv['amount'] <= 0) { continue; }
                $m = gmdate('Y-m', (int) $inv['created']); $d = gmdate('Y-m-d', (int) $inv['created']);
                $out['month'][$m] = ($out['month'][$m] ?? 0) + (int) $inv['amount'];
                $out['day'][$d]   = ($out['day'][$d] ?? 0) + (int) $inv['amount'];
                $out['all'] += (int) $inv['amount'];
            }
        }
        // App-managed charges (plans, extra AI influencers, monthly credit packs) since billing moved off Stripe subscriptions.
        foreach ((new BillingChargesModel())->revenue_buckets() as $k => $v) {
            if ($k === 'all') { $out['all'] += (int) $v; continue; }
            foreach ((array) $v as $key => $n) { $out[$k][$key] = ($out[$k][$key] ?? 0) + (int) $n; }
        }
        @file_put_contents($cache, json_encode($out), LOCK_EX);
        return $out;
    }

    /** Our fee on fan memberships by month (Stripe application fees), cached for 10 minutes like the plan invoices. */
    private static function membership_fee_buckets(): array {
        $cache = sys_get_temp_dir() . '/cls_admin_membership_fees.json';
        if (is_file($cache) && filemtime($cache) > time() - 600) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c)) { return (array) ($c['month'] ?? array()); }
        }
        $out = StripeService::application_fee_buckets(strtotime('first day of -23 months 00:00 UTC'));
        @file_put_contents($cache, json_encode($out), LOCK_EX);
        return $out['month'];
    }

    /**
     * Paid creator plans from app billing: monthly recurring total (cents: plan + extra AI influencers
     * + monthly credit packs), account count, and count/MRR per plan.
     */
    private static function plan_subscriptions(): ?array {
        try {
            $out = array('mrr' => 0, 'count' => 0, 'plans' => array());
            foreach ((new BillingAccountsModel())->recurring_summary() as $r) {
                $key = (string) $r['plan_key'];
                $mrr = ($key !== 'free' ? BillingService::plan_cents($key) * (int) $r['n'] : 0) + (int) $r['slots'] * BillingService::slot_cents() + (int) $r['pack_cents'];
                $out['mrr'] += $mrr;
                if ($key === 'free') { continue; }
                $out['count'] += (int) $r['n'];
                $out['plans'][$key] = array('n' => (int) $r['n'], 'mrr' => $mrr);
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[admin] plan subscriptions: ' . $e->getMessage());
            return null;
        }
    }
}
