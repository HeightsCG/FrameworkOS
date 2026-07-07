<?php
/** Likes on published posts — one per (post, user). */
class PostLikesModel extends Model {

    public function __construct(){ parent::__construct(); }

    public function has_liked($post_id, $user_id){
        $r = parent::select("SELECT id FROM post_likes WHERE post_id = :p AND user_id = :u",
            array('p' => (int) $post_id, 'u' => (int) $user_id));
        return is_array($r) && count($r) === 1;
    }

    /** Toggle the viewer's like. Returns true if now liked, false if unliked. */
    public function toggle($post_id, $user_id){
        if ($this->has_liked($post_id, $user_id)) {
            parent::delete_all('post_likes', 'post_id = :p AND user_id = :u',
                array('p' => (int) $post_id, 'u' => (int) $user_id));
            return false;
        }
        parent::insert('post_likes', array(
            'post_id' => (int) $post_id, 'user_id' => (int) $user_id, 'created_at' => date('Y-m-d H:i:s'),
        ));
        return true;
    }

    public function count($post_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM post_likes WHERE post_id = :p", array('p' => (int) $post_id));
        return (is_array($r) && count($r) === 1) ? (int) $r[0]['n'] : 0;
    }

    /** Map of post_id => true for posts this user has liked, among the given ids. */
    public function liked_map($user_id, array $post_ids){
        $ids = array_filter(array_map('intval', $post_ids));
        if (!$user_id || !$ids) { return array(); }
        $in = implode(',', $ids);
        $r  = parent::select("SELECT post_id FROM post_likes WHERE user_id = :u AND post_id IN ($in)",
            array('u' => (int) $user_id));
        $out = array();
        foreach ((array) $r as $row) { $out[(int) $row['post_id']] = true; }
        return $out;
    }
}
