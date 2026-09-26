<?php
/**
 * Creator-owned promo / discount codes. A code is a percent-off applied at
 * checkout — to subscriptions (Stripe coupon on the creator's connected account),
 * to PPV unlocks (app-level credit discount), or both. Codes are a Pro+ feature
 * (gated at the API), scoped per creator, and unique per creator.
 */
class CreatorPromoCodesModel extends Model {

    /** All codes for a creator, newest first (for the settings manager). */
    public function get_for_user($user_id){
        return parent::select(
            "SELECT * FROM creator_promo_codes WHERE user_id = :u ORDER BY id DESC",
            array('u' => (int) $user_id)
        );
    }

    /** One code owned by the creator (for edit/delete). */
    public function get_owned($user_id, $id){
        $rows = parent::select(
            "SELECT * FROM creator_promo_codes WHERE id = :id AND user_id = :u",
            array('id' => (int) $id, 'u' => (int) $user_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /**
     * A redeemable code for a creator: active, not expired, redemptions left, and
     * scoped to the given context ('subscription'|'ppv'). Returns the row or null.
     */
    public function get_redeemable($creator_id, $code, $context){
        $code = strtoupper(trim((string) $code));
        if ($code === '') { return null; }
        $rows = parent::select(
            "SELECT * FROM creator_promo_codes
             WHERE user_id = :u AND code = :c AND is_active = 1
               AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
               AND (max_redemptions IS NULL OR redemptions < max_redemptions)
               AND (applies_to = 'all' OR applies_to = :ctx)
             LIMIT 1",
            array('u' => (int) $creator_id, 'c' => $code, 'ctx' => (string) $context)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function add($user_id, $fields){
        $now = date('Y-m-d H:i:s');
        return parent::insert('creator_promo_codes', array(
            'user_id'         => (int) $user_id,
            'code'            => (string) $fields['code'],
            'percent_off'     => (int) $fields['percent_off'],
            'applies_to'      => (string) $fields['applies_to'],
            'max_redemptions' => isset($fields['max_redemptions']) && $fields['max_redemptions'] !== null ? (int) $fields['max_redemptions'] : null,
            'expires_at'      => !empty($fields['expires_at']) ? (string) $fields['expires_at'] : null,
            'is_active'       => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ));
    }

    public function update_code($user_id, $id, $fields){
        return parent::update(
            'creator_promo_codes',
            array(
                'code'            => (string) $fields['code'],
                'percent_off'     => (int) $fields['percent_off'],
                'applies_to'      => (string) $fields['applies_to'],
                'max_redemptions' => isset($fields['max_redemptions']) && $fields['max_redemptions'] !== null ? (int) $fields['max_redemptions'] : null,
                'expires_at'      => !empty($fields['expires_at']) ? (string) $fields['expires_at'] : null,
                // An edited discount can't reuse a stale Stripe coupon — recreated on next use.
                'stripe_coupon_id'=> null,
                'updated_at'      => date('Y-m-d H:i:s'),
            ),
            'id = :id AND user_id = :u',
            array('id' => (int) $id, 'u' => (int) $user_id)
        );
    }

    public function set_active($user_id, $id, $active){
        return parent::update(
            'creator_promo_codes',
            array('is_active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND user_id = :u',
            array('id' => (int) $id, 'u' => (int) $user_id)
        );
    }

    public function delete_code($user_id, $id){
        return parent::delete(
            'creator_promo_codes',
            'id = :id AND user_id = :u',
            1,
            array('id' => (int) $id, 'u' => (int) $user_id)
        );
    }

    /** Count a redemption, respecting max_redemptions (the WHERE is the guard). */
    /** Use one redemption; true only when a slot was left (the conditional update is the mutex against max_redemptions). */
    public function redeem($id){
        $sth = $this->db->prepare(
            "UPDATE creator_promo_codes
             SET redemptions = redemptions + 1, updated_at = :now
             WHERE id = :id AND (max_redemptions IS NULL OR redemptions < max_redemptions)");
        $sth->execute(array(':id' => (int) $id, ':now' => date('Y-m-d H:i:s')));
        return $sth->rowCount() > 0;
    }

    /** Give a redemption back (the purchase it was taken for didn't go through). */
    public function unredeem($id){
        return parent::sql("UPDATE creator_promo_codes SET redemptions = redemptions - 1 WHERE id = :id AND redemptions > 0", array(':id' => (int) $id));
    }

    public function set_stripe_coupon($id, $coupon_id){
        return parent::update(
            'creator_promo_codes',
            array('stripe_coupon_id' => (string) $coupon_id, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id',
            array('id' => (int) $id)
        );
    }
}
