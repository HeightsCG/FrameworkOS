<?php
/**
 * Leads from the free public tools (sql/2026-10-09_leads.sql) and a creator's Ideas runs (tool_runs).
 * A lead is one tool run by someone who ticked the consent box; tool_runs keeps what /ideas shows. Times are UTC.
 */
class LeadsModel extends Model {

    /** Lead sources (the public tools) => label shown in Admin > Leads. */
    const SOURCES = array('fan-questions' => 'Fan Questions', 'persona-tool' => 'Persona Tool');

    public function __construct(){ parent::__construct(); }

    /** Record one lead. $extra: anything the tool asked beyond the shared fields (vibe, audience). Returns the id. */
    public function add($source, $first_name, $email, $niche, array $extra, $ip){
        return (int) parent::insert('leads', array(
            'source' => (string) $source, 'first_name' => (string) $first_name, 'email' => (string) $email, 'niche' => (string) $niche,
            'extra' => json_encode($extra, JSON_UNESCAPED_UNICODE), 'consent' => 1, 'ip' => (string) $ip, 'created_at' => gmdate('Y-m-d H:i:s'),
        ));
    }

    public function get($id){
        $rows = parent::select("SELECT * FROM leads WHERE id = :id", array('id' => (int) $id));
        return $rows[0] ?? null;
    }

    /** Mark a lead emailed. True only for the one caller that set it (a retry or a second worker gets false). */
    public function claim_email($id){
        return parent::update('leads', array('emailed_at' => gmdate('Y-m-d H:i:s')), 'id = :id AND emailed_at IS NULL', array('id' => (int) $id)) === 1;
    }

    /** The send failed after claim_email(): clear it so the queue's retry can send. */
    public function release_email($id){
        parent::update('leads', array('emailed_at' => null), 'id = :id', array('id' => (int) $id));
    }

    /** Newest first, optionally one source. $limit 0 = all (the CSV export). */
    public function recent($source = '', $limit = 500){
        $where = ''; $params = array();
        if ((string) $source !== '') { $where = 'WHERE source = :s'; $params['s'] = (string) $source; }
        return parent::select("SELECT id, source, first_name, email, niche, extra, consent, ip, created_at FROM leads $where ORDER BY id DESC" . ((int) $limit > 0 ? ' LIMIT ' . (int) $limit : ''), $params);
    }

    // ---- tool_runs: a creator's runs on /ideas ----

    public function run_add($user_id, $source, $niche){
        return (int) parent::insert('tool_runs', array('user_id' => (int) $user_id, 'source' => (string) $source, 'niche' => (string) $niche, 'status' => 'queued', 'created_at' => gmdate('Y-m-d H:i:s')));
    }

    public function run_get($id){
        $rows = parent::select("SELECT * FROM tool_runs WHERE id = :id", array('id' => (int) $id));
        return $rows[0] ?? null;
    }

    /** One run, only when it belongs to $user_id. */
    public function run_for($user_id, $id){
        $rows = parent::select("SELECT * FROM tool_runs WHERE id = :id AND user_id = :u", array('id' => (int) $id, 'u' => (int) $user_id));
        return $rows[0] ?? null;
    }

    public function run_set($id, $status, $result = null){
        $data = array('status' => (string) $status);
        if ($result !== null) { $data['result'] = json_encode($result, JSON_UNESCAPED_UNICODE); }
        parent::update('tool_runs', $data, 'id = :id', array('id' => (int) $id));
    }

    /** A run still queued or running for this creator (one at a time), or null. Older than 15 minutes counts as stuck. */
    public function run_in_progress($user_id){
        $rows = parent::select("SELECT id FROM tool_runs WHERE user_id = :u AND status IN ('queued','running') AND created_at > :since ORDER BY id DESC LIMIT 1",
            array('u' => (int) $user_id, 'since' => gmdate('Y-m-d H:i:s', time() - 900)));
        return $rows[0] ?? null;
    }

    /** Ideas runs this creator started in the last 24 hours (the daily cap). */
    public function runs_today($user_id){
        $rows = parent::select("SELECT COUNT(*) AS n FROM tool_runs WHERE user_id = :u AND created_at > :since", array('u' => (int) $user_id, 'since' => gmdate('Y-m-d H:i:s', time() - 86400)));
        return (int) ($rows[0]['n'] ?? 0);
    }

    /** queued/running for more than 15 minutes means the worker lost it: show it as failed. */
    public static function shown_status(array $run): string {
        $st = (string) $run['status'];
        if (($st === 'queued' || $st === 'running') && strtotime((string) $run['created_at'] . ' UTC') < time() - 900) { return 'failed'; }
        return $st;
    }

    public function runs_for($user_id, $limit = 50){
        return parent::select("SELECT id, source, niche, status, result, created_at FROM tool_runs WHERE user_id = :u ORDER BY id DESC LIMIT " . (int) $limit, array('u' => (int) $user_id));
    }
}
