<?php
/**
 * Content bundles: a creator groups several of their pay-per-view posts and sells
 * them together at one price. Buying a bundle unlocks every post in it (by writing
 * a ppv_unlocks row per post), so all existing PPV entitlement checks just work.
 */
class ContentBundlesModel extends Model {

    public function __construct(){ parent::__construct(); }

    /** All of a creator's bundles (any status), with item count, for management. */
    public function get_for_creator($creator_id){
        return parent::select(
            "SELECT b.*, (SELECT COUNT(*) FROM bundle_items bi WHERE bi.bundle_id = b.id) AS item_count
             FROM content_bundles b
             WHERE b.creator_id = :c
             ORDER BY b.sort_order ASC, b.id DESC",
            array('c' => (int) $creator_id)
        );
    }

    /** Active, non-empty bundles for a creator's public profile. */
    public function get_active_for_creator($creator_id){
        return parent::select(
            "SELECT b.*, (SELECT COUNT(*) FROM bundle_items bi WHERE bi.bundle_id = b.id) AS item_count
             FROM content_bundles b
             WHERE b.creator_id = :c AND b.is_active = 1
               AND (SELECT COUNT(*) FROM bundle_items bi WHERE bi.bundle_id = b.id) > 0
             ORDER BY b.sort_order ASC, b.id DESC",
            array('c' => (int) $creator_id)
        );
    }

    public function get_owned($creator_id, $id){
        $rows = parent::select(
            "SELECT * FROM content_bundles WHERE id = :id AND creator_id = :c",
            array('id' => (int) $id, 'c' => (int) $creator_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function get_public($id){
        $rows = parent::select(
            "SELECT * FROM content_bundles WHERE id = :id AND is_active = 1",
            array('id' => (int) $id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function add($creator_id, $name, $description, $price_credits){
        $now = date('Y-m-d H:i:s');
        return parent::insert('content_bundles', array(
            'creator_id'    => (int) $creator_id,
            'name'          => $name,
            'description'   => $description,
            'price_credits' => (int) $price_credits,
            'is_active'     => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ));
    }

    public function update_bundle($creator_id, $id, array $fields){
        $fields['updated_at'] = date('Y-m-d H:i:s');
        return parent::update('content_bundles', $fields, 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function set_active($creator_id, $id, $active){
        return parent::update('content_bundles',
            array('is_active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function delete_bundle($creator_id, $id){
        // Scope-check ownership before removing the bundle and its items.
        if (!$this->get_owned($creator_id, $id)) { return false; }
        parent::delete_all('bundle_items', 'bundle_id = :b', array('b' => (int) $id));
        return parent::delete_all('content_bundles', 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Replace a bundle's items with the given (already-validated) media asset ids. */
    public function set_items($bundle_id, array $asset_ids){
        parent::delete_all('bundle_items', 'bundle_id = :b', array('b' => (int) $bundle_id));
        $now = date('Y-m-d H:i:s');
        foreach (array_unique(array_map('intval', $asset_ids)) as $aid) {
            if ($aid <= 0) { continue; }
            try {
                parent::insert('bundle_items', array(
                    'bundle_id'  => (int) $bundle_id,
                    'asset_id'   => (int) $aid,
                    'created_at' => $now,
                ));
            } catch (\Throwable $e) { /* duplicate (bundle_id, asset_id) — skip */ }
        }
        return true;
    }

    /** Media asset ids in a bundle. */
    public function get_item_asset_ids($bundle_id){
        $rows = parent::select("SELECT asset_id FROM bundle_items WHERE bundle_id = :b",
            array('b' => (int) $bundle_id));
        $out = array();
        foreach ((array) $rows as $r) { $out[] = (int) $r['asset_id']; }
        return $out;
    }

    /** The media assets in a bundle (joined to media_assets), for display/delivery. */
    public function get_media_for_bundle($bundle_id){
        return parent::select(
            "SELECT ma.* FROM bundle_items bi
             JOIN media_assets ma ON ma.id = bi.asset_id
             WHERE bi.bundle_id = :b AND ma.deleted_at IS NULL AND ma.status = 'ready' AND ma.moderation_status <> 'blocked'
             ORDER BY bi.id ASC",
            array('b' => (int) $bundle_id)
        );
    }

    // ---- fan side ----

    public function has_unlocked($bundle_id, $fan_id){
        if (!$fan_id) { return false; }
        $r = parent::select("SELECT id FROM bundle_unlocks WHERE bundle_id = :b AND fan_id = :f LIMIT 1",
            array('b' => (int) $bundle_id, 'f' => (int) $fan_id));
        return is_array($r) && count($r) >= 1;
    }

    /** Map of bundle_id => true this fan has unlocked for a creator (profile display). */
    public function unlocked_map_for_creator($fan_id, $creator_id){
        if (!$fan_id) { return array(); }
        $r = parent::select(
            "SELECT bundle_id FROM bundle_unlocks WHERE fan_id = :f AND creator_id = :c",
            array('f' => (int) $fan_id, 'c' => (int) $creator_id));
        $out = array();
        foreach ((array) $r as $row) { $out[(int) $row['bundle_id']] = true; }
        return $out;
    }

    /** Bundles this fan has purchased, with creator identity — for the Purchases area. */
    public function get_purchased_for_fan($fan_id){
        return parent::select(
            "SELECT b.id, b.name, b.description, b.price_credits,
                    bu.price_credits AS paid_credits, bu.created_at AS purchased_at,
                    u.u_name AS creator_handle,
                    COALESCE(cp.display_name, CONCAT(u.first_name, ' ', u.last_name)) AS creator_name
             FROM bundle_unlocks bu
             JOIN content_bundles b ON b.id = bu.bundle_id
             JOIN user_accounts u ON u.user_id = bu.creator_id AND u.deleted = 0
             LEFT JOIN creator_profiles cp ON cp.user_id = bu.creator_id
             WHERE bu.fan_id = :f
             ORDER BY bu.created_at DESC",
            array('f' => (int) $fan_id)
        );
    }

    public function record_unlock($bundle_id, $creator_id, $fan_id, $price_credits){
        try {
            parent::insert('bundle_unlocks', array(
                'bundle_id'     => (int) $bundle_id,
                'creator_id'    => (int) $creator_id,
                'fan_id'        => (int) $fan_id,
                'price_credits' => (int) $price_credits,
                'created_at'    => date('Y-m-d H:i:s'),
            ));
            return true;
        } catch (\Throwable $e) {
            return false; // already unlocked (unique key)
        }
    }

    public function remove_unlock($bundle_id, $fan_id){
        return parent::delete_all('bundle_unlocks', 'bundle_id = :b AND fan_id = :f',
            array('b' => (int) $bundle_id, 'f' => (int) $fan_id));
    }
}
