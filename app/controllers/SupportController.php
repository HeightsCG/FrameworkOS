<?php
/**
 * Support / help desk for every signed-in user (/support, /support/ticket/<id>).
 * Users see only their own requests; staff (is_admin) can open any request and answer it.
 * Staff work the queue from the Support tab in /admin. Data: SupportModel.
 */
class SupportController extends Controller {

    public $protected = 1;

    public function __construct(){ parent::__construct(); }

    /** UTC → the viewer's time zone, e.g. "Sep 21, 2026 7:40 PM". */
    private function local_fmt(){
        $rows = (new UsersModel())->get_user_by_id((int) Session::get('user_id'));
        $tz = (is_array($rows) && count($rows) === 1) ? (string) ($rows[0]['content_timezone'] ?? 'UTC') : 'UTC';
        return function ($utc) use ($tz) {
            if ((string) $utc === '') { return ''; }
            try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz ?: 'UTC')); return $d->format('M j, Y g:i A'); }
            catch (\Throwable $e) { return (string) $utc; }
        };
    }

    public function indexAction(){
        $me = (int) Session::get('user_id');
        $this->view->tickets    = (new SupportModel())->for_user($me);
        $this->view->categories = SupportModel::CATEGORIES;
        $this->view->fmt        = $this->local_fmt();
        $this->view->is_staff   = Permissions::is_admin();
        $this->view->render();
    }

    public function ticketAction(){
        $url = Main::get_url();
        $id  = (int) ($url[2] ?? 0);
        $me  = (int) Session::get('user_id');
        $model = new SupportModel();
        $t = $id > 0 ? $model->get($id) : null;
        $staff = Permissions::is_admin();
        if (!$t || ((int) $t['user_id'] !== $me && !$staff)) { header('Location: /support'); exit; }
        $this->view->ticket     = $t;
        $this->view->messages   = $model->messages($id);
        $this->view->categories = SupportModel::CATEGORIES;
        $this->view->fmt        = $this->local_fmt();
        $this->view->staff_view = $staff && (int) $t['user_id'] !== $me;   // answering someone else's request
        $this->view->me         = $me;
        $this->view->requester  = null;
        if ($this->view->staff_view) {   // staff answering: show who this is and the tools to fix it, beside the conversation
            $am = new AdminModel();
            $uid = (int) $t['user_id'];
            $this->view->requester  = $am->user_detail($uid);
            $this->view->purchases  = $am->purchases_for($uid, 50);
            $this->view->memberships = array_values(array_filter((array) (new CreatorSubscriptionsModel())->get_for_subscriber($uid), function ($m) { return $m['status'] === 'active'; }));
            $this->view->others     = array_values(array_filter($model->for_user($uid), function ($x) use ($id) { return (int) $x['id'] !== $id; }));
            $this->view->diagnosis  = SupportDiagnosis::checks($t['category'], $this->view->requester, $am, $this->view->purchases, $this->view->memberships, $this->local_fmt());
        }
        $this->view->render();
    }
}
