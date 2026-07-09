<?php
class IndexController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        $user_id     = (int) Session::get('user_id');
        $is_creator  = Permissions::has_role('Creator');
        $this->view->is_creator = $is_creator;
        $this->view->display_name = '';

        $user = null;
        if ($user_id > 0) {
            $rows = (new UsersModel())->get_user_by_id($user_id);
            $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
            if ($user) {
                $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $this->view->display_name = $name !== '' ? $name : ('@' . ($user['u_name'] ?? ''));
            }
        }

        if ($is_creator && $user_id > 0) {
            $a = new AnalyticsModel();
            $this->view->stats          = $a->overview($user_id);
            $this->view->views_series   = $a->views_series($user_id, 30);
            $this->view->top_posts      = $a->top_posts($user_id, 5);
            $this->view->recent_unlocks = $a->recent_unlocks($user_id, 8);
            $this->view->timezone       = (string) ($user['content_timezone'] ?? 'UTC');
        }

        $this->view->render();
    }

}
