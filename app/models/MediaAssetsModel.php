<?php
/**
 * Media vault (Content Studio). Assets are uploaded once and reused across posts.
 * All rows are creator-scoped (creator_id == user_accounts.user_id); every query
 * filters by creator_id so one creator can never read another's vault.
 */
class MediaAssetsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** Create a placeholder row (status uploading/processing); returns the new id. */
    public function add($creator_id, $type, $filename, $mime, $status = 'processing'){
        $now = date('Y-m-d H:i:s');
        return parent::insert('media_assets', array(
            'creator_id' => (int) $creator_id,
            'type'       => (string) $type,
            'filename'   => (string) $filename,
            'mime'       => (string) $mime,
            'status'     => (string) $status,
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

    public function get_one($creator_id, $id){
        $rows = parent::select(
            "SELECT * FROM media_assets WHERE id = :id AND creator_id = :c AND deleted_at IS NULL",
            array('id' => (int) $id, 'c' => (int) $creator_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** Apply processed keys + metadata and flip to ready (or failed). */
    public function set_ready($creator_id, $id, array $fields){
        $data = array('status' => 'ready', 'failure_reason' => null, 'updated_at' => date('Y-m-d H:i:s'));
        foreach (array('original_key','display_key','thumb_key','poster_key','blurred_key',
                       'width','height','bytes','duration_sec','watermark_applied') as $k) {
            if (array_key_exists($k, $fields)) { $data[$k] = $fields[$k]; }
        }
        return parent::update('media_assets', $data, 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function set_failed($creator_id, $id, $reason){
        return parent::update('media_assets',
            array('status' => 'failed', 'failure_reason' => (string) $reason, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Ready images awaiting moderation — the worker's feed (across all creators). */
    public function due_for_moderation($limit = 20){
        $limit = (int) $limit;
        return parent::select(
            "SELECT id, creator_id, display_key, original_key, thumb_key
             FROM media_assets
             WHERE type = 'image' AND status = 'ready' AND deleted_at IS NULL
               AND moderation_status = 'pending'
             ORDER BY created_at DESC
             LIMIT $limit"
        );
    }

    /** Record a moderation verdict (system worker — keyed by asset id). */
    public function set_moderation($id, $status, $score = null, array $labels = array()){
        return parent::update('media_assets',
            array(
                'moderation_status' => (string) $status,
                'moderation_score'  => ($score === null) ? null : (float) $score,
                'moderation_labels' => empty($labels) ? null : implode(',', $labels),
                'moderated_at'      => date('Y-m-d H:i:s'),
                'updated_at'        => date('Y-m-d H:i:s'),
            ),
            'id = :id', array('id' => (int) $id));
    }

    public function set_description($creator_id, $id, $description){
        return parent::update('media_assets',
            array('description' => (string) $description, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function set_watermark_applied($creator_id, $id, $applied){
        return parent::update('media_assets',
            array('watermark_applied' => $applied ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function soft_delete($creator_id, $id){
        return parent::update('media_assets',
            array('deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c AND deleted_at IS NULL',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /**
     * Vault listing with usage counts + optional filters. Returns rows including a
     * `usage_count` (number of posts referencing the asset). Filters:
     *   type       — 'image'|'video'|'gif'
     *   collection — collection id (asset must be a member)
     *   usage      — 'used'|'unused'
     *   search     — matches filename, display_name, tags
     */
    public function get_for_creator($creator_id, array $filters = array()){
        $params = array('c' => (int) $creator_id);
        $where  = array('a.creator_id = :c', 'a.deleted_at IS NULL');
        $join   = '';

        if (!empty($filters['type']) && in_array($filters['type'], array('image','video','gif'), true)) {
            $where[] = 'a.type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['collection'])) {
            $join .= ' JOIN collection_assets fca ON fca.asset_id = a.id AND fca.collection_id = :col';
            $params['col'] = (int) $filters['collection'];
        }
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $where[] = '(a.filename LIKE :q OR a.display_name LIKE :q OR a.tags LIKE :q)';
            $params['q'] = '%' . trim((string) $filters['search']) . '%';
        }

        $having = '';
        if (!empty($filters['usage']) && in_array($filters['usage'], array('used','unused'), true)) {
            $having = ' HAVING usage_count ' . ($filters['usage'] === 'used' ? '> 0' : '= 0');
        }

        $sql = "SELECT a.*, (SELECT COUNT(*) FROM post_assets pa WHERE pa.asset_id = a.id) AS usage_count
                FROM media_assets a $join
                WHERE " . implode(' AND ', $where) . "
                GROUP BY a.id $having
                ORDER BY a.created_at DESC, a.id DESC";
        return parent::select($sql, $params);
    }

    public function count_for_creator($creator_id){
        $rows = parent::select(
            "SELECT COUNT(*) AS n FROM media_assets WHERE creator_id = :c AND deleted_at IS NULL",
            array('c' => (int) $creator_id)
        );
        return (is_array($rows) && count($rows) === 1) ? (int) $rows[0]['n'] : 0;
    }

    /** Posts (any state) that reference this asset — for the detail panel + delete impact. */
    public function get_posts_using($creator_id, $asset_id){
        return parent::select(
            "SELECT p.id, p.caption, p.state, p.scheduled_at, p.published_at
             FROM post_assets pa
             JOIN posts p ON p.id = pa.post_id
             WHERE pa.asset_id = :a AND p.creator_id = :c
             ORDER BY p.created_at DESC",
            array('a' => (int) $asset_id, 'c' => (int) $creator_id)
        );
    }

    /** Collection ids this asset belongs to. */
    public function get_collection_ids($asset_id){
        $rows = parent::select(
            "SELECT collection_id FROM collection_assets WHERE asset_id = :a",
            array('a' => (int) $asset_id)
        );
        $ids = array();
        foreach ((array) $rows as $r) { $ids[] = (int) $r['collection_id']; }
        return $ids;
    }
}
