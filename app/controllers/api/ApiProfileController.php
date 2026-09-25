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
    /** Upload the signed-in person's own profile photo (fans, creators and team members alike). Stored on their creator_profiles row. */
    public function upload_my_avatarAction(){
        $user_id = (int) Session::get('user_id');
        if ($user_id <= 0) { $this->jsonError('Not authorized'); }
        $file = $_FILES['image'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->jsonError('No image was uploaded');
        }
        if ((int) $file['size'] > 5 * 1024 * 1024) { $this->jsonError('Image must be 5MB or smaller'); }
        // Trust the actual bytes, not the client-supplied name/type.
        $info = @getimagesize($file['tmp_name']);
        $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if ($info === false || !isset($ext_map[$info['mime']])) { $this->jsonError('Unsupported image type (use JPG, PNG, WebP, or GIF)'); }
        if (!S3Service::configured()) { $this->jsonError('Image uploads are not available right now'); }
        $key = 'creator/u' . $user_id . '_avatar_' . bin2hex(random_bytes(8)) . '.' . $ext_map[$info['mime']];
        $url = S3Service::upload_file($key, $file['tmp_name'], $info['mime']);
        if ($url === '') { $this->jsonError('Could not save the image'); }
        (new CreatorProfileModel())->set_image($user_id, 'avatar_url', $url);
        $this->jsonSuccess(['url' => $url, 'message' => 'Profile photo updated']);
    }

    /** Remove the signed-in person's own profile photo. */
    public function remove_my_avatarAction(){
        $user_id = (int) Session::get('user_id');
        if ($user_id <= 0) { $this->jsonError('Not authorized'); }
        $model = new CreatorProfileModel();
        $old = (string) ($model->get_for_user($user_id)['avatar_url'] ?? '');
        $model->set_image($user_id, 'avatar_url', '');
        if ($old !== '') { S3Service::delete_by_url($old); }
        $this->jsonSuccess(['message' => 'Profile photo removed']);
    }

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

        // Accepts either a user_id (Block buttons on profile / inbox / audience) or a typed username (Settings).
        $target_id = (int) ($this->post['user_id'] ?? 0);
        $u_name = trim((string) ($this->post['u_name'] ?? ''));
        $u_name = ltrim($u_name, '@');
        if ($target_id <= 0 && $u_name === '') {
            $this->jsonError('A username is required');
        }

        $target = $target_id > 0 ? $this->userModel->get_user_by_id($target_id) : $this->userModel->get_user_by_username($u_name);
        if (!is_array($target) || count($target) !== 1) {
            $this->jsonError('No account found with that username');
        }
        $target = $target[0];

        if ((int) $target['user_id'] === (int) Session::get('user_id')) {
            $this->jsonError('You cannot block yourself');
        }

        $me = (int) Session::get('user_id');
        $blocksModel = new BlocksModel();
        $blocksModel->add_block($me, (int) $target['user_id']);
        // A block ends the relationship both ways: follows go, and any membership between the two is canceled now.
        $follows = new FollowsModel();
        $follows->unfollow($me, (int) $target['user_id']);
        $follows->unfollow((int) $target['user_id'], $me);
        $this->end_memberships_between($me, (int) $target['user_id']);

        $name = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? ''));

        $this->jsonSuccess(['message' => 'Account blocked', 'blocked_user_id' => (int) $target['user_id'], 'u_name' => $target['u_name'], 'name' => $name]);
    }

    /**
     * Cancel every active membership between two accounts, whichever of them is the creator.
     * Paid ones are canceled in Stripe immediately (no proration refund — refunds stay an Admin decision);
     * the row is closed even if Stripe fails so access ends at once. The subscriber is told their membership ended.
     */
    private function end_memberships_between(int $a, int $b): void{
        $subs = new CreatorSubscriptionsModel();
        foreach (array(array($a, $b), array($b, $a)) as $pair) {
            list($fan, $creator) = $pair;
            $rows = $subs->active_between($fan, $creator);
            if (empty($rows)) { continue; }
            $creator_row = $this->userModel->get_user_by_id($creator);
            $connect     = (is_array($creator_row) && count($creator_row) === 1) ? (string) ($creator_row[0]['stripe_connect_account_id'] ?? '') : '';
            foreach ($rows as $sub) {
                if (empty($sub['is_free']) && !empty($sub['stripe_subscription_id']) && $connect !== '') {
                    if (!StripeService::cancel_subscription_now($connect, (string) $sub['stripe_subscription_id'])) {
                        error_log('[block] Stripe cancel failed for creator_subscriptions #' . $sub['id'] . ' (' . $sub['stripe_subscription_id'] . ') — row closed locally, check Stripe');
                    }
                }
                $subs->cancel($fan, (int) $sub['id']);
            }
            $this->notify($fan, 'subscriptions', 'Membership ended',
                'Your membership to ' . (Notify::name_of($creator) ?: 'this creator') . ' has ended and will not renew.',
                '/account/settings?section=subscriptions', 'fa-heart-crack');
        }
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
        // New creators start on Free: its one-time starter AI credits land now.
        $fresh = $this->userModel->get_user_by_id($user_id);
        if (is_array($fresh) && count($fresh) === 1) { Plan::grant_monthly($fresh[0]); }

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

        // A fan account has no plan: end any paid plan now so it is never billed again.
        BillingService::end_plan_now($user_id);

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

    /** "Download Your Data": queue an export of the signed-in person's own account. */
    public function data_export_requestAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Not authorized'); }
        $r = DataExportService::request($me);
        if (empty($r['ok'])) { $this->jsonError((string) $r['message']); }
        $this->jsonSuccess(['message' => 'Your export is being prepared. We\'ll let you know when it\'s ready.', 'export' => DataExportService::state_json($r['export'])]);
    }

    /** The signed-in person's latest export (Settings polls this while one is being prepared). */
    public function data_export_statusAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Not authorized'); }
        $rows = $this->userModel->get_user_by_id($me);
        $tz = (is_array($rows) && count($rows) === 1) ? (string) ($rows[0]['content_timezone'] ?? 'UTC') : 'UTC';
        $this->jsonSuccess(['export' => DataExportService::state_json((new DataExportsModel())->latest_for_user($me), $tz)]);
    }

    /** Signed link to the signed-in person's ready export. */
    public function data_export_downloadAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Not authorized'); }
        $url = DataExportService::download_url($me, (int) ($this->post['id'] ?? 0));
        if ($url === '') { $this->jsonError('This export is no longer available. Request a new one.'); }
        $this->jsonSuccess(['url' => $url]);
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
            if ((new BlocksModel())->either_blocked($user_id, $creator_id)) {
                $response['message'] = 'You cannot follow this account';
                return $response;
            }
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
