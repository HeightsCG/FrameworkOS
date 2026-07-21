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

    public function set_connect_account_id($user_id, $account_id){
        return parent::update(
            'user_accounts',
            array(
                'stripe_connect_account_id' => $account_id,
                'updated_at'                => date('Y-m-d H:i:s'),
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
                // Resolve + cache the code tier once here (at subscribe/renewal) so
                // entitlement checks are a plain column read, no Stripe call.
                'plan_tier'                        => StripeService::plan_tier_slug($price_id),
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
                'plan_tier'                         => null,
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
