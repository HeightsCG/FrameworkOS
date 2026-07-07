<?php
/**
 * Collections (Content Studio): simple, creator-named folders over vault media.
 * A media asset can belong to more than one collection. All creator-scoped.
 */
class CollectionsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** Collections for a creator, each with a member asset_count. */
    public function get_for_creator($creator_id){
        return parent::select(
            "SELECT c.*, (SELECT COUNT(*) FROM collection_assets ca WHERE ca.collection_id = c.id) AS asset_count
             FROM collections c
             WHERE c.creator_id = :c
             ORDER BY c.sort_order ASC, c.name ASC, c.id ASC",
            array('c' => (int) $creator_id)
        );
    }

    public function get_one($creator_id, $id){
        $rows = parent::select(
            "SELECT * FROM collections WHERE id = :id AND creator_id = :c",
            array('id' => (int) $id, 'c' => (int) $creator_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function add($creator_id, $name){
        $now = date('Y-m-d H:i:s');
        $max = parent::select(
            "SELECT COALESCE(MAX(sort_order), -1) AS m FROM collections WHERE creator_id = :c",
            array('c' => (int) $creator_id)
        );
        $next = (is_array($max) && count($max) === 1) ? ((int) $max[0]['m'] + 1) : 0;
        return parent::insert('collections', array(
            'creator_id' => (int) $creator_id,
            'name'       => (string) $name,
            'sort_order' => $next,
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

    public function rename($creator_id, $id, $name){
        return parent::update('collections',
            array('name' => (string) $name, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function delete_collection($creator_id, $id){
        // Ownership-checked; membership rows go with it.
        $owned = $this->get_one($creator_id, $id);
        if (!$owned) { return 0; }
        parent::delete_all('collection_assets', 'collection_id = :id', array('id' => (int) $id));
        return parent::delete('collections', 'id = :id AND creator_id = :c', 1,
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Add assets to a collection (idempotent via INSERT IGNORE-style pre-check). */
    public function add_assets($collection_id, array $asset_ids){
        $now = date('Y-m-d H:i:s');
        foreach ($asset_ids as $aid) {
            $aid = (int) $aid;
            if ($aid <= 0) { continue; }
            $exists = parent::select(
                "SELECT 1 FROM collection_assets WHERE collection_id = :col AND asset_id = :a",
                array('col' => (int) $collection_id, 'a' => $aid)
            );
            if (is_array($exists) && count($exists) > 0) { continue; }
            parent::insert('collection_assets', array(
                'collection_id' => (int) $collection_id,
                'asset_id'      => $aid,
                'sort_order'    => 0,
            ));
        }
        return true;
    }

    public function remove_assets($collection_id, array $asset_ids){
        foreach ($asset_ids as $aid) {
            parent::delete('collection_assets', 'collection_id = :col AND asset_id = :a', 1,
                array('col' => (int) $collection_id, 'a' => (int) $aid));
        }
        return true;
    }

    /** Remove an asset from every collection (used when an asset is deleted). */
    public function remove_asset_everywhere($asset_id){
        return parent::delete_all('collection_assets', 'asset_id = :a', array('a' => (int) $asset_id));
    }
}
