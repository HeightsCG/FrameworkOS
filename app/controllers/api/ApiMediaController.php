<?php
/** Creator media library: uploads (single + chunked), AI generation, collections. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiMediaController extends BaseApiController {

    public function media_uploadAction(){
        @ini_set('memory_limit', '512M');
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];

        if (!S3Service::configured()) {
            $this->jsonError('Uploads are unavailable right now. Please try again shortly.');
        }
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $this->jsonError('No file was received. Please pick a file and try again.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($file['tmp_name']);
        // Audio (a voice track for a clip edit or a talking video): MP3, WAV or M4A, stored as it is.
        if (MediaIngestService::verify_audio_file($file['tmp_name']) !== null) {
            if ((int) $file['size'] > MediaLimits::MAX_IMAGE_BYTES * 2) { $this->jsonError('That audio file is too large. Audio can be up to 30 MB.'); }
            $copy = tempnam(sys_get_temp_dir(), 'upaud');
            if ($copy === false || !copy($file['tmp_name'], $copy)) { $this->jsonError('Could not read that file. Please try again.'); }
            try {
                $name = preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', (string) ($file['name'] ?? 'Audio'));
                $r = MediaIngestService::ingest_audio_file($creator_id, $user, $copy, $name !== '' ? $name : 'Audio');
            } catch (\Throwable $e) {
                $this->jsonError($e->getMessage());
            }
            $a = (new MediaAssetsModel())->get_one($creator_id, (int) $r['asset_id']);
            $this->jsonSuccess(['asset' => $this->studio_asset_json($a, $creator_id)]);
        }
        $types = $this->studio_media_types();
        if (!isset($types[$mime]) || $types[$mime][0] === 'video') {
            $this->jsonError('That file type is not supported here. Use JPG, PNG, WebP, GIF, MP3, WAV or M4A.');
        }
        list($type, $ext, $max) = $types[$mime];
        if ((int) $file['size'] > $max) {
            $this->jsonError('That image is too large. Images can be up to 15 MB.');
        }
        if (!$this->within_storage_cap($user, (int) $file['size'])) {
            $this->jsonError("You've reached your plan's storage limit. Upgrade or remove files to free up space.", ['need_upgrade' => true]);
        }

        $orig_name = (string) ($file['name'] ?? 'upload.' . $ext);
        $model     = new MediaAssetsModel();
        $asset_id  = (int) $model->add($creator_id, $type, $orig_name, $mime, 'processing');
        if ($asset_id <= 0) {
            $this->jsonError('Could not start the upload. Please try again.');
        }

        $watermark = !empty($user['watermark_enabled']);
        $res = MediaService::process_image($creator_id, $asset_id, $file['tmp_name'], $ext, $mime, $user, $watermark);
        if (isset($res['error'])) {
            $model->set_failed($creator_id, $asset_id, $res['error']);
            $a = $model->get_one($creator_id, $asset_id);
            $this->jsonError((string) ($res['error']), ['asset' => $a ? $this->studio_asset_json($a, $creator_id) : null]);
        }
        $model->set_ready($creator_id, $asset_id, $res);
        $a = $model->get_one($creator_id, $asset_id);
        $this->jsonSuccess(['asset' => $this->studio_asset_json($a, $creator_id)]);
    }

    /** Generate an image with OpenAI, folding in the creator's brand, and ingest it as a vault asset. */
    public function media_generateAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        if (!S3Service::configured()) {
            $this->jsonError('Image generation is unavailable right now. Please try again shortly.');
        }
        $prompt = html_entity_decode(trim((string) ($this->post['prompt'] ?? '')), ENT_QUOTES);
        if ($prompt === '') {
            $this->jsonError('Describe the image you want to generate.');
        }
        $size_key  = (string) ($this->post['size'] ?? 'square');
        $use_brand = ((string) ($this->post['use_brand'] ?? '1')) !== '0';

        $final = $prompt; $brand_used = false;
        if ($use_brand) {
            $cb = (new CreatorBrandModel())->get_for_user($creator_id);
            if (!empty($cb['brand_name']) || !empty($cb['colors']) || !empty($cb['voice']) || !empty($cb['keywords'])) {
                $final = $this->brand_image_prompt($prompt, $cb);
                $brand_used = true;
            }
        }

        // An AI image costs AI credits on every plan (PlanTiers::AI_PRICES); a failed run gives them back.
        $pay = Plan::charge_ai($user, 'image', 'Studio image: ' . mb_substr($prompt, 0, 60));
        if (empty($pay['ok'])) { $this->jsonError($pay['message'], ['need_credits' => true, 'price' => $pay['price'], 'balance' => $pay['balance']]); }

        $model    = new MediaAssetsModel();
        $label    = 'Generated · ' . mb_substr($prompt, 0, 40);
        $asset_id = (int) $model->add($creator_id, 'image', $label . '.png', 'image/png', 'processing', 'generated');
        if ($asset_id <= 0) { (new AiCreditsModel())->apply_delta($creator_id, (int) $pay['price'], 'refund', 'Refund: image not started'); $this->jsonError('Could not save the image. Try again.'); }

        $job_id = (new DatabaseJobQueue())->dispatch('media_generate', [
            'creator_id' => $creator_id,
            'asset_id'   => $asset_id,
            'prompt'     => $final,
            'size'       => $size_key,
            'watermark'  => !empty($user['watermark_enabled']),
            'credits'    => (int) $pay['price'],
        ]);
        if ($job_id <= 0) {
            (new AiCreditsModel())->refund_once($creator_id, (int) $pay['price'], 'image #' . $asset_id);
            $model->set_failed($creator_id, $asset_id, 'Could not queue the generation.');
            $this->jsonError('Could not start the image generation. Try again.');
        }
        // The asset is returned in 'processing' state; the page polls media_get until it is ready or failed.
        $a = $model->get_one($creator_id, $asset_id);
        $this->jsonSuccess(['queued' => true, 'job_id' => $job_id, 'brand_used' => $brand_used,
            'asset' => $this->studio_asset_json($a, $creator_id)]);
    }
    /**
     * Turn a library image into a short video (image-to-video on fal). Costs the model's AI
     * credits up front; the media_video job lands it in the library or refunds on failure.
     */
    public function media_generate_videoAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        if (!S3Service::configured() || !InfluencerConfig::enabled()) { $this->jsonError('Video generation is unavailable right now. Please try again shortly.'); }
        $src = (new MediaAssetsModel())->get_one($creator_id, (int) ($this->post['source_asset_id'] ?? 0));
        if (!$src || (string) $src['type'] !== 'image' || (string) $src['status'] !== 'ready') { $this->jsonError('Pick an image from your Library.'); }
        $prompt = html_entity_decode(trim((string) ($this->post['prompt'] ?? '')), ENT_QUOTES);
        if ($prompt === '') { $this->jsonError('Describe the motion you want.'); }
        $model = InfluencerConfig::resolve_model('video', (string) ($this->post['model_key'] ?? ''));
        if (!$model) { $this->jsonError('No video model is configured.'); }
        $durs = array_values((array) ($model['durations'] ?? array()));
        $dur  = (string) ($this->post['duration'] ?? ($durs[0] ?? '5'));
        if (!empty($durs) && !in_array($dur, $durs, true)) { $dur = (string) $durs[0]; }
        if (!$this->within_storage_cap($user, 20 * 1048576)) {   // a short video is ~5-20 MB
            $this->jsonError("You've reached your plan's storage limit. Upgrade or remove files to free up space.", ['need_upgrade' => true]);
        }

        $v = MediaVideoJob::start($user, (int) $src['id'], (string) $model['key'], $prompt, $dur);
        if (empty($v['ok'])) {
            $this->jsonError($v['message'], !empty($v['need_credits']) ? ['need_credits' => true, 'price' => $v['price'], 'balance' => $v['balance']] : []);
        }
        $a = (new MediaAssetsModel())->get_one($creator_id, (int) $v['asset_id']);
        $this->jsonSuccess(['queued' => true, 'price' => (int) $v['price'], 'asset' => $this->studio_asset_json($a, $creator_id)]);
    }

    /** Begin (or resume) a resumable multipart video upload. */
    public function media_upload_initAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        if (!S3Service::configured()) {
            $this->jsonError('Uploads are unavailable right now. Please try again shortly.');
        }
        $filename = trim(html_entity_decode((string) ($this->post['filename'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $mime     = (string) ($this->post['mime'] ?? '');
        $bytes    = (int) ($this->post['bytes_total'] ?? 0);
        $token    = (string) ($this->post['client_token'] ?? '');
        $types    = $this->studio_media_types();
        if (!isset($types[$mime]) || $types[$mime][0] !== 'video') {
            $this->jsonError('That video format is not supported. Use MP4, MOV, or WebM.');
        }
        list($type, $ext, $max) = $types[$mime];
        if ($bytes <= 0 || $bytes > $max) {
            $this->jsonError('That video is too large. Videos can be up to 4 GB.');
        }
        if (!$this->within_storage_cap($user, $bytes)) {
            $this->jsonError("You've reached your plan's storage limit. Upgrade or remove files to free up space.", ['need_upgrade' => true]);
        }

        $sessions = new UploadSessionsModel();
        // Resume an existing active session for the same file if we have one.
        if ($token !== '') {
            $existing = $sessions->get_active_by_token($creator_id, $token);
            if ($existing) {
                $nums = [];
                foreach ($sessions->parts($existing) as $p) { $nums[] = (int) $p['PartNumber']; }
                $this->jsonSuccess(['session_id' => (int) $existing['id'], 'asset_id' => (int) $existing['asset_id'], 'part_size' => MediaLimits::CHUNK_SIZE, 'uploaded_parts' => $nums, 'bytes_received' => (int) $existing['bytes_received'], 'resumed' => true]);
            }
        }

        $model    = new MediaAssetsModel();
        $asset_id = (int) $model->add($creator_id, 'video', $filename !== '' ? $filename : ('video.' . $ext), $mime, 'uploading');
        if ($asset_id <= 0) {
            $this->jsonError('Could not start the upload. Please try again.');
        }
        $key       = MediaService::key($creator_id, $asset_id, 'original', $ext);
        $upload_id = S3Service::create_multipart($key, $mime);
        if ($upload_id === '') {
            $model->set_failed($creator_id, $asset_id, 'Could not begin the upload');
            $this->jsonError('Could not begin the upload. Please try again.');
        }
        $session_id = (int) $sessions->create($creator_id, $filename, $mime, $bytes, $key, $upload_id, $token, $asset_id);

        $this->jsonSuccess(['session_id' => $session_id, 'asset_id' => $asset_id, 'part_size' => MediaLimits::CHUNK_SIZE, 'uploaded_parts' => [], 'bytes_received' => 0, 'resumed' => false]);
    }

    /** Upload one ~8 MB part of a resumable video upload. */
    public function media_upload_chunkAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $session_id = (int) ($this->post['session_id'] ?? 0);
        $part_no    = (int) ($this->post['part_number'] ?? 0);
        $sessions   = new UploadSessionsModel();
        $session    = $sessions->get_one($creator_id, $session_id);
        if (!$session || $session['status'] !== 'active') {
            $this->jsonError('This upload session is no longer active. Please restart the upload.');
        }
        // The storage cap was checked against the DECLARED size, so hold the parts to it: no part
        // beyond what that size needs, and no part bigger than a chunk.
        $max_parts = (int) ceil(max(1, (int) $session['bytes_total']) / MediaLimits::CHUNK_SIZE);
        if ($part_no < 1 || $part_no > $max_parts) {
            $this->jsonError('Invalid upload chunk.');
        }
        $chunk = $_FILES['chunk'] ?? null;
        if (!$chunk || ($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($chunk['tmp_name'])) {
            $this->jsonError('That chunk did not arrive. It will be retried.');
        }
        if ((int) ($chunk['size'] ?? 0) > MediaLimits::CHUNK_SIZE) {
            $this->jsonError('Invalid upload chunk.');
        }
        $body = @file_get_contents($chunk['tmp_name']);
        $size = strlen((string) $body);
        $etag = S3Service::upload_part($session['storage_key'], $session['s3_upload_id'], $part_no, $body);
        if ($etag === '') {
            $this->jsonError('That chunk failed to store. It will be retried.');
        }
        $sessions->add_part($creator_id, $session_id, $part_no, $etag, $size);
        $fresh = $sessions->get_one($creator_id, $session_id);
        $this->jsonSuccess(['bytes_received' => (int) $fresh['bytes_received']]);
    }

    /** Report resume state for a file fingerprint (uploaded part numbers). */
    public function media_upload_statusAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $token      = (string) ($this->post['client_token'] ?? '');
        $session    = $token !== '' ? (new UploadSessionsModel())->get_active_by_token($creator_id, $token) : null;
        if (!$session) {
            $this->jsonSuccess(['active' => false]);
        }
        $nums = [];
        foreach ((new UploadSessionsModel())->parts($session) as $p) { $nums[] = (int) $p['PartNumber']; }
        $this->jsonSuccess(['active' => true, 'session_id' => (int) $session['id'], 'asset_id' => (int) $session['asset_id'], 'part_size' => MediaLimits::CHUNK_SIZE, 'uploaded_parts' => $nums, 'bytes_received' => (int) $session['bytes_received']]);
    }

    /** Finish a multipart video upload: assemble in S3, extract poster+duration, go ready. */
    public function media_upload_completeAction(){
        @ini_set('memory_limit', '512M');
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $session_id = (int) ($this->post['session_id'] ?? 0);
        $sessions   = new UploadSessionsModel();
        $session    = $sessions->get_one($creator_id, $session_id);
        $model      = new MediaAssetsModel();
        // Idempotent: a retried finish (the first response was lost to a timeout) must
        // return the asset the first call already produced, not an error.
        if ($session && $session['status'] === 'completed') {
            $done = $model->get_one($creator_id, (int) $session['asset_id']);
            if ($done && $done['status'] === 'ready') {
                $this->jsonSuccess(['asset' => $this->studio_asset_json($done, $creator_id)]);
            }
        }
        if (!$session || $session['status'] !== 'active') {
            $this->jsonError('This upload session is no longer active. Please restart the upload.');
        }
        $asset_id = (int) $session['asset_id'];

        $parts = $sessions->parts($session);
        if (empty($parts)) {
            $this->jsonError('No video data was received. Please try the upload again.');
        }
        $s3parts = [];
        foreach ($parts as $p) { $s3parts[] = ['PartNumber' => (int) $p['PartNumber'], 'ETag' => (string) $p['ETag']]; }
        if (!S3Service::complete_multipart($session['storage_key'], $session['s3_upload_id'], $s3parts)) {
            $model->set_failed($creator_id, $asset_id, 'Could not assemble the uploaded video');
            $this->jsonError('The upload could not be finalized. Please try again.');
        }

        // The browser captured a poster frame + duration/dimensions from the local file.
        // When it did, use them directly: no round trip through ffmpeg over S3, so this
        // step is quick and can't time out. ffmpeg/ffprobe over a signed URL is the
        // fallback for browsers that couldn't decode the video.
        $client_poster = (isset($_FILES['poster']) && is_uploaded_file($_FILES['poster']['tmp_name'] ?? '')) ? (string) $_FILES['poster']['tmp_name'] : '';
        $client_probe  = ['duration' => (int) ($this->post['client_duration'] ?? 0), 'width' => (int) ($this->post['client_width'] ?? 0), 'height' => (int) ($this->post['client_height'] ?? 0)];
        if ($client_poster !== '' && $client_probe['width'] > 0) {
            $probe = $client_probe;
            $res   = MediaService::process_video($creator_id, $asset_id, '', $user, $client_poster);
        } else {
            $src   = S3Service::presigned_get_url($session['storage_key'], 900);
            $probe = MediaService::probe_video($src);
            $res   = MediaService::process_video($creator_id, $asset_id, $src, $user, $client_poster);
        }
        if (isset($res['error'])) {
            $model->set_failed($creator_id, $asset_id, $res['error']);
            $this->jsonError((string) ($res['error']));
        }
        $fields = array_merge($res, [
            'original_key' => $session['storage_key'],
            'bytes'        => (int) $session['bytes_received'],
            'duration_sec' => (int) ($probe['duration'] ?? 0) ?: (int) ($this->post['client_duration'] ?? 0),
            'width'        => (int) ($probe['width'] ?? 0)    ?: (int) ($this->post['client_width'] ?? 0),
            'height'       => (int) ($probe['height'] ?? 0)   ?: (int) ($this->post['client_height'] ?? 0),
        ]);
        $model->set_ready($creator_id, $asset_id, $fields);
        $sessions->mark_completed($creator_id, $session_id, $asset_id);
        $a = $model->get_one($creator_id, $asset_id);
        $this->jsonSuccess(['asset' => $this->studio_asset_json($a, $creator_id)]);
    }

    /** Library grid: the creator's media assets, optionally filtered by type/collection/usage/search. */
    public function media_listAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $filters = [
            'type'       => (string) ($this->post['type'] ?? ''),
            'collection' => (int) ($this->post['collection'] ?? 0),
            'usage'      => (string) ($this->post['usage'] ?? ''),
            'search'     => (string) ($this->post['search'] ?? ''),
            'influencer' => (int) ($this->post['influencer'] ?? 0),
            'role'       => (string) ($this->post['role'] ?? ''),
            'brand'      => (int) ($this->post['brand'] ?? 0),   // generated with no influencer in it
        ];
        $rows = (new MediaAssetsModel())->get_for_creator($creator_id, $filters);
        $assets = [];
        foreach ($rows as $a) { $assets[] = $this->studio_asset_json($a, $creator_id); }
        $this->jsonSuccess(['assets' => $assets, 'total' => count($assets)]);
    }

    /** Full detail for one asset: signed preview, metadata, usage, collections. */
    public function media_getAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new MediaAssetsModel();
        $a          = $model->get_one($creator_id, $id);
        if (!$a) { $this->jsonError('That file was not found.'); }

        $preview = ($a['type'] === 'video')
            ? MediaService::signed_url($a, 'poster', $creator_id)
            : MediaService::signed_url($a, 'display', $creator_id);
        $video_url = ($a['type'] === 'video') ? MediaService::signed_url($a, 'original', $creator_id) : '';
        $usage = $model->get_posts_using($creator_id, $id);
        $out = $this->studio_asset_json($a, $creator_id);
        $out['preview_url']    = $preview;
        $out['video_url']      = $video_url;
        $out['collection_ids'] = $model->get_collection_ids($id);
        $out['posts']          = [];
        foreach ($usage as $p) {
            $cap = trim((string) $p['caption']);
            $out['posts'][] = [
                'id'      => (int) $p['id'],
                'excerpt' => $cap === '' ? '(no caption)' : mb_substr($cap, 0, 60),
                'state'   => $p['state'],
            ];
        }
        $this->jsonSuccess(['asset' => $out]);
    }

    /** Rename + retag an asset. */
    public function media_updateAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new MediaAssetsModel();
        if (!$model->get_one($creator_id, $id)) {
            $this->jsonError('That file was not found.');
        }
        $description = trim(html_entity_decode((string) ($this->post['description'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $model->set_description($creator_id, $id, $description);
        $a = $model->get_one($creator_id, $id);
        $this->jsonSuccess(['asset' => $this->studio_asset_json($a, $creator_id)]);
    }

    /** Mint a fresh signed URL for a variant (used when a grid thumb URL expires). */
    public function media_signAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $variant    = (string) ($this->post['variant'] ?? 'thumb');
        $a          = (new MediaAssetsModel())->get_one($creator_id, $id);
        if (!$a) { $this->jsonError('That file was not found.'); }
        $this->jsonSuccess(['url' => MediaService::signed_url($a, $variant, $creator_id)]);
    }

    /** Download link for one Library file: the untouched original, saved under its Library name. */
    public function media_downloadAction(){
        $user = $this->require_creator('content', false);
        $a    = (new MediaAssetsModel())->get_one((int) $user['user_id'], (int) ($this->post['id'] ?? 0));
        if (!$a || (string) $a['status'] !== 'ready') { $this->jsonError('That file is not ready to download.'); }
        $url = MediaService::download_url($a, 'original');
        if ($url === '') { $url = MediaService::download_url($a, $a['type'] === 'video' ? 'poster' : 'display'); }
        if ($url === '') { $this->jsonError('Could not prepare that download.'); }
        $this->jsonSuccess(['url' => $url]);
    }

    /** Toggle the baked watermark on an image by re-processing from the original. */
    public function media_watermarkAction(){
        @ini_set('memory_limit', '512M');
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $enabled    = ((string) ($this->post['enabled'] ?? '1')) === '1';
        $model      = new MediaAssetsModel();
        $a          = $model->get_one($creator_id, $id);
        if (!$a) { $this->jsonError('That file was not found.'); }
        if ($a['type'] === 'video') {
            $this->jsonError('Video watermarks show as an overlay on the player and cannot be turned off per-file.');
        }
        if (empty($a['original_key'])) {
            $this->jsonError('The original file is unavailable, so the watermark cannot be changed.');
        }
        if (($a['moderation_status'] ?? '') === 'blocked') {
            $this->jsonError('This file was blocked by our content check and cannot be changed.');
        }
        // Pull the original down to a temp file, then re-run the image pipeline.
        $url = S3Service::presigned_get_url($a['original_key'], 300);
        $tmp = tempnam(sys_get_temp_dir(), 'wm');
        // Stream straight to disk (no whole-file buffer in memory); a 4xx/5xx makes copy() return false.
        $context = stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'ignore_errors' => false]]);
        if ($url === '' || !@copy($url, $tmp, $context) || !is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            $this->jsonError('Could not read the original file. Please try again.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        $types = $this->studio_media_types();
        $ext   = isset($types[$mime]) ? $types[$mime][1] : 'jpg';
        $res   = MediaService::process_image($creator_id, $id, $tmp, $ext, $mime, $user, $enabled);
        @unlink($tmp);
        if (isset($res['error'])) {
            $this->jsonError((string) ($res['error']));
        }
        // Same image, new watermark: keep the existing moderation verdict (incl. any admin decision).
        unset($res['moderation_status'], $res['moderation_score'], $res['moderation_labels']);
        $model->set_ready($creator_id, $id, $res);
        $a = $model->get_one($creator_id, $id);
        $this->jsonSuccess(['asset' => $this->studio_asset_json($a, $creator_id)]);
    }

    /** Soft-delete an asset (recoverable). Removes it from all collections. */
    public function media_deleteAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $model      = new MediaAssetsModel();
        $a          = $model->get_one($creator_id, $id);
        if (!$a) { $this->jsonError('That file was not found.'); }
        $model->soft_delete($creator_id, $id);
        (new CollectionsModel())->remove_asset_everywhere($id);
        $r = (new PostsModel())->detach_asset($creator_id, $id);
        $this->jsonSuccess(['affected_posts' => $r['affected'], 'unpublished' => $r['unpublished'], 'message' => 'File removed.' . $this->detach_note($r)]);
    }

    /** Bulk actions over selected assets: add to collection, tag, or delete. */
    public function media_bulkAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $action     = (string) ($this->post['bulk_action'] ?? '');
        $ids        = $this->post['ids'] ?? [];
        if (!is_array($ids)) { $ids = []; }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) { $this->jsonError('No files were selected.'); }

        $model = new MediaAssetsModel();
        // Ownership: keep only assets that belong to this creator.
        $owned = [];
        foreach ($ids as $id) { if ($model->get_one($creator_id, $id)) { $owned[] = $id; } }
        if (empty($owned)) { $this->jsonError('No matching files were found.'); }

        if ($action === 'collection_add') {
            $col = (int) ($this->post['collection_id'] ?? 0);
            if (!(new CollectionsModel())->get_one($creator_id, $col)) {
                $this->jsonError('That collection was not found.');
            }
            (new CollectionsModel())->add_assets($col, $owned);
            $this->jsonSuccess(['message' => count($owned) . ' file(s) added.']);
        }
        if ($action === 'delete') {
            $col = new CollectionsModel();
            $tot = array('affected' => 0, 'unpublished' => 0);
            foreach ($owned as $id) {
                $model->soft_delete($creator_id, $id); $col->remove_asset_everywhere($id);
                $r = (new PostsModel())->detach_asset($creator_id, $id); $tot['affected'] += $r['affected']; $tot['unpublished'] += $r['unpublished'];
            }
            $this->jsonSuccess(['message' => count($owned) . ' file(s) removed.' . $this->detach_note($tot), 'unpublished' => $tot['unpublished']]);
        }
        $this->jsonError('Unknown action.');
    }

    /** "2 posts lost their only media and are back in Drafts" — or nothing when no post changed state. */
    private function detach_note(array $r): string{
        $n = (int) ($r['unpublished'] ?? 0);
        if ($n <= 0) { return ''; }
        return ' ' . ($n === 1 ? '1 post lost its only media and is back in Drafts.' : $n . ' posts lost their only media and are back in Drafts.');
    }

    /* ---------- Content Studio: collections ---------- */

    public function collections_listAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $rows = (new CollectionsModel())->get_for_creator($creator_id);
        $out = [];
        foreach ($rows as $c) {
            $out[] = [
                'id'          => (int) $c['id'],
                'name'        => $c['name'],
                'asset_count' => (int) $c['asset_count'],
            ];
        }
        $this->jsonSuccess(['collections' => $out]);
    }

    public function collection_saveAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $name       = trim(html_entity_decode((string) ($this->post['name'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($name === '') { $this->jsonError('Give the collection a name.'); }
        $model = new CollectionsModel();
        if ($id > 0) {
            if (!$model->get_one($creator_id, $id)) { $this->jsonError('That collection was not found.'); }
            $model->rename($creator_id, $id, $name);
        } else {
            $id = (int) $model->add($creator_id, $name);
        }
        $this->jsonSuccess(['id' => $id, 'name' => $name]);
    }

    public function collection_deleteAction(){
        $user       = $this->require_creator('content', false);
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        (new CollectionsModel())->delete_collection($creator_id, $id);
        $this->jsonSuccess(['message' => 'Collection deleted.']);
    }

    public function collection_add_assetsAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $ids        = $this->post['ids'] ?? [];
        if (!is_array($ids)) { $ids = []; }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $model = new CollectionsModel();
        if (!$model->get_one($creator_id, $id)) { $this->jsonError('That collection was not found.'); }
        // Only add assets this creator owns.
        $ma = new MediaAssetsModel();
        $owned = [];
        foreach ($ids as $aid) { if ($ma->get_one($creator_id, $aid)) { $owned[] = $aid; } }
        $model->add_assets($id, $owned);
        $this->jsonSuccess(['message' => count($owned) . ' file(s) added.']);
    }

    public function collection_remove_assetsAction(){
        $user       = $this->require_creator();
        $creator_id = (int) $user['user_id'];
        $id         = (int) ($this->post['id'] ?? 0);
        $ids        = $this->post['ids'] ?? [];
        if (!is_array($ids)) { $ids = []; }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $model = new CollectionsModel();
        if (!$model->get_one($creator_id, $id)) { $this->jsonError('That collection was not found.'); }
        $model->remove_assets($id, $ids);
        $this->jsonSuccess(['message' => 'Removed from collection.']);
    }

    /* ---------- Content Studio: post composer ---------- */

    /** Accepted vault media: real mime => [type, extension, max bytes]. */
    private function studio_media_types(): array{
        return [
            // images (processed server-side via GD)
            'image/jpeg' => ['image', 'jpg',  MediaLimits::MAX_IMAGE_BYTES],
            'image/png'  => ['image', 'png',  MediaLimits::MAX_IMAGE_BYTES],
            'image/webp' => ['image', 'webp', MediaLimits::MAX_IMAGE_BYTES],
            'image/gif'  => ['gif',   'gif',  MediaLimits::MAX_IMAGE_BYTES],
            // videos (resumable multipart; poster + duration extracted via ffmpeg)
            'video/mp4'       => ['video', 'mp4',  MediaLimits::MAX_VIDEO_BYTES],
            'video/quicktime' => ['video', 'mov',  MediaLimits::MAX_VIDEO_BYTES],
            'video/webm'      => ['video', 'webm', MediaLimits::MAX_VIDEO_BYTES],
        ];
    }

    /** Shape a media_assets row for the client, with a fresh signed thumbnail URL. */
    private function studio_asset_json(array $a, int $creator_id): array{
        $name = (isset($a['display_name']) && $a['display_name'] !== null && $a['display_name'] !== '')
            ? $a['display_name'] : $a['filename'];
        $tags = [];
        foreach (explode(',', (string) ($a['tags'] ?? '')) as $t) {
            $t = trim($t);
            if ($t !== '') { $tags[] = $t; }
        }
        $thumb = ($a['status'] === 'ready') ? MediaService::signed_url($a, 'thumb', $creator_id) : '';
        return [
            'id'                => (int) $a['id'],
            'type'              => $a['type'],
            'status'            => $a['status'],
            'name'              => $name,
            'filename'          => $a['filename'],
            'description'       => (string) ($a['description'] ?? ''),
            'tags'              => $tags,
            'duration'          => isset($a['duration_sec']) && $a['duration_sec'] !== null ? (int) $a['duration_sec'] : null,
            'width'             => isset($a['width'])  && $a['width']  !== null ? (int) $a['width']  : null,
            'height'            => isset($a['height']) && $a['height'] !== null ? (int) $a['height'] : null,
            'bytes'             => isset($a['bytes'])  && $a['bytes']  !== null ? (int) $a['bytes']  : null,
            'watermark_applied' => (int) ($a['watermark_applied'] ?? 0),
            'usage_count'       => (int) ($a['usage_count'] ?? 0),
            'failure_reason'    => (string) ($a['failure_reason'] ?? ''),
            'moderation'        => ($a['type'] === 'image') ? (string) ($a['moderation_status'] ?? 'pending') : 'n/a',
            'created_at'        => $a['created_at'],
            'thumb_url'         => $thumb,
            'video_url'         => ($a['type'] === 'video' && $a['status'] === 'ready') ? MediaService::signed_url($a, 'original', $creator_id) : '',
            'audio_url'         => ($a['type'] === 'audio' && $a['status'] === 'ready') ? MediaService::signed_variant($a, 'original', 1800) : '',
            'provenance'        => (string) ($a['provenance'] ?? 'uploaded'),
        ];
    }

    /** Single-request upload for images/gifs (small enough for one POST). */
    /** Would storing $incoming more bytes keep the creator within their plan's storage cap? (null/0 = unlimited) */
    private function within_storage_cap(array $user, int $incoming): bool{
        $gb = Plan::limit($user, 'storage_gb');
        if ($gb === null || (int) $gb <= 0) { return true; }
        $cap  = (int) $gb * 1073741824; // GB → bytes
        $used = (int) (new MediaAssetsModel())->total_bytes((int) $user['user_id']);
        return ($used + (int) $incoming) <= $cap;
    }

    /** Weave the creator's brand into an image prompt (shared with the Scheduler). */
    private function brand_image_prompt(string $prompt, array $cb): string{
        return BrandService::image_prompt($prompt, (array) $cb);
    }

}
