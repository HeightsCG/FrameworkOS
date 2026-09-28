<?php
/**
 * Job handler: turn a library image into a short video (Studio "Generate a video"). The request
 * goes to the image-to-video model on fal; the job re-queues itself to check back until the
 * video is ready, then ingests it into the placeholder 'processing' asset. AI credits were taken
 * when the video was requested; any failure gives them back once (refund keyed by the asset).
 *
 * payload: creator_id, asset_id (placeholder video), source_asset_id (the image), model_key,
 *          prompt, duration, credits; later also handle (fal request) and polls. An automation's video also
 *          carries auto_post (rule_id, caption): AutoPostService::finish_video publishes it when it lands.
 */
class MediaVideoJob {

    /**
     * Charge the credits, make the placeholder video asset and queue the job. Used by the Studio's
     * "Generate a video" and by video automations. Returns ['ok', 'asset_id', 'message', 'price', 'balance'].
     */
    public static function start(array $user, $source_asset_id, $model_key, $prompt, $duration, array $extra = array()): array {
        $cid   = (int) $user['user_id'];
        $model = InfluencerConfig::resolve_model('video', (string) $model_key);
        if (!$model) { return array('ok' => false, 'message' => 'No video model is configured.'); }
        $durs = array_values(array_map('strval', (array) ($model['durations'] ?? array())));
        $dur  = (string) $duration;
        if (!empty($durs) && !in_array($dur, $durs, true)) { $dur = $durs[0]; }
        $pay = Plan::charge_ai($user, 'video', 'Video: ' . mb_substr((string) $prompt, 0, 60), array('model_key' => (string) $model['key'], 'duration' => $dur));
        if (empty($pay['ok'])) { return array('ok' => false, 'message' => $pay['message'], 'need_credits' => true, 'price' => $pay['price'], 'balance' => $pay['balance']); }
        $media = new MediaAssetsModel();
        $aid = (int) $media->add($cid, 'video', 'Generated · ' . mb_substr((string) $prompt, 0, 40) . '.mp4', 'video/mp4', 'processing');
        if ($aid <= 0) { (new AiCreditsModel())->apply_delta($cid, (int) $pay['price'], 'refund', 'Refund: video not started'); return array('ok' => false, 'message' => 'Could not save the video. Try again.'); }
        $job_id = (new DatabaseJobQueue())->dispatch('media_video', array(
            'creator_id' => $cid, 'asset_id' => $aid, 'source_asset_id' => (int) $source_asset_id, 'model_key' => (string) $model['key'],
            'prompt' => (string) $prompt, 'duration' => $dur, 'credits' => (int) $pay['price'],
        ) + $extra);
        if ($job_id <= 0) {
            (new AiCreditsModel())->refund_once($cid, (int) $pay['price'], 'video #' . $aid);
            $media->set_failed($cid, $aid, 'Could not queue the video.');
            return array('ok' => false, 'message' => 'Could not start the video. Try again.');
        }
        return array('ok' => true, 'asset_id' => $aid, 'price' => (int) $pay['price'], 'message' => '');
    }

    const POLL_SECONDS = 15;
    const MAX_POLLS    = 60;   // ~15 minutes

    public static function handle(array $payload): string {
        $cid   = (int) ($payload['creator_id'] ?? 0);
        $aid   = (int) ($payload['asset_id'] ?? 0);
        $media = new MediaAssetsModel();
        $fail  = function ($why) use ($cid, $aid, $payload, $media) {
            if ((int) ($payload['credits'] ?? 0) > 0) { (new AiCreditsModel())->refund_once($cid, (int) $payload['credits'], 'video #' . $aid); }
            $media->set_failed($cid, $aid, $why);
            if (!empty($payload['auto_post'])) { AutoPostService::finish_video($cid, (array) $payload['auto_post'], 0, $why); }
            return 'FAIL ' . $why;
        };
        // Anything unexpected (fal, S3, the database) fails the video once, with its credits back, instead of bubbling to
        // the queue, which would retry and then drop the job with the credits kept and the tile stuck on 'processing'.
        try {
            return self::run($payload, $cid, $aid, $media, $fail);
        } catch (\Throwable $e) {
            error_log('[media_video] asset ' . $aid . ': ' . $e->getMessage());
            return $fail('The video failed. Your AI credits were returned.');
        }
    }

