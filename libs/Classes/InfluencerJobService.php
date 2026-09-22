<?php
/**
 * The influencer job engine. Every generation/training run is an influencer_jobs row that
 * moves queued -> submitting -> running -> landing -> done|failed through step(). Each
 * transition is a conditional UPDATE (InfluencerJobsModel::transition) so two workers, a
 * stale-lock re-run, or the scheduler's inline loop can never both act on the same step.
 *
 *   queued      cap check; over the cap the job waits (re-dispatched later), never fails.
 *               Under the cap the request is submitted to the first capable provider in the
 *               configured fallback list; a retryable error moves on to the next provider.
 *   running     polled on a backoff until the provider reports completion or the ceiling
 *               (deadline_at) passes, which fails the job with the last provider error kept.
 *   landing     outputs are downloaded, type-verified and ingested through the normal
 *               media pipeline; the result slot + (job, index) unique key make this idempotent.
 *
 * Queue plumbing: one job type 'influencer_job' with payload {job_id}; every dispatch gets a
 * fresh dedupe key (infl_job:{id}:{seq}) because a running queue row still holds its key.
 * The scheduler drives the same step() inline (opts inline=true) and sleeps instead of
 * dispatching.
 */
class InfluencerJobService {

    const QUEUE_TYPE = 'influencer_job';

    /* =====================================================================
     * Creating and dispatching jobs
     * =================================================================== */

    /**
     * Insert a job row (status queued) and, unless $dispatch is false, put it on the queue.
     * $f: model_key, prompt, negative_prompt, seed, params, input_asset_id, model_id, origin,
     *     rule_id, group_key, group_index, result_model_id
     */
    public static function create_job($creator_id, $influencer_id, $type, array $f, $dispatch = true){
        if (!isset($f['seed']) || $f['seed'] === null || $f['seed'] === '' || (int) $f['seed'] <= 0) {
            $f['seed'] = random_int(1, 2147483647);   // always recorded so any result can be reproduced
        }
        // Pay for the run in AI credits up front (refunded if it fails or is cancelled).
        $price = Plan::ai_price($type, $f);
        if ($price > 0) {
            $user = self::user($creator_id);
            if (!Plan::can_use_creator_features($user)) {
                throw new PlanLimitException('Choose a plan to generate with AI.', array('need_plan' => true));
            }
            Plan::grant_monthly($user);
            $credits = new AiCreditsModel();
            $after = $credits->apply_delta($creator_id, -$price, 'spend', ucfirst((string) $type) . ' run');
            if ($after === false) {
                $balance = $credits->get_balance($creator_id);
                throw new PlanLimitException(Plan::credits_message($type, $price, $balance),
                    array('need_credits' => true, 'price' => $price, 'balance' => $balance));
            }
            $f['credits_charged'] = $price;
        }
        $id = (new InfluencerJobsModel())->create($creator_id, $influencer_id, $type, $f);
        if ($id <= 0 && $price > 0) {
            (new AiCreditsModel())->apply_delta($creator_id, $price, 'refund', 'Run could not be created');
        }
        if ($id > 0 && $dispatch) { self::dispatch($id, 0); }
        return $id;
    }

    /** Give a failed/cancelled job's credits back. Only call after a guarded terminal transition returned 1. */
    private static function refund_credits($job){
        if (!is_array($job) || (int) ($job['credits_charged'] ?? 0) <= 0) { return; }
        (new AiCreditsModel())->apply_delta((int) $job['creator_id'], (int) $job['credits_charged'], 'refund',
            ucfirst((string) $job['type']) . ' run ' . (int) $job['id'] . ' did not complete', (int) $job['id']);
    }

    /** Put (or re-put) a job on the shared queue after $delay seconds. Returns the queue job id. */
    public static function dispatch($job_id, $delay = 0){
        $seq = (new InfluencerJobsModel())->bump_dispatch($job_id);
        $run_after = gmdate('Y-m-d H:i:s', time() + max(0, (int) $delay));
        return (new DatabaseJobQueue())->dispatch(self::QUEUE_TYPE, array('job_id' => (int) $job_id),
            'infl_job:' . (int) $job_id . ':' . $seq, $run_after);
    }

    /** User-initiated retry of a failed job: same prompt, same seed, provider list from the top. */
    public static function retry($creator_id, $job_id){
        $m = new InfluencerJobsModel();
        $job = $m->get_one($creator_id, $job_id);
        if (!$job) { return array('ok' => false, 'error' => 'Job not found'); }
        if ((string) $job['status'] !== 'failed') { return array('ok' => false, 'error' => 'Only a failed job can be retried'); }
        // The failure refunded the credits; a retry pays again.
        $price = (int) $job['credits_charged'];
        if ($price > 0) {
            $credits = new AiCreditsModel();
            Plan::grant_monthly(self::user($creator_id));
            if ($credits->apply_delta($creator_id, -$price, 'spend', ucfirst((string) $job['type']) . ' run ' . (int) $job_id . ' retry', (int) $job_id) === false) {
                return array('ok' => false, 'need_credits' => true, 'error' => Plan::credits_message((string) $job['type'], $price, $credits->get_balance($creator_id)));
            }
        }
        $n = $m->transition($job_id, 'failed', array('status' => 'queued', 'provider_index' => 0, 'error' => null, 'error_code' => null,
            'wait_reason' => null, 'provider_job_id' => null, 'provider_status_url' => null, 'provider_response_url' => null,
            'provider_cancel_url' => null, 'poll_count' => 0, 'deadline_at' => null, 'submitted_at' => null, 'landed_at' => null, 'finished_at' => null));
        if ($n !== 1) {
            if ($price > 0) { (new AiCreditsModel())->apply_delta($creator_id, $price, 'refund', 'Retry of run ' . (int) $job_id . ' lost a race', (int) $job_id); }
            return array('ok' => false, 'error' => 'Only a failed job can be retried');
        }
        self::dispatch($job_id, 0);
        return array('ok' => true, 'job_id' => (int) $job_id);
    }

