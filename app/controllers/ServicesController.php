<?php
/**
 * Creator service management (/services), PRD §22. Services are a monetization
 * offering, so management is Manager+ (require_creator('manage') gates the writes).
 * Collaborators operate on the owner's account via Permissions::creator_id().
 */
class ServicesController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        if (!Permissions::can_act_as_creator() || !Permissions::team_allows('manage')) { header('Location: /'); exit; }
        $creator_id = Permissions::creator_id();

        $this->view->services = (new ServicesModel())->list_for_creator($creator_id);
        $this->view->render();
    }

    /** One service's page: /services/manage/<id> — sales, buyers (refund / message), live switch, edit. */
    public function manageAction(){
        if (!Permissions::can_act_as_creator() || !Permissions::team_allows('manage')) { header('Location: /'); exit; }
        $creator_id = Permissions::creator_id();
        $id    = (int) (Main::get_url()[2] ?? 0);
        $model = new ServicesModel();
        $sv    = $id > 0 ? $model->get_one($creator_id, $id) : null;
        if (!$sv) { Errors::page_not_found(); return; }
        $rows  = (new UsersModel())->get_user_by_id($creator_id);
        $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;

        $this->view->service = $sv;
        $this->view->stats   = $model->stats($id);
        $this->view->handle  = (string) ($owner['u_name'] ?? '');
        $this->view->render();
    }
}