    private static function run(array $payload, $cid, $aid, MediaAssetsModel $media, $fail): string {
        $asset = $media->get_one($cid, $aid);
        if (!$asset) {   // deleted while it rendered: nothing to fill, but the credits go back
            if ((int) ($payload['credits'] ?? 0) > 0) { (new AiCreditsModel())->refund_once($cid, (int) $payload['credits'], 'video #' . $aid); }
            return 'SKIP asset gone (refunded)';
        }
        if ((string) $asset['status'] === 'ready') { return 'OK already landed'; }
        $model = InfluencerConfig::resolve_model('video', (string) ($payload['model_key'] ?? ''));
        if (!$model) { return $fail('No video model is configured.'); }

        // 1) Send the request once.
        if (empty($payload['handle'])) {
            $src = $media->get_one($cid, (int) ($payload['source_asset_id'] ?? 0));
            if (!$src || (string) $src['status'] !== 'ready') { return $fail('The source image is not available.'); }
            $url = S3Service::presigned_get_url((string) ($src['original_key'] ?: $src['display_key']), 3600);
            if ($url === '') { return $fail('Could not read the source image.'); }
            $r = FalProvider::generate_video(array(
                'endpoint' => InfluencerConfig::endpoint_for($model, 'fal'), 'prompt' => (string) ($payload['prompt'] ?? ''),
                'duration' => (string) ($payload['duration'] ?? '5'), 'image_url' => $url, 'params' => (array) ($model['params'] ?? array()),
            ));
            if (empty($r['ok'])) {
                // fal briefly refusing the account: try again in 1, 2, then 3 minutes before giving up.
                $tries = (int) ($payload['unavailable'] ?? 0);
                if (($r['error_code'] ?? '') === 'unavailable' && $tries < 3) {
                    $payload['unavailable'] = $tries + 1;
                    if ((new DatabaseJobQueue())->dispatch('media_video', $payload, null, gmdate('Y-m-d H:i:s', time() + 60 * ($tries + 1))) > 0) {
                        return 'RETRY unavailable ' . ($tries + 1);
                    }
                }
                return $fail(($r['error_code'] ?? '') === 'unavailable' ? FalProvider::UNAVAILABLE : 'The video could not be started: ' . ($r['error'] ?? 'unknown error'));
            }
            $payload['handle'] = $r['handle']; $payload['polls'] = 0;
            if ((new DatabaseJobQueue())->dispatch('media_video', $payload, null, gmdate('Y-m-d H:i:s', time() + self::POLL_SECONDS)) <= 0) {
                FalProvider::cancel((array) $payload['handle']);
                return $fail('The video could not be tracked. Try again.');
            }
            return 'SUBMITTED ' . $r['handle']['provider_job_id'];
        }

        // 2) Check back until it is done.
        $st = FalProvider::get_job_status((array) $payload['handle']);
        if ($st['state'] === 'failed') { return $fail('The video failed: ' . ($st['error'] ?: 'the model returned an error')); }
        if ($st['state'] !== 'completed') {
            $payload['polls'] = (int) ($payload['polls'] ?? 0) + 1;
            if ($payload['polls'] > self::MAX_POLLS) { FalProvider::cancel((array) $payload['handle']); return $fail('The video took too long. Try again.'); }
            if ((new DatabaseJobQueue())->dispatch('media_video', $payload, null, gmdate('Y-m-d H:i:s', time() + self::POLL_SECONDS)) <= 0) {
                FalProvider::cancel((array) $payload['handle']);
                return $fail('The video could not be tracked. Try again.');
            }
            return 'WAITING ' . $payload['polls'];
        }

        // 3) Land it in the library.
        $res = FalProvider::fetch_result((array) $payload['handle']);
        $out = null;
        foreach ((array) ($res['outputs'] ?? array()) as $o) { if (($o['kind'] ?? '') === 'video') { $out = $o; break; } }
        if (empty($res['ok']) || !$out) { return $fail('The video came back empty. Try again.'); }
        if (!FalProvider::output_url_allowed((string) ($out['url'] ?? ''))) {   // only ever download from fal's own hosts
            error_log('[media_video] asset ' . $aid . ': unexpected output host ' . parse_url((string) ($out['url'] ?? ''), PHP_URL_HOST));
            return $fail('The video came back from an unexpected address. Try again.');
        }
        $user = BillingService::user($cid);
        try {
            $v = MediaIngestService::fetch_video($out['url'], (int) InfluencerConfig::get('output_max_video_bytes', 1073741824), 540);
            MediaIngestService::ingest_video_file($cid, $user, $v['path'], $v['ext'], $v['mime'], $v['bytes'], preg_replace('/\.mp4$/', '', (string) $asset['filename']), '', $aid, true);
        } catch (\Throwable $e) {
            return $fail($e->getMessage());
        }
        if (!empty($payload['auto_post'])) { AutoPostService::finish_video($cid, (array) $payload['auto_post'], $aid, ''); }
        return 'OK asset ' . $aid;
    }
}