    public static function cancel_job($creator_id, $job_id){
        $m = new InfluencerJobsModel();
        $job = $m->get_one($creator_id, $job_id);
        if (!$job) { return array('ok' => false, 'error' => 'Job not found'); }
        $n = $m->transition($job_id, array('queued', 'submitting', 'running'), array('status' => 'cancelled', 'error_code' => 'cancelled',
            'error' => 'Cancelled', 'finished_at' => date('Y-m-d H:i:s')));
        if ($n === 1 && !empty($job['provider_cancel_url'])) {
            $class = InfluencerConfig::provider_class((string) $job['provider']);
            if ($class !== '') { try { $class::cancel(self::handle($job)); } catch (\Throwable $e) {} }
        }
        if ($n === 1) { self::refund_credits($job); }
        return array('ok' => $n === 1, 'error' => $n === 1 ? '' : 'This job can no longer be cancelled');
    }

    /* =====================================================================
     * The state machine
     * =================================================================== */

    /**
     * Advance one job by one step. Returns
     *   ['status' => current, 'terminal' => bool, 'next_after' => int|null seconds, 'message' => string]
     * With opts['inline'] = true nothing is dispatched; the caller sleeps next_after and calls again.
     */
    public static function step($job_id, array $opts = array()){
        $m   = new InfluencerJobsModel();
        $job = $m->get_by_id($job_id);
        if (!$job) { return self::out('missing', true, null, 'Job ' . (int) $job_id . ' not found'); }

        try {
            switch ((string) $job['status']) {
                case 'queued':  return self::step_queued($job, $m);
                case 'running': return self::step_running($job, $m);
                case 'landing': return self::step_landing_stale($job, $m);
                case 'submitting':
                    // A crashed submitter: nothing was recorded, so treat like a stale lander and requeue.
                    if (strtotime((string) $job['updated_at']) < time() - (int) InfluencerConfig::get('landing_stale_seconds', 600)) {
                        $m->transition($job['id'], 'submitting', array('status' => 'queued'));
                        return self::out('queued', false, 0, 'reclaimed a stale submit');
                    }
                    return self::out('submitting', false, 30, 'another process is submitting');
                default:
                    return self::out((string) $job['status'], true, null, 'terminal');
            }
        } catch (\Throwable $e) {
            error_log('[influencer] job ' . (int) $job_id . ' step error: ' . $e->getMessage());
            $n = $m->transition($job['id'], array('queued', 'submitting', 'running', 'landing'), array('status' => 'failed', 'error_code' => 'provider',
                'error' => mb_substr('Internal error: ' . $e->getMessage(), 0, 2000), 'finished_at' => date('Y-m-d H:i:s')));
            if ($n === 1) { self::refund_credits($job); }
            self::after_terminal($m->get_by_id($job_id));
            return self::out('failed', true, null, $e->getMessage());
        }
    }

    private static function out($status, $terminal, $next_after, $message){
        return array('status' => $status, 'terminal' => (bool) $terminal, 'next_after' => $next_after, 'message' => (string) $message);
    }

    /* ---- queued: cap check + submit with provider fallback ---- */

