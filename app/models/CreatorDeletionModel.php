<?php
/**
 * Reads and deletes for CreatorDeletion ("Stop Being a Creator"): everything a creator owns as a creator, minus what
 * was sent to someone in a message, which stays theirs.
 */
class CreatorDeletionModel extends Model {

    public function __construct(){ parent::__construct(); }

    /**
     * Media that may be deleted for good: not sent to anyone in a message. Returns rows with every stored key.
     */
    public function purgeable_media($cid){
        return (array) parent::select(
            "SELECT ma.id, ma.original_key, ma.display_key, ma.thumb_key, ma.poster_key, ma.blurred_key
             FROM media_assets ma
             WHERE ma.creator_id = :c
               AND NOT EXISTS (SELECT 1 FROM message_assets msa WHERE msa.asset_id = ma.id)",
            array('c' => (int) $cid));
    }

    /** Remove a media row for good (its files are deleted by the caller first) and every reference to it. */
    public function delete_media_row($cid, $asset_id){
        $p = array('a' => (int) $asset_id);
        foreach (array('post_assets', 'collection_assets', 'influencer_images') as $t) {
            try { parent::delete_all($t, 'asset_id = :a', $p); } catch (\Throwable $e) { /* table absent in this install */ }
        }
        return parent::delete_all('media_assets', 'id = :a AND creator_id = :c', array('a' => (int) $asset_id, 'c' => (int) $cid));
    }

    /** Scheduled cross-posts still waiting at Post for Me. */
    public function scheduled_social_posts($cid){
        return (array) parent::select(
            "SELECT id, post_for_me_post_id FROM social_posts WHERE user_id = :c AND status = 'scheduled' AND scheduled_at > UTC_TIMESTAMP()",
            array('c' => (int) $cid));
    }

    public function mark_social_post_canceled($id){
        return parent::update('social_posts', array('status' => 'canceled', 'updated_at' => gmdate('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $id));
    }

    /** Rows owned outright by the creator, removed wholesale (tables that have no per-row rules). Returns rows removed. */
    public function delete_owned_rows($cid){
        $n = 0;
        parent::sql("DELETE ca FROM collection_assets ca JOIN collections c ON c.id = ca.collection_id WHERE c.creator_id = :c", array(':c' => (int) $cid));
        $by = array(
            'creator_id' => array('scheduler_rules', 'auto_messages', 'launch_campaigns', 'collections', 'inbox_settings'),
            'user_id'    => array('creator_links', 'creator_brand'),
        );
        foreach ($by as $col => $tables) {
            foreach ($tables as $t) {
                try { $n += (int) parent::delete_all($t, $col . ' = :c', array('c' => (int) $cid)); } catch (\Throwable $e) { error_log('[creator delete] ' . $t . ': ' . $e->getMessage()); }
            }
        }
        return $n;
    }

    /** Clear the public profile's creator content (bio, images, directory listing); the account itself stays. */
    public function reset_profile($cid){
        return parent::update('creator_profiles', array('bio' => '', 'location' => '', 'avatar_url' => '', 'avatar_webp_url' => '', 'cover_url' => '',
            'cover_webp_url' => '', 'directory_listed' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')), 'user_id = :c', array('c' => (int) $cid));
    }
}
