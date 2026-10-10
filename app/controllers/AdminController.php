<?php
/**
 * Platform admin (/admin), PRD §38. Gated on the is_admin staff flag (independent of role, so the platform
 * owner can also be a Creator). One page per job, all sharing the admin rail (app/views/admin/_shell.php):
 *
 *   /admin             Today: key numbers, what needs a person, one activity table (signups, sales, payouts, staff actions)
 *   /admin/moderation  moderation, reports, verification + age checks, support, past-due billing (?show=<section>)
 *   /admin/users       accounts                      /admin/user/<id>  one account (single scroll)
 *   /admin/financials  ledgers and the revenue chart  /admin/sales  sales + chargebacks   /admin/billing  plan billing
 *   /admin/growth      funnel; /leads /founding /affiliates sit under the same sidebar item
 *   /admin/content     articles; /scenes /niches under the same item; /admin/article/<id> the editor
 *   /admin/system      audit log; /jobs under the same item
 *
 * The sections are a tab row at the top of every admin page (app/views/admin/_shell.php); the app's own left menu
 * stays. The old /admin?tab=<x> URLs and the first-redesign URLs (/people, /money, /articles, /audit) redirect
 * (TAB_ROUTES, PATH_ROUTES).
 * Mutations go through ApiAdminController (admin_*).
 */
class AdminController extends Controller {

    public $protected = 1;

    /** Old tab name → new URL. */
    const TAB_ROUTES = array(
        'financials' => '/admin/financials', 'sales' => '/admin/sales', 'billing' => '/admin/billing',
        'moderation' => '/admin/moderation?show=moderation', 'reports' => '/admin/moderation?show=reports',
        'verification' => '/admin/moderation?show=verification', 'support' => '/admin/moderation?show=support',
        'users' => '/admin/users', 'growth' => '/admin/growth', 'leads' => '/admin/leads', 'founding' => '/admin/founding',
        'affiliates' => '/admin/affiliates', 'content' => '/admin/content', 'scenes' => '/admin/scenes', 'niches' => '/admin/niches',
        'audit' => '/admin/system', 'jobs' => '/admin/jobs',
    );
    /** First-redesign paths that moved. */
    const PATH_ROUTES = array('people' => '/admin/users', 'money' => '/admin/financials', 'articles' => '/admin/content', 'audit' => '/admin/system');

    /** Sidebar item → the pages it covers (the page key the shell highlights). */
    const SECTIONS = array(
        'today' => array('today'), 'moderation' => array('moderation'), 'users' => array('users'), 'financials' => array('financials'),
        'sales' => array('sales'), 'billing' => array('billing'),
        'growth' => array('growth', 'leads', 'founding', 'affiliates'), 'content' => array('content', 'scenes', 'niches'), 'system' => array('system', 'jobs'),
    );

    public function __construct(){
        parent::__construct();
    }

    /* ------------------------------------------------------------------ shell ------------------------------------------------------------------ */

    /** Every admin page: the gate, the viewer's time zone, and the rail counts. $page is the rail item to highlight. */
    private function shell($page){
        if (!Permissions::is_admin()) { self::bounce(); }
        $me   = (int) Session::get('user_id');
        $rows = (new UsersModel())->get_user_by_id($me);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        $this->view->me       = $me;
        $this->view->timezone = (string) ($user['content_timezone'] ?? 'UTC');
        $this->view->page     = (string) $page;
        $this->view->section  = 'today';
        foreach (self::SECTIONS as $sec => $pages) { if (in_array((string) $page, $pages, true)) { $this->view->section = $sec; } }
        $this->view->nav      = self::rail_counts();
    }