    private static function step_queued(array $job, InfluencerJobsModel $m){
        if ($m->transition($job['id'], 'queued', array('status' => 'submitting')) !== 1) {
            return self::out('queued', false, 5, 'lost the claim');
        }
        // Concurrency caps: over the cap the job simply waits its turn.
        $wait = '';
        if ($m->count_active_for_influencer($job['influencer_id'], $job['id']) >= InfluencerConfig::cap('influencer')) { $wait = 'cap_influencer'; }
        elseif ($m->count_active_for_creator($job['creator_id'], $job['id']) >= InfluencerConfig::cap('account')) { $wait = 'cap_account'; }
        if ($wait !== '') {
            $m->transition($job['id'], 'submitting', array('status' => 'queued', 'wait_reason' => $wait));
            return self::out('queued', false, (int) InfluencerConfig::get('cap_wait_seconds', 20), 'waiting: ' . $wait);
        }

        $type  = (string) $job['type'];
        $model = InfluencerConfig::resolve_model(self::op_for($type), (string) $job['model_key']);
        if (!$model) { return self::fail_job($job, $m, 'submitting', 'validation', 'No model is configured for ' . $type); }

        $level = (string) (InfluencerJobsModel::params($job)['level'] ?? 'safe');
        if (!in_array($level, (array) $model['levels'], true)) {
            return self::fail_job($job, $m, 'submitting', 'content_policy',
                'The "' . $model['label'] . '" model does not allow ' . $level . ' content. Pick another model or content level.');
        }

        // Build the provider request once (presigned inputs, ZIP for training).
        try {
            $req = ($type === 'training') ? InfluencerTrainingService::build_request($job, $model) : self::build_request($job, $model);
        } catch (\Throwable $e) {
            return self::fail_job($job, $m, 'submitting', 'validation', $e->getMessage());
        }
        if (isset($req['error'])) { return self::fail_job($job, $m, 'submitting', 'validation', (string) $req['error']); }
        $job = $m->get_by_id($job['id']);   // build_request may have stored params (zip key)

        $providers = InfluencerConfig::providers_for(self::op_for($type));
        $attempts  = InfluencerJobsModel::attempts($job);
        $idx       = (int) $job['provider_index'];
        $last      = array('error' => 'No provider is available for ' . $type, 'error_code' => 'provider');
        for (; $idx < count($providers); $idx++) {
            $pkey  = (string) $providers[$idx];
            $class = InfluencerConfig::provider_class($pkey);
            $att   = array('provider' => $pkey, 'endpoint' => '', 'started_at' => gmdate('c'));
            if ($class === '') {
                $att['outcome'] = 'skipped'; $att['error'] = 'unknown provider';
                $attempts[] = $att; continue;
            }
            $caps = $class::capabilities();
            if (empty($caps['ops'][self::op_for($type)]) || !in_array($level, (array) ($caps['levels'] ?? array()), true)) {
                $att['outcome'] = 'skipped'; $att['error'] = 'provider does not support ' . $type . ' at level ' . $level;
                $attempts[] = $att; continue;
            }
            $endpoint = InfluencerConfig::endpoint_for($model, $pkey);
            if ($endpoint === '') {
                $att['outcome'] = 'skipped'; $att['error'] = 'no endpoint for model ' . $model['key'] . ' on ' . $pkey;
                $attempts[] = $att; continue;
            }
            $att['endpoint'] = $endpoint;
            $req['endpoint'] = $endpoint;
            switch ($type) {
                case 'video':    $r = $class::generate_video($req); break;
                case 'training': $r = $class::train_model($req);    break;
                default:         $r = $class::generate_image($req); break;
            }
            $att['ended_at'] = gmdate('c');
            $att['http_code'] = (int) ($r['http_code'] ?? 0);
            if (!empty($r['ok'])) {
                $h = (array) $r['handle'];
                $att['outcome'] = 'accepted'; $att['request_id'] = (string) $h['provider_job_id'];
                $attempts[] = $att;
                $now = date('Y-m-d H:i:s');
                $n = $m->transition($job['id'], 'submitting', array(
                    'status' => 'running', 'wait_reason' => null, 'provider' => $pkey, 'endpoint' => $endpoint,
                    'provider_index' => $idx, 'attempts_json' => json_encode($attempts),
                    'provider_job_id' => (string) $h['provider_job_id'], 'provider_status_url' => (string) ($h['status_url'] ?? ''),
                    'provider_response_url' => (string) ($h['response_url'] ?? ''), 'provider_cancel_url' => (string) ($h['cancel_url'] ?? ''),
                    'poll_count' => 0, 'submitted_at' => $now, 'deadline_at' => date('Y-m-d H:i:s', time() + InfluencerConfig::ceiling($type)),
                    'error' => null, 'error_code' => null,
                ));
                if ($n !== 1) { return self::out('running', false, 5, 'submitted but lost the transition'); }
                return self::out('running', false, InfluencerConfig::poll_delay($type, 0), 'submitted to ' . $pkey);
            }
            $att['outcome'] = 'rejected'; $att['error'] = (string) $r['error']; $att['error_code'] = (string) $r['error_code'];
            $attempts[] = $att;
            $last = array('error' => (string) $r['error'], 'error_code' => (string) $r['error_code']);
            if (empty($r['retryable'])) { break; }   // content policy / validation / auth: do not shop it around
        }
        $m->transition($job['id'], 'submitting', array('attempts_json' => json_encode($attempts), 'provider_index' => min($idx, max(0, count($providers) - 1))));
        return self::fail_job($m->get_by_id($job['id']), $m, 'submitting', $last['error_code'], $last['error']);
    }

    /* ---- running: poll, time out, or land ---- */

    private static function step_running(array $job, InfluencerJobsModel $m){
        $type = (string) $job['type'];
        if (!empty($job['deadline_at']) && strtotime((string) $job['deadline_at']) < time()) {
            $class = InfluencerConfig::provider_class((string) $job['provider']);
            if ($class !== '') { try { $class::cancel(self::handle($job)); } catch (\Throwable $e) {} }
            return self::fail_job($job, $m, 'running', 'timeout',
                'The provider did not finish within ' . round(InfluencerConfig::ceiling($type) / 60) . ' minutes.' .
                (!empty($job['error']) ? ' Last error: ' . $job['error'] : ''));
        }
        $class = InfluencerConfig::provider_class((string) $job['provider']);
        if ($class === '') { return self::fail_job($job, $m, 'running', 'provider', 'Provider ' . $job['provider'] . ' is no longer configured'); }

        $st = $class::get_job_status(self::handle($job));
        $params = InfluencerJobsModel::params($job);
        if (empty($st['ok'])) {
            if (!empty($st['retryable']) && (string) $st['state'] === 'running') {
                // Transport blip while polling: keep waiting, but not forever.
                $params['poll_errors'] = (int) ($params['poll_errors'] ?? 0) + 1;
                if ($params['poll_errors'] <= 5) {
                    $m->transition($job['id'], 'running', array('poll_count' => (int) $job['poll_count'] + 1, 'params_json' => json_encode($params)));
                    return self::out('running', false, InfluencerConfig::poll_delay($type, (int) $job['poll_count'] + 1), 'poll error, retrying');
                }
            }
            return self::next_provider_or_fail($job, $m, 'running', (string) $st['error_code'], (string) $st['error'], !empty($st['retryable']));
        }
        $params['poll_errors'] = 0;
        if ((string) $st['state'] !== 'completed') {
            $m->transition($job['id'], 'running', array('poll_count' => (int) $job['poll_count'] + 1, 'params_json' => json_encode($params)));
            return self::out('running', false, InfluencerConfig::poll_delay($type, (int) $job['poll_count'] + 1), 'provider ' . $st['state']);
        }
        // Completed: single-winner claim of the landing step before any download.
        if ($m->transition($job['id'], 'running', array('status' => 'landing', 'landed_at' => date('Y-m-d H:i:s'), 'params_json' => json_encode($params))) !== 1) {
            return self::out('landing', false, 30, 'another process is landing');
        }
        return self::land($m->get_by_id($job['id']), $m);
    }

