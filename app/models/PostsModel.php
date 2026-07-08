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

    /** Add to a post's cumulative earnings (cents). Used when a PPV unlock is sold. */
    public function add_earnings($post_id, $cents){
        return parent::sql(
            "UPDATE posts SET earnings_cents = earnings_cents + :c, updated_at = :now WHERE id = :id",
            array('c' => (int) $cents, 'now' => date('Y-m-d H:i:s'), 'id' => (int) $post_id)
        );
    }

    public function update_fields($creator_id, $id, array $fields){
        $data = array('updated_at' => date('Y-m-d H:i:s'));
        if (array_key_exists('caption', $fields))  { $data['caption'] = (string) $fields['caption']; }
        if (array_key_exists('audience', $fields)) {
            $aud = in_array($fields['audience'], array('subscribers', 'ppv'), true) ? $fields['audience'] : 'free';
            $data['audience'] = $aud;
            // Price is meaningful only for PPV; clear it otherwise.
            $data['ppv_price_credits'] = ($aud === 'ppv' && (int) ($fields['ppv_price_credits'] ?? 0) > 0)
                ? (int) $fields['ppv_price_credits'] : null;
        }
        if (array_key_exists('tier_id', $fields))  { $data['tier_id'] = ((int) $fields['tier_id'] > 0) ? (int) $fields['tier_id'] : null; }
        if (array_key_exists('comments_enabled', $fields)) { $data['comments_enabled'] = !empty($fields['comments_enabled']) ? 1 : 0; }
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
                    ma.thumb_key, ma.display_key, ma.poster_key, ma.blurred_key, ma.original_key, ma.deleted_at
             FROM post_assets pa
             JOIN media_assets ma ON ma.id = pa.asset_id
             WHERE pa.post_id = :p
             ORDER BY pa.sort_order ASC",
            array('p' => (int) $post_id)
        );
    }

    /** A post by id regardless of owner — for public engagement (like/comment/view). */
    public function get_by_id($id){
        $rows = parent::select("SELECT * FROM posts WHERE id = :id", array('id' => (int) $id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** Sync a denormalized counter (likes|comments|views) to an exact value. */
    public function set_counter($id, $column, $value){
        if (!in_array($column, array('likes', 'comments', 'views'), true)) { return 0; }
        return parent::update('posts', array($column => (int) $value), 'id = :id', array('id' => (int) $id));
    }

    /** Published posts for a creator's public profile, newest first. */
    public function get_published_for_creator($creator_id){
        return parent::select(
            "SELECT * FROM posts WHERE creator_id = :c AND state = 'published'
             ORDER BY published_at DESC, id DESC",
            array('c' => (int) $creator_id)
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

    /**
     * Posts for the list, newest first, with the cover asset's keys + an asset
     * count. Optional filters: state, search (caption).
     */
    public function list_for_creator($creator_id, array $filters = array()){
        $params = array('c' => (int) $creator_id);
        $where  = array('p.creator_id = :c');
        if (!empty($filters['state']) && in_array($filters['state'], array('draft','scheduled','published','archived'), true)) {
            $where[] = 'p.state = :state';
            $params['state'] = $filters['state'];
        }
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $where[] = 'p.caption LIKE :q';
            $params['q'] = '%' . trim((string) $filters['search']) . '%';
        }
        $sql = "SELECT p.*,
                    (SELECT COUNT(*) FROM post_assets pa WHERE pa.post_id = p.id) AS asset_count,
                    (SELECT ma.thumb_key FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
                       WHERE pa.post_id = p.id ORDER BY pa.is_cover DESC, pa.sort_order ASC LIMIT 1) AS cover_thumb_key,
                    (SELECT ma.type FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
                       WHERE pa.post_id = p.id ORDER BY pa.is_cover DESC, pa.sort_order ASC LIMIT 1) AS cover_type
                FROM posts p
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.created_at DESC, p.id DESC";
        return parent::select($sql, $params);
    }

    /** Count posts by state (for filter tabs). */
    public function counts_by_state($creator_id){
        $rows = parent::select(
            "SELECT state, COUNT(*) AS n FROM posts WHERE creator_id = :c GROUP BY state",
            array('c' => (int) $creator_id)
        );
        $out = array('all' => 0, 'draft' => 0, 'scheduled' => 0, 'published' => 0, 'archived' => 0);
        foreach ($rows as $r) { $out[$r['state']] = (int) $r['n']; $out['all'] += (int) $r['n']; }
        return $out;
    }

    /** Duplicate a post into a new draft (same caption, options, and media). */
    public function duplicate($creator_id, $id){
        $src = $this->get_one($creator_id, $id);
        if (!$src) { return 0; }
        $new_id = (int) $this->create_draft($creator_id, (string) $src['caption'], $src['audience']);
        $this->update_fields($creator_id, $new_id, array(
            'caption' => (string) $src['caption'], 'audience' => $src['audience'],
            'tier_id' => $src['tier_id'], 'comments_enabled' => $src['comments_enabled'],
            'ppv_price_credits' => (int) ($src['ppv_price_credits'] ?? 0),
        ));
        $assets = $this->get_assets($id);
        $ids = array(); $cover = 0;
        foreach ($assets as $a) { $ids[] = (int) $a['asset_id']; if ((int) $a['is_cover'] === 1) { $cover = (int) $a['asset_id']; } }
        $this->set_assets($creator_id, $new_id, $ids, $cover);
        return $new_id;
    }

    /** Publish any scheduled posts whose time has arrived (cron-less fallback). */
    public function publish_due($creator_id){
        $now = date('Y-m-d H:i:s');
        return parent::sql(
            "UPDATE posts SET state = 'published',
                    published_at = COALESCE(published_at, :n1), scheduled_at = NULL, updated_at = :n2
             WHERE creator_id = :c AND state = 'scheduled' AND scheduled_at <= :n3
               AND media_missing = 0",
            array('c' => (int) $creator_id, 'n1' => $now, 'n2' => $now, 'n3' => $now)
        );
    }

    public function delete_post($creator_id, $id){
        $owned = $this->get_one($creator_id, $id);
        if (!$owned) { return 0; }
        parent::delete_all('post_assets', 'post_id = :p', array('p' => (int) $id));
        return parent::delete('posts', 'id = :id AND creator_id = :c', 1,
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }
}
