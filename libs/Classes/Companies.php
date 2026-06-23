<?php
class Companies extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function get_company_by_id($id){
        $sql = "SELECT
                    c.*
                FROM
                    companies c
                WHERE
                    c.id = :id
                    and
                    c.deleted = 0";
        return parent::select($sql, array('id' => $id));
    }

    public function get_all_companies(){
        $sql = "SELECT
                    c.*
                FROM
                    companies c
                WHERE
                    c.deleted = 0
                ORDER BY
                    c.company_name";
        return parent::select($sql);
    }

    public function add_company($data){
        return parent::insert('companies', $data);
    }

    public function update_company($id, $data){
        return parent::update('companies', $data, 'id = :w_id', array('w_id' => $id));
    }

    public function delete_company($id){
        $data = array(
            'deleted'    => 1,
            'updated_by' => (int) Session::get('user_id'),
        );
        return parent::update('companies', $data, 'id = :w_id', array('w_id' => $id));
    }

}