    /** landing rows are owned by whoever won the claim; only a stale one (crashed lander) is reclaimed. */
    private static function step_landing_stale(array $job, InfluencerJobsModel $m){
        $stale = (int) InfluencerConfig::get('landing_stale_seconds', 600);
        if (strtotime((string) $job['landed_at']) >= time() - $stale) {
            return self::out('landing', false, 60, 'another process is landing');
        }
        if ($m->transition($job['id'], 'landing', array('status' => 'running')) === 1) {
            return self::out('running', false, 0, 'reclaimed a stale landing');
        }
        return self::out('landing', false, 60, 'lost the reclaim');
    }

    /* ---- landing: download, verify, ingest, attach ---- */

    private static function land(array $job, InfluencerJobsModel $m){
        $type  = (string) $job['type'];
        $class = InfluencerConfig::provider_class((string) $job['provider']);
        $res   = $class::fetch_result(self::handle($job));
        if (empty($res['ok'])) {
            return self::next_provider_or_fail($job, $m, 'landing', (string) $res['error_code'], (string) $res['error'], !empty($res['retryable']));
        }
        $result = InfluencerJobsModel::result($job);
        $result['outputs'] = array();
        foreach ((array) $res['outputs'] as $o) { unset($o['raw']); $result['outputs'][] = $o; }
        $result['seed'] = $res['seed'] ?? ($result['seed'] ?? null);
        $result['asset_ids'] = (array) ($result['asset_ids'] ?? array());
        $m->transition($job['id'], 'landing', array('result_json' => json_encode($result)));

        try {
            if ($type === 'training') {
                $lora = null;
                foreach ((array) $res['outputs'] as $o) { if (($o['kind'] ?? '') === 'lora') { $lora = $o; break; } }
                if (!$lora) { throw new RuntimeException('Training finished but no weights file was returned'); }
                $model_id = InfluencerTrainingService::land($job, $lora);
                $cost = InfluencerConfig::price((string) $job['model_key'], (int) (InfluencerJobsModel::params($job)['steps'] ?? InfluencerConfig::get('training_steps', 1000)));
                $m->transition($job['id'], 'landing', array('status' => 'done', 'result_model_id' => (int) $model_id, 'cost_usd' => $cost,
                    'finished_at' => date('Y-m-d H:i:s'), 'error' => null, 'error_code' => null));
                self::after_terminal($m->get_by_id($job['id']));
                return self::out('done', true, null, 'trained model ' . (int) $model_id);
            }

            $user = self::user($job['creator_id']);
            $infl = (new InfluencersModel())->get_by_id($job['influencer_id']);
            $role = self::role_for($type);
            $watermark = in_array($type, array('image', 'video', 'enhance'), true) ? !empty($user['watermark_enabled']) : false;
            $label = self::label_for($job, $infl);
            $ids = array();
            $i = 0;
            $units = 0;
            // Quality check runs until the re-roll budget is spent; a hand-run job then lands with a note,
            // an unattended (scheduler) job keeps checking and fails instead of publishing a bad render.
            $rerolls = (int) (InfluencerJobsModel::result($job)['quality_rerolls'] ?? 0);
            $qa = ($rerolls < self::QUALITY_REROLLS) || ((string) $job['origin'] === 'scheduler');
            foreach ((array) $res['outputs'] as $o) {
                if (!in_array($o['kind'] ?? '', array('image', 'video'), true)) { continue; }
                if (!$class::output_url_allowed($o['url'])) { throw new RuntimeException('Provider returned an output from an unexpected host'); }
                $aid = self::land_output($job, $m, $user, $infl, $o, $i, $role, $watermark, $label, $qa);
                if ($aid > 0) { $ids[] = $aid; }
                $units += ($o['kind'] === 'video') ? (int) (InfluencerJobsModel::params($job)['duration'] ?? 5) : 1;
                $i++;
            }
            if (empty($ids)) { throw new RuntimeException('No output could be stored'); }
            $result = InfluencerJobsModel::result($m->get_by_id($job['id']));
            $result['asset_ids'] = array_values(array_unique(array_merge((array) ($result['asset_ids'] ?? array()), $ids)));
            $cost = InfluencerConfig::price((string) $job['model_key'], max(1, $units));
            $m->transition($job['id'], 'landing', array('status' => 'done', 'result_json' => json_encode($result), 'cost_usd' => $cost,
                'finished_at' => date('Y-m-d H:i:s'), 'error' => null, 'error_code' => null));
            self::after_terminal($m->get_by_id($job['id']));
            return self::out('done', true, null, 'landed ' . count($ids) . ' asset(s)');
        } catch (BlankOutputException $e) {
            // fal's flux-lora blanks per IMAGE (its classifier runs whatever we send); another seed usually passes.
            return self::reroll_or_fail($m->get_by_id($job['id']), $m, $e->getMessage(), 'blank', self::BLANK_REROLLS);
        } catch (QualityException $e) {
            return self::reroll_or_fail($m->get_by_id($job['id']), $m, 'The render had visible anatomy problems: ' . $e->getMessage(), 'quality', self::QUALITY_REROLLS);
        } catch (\Throwable $e) {
            error_log('[influencer] job ' . (int) $job['id'] . ' landing failed: ' . $e->getMessage());
            return self::fail_job($m->get_by_id($job['id']), $m, 'landing', 'landing', 'Could not store the result: ' . $e->getMessage());
        }
    }

