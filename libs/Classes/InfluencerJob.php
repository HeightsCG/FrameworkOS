<?php
/**
 * Queue handler for influencer generation/training jobs (type influencer_job, payload
 * {job_id}). One step per queue run; when the engine asks to be called again it is
 * re-dispatched with the requested delay and a fresh dedupe key.
 */
class InfluencerJob {

    public static function handle(array $payload): string {
        $job_id = (int) ($payload['job_id'] ?? 0);
        if ($job_id <= 0) { return 'SKIP no job_id'; }
        $st = InfluencerJobService::step($job_id);
        if (!$st['terminal'] && $st['next_after'] !== null) {
            InfluencerJobService::dispatch($job_id, (int) $st['next_after']);
        }
        return strtoupper((string) $st['status']) . ' job ' . $job_id . ': ' . $st['message']
            . ($st['terminal'] ? '' : ' (again in ' . (int) $st['next_after'] . 's)');
    }
}
