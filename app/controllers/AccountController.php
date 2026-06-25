<?php
class AccountController extends Controller {

    public $protected = 1;
    private $userModel;

    public function __construct(){
        parent::__construct();
        $this->userModel = new UsersModel();
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
