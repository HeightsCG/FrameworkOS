<?php
/**
 * LoRA training for an influencer: builds the training ZIP from the chosen library assets,
 * submits it through the provider layer (via InfluencerJobService), and on completion
 * mirrors the weights to S3, activates the new model and flips the influencer to ready.
 * A retrain leaves the current active model in place until the new one succeeds.
 */
class InfluencerTrainingService {

    /**
     * Create the model row + training job for $asset_ids and dispatch it.
     * Returns ['ok', 'job_id', 'model_id', 'error'].
     */
    public static function start($creator_id, array $infl, array $asset_ids, $origin = 'wizard'){
        $creator_id = (int) $creator_id;
        $iid = (int) $infl['id'];
        $min = (int) InfluencerConfig::get('training_min_photos', 10);
        $max = (int) InfluencerConfig::get('training_max_photos', 50);
        $asset_ids = array_values(array_unique(array_map('intval', $asset_ids)));
        if (count($asset_ids) < $min) { return array('ok' => false, 'error' => 'Training needs at least ' . $min . ' images (you have ' . count($asset_ids) . ').'); }
        if (count($asset_ids) > $max) { return array('ok' => false, 'error' => 'Training accepts at most ' . $max . ' images.'); }
        if (!empty($infl['pending_model_id'])) { return array('ok' => false, 'error' => 'A training run is already in progress.'); }
        if (!InfluencerConfig::enabled()) { return array('ok' => false, 'error' => 'Image generation is not configured (fal_api_key).'); }

        $model = InfluencerConfig::resolve_model('training', '');
        if (!$model) { return array('ok' => false, 'error' => 'No training model is configured.'); }
        $steps   = (int) InfluencerConfig::get('training_steps', 1000);
        $trigger = self::trigger_word($iid);

        $models = new InfluencerModelsModel();
        $model_id = $models->create($iid, $creator_id, array(
            'provider' => (string) $model['provider'], 'model_key' => (string) $model['key'], 'trigger_word' => $trigger,
            'steps' => $steps, 'image_count' => count($asset_ids), 'training_set_group' => $infl['training_set_group'] ?? null,
            'params' => array('asset_ids' => $asset_ids),
        ));
        if ($model_id <= 0) { return array('ok' => false, 'error' => 'Could not create the model record.'); }

        $job_id = InfluencerJobService::create_job($creator_id, $iid, 'training', array(
            'origin' => $origin, 'model_key' => (string) $model['key'], 'prompt' => $trigger,
            'params' => array('asset_ids' => $asset_ids, 'steps' => $steps, 'trigger_word' => $trigger, 'model_id' => $model_id),
            'result_model_id' => $model_id,
        ), false);
        if ($job_id <= 0) { $models->mark_failed($model_id, 'Could not create the training job'); return array('ok' => false, 'error' => 'Could not create the training job.'); }
        $models->set_job($model_id, $job_id);

        (new InfluencersModel())->transition($iid, array(
            'pending_model_id' => $model_id, 'last_error' => null, 'wizard_step' => 'training',
        ));
        // Status becomes 'training' only when there is nothing active yet; a retrain keeps 'ready'.
        (new InfluencersModel())->transition($iid, array('status' => 'training'), 'active_model_id IS NULL');
        InfluencerJobService::dispatch($job_id, 0);
        return array('ok' => true, 'job_id' => $job_id, 'model_id' => $model_id, 'error' => '');
    }

    /** Trigger word attached to the weights: lowercase, unique-enough, easy to type. */
    public static function trigger_word($influencer_id){
        return 'infl_' . (int) $influencer_id . '_' . substr(bin2hex(random_bytes(3)), 0, 4);
    }

