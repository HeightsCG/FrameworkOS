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
            return parent::update('creator_profiles', array($column => $url, 'updated_at' => $now), 'user_id = :user_id', array('user_id' => (int) $user_id));
        }
        return parent::insert('creator_profiles', array(
            'user_id'    => (int) $user_id,
            $column      => $url,
            'created_at' => $now,
            'updated_at' => $now,
        ));
    }

}
