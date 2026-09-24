<?php
/**
 * Job handler: turn a library image into a short video (Studio "Generate a video"). The request
 * goes to the image-to-video model on fal; the job re-queues itself to check back until the
 * video is ready, then ingests it into the placeholder 'processing' asset. AI credits were taken
 * when the video was requested; any failure gives them back once (refund keyed by the asset).
 *
 * payload: creator_id, asset_id (placeholder video), source_asset_id (the image), model_key,
 *          prompt, duration, credits; later also handle (fal request) and polls.
 */
class MediaVideoJob {

    const POLL_SECONDS = 15;
    const MAX_POLLS    = 60;   // ~15 minutes

    public static function handle(array $payload): string {
        $cid   = (int) ($payload['creator_id'] ?? 0);
        $aid   = (int) ($payload['asset_id'] ?? 0);
        $media = new MediaAssetsModel();
        $fail  = function ($why) use ($cid, $aid, $payload, $media) {
            if ((int) ($payload['credits'] ?? 0) > 0) { (new AiCreditsModel())->refund_once($cid, (int) $payload['credits'], 'video #' . $aid); }
            $media->set_failed($cid, $aid, $why);
            return 'FAIL ' . $why;
        };
        $asset = $media->get_one($cid, $aid);
        if (!$asset) { return 'SKIP asset gone'; }
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
            if (empty($r['ok'])) { return $fail('The video could not be started: ' . ($r['error'] ?? 'unknown error')); }
            $payload['handle'] = $r['handle']; $payload['polls'] = 0;
            (new DatabaseJobQueue())->dispatch('media_video', $payload, null, gmdate('Y-m-d H:i:s', time() + self::POLL_SECONDS));
            return 'SUBMITTED ' . $r['handle']['provider_job_id'];
        }

        // 2) Check back until it is done.
        $st = FalProvider::get_job_status((array) $payload['handle']);
        if ($st['state'] === 'failed') { return $fail('The video failed: ' . ($st['error'] ?: 'the model returned an error')); }
        if ($st['state'] !== 'completed') {
            $payload['polls'] = (int) ($payload['polls'] ?? 0) + 1;
            if ($payload['polls'] > self::MAX_POLLS) { FalProvider::cancel((array) $payload['handle']); return $fail('The video took too long. Try again.'); }
            (new DatabaseJobQueue())->dispatch('media_video', $payload, null, gmdate('Y-m-d H:i:s', time() + self::POLL_SECONDS));
            return 'WAITING ' . $payload['polls'];
        }

        // 3) Land it in the library.
        $res = FalProvider::fetch_result((array) $payload['handle']);
        $out = null;
        foreach ((array) ($res['outputs'] ?? array()) as $o) { if (($o['kind'] ?? '') === 'video') { $out = $o; break; } }
        if (empty($res['ok']) || !$out) { return $fail('The video came back empty. Try again.'); }
        $user = BillingService::user($cid);
        try {
            $v = MediaIngestService::fetch_video($out['url'], (int) InfluencerConfig::get('output_max_video_bytes', 1073741824), 540);
            MediaIngestService::ingest_video_file($cid, $user, $v['path'], $v['ext'], $v['mime'], $v['bytes'], preg_replace('/\.mp4$/', '', (string) $asset['filename']), '', $aid, true);
        } catch (\Throwable $e) {
            return $fail($e->getMessage());
        }
        return 'OK asset ' . $aid;
    }
}
