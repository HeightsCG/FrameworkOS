<?php
class Roles extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function get_role_by_id($id){
        $sql = "SELECT
                    r.*
                FROM
                    user_roles r
                WHERE
                    r.id = :id
                    and
                    r.deleted = 0";
        return parent::select($sql, array('id' => $id));
    }

    public function get_all_roles(){
        $sql = "SELECT
                    r.*
                FROM
                    user_roles r
                WHERE
                    r.deleted = 0
                ORDER BY
                    r.role_name";
        return parent::select($sql);
    }

    public function add_role($data){
        return parent::insert('user_roles', $data);
    }

    public function update_role($id, $data){
        return parent::update('user_roles', $data, 'id = :w_id', array('w_id' => $id));
    }

    public function delete_role($id){
        $data = array(
            'deleted'    => 1,
            'updated_by' => (int) Session::get('user_id'),
        );
        return parent::update('user_roles', $data, 'id = :w_id', array('w_id' => $id));
    }

}
