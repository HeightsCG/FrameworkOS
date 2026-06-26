<?php
class ApiController extends Controller {

    public $protected = 1;
    private $userModel;
    private $notificationsModel;
    private $loginAttemptsModel;
    private $billingModel;

    public function __construct(){
        parent::__construct();

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !CSRF::validate()) {
            echo json_encode(array('success' => false, 'message' => 'Invalid or expired request token'));
            exit;
        }

        $this->userModel = new UsersModel();
        $this->notificationsModel = new NotificationsModel();
        $this->loginAttemptsModel = new LoginAttemptsModel();
        $this->billingModel = new BillingModel();
    }

    private function password_complexity_error($p){
        if (strlen($p) < 8) {
            return 'Password must be at least 8 characters';
        }
        if (!preg_match('/[A-Z]/', $p)) {
            return 'Password must include an uppercase letter';
        }
        if (!preg_match('/[a-z]/', $p)) {
            return 'Password must include a lowercase letter';
        }
        if (!preg_match('/[0-9]/', $p)) {
            return 'Password must include a number';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $p)) {
            return 'Password must include a symbol';
        }
        return '';
    }

    public function registerAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty($this->post['first_name'])) {
            $response['message'] = 'First name is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['last_name'])) {
            $response['message'] = 'Last name is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['user_email']) || !filter_var($this->post['user_email'], FILTER_VALIDATE_EMAIL)) {
            $response['message'] = 'A valid email is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['p_word'])) {
            $response['message'] = 'Password is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['p_word_confirm']) || $this->post['p_word'] !== $this->post['p_word_confirm']) {
            $response['message'] = 'Passwords do not match';
            echo json_encode($response);
            exit;
        }

        $pw_error = $this->password_complexity_error($this->post['p_word']);
        if ($pw_error !== '') {
            $response['message'] = $pw_error;
            echo json_encode($response);
            exit;
        }

        if ($this->userModel->email_exists($this->post['user_email'])) {
            $response['message'] = 'Could not create an account with those details';
            echo json_encode($response);
            exit;
        }

        $u_name = $this->userModel->generate_unique_username($this->post['first_name'], $this->post['last_name']);
        $enc_p_word = password_hash($this->post['p_word'], PASSWORD_DEFAULT);

        $this->userModel->create_user(
            $u_name,
            $enc_p_word,
            $this->post['first_name'],
            $this->post['last_name'],
            $this->post['user_email'],
        );

        $response['success']  = true;
        $response['message']  = 'Account created';
        $response['reset_pw'] = 0;
        echo json_encode($response);
        exit;
    }

    public function loginAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty($this->post['u_name'])) {
            $response['message'] = 'Username is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['p_word'])) {
            $response['message'] = 'Password is required';
            echo json_encode($response);
            exit;
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'login', 15) >= 5) {
            $response['message'] = 'Too many attempts. Please try again later.';
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_username($this->post['u_name']);
        if (!is_array($user_account) || count($user_account) !== 1
            || !password_verify($this->post['p_word'], $user_account[0]['p_word'])) {
            $this->loginAttemptsModel->record($ip, $this->post['u_name'], 'login');
            $response['message'] = 'Invalid username or password';
            echo json_encode($response);
            exit;
        }

        $user = $user_account[0];

        session_regenerate_id(true);

        foreach ($user as $key => $value) {
            Session::set($key, $value);
        }

        $response['success']  = true;
        $response['message']  = 'Login successful';
        $response['reset_pw'] = (int) ($user['reset_pw'] ?? 0);
        echo json_encode($response);
        exit;
    }

    public function logoutAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        Main::do_logout();

        $response['success'] = true;
        $response['message'] = 'You have been signed out';
        echo json_encode($response);
        exit;
    }

    public function forgotAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty($this->post['u_name'])) {
            $response['message'] = 'Username is required';
            echo json_encode($response);
            exit;
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'forgot', 15) >= 5) {
            $response['message'] = 'Too many attempts. Please try again later.';
            echo json_encode($response);
            exit;
        }
        $this->loginAttemptsModel->record($ip, $this->post['u_name'], 'forgot');

        $user_account = $this->userModel->get_user_by_username($this->post['u_name']);
        if (is_array($user_account) && count($user_account) === 1) {
            $user  = $user_account[0];
            $token = $this->userModel->set_reset_token($user['user_id']);

            if (!empty($user['user_email'])) {
                $reset_link = Main::get_base_domain() . '/account/reset?token=' . urlencode($token);
                $to_name    = trim($user['first_name'] . ' ' . $user['last_name']);
                $this->notificationsModel->send_password_reset_email($user['user_email'], $to_name, $reset_link);
            }
        }

        $response['success'] = true;
        $response['message'] = 'If the account exists, a reset link has been sent';
        echo json_encode($response);
        exit;
    }

    public function resetAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty($this->post['reset_token'])) {
            $response['message'] = 'Reset token is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['p_word'])) {
            $response['message'] = 'Password is required';
            echo json_encode($response);
            exit;
        }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'reset', 15) >= 5) {
            $response['message'] = 'Too many attempts. Please try again later.';
            echo json_encode($response);
            exit;
        }

        $pw_error = $this->password_complexity_error($this->post['p_word']);
        if ($pw_error !== '') {
            $response['message'] = $pw_error;
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_reset_token($this->post['reset_token']);
        if (!is_array($user_account) || count($user_account) !== 1) {
            $this->loginAttemptsModel->record($ip, null, 'reset');
            $response['message'] = 'This reset link is invalid or has expired';
            echo json_encode($response);
            exit;
        }

        $user = $user_account[0];
        $enc_p_word = password_hash($this->post['p_word'], PASSWORD_DEFAULT);
        $this->userModel->change_password($user['user_id'], $enc_p_word);

        $response['success'] = true;
        $response['message'] = 'Your password has been updated';
        echo json_encode($response);
        exit;
    }

    public function change_passwordAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['current_password'])) {
            $response['message'] = 'Current password is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['p_word'])) {
            $response['message'] = 'New password is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['confirm_password'])) {
            $response['message'] = 'Confirm password is required';
            echo json_encode($response);
            exit;
        }

        if ($this->post['p_word'] !== $this->post['confirm_password']) {
            $response['message'] = 'New passwords do not match';
            echo json_encode($response);
            exit;
        }

        $pw_error = $this->password_complexity_error($this->post['p_word']);
        if ($pw_error !== '') {
            $response['message'] = $pw_error;
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user_account) || count($user_account) !== 1) {
            $response['message'] = 'User not found';
            echo json_encode($response);
            exit;
        }

        if (!password_verify($this->post['current_password'], $user_account[0]['p_word'])) {
            $response['message'] = 'Your current password is incorrect';
            echo json_encode($response);
            exit;
        }

        $enc_p_word = password_hash($this->post['p_word'], PASSWORD_DEFAULT);
        $this->userModel->change_password(Session::get('user_id'), $enc_p_word, Session::get('user_id'));
        Session::set('reset_pw', 0);

        $response['success'] = true;
        $response['message'] = 'Your password has been updated';
        echo json_encode($response);
        exit;
    }

    public function update_profileAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['first_name'])) {
            $response['message'] = 'First name is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['last_name'])) {
            $response['message'] = 'Last name is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['user_email']) || !filter_var($this->post['user_email'], FILTER_VALIDATE_EMAIL)) {
            $response['message'] = 'A valid email is required';
            echo json_encode($response);
            exit;
        }

        if (strtolower($this->post['user_email']) !== strtolower((string) Session::get('user_email')) && $this->userModel->email_exists($this->post['user_email'])) {
            $response['message'] = 'That email is already in use';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['u_name'])) {
            $response['message'] = 'Username is required';
            echo json_encode($response);
            exit;
        }

        if (strtolower($this->post['u_name']) !== strtolower((string) Session::get('u_name')) && $this->userModel->username_exists($this->post['u_name'])) {
            $response['message'] = 'That username is already taken';
            echo json_encode($response);
            exit;
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
            $this->post['u_name'],
            $business_name,
            $website_url,
            (int) Session::get('user_id')
        );

        Session::set('first_name', $this->post['first_name']);
        Session::set('last_name', $this->post['last_name']);
        Session::set('user_email', $this->post['user_email']);
        Session::set('user_phone', $user_phone);
        Session::set('u_name', $this->post['u_name']);
        Session::set('business_name', $business_name);
        Session::set('website_url', $website_url);

        $response['success'] = true;
        $response['message'] = 'Your profile has been updated';
        echo json_encode($response);
        exit;
    }

    public function delete_my_accountAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $this->userModel->delete_account((int) Session::get('user_id'), (int) Session::get('user_id'));
        Main::do_logout();

        $response['success'] = true;
        $response['message'] = 'Your account has been deleted';
        echo json_encode($response);
        exit;
    }

    public function create_subscriptionAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['price_id'])) {
            $response['message'] = 'Please choose a plan';
            echo json_encode($response);
            exit;
        }

        try {
            $stripe  = StripeService::client();
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];

            $customer_id = $user['stripe_customer_id'] ?? '';
            if (empty($customer_id)) {
                $customer = $stripe->customers->create(array(
                    'email'    => $user['user_email'],
                    'name'     => trim($user['first_name'] . ' ' . $user['last_name']),
                    'metadata' => array('user_id' => (string) $user_id),
                ));
                $customer_id = $customer->id;
                $this->billingModel->set_customer_id($user_id, $customer_id);
            }

            $subscription = $stripe->subscriptions->create(array(
                'customer'         => $customer_id,
                'items'            => array(array('price' => $this->post['price_id'])),
                'payment_behavior' => 'default_incomplete',
                'payment_settings' => array('save_default_payment_method' => 'on_subscription'),
                'expand'           => array('latest_invoice.confirmation_secret'),
            ));

            $client_secret = $subscription->latest_invoice->confirmation_secret->client_secret ?? null;
            if (empty($client_secret)) {
                $response['message'] = 'Could not initialize payment';
                echo json_encode($response);
                exit;
            }

            $period_end = $subscription->items->data[0]->current_period_end ?? null;
            $this->billingModel->save_subscription($user_id, $subscription->id, $this->post['price_id'], $subscription->status, $period_end, $subscription->cancel_at_period_end ? 1 : 0);

            $response['success']         = true;
            $response['client_secret']   = $client_secret;
            $response['subscription_id'] = $subscription->id;
            $response['message']         = 'Subscription started';
            echo json_encode($response);
            exit;

        } catch (\Throwable $e) {
            error_log('[stripe] create_subscription: ' . $e->getMessage());
            $response['message'] = 'Could not start the subscription. Please try again.';
            echo json_encode($response);
            exit;
        }
    }

    public function sync_subscriptionAction(){

        $response = array('success' => false, 'message' => 'Something went wrong', 'status' => '');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        try {
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];
            $sub_id  = $user['stripe_subscription_id'] ?? '';

            if (empty($sub_id)) {
                $response['success'] = true;
                $response['status']  = '';
                echo json_encode($response);
                exit;
            }

            $stripe       = StripeService::client();
            $subscription = $stripe->subscriptions->retrieve($sub_id);
            $price_id     = $subscription->items->data[0]->price->id ?? ($user['stripe_price_id'] ?? '');
            $period_end   = $subscription->items->data[0]->current_period_end ?? null;

            $this->billingModel->save_subscription($user_id, $subscription->id, $price_id, $subscription->status, $period_end, $subscription->cancel_at_period_end ? 1 : 0);

            $response['success'] = true;
            $response['status']  = $subscription->status;
            $response['message'] = 'Subscription updated';
            echo json_encode($response);
            exit;

        } catch (\Throwable $e) {
            error_log('[stripe] sync_subscription: ' . $e->getMessage());
            $response['message'] = 'Could not refresh subscription';
            echo json_encode($response);
            exit;
        }
    }

    public function cancel_subscriptionAction(){
        echo json_encode($this->set_cancel_at_period_end(true, 'Your subscription will cancel at the end of the period'));
        exit;
    }

    public function resume_subscriptionAction(){
        echo json_encode($this->set_cancel_at_period_end(false, 'Your subscription has been resumed'));
        exit;
    }

    public function cancel_now_subscriptionAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        try {
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];
            $sub_id  = $user['stripe_subscription_id'] ?? '';

            if (empty($sub_id)) {
                $response['message'] = 'No active subscription';
                echo json_encode($response);
                exit;
            }

            StripeService::client()->subscriptions->cancel($sub_id);
            $this->billingModel->clear_subscription($user_id);

            $response['success'] = true;
            $response['message'] = 'Your subscription has been canceled';
            echo json_encode($response);
            exit;

        } catch (\Throwable $e) {
            error_log('[stripe] cancel_now_subscription: ' . $e->getMessage());
            $response['message'] = 'Could not cancel the subscription. Please try again.';
            echo json_encode($response);
            exit;
        }
    }

    private function set_cancel_at_period_end($cancel, $success_message){
        $response = array('success' => false, 'message' => 'Something went wrong');

        if (empty(Session::get('user_id'))) {
            $response['message'] = 'Not authorized';
            return $response;
        }

        try {
            $user_id = (int) Session::get('user_id');
            $user    = $this->userModel->get_user_by_id($user_id)[0];
            $sub_id  = $user['stripe_subscription_id'] ?? '';

            if (empty($sub_id)) {
                $response['message'] = 'No active subscription';
                return $response;
            }

            $stripe       = StripeService::client();
            $subscription = $stripe->subscriptions->update($sub_id, array('cancel_at_period_end' => (bool) $cancel));
            $price_id     = $subscription->items->data[0]->price->id ?? ($user['stripe_price_id'] ?? '');
            $period_end   = $subscription->items->data[0]->current_period_end ?? null;

            $this->billingModel->save_subscription($user_id, $subscription->id, $price_id, $subscription->status, $period_end, $subscription->cancel_at_period_end ? 1 : 0);

            $response['success'] = true;
            $response['message'] = $success_message;
            return $response;

        } catch (\Throwable $e) {
            error_log('[stripe] set_cancel_at_period_end: ' . $e->getMessage());
            $response['message'] = 'Could not update the subscription. Please try again.';
            return $response;
        }
    }

    /* ---------- Social publishing (Post for Me) ---------- */

    /** Auth + plan gate for all social endpoints. Returns the user array or exits with a JSON error. */
    private function social_user(){
        if (empty(Session::get('user_id'))) {
            echo json_encode(array('success' => false, 'message' => 'Not authorized'));
            exit;
        }
        $user = $this->userModel->get_user_by_id((int) Session::get('user_id'));
        $user = (is_array($user) && count($user) === 1) ? $user[0] : null;
        if (!$user || !Plan::can_social_post($user)) {
            echo json_encode(array('success' => false, 'message' => 'Your plan does not include social posting'));
            exit;
        }
        return $user;
    }

    public function connect_accountAction(){
        $user = $this->social_user();
        $platform = $this->post['platform'] ?? '';
        if ($platform === '') {
            echo json_encode(array('success' => false, 'message' => 'Platform is required'));
            exit;
        }
        // Some platforms need extra connection data (keyed by platform).
        // Instagram: connection_type ("instagram" = Login with Instagram, "facebook" = via a linked Page).
        // LinkedIn: connection_type "organization" is required when using Post for Me's provided credentials.
        $platform_data = null;
        if ($platform === 'instagram') {
            $platform_data = array('instagram' => array('connection_type' => 'instagram'));
        } elseif ($platform === 'linkedin') {
            $platform_data = array('linkedin' => array('connection_type' => 'organization'));
        }
        $url = PostForMeService::create_auth_url($platform, (int) $user['user_id'], array('posts'), $platform_data);
        if ($url === '') {
            echo json_encode(array('success' => false, 'message' => 'Could not start the connection. Please try again.'));
            exit;
        }
        echo json_encode(array('success' => true, 'url' => $url));
        exit;
    }

    public function disconnect_accountAction(){
        $user   = $this->social_user();
        $pfm_id = $this->post['account_id'] ?? '';
        if ($pfm_id === '') {
            echo json_encode(array('success' => false, 'message' => 'Account is required'));
            exit;
        }
        $accountsModel = new SocialAccountsModel();
        $acct = $accountsModel->get_by_pfm_id($pfm_id);
        if (!$acct || (int) $acct['user_id'] !== (int) $user['user_id']) {
            echo json_encode(array('success' => false, 'message' => 'Account not found'));
            exit;
        }
        PostForMeService::disconnect($pfm_id);
        $accountsModel->mark_disconnected((int) $user['user_id'], $pfm_id);
        echo json_encode(array('success' => true, 'message' => 'Account disconnected'));
        exit;
    }

    public function upload_media_urlAction(){
        $this->social_user();
        $res = PostForMeService::create_upload_url();
        if (!$res) {
            echo json_encode(array('success' => false, 'message' => 'Could not prepare the upload'));
            exit;
        }
        echo json_encode(array('success' => true, 'media_url' => $res[0], 'upload_url' => $res[1]));
        exit;
    }

    public function create_postAction(){
        $user     = $this->social_user();
        $caption  = $this->post['caption'] ?? '';
        $ids      = $this->post['social_account_ids'] ?? array();
        $media    = $this->post['media_url'] ?? '';
        $schedule = $this->post['schedule'] ?? 'now';
        $when     = $this->post['scheduled_at'] ?? '';

        if (!is_array($ids) || count($ids) === 0) {
            echo json_encode(array('success' => false, 'message' => 'Select at least one connected account'));
            exit;
        }
        if (trim($caption) === '' && $media === '') {
            echo json_encode(array('success' => false, 'message' => 'Add a caption or media'));
            exit;
        }

        // Only allow this user's currently-connected accounts.
        $accountsModel = new SocialAccountsModel();
        $valid = array();
        foreach ($accountsModel->get_connected_for_user((int) $user['user_id']) as $c) {
            $valid[$c['post_for_me_social_account_id']] = true;
        }
        $target = array();
        foreach ($ids as $id) {
            if (isset($valid[$id])) { $target[] = $id; }
        }
        if (count($target) === 0) {
            echo json_encode(array('success' => false, 'message' => 'No connected accounts selected'));
            exit;
        }

        $sched = null;
        if ($schedule === 'later' && $when !== '') {
            $ts = strtotime($when);
            if ($ts) { $sched = date('c', $ts); }
        }
        $media_urls = ($media !== '') ? array($media) : array();

        $post = PostForMeService::create_post($target, $caption, $media_urls, $sched);
        if (!$post || empty($post['id'])) {
            echo json_encode(array('success' => false, 'message' => 'Could not create the post. Please try again.'));
            exit;
        }

        $postsModel = new SocialPostsModel();
        $postsModel->create((int) $user['user_id'], $post['id'], $caption, $post['status'] ?? 'processing', $sched, $target);

        echo json_encode(array(
            'success' => true,
            'post_id' => $post['id'],
            'status'  => $post['status'] ?? 'processing',
            'message' => $sched ? 'Post scheduled' : 'Post submitted',
        ));
        exit;
    }

    public function post_statusAction(){
        $this->social_user();
        $pfm_post_id = $this->post['post_id'] ?? '';
        if ($pfm_post_id === '') {
            echo json_encode(array('success' => false, 'message' => 'Post id is required'));
            exit;
        }
        $post = PostForMeService::get_post($pfm_post_id);
        if (!$post) {
            echo json_encode(array('success' => false, 'message' => 'Post not found'));
            exit;
        }
        $status = $post['status'] ?? '';
        (new SocialPostsModel())->update_status($pfm_post_id, $status);
        echo json_encode(array('success' => true, 'status' => $status));
        exit;
    }

}
