<?php
class CreatorLinksModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** All links for a creator, in display order. */
    public function get_for_user($user_id){
        return parent::select(
            "SELECT id, title, url, is_enabled, sort_order
             FROM creator_links
             WHERE user_id = :user_id
             ORDER BY sort_order ASC, id ASC",
            array('user_id' => (int) $user_id)
        );
    }

    /** A single enabled link by id (any owner) — for the public /go click-through. */
    public function get_public($id){
        $rows = parent::select(
            "SELECT id, user_id, url, is_enabled FROM creator_links WHERE id = :id",
            array('id' => (int) $id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** A single link scoped to its owner, or null. */
    public function get_one($user_id, $id){
        $rows = parent::select(
            "SELECT * FROM creator_links WHERE id = :id AND user_id = :user_id",
            array('id' => (int) $id, 'user_id' => (int) $user_id)
        );
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function add($user_id, $title, $url){
        $now = date('Y-m-d H:i:s');
        $max = parent::select(
            "SELECT COALESCE(MAX(sort_order), -1) AS m FROM creator_links WHERE user_id = :user_id",
            array('user_id' => (int) $user_id)
        );
        $next = (is_array($max) && count($max) === 1) ? ((int) $max[0]['m'] + 1) : 0;
        return parent::insert('creator_links', array(
            'user_id'    => (int) $user_id,
            'title'      => $title,
            'url'        => $url,
            'is_enabled' => 1,
            'sort_order' => $next,
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

    public function update_link($user_id, $id, $title, $url){
        return parent::update(
            'creator_links',
            array('title' => $title, 'url' => $url, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND user_id = :user_id',
            array('id' => (int) $id, 'user_id' => (int) $user_id)
        );
    }

    public function set_enabled($user_id, $id, $enabled){
        return parent::update(
            'creator_links',
            array('is_enabled' => $enabled ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND user_id = :user_id',
            array('id' => (int) $id, 'user_id' => (int) $user_id)
        );
    }

    public function delete_link($user_id, $id){
        return parent::delete(
            'creator_links',
            'id = :id AND user_id = :user_id',
            1,
            array('id' => (int) $id, 'user_id' => (int) $user_id)
        );
    }

    /** Persist a new order. $ids is the desired ordering; only the user's own links move. */
    public function reorder($user_id, $ids){
        $now = date('Y-m-d H:i:s');
        $order = 0;
        foreach ($ids as $id) {
            parent::update(
                'creator_links',
                array('sort_order' => $order, 'updated_at' => $now),
                'id = :id AND user_id = :user_id',
                array('id' => (int) $id, 'user_id' => (int) $user_id)
            );
            $order++;
        }
        return true;
    }

}
