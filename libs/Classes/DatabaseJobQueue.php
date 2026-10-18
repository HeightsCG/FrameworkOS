<?php
/** JobQueue backed by the `jobs` table (see JobsModel). One instance = one DB connection; reuse it in loops. */
class DatabaseJobQueue implements JobQueue {

    const DEFAULT_MAX_ATTEMPTS = 3;
    const RETRY_DELAY_SECONDS  = 60;

    private $jobs;

    public function __construct(){ $this->jobs = new JobsModel(); }

    public function dispatch(string $type, array $payload, ?string $dedupe_key = null, ?string $run_after_utc = null): int {
        return $this->jobs->add($type, $payload, $dedupe_key, $run_after_utc ?? gmdate('Y-m-d H:i:s'), self::DEFAULT_MAX_ATTEMPTS);
    }

    public function claim(string $worker_id): ?array {
        // Two workers may pick the same candidate id; the conditional UPDATE decides who owns it.
        for ($try = 0; $try < 3; $try++) {
            $now = gmdate('Y-m-d H:i:s');
            $id  = $this->jobs->next_queued_id($now);
            if ($id <= 0) { return null; }
            if ($this->jobs->claim($id, $worker_id, $now)) { return $this->jobs->get($id); }
        }
        return null;
    }

    /** $worker_id: only while this worker still owns the run (see JobsModel::finish). */
    public function complete(int $job_id, string $worker_id = ''): void { $this->jobs->finish($job_id, 'done', null, $worker_id); }

    public function fail(int $job_id, string $error, string $worker_id = ''): void {
        $job = $this->jobs->get($job_id);
        if (!$job) { return; }
        $error = mb_substr($error, 0, 2000);
        if ((int) $job['attempts'] < (int) $job['max_attempts']) {
            $delay = self::RETRY_DELAY_SECONDS * (int) $job['attempts'];
            $this->jobs->requeue($job_id, gmdate('Y-m-d H:i:s', time() + $delay), $error, $worker_id);
        } else {
            $this->jobs->finish($job_id, 'failed', $error, $worker_id);
        }
    }

    public function get(int $job_id): ?array { return $this->jobs->get($job_id); }

    /** Runs locked longer than $seconds whose worker is gone; a live worker keeps its run until $ceiling_seconds. */
    public function release_stale(int $seconds, int $ceiling_seconds = 10800): int {
        return $this->jobs->release_stale(gmdate('Y-m-d H:i:s', time() - $seconds), gmdate('Y-m-d H:i:s', time() - $ceiling_seconds));
    }
}