    /** What is waiting on staff, per group, for the rail badges and the queue chips. Every source is optional (a missing table never breaks /admin). */
    public static function rail_counts(): array {
        $n = array('moderation' => 0, 'reports' => 0, 'verification' => 0, 'age' => 0, 'support' => 0, 'billing' => 0, 'articles' => 0, 'affiliates' => 0);
        $try = function ($key, $fn) use (&$n) { try { $n[$key] = (int) $fn(); } catch (\Throwable $e) { error_log('[admin] count ' . $key . ': ' . $e->getMessage()); } };
        $try('moderation',   function () { return (new AdminModel())->moderation_count(); });
        $try('reports',      function () { return (new ReportsModel())->open_count(); });
        $try('verification', function () { return (new VerificationsModel())->pending_count(); });
        $try('age',          function () { $c = (new AgeVerificationsModel())->counts(); return $c['pending']; });
        $try('support',      function () { return (new SupportModel())->count_open(); });
        $try('billing',      function () { return (new BillingAccountsModel())->past_due_count(); });
        $try('articles',     function () { return count((new SeoArticlesModel())->by_status(array('review', 'draft'))); });
        $try('affiliates',   function () { return (new AffiliatesModel())->pending_count() + (new AffiliatePayoutsModel())->requested_count(); });
        $n['queue'] = $n['moderation'] + $n['reports'] + $n['verification'] + $n['age'] + $n['support'] + $n['billing'];   // the Moderation tab's pill
        return $n;
    }

    /* ------------------------------------------------------------------ pages ------------------------------------------------------------------ */

    /** /admin — the daily operating view. /admin?tab=<old tab> redirects to the page that replaced it. */
    public function indexAction(){
        $tab = preg_replace('/[^a-z]/', '', (string) ($_GET['tab'] ?? ''));
        if ($tab !== '' && isset(self::TAB_ROUTES[$tab])) { header('Location: ' . self::TAB_ROUTES[$tab], true, 302); exit; }
        $this->shell('today');
        $model = new AdminModel();
        $this->view->stats = $model->overview();
        $fin = self::financials($model);
        $this->view->fin    = $fin['fin'];
        $this->view->series = $fin['series'];
        $this->view->signups = array();
        try { $this->view->signups = $model->funnel(7); } catch (\Throwable $e) { error_log('[admin] today funnel: ' . $e->getMessage()); }
        $this->view->daily = array();
        try { $this->view->daily = $model->daily_series(60, $fin['plan_days']); } catch (\Throwable $e) { error_log('[admin] daily series: ' . $e->getMessage()); }
        $jobs_bad = array();
        foreach (CronRuns::status() as $j) { if (($j['finished_at'] !== '' && !$j['ok']) || $j['stale']) { $jobs_bad[] = $j; } }
        $this->view->jobs_bad = $jobs_bad;
        $this->view->activity = self::platform_activity($model, 60);
        $this->view->render();
    }

