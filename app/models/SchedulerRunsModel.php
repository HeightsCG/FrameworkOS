<?php
/** Execution log for scheduler automations — one row per run attempt. */
class SchedulerRunsModel extends Model {

    public function __construct(){ parent::__construct(); }

    /** $meta: what this run sent to the models (AutoPostService): image_prompt, hook_line, motion_action, image_model, video_model. */
    public function add($rule_id, $creator_id, $status, $post_id, $message, array $meta = array()){
        return parent::insert('scheduler_runs', array(
            'rule_id'       => (int) $rule_id,
            'creator_id'    => (int) $creator_id,
            'status'        => (string) $status,
            'post_id'       => $post_id ? (int) $post_id : null,
            'message'       => (string) $message,
            'image_prompt'  => ($v = trim((string) ($meta['image_prompt'] ?? ''))) !== '' ? $v : null,
            'hook_line'     => ($v = trim((string) ($meta['hook_line'] ?? ''))) !== '' ? mb_substr($v, 0, 255) : null,
            'motion_action' => ($v = trim((string) ($meta['motion_action'] ?? ''))) !== '' ? $v : null,
            'image_model'   => ($v = trim((string) ($meta['image_model'] ?? ''))) !== '' ? mb_substr($v, 0, 64) : null,
            'video_model'   => ($v = trim((string) ($meta['video_model'] ?? ''))) !== '' ? mb_substr($v, 0, 64) : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ));
    }

    /** The hook lines this automation used most recently (newest first), so the next run writes a new one. */
    public function recent_hooks($rule_id, $limit = 12){
        $limit = max(1, (int) $limit);
        $rows = parent::select(
            "SELECT hook_line FROM scheduler_runs WHERE rule_id = :r AND hook_line IS NOT NULL AND hook_line <> '' ORDER BY id DESC LIMIT $limit",
            array('r' => (int) $rule_id)
        );
        return array_values(array_unique(array_map(function ($r) { return (string) $r['hook_line']; }, is_array($rows) ? $rows : array())));
    }

    public function recent_for_rule($rule_id, $limit = 5){
        $limit = (int) $limit;
        return parent::select(
            "SELECT * FROM scheduler_runs WHERE rule_id = :r ORDER BY created_at DESC LIMIT $limit",
            array('r' => (int) $rule_id)
        );
    }
}
