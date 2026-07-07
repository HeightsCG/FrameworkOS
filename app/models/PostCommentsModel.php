<?php
/** Fan comments on published posts. Soft-deleted (creator/author moderation). */
class PostCommentsModel extends Model {

    public function __construct(){ parent::__construct(); }

    public function add($post_id, $user_id, $body){
        return parent::insert('post_comments', array(
            'post_id'    => (int) $post_id,
            'user_id'    => (int) $user_id,
            'body'       => (string) $body,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    public function get_one($id){
        $r = parent::select("SELECT * FROM post_comments WHERE id = :id AND deleted_at IS NULL", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Comments for a post, oldest first, with the author's name/handle. */
    public function list_for_post($post_id, $limit = 200){
        $limit = (int) $limit;
        return parent::select(
            "SELECT c.id, c.user_id, c.body, c.created_at,
                    ua.first_name, ua.last_name, ua.u_name
             FROM post_comments c
             JOIN user_accounts ua ON ua.user_id = c.user_id
             WHERE c.post_id = :p AND c.deleted_at IS NULL
             ORDER BY c.created_at ASC LIMIT $limit",
            array('p' => (int) $post_id)
        );
    }

    public function count($post_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM post_comments WHERE post_id = :p AND deleted_at IS NULL",
            array('p' => (int) $post_id));
        return (is_array($r) && count($r) === 1) ? (int) $r[0]['n'] : 0;
    }

    public function soft_delete($id){
        return parent::update('post_comments', array('deleted_at' => date('Y-m-d H:i:s')),
            'id = :id AND deleted_at IS NULL', array('id' => (int) $id));
    }
}
