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
}
