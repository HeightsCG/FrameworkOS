<?php
/**
 * Posts (Content Studio). A post references vault assets via post_assets (a file
 * is never coupled to one post). Audience is free | subscribers. Lifecycle:
 * draft -> scheduled -> published -> archived. All creator-scoped.
 */
class PostsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function create_draft($creator_id, $caption = '', $audience = 'free'){
        $now = date('Y-m-d H:i:s');
        return parent::insert('posts', array(
            'creator_id' => (int) $creator_id,
            'caption'    => (string) $caption,
            'audience'   => $audience === 'subscribers' ? 'subscribers' : 'free',
            'state'      => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

    public function get_one($creator_id, $id){
        $rows = parent::select(
            "SELECT * FROM posts WHERE id = :id AND creator_id = :c",
            array('id' => (int) $id, 'c' => (int) $creator_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** The creator's most recent editable draft (for the reopen-draft offer). */
    public function get_open_draft($creator_id){
        $rows = parent::select(
            "SELECT * FROM posts WHERE creator_id = :c AND state = 'draft' ORDER BY updated_at DESC, id DESC LIMIT 1",
            array('c' => (int) $creator_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function update_fields($creator_id, $id, array $fields){
        $data = array('updated_at' => date('Y-m-d H:i:s'));
        if (array_key_exists('caption', $fields))  { $data['caption'] = (string) $fields['caption']; }
        if (array_key_exists('audience', $fields)) { $data['audience'] = $fields['audience'] === 'subscribers' ? 'subscribers' : 'free'; }
        if (array_key_exists('tier_id', $fields))  { $data['tier_id'] = ((int) $fields['tier_id'] > 0) ? (int) $fields['tier_id'] : null; }
        return parent::update('posts', $data, 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /**
     * Replace a post's media with an ordered list of owned, ready assets. The
     * first asset is the cover unless $cover_id names another in the list.
     */
    public function set_assets($creator_id, $post_id, array $asset_ids, $cover_id = 0){
        parent::delete_all('post_assets', 'post_id = :p', array('p' => (int) $post_id));
        $order = 0; $first = null;
        foreach ($asset_ids as $aid) {
            $aid = (int) $aid;
            $own = parent::select(
                "SELECT id FROM media_assets WHERE id = :a AND creator_id = :c AND deleted_at IS NULL",
                array('a' => $aid, 'c' => (int) $creator_id)
            );
            if (!is_array($own) || count($own) !== 1) { continue; }
            if ($first === null) { $first = $aid; }
            parent::insert('post_assets', array(
                'post_id'    => (int) $post_id,
                'asset_id'   => $aid,
                'sort_order' => $order,
                'is_cover'   => 0,
            ));
            $order++;
        }
        // Mark the cover: the named cover if it made the cut, else the first asset.
        $cover = ((int) $cover_id > 0 && in_array((int) $cover_id, array_map('intval', $asset_ids), true)) ? (int) $cover_id : $first;
        if ($cover !== null) {
            parent::update('post_assets', array('is_cover' => 1), 'post_id = :p AND asset_id = :a',
                array('p' => (int) $post_id, 'a' => (int) $cover));
        }
        return true;
    }

    /** A post's assets with the media info needed for previews and the list. */
    public function get_assets($post_id){
        return parent::select(
            "SELECT pa.asset_id, pa.sort_order, pa.is_cover,
                    ma.creator_id, ma.type, ma.status, ma.duration_sec,
                    ma.thumb_key, ma.display_key, ma.poster_key, ma.blurred_key, ma.deleted_at
             FROM post_assets pa
             JOIN media_assets ma ON ma.id = pa.asset_id
             WHERE pa.post_id = :p
             ORDER BY pa.sort_order ASC",
            array('p' => (int) $post_id)
        );
    }

    /** Count assets on a post that are missing (soft-deleted) — blocks publishing. */
    public function count_missing_assets($post_id){
        $rows = parent::select(
            "SELECT COUNT(*) AS n FROM post_assets pa
             JOIN media_assets ma ON ma.id = pa.asset_id
             WHERE pa.post_id = :p AND ma.deleted_at IS NOT NULL",
            array('p' => (int) $post_id)
        );
        return (is_array($rows) && count($rows) === 1) ? (int) $rows[0]['n'] : 0;
    }

    public function set_state($creator_id, $id, $state, $scheduled_at = null, $published_at = null){
        $data = array('state' => $state, 'updated_at' => date('Y-m-d H:i:s'));
        $data['scheduled_at'] = ($state === 'scheduled') ? $scheduled_at : null;
        if ($state === 'published') {
            $post = $this->get_one($creator_id, $id);
            $data['published_at'] = ($post && !empty($post['published_at'])) ? $post['published_at'] : ($published_at ?: date('Y-m-d H:i:s'));
        }
        return parent::update('posts', $data, 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function set_media_missing($creator_id, $id, $missing){
        return parent::update('posts',
            array('media_missing' => $missing ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function delete_post($creator_id, $id){
        $owned = $this->get_one($creator_id, $id);
        if (!$owned) { return 0; }
        parent::delete_all('post_assets', 'post_id = :p', array('p' => (int) $id));
        return parent::delete('posts', 'id = :id AND creator_id = :c', 1,
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }
}
