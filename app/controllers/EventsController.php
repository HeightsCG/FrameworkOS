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

    /** One event's workspace: /events/manage/<id> — sales, attendees, messages, settings. */
    public function manageAction(){
        if (!Permissions::can_act_as_creator() || !Permissions::team_allows('manage')) { header('Location: /'); exit; }
        $creator_id = Permissions::creator_id();
        $id    = (int) (Main::get_url()[2] ?? 0);
        $model = new EventsModel();
        $ev    = $id > 0 ? $model->get_one($creator_id, $id) : null;
        if (!$ev) { Errors::page_not_found(); return; }
        $rows  = (new UsersModel())->get_user_by_id($creator_id);
        $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        $tab   = (string) ($_GET['tab'] ?? 'attendees');

        $this->view->event     = $ev;
        $this->view->stats     = $model->stats($id);
        $this->view->recordings = (new LiveRecordingsModel())->for_event($id);   // CLS Video recordings of this event's call
        $this->view->replay_sold = (new ReplayUnlocksModel())->count_for_event($id);
        $this->view->registrations = $model->registration_count($id);
        $this->view->has_paid  = $model->has_paid_going($id);
        $blocked = (new BlocksModel())->related_ids($creator_id);   // same filter event_message_send applies
        $this->view->recipients = count(array_filter($model->going_user_ids($id), function ($u) use ($blocked) { return !isset($blocked[$u]); }));
        $this->view->tiers     = (new CreatorPlansModel())->get_for_user($creator_id);
        $this->view->timezone  = (string) ($owner['content_timezone'] ?? 'UTC');
        $this->view->handle    = (string) ($owner['u_name'] ?? '');
        $this->view->share_link = $owner ? CustomDomains::share_url($owner, 'events/' . (int) $id) : '';   // their own domain when they have one
        $this->view->tab       = in_array($tab, array('attendees', 'messages'), true) ? $tab : 'attendees';
        $this->view->render();
    }
}
