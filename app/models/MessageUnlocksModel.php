<?php
/**
 * Paid unlocks of direct messages: a row per (fan, message) the fan has paid for.
 * UNIQUE (message_id, fan_id) makes the purchase idempotent and is the mutex against
 * a double charge from two concurrent clicks (record first, then debit).
 */
class MessageUnlocksModel extends Model {

    /** message_id => true for the messages this fan has unlocked (within a given set). */
    public function unlocked_map($fan_id, array $message_ids){
        $ids = array_filter(array_map('intval', $message_ids));
        if (!$fan_id || !$ids) { return array(); }
        $in = implode(',', $ids);
        $r  = parent::select("SELECT message_id FROM message_unlocks WHERE fan_id = :f AND message_id IN ($in)",
            array('f' => (int) $fan_id));
        $out = array();
        foreach ((array) $r as $row) { $out[(int) $row['message_id']] = true; }
        return $out;
    }

    public function has_unlocked($message_id, $fan_id){
        if (!$fan_id) { return false; }
        $r = parent::select("SELECT id FROM message_unlocks WHERE message_id = :m AND fan_id = :f LIMIT 1",
            array('m' => (int) $message_id, 'f' => (int) $fan_id));
        return is_array($r) && count($r) >= 1;
    }

    /** Insert an unlock. True on success, false if it already existed (unique key). */
    public function record($message_id, $creator_id, $fan_id, $price_credits){
        try {
            parent::insert('message_unlocks', array(
                'message_id'    => (int) $message_id,
                'creator_id'    => (int) $creator_id,
                'fan_id'        => (int) $fan_id,
                'price_credits' => (int) $price_credits,
                'created_at'    => date('Y-m-d H:i:s'),
            ));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Remove an unlock (charge failed after insert, or an admin refund). */
    /** What the creator earned on this unlock (refunds reverse exactly this). */
    public function set_net($message_id, $fan_id, $net){
        return parent::update('message_unlocks', array('net_credits' => max(0, (int) $net)), 'message_id = :m AND fan_id = :f', array('m' => (int) $message_id, 'f' => (int) $fan_id));
    }

    public function remove($message_id, $fan_id){
        return parent::delete_all('message_unlocks', 'message_id = :m AND fan_id = :f',
            array('m' => (int) $message_id, 'f' => (int) $fan_id));
    }

    /** A fan's paid DM unlocks with creator identity — for the Purchases page. */
    public function get_for_fan($fan_id){
        return parent::select(
            "SELECT mu.message_id, mu.price_credits, mu.created_at AS purchased_at,
                    m.body, m.media_count, mu.creator_id,
                    u.u_name AS creator_handle,
                    COALESCE(cp.display_name, CONCAT(u.first_name, ' ', u.last_name)) AS creator_name
             FROM message_unlocks mu
             JOIN messages m ON m.id = mu.message_id
             JOIN user_accounts u ON u.user_id = mu.creator_id AND u.deleted = 0
             LEFT JOIN creator_profiles cp ON cp.user_id = mu.creator_id
             WHERE mu.fan_id = :f
             ORDER BY mu.created_at DESC",
            array('f' => (int) $fan_id)
        );
    }

    /** Fans who have bought anything in DMs from a creator (broadcast 'buyers' segment). */
    public function buyer_ids($creator_id){
        $rows = parent::select("SELECT DISTINCT fan_id FROM message_unlocks WHERE creator_id = :c", array('c' => (int) $creator_id));
        $out = array();
        foreach ((array) $rows as $r) { $out[] = (int) $r['fan_id']; }
        return $out;
    }
}
