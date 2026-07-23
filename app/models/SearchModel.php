<?php
/**
 * Universal search (PRD §28) for the top-chrome search box: finds creators (by handle
 * or name) and published content (by caption). Content results respect the same
 * moderation gate as the public profile — blocked/unscanned never surface, and adult
 * (flagged) content only surfaces for viewers who opted in.
 */
class SearchModel extends Model {

    public function __construct(){ parent::__construct(); }

    private function like($q){
        return '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\%', '\_'), trim((string) $q)) . '%';
    }

    /** Creators matching handle or display name, handle-prefix matches first. */
    public function creators($q, $limit = 6){
        $limit = max(1, min(10, (int) $limit));
        $rid = 0;
        $rr = parent::select("SELECT id FROM user_roles WHERE role_name = 'Creator'");
        if (is_array($rr) && count($rr)) { $rid = (int) $rr[0]['id']; }
        if ($rid <= 0) { return array(); }
        $like = $this->like($q);
        $pref = str_replace(array('\\', '%', '_'), array('\\\\', '\%', '\_'), trim((string) $q)) . '%';
        $rows = parent::select(
            "SELECT u.user_id, u.u_name, u.verified,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '')) AS name,
                    cp.avatar_url AS avatar
             FROM user_accounts u
             LEFT JOIN creator_profiles cp ON cp.user_id = u.user_id
             WHERE u.deleted = 0 AND u.role_id = :r
               AND (u.u_name LIKE :q1 OR cp.display_name LIKE :q2 OR TRIM(CONCAT(u.first_name, ' ', u.last_name)) LIKE :q3)
             ORDER BY (u.u_name LIKE :pref) DESC, u.u_name ASC
             LIMIT $limit",
            array('r' => $rid, 'q1' => $like, 'q2' => $like, 'q3' => $like, 'pref' => $pref)
        );
        $out = array();
        foreach ((array) $rows as $r) {
            $handle = (string) $r['u_name'];
            $name   = trim((string) ($r['name'] ?? ''));
            $out[] = array(
                'handle'   => $handle,
                'name'     => $name !== '' ? $name : ('@' . $handle),
                'avatar'   => (string) ($r['avatar'] ?? ''),
                'verified' => !empty($r['verified']),
            );
        }
        return $out;
    }

    /** Published posts matching caption, moderation-gated (adult only if $show_adult). */
    public function posts($q, $show_adult = false, $limit = 6){
        $limit = max(1, min(10, (int) $limit));
        $like  = $this->like($q);
        // Over-fetch so the moderation filter still leaves up to $limit results.
        $rows = parent::select(
            "SELECT p.id, p.caption, p.audience, u.u_name AS creator_handle,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '')) AS creator_name
             FROM posts p
             JOIN user_accounts u ON u.user_id = p.creator_id
             LEFT JOIN creator_profiles cp ON cp.user_id = u.user_id
             WHERE p.state = 'published' AND u.deleted = 0 AND p.caption LIKE :q
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT 30",
            array('q' => $like)
        );
        if (empty($rows)) { return array(); }

        $ids = array();
        foreach ($rows as $r) { $ids[] = (int) $r['id']; }
        $mod = (new PostsModel())->moderation_map($ids);

        $out = array();
        foreach ($rows as $r) {
            $status = $mod[(int) $r['id']] ?? '';
            if ($status === 'blocked' || $status === 'pending') { continue; }   // never surface
            if ($status === 'adult' && !$show_adult) { continue; }              // approved adult, viewer opted out
            $handle = (string) $r['creator_handle'];
            $name   = trim((string) ($r['creator_name'] ?? ''));
            $out[] = array(
                'id'             => (int) $r['id'],
                'caption'        => html_entity_decode((string) $r['caption'], ENT_QUOTES, 'UTF-8'),
                'audience'       => (string) $r['audience'],
                'creator_handle' => $handle,
                'creator_name'   => $name !== '' ? $name : ('@' . $handle),
            );
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }
}
