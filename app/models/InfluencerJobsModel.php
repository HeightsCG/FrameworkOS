<?php
/**
 * Generation/training job rows for influencers. Every run is a row, including failures.
 * The engine (InfluencerJobService) advances `status` only through transition(), a
 * conditional UPDATE whose affected-row count decides who owns the step.
 */
class InfluencerJobsModel extends Model {

    const TYPES    = array('reference', 'training_set', 'training', 'image', 'video', 'enhance',
                           'replicate', 'edit', 'angle', 'carousel', 'motion', 'talking', 'replace', 'scene', 'speech');
    const ACTIVE   = "('submitting','running','landing')";
    const PENDING  = "('queued','submitting','running','landing')";

    public function __construct(){ parent::__construct(); }

    /** Insert a queued job. An unknown type is refused (0): it is never run as something else. $influencer_id 0 = no influencer (a Library edit). */
    public function create($creator_id, $influencer_id, $type, array $f){
        if (!in_array($type, self::TYPES, true)) { return 0; }
        $now = date('Y-m-d H:i:s');
        return (int) parent::insert('influencer_jobs', array(
            'creator_id'      => (int) $creator_id,
            'influencer_id'   => ((int) $influencer_id > 0) ? (int) $influencer_id : null,
            'type'            => $type,
            'status'          => 'queued',
            'origin'          => in_array($f['origin'] ?? '', array('wizard', 'studio', 'scheduler'), true) ? $f['origin'] : 'studio',
            'rule_id'         => isset($f['rule_id']) ? (int) $f['rule_id'] : null,
            'group_key'       => $f['group_key'] ?? null,
            'group_index'     => isset($f['group_index']) ? (int) $f['group_index'] : null,
            'model_key'       => (string) ($f['model_key'] ?? ''),
            'prompt'          => (string) ($f['prompt'] ?? ''),
            'negative_prompt' => (string) ($f['negative_prompt'] ?? ''),
            'seed'            => isset($f['seed']) && $f['seed'] !== null && $f['seed'] !== '' ? (int) $f['seed'] : null,
            'params_json'     => json_encode((array) ($f['params'] ?? array())),
            'input_asset_id'  => isset($f['input_asset_id']) ? (int) $f['input_asset_id'] : null,
            'model_id'        => isset($f['model_id']) ? (int) $f['model_id'] : null,
            'result_model_id' => isset($f['result_model_id']) ? (int) $f['result_model_id'] : null,
            'credits_charged' => max(0, (int) ($f['credits_charged'] ?? 0)),
            'attested_at'     => !empty($f['attested_at']) ? (string) $f['attested_at'] : null,
            'source_hash'     => !empty($f['source_hash']) ? mb_substr((string) $f['source_hash'], 0, 64) : null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ));
    }