    /**
     * A blank frame is a filtered render, not a result. Re-run once with a fresh seed (same
     * prompt, same provider); a second blank fails the job with a reason the creator can act on.
     */
    const QUALITY_REROLLS = 2;
    const BLANK_REROLLS   = 3;
    /** Appended to the prompt from the second blank re-roll on: steers the render away from what fal's classifier blanks. */
    const SOFTEN = ', fully clothed, nothing exposed, tasteful editorial photo';

    private static function reroll_or_fail($job, InfluencerJobsModel $m, $why, $kind = 'blank', $max = 1){
        if (!$job) { return self::out('failed', true, null, (string) $why); }
        $result = InfluencerJobsModel::result($job);
        $key = $kind . '_rerolls';
        $rerolls = (int) ($result[$key] ?? 0);
        if ($rerolls < $max) {
            $result[$key] = $rerolls + 1;
            $result['quality_note'] = ($kind === 'quality') ? (string) $why : ($result['quality_note'] ?? null);
            $result['outputs'] = array();
            $attempts = InfluencerJobsModel::attempts($job);
            if (!empty($attempts)) { $k = count($attempts) - 1; $attempts[$k]['outcome'] = $kind; $attempts[$k]['error'] = (string) $why; $attempts[$k]['ended_at'] = gmdate('c'); }
            $data = array('status' => 'queued', 'seed' => random_int(1, 2147483647),
                'result_json' => json_encode($result), 'attempts_json' => json_encode($attempts),
                'provider_job_id' => null, 'provider_status_url' => null, 'provider_response_url' => null, 'provider_cancel_url' => null,
                'poll_count' => 0, 'deadline_at' => null, 'error' => null, 'error_code' => null);
            // A second blank: the seed alone was not enough, soften the wording as well (kept on the job so it shows in the history).
            if ($kind === 'blank' && $rerolls >= 1 && strpos((string) $job['prompt'], self::SOFTEN) === false) {
                $data['prompt'] = mb_substr((string) $job['prompt'], 0, 1900) . self::SOFTEN;
            }
            $n = $m->transition($job['id'], 'landing', $data);
            if ($n === 1) { return self::out('queued', false, 0, $kind . ' render, re-rolling with a new seed' . (isset($data['prompt']) ? ' and softer wording' : '')); }
        }
        $why = rtrim((string) $why); if ($why !== '' && !preg_match('/[.!?]$/', $why)) { $why .= '.'; }
        return self::fail_job($job, $m, 'landing', $kind, $why . ($kind === 'blank' ? ' Try a different prompt or a less revealing scene.' : ' Try a simpler pose or a different scene.'));
    }

    /**
     * Land one output into the library, idempotently. Index 0 uses the result_asset_id slot;
     * later indexes use the (job, index) attachment key. Returns the asset id (0 when skipped).
     */
    private static function land_output(array $job, InfluencerJobsModel $m, array $user, $infl, array $o, $index, $role, $watermark, $label, $qa = true){
        $cid = (int) $job['creator_id'];
        $mm  = new MediaAssetsModel();
        $im  = new InfluencerImagesModel();
        $is_video = ($o['kind'] === 'video');
        $type = $is_video ? 'video' : 'image';

        // Find or claim the asset row for this output slot.
        $aid = 0;
        if ($index === 0) {
            $aid = (int) ($job['result_asset_id'] ?? 0);
            if ($aid <= 0) {
                $new = (int) $mm->add($cid, $type, $label . '.' . ($is_video ? 'mp4' : 'png'), $is_video ? 'video/mp4' : 'image/png', 'processing');
                if ($new <= 0) { throw new RuntimeException('Could not create the media asset'); }
                if ($m->claim_result_slot($job['id'], $new) !== 1) {
                    $mm->soft_delete($cid, $new);   // a parallel lander won the slot
                    $aid = (int) ($m->get_by_id($job['id'])['result_asset_id'] ?? 0);
                } else { $aid = $new; }
            }
        } else {
            $rows = (new InfluencerImagesModel())->list_for_influencer($cid, $job['influencer_id'], '', true);
            foreach ($rows as $r) { if ((int) $r['job_id'] === (int) $job['id'] && (int) $r['result_index'] === (int) $index) { $aid = (int) $r['id']; break; } }
            if ($aid <= 0) {
                $aid = (int) $mm->add($cid, $type, $label . '.' . ($is_video ? 'mp4' : 'png'), $is_video ? 'video/mp4' : 'image/png', 'processing');
                if ($aid <= 0) { throw new RuntimeException('Could not create the media asset'); }
            }
        }
        $asset = $mm->get_one($cid, $aid);
        if (!$asset) { throw new RuntimeException('Media asset ' . $aid . ' vanished'); }

        if ((string) $asset['status'] !== 'ready') {
            // Download + verify + ingest into this exact placeholder row.
            if ($is_video) {
                $v = MediaIngestService::fetch_video($o['url'], (int) InfluencerConfig::get('output_max_video_bytes', 1073741824), 540);
                MediaIngestService::ingest_video_file($cid, $user, $v['path'], $v['ext'], $v['mime'], $v['bytes'], $label, '', $aid, true);
            } else {
                $img = MediaIngestService::fetch_image($o['url'], (int) InfluencerConfig::get('output_max_image_bytes', 31457280), 60);
                if (!empty($o['nsfw']) || MediaIngestService::is_blank_image($img['bytes'])) {
                    throw new BlankOutputException('The model returned a blank image (its content filter fired).');
                }
                if ($qa && $type === 'image' && (string) $job['type'] === 'image') {
                    $q = ImageQualityService::check($img['bytes'], (string) $img['mime']);
                    if (!$q['ok']) { throw new QualityException($q['issues'] !== '' ? $q['issues'] : 'visible anatomy errors'); }
                }
                MediaIngestService::ingest_image($cid, $user, $img['bytes'], $img['ext'], $img['mime'], $label, $watermark, $aid);
            }
            $mm->set_tags($cid, $aid, 'influencer:' . (int) $job['influencer_id'] . ',' . $role);
        }
        $sort = (int) ($job['group_index'] ?? 0);
        $im->attach($job['influencer_id'], $cid, $aid, $role, $job['id'], $index, $sort);   // 0 = already attached, fine
        return $aid;
    }

