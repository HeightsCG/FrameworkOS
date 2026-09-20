<?php
/** Account profile, preferences, blocking, creator role changes and following. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiProfileController extends BaseApiController {

    public function update_profileAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        if (empty($this->post['first_name'])) {
            $this->jsonError('First name is required');
        }

        if (empty($this->post['last_name'])) {
            $this->jsonError('Last name is required');
        }

        if (empty($this->post['user_email']) || !filter_var($this->post['user_email'], FILTER_VALIDATE_EMAIL)) {
            $this->jsonError('A valid email is required');
        }

        if (strtolower($this->post['user_email']) !== strtolower((string) Session::get('user_email')) && $this->userModel->email_exists($this->post['user_email'])) {
            $this->jsonError('That email is already in use');
        }

        $user_phone    = empty($this->post['user_phone']) ? '' : $this->post['user_phone'];
        $business_name = empty($this->post['business_name']) ? '' : $this->post['business_name'];
        $website_url   = empty($this->post['website_url']) ? '' : $this->post['website_url'];

        $this->userModel->update_profile(
            (int) Session::get('user_id'),
            $this->post['first_name'],
            $this->post['last_name'],
            $this->post['user_email'],
            $user_phone,
            $business_name,
            $website_url,
            (int) Session::get('user_id')
        );

        Session::set('first_name', $this->post['first_name']);
        Session::set('last_name', $this->post['last_name']);
        Session::set('user_email', $this->post['user_email']);
        Session::set('user_phone', $user_phone);
        Session::set('business_name', $business_name);
        Session::set('website_url', $website_url);

        $this->jsonSuccess(['message' => 'Your profile has been updated']);
    }

    /** Change the signed-in user's username, enforcing the PRD 6.4 rules. */
    public function change_usernameAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $user_id = (int) Session::get('user_id');
        $new     = ltrim(strtolower(trim((string) ($this->post['u_name'] ?? ''))), '@');

        $user = $this->userModel->get_user_by_id($user_id);
        if (!is_array($user) || count($user) !== 1) {
            $this->jsonError('Account not found');
        }
        $user    = $user[0];
        $current = (string) $user['u_name'];

        if ($new === strtolower($current)) {
            $this->jsonError('That is already your username');
        }

        $usernameModel = new UsernameModel();

        if ($usernameModel->cooldown_days_left($user['u_name_changed_at']) > 0) {
            $this->jsonError('You can change your username again on ' . $usernameModel->next_change_date($user['u_name_changed_at']));
        }

        $format_error = $usernameModel->validate_format($new);
        if ($format_error !== '') {
            $this->jsonError((string) ($format_error));
        }

        if ($usernameModel->is_taken($new, $user_id)) {
            $this->jsonError('That username is not available');
        }

        $usernameModel->change($user_id, $current, $new);
        Session::set('u_name', $new);

        $this->jsonSuccess(['message' => 'Your username has been updated', 'u_name' => $new, 'next_change_date' => $usernameModel->next_change_date(date('Y-m-d H:i:s'))]);
    }

    public function save_notification_prefsAction(){
        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $posted = $this->post['prefs'] ?? [];
        if (!is_array($posted)) {
            $this->jsonError('Invalid preferences');
        }

        // Normalize every known category from the posted set so unchecked boxes
        // (absent from the payload) are saved as off, not left untouched.
        $incoming = [];
        foreach (array_keys(NotificationPrefsModel::$categories) as $category) {
            $row = $posted[$category] ?? [];
            $incoming[$category] = [
                'in_platform' => !empty($row['in_platform']),
                'email'       => !empty($row['email']),
            ];
        }

        $prefsModel = new NotificationPrefsModel();
        $prefsModel->save_prefs((int) Session::get('user_id'), $incoming);

        $this->jsonSuccess(['message' => 'Notification preferences saved']);
    }

    /* ---------- Content preferences ---------- */

    public function save_adult_content_prefAction(){
        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $enabled = !empty($this->post['enabled']);

        // Enabling adult content requires an explicit age confirmation (PRD 34.7).
        if ($enabled && empty($this->post['age_confirmed'])) {
            $this->jsonError('Age confirmation is required');
        }

        $this->userModel->set_adult_content_enabled((int) Session::get('user_id'), $enabled, (int) Session::get('user_id'));

        $this->jsonSuccess(['message' => $enabled ? 'Adult content enabled' : 'Adult content hidden']);
    }

    /* ---------- Internal messaging (PRD 24) ---------- */

    public function block_userAction(){
        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $u_name = trim((string) ($this->post['u_name'] ?? ''));
        $u_name = ltrim($u_name, '@');
        if ($u_name === '') {
            $this->jsonError('A username is required');
        }

        $target = $this->userModel->get_user_by_username($u_name);
        if (!is_array($target) || count($target) !== 1) {
            $this->jsonError('No account found with that username');
        }
        $target = $target[0];

        if ((int) $target['user_id'] === (int) Session::get('user_id')) {
            $this->jsonError('You cannot block yourself');
        }

        $blocksModel = new BlocksModel();
        $blocksModel->add_block((int) Session::get('user_id'), (int) $target['user_id']);

        $name = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? ''));

        $this->jsonSuccess(['message' => 'Account blocked', 'blocked_user_id' => (int) $target['user_id'], 'u_name' => $target['u_name'], 'name' => $name]);
    }

    public function unblock_userAction(){
        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $blocked_user_id = (int) ($this->post['blocked_user_id'] ?? 0);
        if ($blocked_user_id <= 0) {
            $this->jsonError('Account is required');
        }

        $blocksModel = new BlocksModel();
        $blocksModel->remove_block((int) Session::get('user_id'), $blocked_user_id);

        $this->jsonSuccess(['message' => 'Account unblocked']);
    }

    /* ---------- Become a creator ---------- */

    public function become_creatorAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        if (empty($this->post['accept_agreement'])) {
            $this->jsonError('You must accept the Creator Agreement and Content Policy');
        }

        $user_id = (int) Session::get('user_id');
        $result  = $this->userModel->make_creator($user_id, $user_id);
        if ($result === false) {
            $this->jsonError('Could not activate your creator account');
        }

        // Reflect the new role on the session so gating updates without re-login.
        $creator_role_id = $this->userModel->get_role_id_by_name('Creator');
        Session::set('role_id', $creator_role_id);
        Session::set('creator_since', date('Y-m-d H:i:s'));

        $this->jsonSuccess(['message' => 'Welcome — your creator account is active']);
    }

    public function leave_creatorAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        // Guard against accidental calls — the UI requires an explicit confirmation.
        if (empty($this->post['confirm'])) {
            $this->jsonError('Please confirm you want to stop being a creator');
        }

        $user_id = (int) Session::get('user_id');

        // Hard delete all creator content first (irreversible, no soft delete),
        // then revert the account to a regular User.
        $this->userModel->hard_delete_creator_content($user_id);

        $result = $this->userModel->revert_creator($user_id, $user_id);
        if ($result === false) {
            $this->jsonError('Could not update your account');
        }

        Session::set('role_id', $this->userModel->get_role_id_by_name('User'));
        Session::set('creator_since', null);

        $this->jsonSuccess(['message' => 'Your creator account has been removed']);
    }

    /* ---------- Creator profile / branding ---------- */

    public function delete_my_accountAction(){

        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }

        $this->userModel->delete_account((int) Session::get('user_id'), (int) Session::get('user_id'));
        Main::do_logout();

        $this->jsonSuccess(['message' => 'Your account has been deleted']);
    }

    /** Follow a creator (auth required). */
    public function follow_creatorAction(){
        echo json_encode($this->set_follow(true));
        exit;
    }

    /** Unfollow a creator (auth required). */
    public function unfollow_creatorAction(){
        echo json_encode($this->set_follow(false));
        exit;
    }

    private function set_follow(bool $following): array{
        $response = ['success' => false, 'message' => 'Something went wrong'];

        if (empty(Session::get('user_id'))) {
            $response['message']    = 'Sign in to follow creators';
            $response['need_login'] = true;
            return $response;
        }

        $user_id    = (int) Session::get('user_id');
        $creator_id = (int) ($this->post['creator_id'] ?? 0);

        if ($creator_id <= 0) {
            $response['message'] = 'You cannot follow this account';
            return $response;
        }

        $follows = new FollowsModel();
        if ($following) {
            $follows->follow($user_id, $creator_id);
            $who = (new MessagesModel())->identity_map([$user_id])[$user_id] ?? ['handle' => ''];
            $this->notify($creator_id, 'creator_activity', 'New follower',
                '@' . $who['handle'] . ' started following you.', '/audience', 'fa-user-plus');
            InboxAutomationService::trigger($creator_id, $user_id, 'new_follower');
        } else {
            $follows->unfollow($user_id, $creator_id);
        }

        $response['success']        = true;
        $response['message']        = $following ? 'Following' : 'Unfollowed';
        $response['following']      = $following;
        $response['follower_count'] = $follows->count_followers($creator_id);
        return $response;
    }

}
