<?php
/**
 * Creator audience / CRM (/audience). Creator-only; non-creators are redirected home.
 * Lists everyone connected to the creator with relationship badges, spend, tags and
 * notes. Tag/note mutations and messaging happen via ApiController + the messenger widget.
 */
class AudienceController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        if (!Permissions::can_act_as_creator()) { header('Location: /'); exit; }
        $user_id = Permissions::creator_id();   // owner account for collaborators, self otherwise

        $rows = (new UsersModel())->get_user_by_id($user_id);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;

        $model    = new AudienceModel();
        $audience = $model->list_for_creator($user_id);

        $this->view->audience = $audience;
        $this->view->counts   = $model->segment_counts($audience);
        $this->view->timezone = (string) ($user['content_timezone'] ?? 'UTC');
        $this->view->render();
    }
}
