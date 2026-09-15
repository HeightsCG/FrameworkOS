<?php
/** Post for Me social accounts, Fanvue and Eromify connections, Fanvue auto-messages. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
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

    public function upload_media_urlAction(){
        $this->social_user();
        $res = PostForMeService::create_upload_url();
        if (!$res) {
            $this->jsonError('Could not prepare the upload');
        }
        $this->jsonSuccess(['media_url' => $res[0], 'upload_url' => $res[1]]);
    }

    public function create_postAction(){
        $user     = $this->social_user();
        $caption  = $this->post['caption'] ?? '';
        $ids      = $this->post['social_account_ids'] ?? [];
        $media    = $this->post['media_url'] ?? '';
        $schedule = $this->post['schedule'] ?? 'now';
        $when     = $this->post['scheduled_at'] ?? '';

        if (!is_array($ids) || count($ids) === 0) {
            $this->jsonError('Select at least one connected account');
        }
        if (trim($caption) === '' && $media === '') {
            $this->jsonError('Add a caption or media');
        }

        // Only allow this user's currently-connected accounts.
        $accountsModel = new SocialAccountsModel();
        $valid = [];
        foreach ($accountsModel->get_connected_for_user((int) $user['user_id']) as $c) {
            $valid[$c['post_for_me_social_account_id']] = true;
        }
        $target = [];
        foreach ($ids as $id) {
            if (isset($valid[$id])) { $target[] = $id; }
        }
        if (count($target) === 0) {
            $this->jsonError('No connected accounts selected');
        }

        $sched = null;
        if ($schedule === 'later' && $when !== '') {
            $ts = strtotime($when);
            if ($ts) { $sched = date('c', $ts); }
        }
        $media_urls = ($media !== '') ? [$media] : [];

        $post = PostForMeService::create_post($target, $caption, $media_urls, $sched);
        if (!$post || empty($post['id'])) {
            $this->jsonError('Could not create the post. Please try again.');
        }

        $postsModel = new SocialPostsModel();
        $postsModel->create((int) $user['user_id'], $post['id'], $caption, $post['status'] ?? 'processing', $sched, $target);

        $this->jsonSuccess(['post_id' => $post['id'], 'status' => $post['status'] ?? 'processing', 'message' => $sched ? 'Post scheduled' : 'Post submitted']);
    }

    public function post_statusAction(){
        $this->social_user();
        $pfm_post_id = $this->post['post_id'] ?? '';
        if ($pfm_post_id === '') {
            $this->jsonError('Post id is required');
        }
        $post = PostForMeService::get_post($pfm_post_id);
        if (!$post) {
            $this->jsonError('Post not found');
        }
        $status = $post['status'] ?? '';
        (new SocialPostsModel())->update_status($pfm_post_id, $status);
        $this->jsonSuccess(['status' => $status]);
    }

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

    public function fanvue_auto_messages_listAction(){
        $user  = $this->inbox_user();
        $token = $this->inbox_fanvue_token($user);
        $items = FanvueService::get_automated_messages($token);
        if ($items === null) {
            $this->jsonError("Couldn't load your automated messages from Fanvue. Try again or reconnect.");
        }
        $out = [];
        foreach (FanvueService::TRIGGERS as $t) {
            $out[$t] = isset($items[$t]) ? $items[$t] : ['enabled' => false, 'text' => '', 'price' => 0];
        }
        $this->jsonSuccess(['items' => $out]);
    }

    /** Save (enable) one trigger's text on Fanvue, or with ai_generate=1 just return a Claude draft. */
    public function fanvue_auto_message_saveAction(){
        $user    = $this->inbox_user();
        $trigger = (string) ($this->post['trigger'] ?? '');
        if (!in_array($trigger, FanvueService::TRIGGERS, true)) {
            $this->jsonError('Unknown trigger.');
        }
        if (!empty($this->post['ai_generate'])) {
            if (!ClaudeService::configured()) { $this->jsonError('AI is not configured on this server.'); }
            $ip = $this->get_ip_address();
            if ($this->loginAttemptsModel->count_recent($ip, 'inbox_test', 1) >= 10) {
                $this->jsonError('Slow down — try again in a minute.');
            }
            $this->loginAttemptsModel->record($ip, (string) $user['user_id'], 'inbox_test');
            $text = InboxAutomationService::draft_trigger_message($user, $trigger);
            if ($text === '') { $this->jsonError('Could not draft that message. Try again.'); }
            $this->jsonSuccess(['text' => $text, 'message' => 'Draft ready']);
        }
        $text  = trim(html_entity_decode((string) ($this->post['text'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($text === '') { $this->jsonError('Write the message first.'); }
        $token = $this->inbox_fanvue_token($user);
        try {
            FanvueService::put_automated_message($token, $trigger, $text);
        } catch (\Throwable $e) {
            $this->jsonError((string) ($e->getMessage()));
        }
        $this->jsonSuccess(['message' => 'Saved to Fanvue']);
    }

    public function fanvue_auto_message_deleteAction(){
        $user    = $this->inbox_user();
        $trigger = (string) ($this->post['trigger'] ?? '');
        if (!in_array($trigger, FanvueService::TRIGGERS, true)) {
            $this->jsonError('Unknown trigger.');
        }
        $token = $this->inbox_fanvue_token($user);
        try {
            FanvueService::delete_automated_message($token, $trigger);
        } catch (\Throwable $e) {
            $this->jsonError((string) ($e->getMessage()));
        }
        $this->jsonSuccess(['message' => 'Turned off']);
    }

    // ---- Eromify (Creator Studio) ---------------------------------------------------------

    /** Save a creator's Eromify API key after checking it works. */
    public function eromify_connectAction(){
        $user = $this->require_creator('manage');
        $key  = trim(html_entity_decode((string) ($this->post['api_key'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($key === '' || strpos($key, 'ero_') !== 0) {
            $this->jsonError('Paste an Eromify API key (it starts with ero_live_).');
        }
        $v = EromifyService::verify($key);
        if (!$v['ok']) {
            $this->jsonError((string) ($v['error']));
        }
        (new EromifyAccountsModel())->connect((int) $user['user_id'], $key, $v['plan'], $v['credits']);
        $this->jsonSuccess(['message' => 'Eromify connected', 'credits' => $v['credits'], 'plan' => $v['plan']]);
    }

    public function eromify_disconnectAction(){
        $user = $this->require_creator('manage');
        (new EromifyAccountsModel())->disconnect((int) $user['user_id']);
        $this->jsonSuccess(['message' => 'Eromify disconnected']);
    }

    /** The creator's studio characters, for the automation form. */
    public function eromify_charactersAction(){
        $user = $this->require_creator();
        $acct = (new EromifyAccountsModel())->get_connected_for_user((int) $user['user_id']);
        if (!$acct) {
            $this->jsonError('Connect Eromify in Integrations first.', ['need_connect' => true]);
        }
        $r = EromifyService::list_characters($acct['api_key']);
        if (isset($r['error'])) {
            (new EromifyAccountsModel())->set_error((int) $user['user_id'], $r['error']);
            $this->jsonError((string) ($r['error']));
        }
        $this->jsonSuccess(['characters' => $r['characters']]);
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