    /**
     * Provider request for a training job: build the ZIP of originals (once — the vault key is
     * cached in params so a provider fallback reuses it), presign it, and pass trigger + steps.
     */
    public static function build_request(array $job, array $model){
        $p   = InfluencerJobsModel::params($job);
        $cid = (int) $job['creator_id'];
        $iid = (int) $job['influencer_id'];
        $model_id = (int) ($p['model_id'] ?? $job['result_model_id']);
        $zip_key = (string) ($p['zip_key'] ?? '');
        if ($zip_key === '') {
            $zip_key = 'vault/' . $cid . '/influencers/' . $iid . '/models/' . $model_id . '/images.zip';
            $n = self::build_zip($cid, (array) ($p['asset_ids'] ?? array()), (string) ($p['trigger_word'] ?? ''), $zip_key);
            $p['zip_key'] = $zip_key;
            $p['zip_images'] = $n;
            (new InfluencerJobsModel())->transition($job['id'], null, array('params_json' => json_encode($p)));
        }
        $url = S3Service::presigned_get_url($zip_key, (int) InfluencerConfig::get('training_zip_url_ttl', 21600));
        if ($url === '') { throw new RuntimeException('Could not sign the training archive'); }
        return array(
            'endpoint'        => (string) $model['endpoint'],
            'params'          => (array) ($model['params'] ?? array()),
            'images_data_url' => $url,
            'trigger_word'    => (string) ($p['trigger_word'] ?? ''),
            'steps'           => (int) ($p['steps'] ?? InfluencerConfig::get('training_steps', 1000)),
        );
    }

    /**
     * Download each asset's original from S3, downscale anything larger than the configured
     * side with GD, and add it to a ZIP that is stored privately at $zip_key. Returns the
     * number of images packed; throws when the set is unusable.
     */
    private static function build_zip($creator_id, array $asset_ids, $trigger_word, $zip_key){
        if (!class_exists('ZipArchive')) { throw new RuntimeException('ZIP support is not available on this server'); }
        $mm = new MediaAssetsModel();
        $zip_path = tempnam(sys_get_temp_dir(), 'infltrain') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Could not create the training archive'); }
        $max_side  = (int) InfluencerConfig::get('training_image_max_side', 1536);
        $max_bytes = (int) InfluencerConfig::get('training_zip_max_bytes', 209715200);
        $total = 0; $n = 0; $tmp_files = array();
        foreach ($asset_ids as $i => $aid) {
            $a = $mm->get_one($creator_id, $aid);
            if (!$a || (string) $a['status'] !== 'ready' || (string) $a['type'] !== 'image') { continue; }
            $key = (string) ($a['original_key'] ?: $a['display_key']);
            if ($key === '') { continue; }
            $src = tempnam(sys_get_temp_dir(), 'inflimg');
            if (!S3Service::get_private_to_file($key, $src)) { @unlink($src); continue; }
            $out = self::normalize_image($src, $max_side);
            if ($out === '') { @unlink($src); continue; }
            $size = (int) filesize($out);
            if ($total + $size > $max_bytes) { @unlink($out); if ($out !== $src) { @unlink($src); } break; }
            $name = sprintf('img_%02d.jpg', $n + 1);
            $zip->addFile($out, $name);
            if ($trigger_word !== '') { $zip->addFromString(sprintf('img_%02d.txt', $n + 1), $trigger_word); }
            $tmp_files[] = $out; if ($out !== $src) { $tmp_files[] = $src; }
            $total += $size; $n++;
        }
        $zip->close();
        foreach ($tmp_files as $f) { @unlink($f); }
        if ($n < (int) InfluencerConfig::get('training_min_photos', 10)) {
            @unlink($zip_path);
            throw new RuntimeException('Only ' . $n . ' usable images could be packed for training');
        }
        if (!S3Service::put_private($zip_key, $zip_path, 'application/zip')) { @unlink($zip_path); throw new RuntimeException('Could not store the training archive'); }
        @unlink($zip_path);
        return $n;
    }

