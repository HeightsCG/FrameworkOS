<?php
/**
 * One-time content purchases (PRD §16.3). Ownership persists; unique per
 * (user, content) makes unlocking idempotent and prevents double-charge.
 */
class ContentUnlocksModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function is_unlocked($user_id, $content_id){
        $rows = parent::select(
            "SELECT id FROM content_unlocks WHERE user_id = :u AND content_id = :cid",
            array('u' => (int) $user_id, 'cid' => (int) $content_id)
        );
        return is_array($rows) && count($rows) === 1;
    }

    /** Content ids this viewer has unlocked from a given creator. */
    public function unlocked_content_ids($viewer_id, $creator_id){
        $rows = parent::select(
            "SELECT content_id FROM content_unlocks WHERE user_id = :u AND creator_id = :c",
            array('u' => (int) $viewer_id, 'c' => (int) $creator_id)
        );
        $ids = array();
        if (is_array($rows)) {
            foreach ($rows as $r) { $ids[] = (int) $r['content_id']; }
        }
        return $ids;
    }

    /**
     * Record an unlock. Returns the new row id, or false if the unique key
     * rejects it (already owned / lost a race) — the caller then treats it as
     * already unlocked and does NOT charge.
     */
    public function record($user_id, $content_id, $creator_id, $credits_spent){
        try {
            return parent::insert('content_unlocks', array(
                'user_id'       => (int) $user_id,
                'content_id'    => (int) $content_id,
                'creator_id'    => (int) $creator_id,
                'credits_spent' => (int) $credits_spent,
                'created_at'    => date('Y-m-d H:i:s'),
            ));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Undo an unlock row (used when the credit charge fails). */
    public function remove($user_id, $content_id){
        return parent::delete('content_unlocks', 'user_id = :u AND content_id = :cid', 1,
            array('u' => (int) $user_id, 'cid' => (int) $content_id));
    }
}
