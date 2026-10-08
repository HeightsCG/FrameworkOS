<?php
/** Creator onboarding checklist: progress read + dismiss. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiSetupController extends BaseApiController {

    public function setup_progressAction(){
        $user = $this->require_creator('content');
        $this->jsonSuccess(SetupService::progress((int) $user['user_id'], true));
    }

    /** Hide one step. The /setup page still lists it so it can be done later. */
    public function setup_skip_stepAction(){
        $user = $this->require_creator('content');
        $key  = (string) ($this->post['key'] ?? '');
        if (!array_key_exists($key, SetupService::steps())) { $this->jsonError('Unknown step'); }
        if ((SetupService::steps()[$key]['skippable'] ?? true) === false) { $this->jsonError('This step cannot be skipped'); }
        (new CreatorSetupModel())->skip_step((int) $user['user_id'], $key);
        $this->jsonSuccess(SetupService::progress((int) $user['user_id']));
    }

    /** Hides the layout card for good (the /setup page stays reachable). Owner-scoped, like every creator setting. */
    public function setup_dismissAction(){
        $user = $this->require_creator('content');
        (new CreatorSetupModel())->dismiss((int) $user['user_id']);
        $this->jsonSuccess(['message' => 'Checklist hidden']);
    }
}
