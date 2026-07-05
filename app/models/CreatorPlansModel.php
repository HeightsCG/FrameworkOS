<?php
/**
 * Creator membership plans (subscription tiers). Local source of truth; the
 * stripe_product_id / stripe_price_id columns get populated when the subscribe
 * checkout flow (Stripe Connect) is built.
 */
class CreatorPlansModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** All plans for a creator, in display order (for the settings manager). */
    public function get_for_user($user_id){
        return parent::select(
            "SELECT id, name, price_cents, currency, billing_interval, description, perks, is_active, sort_order
             FROM creator_plans
             WHERE user_id = :user_id
             ORDER BY sort_order ASC, id ASC",
            array('user_id' => (int) $user_id)
        );
    }

    /** Active plans only (for the public profile). */
    public function get_active_for_user($user_id){
        return parent::select(
            "SELECT id, name, price_cents, currency, billing_interval, description, perks
             FROM creator_plans
             WHERE user_id = :user_id AND is_active = 1
             ORDER BY sort_order ASC, id ASC",
            array('user_id' => (int) $user_id)
        );
    }

    /** A plan by id regardless of owner or active state (for webhook recording). */
    public function get_by_id($id){
        $rows = parent::select(
            "SELECT * FROM creator_plans WHERE id = :id",
            array('id' => (int) $id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** A single active plan by id, regardless of owner (for the public subscribe flow). */
    public function get_public($id){
        $rows = parent::select(
            "SELECT * FROM creator_plans WHERE id = :id AND is_active = 1",
            array('id' => (int) $id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function get_one($user_id, $id){
        $rows = parent::select(
            "SELECT * FROM creator_plans WHERE id = :id AND user_id = :user_id",
            array('id' => (int) $id, 'user_id' => (int) $user_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function add($user_id, $fields){
        $now = date('Y-m-d H:i:s');
        $max = parent::select(
            "SELECT COALESCE(MAX(sort_order), -1) AS m FROM creator_plans WHERE user_id = :user_id",
            array('user_id' => (int) $user_id)
        );
        $next = (is_array($max) && count($max) === 1) ? ((int) $max[0]['m'] + 1) : 0;

        return parent::insert('creator_plans', array(
            'user_id'          => (int) $user_id,
            'name'             => (string) $fields['name'],
            'price_cents'      => (int) $fields['price_cents'],
            'currency'         => 'usd',
            'billing_interval' => (string) $fields['billing_interval'],
            'description'      => (string) ($fields['description'] ?? ''),
            'perks'            => (string) ($fields['perks'] ?? ''),
            'is_active'        => 1,
            'sort_order'       => $next,
            'created_at'       => $now,
            'updated_at'       => $now,
        ));
    }

    public function update_plan($user_id, $id, $fields){
        // Clear the cached Stripe price/product: an edited amount or interval must
        // never reuse a stale (immutable) Stripe price — it's recreated on next subscribe.
        return parent::update(
            'creator_plans',
            array(
                'name'              => (string) $fields['name'],
                'price_cents'       => (int) $fields['price_cents'],
                'billing_interval'  => (string) $fields['billing_interval'],
                'description'       => (string) ($fields['description'] ?? ''),
                'perks'             => (string) ($fields['perks'] ?? ''),
                'stripe_product_id' => null,
                'stripe_price_id'   => null,
                'updated_at'        => date('Y-m-d H:i:s'),
            ),
            'id = :id AND user_id = :user_id',
            array('id' => (int) $id, 'user_id' => (int) $user_id)
        );
    }

    /** Cache the Stripe product/price ids created for a plan. */
    public function set_stripe_ids($id, $product_id, $price_id){
        return parent::update(
            'creator_plans',
            array('stripe_product_id' => $product_id, 'stripe_price_id' => $price_id, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id',
            array('id' => (int) $id)
        );
    }

    public function set_active($user_id, $id, $active){
        return parent::update(
            'creator_plans',
            array('is_active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND user_id = :user_id',
            array('id' => (int) $id, 'user_id' => (int) $user_id)
        );
    }

    public function delete_plan($user_id, $id){
        return parent::delete(
            'creator_plans',
            'id = :id AND user_id = :user_id',
            1,
            array('id' => (int) $id, 'user_id' => (int) $user_id)
        );
    }

    public function reorder($user_id, $ids){
        $now = date('Y-m-d H:i:s');
        $order = 0;
        foreach ($ids as $id) {
            parent::update(
                'creator_plans',
                array('sort_order' => $order, 'updated_at' => $now),
                'id = :id AND user_id = :user_id',
                array('id' => (int) $id, 'user_id' => (int) $user_id)
            );
            $order++;
        }
        return true;
    }
}