    /** Signups, sales, payouts and staff actions in one newest-first list (the Today page's Activity table). */
    public static function platform_activity(AdminModel $model, int $limit = 60): array {
        $out = array();
        foreach ($model->users('', '', 30) as $u) {
            $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); $name = $name !== '' ? $name : '@' . $u['u_name'];
            $out[] = array('at' => $u['created_at'], 'type' => 'signup', 'label' => 'Signup', 'who' => $name, 'who_id' => (int) $u['user_id'], 'handle' => (string) $u['u_name'], 'avatar' => (string) ($u['avatar_url'] ?? ''),
                           'what' => ($u['role_name'] ?: 'User') . ' account', 'amount' => '', 'href' => '/admin/user/' . (int) $u['user_id']);
        }
        foreach ($model->recent_sales(30) as $sl) {
            $kind = AdminModel::SALE_KINDS[$sl['kind']] ?? ucfirst($sl['kind']);
            $out[] = array('at' => $sl['created_at'], 'type' => 'sale', 'label' => 'Sale', 'who' => $sl['fan_name'] !== '' ? $sl['fan_name'] : '@' . $sl['fan_handle'], 'who_id' => (int) $sl['fan_id'], 'handle' => (string) $sl['fan_handle'], 'avatar' => (string) $sl['fan_avatar'],
                           'what' => $kind . ($sl['item'] !== '' ? ' · ' . mb_substr($sl['item'], 0, 60) : '') . ($sl['creator_handle'] !== '' ? ' · from @' . $sl['creator_handle'] : ''),
                           'amount' => '$' . number_format(((int) $sl['credits']) / 10, 2), 'href' => '/admin/user/' . (int) $sl['fan_id']);
        }
        try {
            foreach ((new PayoutsModel())->recent_for_admin(30) as $po) {
                $st = (string) $po['status'];
                $out[] = array('at' => $po['created_at'], 'type' => 'payout', 'label' => 'Payout', 'who' => '@' . $po['u_name'], 'who_id' => (int) $po['creator_id'], 'handle' => (string) $po['u_name'], 'avatar' => '',
                               'what' => ucfirst($st) . ((string) $po['failure_message'] !== '' ? ' · ' . $po['failure_message'] : ''),
                               'amount' => '$' . number_format(((int) $po['amount_cents']) / 100, 2), 'href' => '/admin/user/' . (int) $po['creator_id']);
            }
        } catch (\Throwable $e) { error_log('[admin] activity payouts: ' . $e->getMessage()); }
        try {
            foreach ((new AuditModel())->recent(30) as $ar) {
                $admin = $ar['admin_name'] !== '' ? $ar['admin_name'] : '@' . $ar['admin_handle'];
                $target = $ar['target_user_id'] ? ($ar['target_name'] !== '' ? $ar['target_name'] : '@' . $ar['target_handle']) : '';
                $d = json_decode((string) $ar['details'], true) ?: array();
                $out[] = array('at' => $ar['created_at'], 'type' => 'staff', 'label' => 'Staff', 'who' => $admin, 'who_id' => (int) $ar['admin_id'], 'handle' => (string) $ar['admin_handle'], 'avatar' => '',
                               'what' => (AuditModel::LABELS[$ar['action']] ?? $ar['action']) . ($target !== '' ? ' · ' . $target : '') . (!empty($d['result']) ? ' · ' . $d['result'] : ''),
                               'amount' => '', 'href' => $ar['target_user_id'] ? '/admin/user/' . (int) $ar['target_user_id'] : '/admin/system');
            }
        } catch (\Throwable $e) { error_log('[admin] activity audit: ' . $e->getMessage()); }
        usort($out, function ($a, $b) { return strcmp((string) $b['at'], (string) $a['at']); });
        return array_slice($out, 0, $limit);
    }

    /** /admin/moderation — moderation, reports, verification + age checks, support and past-due billing. ?show=<section> opens one. */
    public function moderationAction(){
        $this->shell('moderation');
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
        $this->view->queue         = $queue;
        $this->view->show          = preg_replace('/[^a-z]/', '', (string) ($_GET['show'] ?? ''));
        $this->view->reports_queue = (new ReportsModel())->open_for_admin(40);
        $this->view->verifications = (new VerificationsModel())->pending_for_admin(40);
        $this->view->age_rows = array(); $this->view->age_counts = array('pending' => 0, 'verified' => 0, 'failed' => 0);
        try { $am = new AgeVerificationsModel(); $this->view->age_rows = $am->recent(200); $this->view->age_counts = $am->counts(); }
        catch (\Throwable $e) { error_log('[admin] age checks: ' . $e->getMessage()); }
        $this->view->support = array();
        try { $this->view->support = (new SupportModel())->for_staff('', 300); } catch (\Throwable $e) { error_log('[admin] support: ' . $e->getMessage()); }
        $this->view->billing = array();
        try { foreach ((new BillingAccountsModel())->admin_list(300) as $b) { if ((string) $b['status'] === 'past_due') { $this->view->billing[] = $b; } } }
        catch (\Throwable $e) { error_log('[admin] billing: ' . $e->getMessage()); }
        $this->view->render();
    }

    /** /admin/users — accounts, newest first; ?q= searches name, handle or email on the server. */
    public function usersAction(){
        $this->shell('users');
        $q = trim((string) ($_GET['q'] ?? ''));
        $this->view->q     = mb_substr($q, 0, 80);
        $this->view->users = (new AdminModel())->users($this->view->q, '', $q !== '' ? 200 : 100);
        $this->view->render();
    }

    /** /admin/financials — the ledgers: period numbers, revenue by month, sales by type, monthly breakdown. */
    public function financialsAction(){
        $this->shell('financials');
        $fin = self::financials(new AdminModel());
        $this->view->fin      = $fin['fin'];
        $this->view->series   = $fin['series'];
        $this->view->plan_all = $fin['plan_all'];
        $this->view->daily    = array();
        try { $this->view->daily = (new AdminModel())->daily_series(30, $fin['plan_days']); } catch (\Throwable $e) { error_log('[admin] daily series: ' . $e->getMessage()); }
        $this->view->render();
    }

    /** /admin/sales — recent sales (refundable) and chargebacks. */
    public function salesAction(){
        $this->shell('sales');
        $model = new AdminModel(); $refunds = new RefundsModel();
        $this->view->sales       = $model->recent_sales(200);
        $this->view->refunds     = $refunds->totals();
        $this->view->chargebacks = $refunds->recent_chargebacks(50);
        $this->view->render();
    }

    /** /admin/billing — every paid plan and monthly pack, past due first. */
    public function billingAction(){
        $this->shell('billing');
        $this->view->billing = array();
        try { $this->view->billing = (new BillingAccountsModel())->admin_list(300); } catch (\Throwable $e) { error_log('[admin] billing: ' . $e->getMessage()); }
        $this->view->render();
    }

    /** Moved first-redesign paths. */
    public function peopleAction(){ header('Location: /admin/users' . ((string) ($_GET['q'] ?? '') !== '' ? '?q=' . rawurlencode((string) $_GET['q']) : ''), true, 302); exit; }
    public function moneyAction(){ header('Location: /admin/financials', true, 302); exit; }
    public function articlesAction(){ header('Location: /admin/content', true, 302); exit; }
    public function auditAction(){ header('Location: /admin/system', true, 302); exit; }

    /** /admin/growth — the signup funnel by day and by source, referred signups, free creators building, cross-promotion setting. */
    public function growthAction(){
        $this->shell('growth');
        $model = new AdminModel();
        $this->view->growth = array();
        try { foreach (array(7, 30, 90) as $gd) { $this->view->growth[$gd] = array('days' => $model->funnel($gd), 'sources' => $model->funnel_by_source($gd), 'referred' => $model->referred_signups($gd), 'builders' => $model->free_builders($gd)); } }
        catch (\Throwable $e) { error_log('[admin] growth: ' . $e->getMessage()); }
        $this->view->cross_promo_plans = '';
        try { $this->view->cross_promo_plans = implode(', ', PromoSwapsModel::plans()); } catch (\Throwable $e) { error_log('[admin] cross promo: ' . $e->getMessage()); }
        $this->view->render();
    }

    /** /admin/leads — people who used a free tool (/tools/*). */
    public function leadsAction(){
        $this->shell('leads');
        $this->view->leads = array();
        try { $this->view->leads = (new LeadsModel())->recent('', 500); } catch (\Throwable $e) { error_log('[admin] leads: ' . $e->getMessage()); }
        $this->view->render();
    }

    /** /admin/founding — the /founding offer's claims. */
    public function foundingAction(){
        $this->shell('founding');
        $this->view->founding = array(); $this->view->founding_taken = 0;
        try { $fm = new FoundingClaimsModel(); $this->view->founding = $fm->admin_list(300); $this->view->founding_taken = $fm->taken(); }
        catch (\Throwable $e) { error_log('[admin] founding: ' . $e->getMessage()); }
        $this->view->render();
    }

    /** /admin/affiliates — applications, the commissions ledger, payout requests. */
    public function affiliatesAction(){
        $this->shell('affiliates');
        $this->view->affiliates = array(); $this->view->aff_ledger = array(); $this->view->aff_payouts = array(); $this->view->aff_owed = 0;
        try { $this->view->affiliates = (new AffiliatesModel())->admin_list(300); $this->view->aff_ledger = (new AffiliateCommissionsModel())->ledger(300); $this->view->aff_payouts = (new AffiliatePayoutsModel())->admin_list(200); $this->view->aff_owed = (new AffiliateCommissionsModel())->owed_cents(); }
        catch (\Throwable $e) { error_log('[admin] affiliates: ' . $e->getMessage()); }
        $this->view->render();
    }

    /** /admin/content — the blog engine: published, keyword queue, unpublished. */
    public function contentAction(){
        $this->shell('content');
        $this->view->seo_keywords = array(); $this->view->seo_review = array(); $this->view->seo_published = array(); $this->view->seo_archived = 0;
        try {
            $art = new SeoArticlesModel();
            $this->view->seo_keywords  = (new SeoKeywordsModel())->all();
            $this->view->seo_review    = $art->by_status(array('review', 'draft'));
            $this->view->seo_published = $art->by_status(array('published'));
            $this->view->seo_archived  = count($art->by_status(array('archived')));
        } catch (\Throwable $e) { error_log('[seo] admin articles: ' . $e->getMessage()); }
        $this->view->render();
    }

    /** /admin/scenes — scene templates creators generate from. */
    public function scenesAction(){
        $this->shell('scenes');
        $this->view->scenes = array();
        try { $this->view->scenes = (new SceneTemplatesModel())->list_all(); } catch (\Throwable $e) { error_log('[admin] scenes: ' . $e->getMessage()); }
        $this->view->render();
    }

    /** /admin/niches — the Creator Directory categories. */
    public function nichesAction(){
        $this->shell('niches');
        $this->view->niches = array(); $this->view->niche_counts = array();
        try { $nm = new NichesModel(); $this->view->niches = $nm->all(); $this->view->niche_counts = $nm->listed_counts(); }
        catch (\Throwable $e) { error_log('[admin] niches: ' . $e->getMessage()); }
        $this->view->render();
    }

    /** /admin/system — every staff action, newest first. */
    public function systemAction(){
        $this->shell('system');
        $this->view->audit = array();
        try { $this->view->audit = (new AuditModel())->recent(300); } catch (\Throwable $e) { error_log('[admin] audit: ' . $e->getMessage()); }
        $this->view->render();
    }

    /** /admin/jobs — the last run of every scheduled job. */
    public function jobsAction(){
        $this->shell('jobs');
        $this->view->cron_jobs = CronRuns::status();
        $this->view->render();
    }

    /** /admin/article/<id> — full-page editor for one article (Content → Edit). */
    public function articleAction(){
        $this->shell('content');
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
        $this->shell('users');
        $url = Main::get_url();
        $model = new AdminModel();
        $u = $model->user_detail((int) ($url[2] ?? 0));
        if (!$u) { Errors::page_not_found(); return; }
        $uid = (int) $u['user_id'];
        $this->view->u            = $u;
        $this->view->age_verification = AgeVerification::record($uid);   // status, provider, dates only (never documents)
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

    /* ------------------------------------------------------------------ helpers ------------------------------------------------------------------ */

    /** Financial totals + the 24-month series, with app billing as the source of truth for plan MRR. */
    private static function financials(AdminModel $model): array {
        $plan_prices = array();
        foreach (PagesController::pricing_rows() as $pr) { if ($pr['amount'] !== null) { $plan_prices[$pr['tier']['key']] = (int) $pr['amount']; } }
        $fin  = $model->financials($plan_prices);
        $live = self::plan_subscriptions();
        if ($live !== null) {   // app billing (billing_accounts) is the source of truth for what creators pay
            $fin['plan_mrr']   = $live['mrr'];
            $fin['plan_count'] = $live['count'];
            $fin['plan_comped'] = $live['comped'];
            $fin['plans']      = $live['plans'];
        }
        $plan_inv = self::plan_invoice_buckets($model);
        return array('fin' => $fin, 'series' => $model->money_series($plan_inv['month'], self::membership_fee_buckets()), 'plan_all' => $plan_inv['all'], 'plan_days' => (array) ($plan_inv['day'] ?? array()));
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
     * Paid creator plans from app billing: monthly recurring total (cents) and, per plan, how many accounts pay and
     * how many are on a free code. MRR is what each account's next renewal actually charges (plan + extra AI
     * influencers + monthly credit pack, minus its promo), so a 100% code counts as $0 and a canceling plan as $0.
     */
    private static function plan_subscriptions(): ?array {
        try {
            $out = array('mrr' => 0, 'count' => 0, 'comped' => 0, 'plans' => array());
            foreach ((new BillingAccountsModel())->admin_list(1000) as $b) {
                if (!in_array((string) $b['status'], array('active', 'past_due'), true)) { continue; }
                $key = (string) $b['plan_key'];
                if ($key === BillingService::PLAN_FREE) { continue; }
                $mrr = self::account_mrr($b);
                if (!isset($out['plans'][$key])) { $out['plans'][$key] = array('n' => 0, 'comped' => 0, 'mrr' => 0); }
                $out['mrr'] += $mrr;
                $out['plans'][$key]['mrr'] += $mrr;
                if ($mrr > 0) { $out['count']++; $out['plans'][$key]['n']++; } else { $out['comped']++; $out['plans'][$key]['comped']++; }
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[admin] plan subscriptions: ' . $e->getMessage());
            return null;
        }
    }

    /** What one billing account adds to MRR (cents): its next renewal total after promo, never below zero; nothing if nothing renews. */
    public static function account_mrr(array $acct): int {
        $nx = BillingService::next_charge($acct);
        return $nx ? max(0, (int) $nx['total']) : 0;
    }
}