    public function get_by_id($id){
        $r = parent::select("SELECT * FROM influencer_jobs WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function get_one($creator_id, $id){
        $r = parent::select("SELECT * FROM influencer_jobs WHERE id = :id AND creator_id = :c",
            array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Guarded update. Returns rows affected: 1 = this caller owns the transition, 0 = lost. */
    public function transition($id, $from, array $data){
        $data['updated_at'] = date('Y-m-d H:i:s');
        $params = array('id' => (int) $id);
        $where  = 'id = :id';
        if (is_array($from)) {
            $ph = array();
            foreach (array_values($from) as $i => $s) { $ph[] = ':f' . $i; $params['f' . $i] = (string) $s; }
            $where .= ' AND status IN (' . implode(',', $ph) . ')';
        } elseif ($from !== null && $from !== '') {
            $where .= ' AND status = :f'; $params['f'] = (string) $from;
        }
        return (int) parent::update('influencer_jobs', $data, $where, $params);
    }

    /** Claim the result slot: only the first caller gets 1 back and may create the asset. */
    public function claim_result_slot($id, $asset_id){
        return (int) parent::update('influencer_jobs',
            array('result_asset_id' => (int) $asset_id, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id AND status = :s AND result_asset_id IS NULL', array('id' => (int) $id, 's' => 'landing'));
    }

    /** Increment and return the dispatch sequence (unique dedupe key per queue dispatch). */
    public function bump_dispatch($id){
        parent::sql("UPDATE influencer_jobs SET dispatch_seq = dispatch_seq + 1 WHERE id = :id", array(':id' => (int) $id));
        $r = parent::select("SELECT dispatch_seq FROM influencer_jobs WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r)) ? (int) $r[0]['dispatch_seq'] : 0;
    }

    public function count_active_for_influencer($influencer_id, $except_id = 0){
        $r = parent::select("SELECT COUNT(*) AS n FROM influencer_jobs WHERE influencer_id = :i AND status IN " . self::ACTIVE . " AND id <> :x",
            array('i' => (int) $influencer_id, 'x' => (int) $except_id));
        return (is_array($r) && count($r)) ? (int) $r[0]['n'] : 0;
    }

    public function count_active_for_creator($creator_id, $except_id = 0){
        $r = parent::select("SELECT COUNT(*) AS n FROM influencer_jobs WHERE creator_id = :c AND status IN " . self::ACTIVE . " AND id <> :x",
            array('c' => (int) $creator_id, 'x' => (int) $except_id));
        return (is_array($r) && count($r)) ? (int) $r[0]['n'] : 0;
    }

    public function has_pending($influencer_id, $type = ''){
        $params = array('i' => (int) $influencer_id);
        $extra  = '';
        if ($type !== '') { $extra = ' AND type = :t'; $params['t'] = (string) $type; }
        $r = parent::select("SELECT COUNT(*) AS n FROM influencer_jobs WHERE influencer_id = :i AND status IN " . self::PENDING . $extra, $params);
        return (is_array($r) && count($r)) ? ((int) $r[0]['n'] > 0) : false;
    }

    /** Recent jobs for one influencer (studio result strips), newest first. $type: one type, a list, or ''. */
    public function list_for_influencer($creator_id, $influencer_id, $type = '', $limit = 30){
        $limit  = max(1, min(200, (int) $limit));
        $params = array('c' => (int) $creator_id, 'i' => (int) $influencer_id);
        $extra  = '';
        $types  = array_values(array_filter(array_map('trim', (array) (is_array($type) ? $type : explode(',', (string) $type))), 'strlen'));
        if (!empty($types)) {
            $ph = array();
            foreach ($types as $k => $t) { $ph[] = ':t' . $k; $params['t' . $k] = $t; }
            $extra = ' AND type IN (' . implode(',', $ph) . ')';
        }
        return (array) parent::select(
            "SELECT * FROM influencer_jobs WHERE creator_id = :c AND influencer_id = :i $extra ORDER BY id DESC LIMIT $limit", $params);
    }

    /** Jobs in a training-set group (current slots only). */
    public function list_group($group_key){
        return (array) parent::select(
            "SELECT * FROM influencer_jobs WHERE group_key = :g AND superseded_by IS NULL ORDER BY group_index ASC, id ASC",
            array('g' => (string) $group_key));
    }

    public function group_counts($group_key){
        $rows = parent::select(
            "SELECT status, COUNT(*) AS n FROM influencer_jobs WHERE group_key = :g AND superseded_by IS NULL GROUP BY status",
            array('g' => (string) $group_key));
        $out = array('queued' => 0, 'submitting' => 0, 'running' => 0, 'landing' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0);
        foreach ((array) $rows as $r) { $out[$r['status']] = (int) $r['n']; }
        return $out;
    }

    /** One-time claim on a finished job (joining the parts of a talking video): only the first caller gets 1. */
    public function claim_once($id, $mark){
        return (int) parent::update('influencer_jobs', array('wait_reason' => mb_substr((string) $mark, 0, 24), 'updated_at' => date('Y-m-d H:i:s')),
            "id = :id AND status = 'done' AND (wait_reason IS NULL OR wait_reason = '')", array('id' => (int) $id));
    }

    /** Merge keys into a job's result_json. */
    public function merge_result($id, array $more){
        $job = $this->get_by_id($id);
        if (!$job) { return 0; }
        return parent::update('influencer_jobs', array('result_json' => json_encode(array_merge(self::result($job), $more)), 'updated_at' => date('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $id));
    }

    public function set_superseded($id, $by_id){
        return parent::update('influencer_jobs', array('superseded_by' => (int) $by_id, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id', array('id' => (int) $id));
    }

    public static function params(array $job){
        $p = json_decode((string) ($job['params_json'] ?? '{}'), true);
        return is_array($p) ? $p : array();
    }

    public static function attempts(array $job){
        $a = json_decode((string) ($job['attempts_json'] ?? '[]'), true);
        return is_array($a) ? $a : array();
    }

    public static function result(array $job){
        $r = json_decode((string) ($job['result_json'] ?? '{}'), true);
        return is_array($r) ? $r : array();
    }
}
