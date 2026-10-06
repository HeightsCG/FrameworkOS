<?php
/** Post for Me social accounts, Fanvue connections, Fanvue auto-messages. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiSocialIntegrationsController extends BaseApiController {

    public function connect_accountAction(){
        $user = $this->social_user('manage');
        // Enforce the plan tier's connected-account cap (0 = unlimited).
        $cap = Plan::limit($user, 'socials');
        if ($cap !== null && (int) $cap > 0) {
            $connected = (new SocialAccountsModel())->get_connected_for_user((int) $user['user_id']);
            if (is_array($connected) && count($connected) >= (int) $cap) {
                $this->jsonError('Your plan connects up to ' . (int) $cap . ' social accounts. Upgrade to add more.', ['need_upgrade' => true]);
            }
        }
        $platform = $this->post['platform'] ?? '';
        if ($platform === '') {
            $this->jsonError('Platform is required');
        }
        // Some platforms need extra connection data (keyed by platform).
        // Instagram: connection_type ("instagram" = Login with Instagram, "facebook" = via a linked Page).
        // LinkedIn: connection_type "organization" is required when using Post for Me's provided credentials.
        $platform_data = null;
        if ($platform === 'instagram') {
            $platform_data = ['instagram' => ['connection_type' => 'instagram']];
        } elseif ($platform === 'linkedin') {
            $platform_data = ['linkedin' => ['connection_type' => 'organization']];
        } elseif ($platform === 'x') {
            // X: Post for Me requires the OAuth flavour. OAuth 2.0 is the current X API.
            $platform_data = ['x' => ['connection_type' => 'oauth2']];
        } elseif ($platform === 'bluesky') {
            // Bluesky: no OAuth screen — the handle + an app password go to Post for Me, which
            // returns the same kind of redirect URL. The password is passed through, never stored or logged.
            $handle = ltrim(trim(html_entity_decode((string) ($this->post['handle'] ?? ''), ENT_QUOTES, 'UTF-8')), '@');
            $app_pw = trim(html_entity_decode((string) ($this->post['app_password'] ?? ''), ENT_QUOTES, 'UTF-8'));
            if ($handle === '' || $app_pw === '') {
                $this->jsonError('Enter your Bluesky handle and an app password.');
            }
            $platform_data = ['bluesky' => ['handle' => $handle, 'app_password' => $app_pw]];
        }
        $url = PostForMeService::create_auth_url($platform, (int) $user['user_id'], ['posts'], $platform_data);
        if ($url === '') {
            $why = (string) PostForMeService::$last_error;
            $this->jsonError('Could not start the connection. ' . ($why !== '' ? $why : 'Please try again.'));
        }
        $this->jsonSuccess(['url' => $url]);
    }

    /** Save the AI disclosure line for one connected account (blank = the default line). */
    public function social_disclosure_saveAction(){
        $user   = $this->social_user('manage');
        $pfm_id = (string) ($this->post['account_id'] ?? '');
        $model  = new SocialAccountsModel();
        $acct   = $pfm_id !== '' ? $model->get_by_pfm_id($pfm_id) : null;
        if (!$acct || (int) $acct['user_id'] !== (int) $user['user_id']) { $this->jsonError('That account was not found.'); }
        $text = html_entity_decode((string) ($this->post['text'] ?? ''), ENT_QUOTES, 'UTF-8');
        $model->set_ai_disclosure_text((int) $user['user_id'], $pfm_id, $text);
        $this->jsonSuccess(['line' => AiDisclosure::line($text)]);
    }

    public function disconnect_accountAction(){
        $user   = $this->social_user('manage');
        $pfm_id = $this->post['account_id'] ?? '';
        if ($pfm_id === '') {
            $this->jsonError('Account is required');
        }
        $accountsModel = new SocialAccountsModel();
        $acct = $accountsModel->get_by_pfm_id($pfm_id);
        if (!$acct || (int) $acct['user_id'] !== (int) $user['user_id']) {
            $this->jsonError('Account not found');
        }
        PostForMeService::disconnect($pfm_id);
        $accountsModel->mark_disconnected((int) $user['user_id'], $pfm_id);
        $this->jsonSuccess(['message' => 'Account disconnected']);
    }

    // (upload_media_url / create_post / post_status were removed 2026-09-26: nothing called them; posts go out through
    //  SocialShareService from the post editor and the Claude connector.)

    /* ---------- Notification preferences ---------- */

    /**
     * Start the Fanvue OAuth flow: returns the authorize URL to send the browser to.
     * State + PKCE verifier are kept in the session and checked by
     * AccountController::fanvue_callbackAction.
     */
    public function fanvue_connectAction(){
        $user = $this->social_user('manage');
        if (!FanvueService::configured()) {
            $this->jsonError('Fanvue is not configured on this server yet.');
        }
        list($url, $state, $verifier) = FanvueService::authorize_url();
        Session::set('fanvue_oauth', [
            'state'    => $state,
            'verifier' => $verifier,
            'user_id'  => (int) $user['user_id'],   // the OWNER the connection belongs to
            'started'  => time(),
            'return_section' => (($this->post['return_section'] ?? '') === 'inbox') ? 'inbox' : 'connected',
        ]);
        $this->jsonSuccess(['url' => $url]);
    }

    public function fanvue_disconnectAction(){
        $user = $this->social_user('manage');
        (new FanvueAccountsModel())->disconnect((int) $user['user_id']);
        $this->jsonSuccess(['message' => 'Fanvue disconnected']);
    }

    /** Auth + plan gate for all social endpoints. Returns the user array or exits with a JSON error. */
    /**
     * Auth gate for social posting/integrations. Connections are a SHARED team resource:
     * this always resolves to the OWNER's account, so collaborators use the owner's
     * connected accounts. $capability: 'content' to use them (Editor+), 'manage' to
     * connect/disconnect (Manager+). Returns the OWNER's user array or exits with JSON.
     */
    private function social_user(string $capability = 'content'): array{
        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }
        $acting = $this->userModel->get_user_by_id((int) Session::get('user_id'));
        $acting = (is_array($acting) && count($acting) === 1) ? $acting[0] : null;
        if (!$acting) { $this->jsonError('Not authorized'); }

        if (!Permissions::team_allows($capability)) {
            $msg = ($capability === 'manage') ? 'Only the owner or a manager can connect or remove social accounts.' : 'Your role is view-only.';
            $this->jsonError((string) ($msg));
        }

        // Collaborators operate on the owner's connected accounts (shared).
        $is_team = !empty($acting['team_role']) && (int) ($acting['created_by'] ?? 0) > 0;
        if ($is_team) {
            $rows = $this->userModel->get_user_by_id((int) $acting['created_by']);
            $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        } else {
            $user = $acting;
        }
        if (!$user || (int) $user['role_id'] !== $this->userModel->get_role_id_by_name('Creator')) {
            $this->jsonError('Only creator accounts can connect social accounts');
        }
        if (!Plan::can_social_post($user)) {
            $this->jsonError('Your plan does not include social posting');
        }
        return $user;   // the owner
    }

}
