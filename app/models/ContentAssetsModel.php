<?php
/**
 * Media assets attached to a content item (PRD §10.1). Stage 1 stores images;
 * the type enum is ready for video/audio/document.
 */
class ContentAssetsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function get_for_content($content_id){
        return parent::select(
            "SELECT id, type, url, mime, sort_order
             FROM content_assets
             WHERE content_id = :cid
             ORDER BY sort_order ASC, id ASC",
            array('cid' => (int) $content_id)
        );
    }

    public function add($creator_id, $content_id, $type, $url, $mime){
        $now = date('Y-m-d H:i:s');
        $max = parent::select(
            "SELECT COALESCE(MAX(sort_order), -1) AS m FROM content_assets WHERE content_id = :cid",
            array('cid' => (int) $content_id)
        );
        $next = (is_array($max) && count($max) === 1) ? ((int) $max[0]['m'] + 1) : 0;

        return parent::insert('content_assets', array(
            'content_id' => (int) $content_id,
            'creator_id' => (int) $creator_id,
            'type'       => (string) $type,
            'url'        => (string) $url,
            'mime'       => (string) $mime,
            'sort_order' => $next,
            'created_at' => $now,
        ));
    }

    /** Owner-scoped fetch (for delete + old-URL cleanup). */
    public function get_one($creator_id, $id){
        $rows = parent::select(
            "SELECT * FROM content_assets WHERE id = :id AND creator_id = :c",
            array('id' => (int) $id, 'c' => (int) $creator_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function delete_asset($creator_id, $id){
        return parent::delete('content_assets', 'id = :id AND creator_id = :c', 1,
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Remove all assets for an item (used when the item itself is deleted). */
    public function delete_for_content($creator_id, $content_id){
        return parent::delete_all('content_assets', 'content_id = :cid AND creator_id = :c',
            array('cid' => (int) $content_id, 'c' => (int) $creator_id));
    }

    /** Persist a new media order within a content item (owner-scoped). */
    public function reorder($creator_id, $ids){
        $order = 0;
        foreach ($ids as $id) {
            parent::update('content_assets',
                array('sort_order' => $order),
                'id = :id AND creator_id = :c',
                array('id' => (int) $id, 'c' => (int) $creator_id));
            $order++;
        }
        return true;
    }
}
