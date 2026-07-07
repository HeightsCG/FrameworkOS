<?php
/** Execution log for scheduler automations — one row per run attempt. */
class SchedulerRunsModel extends Model {

    public function __construct(){ parent::__construct(); }

    public function add($rule_id, $creator_id, $status, $post_id, $message){
        return parent::insert('scheduler_runs', array(
            'rule_id'    => (int) $rule_id,
            'creator_id' => (int) $creator_id,
            'status'     => (string) $status,
            'post_id'    => $post_id ? (int) $post_id : null,
            'message'    => (string) $message,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    public function recent_for_rule($rule_id, $limit = 5){
        $limit = (int) $limit;
        return parent::select(
            "SELECT * FROM scheduler_runs WHERE rule_id = :r ORDER BY created_at DESC LIMIT $limit",
            array('r' => (int) $rule_id)
        );
    }
}
