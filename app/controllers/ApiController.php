<?php
class ApiController extends Controller {

    public $protected = 1;
    private $userModel;
    private $notificationsModel;

    public function __construct(){
        parent::__construct();
        $this->userModel = new UsersModel();
        $this->notificationsModel = new NotificationsModel();
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

        $user_account = $this->userModel->get_user_by_username($this->post['u_name']);
        if (!is_array($user_account) || count($user_account) !== 1
            || !password_verify($this->post['p_word'], $user_account[0]['p_word'])) {
            $response['message'] = 'Invalid username or password';
            echo json_encode($response);
            exit;
        }

        $user = $user_account[0];

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

        $user_account = $this->userModel->get_user_by_username($this->post['u_name']);
        if (is_array($user_account) && count($user_account) === 1) {
            $user  = $user_account[0];
            $token = $this->userModel->set_reset_token($user['user_id']);

            if (!empty($user['user_email'])) {
                $reset_link = Main::get_base_domain() . '/account/reset?token=' . urlencode($token);
                $to_name    = trim($this->post['first_name'] . ' ' . $this->post['last_name']);
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

        $user_account = $this->userModel->get_user_by_reset_token($this->post['reset_token']);
        if (!is_array($user_account) || count($user_account) !== 1) {
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

        $user_id = (int) Session::get('user_id');

        if (!$this->userModel->verify_current_password($user_id, $this->post['current_password'])) {
            $response['message'] = 'Your current password is incorrect';
            echo json_encode($response);
            exit;
        }

        $enc_p_word = password_hash($this->post['p_word'], PASSWORD_DEFAULT);
        $this->userModel->change_password($user_id, $enc_p_word);
        Session::set('reset_pw', 0);

        $response['success'] = true;
        $response['message'] = 'Your password has been updated';
        echo json_encode($response);
        exit;
    }

}
