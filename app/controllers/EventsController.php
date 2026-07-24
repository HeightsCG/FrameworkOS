<?php
/**
 * Creator event management (/events), PRD §23. Events are a monetization offering, so
 * management is Manager+ (require_creator('manage') gates the writes). Collaborators
 * operate on the owner's account via Permissions::creator_id().
 */
class EventsController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function indexAction(){
        if (!Permissions::can_act_as_creator() || !Permissions::team_allows('manage')) { header('Location: /'); exit; }
        $creator_id = Permissions::creator_id();
        $rows  = (new UsersModel())->get_user_by_id($creator_id);
        $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;

        $this->view->events   = (new EventsModel())->list_for_creator($creator_id);
        $this->view->tiers    = (new CreatorPlansModel())->get_for_user($creator_id);   // for tier-specific access
        $this->view->timezone = (string) ($owner['content_timezone'] ?? 'UTC');
        $this->view->render();
    }
}
