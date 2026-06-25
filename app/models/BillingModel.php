<?php
class BillingModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function set_customer_id($user_id, $customer_id){
        return parent::update(
            'user_accounts',
            array(
                'stripe_customer_id' => $customer_id,
                'updated_at'         => date('Y-m-d H:i:s'),
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    public function save_subscription($user_id, $subscription_id, $price_id, $status, $current_period_end, $cancel_at_period_end = 0){
        return parent::update(
            'user_accounts',
            array(
                'stripe_subscription_id'           => $subscription_id,
                'stripe_price_id'                  => $price_id,
                'subscription_status'              => $status,
                'subscription_current_period_end'  => $current_period_end ? date('Y-m-d H:i:s', (int) $current_period_end) : null,
                'subscription_cancel_at_period_end'=> $cancel_at_period_end ? 1 : 0,
                'updated_at'                       => date('Y-m-d H:i:s'),
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    public function clear_subscription($user_id){
        return parent::update(
            'user_accounts',
            array(
                'stripe_subscription_id'            => null,
                'stripe_price_id'                   => null,
                'subscription_status'               => null,
                'subscription_current_period_end'   => null,
                'subscription_cancel_at_period_end' => 0,
                'updated_at'                        => date('Y-m-d H:i:s'),
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

}
