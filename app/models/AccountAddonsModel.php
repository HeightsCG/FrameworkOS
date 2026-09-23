<?php
/**
 * Plan add-ons an account holds (account_addons): extra capacity billed as a quantity line item
 * on the platform Stripe subscription. Definitions (price, max, which plans) live in
 * PlanTiers::ADDONS; this table only records quantities.
 */
class AccountAddonsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function get($user_id, $addon_key){
        $r = parent::select("SELECT * FROM account_addons WHERE user_id = :u AND addon_key = :k",
            array('u' => (int) $user_id, 'k' => (string) $addon_key));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Insert or replace the row's quantities; $fields may hold quantity, quantity_next, next_at, stripe_item_id. */
    public function save($user_id, $addon_key, array $fields){
        $row = $this->get($user_id, $addon_key);
        if (!$row) {
            $fields['user_id'] = (int) $user_id; $fields['addon_key'] = (string) $addon_key;
            return (int) parent::insert('account_addons', $fields) > 0;
        }
        return parent::update('account_addons', $fields, 'id = :id', array('id' => (int) $row['id'])) >= 0;
    }

    /** Forget every add-on on the account (subscription ended, or moved to a plan that takes none). */
    public function clear($user_id, $addon_key = ''){
        if ((string) $addon_key !== '') {
            return parent::delete_all('account_addons', 'user_id = :u AND addon_key = :k', array('u' => (int) $user_id, 'k' => (string) $addon_key));
        }
        return parent::delete_all('account_addons', 'user_id = :u', array('u' => (int) $user_id));
    }
}