    /* ---- failure paths ---- */

    /** Retryable provider failure after submit: try the next provider (same seed/prompt) or fail. */
    private static function next_provider_or_fail(array $job, InfluencerJobsModel $m, $from, $code, $error, $retryable){
        $attempts = InfluencerJobsModel::attempts($job);
        if (!empty($attempts)) {
            $k = count($attempts) - 1;
            $attempts[$k]['outcome'] = 'failed'; $attempts[$k]['error'] = (string) $error; $attempts[$k]['error_code'] = (string) $code;
            $attempts[$k]['ended_at'] = gmdate('c');
        }
        $providers = InfluencerConfig::providers_for(self::op_for((string) $job['type']));
        $next = (int) $job['provider_index'] + 1;
        if ($retryable && $next < count($providers) && (string) $job['type'] !== 'training') {
            $n = $m->transition($job['id'], $from, array('status' => 'queued', 'provider_index' => $next, 'attempts_json' => json_encode($attempts),
                'provider_job_id' => null, 'provider_status_url' => null, 'provider_response_url' => null, 'provider_cancel_url' => null,
                'poll_count' => 0, 'deadline_at' => null, 'error' => mb_substr((string) $error, 0, 2000), 'error_code' => (string) $code));
            if ($n === 1) { return self::out('queued', false, 0, 'moving to the next provider'); }
        }
        $m->transition($job['id'], $from, array('attempts_json' => json_encode($attempts)));
        return self::fail_job($m->get_by_id($job['id']), $m, $from, $code, $error);
    }

    private static function fail_job($job, InfluencerJobsModel $m, $from, $code, $error){
        if (!$job) { return self::out('failed', true, null, (string) $error); }
        $n = $m->transition($job['id'], $from, array('status' => 'failed', 'error_code' => mb_substr((string) $code, 0, 32),
            'error' => mb_substr((string) $error, 0, 2000), 'finished_at' => date('Y-m-d H:i:s')));
        if ($n === 1) { self::refund_credits($job); }
        self::after_terminal($m->get_by_id($job['id']));
        return self::out('failed', true, null, (string) $error);
    }

    /** Side effects once a job is done/failed (training bookkeeping, wizard status). */
    private static function after_terminal($job){
        if (!$job) { return; }
        try {
            if ((string) $job['type'] === 'training' && (string) $job['status'] === 'failed') {
                InfluencerTrainingService::fail($job, (string) $job['error']);
            }
            if (in_array((string) $job['type'], array('reference', 'training_set'), true) && class_exists('InfluencerService')) {
                InfluencerService::on_wizard_job_finished($job);
            }
        } catch (\Throwable $e) {
            error_log('[influencer] after_terminal job ' . (int) $job['id'] . ': ' . $e->getMessage());
        }
    }

    /* =====================================================================
     * Request building
     * =================================================================== */

    /** Generic provider request from the job row (see InfluencerProvider for the shape). */
    public static function build_request(array $job, array $model){
        $p    = InfluencerJobsModel::params($job);
        $type = (string) $job['type'];
        $req  = array(
            'endpoint'        => (string) $model['endpoint'],
            'params'          => (array) ($model['params'] ?? array()),
            'prompt'          => (string) $job['prompt'],
            'negative_prompt' => (string) $job['negative_prompt'],
            'seed'            => (int) $job['seed'],
            'num_images'      => max(1, min(4, (int) ($p['num_images'] ?? 1))),
            'image_size'      => (string) ($p['image_size'] ?? 'square'),
            'aspect_ratio'    => (string) ($p['aspect_ratio'] ?? '1:1'),
            'level'           => (string) ($p['level'] ?? 'safe'),
        );
        // Per-job overrides of the catalog's fixed params (steps, guidance, upscale factor...).
        foreach ((array) ($p['overrides'] ?? array()) as $k => $v) { $req['params'][$k] = $v; }
        $ttl = (int) InfluencerConfig::get('input_url_ttl', 1800);
        $mm  = new MediaAssetsModel();

        if ($type === 'image') {
            $model_row = !empty($job['model_id']) ? (new InfluencerModelsModel())->get_by_id($job['model_id']) : null;
            if (!$model_row || (string) $model_row['status'] !== 'ready') { return array('error' => 'This influencer has no active trained model'); }
            $url = '';
            if (!empty($model_row['weights_key'])) { $url = S3Service::presigned_get_url($model_row['weights_key'], (int) InfluencerConfig::get('weights_url_ttl', 3600)); }
            if ($url === '') { $url = (string) $model_row['weights_url']; }
            if ($url === '') { return array('error' => 'The trained weights are unavailable'); }
            $req['loras'] = array(array('url' => $url, 'scale' => (float) ($p['lora_scale'] ?? InfluencerConfig::get('training_lora_scale', 1.0))));
        }
        if (in_array($type, array('training_set', 'reference', 'enhance', 'video'), true) && !empty($job['input_asset_id'])) {
            $a = $mm->get_one($job['creator_id'], $job['input_asset_id']);
            if (!$a || (string) $a['status'] !== 'ready') { return array('error' => 'The source image is not ready'); }
            $key = (string) ($a['original_key'] ?: ($a['display_key'] ?: $a['thumb_key']));
            $url = S3Service::presigned_get_url($key, $ttl);
            if ($url === '') { return array('error' => 'Could not sign the source image'); }
            $req['image_url']  = $url;
            $req['image_urls'] = array($url);
        }
        if (!empty($p['image_asset_ids'])) {   // extra references for edit models
            $urls = array();
            foreach ((array) $p['image_asset_ids'] as $id) {
                $a = $mm->get_one($job['creator_id'], $id);
                if ($a && (string) $a['status'] === 'ready') {
                    $u = S3Service::presigned_get_url((string) ($a['original_key'] ?: $a['display_key']), $ttl);
                    if ($u !== '') { $urls[] = $u; }
                }
            }
            if (!empty($urls)) { $req['image_urls'] = array_values(array_unique(array_merge((array) ($req['image_urls'] ?? array()), $urls))); }
        }
        if ($type === 'video') {
            if (empty($req['image_url'])) { return array('error' => 'A still image is required for video'); }
            $durs = array_values((array) ($model['durations'] ?? array()));
            $d = (string) ($p['duration'] ?? ($durs[0] ?? '5'));
            $req['duration'] = (!empty($durs) && !in_array($d, $durs, true)) ? (string) $durs[0] : $d;
            if ($req['duration'] !== $d) {   // record what was actually sent
                $p['duration'] = $req['duration'];
                (new InfluencerJobsModel())->transition($job['id'], null, array('params_json' => json_encode($p)));
            }
        }
        if ($type === 'enhance' && empty($req['image_url'])) { return array('error' => 'A source image is required to enhance'); }
        if (in_array($type, array('training_set'), true) && empty($req['image_urls'])) { return array('error' => 'A reference image is required'); }
        return $req;
    }

