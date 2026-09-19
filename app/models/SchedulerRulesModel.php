<?php
/**
 * Scheduler automations — recurring rules that auto-generate + publish a post.
 * Times are stored: run_time (local, in `timezone`), next_run_at/last_run_at (UTC).
 */
class SchedulerRulesModel extends Model {

    public function __construct(){ parent::__construct(); }

    private static $cols = array(
        'kind', 'name', 'active', 'topic', 'message_text', 'message_targets', 'message_ai',
        'size', 'image_source', 'influencer_id', 'influencer_model_key', 'content_level', 'audience', 'tier_id', 'comments_enabled',
        'use_brand', 'ai_assist', 'caption_text', 'social_accounts', 'cadence', 'days_of_week', 'run_time', 'timezone',
    );

    const FANVUE_LISTS = array('subscribers', 'auto_renewing', 'non_renewing', 'followers', 'free_trial_subscribers', 'expired_subscribers', 'spent_more_than_50');
    const CLS_SEGMENTS = array('all', 'followers', 'subscribers');

    /** Decode message_targets → ['fanvue' => [lists], 'cls' => segment|''] */
    public static function targets(array $rule){
        $t = json_decode((string) ($rule['message_targets'] ?? ''), true);
        $t = is_array($t) ? $t : array();
        $fv = array_values(array_intersect(array_map('strval', (array) ($t['fanvue'] ?? array())), self::FANVUE_LISTS));
        $cls = in_array($t['cls'] ?? '', self::CLS_SEGMENTS, true) ? (string) $t['cls'] : '';
        return array('fanvue' => $fv, 'cls' => $cls);
    }

    /** Insert a new rule, returns its id. */
    public function create($creator_id, array $f){
        $now  = date('Y-m-d H:i:s');
        $data = $this->clean($f);
        $data['creator_id'] = (int) $creator_id;
        $data['next_run_at'] = $this->compute_next_run($data);
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        return parent::insert('scheduler_rules', $data);
    }

