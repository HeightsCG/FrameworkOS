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
        $this->view->queue        = $queue;
        $this->view->sales        = $model->recent_sales(25);
        $this->view->refunds      = $refunds->totals();
        $this->view->chargebacks  = $refunds->recent_chargebacks(10);
        $this->view->users        = $model->users('', '', 60);
        $this->view->me           = $me;
        $this->view->timezone     = (string) ($user['content_timezone'] ?? 'UTC');

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
}
