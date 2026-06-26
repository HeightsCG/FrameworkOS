<?php
class AccountController extends Controller {

    public $protected = 1;
    private $userModel;

    public function __construct(){
        parent::__construct();
        $this->userModel = new UsersModel();
    }

    public function settingsAction(){
        $user = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (!is_array($user) || count($user) !== 1) {
            Header('Location: /');
            exit;
        }
        $user = $user[0];

        $can_post = Plan::can_social_post($user);

        $this->view->user            = $user;
        $this->view->can_social_post = $can_post;
        $this->view->platforms       = array('linkedin', 'bluesky', 'x', 'facebook', 'instagram', 'threads', 'tiktok', 'youtube', 'pinterest');
        $this->view->accounts        = array();
        $this->view->recent_posts    = array();

        if ($can_post) {
            $accountsModel = new SocialAccountsModel();
            $postsModel    = new SocialPostsModel();
            $this->view->accounts     = $accountsModel->get_for_user($user['user_id']);
            $this->view->recent_posts = $postsModel->get_recent_for_user($user['user_id']);
        }

        $this->view->render();
    }

    /**
     * Post for Me redirects the browser here after a connection attempt
     * (configure this URL as the Project Redirect URL in the PFM dashboard).
     */
    public function social_callbackAction(){
        if (Session::get('user_id') == 0) {
            Header('Location: /');
            exit;
        }
        $user_id = (int) Session::get('user_id');

        if (($_GET['isSuccess'] ?? '') === 'true') {
            // Re-list this user's accounts by external_id and upsert them locally.
            $accounts      = PostForMeService::get_accounts($user_id);
            $accountsModel = new SocialAccountsModel();
            foreach ($accounts as $acct) {
                if (!empty($acct['id'])) {
                    $accountsModel->upsert_from_pfm($user_id, $acct);
                }
            }
            Header('Location: /account/settings?section=connected&connected=1');
        } else {
            Header('Location: /account/settings?section=connected&error=1');
        }
        exit;
    }

    public function billingAction(){
        $user = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (is_array($user) && count($user) === 1) {
            $customer_id           = $user[0]['stripe_customer_id'] ?? '';
            $this->view->user      = $user[0];
            $this->view->plans     = StripeService::get_plans();
            $this->view->invoices  = StripeService::get_invoices($customer_id);
            $this->view->cards     = StripeService::get_payment_methods($customer_id);
            $this->view->stripe_pk = StripeService::publishable_key();
            $this->view->render();
        } else {
            Header('Location: /');
            exit;
        }
    }

    public function profileAction(){
        $user = $this->userModel->get_user_by_id(Session::get('user_id'));
        if (is_array($user) && count($user) === 1) {
            $this->view->user = $user[0];
            $this->view->render();
        } else {
            Header('Location: /');
            exit;
        }
        
    }

}
