<?php
/**
 * A fan's membership to a creator's plan. Free memberships are recorded
 * immediately (no Stripe); paid memberships carry the Stripe subscription id
 * and period once the checkout flow is built. Also the source for the fan's
 * "My subscriptions" view.
 */
class CreatorSubscriptionsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function is_subscribed_to_plan($subscriber_id, $plan_id){
        $rows = parent::select(
            "SELECT id FROM creator_subscriptions
             WHERE subscriber_id = :s AND plan_id = :p AND status = 'active'",
            array('s' => (int) $subscriber_id, 'p' => (int) $plan_id)
        );
        return is_array($rows) && count($rows) === 1;
    }

    /**
     * The price of the fan's highest active tier for a creator, or null if they
     * have no active membership. Drives the subscriber-content tier hierarchy:
     * a fan is entitled to an item when this value >= the item's required tier price.
     */
    public function max_active_tier_price($subscriber_id, $creator_id){
        $rows = parent::select(
            "SELECT MAX(cp.price_cents) AS mx
             FROM creator_subscriptions cs
             JOIN creator_plans cp ON cp.id = cs.plan_id
             WHERE cs.subscriber_id = :s AND cs.creator_id = :c AND cs.status = 'active'",
            array('s' => (int) $subscriber_id, 'c' => (int) $creator_id)
        );
        if (is_array($rows) && count($rows) && $rows[0]['mx'] !== null) {
            return (int) $rows[0]['mx'];
        }
        return null;
    }

    /** Does this fan already hold an active PAID membership to this creator (any tier)? */
    public function has_active_paid_for_creator($subscriber_id, $creator_id){
        $rows = parent::select(
            "SELECT id FROM creator_subscriptions
             WHERE subscriber_id = :s AND creator_id = :c AND status = 'active' AND is_free = 0
             LIMIT 1",
            array('s' => (int) $subscriber_id, 'c' => (int) $creator_id)
        );
        return is_array($rows) && count($rows) === 1;
    }

    /** Active membership rows (free or paid) a fan holds with one creator — closed out when either blocks the other. */
    public function active_between($subscriber_id, $creator_id){
        return (array) parent::select(
            "SELECT * FROM creator_subscriptions
             WHERE subscriber_id = :s AND creator_id = :c AND status = 'active'",
            array('s' => (int) $subscriber_id, 'c' => (int) $creator_id)
        );
    }

    /** Plan ids this fan has an active membership to for a given creator. */
    public function active_plan_ids($subscriber_id, $creator_id){
        $rows = parent::select(
            "SELECT plan_id FROM creator_subscriptions
             WHERE subscriber_id = :s AND creator_id = :c AND status = 'active'",
            array('s' => (int) $subscriber_id, 'c' => (int) $creator_id)
        );
        $ids = array();
        if (is_array($rows)) {
            foreach ($rows as $r) { $ids[] = (int) $r['plan_id']; }
        }
        return $ids;
    }

    /** Record a free membership (idempotent — reactivates a canceled one). */
    public function join_free($subscriber_id, $creator_id, $plan){
        $now = date('Y-m-d H:i:s');
        $existing = parent::select(
            "SELECT id, stripe_subscription_id FROM creator_subscriptions WHERE subscriber_id = :s AND plan_id = :p",
            array('s' => (int) $subscriber_id, 'p' => (int) $plan['id'])
        );
        if (is_array($existing) && count($existing) === 1) {
            return parent::update(
                'creator_subscriptions',
                array('status' => 'active', 'canceled_at' => null, 'updated_at' => $now),
                'id = :id',
                array('id' => (int) $existing[0]['id'])
            );
        }
        return parent::insert('creator_subscriptions', array(
            'subscriber_id'    => (int) $subscriber_id,
            'creator_id'       => (int) $creator_id,
            'plan_id'          => (int) $plan['id'],
            'status'           => 'active',
            'is_free'          => 1,
            'price_cents'      => 0,
            'billing_interval' => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ));
    }

    /** Record (or reactivate) a paid membership after a successful checkout. Idempotent by stripe_subscription_id. */
    public function record_paid($subscriber_id, $creator_id, $plan, $stripe){
        $now = date('Y-m-d H:i:s');
        $period_end = !empty($stripe['current_period_end']) ? date('Y-m-d H:i:s', (int) $stripe['current_period_end']) : null;

        // Free trial: the end date is the signup date plus the plan's value + unit
        // (e.g. "+7 day", "+2 week", "+1 month") — calculated once, here, and stored.
        $trial_ends_at = null;
        if (!empty($plan['trial_enabled']) && (int) ($plan['trial_value'] ?? 0) > 0) {
            $tu = in_array(($plan['trial_unit'] ?? 'day'), array('day', 'week', 'month'), true) ? $plan['trial_unit'] : 'day';
            $trial_ends_at = date('Y-m-d H:i:s', strtotime('+' . (int) $plan['trial_value'] . ' ' . $tu));
        }

        $existing = parent::select(
            "SELECT id FROM creator_subscriptions WHERE subscriber_id = :s AND plan_id = :p",
            array('s' => (int) $subscriber_id, 'p' => (int) $plan['id'])
        );
        $data = array(
            'status'                 => 'active',
            'is_free'                => 0,
            'price_cents'            => (int) $plan['price_cents'],
            'billing_interval'       => (string) $plan['billing_interval'],
            'stripe_subscription_id' => (string) ($stripe['subscription_id'] ?? ''),
            'stripe_customer_id'     => (string) ($stripe['customer_id'] ?? ''),
            'current_period_end'     => $period_end,
            'trial_ends_at'          => $trial_ends_at,
            'cancel_at_period_end'   => 0,
            'canceled_at'            => null,
            'updated_at'             => $now,
        );
        if (is_array($existing) && count($existing) === 1) {
            // A new Stripe subscription (they came back) gets its own welcome notices: reset the once-only claim.
            if ((string) $existing[0]['stripe_subscription_id'] !== $data['stripe_subscription_id']) { $data['checkout_recorded'] = 0; }
            return parent::update('creator_subscriptions', $data, 'id = :id', array('id' => (int) $existing[0]['id']));
        }
        $data['subscriber_id'] = (int) $subscriber_id;
        $data['creator_id']    = (int) $creator_id;
        $data['plan_id']       = (int) $plan['id'];
        $data['created_at']    = $now;
        return parent::insert('creator_subscriptions', $data);
    }

    public function exists_by_stripe_id($stripe_subscription_id){
        $rows = parent::select(
            "SELECT id FROM creator_subscriptions WHERE stripe_subscription_id = :sid LIMIT 1",
            array('sid' => (string) $stripe_subscription_id)
        );
        return is_array($rows) && count($rows) === 1;
    }

    /** The row for a Stripe subscription id (with the plan name), or null. */
    public function get_by_stripe_id($stripe_subscription_id){
        $rows = parent::select(
            "SELECT cs.*, p.name AS plan_name FROM creator_subscriptions cs LEFT JOIN creator_plans p ON p.id = cs.plan_id
             WHERE cs.stripe_subscription_id = :sid LIMIT 1", array('sid' => (string) $stripe_subscription_id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** True exactly once per Stripe subscription: the first success-page visit that records it. */
    public function claim_checkout_recorded($stripe_subscription_id){
        if ((string) $stripe_subscription_id === '') { return false; }
        return parent::update('creator_subscriptions', array('checkout_recorded' => 1),
            'stripe_subscription_id = :sid AND checkout_recorded = 0', array('sid' => (string) $stripe_subscription_id)) > 0;
    }

    /** Update a subscription's lifecycle from a Stripe webhook (by stripe_subscription_id). */
    public function update_by_stripe_id($stripe_subscription_id, $status, $current_period_end = null, $cancel_at_period_end = 0){
        $data = array('status' => $status, 'updated_at' => date('Y-m-d H:i:s'), 'cancel_at_period_end' => $cancel_at_period_end ? 1 : 0);
        if ($current_period_end) {
            $data['current_period_end'] = date('Y-m-d H:i:s', (int) $current_period_end);
        }
        if ($status === 'canceled') {
            $data['canceled_at'] = date('Y-m-d H:i:s');
        }
        return parent::update(
            'creator_subscriptions',
            $data,
            'stripe_subscription_id = :sid',
            array('sid' => (string) $stripe_subscription_id)
        );
    }

    /** A subscription scoped to its owner (the subscriber), or null. */
    public function get_owned($subscriber_id, $id){
        $rows = parent::select(
            "SELECT * FROM creator_subscriptions WHERE id = :id AND subscriber_id = :s",
            array('id' => (int) $id, 's' => (int) $subscriber_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function set_status($subscriber_id, $id, $status){
        $data = array('status' => $status, 'updated_at' => date('Y-m-d H:i:s'));
        if ($status === 'canceled') {
            $data['canceled_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'active') {
            $data['canceled_at'] = null;
        }
        return parent::update('creator_subscriptions', $data, 'id = :id AND subscriber_id = :s',
            array('id' => (int) $id, 's' => (int) $subscriber_id));
    }

    public function set_cancel_at_period_end($subscriber_id, $id, $cancel){
        return parent::update('creator_subscriptions',
            array('cancel_at_period_end' => $cancel ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND subscriber_id = :s',
            array('id' => (int) $id, 's' => (int) $subscriber_id));
    }

    public function cancel($subscriber_id, $id){
        return parent::update(
            'creator_subscriptions',
            array('status' => 'canceled', 'canceled_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND subscriber_id = :s',
            array('id' => (int) $id, 's' => (int) $subscriber_id)
        );
    }

    /** The fan's memberships, with creator + plan detail, for "My subscriptions". */
    public function get_for_subscriber($subscriber_id){
        return parent::select(
            "SELECT cs.*, p.name AS plan_name, u.u_name AS creator_handle,
                    COALESCE(cp.display_name, CONCAT(u.first_name, ' ', u.last_name)) AS creator_name
             FROM creator_subscriptions cs
             JOIN creator_plans p ON p.id = cs.plan_id
             JOIN user_accounts u ON u.user_id = cs.creator_id AND u.deleted = 0
             LEFT JOIN creator_profiles cp ON cp.user_id = cs.creator_id
             WHERE cs.subscriber_id = :s
             ORDER BY cs.status = 'active' DESC, cs.created_at DESC",
            array('s' => (int) $subscriber_id)
        );
    }

    public function count_members($creator_id){
        $rows = parent::select(
            "SELECT COUNT(*) AS c FROM creator_subscriptions WHERE creator_id = :c AND status = 'active'",
            array('c' => (int) $creator_id)
        );
        return is_array($rows) && count($rows) ? (int) $rows[0]['c'] : 0;
    }
}
