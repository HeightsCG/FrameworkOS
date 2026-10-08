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

    const PROVENANCE = array('uploaded', 'generated', 'edited');

    /** Create a placeholder row (status uploading/processing); returns the new id. $provenance: uploaded | generated | edited. */
    public function add($creator_id, $type, $filename, $mime, $status = 'processing', $provenance = 'uploaded'){
        $now = date('Y-m-d H:i:s');
        return parent::insert('media_assets', array(
            'creator_id' => (int) $creator_id,
            'type'       => (string) $type,
            'filename'   => (string) $filename,
            'mime'       => (string) $mime,
            'status'     => (string) $status,
            'provenance' => in_array($provenance, self::PROVENANCE, true) ? $provenance : 'uploaded',
            // Only images are content-moderated; videos/gifs aren't scanned.
            'moderation_status' => 'pending',   // images and videos (poster frame) are both moderated
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

    /** Call recordings (source 'recording') belong to their event: never in the Library, pickers or attachments. */
    const NOT_RECORDING = "COALESCE(source, '') <> 'recording'";

    /** Mark a Library row as a call recording. */
    public function mark_recording($creator_id, $id){
        return parent::update('media_assets', array('source' => 'recording'), 'id = :id AND creator_id = :c', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Ready, owned assets by id (order preserved) — for attaching to messages. */
    public function get_owned_ready($creator_id, array $ids){
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) { return array(); }
        $in = implode(',', $ids);
        $rows = parent::select(
            "SELECT * FROM media_assets WHERE id IN ($in) AND creator_id = :c AND deleted_at IS NULL AND status = 'ready'
               AND moderation_status <> 'blocked' AND " . self::NOT_RECORDING,   // quarantined media never goes out in DMs/broadcasts/automations
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
        $rows = (array) parent::select("SELECT * FROM media_assets WHERE creator_id = :c AND status = 'ready' AND deleted_at IS NULL AND " . self::NOT_RECORDING . " AND id IN (" . implode(',', $in) . ")", $params);
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
        $res = parent::update('media_assets', $data, 'id = :id', array('id' => (int) $id));
        if ((string) $status !== 'approved' || !empty($is_adult)) { PublicThumbService::queue_purge(array('assets' => array((int) $id))); }   // no longer safe for a public copy
        return $res;
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

    /**
     * Record where an AI-made file came from. $f: provenance (generated | edited), parent_asset_id (the file this is a
     * new version of), source_asset_id (the input it was made from), model_key, prompt, influencer_id, job_id.
     */
    public function set_lineage($creator_id, $id, array $f){
        $data = array('updated_at' => date('Y-m-d H:i:s'));
        if (isset($f['provenance']) && in_array($f['provenance'], self::PROVENANCE, true)) { $data['provenance'] = $f['provenance']; }
        foreach (array('parent_asset_id' => 'parent_asset_id', 'source_asset_id' => 'source_asset_id', 'influencer_id' => 'gen_influencer_id', 'job_id' => 'gen_job_id') as $in => $col) {
            if (array_key_exists($in, $f)) { $data[$col] = ((int) $f[$in] > 0) ? (int) $f[$in] : null; }
        }
        if (array_key_exists('model_key', $f)) { $data['gen_model_key'] = ((string) $f['model_key'] !== '') ? mb_substr((string) $f['model_key'], 0, 64) : null; }
        if (array_key_exists('prompt', $f))    { $data['gen_prompt'] = ((string) $f['prompt'] !== '') ? (string) $f['prompt'] : null; }
        return parent::update('media_assets', $data, 'id = :id AND creator_id = :c', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** The version chain an asset belongs to, oldest first: its ancestors, itself, then everything made from it. */
    public function versions($creator_id, $id){
        $root = $this->get_one($creator_id, $id);
        if (!$root) { return array(); }
        for ($i = 0; $i < 50 && !empty($root['parent_asset_id']); $i++) {   // walk up to the original
            $up = $this->get_one($creator_id, (int) $root['parent_asset_id']);
            if (!$up) { break; }
            $root = $up;
        }
        $out = array($root); $seen = array((int) $root['id'] => true); $frontier = array((int) $root['id']);
        for ($depth = 0; $depth < 50 && !empty($frontier); $depth++) {
            $kids = (array) parent::select(
                "SELECT * FROM media_assets WHERE creator_id = :c AND deleted_at IS NULL AND parent_asset_id IN (" . implode(',', array_map('intval', $frontier)) . ") ORDER BY id ASC",
                array('c' => (int) $creator_id));
            $frontier = array();
            foreach ($kids as $k) { if (empty($seen[(int) $k['id']])) { $seen[(int) $k['id']] = true; $out[] = $k; $frontier[] = (int) $k['id']; } }
        }
        return $out;
    }

    public function set_watermark_applied($creator_id, $id, $applied){
        return parent::update('media_assets',
            array('watermark_applied' => $applied ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function soft_delete($creator_id, $id){
        $res = parent::update('media_assets',
            array('deleted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c AND deleted_at IS NULL',
            array('id' => (int) $id, 'c' => (int) $creator_id));
        PublicThumbService::queue_purge(array('assets' => array((int) $id)));   // deleted media loses its public copy
        return $res;
    }

    /** The subset of these asset ids someone has paid for (PPV post, priced message, or bundle) — buyers keep them, so they can't be removed. */
    public function sold_asset_ids(array $ids, $creator_id){
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) { return array(); }
        $in = implode(',', $ids);
        $r  = parent::select(
            "SELECT DISTINCT ma.id FROM media_assets ma
             WHERE ma.creator_id = :c AND ma.id IN ($in)
               AND (EXISTS (SELECT 1 FROM post_assets pa JOIN ppv_unlocks pu ON pu.post_id = pa.post_id WHERE pa.asset_id = ma.id)
                 OR EXISTS (SELECT 1 FROM message_assets msa JOIN message_unlocks mu ON mu.message_id = msa.message_id WHERE msa.asset_id = ma.id)
                 OR EXISTS (SELECT 1 FROM bundle_items bi JOIN bundle_unlocks bu ON bu.bundle_id = bi.bundle_id WHERE bi.asset_id = ma.id))",
            array('c' => (int) $creator_id));
        $out = array();
        foreach ((array) $r as $row) { $out[] = (int) $row['id']; }
        return $out;
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
        $where  = array('a.creator_id = :c', 'a.deleted_at IS NULL', "COALESCE(a.source, '') <> 'recording'");   // call recordings live on their event
        $join   = '';

        if (!empty($filters['type']) && in_array($filters['type'], array('image','video','gif','audio'), true)) {
            $where[] = 'a.type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['collection'])) {
            $join .= ' JOIN collection_assets fca ON fca.asset_id = a.id AND fca.collection_id = :col';
            $params['col'] = (int) $filters['collection'];
        }
        if (!empty($filters['brand'])) {   // AI-made with no influencer in it (Generate Images / Videos in brand mode)
            $where[] = "a.provenance = 'generated' AND COALESCE(a.gen_influencer_id, 0) = 0 AND NOT EXISTS (SELECT 1 FROM influencer_images bii WHERE bii.asset_id = a.id)";
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
            "SELECT COUNT(*) AS n FROM media_assets WHERE creator_id = :c AND deleted_at IS NULL AND " . self::NOT_RECORDING,
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

    /* ---- public renditions (PublicThumbService) ---- */

    /**
     * Safe for a public, cacheable copy: ready, moderator-approved, not adult, in a published free post on the site,
     * by a creator with a public page (can sell, not demo, not deleted, not suspended).
     */
    public static function public_ok_sql(): string {
        return "ma.deleted_at IS NULL AND ma.status = 'ready' AND ma.type IN ('image', 'video')
        AND ma.moderation_status = 'approved' AND ma.is_adult = 0
        AND EXISTS (SELECT 1 FROM post_assets pa JOIN posts p ON p.id = pa.post_id
                     WHERE pa.asset_id = ma.id AND p.state = 'published' AND p.on_cls = 1 AND p.audience = 'free')
        AND EXISTS (SELECT 1 FROM user_accounts ua WHERE ua.user_id = ma.creator_id AND ua.deleted = 0 AND ua.is_demo = 0
                     AND (ua.user_status IS NULL OR ua.user_status <> 'Disabled') AND " . Plan::paid_sql('ua') . ")";
    }

    /**
     * Assets that qualify for a public rendition but have none yet, ids above $after_id (the catch-up's cursor);
     * one post's when $post_id is given. Rows that failed for good (public_error) are skipped.
     */
    public function public_missing($post_id = 0, $limit = 200, $after_id = 0){
        $limit = max(1, min(1000, (int) $limit));
        $params = array('after' => (int) $after_id);
        $post_sql = '';
        if ((int) $post_id > 0) { $post_sql = ' AND ma.id IN (SELECT asset_id FROM post_assets WHERE post_id = :p)'; $params['p'] = (int) $post_id; }
        $rows = parent::select("SELECT ma.* FROM media_assets ma WHERE ma.public_thumb_key IS NULL AND ma.public_error IS NULL AND ma.id > :after AND "
            . self::public_ok_sql() . $post_sql . " ORDER BY ma.id ASC LIMIT $limit", $params);
        return is_array($rows) ? $rows : array();
    }

    /**
     * Assets holding a public rendition that no longer qualify (deleted, re-moderated, post gated or unpublished,
     * creator suspended, demo or without a selling plan). $scope narrows it: array('post' => id), array('creator' => id)
     * or array('assets' => ids); empty = everyone.
     */
    public function public_stale($limit = 200, array $scope = array()){
        $limit = max(1, min(1000, (int) $limit));
        $params = array(); $scope_sql = '';
        if (!empty($scope['post']))    { $scope_sql = ' AND ma.id IN (SELECT asset_id FROM post_assets WHERE post_id = :p)'; $params['p'] = (int) $scope['post']; }
        if (!empty($scope['creator'])) { $scope_sql = ' AND ma.creator_id = :c'; $params['c'] = (int) $scope['creator']; }
        if (!empty($scope['assets']))  { $scope_sql = ' AND ma.id IN (' . implode(',', array_map('intval', (array) $scope['assets'])) . ')'; }
        $rows = parent::select("SELECT ma.* FROM media_assets ma WHERE ma.public_thumb_key IS NOT NULL" . $scope_sql . " AND NOT (" . self::public_ok_sql() . ") ORDER BY ma.id ASC LIMIT $limit", $params);
        return is_array($rows) ? $rows : array();
    }

    /** Record why an asset's public rendition can't be built, so the catch-up stops retrying it ('' clears). */
    public function set_public_error($id, $error){
        return parent::update('media_assets', array('public_error' => (string) $error === '' ? null : mb_substr((string) $error, 0, 255)), 'id = :id', array('id' => (int) $id));
    }

    /** Store (or clear, with '' keys) an asset's public rendition keys and the larger rendition's size. */
    public function set_public($id, $thumb_key, $display_key, $width, $height){
        return parent::update('media_assets', array(
            'public_thumb_key'   => (string) $thumb_key === '' ? null : (string) $thumb_key,
            'public_display_key' => (string) $display_key === '' ? null : (string) $display_key,
            'public_width'       => (int) $width > 0 ? (int) $width : null,
            'public_height'      => (int) $height > 0 ? (int) $height : null,
        ), 'id = :id', array('id' => (int) $id));
    }
}
