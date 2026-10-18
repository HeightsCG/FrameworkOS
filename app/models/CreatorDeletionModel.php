<?php
/**
 * Reads and deletes for CreatorDeletion ("Stop Being a Creator"): everything a creator owns as a creator, minus what
 * fans bought or were sent, which stays theirs.
 */
class CreatorDeletionModel extends Model {

    public function __construct(){ parent::__construct(); }

    /** The creator's events with whether money ever changed hands on them (paid tickets or replay sales). */
    public function events($cid){
        return (array) parent::select(
            "SELECT e.id, e.status, e.start_at, e.end_at,
                    (EXISTS (SELECT 1 FROM event_registrations r WHERE r.event_id = e.id AND r.price_credits > 0)
                     OR EXISTS (SELECT 1 FROM replay_unlocks u WHERE u.event_id = e.id)) AS paid_ever
             FROM events e WHERE e.creator_id = :c", array('c' => (int) $cid));
    }

    /** Paid bookings not yet delivered, per service: these are refunded before the creator goes. */
    public function open_paid_bookings($cid){
        return (array) parent::select(
            "SELECT sp.id, sp.service_id FROM service_purchases sp JOIN services s ON s.id = sp.service_id
             WHERE s.creator_id = :c AND sp.status = 'paid' AND sp.delivered_at IS NULL", array('c' => (int) $cid));
    }

    /** The creator's services with how many bookings each ever had. */
    public function services($cid){
        return (array) parent::select(
            "SELECT s.id, (SELECT COUNT(*) FROM service_purchases sp WHERE sp.service_id = s.id) AS bookings
             FROM services s WHERE s.creator_id = :c", array('c' => (int) $cid));
    }

    /**
     * Media that may be deleted for good: not bought (PPV post, paid message, bundle), not sent to anyone in a message,
     * and not the recording of an event that is kept. Returns rows with every stored key.
     */
    public function purgeable_media($cid, array $kept_event_ids){
        $keep_rec = '';
        $ids = array_values(array_filter(array_map('intval', $kept_event_ids)));
        if ($ids) { $keep_rec = " AND NOT EXISTS (SELECT 1 FROM live_recordings lr WHERE lr.asset_id = ma.id AND lr.event_id IN (" . implode(',', $ids) . "))"; }
        return (array) parent::select(
            "SELECT ma.id, ma.original_key, ma.display_key, ma.thumb_key, ma.poster_key, ma.blurred_key
             FROM media_assets ma
             WHERE ma.creator_id = :c
               AND NOT EXISTS (SELECT 1 FROM post_assets pa JOIN ppv_unlocks pu ON pu.post_id = pa.post_id WHERE pa.asset_id = ma.id)
               AND NOT EXISTS (SELECT 1 FROM message_assets msa WHERE msa.asset_id = ma.id)
               AND NOT EXISTS (SELECT 1 FROM bundle_items bi JOIN bundle_unlocks bu ON bu.bundle_id = bi.bundle_id WHERE bi.asset_id = ma.id)
               AND NOT EXISTS (SELECT 1 FROM events e WHERE e.creator_id = ma.creator_id AND e.replay_recording_id IS NOT NULL
                               AND EXISTS (SELECT 1 FROM live_recordings lr2 WHERE lr2.id = e.replay_recording_id AND lr2.asset_id = ma.id)
                               AND EXISTS (SELECT 1 FROM replay_unlocks ru WHERE ru.event_id = e.id))"
               . $keep_rec, array('c' => (int) $cid));
    }

    /** Remove a media row for good (its files are deleted by the caller first) and every reference to it. */
    public function delete_media_row($cid, $asset_id){
        $p = array('a' => (int) $asset_id);
        foreach (array('post_assets', 'bundle_items', 'collection_assets', 'influencer_images') as $t) {
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

    /** The sign-ups and messages of an event nobody paid for, removed with it. */
    public function delete_event_children($event_id){
        $p = array('e' => (int) $event_id);
        parent::delete_all('event_registrations', 'event_id = :e', $p);
        try { parent::delete_all('event_messages', 'event_id = :e', $p); } catch (\Throwable $e) { /* table absent */ }
    }

    /** Rows owned outright by the creator, removed wholesale (tables that have no per-row rules). Returns rows removed. */
    public function delete_owned_rows($cid){
        $n = 0;
        parent::sql("DELETE ca FROM collection_assets ca JOIN collections c ON c.id = ca.collection_id WHERE c.creator_id = :c", array(':c' => (int) $cid));
        $by = array(
            'creator_id' => array('scheduler_rules', 'auto_messages', 'launch_campaigns', 'collections', 'inbox_settings'),
            'user_id'    => array('creator_links', 'creator_promo_codes', 'creator_plans', 'creator_brand'),
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
