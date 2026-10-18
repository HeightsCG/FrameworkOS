<?php
/** Persistence for the `jobs` table used by DatabaseJobQueue. */
class JobsModel extends Model {

    public function __construct(){ parent::__construct(); }

    /** Returns the new id, or 0 when the dedupe_key is already present (UNIQUE collision). */
    public function add(string $type, array $payload, ?string $dedupe_key, string $run_after, int $max_attempts): int {
        $now = gmdate('Y-m-d H:i:s');
        try {
            return (int) parent::insert('jobs', [
                'type'         => $type,
                'payload_json' => json_encode($payload),
                'dedupe_key'   => $dedupe_key,
                'state'        => 'queued',
                'attempts'     => 0,
                'max_attempts' => $max_attempts,
                'run_after'    => $run_after,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { return 0; }
            throw $e;
        }
    }

    public function get(int $id): ?array {
        $rows = parent::select("SELECT * FROM jobs WHERE id = :id", ['id' => $id]);
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function next_queued_id(string $now): int {
        $rows = parent::select(
            "SELECT id FROM jobs WHERE state = 'queued' AND run_after <= :now ORDER BY run_after ASC, id ASC LIMIT 1",
            ['now' => $now]);
        return isset($rows[0]['id']) ? (int) $rows[0]['id'] : 0;
    }

    /** Conditional UPDATE queued → running. Exactly one affected row means this worker owns the job. */
    public function claim(int $id, string $worker_id, string $now): bool {
        $n = parent::update('jobs',
            ['state' => 'running', 'locked_at' => $now, 'locked_by' => $worker_id, 'updated_at' => $now],
            'id = :id AND state = :s', ['id' => $id, 's' => 'queued']);
        if ((int) $n !== 1) { return false; }
        parent::sql("UPDATE jobs SET attempts = attempts + 1 WHERE id = :id", [':id' => $id]);
        return true;
    }

    /**
     * End a run. With $worker_id the update applies only while that worker still owns the job, so a run that was
     * released and picked up by another worker can't mark the job done (or failed) under the new owner.
     */
    public function finish(int $id, string $state, ?string $error, string $worker_id = ''): bool {
        $where = 'id = :id'; $p = ['id' => $id];
        if ($worker_id !== '') { $where .= ' AND state = :s AND locked_by = :w'; $p['s'] = 'running'; $p['w'] = $worker_id; }
        return (int) parent::update('jobs',
            ['state' => $state, 'last_error' => $error, 'dedupe_key' => null, 'locked_at' => null, 'locked_by' => null,
             'updated_at' => gmdate('Y-m-d H:i:s')],
            $where, $p) === 1;
    }

    public function requeue(int $id, string $run_after, string $error, string $worker_id = ''): bool {
        $where = 'id = :id'; $p = ['id' => $id];
        if ($worker_id !== '') { $where .= ' AND state = :s AND locked_by = :w'; $p['s'] = 'running'; $p['w'] = $worker_id; }
        return (int) parent::update('jobs',
            ['state' => 'queued', 'run_after' => $run_after, 'last_error' => $error, 'locked_at' => null, 'locked_by' => null,
             'updated_at' => gmdate('Y-m-d H:i:s')],
            $where, $p) === 1;
    }

    /**
     * Give back runs whose worker died. A run counts as stale once its lock is older than $older_than, unless the
     * worker that holds it is still alive on this host (long broadcasts, exports and renders legitimately run past
     * the cutoff; re-queueing them would run the same job twice). $hard_ceiling releases even a live owner, as a
     * backstop against a hung process or a reused pid.
     */
    public function release_stale(string $older_than, string $hard_ceiling = ''): int {
        $rows = (array) parent::select(
            "SELECT id, locked_by, locked_at, attempts, max_attempts FROM jobs WHERE state = 'running' AND locked_at < :t",
            ['t' => $older_than]);
        $released = 0;
        foreach ($rows as $r) {
            $owner = (string) $r['locked_by'];
            if ($hard_ceiling === '' || (string) $r['locked_at'] >= $hard_ceiling) {
                if (self::worker_alive($owner)) { continue; }
            }
            // A job that keeps killing its worker (fatal error, OOM) must not be re-claimed forever:
            // once it has used its attempts, a stale run is final.
            $final = (int) $r['attempts'] >= (int) $r['max_attempts'];
            $data  = $final
                ? ['state' => 'failed', 'last_error' => 'Worker died mid-run (attempts exhausted)', 'dedupe_key' => null]
                : ['state' => 'queued'];
            $data += ['locked_at' => null, 'locked_by' => null, 'updated_at' => gmdate('Y-m-d H:i:s')];
            $n = (int) parent::update('jobs', $data, 'id = :id AND state = :s AND locked_by = :w',
                ['id' => (int) $r['id'], 's' => 'running', 'w' => $owner]);
            if ($n === 1 && !$final) { $released++; }
        }
        return $released;
    }

    /** Is a queued or running row on the queue whose dedupe key starts with $prefix (e.g. "infl_job:12:")? */
    public function has_live_with_prefix(string $prefix): bool {
        $rows = parent::select("SELECT 1 FROM jobs WHERE dedupe_key LIKE :p AND state IN ('queued','running') LIMIT 1",
            ['p' => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix) . '%']);
        return is_array($rows) && count($rows) > 0;
    }

    /** Payloads of the queued / running rows of one type (the live queue is small). */
    public function live_payloads(string $type): array {
        $rows = (array) parent::select("SELECT payload_json FROM jobs WHERE type = :t AND state IN ('queued','running')", ['t' => $type]);
        $out = [];
        foreach ($rows as $r) { $p = json_decode((string) $r['payload_json'], true); if (is_array($p)) { $out[] = $p; } }
        return $out;
    }

    /** The newest payload of a $type row whose payload field $field equals $value, or null. */
    public function last_payload_where(string $type, string $field, int $value): ?array {
        $rows = parent::select("SELECT payload_json FROM jobs WHERE type = :t AND CAST(JSON_EXTRACT(payload_json, :f) AS UNSIGNED) = :v ORDER BY id DESC LIMIT 1",
            ['t' => $type, 'f' => '$.' . preg_replace('/[^a-z0-9_]/i', '', $field), 'v' => $value]);
        $p = isset($rows[0]) ? json_decode((string) $rows[0]['payload_json'], true) : null;
        return is_array($p) ? $p : null;
    }

    /** Delete finished rows (done / failed) older than $days days, at most $batch per call. Returns rows deleted. */
    public function prune_finished(int $days = 14, int $batch = 500): int {
        return (int) parent::delete('jobs', "state IN ('done','failed') AND updated_at < :cut", max(1, $batch),
            ['cut' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
    }

    /** Is the worker named "<host>:<pid>" (the queue worker's id) still running? Unknown hosts count as dead. */
    public static function worker_alive(string $worker_id): bool {
        $at = strrpos($worker_id, ':');
        if ($at === false) { return false; }
        $host = substr($worker_id, 0, $at); $pid = (int) substr($worker_id, $at + 1);
        if ($pid <= 0 || $host !== substr((string) gethostname(), 0, strlen($host))) { return false; }
        if (function_exists('posix_kill')) {
            if (@posix_kill($pid, 0)) { return true; }
            return function_exists('posix_get_last_error') && posix_get_last_error() === 1;   // EPERM: alive, owned by another user
        }
        return is_dir('/proc/' . $pid);
    }
}
