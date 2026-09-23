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

    /**
     * Mirror of the app-managed plan (BillingService::mirror) so Plan:: checks stay column reads.
     * $tier/$status null = Free. The Stripe subscription columns are only history now.
     */
    public function save_plan_mirror($user_id, $tier, $status, $period_end, $cancel_at_period_end){
        return parent::update(
            'user_accounts',
            array(
                'plan_tier'                         => $tier,
                'subscription_status'               => $status,
                'subscription_current_period_end'   => $period_end,
                'subscription_cancel_at_period_end' => $cancel_at_period_end ? 1 : 0,
                'updated_at'                        => date('Y-m-d H:i:s'),
            ),
            'user_id = :user_id',
            array('user_id' => (int) $user_id)
        );
    }

    /** Accounts still billed by a Stripe subscription (cron/migrate_subscriptions.php). */
    public function users_with_stripe_subscription(){
        $r = parent::select("SELECT user_id FROM user_accounts WHERE stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> '' AND deleted = 0");
        return array_map('intval', array_column((array) $r, 'user_id'));
    }

    /** Forget the Stripe subscription once the app bills the account (billing_accounts keeps its id). */
    public function forget_stripe_subscription($user_id){
        return parent::update('user_accounts', array('stripe_subscription_id' => null, 'updated_at' => date('Y-m-d H:i:s')), 'user_id = :user_id', array('user_id' => (int) $user_id));
    }

}
