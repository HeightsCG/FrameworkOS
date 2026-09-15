<?php
/** Job handler: generate an AI image into an already-created 'processing' media asset. */
class MediaGenerateJob {

    public static function handle(array $payload): string {
        $creator_id = (int) ($payload['creator_id'] ?? 0);
        $asset_id   = (int) ($payload['asset_id'] ?? 0);
        $prompt     = (string) ($payload['prompt'] ?? '');
        $size_key   = (string) ($payload['size'] ?? 'square');
        $watermark  = !empty($payload['watermark']);
        $model      = new MediaAssetsModel();

        $rows = (new UsersModel())->get_user_by_id($creator_id);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$user || !$model->get_one($creator_id, $asset_id)) {
            $model->set_failed($creator_id, $asset_id, 'Creator or asset not found.');
            return 'FAIL creator/asset missing';
        }
        try {
            $res = ImageGenService::generate($prompt, ImageGenService::dimensions($size_key));
            if (empty($res['ok'])) {
                $model->set_failed($creator_id, $asset_id, $res['error'] ?? 'Generation failed. Try again.');
                return 'FAIL ' . ($res['error'] ?? 'generation');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'gen');
            if ($tmp === false || file_put_contents($tmp, $res['bytes']) === false) {
                $model->set_failed($creator_id, $asset_id, 'Could not process the generated image. Try again.');
                return 'FAIL temp file';
            }
            $r = MediaService::process_image($creator_id, $asset_id, $tmp, 'png', 'image/png', $user, $watermark);
            @unlink($tmp);
            if (isset($r['error'])) {
                $model->set_failed($creator_id, $asset_id, $r['error']);
                return 'FAIL ' . $r['error'];
            }
            $model->set_ready($creator_id, $asset_id, $r);
            return 'OK asset ' . $asset_id;
        } catch (\Throwable $e) {
            $model->set_failed($creator_id, $asset_id, 'Generation failed: ' . $e->getMessage());
            throw $e;   // lets the queue record the error; the asset already shows the failure
        }
    }
}