    /* =====================================================================
     * Inline driver (scheduler) and helpers
     * =================================================================== */

    /**
     * Drive a job synchronously until it is terminal or $budget seconds pass. If the budget
     * runs out the job is handed to the queue so it still lands. Returns the final job row.
     */
    public static function run_inline($job_id, $budget = null){
        $budget = ($budget === null) ? (int) InfluencerConfig::get('inline_budget_seconds', 240) : (int) $budget;
        $t_end  = time() + max(10, $budget);
        do {
            $st = self::step($job_id, array('inline' => true));
            if ($st['terminal']) { break; }
            $wait = max(1, (int) $st['next_after']);
            sleep(min($wait, max(1, $t_end - time())));
        } while (time() < $t_end);
        $job = (new InfluencerJobsModel())->get_by_id($job_id);
        if ($job && !in_array((string) $job['status'], array('done', 'failed', 'cancelled'), true)) {
            self::dispatch($job_id, 0);   // finish in the background
        }
        return $job;
    }

    /**
     * Scheduler entry: render one image of the rule's influencer for $topic and drive the job
     * inline (same state machine as the queue). Returns ['ok', 'asset_id', 'job_id', 'error'].
     * The prompt is the trigger word + the influencer's prompt defaults + the scene; nothing else.
     */
    public static function run_for_rule(array $rule, array $user, $topic, $size){
        $cid = (int) ($user['user_id'] ?? 0);
        $infl = (new InfluencersModel())->get_one($cid, (int) ($rule['influencer_id'] ?? 0));
        if (!$infl) { return array('ok' => false, 'error' => 'This automation has no influencer selected.'); }
        if ((string) $infl['status'] !== 'ready' || empty($infl['active_model_id'])) { return array('ok' => false, 'error' => $infl['name'] . ' is not trained yet.'); }
        $model = (new InfluencerModelsModel())->get_by_id($infl['active_model_id']);
        if (!$model || (string) $model['status'] !== 'ready') { return array('ok' => false, 'error' => $infl['name'] . ' has no active model.'); }
        if (!InfluencerConfig::enabled()) { return array('ok' => false, 'error' => 'Rendering is not configured (no provider key).'); }

        // Nothing is spent (not even the scene prompt) when the account cannot pay for the image.
        Plan::grant_monthly($user);
        $price = Plan::ai_price('image', array('params' => array('num_images' => 1)));
        $balance = (new AiCreditsModel())->get_balance($cid);
        if ($balance < $price) { return array('ok' => false, 'error' => Plan::credits_message('image', $price, $balance)); }

        $ai_assist = !isset($rule['ai_assist']) || (int) $rule['ai_assist'] === 1;
        $level = 'safe';
        $scene = $ai_assist ? InfluencerService::scene_from_topic($topic, $size, $level, InfluencerService::noun($infl)) : $topic;
        $trigger  = (string) $model['trigger_word'];
        $defaults = trim((string) ($infl['prompt_defaults'] ?? ''));
        $scene    = trim((string) $scene);
        // The model only draws the trained person when the prompt names them: lead with "photo of a woman/man" unless it already does.
        $noun = InfluencerService::noun($infl);
        if ($scene !== '' && !preg_match('/\\b(wo)?m[ae]n\\b/i', $defaults . ' ' . $scene)) { $scene = 'photo of a ' . $noun . ', ' . $scene; }
        // The trigger word goes first unless the defaults or the scene already carry it.
        $parts = array((stripos($defaults . ' ' . $scene, $trigger) === false) ? $trigger : '', $defaults, $scene);
        $prompt = trim(implode(' ', array_filter($parts, 'strlen')));
        $mk = InfluencerConfig::resolve_model('image', (string) ($rule['influencer_model_key'] ?? ''));
        if (!$mk) { return array('ok' => false, 'error' => 'No image model is configured.'); }

        try {
            $job_id = self::create_job($cid, (int) $infl['id'], 'image', array(
                'origin' => 'scheduler', 'rule_id' => (int) ($rule['id'] ?? 0), 'model_key' => (string) $mk['key'], 'model_id' => (int) $model['id'],
                'prompt' => $prompt, 'negative_prompt' => (string) ($infl['negative_prompt'] ?? ''),
                'params' => array('image_size' => in_array($size, array('square', 'portrait', 'landscape'), true) ? $size : 'square', 'num_images' => 1, 'level' => $level, 'scene' => (string) $scene),
            ), false);
        } catch (PlanLimitException $e) {
            return array('ok' => false, 'error' => $e->getMessage());
        }
        if ($job_id <= 0) { return array('ok' => false, 'error' => 'Could not create the generation job.'); }
        error_log('[influencer] rule ' . (int) ($rule['id'] ?? 0) . ' job ' . $job_id . ' prompt: ' . $prompt);
        $job = self::run_inline($job_id);
        if (!$job) { return array('ok' => false, 'error' => 'Job vanished.'); }
        if ((string) $job['status'] === 'done' && (int) $job['result_asset_id'] > 0) {
            return array('ok' => true, 'asset_id' => (int) $job['result_asset_id'], 'job_id' => $job_id, 'error' => '');
        }
        if (in_array((string) $job['status'], array('failed', 'cancelled'), true)) {
            return array('ok' => false, 'job_id' => $job_id, 'error' => (string) $job['error']);
        }
        return array('ok' => false, 'job_id' => $job_id, 'error' => 'The image is still generating; it will land in the vault but this run did not publish.');
    }

