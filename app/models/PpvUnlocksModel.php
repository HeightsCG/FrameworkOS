<?php
/**
 * Pay-per-view unlocks: a row per (fan, post) the fan has purchased. The unique
 * key (post_id, fan_id) makes the purchase idempotent and prevents double-charge.
 */
class PpvUnlocksModel extends Model {

    /** Map of post_id => true for posts this fan has unlocked (within a given set). */
    public function unlocked_map($fan_id, array $post_ids){
        $ids = array_filter(array_map('intval', $post_ids));
        if (!$fan_id || !$ids) { return array(); }
        $in = implode(',', $ids);
        $r  = parent::select("SELECT post_id FROM ppv_unlocks WHERE fan_id = :f AND post_id IN ($in)",
            array('f' => (int) $fan_id));
        $out = array();
        foreach ((array) $r as $row) { $out[(int) $row['post_id']] = true; }
        return $out;
    }

    public function has_unlocked($post_id, $fan_id){
        if (!$fan_id) { return false; }
        $r = parent::select("SELECT id FROM ppv_unlocks WHERE post_id = :p AND fan_id = :f LIMIT 1",
            array('p' => (int) $post_id, 'f' => (int) $fan_id));
        return is_array($r) && count($r) >= 1;
    }

    /** Insert an unlock. Returns true on success, false if it already existed (unique key). */
    public function record($post_id, $creator_id, $fan_id, $price_credits){
        try {
            parent::insert('ppv_unlocks', array(
                'post_id'       => (int) $post_id,
                'creator_id'    => (int) $creator_id,
                'fan_id'        => (int) $fan_id,
                'price_credits' => (int) $price_credits,
                'created_at'    => date('Y-m-d H:i:s'),
            ));
            return true;
        } catch (\Throwable $e) {
            return false; // duplicate (post_id, fan_id) — already unlocked
        }
    }

    /** Remove an unlock (used to roll back if the credit charge fails after insert). */
    public function remove($post_id, $fan_id){
        return parent::delete_all('ppv_unlocks', 'post_id = :p AND fan_id = :f',
            array('p' => (int) $post_id, 'f' => (int) $fan_id));
    }

    /** Per-post {unlocks, credits} for a set of posts (posts list / stats). */
    public function stats_for_posts(array $post_ids){
        $ids = array_filter(array_map('intval', $post_ids));
        if (!$ids) { return array(); }
        $in = implode(',', $ids);
        $r  = parent::select(
            "SELECT post_id, COUNT(*) unlocks, COALESCE(SUM(price_credits),0) credits
             FROM ppv_unlocks WHERE post_id IN ($in) GROUP BY post_id");
        $out = array();
        foreach ((array) $r as $row) {
            $out[(int) $row['post_id']] = array('unlocks' => (int) $row['unlocks'], 'credits' => (int) $row['credits']);
        }
        return $out;
    }
}
