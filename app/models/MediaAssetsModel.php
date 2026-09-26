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
            // Only images are content-moderated; videos/gifs aren't scanned.
            'moderation_status' => 'pending',   // images and videos (poster frame) are both moderated
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

    /** Ready, owned assets by id (order preserved) — for attaching to messages. */
    public function get_owned_ready($creator_id, array $ids){
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) { return array(); }
        $in = implode(',', $ids);
        $rows = parent::select(
            "SELECT * FROM media_assets WHERE id IN ($in) AND creator_id = :c AND deleted_at IS NULL AND status = 'ready'
               AND moderation_status <> 'blocked'",   // quarantined media never goes out in DMs/broadcasts/automations
            array('c' => (int) $creator_id));
        $by = array();
        foreach ((array) $rows as $r) { $by[(int) $r['id']] = $r; }
        $out = array();
        foreach ($ids as $id) { if (isset($by[$id])) { $out[] = $by[$id]; } }
        return $out;
    }

    public function get_one($creator_id, $id){
        $rows = parent::select(
            "SELECT * FROM media_assets WHERE id = :id AND creator_id = :c AND deleted_at IS NULL",
            array('id' => (int) $id, 'c' => (int) $creator_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** Several of one creator's ready files by id (others' and deleted ones are left out), in the order given. */
    public function get_ready_many($creator_id, array $ids){
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) { return array(); }
        $in = array(); $params = array('c' => (int) $creator_id);
        foreach ($ids as $i => $id) { $in[] = ':i' . $i; $params['i' . $i] = $id; }
        $rows = (array) parent::select("SELECT * FROM media_assets WHERE creator_id = :c AND status = 'ready' AND deleted_at IS NULL AND id IN (" . implode(',', $in) . ")", $params);
        $by = array(); foreach ($rows as $r) { $by[(int) $r['id']] = $r; }
        $out = array(); foreach ($ids as $id) { if (isset($by[$id])) { $out[] = $by[$id]; } }
        return $out;
    }

    /** Apply processed keys + metadata and flip to ready (or failed). */
    public function set_ready($creator_id, $id, array $fields){
        $data = array('status' => 'ready', 'failure_reason' => null, 'updated_at' => date('Y-m-d H:i:s'));
        foreach (array('original_key','display_key','thumb_key','poster_key','blurred_key',
                       'width','height','bytes','duration_sec','watermark_applied') as $k) {
            if (array_key_exists($k, $fields)) { $data[$k] = $fields[$k]; }
        }
        // Moderation verdict from the processing pipeline (set before the asset goes live).
        if (array_key_exists('moderation_status', $fields)) {
            $data['moderation_status'] = (string) $fields['moderation_status'];
            $data['moderation_score']  = ($fields['moderation_score'] ?? null) === null ? null : (float) $fields['moderation_score'];
            $data['moderation_labels'] = $fields['moderation_labels'] ?? null;
            if ($fields['moderation_status'] !== 'pending') { $data['moderated_at'] = date('Y-m-d H:i:s'); }
            if ($fields['moderation_status'] === 'flagged') { $data['is_adult'] = 1; }   // flagged = adult, same as the cron path
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
            "SELECT id, creator_id, type, display_key, original_key, thumb_key, poster_key
             FROM media_assets
             WHERE type IN ('image', 'video') AND status = 'ready' AND deleted_at IS NULL
               AND (moderation_status = 'pending'
                    OR (moderation_status = 'error' AND (moderated_at IS NULL OR moderated_at < :retry)))   -- errors retry every 30 min, never ahead of new work
             ORDER BY (moderation_status = 'error') ASC, created_at DESC
             LIMIT $limit",
            array('retry' => date('Y-m-d H:i:s', time() - 1800))
        );
    }

    /** Record a moderation verdict (system worker — keyed by asset id). */
    public function set_moderation($id, $status, $score = null, array $labels = array(), $is_adult = null){
        $data = array(
            'moderation_status' => (string) $status,
            'moderation_score'  => ($score === null) ? null : (float) $score,
            'moderation_labels' => empty($labels) ? null : implode(',', $labels),
            'moderated_at'      => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        );
        if ($is_adult !== null) { $data['is_adult'] = $is_adult ? 1 : 0; }   // adult classification, kept across approve/block
        return parent::update('media_assets', $data, 'id = :id', array('id' => (int) $id));
    }

    public function set_description($creator_id, $id, $description){
        return parent::update('media_assets',
            array('description' => (string) $description, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Comma-separated tags (e.g. "influencer:12,generated") — the only write path for the column. */
    public function set_tags($creator_id, $id, $tags){
        return parent::update('media_assets',
            array('tags' => mb_substr((string) $tags, 0, 512), 'updated_at' => date('Y-m-d H:i:s')),
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
     *   influencer — influencer id (asset attached to her); role — one of InfluencerImagesModel::ROLES
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
        if (!empty($filters['influencer'])) {   // media attached to one AI influencer, optionally by role
            $join .= ' JOIN influencer_images fii ON fii.asset_id = a.id AND fii.influencer_id = :infl';
            $params['infl'] = (int) $filters['influencer'];
            if (!empty($filters['role']) && in_array($filters['role'], InfluencerImagesModel::ROLES, true)) {
                $join .= ' AND fii.role = :role';
                $params['role'] = (string) $filters['role'];
            }
        }
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $where[] = '(a.filename LIKE :q1 OR a.display_name LIKE :q2 OR a.tags LIKE :q3)';
            $like = '%' . trim((string) $filters['search']) . '%';
            $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like;
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

    /** Total bytes a creator currently stores (non-deleted assets) — for storage-quota checks. */
    public function total_bytes($creator_id){
        $rows = parent::select(
            "SELECT COALESCE(SUM(bytes),0) AS b FROM media_assets WHERE creator_id = :c AND deleted_at IS NULL",
            array('c' => (int) $creator_id)
        );
        return (is_array($rows) && count($rows)) ? (int) $rows[0]['b'] : 0;
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