    /** Re-encode to JPEG (strips metadata), downscaling to $max_side. Returns a path ('' on failure). */
    private static function normalize_image($src, $max_side){
        $data = @file_get_contents($src);
        if ($data === false || $data === '') { return ''; }
        $img = @imagecreatefromstring($data);
        unset($data);
        if ($img === false) { return ''; }
        $w = imagesx($img); $h = imagesy($img);
        $scale = ($max_side > 0 && max($w, $h) > $max_side) ? $max_side / max($w, $h) : 1.0;
        if ($scale < 1.0) {
            $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img); $img = $dst;
        }
        $out = tempnam(sys_get_temp_dir(), 'inflnorm');
        $ok = imagejpeg($img, $out, 92);
        imagedestroy($img);
        if (!$ok) { @unlink($out); return ''; }
        return $out;
    }

    /**
     * Landing for a finished training job: stream the LoRA to disk, verify it is a safetensors
     * file, mirror it to the vault, mark the model ready, make it the active one and flip the
     * influencer to ready. Returns the model id; throws on any failure (the job then fails and
     * fail() keeps the previously active model untouched).
     */
    public static function land(array $job, array $lora){
        $p        = InfluencerJobsModel::params($job);
        $cid      = (int) $job['creator_id'];
        $iid      = (int) $job['influencer_id'];
        $model_id = (int) ($p['model_id'] ?? $job['result_model_id']);
        $models   = new InfluencerModelsModel();
        $row      = $models->get_by_id($model_id);
        if (!$row) { throw new RuntimeException('Model record ' . $model_id . ' is missing'); }
        if ((string) $row['status'] === 'ready') { return $model_id; }   // already landed (repeat callback)

        $url   = (string) $lora['url'];
        $class = InfluencerConfig::provider_class((string) $job['provider']);
        if ($class === '' || !$class::output_url_allowed($url)) { throw new RuntimeException('Weights URL is not from the provider'); }
        $tmp = tempnam(sys_get_temp_dir(), 'infllora');
        $max = (int) InfluencerConfig::get('training_lora_max_bytes', 536870912);
        $bytes = self::download_to_file($url, $tmp, $max);
        if ($bytes <= 0) { @unlink($tmp); throw new RuntimeException('Could not download the trained weights'); }
        if (!self::looks_like_safetensors($tmp)) { @unlink($tmp); throw new RuntimeException('The trained weights file is not a valid safetensors file'); }

        $key = 'vault/' . $cid . '/influencers/' . $iid . '/models/' . $model_id . '/lora.safetensors';
        if (!S3Service::put_private($key, $tmp, 'application/octet-stream')) { @unlink($tmp); throw new RuntimeException('Could not store the trained weights'); }
        @unlink($tmp);

        if ($models->mark_ready($model_id, $key, $url, $bytes) !== 1) { throw new RuntimeException('Model ' . $model_id . ' was not in training state'); }
        $models->activate($iid, $model_id);
        $infl = new InfluencersModel();
        $infl->transition($iid, array('status' => 'ready', 'active_model_id' => $model_id, 'pending_model_id' => null, 'last_error' => null, 'wizard_step' => 'done'),
            'pending_model_id = :m', array('m' => $model_id));
        if (!empty($p['zip_key'])) { S3Service::delete_key((string) $p['zip_key']); }
        return $model_id;
    }

    /** Training failed: keep the previous active model, record the provider error, clear the pending pointer. */
    public static function fail(array $job, $error){
        $p        = InfluencerJobsModel::params($job);
        $iid      = (int) $job['influencer_id'];
        $model_id = (int) ($p['model_id'] ?? $job['result_model_id']);
        (new InfluencerModelsModel())->mark_failed($model_id, $error);
        $infl = new InfluencersModel();
        // wizard_step leaves 'training' so the page stops showing progress and says what happened (the done step shows the error).
        $infl->transition($iid, array('pending_model_id' => null, 'last_error' => mb_substr((string) $error, 0, 2000), 'wizard_step' => 'done'),
            'pending_model_id = :m', array('m' => $model_id));
        $infl->transition($iid, array('status' => 'failed'), 'active_model_id IS NULL AND id = :i2', array('i2' => $iid));
        if (!empty($p['zip_key'])) { S3Service::delete_key((string) $p['zip_key']); }
    }

    private static function download_to_file($url, $dest, $max){
        $fh = @fopen($dest, 'wb');
        if ($fh === false) { return 0; }
        $got = 0; $over = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 900, CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => MediaIngestService::USER_AGENT,
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use ($fh, &$got, &$over, $max) {
                $got += strlen($chunk);
                if ($got > $max) { $over = true; return 0; }
                return fwrite($fh, $chunk);
            },
        ));
        curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        fclose($fh);
        if ($over || $code !== 200 || $got === 0) { return 0; }
        return $got;
    }

    /** safetensors = 8-byte little-endian header length followed by a JSON object. */
    private static function looks_like_safetensors($path){
        $size = (int) @filesize($path);
        $head = (string) @file_get_contents($path, false, null, 0, 8);
        if ($size < 16 || strlen($head) < 8) { return false; }
        $u = unpack('P', $head);
        $n = (int) ($u[1] ?? 0);
        if ($n <= 0 || $n > $size - 8 || $n > 100 * 1024 * 1024) { return false; }
        $json = (string) @file_get_contents($path, false, null, 8, min($n, 4096));
        return ltrim($json) !== '' && ltrim($json)[0] === '{';
    }
}
