<?php
/**
 * /founding/testimonial: where a founding creator writes the sentence the testimonial request asks for (Founding).
 * Reached through PagesController::foundingAction (the /founding URL is a public page). Account owners with an
 * active founding claim only; everyone else gets a 404. Saved through /api/founding_testimonial_save.
 */
class FoundingController extends Controller {

    public $protected = 1;

    public function __construct(){
        parent::__construct();
    }

    public function testimonialAction(){
        header('Cache-Control: private, no-store');
        if ((int) Session::get('user_id') === 0) { self::bounce(); }
        $claim = Permissions::is_owner_creator() ? (new FoundingClaimsModel())->for_user((int) Session::get('user_id')) : null;
        if (!$claim || (string) $claim['status'] !== 'active') { Errors::page_not_found(); return; }
        $this->view->claim = $claim;
        $this->view->render();
    }
}
