<?php
/** The two checks behind GET /health: the database answers, and how far behind the job queue is. */
class HealthModel extends Model {

    public function __construct(){ parent::__construct(); }

    public function db_ok(): bool {
        $rows = parent::select("SELECT 1 AS ok");
        return isset($rows[0]['ok']) && (int) $rows[0]['ok'] === 1;
    }

    /** Seconds the oldest due job has been waiting (state queued, run_after passed); 0 when none is waiting. */
    public function queue_lag_s(): int {
        $rows = parent::select("SELECT TIMESTAMPDIFF(SECOND, MIN(run_after), UTC_TIMESTAMP()) AS lag_s FROM jobs WHERE state = 'queued' AND run_after <= UTC_TIMESTAMP()");
        return max(0, (int) ($rows[0]['lag_s'] ?? 0));
    }
}
