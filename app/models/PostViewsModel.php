<?php
/** De-duplicated post views, keyed per unique viewer (user id or hashed IP). */
class PostViewsModel extends Model {

    public function __construct(){ parent::__construct(); }

    /** Record a view. Returns true only when it's this viewer's first view of the post. */
    public function record($post_id, $viewer_key){
        $r = parent::select("SELECT id FROM post_views WHERE post_id = :p AND viewer_key = :k",
            array('p' => (int) $post_id, 'k' => (string) $viewer_key));
        if (is_array($r) && count($r) >= 1) { return false; }
        try {
            parent::insert('post_views', array(
                'post_id' => (int) $post_id, 'viewer_key' => (string) $viewer_key, 'created_at' => date('Y-m-d H:i:s'),
            ));
        } catch (\Throwable $e) {
            return false; // lost a race to a concurrent first view — the UNIQUE key held
        }
        return true;
    }

    public function count($post_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM post_views WHERE post_id = :p", array('p' => (int) $post_id));
        return (is_array($r) && count($r) === 1) ? (int) $r[0]['n'] : 0;
    }
}
