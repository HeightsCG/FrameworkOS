<?php
class ApiController extends Controller {

    public $protected = 1;
    private $userModel;
    private $rolesModel;
    private $companiesModel;

    public function __construct(){
        parent::__construct();
        $this->userModel      = new Users();
        $this->rolesModel     = new Roles();
        $this->companiesModel = new Companies();
    }

    public function registerAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        $u_name = isset($this->post['u_name']) ? trim((string) $this->post['u_name']) : '';
        $email  = isset($this->post['user_email']) ? trim((string) $this->post['user_email']) : '';

        if ($u_name === '') {
            $response['message'] = 'Username is required';
            echo json_encode($response);
            exit;
        }

        if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $u_name)) {
            $response['message'] = 'Username must be 3-30 characters: letters, numbers, or underscore';
            echo json_encode($response);
            exit;
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response['message'] = 'A valid email is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['p_word'])) {
            $response['message'] = 'Password is required';
            echo json_encode($response);
            exit;
        }

        // Reuse the existing framework password policy (no SQL).
        $check = $this->userModel->validate_password($this->post['p_word']);
        if (!$check['valid']) {
            $response['message'] = $check['message'];
            echo json_encode($response);
            exit;
        }

        // Throttle registration per source IP (existing RateLimit).
        $rate_key  = 'register:' . $this->get_ip_address();
        $rateLimit = new RateLimit();
        if ($rateLimit->is_locked($rate_key)) {
            $response['message'] = 'Too many attempts. Please try again later.';
            echo json_encode($response);
            exit;
        }
        $rateLimit->register_failure($rate_key, 10, 15);

        // All registration SQL lives in the product UsersModel.
        $usersModel = new UsersModel();
        if ($usersModel->username_exists($u_name) || $usersModel->email_exists($email)) {
            // Neutral — do not reveal which field is already taken.
            $response['message'] = 'Could not create an account with those details';
            echo json_encode($response);
            exit;
        }

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

        $rate_key  = 'login:' . strtolower($this->post['u_name']);
        $rateLimit = new RateLimit();
        if ($rateLimit->is_locked($rate_key)) {
            $response['message'] = 'Too many attempts. Please try again later.';
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_username($this->post['u_name']);
        if (!is_array($user_account) || count($user_account) !== 1
            || !password_verify($this->post['p_word'], $user_account[0]['p_word'])) {
            // Same message + rate hit whether the user exists or not (no enumeration).
            $rateLimit->register_failure($rate_key, 5, 15);
            $response['message'] = 'Invalid username or password';
            echo json_encode($response);
            exit;
        }

        $user = $user_account[0];

        $rateLimit->clear($rate_key);
        Session::regenerate();
        foreach ($user as $key => $value) {
            Session::set($key, $value);
        }

        $response['success']  = true;
        $response['message']  = 'Login successful';
        $response['reset_pw'] = (int) ($user['reset_pw'] ?? 0);
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

        // Throttle to blunt enumeration / email bombing.
        $rate_key  = 'forgot:' . strtolower($this->post['u_name']);
        $rateLimit = new RateLimit();
        if (!$rateLimit->is_locked($rate_key)) {
            $rateLimit->register_failure($rate_key, 3, 60);

            $user_account = $this->userModel->get_user_by_username($this->post['u_name']);
            if (is_array($user_account) && count($user_account) === 1) {
                $user  = $user_account[0];
                $token = $this->userModel->set_reset_token($user['user_id']);

                if (!empty($user['user_email'])) {
                    $reset_link = Main::get_base_domain() . '/account/reset?token=' . urlencode($token);
                    $to_name    = trim($user['first_name'] . ' ' . $user['last_name']);

                    $notificationsModel = new NotificationsModel();
                    $notificationsModel->send_password_reset_email($user['user_email'], $to_name, $reset_link);
                }
            }
        }

        // Always neutral — never reveal whether the account exists.
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

        $check = $this->userModel->validate_password($this->post['p_word']);
        if (!$check['valid']) {
            $response['message'] = $check['message'];
            echo json_encode($response);
            exit;
        }

        $user_account = $this->userModel->get_user_by_reset_token($this->post['reset_token']);
        if (!is_array($user_account) || count($user_account) !== 1) {
            $response['message'] = 'This reset link is invalid or has expired';
            echo json_encode($response);
            exit;
        }

        $user = $user_account[0];
        $this->userModel->change_password($user['user_id'], password_hash($this->post['p_word'], PASSWORD_DEFAULT));

        $response['success'] = true;
        $response['message'] = 'Your password has been updated';
        echo json_encode($response);
        exit;
    }

    public function change_passwordAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::is_logged_in()) {
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

        $user_id = (int) Session::get('user_id');

        if (!$this->userModel->verify_current_password($user_id, $this->post['current_password'])) {
            $response['message'] = 'Your current password is incorrect';
            echo json_encode($response);
            exit;
        }

        $check = $this->userModel->validate_password($this->post['p_word']);
        if (!$check['valid']) {
            $response['message'] = $check['message'];
            echo json_encode($response);
            exit;
        }

        $this->userModel->change_password($user_id, password_hash($this->post['p_word'], PASSWORD_DEFAULT));
        Session::set('reset_pw', 0); // release the force-change gate for this session

        $response['success'] = true;
        $response['message'] = 'Your password has been updated';
        echo json_encode($response);
        exit;
    }

    // ---- Users -------------------------------------------------------------

    public function get_usersAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $response['success'] = true;
        $response['message'] = '';
        $response['data']    = $this->userModel->get_all_users();
        echo json_encode($response);
        exit;
    }

    public function add_userAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

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

        $check = $this->userModel->validate_password($this->post['p_word']);
        if (!$check['valid']) {
            $response['message'] = $check['message'];
            echo json_encode($response);
            exit;
        }

        $data = array(
            'u_name'     => $this->post['u_name'],
            'p_word'     => password_hash($this->post['p_word'], PASSWORD_DEFAULT),
            'first_name' => $this->post['first_name'] ?? '',
            'last_name'  => $this->post['last_name'] ?? '',
            'user_email' => $this->post['user_email'] ?? '',
            'role_id'    => !empty($this->post['role_id']) ? (int) $this->post['role_id'] : null,
            'company_id' => !empty($this->post['company_id']) ? (int) $this->post['company_id'] : null,
            'created_by' => (int) Session::get('user_id'),
            'updated_by' => (int) Session::get('user_id'),
        );

        $this->userModel->add_user($data);

        $response['success'] = true;
        $response['message'] = 'User added';
        echo json_encode($response);
        exit;
    }

    public function update_userAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['user_id'])) {
            $response['message'] = 'User is required';
            echo json_encode($response);
            exit;
        }

        $data = array('updated_by' => (int) Session::get('user_id'));
        if (isset($this->post['u_name']))     { $data['u_name']     = $this->post['u_name']; }
        if (isset($this->post['first_name'])) { $data['first_name'] = $this->post['first_name']; }
        if (isset($this->post['last_name']))  { $data['last_name']  = $this->post['last_name']; }
        if (isset($this->post['user_email'])) { $data['user_email'] = $this->post['user_email']; }
        if (isset($this->post['role_id']))    { $data['role_id']    = !empty($this->post['role_id']) ? (int) $this->post['role_id'] : null; }
        if (isset($this->post['company_id'])) { $data['company_id'] = !empty($this->post['company_id']) ? (int) $this->post['company_id'] : null; }
        if (!empty($this->post['p_word'])) {
            $check = $this->userModel->validate_password($this->post['p_word']);
            if (!$check['valid']) {
                $response['message'] = $check['message'];
                echo json_encode($response);
                exit;
            }
            $data['p_word'] = password_hash($this->post['p_word'], PASSWORD_DEFAULT);
        }

        $this->userModel->update_user((int) $this->post['user_id'], $data);

        $response['success'] = true;
        $response['message'] = 'User updated';
        echo json_encode($response);
        exit;
    }

    public function delete_userAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['user_id'])) {
            $response['message'] = 'User is required';
            echo json_encode($response);
            exit;
        }

        $this->userModel->delete_user((int) $this->post['user_id']);

        $response['success'] = true;
        $response['message'] = 'User deleted';
        echo json_encode($response);
        exit;
    }

    // ---- Roles -------------------------------------------------------------

    public function get_rolesAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $response['success'] = true;
        $response['message'] = '';
        $response['data']    = $this->rolesModel->get_all_roles();
        echo json_encode($response);
        exit;
    }

    public function add_roleAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['role_name'])) {
            $response['message'] = 'Role name is required';
            echo json_encode($response);
            exit;
        }

        $data = array(
            'role_name'  => $this->post['role_name'],
            'created_by' => (int) Session::get('user_id'),
            'updated_by' => (int) Session::get('user_id'),
        );

        $this->rolesModel->add_role($data);

        $response['success'] = true;
        $response['message'] = 'Role added';
        echo json_encode($response);
        exit;
    }

    public function update_roleAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['id'])) {
            $response['message'] = 'Role is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['role_name'])) {
            $response['message'] = 'Role name is required';
            echo json_encode($response);
            exit;
        }

        $data = array(
            'role_name'  => $this->post['role_name'],
            'updated_by' => (int) Session::get('user_id'),
        );

        $this->rolesModel->update_role((int) $this->post['id'], $data);

        $response['success'] = true;
        $response['message'] = 'Role updated';
        echo json_encode($response);
        exit;
    }

    public function delete_roleAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['id'])) {
            $response['message'] = 'Role is required';
            echo json_encode($response);
            exit;
        }

        $this->rolesModel->delete_role((int) $this->post['id']);

        $response['success'] = true;
        $response['message'] = 'Role deleted';
        echo json_encode($response);
        exit;
    }

    // ---- Companies ---------------------------------------------------------

    public function get_companiesAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        $response['success'] = true;
        $response['message'] = '';
        $response['data']    = $this->companiesModel->get_all_companies();
        echo json_encode($response);
        exit;
    }

    public function add_companyAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['company_name'])) {
            $response['message'] = 'Company name is required';
            echo json_encode($response);
            exit;
        }

        $data = array(
            'company_name' => $this->post['company_name'],
            'created_by'   => (int) Session::get('user_id'),
            'updated_by'   => (int) Session::get('user_id'),
        );

        $this->companiesModel->add_company($data);

        $response['success'] = true;
        $response['message'] = 'Company added';
        echo json_encode($response);
        exit;
    }

    public function update_companyAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['id'])) {
            $response['message'] = 'Company is required';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['company_name'])) {
            $response['message'] = 'Company name is required';
            echo json_encode($response);
            exit;
        }

        $data = array(
            'company_name' => $this->post['company_name'],
            'updated_by'   => (int) Session::get('user_id'),
        );

        $this->companiesModel->update_company((int) $this->post['id'], $data);

        $response['success'] = true;
        $response['message'] = 'Company updated';
        echo json_encode($response);
        exit;
    }

    public function delete_companyAction(){

        $response = array('success' => false, 'message' => 'Something went wrong');

        if (!Permissions::has_role('admin')) {
            $response['message'] = 'Not authorized';
            echo json_encode($response);
            exit;
        }

        if (empty($this->post['id'])) {
            $response['message'] = 'Company is required';
            echo json_encode($response);
            exit;
        }

        $this->companiesModel->delete_company((int) $this->post['id']);

        $response['success'] = true;
        $response['message'] = 'Company deleted';
        echo json_encode($response);
        exit;
    }

}