    public static function handle(array $job){
        return array('provider' => (string) $job['provider'], 'provider_job_id' => (string) $job['provider_job_id'],
            'status_url' => (string) $job['provider_status_url'], 'response_url' => (string) $job['provider_response_url'],
            'cancel_url' => (string) $job['provider_cancel_url']);
    }

    public static function op_for($type){ return (string) $type; }

    public static function role_for($type){
        switch ((string) $type) {
            case 'reference':    return 'reference';
            case 'training_set': return 'training';
            case 'video':        return 'video';
            case 'enhance':      return 'enhanced';
            default:             return 'generated';
        }
    }

    private static function label_for(array $job, $infl){
        $name = $infl ? (string) $infl['name'] : 'Influencer';
        switch ((string) $job['type']) {
            case 'reference':    return $name . ' · reference';
            case 'training_set': return $name . ' · training ' . (int) ($job['group_index'] ?? 0);
            case 'video':        return $name . ' · video';
            case 'enhance':      return $name . ' · enhanced';
            default:             return $name . ' · ' . mb_substr((string) $job['prompt'], 0, 30);
        }
    }

    public static function user($creator_id){
        $rows = (new UsersModel())->get_user_by_id((int) $creator_id);
        if (!is_array($rows) || count($rows) !== 1) { throw new RuntimeException('Creator not found'); }
        return $rows[0];
    }

    /** Public, creator-scoped job payload for the UI/API (signed thumbs for landed assets). */
    public static function job_json($creator_id, array $job){
        $result = InfluencerJobsModel::result($job);
        $assets = array();
        $mm = new MediaAssetsModel();
        foreach ((array) ($result['asset_ids'] ?? array()) as $aid) {
            $a = $mm->get_one($creator_id, $aid);
            if (!$a) { continue; }
            $assets[] = array(
                'id' => (int) $a['id'], 'type' => (string) $a['type'], 'status' => (string) $a['status'],
                'width' => (int) $a['width'], 'height' => (int) $a['height'], 'duration' => (int) $a['duration_sec'],
                'thumb_url'   => ($a['status'] === 'ready') ? MediaService::signed_url($a, 'thumb', $creator_id) : '',
                'display_url' => ($a['status'] === 'ready') ? MediaService::signed_url($a, $a['type'] === 'video' ? 'poster' : 'display', $creator_id) : '',
                'video_url'   => ($a['status'] === 'ready' && $a['type'] === 'video') ? MediaService::signed_variant($a, 'original', 900) : '',
                'moderation'  => (string) $a['moderation_status'],
            );
        }
        $p = InfluencerJobsModel::params($job);
        return array(
            'id' => (int) $job['id'], 'influencer_id' => (int) $job['influencer_id'], 'type' => (string) $job['type'],
            'status' => (string) $job['status'], 'wait_reason' => (string) $job['wait_reason'], 'provider' => (string) $job['provider'],
            'model_key' => (string) $job['model_key'], 'prompt' => (string) $job['prompt'], 'negative_prompt' => (string) $job['negative_prompt'],
            'seed' => (int) $job['seed'], 'result_seed' => isset($result['seed']) ? (int) $result['seed'] : (int) $job['seed'],
            'params' => $p, 'group_key' => (string) $job['group_key'], 'group_index' => (int) $job['group_index'],
            'input_asset_id' => (int) $job['input_asset_id'], 'model_id' => (int) $job['model_id'], 'result_model_id' => (int) $job['result_model_id'],
            'error' => (string) $job['error'], 'error_code' => (string) $job['error_code'], 'cost_usd' => (float) $job['cost_usd'],
            'quality_note' => ((string) $job['status'] === 'done' && (int) ($result['quality_rerolls'] ?? 0) >= self::QUALITY_REROLLS) ? (string) ($result['quality_note'] ?? '') : '',
            'created_at' => (string) $job['created_at'], 'submitted_at' => (string) $job['submitted_at'], 'finished_at' => (string) $job['finished_at'],
            'assets' => $assets,
        );
    }
}
