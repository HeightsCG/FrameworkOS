<?php
/**
 * Background job contract. Web requests dispatch() and answer immediately with the job id;
 * cron/queue_worker.php claim()s and runs the matching handler (libs/Classes/*Job.php).
 * Times are UTC 'Y-m-d H:i:s' strings, like everything else in the DB.
 */
interface JobQueue {
    /** Enqueue. Returns the job id, or 0 when $dedupe_key is already queued/running (no duplicate created). */
    public function dispatch(string $type, array $payload, ?string $dedupe_key = null, ?string $run_after_utc = null): int;
    /** Atomically claim the next runnable job for this worker, or null when idle. */
    public function claim(string $worker_id): ?array;
    public function complete(int $job_id): void;
    /** Record a failed attempt; re-queues with back-off while attempts < max_attempts, else marks failed. */
    public function fail(int $job_id, string $error): void;
    public function get(int $job_id): ?array;
    /** Return 'running' jobs whose lock is older than $seconds to the queue (worker died). Returns rows released. */
    public function release_stale(int $seconds): int;
}
