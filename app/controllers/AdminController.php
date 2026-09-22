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
        if (!Permissions::is_admin()) { header('Location: /'); exit; }

        $me   = (int) Session::get('user_id');
        $rows = (new UsersModel())->get_user_by_id($me);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;

        $model = new AdminModel();

        $queue = array();
        foreach ($model->moderation_queue(40) as $a) {
            $queue[] = array(
                'id'             => (int) $a['id'],
                'thumb'          => MediaService::signed_variant($a, 'thumb', 900),
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
        $live = self::plan_subscriptions($model);
        if ($live !== null) {   // Stripe is the source of truth for what creators actually pay
            $this->view->fin['plan_mrr']   = $live['mrr'];
            $this->view->fin['plan_count'] = $live['count'];
            $this->view->fin['plans']      = $live['plans'];
        }
        $plan_inv = self::plan_invoice_buckets($model);
        $this->view->series       = $model->money_series($plan_inv['month']);
        $this->view->plan_all     = $plan_inv['all'];
        $this->view->queue        = $queue;
        $this->view->sales        = $model->recent_sales(25);
        $this->view->refunds      = $refunds->totals();
        $this->view->chargebacks  = $refunds->recent_chargebacks(10);
        $this->view->users        = $model->users('', '', 60);
        $this->view->me           = $me;
        $this->view->timezone     = (string) ($user['content_timezone'] ?? 'UTC');
        $this->view->support      = array(); $this->view->support_open = 0;
        try { $sm = new SupportModel(); $this->view->support = $sm->for_staff('', 300); $this->view->support_open = $sm->count_open(); }
        catch (\Throwable $e) { error_log('[admin] support: ' . $e->getMessage()); }   // /admin still loads if the support tables are missing

        $this->view->seo_keywords = array(); $this->view->seo_review = array(); $this->view->seo_published = array(); $this->view->seo_archived = 0;
        try {   // /admin must still load if the SEO tables aren't there yet
            $art = new SeoArticlesModel();
            $this->view->seo_keywords  = (new SeoKeywordsModel())->all();
            $this->view->seo_review    = $art->by_status(array('review', 'draft'));
            $this->view->seo_published = $art->by_status(array('published'));
            $this->view->seo_archived  = count($art->by_status(array('archived')));
        } catch (\Throwable $e) { error_log('[seo] admin content tab: ' . $e->getMessage()); }

        $this->view->render();
    }

    /** /admin/article/<id> — full-page editor for one article (Content tab → Edit). */
    public function articleAction(){
        if (!Permissions::is_admin()) { header('Location: /'); exit; }
        $url = Main::get_url();
        $a = (new SeoArticlesModel())->get((int) ($url[2] ?? 0));
        if (!$a) { Errors::page_not_found(); return; }
        $this->view->article  = $a;
        $this->view->faq      = (array) json_decode((string) ($a['faq'] ?? '[]'), true);
        $this->view->errors   = SeoDrafter::validate(array('title' => $a['title'], 'slug' => $a['slug'], 'meta_description' => $a['meta_description'], 'body_md' => $a['body_md'], 'faq' => $this->view->faq), (int) $a['id']);
        $this->view->render();
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
        @file_put_contents($cache, json_encode($out), LOCK_EX);
        return $out;
    }

    /**
     * Active creator-plan subscriptions straight from Stripe: monthly recurring total (cents), subscription count,
     * and a count per tier (matched by product name, like PlanTiers). Returns null if Stripe can't be reached,
     * so the page falls back to the local plan_tier data. Cached for 10 minutes.
     */
    private static function plan_subscriptions(AdminModel $model): ?array {
        $cache = sys_get_temp_dir() . '/cls_admin_plan_subs.json';
        if (is_file($cache) && filemtime($cache) > time() - 600) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c)) { return $c; }
        }
        $out = array('mrr' => 0, 'count' => 0, 'plans' => array()); $names = array();
        try {
            $stripe = StripeService::client();
            foreach ($model->plan_customers() as $cu) {
                $subs = $stripe->subscriptions->all(array('customer' => (string) $cu['stripe_customer_id'], 'status' => 'all', 'limit' => 100));
                foreach ($subs->data as $sub) {
                    if (!in_array($sub->status, array('active', 'trialing', 'past_due'), true)) { continue; }
                    foreach ($sub->items->data as $it) {
                        $price = $it->price; $qty = (int) ($it->quantity ?: 1);
                        $amt = (int) $price->unit_amount * $qty;
                        $iv = $price->recurring ? (string) $price->recurring->interval : 'month';
                        $n  = $price->recurring ? max(1, (int) $price->recurring->interval_count) : 1;
                        $monthly = $iv === 'year' ? $amt / (12 * $n) : ($iv === 'week' ? $amt * 52 / 12 / $n : ($iv === 'day' ? $amt * 365 / 12 / $n : $amt / $n));
                        $pid  = is_object($price->product) ? (string) $price->product->id : (string) $price->product;
                        if (!isset($names[$pid])) { try { $names[$pid] = (string) $stripe->products->retrieve($pid)->name; } catch (\Throwable $e) { $names[$pid] = ''; } }
                        $name = $names[$pid];
                        $tier = PlanTiers::match($name);
                        if ($tier === '') { continue; }   // not a creator plan
                        $out['mrr'] += (int) round($monthly);
                        $out['count']++;
                        if (!isset($out['plans'][$tier])) { $out['plans'][$tier] = array('n' => 0, 'mrr' => 0); }
                        $out['plans'][$tier]['n']++;
                        $out['plans'][$tier]['mrr'] += (int) round($monthly);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[admin] plan subscriptions: ' . $e->getMessage());
            return null;
        }
        @file_put_contents($cache, json_encode($out), LOCK_EX);
        return $out;
    }
}
