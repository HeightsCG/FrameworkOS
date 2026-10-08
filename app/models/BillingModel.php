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

    /** Drop a Stripe customer id that Stripe no longer knows (e.g. a test-mode id on live); a new one is made when needed. */
    public function forget_customer_id($customer_id){
        return parent::update('user_accounts', array('stripe_customer_id' => null, 'updated_at' => date('Y-m-d H:i:s')),
            'stripe_customer_id = :c', array('c' => (string) $customer_id));
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
        $res = parent::update(
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
        if (!in_array((string) $status, array('active', 'trialing', 'past_due'), true)) { PublicThumbService::queue_purge(array('creator' => (int) $user_id)); }   // no selling plan: no public copies
        Founding::on_plan_mirror((int) $user_id, $tier, $status);   // left the Creator plan: founding terms end
        return $res;
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

    /** Accounts the plan mirror calls paid (user_accounts.subscription_status) — for the stale-plan cleanup. */
    public function users_marked_paid(){
        $r = parent::select("SELECT user_id FROM user_accounts WHERE subscription_status IN ('active', 'trialing', 'past_due') AND deleted = 0");
        return array_map('intval', array_column((array) $r, 'user_id'));
    }

}
