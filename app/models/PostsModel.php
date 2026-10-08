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
        if (array_key_exists('on_cls', $fields))           { $data['on_cls'] = !empty($fields['on_cls']) ? 1 : 0; }
        // AI disclosure on cross-posts: null = on whenever the post has AI media, 0 = off, 1 = on.
        if (array_key_exists('ai_disclosure', $fields)) {
            $d = $fields['ai_disclosure'];
            $data['ai_disclosure'] = ($d === null || $d === '') ? null : (((int) $d === 1) ? 1 : 0);
        }
        // Social accounts that get this post as a Story instead of a feed post.
        if (array_key_exists('story_accounts', $fields)) {
            $ids = is_array($fields['story_accounts']) ? $fields['story_accounts'] : explode(',', (string) $fields['story_accounts']);
            $ids = array_values(array_unique(array_filter(array_map(function ($v) { return preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $v); }, $ids), 'strlen')));
            $data['story_accounts'] = mb_substr(implode(',', $ids), 0, 1024);
        }
        if (array_key_exists('campaign_id', $fields)) { $data['campaign_id'] = ((int) $fields['campaign_id'] > 0) ? (int) $fields['campaign_id'] : null; }
        $res = parent::update('posts', $data, 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
        // Multi-tier targeting lives in post_tiers. Passing tier_ids replaces the set; a
        // non-subscriber audience clears it. (tier_id stays for legacy single-tier rows.)
        if (array_key_exists('tier_ids', $fields)) {
            $this->set_tiers((int) $id, (array) $fields['tier_ids']);
        } elseif (array_key_exists('audience', $fields) && $data['audience'] !== 'subscribers') {
            $this->set_tiers((int) $id, array());
        }
        return $res;
    }

    /** Replace the tiers a subscribers-only post is limited to. Empty = every active subscriber. */
    public function set_tiers($post_id, array $plan_ids){
        $post_id = (int) $post_id;
        parent::delete_all('post_tiers', 'post_id = :p', array('p' => $post_id));
        foreach (array_unique(array_filter(array_map('intval', $plan_ids))) as $pid) {
            parent::insert('post_tiers', array('post_id' => $post_id, 'plan_id' => $pid));
        }
    }

    /** post_id => [plan_id, ...] for the given posts (posts with no rows are omitted). */
    public function tiers_for_posts(array $post_ids){
        $ids = array_filter(array_map('intval', $post_ids));
        if (!$ids) { return array(); }
        $in = implode(',', $ids);
        $out = array();
        foreach ((array) parent::select("SELECT post_id, plan_id FROM post_tiers WHERE post_id IN ($in)") as $r) {
            $out[(int) $r['post_id']][] = (int) $r['plan_id'];
        }
        return $out;
    }

    /**
     * Is a viewer with these active plan ids (and this max active price) entitled to a
     * subscribers-only post? Tier rows win (any match); a legacy tier_id with no rows keeps
     * the old "this tier and higher" rule; nothing set = any active subscriber.
     */
    public static function tier_entitled(array $post, array $post_tiers, array $viewer_plan_ids, $viewer_max_price, array $plan_prices){
        if (!empty($post_tiers)) { return count(array_intersect($post_tiers, array_map('intval', $viewer_plan_ids))) > 0; }
        $tier_id = (int) ($post['tier_id'] ?? 0);
        if ($tier_id > 0) { return $viewer_max_price !== null && (int) $viewer_max_price >= (int) ($plan_prices[$tier_id] ?? 0); }
        return !empty($viewer_plan_ids);
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
                "SELECT id FROM media_assets WHERE id = :a AND creator_id = :c AND deleted_at IS NULL AND " . MediaAssetsModel::NOT_RECORDING,   // call recordings stay on their event
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
        return array('affected' => $affected, 'unpublished' => $unpublished);
    }

    /** A post's assets with the media info needed for previews and the list. */
    public function get_assets($post_id){
        return parent::select(
            "SELECT pa.asset_id, pa.sort_order, pa.is_cover,
                    ma.creator_id, ma.type, ma.status, ma.duration_sec, ma.moderation_status, ma.is_adult, ma.provenance, ma.width, ma.height,
                    ma.thumb_key, ma.display_key, ma.poster_key, ma.blurred_key, ma.original_key, ma.mime, ma.deleted_at
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

    /** Published posts for a creator's public profile, newest first. */
    /**
     * Per-post moderation gate for the given posts, considering IMAGES only (videos are
     * not scanned, so they never gate a post). Returns post_id => 'blocked'|'pending'|'flagged':
     *   - 'blocked'  → has an image the moderator blocked (suspected sexual/minors):
     *                  hide from EVERYONE (incl. the creator) and never publishable.
     *   - 'pending'  → has an image not yet cleared (unscanned/error): hide from everyone
     *                  but the creator until it's scanned ("scanned before available").
     *   - 'flagged'  → all images scanned and at least one is adult: hide from viewers with
     *                  "show adult content" off.
     * Posts with all images approved are omitted (fully visible).
     */
    /**
     * Creator-facing version of moderation_map() for the Studio: 'blocked' | 'pending'
     * (unscanned/error, hidden from fans until scanned) | 'adult' (shown to opted-in fans) | 'ok'.
     */
    public function studio_moderation_map(array $post_ids){
        $ids = array_filter(array_map('intval', $post_ids));
        if (!$ids) { return array(); }
        $in = implode(',', $ids);
        $rows = parent::select(
            "SELECT pa.post_id,
                    SUM(ma.moderation_status = 'blocked') AS blocked_n,
                    SUM(ma.moderation_status NOT IN ('approved','blocked','flagged','n_a')) AS unscanned_n,
                    SUM(ma.moderation_status = 'flagged' OR (ma.moderation_status = 'approved' AND ma.is_adult = 1)) AS adult_n
             FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
             WHERE pa.post_id IN ($in) AND ma.deleted_at IS NULL AND ma.type IN ('image', 'video')
             GROUP BY pa.post_id"
        );
        $out = array();
        foreach ((array) $rows as $r) {
            $pid = (int) $r['post_id'];
            if ((int) $r['blocked_n'] > 0)        { $out[$pid] = 'blocked'; }
            elseif ((int) $r['unscanned_n'] > 0)  { $out[$pid] = 'pending'; }
            elseif ((int) $r['adult_n'] > 0)      { $out[$pid] = 'adult'; }
            else                                  { $out[$pid] = 'ok'; }
        }
        return $out;
    }

    public function moderation_map(array $post_ids){
        $ids = array_filter(array_map('intval', $post_ids));
        if (!$ids) { return array(); }
        $in = implode(',', $ids);
        // Adult content is NOT held for human approval (Daniel, 2026-09-21): a 'flagged' image
        // is simply adult and shows to opted-in viewers right away. Only 'blocked' (suspected
        // minors) is a hard stop; 'pending'/'error' (unscanned) hide the post until scanned.
        $rows = parent::select(
            "SELECT pa.post_id,
                    SUM(ma.moderation_status = 'blocked') AS blocked_n,
                    SUM(ma.moderation_status NOT IN ('approved','blocked','flagged','n_a')) AS held_n,
                    SUM(ma.moderation_status = 'flagged' OR (ma.moderation_status = 'approved' AND ma.is_adult = 1)) AS adult_n
             FROM post_assets pa JOIN media_assets ma ON ma.id = pa.asset_id
             WHERE pa.post_id IN ($in) AND ma.deleted_at IS NULL AND ma.type IN ('image', 'video')
             GROUP BY pa.post_id"
        );
        $out = array();
        foreach ((array) $rows as $r) {
            if ((int) $r['blocked_n'] > 0)   { $out[(int) $r['post_id']] = 'blocked'; }
            elseif ((int) $r['held_n'] > 0)  { $out[(int) $r['post_id']] = 'pending'; }   // unscanned / scan error
            elseif ((int) $r['adult_n'] > 0) { $out[(int) $r['post_id']] = 'adult'; }
        }
        return $out;
    }

    public function get_published_for_creator($creator_id){
        return parent::select(
            "SELECT * FROM posts WHERE creator_id = :c AND state = 'published' AND on_cls = 1
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

    /** Remember the Fanvue post this one was mirrored to (see FanvueShareService). */
    public function set_fanvue_post_uuid($creator_id, $id, $uuid){
        return parent::update('posts',
            array('fanvue_post_uuid' => ($uuid !== '' && $uuid !== null) ? (string) $uuid : null, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND creator_id = :c', array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function set_state($creator_id, $id, $state, $scheduled_at = null, $published_at = null){
        $data = array('state' => $state, 'updated_at' => date('Y-m-d H:i:s'));
        if ($state !== 'draft') { $data['held_reason'] = null; }   // scheduled or published again: no longer held
        $data['scheduled_at'] = ($state === 'scheduled') ? $scheduled_at : null;
        if ($state === 'published') {
            $post = $this->get_one($creator_id, $id);
            $data['published_at'] = ($post && !empty($post['published_at'])) ? $post['published_at'] : ($published_at ?: date('Y-m-d H:i:s'));
        }
        return parent::update('posts', $data, 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
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
        return parent::update('posts',
            array('state' => 'published', 'scheduled_at' => null, 'updated_at' => $now, 'held_reason' => null,
                  'published_at' => !empty($post['published_at']) ? $post['published_at'] : $now),
            "id = :id AND creator_id = :c AND state <> 'published'",
            array('id' => (int) $id, 'c' => (int) $creator_id)) > 0;
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
            'tier_ids' => $this->tiers_for_posts(array($id))[(int) $id] ?? array(),
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
        parent::delete_all('post_assets', 'post_id = :p', array('p' => (int) $id));
        parent::delete_all('post_tiers',  'post_id = :p', array('p' => (int) $id));
        return parent::delete('posts', 'id = :id AND creator_id = :c', 1,
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Claim the new-post notice for one follower; true only the first time (PRIMARY KEY is the mutex). */
    public function claim_post_notice($post_id, $user_id){
        try {
            parent::insert('post_notify_sent', array('post_id' => (int) $post_id, 'user_id' => (int) $user_id, 'sent_at' => gmdate('Y-m-d H:i:s')));
            return true;
        } catch (\Throwable $e) { return false; }   // already told
    }
}
