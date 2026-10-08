<?php
class CreatorProfileModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** The creator's profile row, or a defaults array if none exists yet. */
    public function get_for_user($user_id){
        $rows = parent::select(
            "SELECT * FROM creator_profiles WHERE user_id = :user_id",
            array('user_id' => (int) $user_id)
        );
        if (is_array($rows) && count($rows) === 1) {
            return $rows[0];
        }
        return array(
            'display_name' => '',
            'bio'          => '',
            'location'     => '',
            'avatar_url'   => '',
            'cover_url'    => '',
        );
    }

    private function exists($user_id){
        $rows = parent::select(
            "SELECT id FROM creator_profiles WHERE user_id = :user_id",
            array('user_id' => (int) $user_id)
        );
        return is_array($rows) && count($rows) === 1;
    }

    /** Upsert the editable text/branding fields. $fields is an associative array. */
    public function save($user_id, $fields){
        $now  = date('Y-m-d H:i:s');
        $data = array(
            'display_name' => (string) ($fields['display_name'] ?? ''),
            'bio'          => (string) ($fields['bio'] ?? ''),
            'location'     => (string) ($fields['location'] ?? ''),
            'updated_at'   => $now,
        );

        if ($this->exists($user_id)) {
            return parent::update('creator_profiles', $data, 'user_id = :user_id', array('user_id' => (int) $user_id));
        }
        $data['user_id']    = (int) $user_id;
        $data['created_at'] = $now;
        return parent::insert('creator_profiles', $data);
    }

    /** Set a single image column (avatar_url or cover_url), upserting the row. */
    public function set_image($user_id, $column, $url){
        if (!in_array($column, array('avatar_url', 'cover_url'), true)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        if ($this->exists($user_id)) {
            // a new image drops the old webp copy; PublicThumbService::profile_image() makes the new one.
            $webp = ($column === 'avatar_url') ? 'avatar_webp_url' : 'cover_webp_url';
            $r = parent::update('creator_profiles', array($column => $url, $webp => null, 'updated_at' => $now), 'user_id = :user_id', array('user_id' => (int) $user_id));
            DirectoryService::queue_recheck($user_id);   // a listed creator drops out of the directory until the new image passes
            return $r;
        }
        return parent::insert('creator_profiles', array(
            'user_id'    => (int) $user_id,
            $column      => $url,
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

    /** Store the webp copy of the current avatar or cover, only while that image is still the current one. */
    public function set_image_webp($user_id, $column, $source_url, $webp_url){
        if (!in_array($column, array('avatar_url', 'cover_url'), true)) { return false; }
        $webp = ($column === 'avatar_url') ? 'avatar_webp_url' : 'cover_webp_url';
        return parent::update('creator_profiles', array($webp => (string) $webp_url === '' ? null : (string) $webp_url),
            "user_id = :user_id AND $column = :src", array('user_id' => (int) $user_id, 'src' => (string) $source_url));
    }

    /**
     * Profiles whose avatar or cover has no webp copy yet (PublicThumbService backfill): only our own uploads
     * (URLs starting with $prefix, the bucket's public URL), and GIFs keep their animation, so they're skipped.
     */
    public function missing_image_webp($prefix, $limit = 500){
        $limit = max(1, min(2000, (int) $limit));
        $like = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), (string) $prefix) . '%';
        return (array) parent::select(
            "SELECT user_id, avatar_url, avatar_webp_url, cover_url, cover_webp_url FROM creator_profiles
             WHERE (avatar_url LIKE :p1 AND avatar_webp_url IS NULL AND avatar_url NOT LIKE '%.gif')
                OR (cover_url LIKE :p2 AND cover_webp_url IS NULL AND cover_url NOT LIKE '%.gif')
             ORDER BY user_id ASC LIMIT $limit", array('p1' => $like, 'p2' => $like));
    }

    /* ---- creator directory (/creators) ---- */

    /** The directory switch and category. */
    public function set_directory($user_id, $listed, $category){
        if (!$this->exists($user_id)) { $this->save($user_id, array()); }
        return parent::update('creator_profiles', array('directory_listed' => $listed ? 1 : 0, 'directory_category' => $category !== '' ? $category : null, 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :user_id', array('user_id' => (int) $user_id));
    }

    /** Creators on a paid plan who aren't listed in the directory (for the one-off cron/directory_backfill.php). */
    public function paid_unlisted_creators(): array {
        $rows = parent::select(
            "SELECT u.user_id FROM user_accounts u
             JOIN user_roles r ON r.id = u.role_id AND r.role_name = 'Creator'
             LEFT JOIN creator_profiles cp ON cp.user_id = u.user_id
             WHERE u.deleted = 0 AND u.user_status = 'Active' AND " . Plan::paid_sql('u') . " AND COALESCE(cp.directory_listed, 0) = 0
             ORDER BY u.user_id");
        return array_map(function ($r) { return (int) $r['user_id']; }, (array) $rows);
    }

    /** Record which images passed the adult check (hash of "avatar|cover"), or null when they didn't. */
    public function set_directory_media($user_id, $hash){
        return parent::update('creator_profiles', array('directory_media_ok' => $hash, 'directory_checked_at' => gmdate('Y-m-d H:i:s')),
            'user_id = :user_id', array('user_id' => (int) $user_id));
    }

    /**
     * Who may appear: opted in, images checked as they are now, an active creator account, and at least one
     * published page post whose images are all scanned and not adult. The directory is safe-for-work only.
     */
    private function directory_where(): string {
        return "cp.directory_listed = 1
            AND cp.directory_media_ok = SHA1(CONCAT(COALESCE(cp.avatar_url, ''), '|', COALESCE(cp.cover_url, '')))
            AND u.deleted = 0 AND u.user_status = 'Active' AND u.is_demo = 0 AND r.role_name = 'Creator' AND u.u_name <> '' AND COALESCE(cp.avatar_url, '') <> ''
            AND " . Plan::paid_sql('u') . "
            AND EXISTS (SELECT 1 FROM posts p JOIN post_assets pa ON pa.post_id = p.id JOIN media_assets ma ON ma.id = pa.asset_id
                        WHERE p.creator_id = u.user_id AND p.state = 'published' AND p.on_cls = 1
                          AND ma.deleted_at IS NULL AND ma.type = 'image' AND ma.moderation_status = 'approved' AND ma.is_adult = 0
                          AND NOT EXISTS (SELECT 1 FROM post_assets pa2 JOIN media_assets m2 ON m2.id = pa2.asset_id
                                          WHERE pa2.post_id = p.id AND m2.deleted_at IS NULL AND m2.type IN ('image', 'video')
                                            AND m2.moderation_status <> 'n_a'
                                            AND (m2.moderation_status <> 'approved' OR m2.is_adult = 1)))";
    }

    /** The card columns: profile, follower count, last post and the cheapest active paid membership. */
    private function directory_cols(): string {
        return "u.user_id, u.u_name, u.verified, cp.display_name, cp.bio, cp.avatar_url, cp.avatar_webp_url, cp.directory_category,
                    (SELECT COUNT(*) FROM follows f WHERE f.creator_id = u.user_id) AS followers,
                    (SELECT MAX(p.published_at) FROM posts p WHERE p.creator_id = u.user_id AND p.state = 'published') AS last_post,
                    (SELECT cpl.price_cents FROM creator_plans cpl WHERE cpl.user_id = u.user_id AND cpl.is_active = 1 AND cpl.price_cents > 0 ORDER BY cpl.price_cents, cpl.id LIMIT 1) AS min_price_cents,
                    (SELECT cpl.billing_interval FROM creator_plans cpl WHERE cpl.user_id = u.user_id AND cpl.is_active = 1 AND cpl.price_cents > 0 ORDER BY cpl.price_cents, cpl.id LIMIT 1) AS min_price_interval";
    }

    /** Category and search filters (bound). $q under 2 characters is ignored; matches name, handle or bio. */
    private function directory_filter($category, $q, array &$params): string {
        $sql = '';
        if ((string) $category !== '') { $sql .= ' AND cp.directory_category = :cat'; $params['cat'] = (string) $category; }
        $q = ltrim(trim((string) $q), '@');
        if (mb_strlen($q) >= 2) {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $sql .= ' AND (cp.display_name LIKE :q1 OR u.u_name LIKE :q2 OR cp.bio LIKE :q3)';
            $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like;
        }
        return $sql;
    }

    /**
     * Listed creators for one page. $category '' = all; $sort is a DirectoryService::SORTS key: trending (new followers
     * in the last 7 days, then post views in the last 7 days), new (became a creator most recently), active (published
     * page posts in the last 30 days).
     */
    public function directory($category, $q, $sort, $limit, $offset){
        $params = array();
        $filter = $this->directory_filter($category, $q, $params);
        if ($sort === 'new') {
            $extra = ''; $order = 'COALESCE(u.creator_since, cp.created_at) DESC, u.user_id DESC';
        } elseif ($sort === 'active') {
            $extra = ", (SELECT COUNT(*) FROM posts p WHERE p.creator_id = u.user_id AND p.state = 'published' AND p.on_cls = 1 AND p.published_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS posts_30d";
            $order = 'posts_30d DESC, last_post DESC, u.user_id DESC';
        } else {
            $extra = ", (SELECT COUNT(*) FROM follows f WHERE f.creator_id = u.user_id AND f.created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY) AS follows_7d
                    , (SELECT COUNT(*) FROM post_views pv JOIN posts p ON p.id = pv.post_id WHERE p.creator_id = u.user_id AND pv.created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY) AS views_7d";
            $order = 'follows_7d DESC, views_7d DESC, followers DESC, u.user_id DESC';
        }
        return (array) parent::select(
            "SELECT " . $this->directory_cols() . $extra . "
             FROM creator_profiles cp JOIN user_accounts u ON u.user_id = cp.user_id JOIN user_roles r ON r.id = u.role_id
             WHERE " . $this->directory_where() . $filter . "
             ORDER BY " . $order . "
             LIMIT " . (int) $limit . " OFFSET " . (int) $offset, $params);
    }

    /** How many listed creators match (same filters as directory()). */
    public function directory_total($category, $q): int {
        $params = array();
        $filter = $this->directory_filter($category, $q, $params);
        $r = parent::select(
            "SELECT COUNT(*) AS n FROM creator_profiles cp JOIN user_accounts u ON u.user_id = cp.user_id JOIN user_roles r ON r.id = u.role_id
             WHERE " . $this->directory_where() . $filter, $params);
        return (int) ($r[0]['n'] ?? 0);
    }

    /**
     * The featured row: listed Studio creators first, then Creator-plan creators (legacy Pro included) in the open
     * slots, up to $limit. The order within each group is a hash of the day and the user id, so it rotates daily and
     * is the same for everyone on a given day ($day 'Y-m-d').
     */
    public function directory_featured($category, $day, $limit){
        $params = array('day' => (string) $day);
        $filter = $this->directory_filter($category, '', $params);
        return (array) parent::select(
            "SELECT " . $this->directory_cols() . ", u.plan_tier
             FROM creator_profiles cp JOIN user_accounts u ON u.user_id = cp.user_id JOIN user_roles r ON r.id = u.role_id
             WHERE " . $this->directory_where() . $filter . " AND u.plan_tier IN ('studio', 'creator', 'pro')
             ORDER BY (u.plan_tier = 'studio') DESC, SHA1(CONCAT(:day, ':', u.user_id))
             LIMIT " . (int) $limit, $params);
    }

    /** category => number of listed creators, for the active niches that have any. */
    public function directory_counts(){
        $out = array();
        foreach ((array) parent::select(
            "SELECT cp.directory_category AS cat, COUNT(*) AS n
             FROM creator_profiles cp JOIN user_accounts u ON u.user_id = cp.user_id JOIN user_roles r ON r.id = u.role_id
             JOIN niches n ON n.slug = cp.directory_category AND n.active = 1
             WHERE " . $this->directory_where() . " GROUP BY cp.directory_category") as $r) {
            $out[(string) $r['cat']] = (int) $r['n'];
        }
        return $out;
    }

    /** Is this one creator in the directory right now? */
    public function directory_eligible($user_id){
        $r = parent::select("SELECT 1 FROM creator_profiles cp JOIN user_accounts u ON u.user_id = cp.user_id JOIN user_roles r ON r.id = u.role_id
                             WHERE cp.user_id = :uid AND " . $this->directory_where(), array('uid' => (int) $user_id));
        return is_array($r) && count($r) > 0;
    }


    /* ---- "More Creators Like This" on public profiles ---- */

    /** The creator's switch for the block (Settings -> Creator Profile). */
    public function set_similar_off($user_id, $off){
        if (!$this->exists($user_id)) { $this->save($user_id, array()); }
        return parent::update('creator_profiles', array('similar_off' => $off ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :user_id', array('user_id' => (int) $user_id));
    }

    /**
     * Up to $limit directory creators in the same niche as $user_id (any niche when they have none), never themselves
     * or anyone the viewer blocked or was blocked by. Same order for everyone on a given day ($day 'Y-m-d').
     */
    public function similar($user_id, $category, $day, $limit, $viewer_id = 0){
        $params = array('self' => (int) $user_id, 'day' => (string) $day . ':' . (int) $user_id);
        $cat = '';
        if ((string) $category !== '') { $cat = ' AND cp.directory_category = :cat'; $params['cat'] = (string) $category; }
        $blk = '';
        if ((int) $viewer_id > 0) { $blk = ' AND ' . BlocksModel::exclude_sql('u.user_id', 'bv1', 'bv2'); $params['bv1'] = (int) $viewer_id; $params['bv2'] = (int) $viewer_id; }
        return (array) parent::select(
            "SELECT u.user_id, u.u_name, u.verified, cp.display_name, cp.avatar_url, cp.avatar_webp_url,
                    (SELECT MIN(cpl.price_cents) FROM creator_plans cpl WHERE cpl.user_id = u.user_id AND cpl.is_active = 1 AND cpl.price_cents > 0) AS paid_from_cents,
                    (SELECT cpl.billing_interval FROM creator_plans cpl WHERE cpl.user_id = u.user_id AND cpl.is_active = 1 AND cpl.price_cents > 0 ORDER BY cpl.price_cents, cpl.id LIMIT 1) AS paid_from_interval,
                    (SELECT COUNT(*) FROM creator_plans cpl WHERE cpl.user_id = u.user_id AND cpl.is_active = 1 AND cpl.price_cents = 0) AS free_plans
             FROM creator_profiles cp JOIN user_accounts u ON u.user_id = cp.user_id JOIN user_roles r ON r.id = u.role_id
             WHERE " . $this->directory_where() . " AND u.user_id <> :self" . $cat . $blk . "
             ORDER BY SHA1(CONCAT(:day, ':', u.user_id))
             LIMIT " . (int) $limit, $params);
    }
}
