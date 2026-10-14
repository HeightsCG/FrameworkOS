<?php
/**
 * Job handler: make one platform version of an image in the background (MediaCompliance::start_variant). Queued as
 * soon as the composer, a schedule or an automation picks the image, so the version is usually ready before Publish.
 * payload: creator_id, asset_id, teaser, platform, placement, method (fit | crop), focus (top | center | bottom).
 */
class MediaVariantJob {

    public static function handle(array $payload): string {
        $cid = (int) ($payload['creator_id'] ?? 0);
        $asset = (new MediaAssetsModel())->get_one($cid, (int) ($payload['asset_id'] ?? 0));
        if (!$asset || (string) $asset['status'] !== 'ready' || !in_array((string) $asset['type'], array('image', 'video'), true)) { return 'SKIP source gone or not an image or video'; }
        $v = MediaCompliance::make_variant($cid, $asset, !empty($payload['teaser']), (string) ($payload['platform'] ?? ''), (string) ($payload['placement'] ?? 'feed'),
            (string) ($payload['method'] ?? 'fit'), (string) ($payload['focus'] ?? 'center'));
        return $v ? 'OK variant ' . (int) $v['id'] . ' ' . MediaCompliance::variant_state($v) : 'FAIL could not make the version';
    }
}
