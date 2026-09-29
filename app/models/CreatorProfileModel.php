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
            $r = parent::update('creator_profiles', array($column => $url, 'updated_at' => $now), 'user_id = :user_id', array('user_id' => (int) $user_id));
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

    /* ---- creator directory (/creators) ---- */

    /** The directory switch and category. */
    public function set_directory($user_id, $listed, $category){
        if (!$this->exists($user_id)) { $this->save($user_id, array()); }
        return parent::update('creator_profiles', array('directory_listed' => $listed ? 1 : 0, 'directory_category' => $category !== '' ? $category : null, 'updated_at' => date('Y-m-d H:i:s')),
            'user_id = :user_id', array('user_id' => (int) $user_id));
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
            AND u.deleted = 0 AND u.user_status = 'Active' AND r.role_name = 'Creator' AND u.u_name <> ''
            AND " . Plan::paid_sql('u') . "
            AND EXISTS (SELECT 1 FROM posts p JOIN post_assets pa ON pa.post_id = p.id JOIN media_assets ma ON ma.id = pa.asset_id
                        WHERE p.creator_id = u.user_id AND p.state = 'published' AND p.on_cls = 1
                          AND ma.deleted_at IS NULL AND ma.type = 'image' AND ma.moderation_status = 'approved' AND ma.is_adult = 0
                          AND NOT EXISTS (SELECT 1 FROM post_assets pa2 JOIN media_assets m2 ON m2.id = pa2.asset_id
                                          WHERE pa2.post_id = p.id AND m2.deleted_at IS NULL AND m2.type IN ('image', 'video')
                                            AND m2.moderation_status <> 'n_a'
                                            AND (m2.moderation_status <> 'approved' OR m2.is_adult = 1)))";
    }

    /** Listed creators, verified first, then most recently active. $category '' = all. */
    public function directory($category, $limit, $offset){
        $params = array(); $cat = '';
        if ((string) $category !== '') { $cat = ' AND cp.directory_category = :cat'; $params['cat'] = (string) $category; }
        return (array) parent::select(
            "SELECT u.user_id, u.u_name, u.verified, cp.display_name, cp.bio, cp.avatar_url, cp.directory_category,
                    (SELECT COUNT(*) FROM follows f WHERE f.creator_id = u.user_id) AS followers,
                    (SELECT MAX(p.published_at) FROM posts p WHERE p.creator_id = u.user_id AND p.state = 'published') AS last_post
             FROM creator_profiles cp JOIN user_accounts u ON u.user_id = cp.user_id JOIN user_roles r ON r.id = u.role_id
             WHERE " . $this->directory_where() . $cat . "
             ORDER BY u.verified DESC, last_post DESC, u.user_id DESC
             LIMIT " . (int) $limit . " OFFSET " . (int) $offset, $params);
    }

    /** category => number of listed creators (only categories that have any). */
    public function directory_counts(){
        $out = array();
        foreach ((array) parent::select(
            "SELECT cp.directory_category AS cat, COUNT(*) AS n
             FROM creator_profiles cp JOIN user_accounts u ON u.user_id = cp.user_id JOIN user_roles r ON r.id = u.role_id
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
}