    /** Update an owned rule. Recomputes next_run_at from the new cadence. */
    public function update_rule($creator_id, $id, array $f){
        $data = $this->clean($f);
        $data['next_run_at'] = ((int) ($data['active'] ?? 1) === 1) ? $this->compute_next_run($data) : null;
        $data['updated_at']  = date('Y-m-d H:i:s');
        return parent::update('scheduler_rules', $data, 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    /** Coerce request fields into safe, bounded columns. */
    private function clean(array $f){
        $out = array();
        $out['kind']             = (($f['kind'] ?? 'post') === 'message') ? 'message' : 'post';
        $out['message_text']     = mb_substr(trim((string) ($f['message_text'] ?? '')), 0, 5000);
        $out['message_ai']       = !empty($f['message_ai']) ? 1 : 0;
        $targets = $f['message_targets'] ?? array();
        if (is_string($targets)) { $targets = json_decode($targets, true) ?: array(); }
        $out['message_targets']  = json_encode(self::targets(array('message_targets' => json_encode((array) $targets))));
        $out['name']             = mb_substr(trim((string) ($f['name'] ?? '')), 0, 190);
        $out['active']           = !empty($f['active']) ? 1 : 0;
        $out['topic']            = (string) ($f['topic'] ?? '');
        $out['size']             = in_array($f['size'] ?? '', array('square','portrait','landscape'), true) ? $f['size'] : 'square';
        $src = (string) ($f['image_source'] ?? 'brand');
        $out['image_source']     = ($src === 'influencer') ? 'influencer' : 'brand';
        $out['influencer_id']    = ($out['image_source'] === 'influencer' && (int) ($f['influencer_id'] ?? 0) > 0) ? (int) $f['influencer_id'] : null;
        $out['influencer_model_key'] = ($out['image_source'] === 'influencer') ? (mb_substr(trim((string) ($f['influencer_model_key'] ?? '')), 0, 64) ?: null) : null;
        $out['content_level']    = (($f['content_level'] ?? 'safe') === 'spicy') ? 'spicy' : 'safe';
        $out['audience']         = (($f['audience'] ?? 'free') === 'subscribers') ? 'subscribers' : 'free';
        $out['tier_id']          = ((int) ($f['tier_id'] ?? 0) > 0) ? (int) $f['tier_id'] : null;
        $out['comments_enabled'] = !empty($f['comments_enabled']) ? 1 : 0;
        $out['use_brand']        = !empty($f['use_brand']) ? 1 : 0;
        $out['ai_assist']        = (isset($f['ai_assist']) && (string) $f['ai_assist'] === '0') ? 0 : 1;
        $out['caption_text']     = mb_substr(trim((string) ($f['caption_text'] ?? '')), 0, 5000);
        $socials = $f['social_accounts'] ?? array();
        if (is_string($socials)) { $socials = array_filter(array_map('trim', explode(',', $socials)), 'strlen'); }
        $out['social_accounts']  = json_encode(array_values(array_map('strval', (array) $socials)));
        $out['cadence']          = (($f['cadence'] ?? 'daily') === 'weekly') ? 'weekly' : 'daily';
        $days = $f['days_of_week'] ?? array();
        if (is_string($days)) { $days = array_filter(array_map('trim', explode(',', $days)), 'strlen'); }
        $days = array_values(array_unique(array_filter(array_map('intval', (array) $days), function ($d) { return $d >= 0 && $d <= 6; })));
        sort($days);
        $out['days_of_week']     = implode(',', $days);
        $t = (string) ($f['run_time'] ?? '09:00');
        $out['run_time']         = preg_match('/^\d{1,2}:\d{2}/', $t) ? (substr($t, 0, 5) . ':00') : '09:00:00';
        $out['timezone']         = mb_substr(trim((string) ($f['timezone'] ?? 'UTC')), 0, 64) ?: 'UTC';
        return $out;
    }

    public function get_one($creator_id, $id){
        $r = parent::select("SELECT * FROM scheduler_rules WHERE id = :id AND creator_id = :c",
            array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** All rules, post + message, active or not: what the list shows is what counts against the plan. */
    public function count_for_creator($creator_id){
        $r = parent::select("SELECT COUNT(*) AS n FROM scheduler_rules WHERE creator_id = :c", array('c' => (int) $creator_id));
        return (is_array($r) && count($r) === 1) ? (int) $r[0]['n'] : 0;
    }

    public function list_for_creator($creator_id){
        return parent::select("SELECT * FROM scheduler_rules WHERE creator_id = :c ORDER BY created_at DESC",
            array('c' => (int) $creator_id));
    }

    public function delete_rule($creator_id, $id){
        $owned = $this->get_one($creator_id, $id);
        if (!$owned) { return 0; }
        parent::delete_all('scheduler_runs', 'rule_id = :r', array('r' => (int) $id));
        return parent::delete('scheduler_rules', 'id = :id AND creator_id = :c', 1,
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function set_active($creator_id, $id, $active){
        $rule = $this->get_one($creator_id, $id);
        if (!$rule) { return 0; }
        $data = array('active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'));
        $data['next_run_at'] = $active ? $this->compute_next_run($rule) : null;
        return parent::update('scheduler_rules', $data, 'id = :id AND creator_id = :c',
            array('id' => (int) $id, 'c' => (int) $creator_id));
    }

    public function set_next_run($id, $utc){
        return parent::update('scheduler_rules', array('next_run_at' => $utc, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id', array('id' => (int) $id));
    }

    public function set_last_run($id, $status){
        return parent::update('scheduler_rules',
            array('last_run_at' => date('Y-m-d H:i:s'), 'last_status' => (string) $status, 'updated_at' => date('Y-m-d H:i:s')),
            'id = :id', array('id' => (int) $id));
    }

    /** Active rules whose next run is due (worker feed, across all creators). */
    public function due_rules($now_utc = null){
        $now = $now_utc ?: date('Y-m-d H:i:s');
        return parent::select(
            "SELECT * FROM scheduler_rules WHERE active = 1 AND next_run_at IS NOT NULL AND next_run_at <= :n ORDER BY next_run_at ASC",
            array('n' => $now)
        );
    }

    /**
     * Next run in UTC ('Y-m-d H:i:s') after $from (default now), based on cadence /
     * days_of_week / run_time interpreted in the rule's timezone.
     */
    public function compute_next_run(array $rule, $from = null){
        try {
            $tz = new DateTimeZone(($rule['timezone'] ?? 'UTC') ?: 'UTC');
        } catch (\Throwable $e) { $tz = new DateTimeZone('UTC'); }
        $now = $from ? clone $from : new DateTime('now', new DateTimeZone('UTC'));
        $now->setTimezone($tz);
        $parts = array_pad(explode(':', ($rule['run_time'] ?? '09:00:00') ?: '09:00:00'), 3, '0');
        list($h, $m, $s) = array($parts[0], $parts[1], $parts[2]);

        if (($rule['cadence'] ?? 'daily') === 'weekly') {
            $days = array_filter(array_map('intval', explode(',', (string) ($rule['days_of_week'] ?? ''))), function ($d) { return $d >= 0 && $d <= 6; });
            if (empty($days)) { $days = array((int) $now->format('w')); }
            for ($i = 0; $i <= 7; $i++) {
                $cand = (clone $now)->modify("+$i day")->setTime((int) $h, (int) $m, (int) $s);
                if (in_array((int) $cand->format('w'), $days, true) && $cand > $now) {
                    return $cand->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
            }
            $cand = (clone $now)->modify('+7 day')->setTime((int) $h, (int) $m, (int) $s);
            return $cand->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        $cand = (clone $now)->setTime((int) $h, (int) $m, (int) $s);
        if ($cand <= $now) { $cand->modify('+1 day'); }
        return $cand->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
