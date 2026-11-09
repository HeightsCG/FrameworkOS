<?php
/**
 * Posts (Content Studio). A post references vault assets via post_assets (a file
 * is never coupled to one post). Every post is free to view. Lifecycle:
 * draft -> scheduled -> published -> archived. All creator-scoped.
 */
class PostsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function create_draft($creator_id, $caption = ''){
        $now = date('Y-m-d H:i:s');
        return parent::insert('posts', array(
            'creator_id' => (int) $creator_id,
            'caption'    => (string) $caption,
            'audience'   => 'free',
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
        if (array_key_exists('comments_enabled', $fields)) { $data['comments_enabled'] = !empty($fields['comments_enabled']) ? 1 : 0; }
        if (array_key_exists('on_cls', $fields))           { $data['on_cls'] = !empty($fields['on_cls']) ? 1 : 0; }
        // AI disclosure on cross-posts: null = on whenever the post has AI media, 0 = off, 1 = on.
        if (array_key_exists('ai_disclosure', $fields)) {
            $d = $fields['ai_disclosure'];
            $data['ai_disclosure'] = ($d === null || $d === '') ? null : (((int) $d === 1) ? 1 : 0);
        }
        // Social accounts that get this post as a Story: "<id>" = Story instead of a feed post, "both:<id>" = feed post and Story
        // (SocialShareService::placements). An empty value clears them.
        if (array_key_exists('story_accounts', $fields)) {
            $ids = is_array($fields['story_accounts']) ? $fields['story_accounts'] : explode(',', (string) $fields['story_accounts']);
            $ids = array_values(array_unique(array_filter(array_map(function ($v) { return preg_replace('/[^A-Za-z0-9_\-:]/', '', (string) $v); }, $ids), 'strlen')));
            $data['story_accounts'] = mb_substr(implode(',', $ids), 0, 1024);
        }
        if (array_key_exists('campaign_id', $fields)) { $data['campaign_id'] = ((int) $fields['campaign_id'] > 0) ? (int) $fields['campaign_id'] : null; }
        $res = parent::update('posts', $data, 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
        if (array_key_exists('on_cls', $fields)) { PublicThumbService::queue_purge(array('post' => (int) $id)); }   // off the site: public copies come down
        return $res;
    }

    /**
     * Replace a post's media with an ordered list of owned, ready assets. The
     * first asset is the cover unless $cover_id names another in the list.
     */
    public function set_assets($creator_id, $post_id, array $asset_ids, $cover_id = 0){
        // The same id twice would hit the post_assets key mid-way (after the old rows were deleted): keep the first.
        $asset_ids = array_values(array_unique(array_filter(array_map('intval', $asset_ids), function ($v) { return $v > 0; })));
        $owned = array();
        if (!empty($asset_ids)) {
            $rows = parent::select(
                "SELECT id FROM media_assets WHERE id IN (" . implode(',', $asset_ids) . ") AND creator_id = :c AND deleted_at IS NULL AND " . MediaAssetsModel::NOT_RECORDING,   // call recordings stay on their event
                array('c' => (int) $creator_id)
            );
            foreach ((array) $rows as $r) { $owned[(int) $r['id']] = true; }
        }
        $keep = array_values(array_filter($asset_ids, function ($a) use ($owned) { return isset($owned[$a]); }));
        $first = $keep[0] ?? null;
        // Mark the cover: the named cover if it made the cut, else the first asset.
        $cover = ((int) $cover_id > 0 && in_array((int) $cover_id, $keep, true)) ? (int) $cover_id : $first;

        $before = array_column((array) parent::select("SELECT asset_id FROM post_assets WHERE post_id = :p", array('p' => (int) $post_id)), 'asset_id');
        $own_tx = !$this->db->inTransaction();   // never nest: a caller's transaction covers this too
        if ($own_tx) { $this->db->beginTransaction(); }
        try {
            parent::delete_all('post_assets', 'post_id = :p', array('p' => (int) $post_id));
            foreach ($keep as $order => $aid) {
                parent::insert('post_assets', array(
                    'post_id'    => (int) $post_id,
                    'asset_id'   => (int) $aid,
                    'sort_order' => (int) $order,
                    'is_cover'   => ((int) $aid === (int) $cover) ? 1 : 0,
                ));
            }
            if ($own_tx) { $this->db->commit(); }
        } catch (\Throwable $e) {
            if ($own_tx && $this->db->inTransaction()) { $this->db->rollBack(); }   // the old media stays on the post
            throw $e;
        }
        (new MediaAssetsModel())->release_hidden($creator_id, $before);   // a file hidden from the library that this post just dropped is no longer needed
        return true;
    }

    /**
     * A media file is being deleted: take it out of every post that uses it. A published or
     * scheduled post left with no media goes back to drafts (flagged media_missing) so the feed,
     * profile and scheduler never show an empty frame. Returns ['affected' => n, 'unpublished' => n].
     */
    public function detach_asset($creator_id, $asset_id){
        $creator_id = (int) $creator_id; $asset_id = (int) $asset_id;
        $rows = parent::select(
            "SELECT p.id, p.state, pa.is_cover FROM post_assets pa JOIN posts p ON p.id = pa.post_id
             WHERE pa.asset_id = :a AND p.creator_id = :c", array('a' => $asset_id, 'c' => $creator_id));
        $affected = 0; $unpublished = 0;
        foreach ((array) $rows as $r) {
            $pid = (int) $r['id'];
            parent::delete_all('post_assets', 'post_id = :p AND asset_id = :a', array('p' => $pid, 'a' => $asset_id));
            $left = parent::select(
                "SELECT pa.asset_id FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
                 WHERE pa.post_id = :p AND ma.deleted_at IS NULL ORDER BY pa.sort_order ASC", array('p' => $pid));
            $affected++;
            if (is_array($left) && count($left) > 0) {
                if (!empty($r['is_cover'])) {
                    parent::update('post_assets', array('is_cover' => 1), 'post_id = :p AND asset_id = :a', array('p' => $pid, 'a' => (int) $left[0]['asset_id']));
                }
                continue;
            }
            $data = array('media_missing' => 1, 'updated_at' => date('Y-m-d H:i:s'));
            if (in_array((string) $r['state'], array('published', 'scheduled'), true)) {
                $data['state'] = 'draft'; $data['scheduled_at'] = null; $unpublished++;
            }
            parent::update('posts', $data, 'id = :id AND creator_id = :c', array('id' => $pid, 'c' => $creator_id));
        }
        PublicThumbService::queue_purge(array('assets' => array($asset_id)));   // detached media loses its public copy
        return array('affected' => $affected, 'unpublished' => $unpublished);
    }

    /** A post's assets with the media info needed for previews and the list. */
    public function get_assets($post_id){
        return parent::select(
            "SELECT pa.asset_id, pa.sort_order, pa.is_cover,
                    ma.creator_id, ma.type, ma.status, ma.duration_sec, ma.moderation_status, ma.provenance, ma.width, ma.height,
                    ma.thumb_key, ma.display_key, ma.poster_key, ma.blurred_key, ma.original_key, ma.mime, ma.deleted_at,
                    ma.public_thumb_key, ma.public_display_key, ma.public_width, ma.public_height
             FROM post_assets pa
             JOIN media_assets ma ON ma.id = pa.asset_id
             WHERE pa.post_id = :p
             ORDER BY pa.sort_order ASC",
            array('p' => (int) $post_id)
        );
    }

    /** Has this creator published a post (on Creator Link Studio) with AI-generated or AI-edited media? Drives the profile's AI badge. */
    public function has_published_ai($creator_id){
        $r = parent::select(
            "SELECT 1 FROM posts p JOIN post_assets pa ON pa.post_id = p.id JOIN media_assets ma ON ma.id = pa.asset_id
             WHERE p.creator_id = :c AND p.state = 'published' AND p.on_cls = 1
               AND ma.provenance IN ('generated', 'edited') AND ma.deleted_at IS NULL LIMIT 1",
            array('c' => (int) $creator_id));
        return is_array($r) && count($r) > 0;
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

    /**
     * Content check hold: true when any of the post's images or videos is flagged (or blocked) by the content check.
     * Such a post is not published; it stays in drafts until staff clear the image (ModerationHold).
     */
    public function has_flagged_media($post_id): bool {
        return $this->media_hold_state($post_id) !== '';
    }

    /** 'blocked' (a moderator-blocked image: never publishable) | 'flagged' (held until staff clear it) | '' (clean). */
    public function media_hold_state($post_id): string {
        $rows = parent::select(
            "SELECT SUM(ma.moderation_status = 'blocked') AS blocked_n, SUM(ma.moderation_status = 'flagged') AS flagged_n
             FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
             WHERE pa.post_id = :p AND ma.deleted_at IS NULL AND ma.type IN ('image', 'video')",
            array('p' => (int) $post_id)
        );
        $r = (is_array($rows) && count($rows) === 1) ? $rows[0] : array();
        if ((int) ($r['blocked_n'] ?? 0) > 0) { return 'blocked'; }
        if ((int) ($r['flagged_n'] ?? 0) > 0) { return 'flagged'; }
        return '';
    }

    /** Published or scheduled posts that use this asset (for the hold after a scan flags it). */
    public function live_posts_for_asset($asset_id){
        return (array) parent::select(
            "SELECT DISTINCT p.id, p.creator_id FROM post_assets pa JOIN posts p ON p.id = pa.post_id
             WHERE pa.asset_id = :a AND p.state IN ('published', 'scheduled')",
            array('a' => (int) $asset_id)
        );
    }

    /** Drafts held for $reason that use this asset (for the release after staff clear it). */
    public function held_posts_for_asset($asset_id, $reason){
        return (array) parent::select(
            "SELECT DISTINCT p.id, p.creator_id, p.caption FROM post_assets pa JOIN posts p ON p.id = pa.post_id
             WHERE pa.asset_id = :a AND p.state = 'draft' AND p.held_reason = :r",
            array('a' => (int) $asset_id, 'r' => (string) $reason)
        );
    }

    /** The post is no longer held (it stays a draft for the creator to publish). */
    public function clear_hold($creator_id, $id){
        return parent::update('posts', array('held_reason' => null, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /**
     * Creator-facing version of moderation_map() for the Studio: 'blocked' | 'flagged' (held by the content check)
     * | 'pending' (unscanned/error, hidden from viewers until scanned) | 'ok'.
     */
    public function studio_moderation_map(array $post_ids){
        $ids = array_filter(array_map('intval', $post_ids));
        if (!$ids) { return array(); }
        $out = array();
        foreach ($this->moderation_map($ids) as $pid => $st) { $out[$pid] = $st; }
        foreach ($ids as $pid) { if (!isset($out[$pid])) { $out[$pid] = 'ok'; } }
        return $out;
    }

    /**
     * Per-post moderation gate (images and videos): post_id => 'blocked' | 'flagged' | 'pending'. Posts whose media
     * is all approved are omitted. 'blocked' (suspected minors) and 'flagged' (the content check flagged it) are never
     * shown to anyone but the creator; 'pending'/'error' (unscanned) hide the post until it is scanned.
     */
    public function moderation_map(array $post_ids){
        $ids = array_filter(array_map('intval', $post_ids));
        if (!$ids) { return array(); }
        $in = implode(',', $ids);
        $rows = parent::select(
            "SELECT pa.post_id,
                    SUM(ma.moderation_status = 'blocked') AS blocked_n,
                    SUM(ma.moderation_status = 'flagged') AS flagged_n,
                    SUM(ma.moderation_status NOT IN ('approved','blocked','flagged','n_a')) AS held_n
             FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
             WHERE pa.post_id IN ($in) AND ma.deleted_at IS NULL AND ma.type IN ('image', 'video')
             GROUP BY pa.post_id"
        );
        $out = array();
        foreach ((array) $rows as $r) {
            if ((int) $r['blocked_n'] > 0)     { $out[(int) $r['post_id']] = 'blocked'; }
            elseif ((int) $r['flagged_n'] > 0) { $out[(int) $r['post_id']] = 'flagged'; }
            elseif ((int) $r['held_n'] > 0)    { $out[(int) $r['post_id']] = 'pending'; }   // unscanned / scan error
        }
        return $out;
    }

    /** A creator's published page posts, newest first. $limit 0 = all; the profile pages them (ProfilePosts). */
    /** $q: only posts whose caption contains it (the profile's search box, across every post, not just the loaded page). */
    public function get_published_for_creator($creator_id, $limit = 0, $offset = 0, $q = ''){
        $page = (int) $limit > 0 ? ' LIMIT ' . max(0, (int) $offset) . ', ' . (int) $limit : '';
        $p = array('c' => (int) $creator_id); $find = '';
        $q = trim((string) $q);
        if ($q !== '') {
            // captions may be stored as typed or html-encoded (older rows): match either form
            $find = ' AND (caption LIKE :q1 OR caption LIKE :q2)';
            $p['q1'] = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
            $p['q2'] = '%' . addcslashes(htmlspecialchars(mb_substr($q, 0, 100), ENT_QUOTES, 'UTF-8'), '%_\\') . '%';
        }
        return parent::select(
            "SELECT * FROM posts WHERE creator_id = :c AND state = 'published' AND on_cls = 1 AND audience = 'free'" . $find . "
             ORDER BY published_at DESC, id DESC" . $page,
            $p
        );
    }

    /** get_assets() for several posts in one query: post_id => that post's rows, in sort order. */
    public function assets_for_posts(array $post_ids){
        $ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (!$ids) { return array(); }
        $rows = parent::select(
            "SELECT pa.post_id, pa.asset_id, pa.sort_order, pa.is_cover,
                    ma.creator_id, ma.type, ma.status, ma.duration_sec, ma.moderation_status, ma.provenance, ma.width, ma.height,
                    ma.thumb_key, ma.display_key, ma.poster_key, ma.blurred_key, ma.original_key, ma.mime, ma.deleted_at,
                    ma.public_thumb_key, ma.public_display_key, ma.public_width, ma.public_height
             FROM post_assets pa
             JOIN media_assets ma ON ma.id = pa.asset_id
             WHERE pa.post_id IN (" . implode(',', $ids) . ")
             ORDER BY pa.post_id ASC, pa.sort_order ASC, pa.asset_id ASC"
        );
        $out = array();
        foreach ((array) $rows as $r) { $out[(int) $r['post_id']][] = $r; }
        return $out;
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
        if ($state !== 'draft') { $data['held_reason'] = null; }   // scheduled or published again: no longer held
        $data['scheduled_at'] = ($state === 'scheduled') ? $scheduled_at : null;
        if ($state === 'published') {
            $post = $this->get_one($creator_id, $id);
            $data['published_at'] = ($post && !empty($post['published_at'])) ? $post['published_at'] : ($published_at ?: date('Y-m-d H:i:s'));
        }
        $res = parent::update('posts', $data, 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
        if ($state !== 'published') { PublicThumbService::queue_purge(array('post' => (int) $id)); }   // unpublished: public copies come down
        return $res;
    }

    /**
     * Publish now, exactly once: true only for the request that moved the post INTO published.
     * Callers notify followers and cross-post only on true, so a double-click or an MCP retry
     * can't fan out twice.
     */
    public function publish_once($creator_id, $id){
        $post = $this->get_one($creator_id, $id);
        if (!$post || ($post['state'] ?? '') === 'published') { return false; }
        $now = date('Y-m-d H:i:s');
        $moved = parent::update('posts',
            array('state' => 'published', 'scheduled_at' => null, 'updated_at' => $now, 'held_reason' => null,
                  'published_at' => !empty($post['published_at']) ? $post['published_at'] : $now),
            "id = :id AND creator_id = :c AND state <> 'published'",
            array('id' => (int) $id, 'c' => (int) $creator_id)) > 0;
        if ($moved) { Analytics::event('post_published', array('user_id' => (int) $creator_id, 'creator_id' => (int) $creator_id, 'meta' => 'post ' . (int) $id)); }
        return $moved;
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
        $new_id = (int) $this->create_draft($creator_id, (string) $src['caption']);
        $this->update_fields($creator_id, $new_id, array(
            'caption' => (string) $src['caption'], 'comments_enabled' => $src['comments_enabled'],
        ));
        $assets = $this->get_assets($id);
        $ids = array(); $cover = 0;
        foreach ($assets as $a) { $ids[] = (int) $a['asset_id']; if ((int) $a['is_cover'] === 1) { $cover = (int) $a['asset_id']; } }
        $this->set_assets($creator_id, $new_id, $ids, $cover);
        return $new_id;
    }

    /**
     * Publish scheduled posts whose time has arrived — one creator's (Studio fallback) or
     * everyone's ($creator_id = 0, cron). Posts holding moderator-blocked media stay scheduled.
     * Each post is claimed with a conditional UPDATE so the cron and a Studio load can't both
     * flip it. Returns [post_id => creator_id] for the posts THIS call published (notify those).
     */
    public function publish_due($creator_id = 0){
        $now    = date('Y-m-d H:i:s');
        $params = array('n' => $now);
        $mine   = '';
        if ((int) $creator_id > 0) { $mine = ' AND p.creator_id = :c'; $params['c'] = (int) $creator_id; }
        $rows = parent::select(
            "SELECT p.id, p.creator_id, p.published_at FROM posts p
             JOIN user_accounts u ON u.user_id = p.creator_id AND u.deleted = 0 AND u.user_status = 'Active'
             WHERE p.state = 'scheduled' AND p.scheduled_at <= :n AND p.media_missing = 0" . $mine . "
               AND NOT EXISTS (SELECT 1 FROM post_assets pa JOIN media_assets m ON m.id = pa.asset_id
                               WHERE pa.post_id = p.id AND m.deleted_at IS NULL AND m.moderation_status = 'blocked')
             ORDER BY p.scheduled_at ASC LIMIT 200",
            $params
        );
        $flipped = array();
        foreach ((array) $rows as $r) {
            $n = parent::update('posts',
                array('state' => 'published', 'published_at' => !empty($r['published_at']) ? $r['published_at'] : $now,
                      'scheduled_at' => null, 'updated_at' => $now),
                "id = :id AND state = 'scheduled'",
                array('id' => (int) $r['id']));
            if ($n > 0) { $flipped[(int) $r['id']] = (int) $r['creator_id']; }
        }
        return $flipped;
    }

    /** Hold a post for the plan: back to drafts, never published, with the reason the Studio shows. */
    public function hold_for_plan($creator_id, $id, $reason = 'plan'){
        return parent::update('posts',
            array('state' => 'draft', 'scheduled_at' => null, 'published_at' => null, 'held_reason' => (string) $reason, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function delete_post($creator_id, $id){
        $owned = $this->get_one($creator_id, $id);
        if (!$owned) { return 0; }
        $asset_ids = array_column((array) parent::select("SELECT asset_id FROM post_assets WHERE post_id = :p", array('p' => (int) $id)), 'asset_id');
        parent::delete_all('post_assets', 'post_id = :p', array('p' => (int) $id));
        $res = parent::delete('posts', 'id = :id AND creator_id = :c', 1,
            array('id' => (int) $id, 'c' => (int) $creator_id));
        PublicThumbService::queue_purge(array('assets' => $asset_ids));   // its media may no longer be in any free post
        (new MediaAssetsModel())->release_hidden($creator_id, $asset_ids);   // files that were only kept for this post
        return $res;
    }

    /** Claim the new-post notice for one follower; true only the first time (PRIMARY KEY is the mutex). */
    public function claim_post_notice($post_id, $user_id){
        try {
            parent::insert('post_notify_sent', array('post_id' => (int) $post_id, 'user_id' => (int) $user_id, 'sent_at' => gmdate('Y-m-d H:i:s')));
            return true;
        } catch (\Throwable $e) { return false; }   // already told
    }
}
