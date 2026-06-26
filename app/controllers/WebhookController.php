<?php
/**
 * Public, unauthenticated endpoints for inbound provider webhooks.
 * NOT extended from ApiController, so it carries no CSRF check; $protected = 0
 * so the layout never swaps in the login form.
 *
 * NOTE: live delivery needs a publicly reachable URL configured in the Post for
 * Me dashboard. The POC works without it (connection via redirect, status via
 * polling); this handler is here so wiring it later is a config-only change.
 */
class WebhookController extends Controller {

    public $protected = 0;

    public function __construct(){
        parent::__construct();
    }

    public function postformeAction(){
        $event = json_decode(file_get_contents('php://input'), true);
        if (!is_array($event)) {
            http_response_code(400);
            echo 'bad request';
            exit;
        }

        // TODO: verify the webhook signature once configured in the dashboard.
        $type = $event['type'] ?? '';
        $data = $event['data'] ?? array();

        if ($type === 'social.account.created' && !empty($data['id']) && isset($data['external_id'])) {
            (new SocialAccountsModel())->upsert_from_pfm((int) $data['external_id'], $data);
        } elseif (strpos($type, 'social.post') === 0 && !empty($data['id']) && isset($data['status'])) {
            (new SocialPostsModel())->update_status($data['id'], $data['status']);
        }

        http_response_code(200);
        echo 'ok';
        exit;
    }
}
