<?php
/** Creator onboarding checklist: progress read + dismiss. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiSetupController extends BaseApiController {

    public function setup_progressAction(){
        $user = $this->require_creator('content');
        $this->jsonSuccess(SetupService::progress((int) $user['user_id'], true));
    }

    /** Hides the layout card for good (the /setup page stays reachable). Owner-scoped, like every creator setting. */
    public function setup_dismissAction(){
        $user = $this->require_creator('content');
        (new CreatorSetupModel())->dismiss((int) $user['user_id']);
        $this->jsonSuccess(['message' => 'Checklist hidden']);
    }
}
