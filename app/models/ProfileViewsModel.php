<?php
/**
 * Profile-view tracking (PRD §7.5 / §41.1). One row per visit to a public creator
 * profile (the creator's own visits are excluded by the caller). viewer_key mirrors
 * post_views ('u:<id>' or 'ip:<hash>') so unique-visitor counts are comparable.
 */
class ProfileViewsModel extends Model {

    public function record($creator_id, $viewer_key){
        return (int) parent::insert('profile_views', array(
            'creator_id' => (int) $creator_id,
            'viewer_key' => (string) $viewer_key,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }
}
