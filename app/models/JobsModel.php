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

    public function finish(int $id, string $state, ?string $error): void {
        parent::update('jobs',
            ['state' => $state, 'last_error' => $error, 'dedupe_key' => null, 'locked_at' => null, 'locked_by' => null,
             'updated_at' => gmdate('Y-m-d H:i:s')],
            'id = :id', ['id' => $id]);
    }

    public function requeue(int $id, string $run_after, string $error): void {
        parent::update('jobs',
            ['state' => 'queued', 'run_after' => $run_after, 'last_error' => $error, 'locked_at' => null, 'locked_by' => null,
             'updated_at' => gmdate('Y-m-d H:i:s')],
            'id = :id', ['id' => $id]);
    }

    public function release_stale(string $older_than): int {
        return (int) parent::update('jobs',
            ['state' => 'queued', 'locked_at' => null, 'locked_by' => null, 'updated_at' => gmdate('Y-m-d H:i:s')],
            'state = :s AND locked_at < :t', ['s' => 'running', 't' => $older_than]);
    }
}
