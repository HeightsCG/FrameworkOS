<?php
/**
 * Ideas (/ideas): the Fan Question Finder inside the app. A creator enters a niche, the run is queued (ToolRunJob) and
 * the page polls /api/tool_run_status for the themed ideas; past runs are listed below, and Draft Post opens the Studio
 * composer with an idea as the caption (/studio?compose=<run_id>:<idea_index>). Running needs a paid plan (the API
 * answers need_plan on Free); collaborators work on the owner's runs.
 */
class IdeasController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        if (!Permissions::can_act_as_creator()) { self::bounce(); }
        $creator_id = Permissions::creator_id();
        $runs = array();
        foreach ((new LeadsModel())->runs_for($creator_id, 50) as $r) {
            $res = (array) json_decode((string) ($r['result'] ?? ''), true);
            $n = 0; foreach ((array) ($res['themes'] ?? array()) as $t) { $n += count((array) ($t['ideas'] ?? array())); }
            $runs[] = array('id' => (int) $r['id'], 'niche' => (string) $r['niche'], 'status' => LeadsModel::shown_status($r), 'ideas' => $n, 'created_at' => (string) $r['created_at']);
        }
        $rows = (new UsersModel())->get_user_by_id($creator_id);
        $this->view->timezone = (is_array($rows) && count($rows) === 1 && (string) ($rows[0]['content_timezone'] ?? '') !== '') ? (string) $rows[0]['content_timezone'] : 'UTC';
        $this->view->runs = $runs;
        $this->view->render();
    }
}
