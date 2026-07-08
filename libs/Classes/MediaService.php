<?php
/**
 * Content Studio media processing (GD only — this server has no ffmpeg/Imagick).
 *
 * Responsibilities:
 *  - Images: re-encode through GD (which strips EXIF/GPS), then produce a
 *    watermarked full-res `display`, a watermarked `thumb`, and a blurred/darkened
 *    `blurred` locked preview. The untouched original is stored separately and
 *    UNWATERMARKED so watermark settings can change later.
 *  - Videos: the browser captures a poster frame + duration/dimensions at upload
 *    (the server can't decode video). We turn that poster JPEG into `poster`,
 *    `thumb`, and `blurred`. Video bytes are never watermarked here — the player
 *    shows a visible name overlay instead.
 *  - Presigned delivery: every rendition is a PRIVATE S3 object reached only
 *    through a short-lived presigned URL, minted after an entitlement check.
 *
 * S3 key scheme: creator/{creator_id}/media/{asset_id}/{variant}.{ext}
 */
class MediaService {

    const MAX_DISPLAY = 2048;   // longest side of the delivered full-res image
    const MAX_THUMB   = 600;    // longest side of the grid thumbnail
    const MAX_BLUR    = 420;    // longest side of the locked preview

    /* ---- key helpers ---- */

    public static function key($creator_id, $asset_id, $variant, $ext): string
    {
        // NOTE: the 'vault/' prefix is deliberately OUTSIDE the bucket's public-read
        // policy (which covers 'creator/*' for profile images). Vault media is private
        // and reachable only through short-lived presigned URLs.
        return 'vault/' . (int) $creator_id . '/media/' . (int) $asset_id . '/' . $variant . '.' . $ext;
    }

    /* ---- entitlement + presigned delivery ---- */

    /**
     * Can $viewer_id receive the real (non-blurred) rendition of $asset?
     * The owner always can. For anyone else, "subscribers" gating would apply —
     * but in the Studio the viewer is always the owner, so this stays simple and
     * the same gate is ready for the future fan surface.
     */
    public static function entitled(array $asset, $viewer_id): bool
    {
        return (int) $asset['creator_id'] === (int) $viewer_id;
    }

    /**
     * Presigned URL for one variant of an asset, after an entitlement check. A
     * non-entitled viewer is silently downgraded to the blurred preview — the
     * clear-rendition key is never signed for them. Returns '' if unavailable.
     */
    public static function signed_url(array $asset, $variant, $viewer_id, $ttl = 600): string
    {
        $entitled = self::entitled($asset, $viewer_id);
        if (!$entitled) {
            $variant = 'blurred';   // the only rendition a non-entitled viewer ever gets
        }
        $col = self::variant_column($variant);
        $key = (string) ($asset[$col] ?? '');
        if ($key === '') {
            // Fall back to blurred, then thumb, so the grid still shows something.
            foreach (array('blurred_key', 'thumb_key', 'poster_key') as $fallback) {
                if (!empty($asset[$fallback])) { $key = (string) $asset[$fallback]; break; }
            }
        }
        return $key === '' ? '' : S3Service::presigned_get_url($key, $ttl);
    }

    /**
     * Presign a specific variant's key directly, WITHOUT an entitlement check.
     * The CALLER must have already decided the viewer is entitled to this variant
     * (e.g. the profile controller signs 'display' only for entitled fans, and
     * 'blurred' for everyone else). Returns '' if that key doesn't exist.
     */
    public static function signed_variant(array $asset, $variant, $ttl = 900): string
    {
        $key = (string) ($asset[self::variant_column($variant)] ?? '');
        return $key === '' ? '' : S3Service::presigned_get_url($key, $ttl);
    }

    private static function variant_column($variant): string
    {
        $map = array(
            'display' => 'display_key',
            'thumb'   => 'thumb_key',
            'blurred' => 'blurred_key',
            'poster'  => 'poster_key',
            'original'=> 'original_key',
        );
        return $map[$variant] ?? 'thumb_key';
    }

    /* ---- image pipeline ---- */

    /**
     * Process an uploaded image. Uploads the original (unwatermarked, private) and
     * three derived renditions. Returns an array of keys + metadata to persist on
     * the media_assets row, or array('error' => '...') on failure.
     *
     * $user is the creator's user_accounts row (for watermark settings).
     */
    public static function process_image($creator_id, $asset_id, $src_path, $orig_ext, $orig_mime, array $user, $watermark = true): array
    {
        $bytes = @filesize($src_path);
        $src   = @file_get_contents($src_path);
        if ($src === false || $src === '') {
            return array('error' => 'Could not read the uploaded image');
        }
        $img = @imagecreatefromstring($src);
        unset($src);
        if ($img === false) {
            return array('error' => 'That image could not be processed');
        }
        $w = imagesx($img);
        $h = imagesy($img);

        // 1) original, preserved unwatermarked, private.
        $original_key = self::key($creator_id, $asset_id, 'original', $orig_ext);
        if (!S3Service::put_private($original_key, $src_path, $orig_mime)) {
            imagedestroy($img);
            return array('error' => 'Storage is unavailable right now');
        }

        // 2) display (watermarked full-res), 3) thumb (watermarked), 4) blurred preview.
        $display = self::resized($img, self::MAX_DISPLAY);
        if ($watermark) { self::apply_watermark($display, $user); }
        $display_key = self::key($creator_id, $asset_id, 'display', 'jpg');
        $ok = S3Service::put_private_bytes($display_key, self::jpeg($display, 88), 'image/jpeg');
        imagedestroy($display);

        $thumb = self::resized($img, self::MAX_THUMB);
        if ($watermark) { self::apply_watermark($thumb, $user); }
        $thumb_key = self::key($creator_id, $asset_id, 'thumb', 'jpg');
        $ok = $ok && S3Service::put_private_bytes($thumb_key, self::jpeg($thumb, 82), 'image/jpeg');
        imagedestroy($thumb);

        $blur = self::blurred($img);
        $blurred_key = self::key($creator_id, $asset_id, 'blurred', 'jpg');
        $ok = $ok && S3Service::put_private_bytes($blurred_key, self::jpeg($blur, 60), 'image/jpeg');
        imagedestroy($blur);

        imagedestroy($img);

        if (!$ok) {
            return array('error' => 'Storage failed while processing the image');
        }

        // Moderate BEFORE the asset is marked ready, so nothing reaches viewers unscanned.
        // If the classifier is unavailable, leave it 'pending' — the cron worker is the
        // fallback and the viewer layer hides pending (unscanned) content until resolved.
        $moderation = array('status' => 'pending', 'score' => null, 'labels' => null);
        $mod = ModerationService::classify_image(S3Service::presigned_get_url($display_key, 600));
        if (!empty($mod['ok'])) {
            // 'blocked' (suspected minors) is a hard stop — quarantined from EVERYONE and
            // unpublishable; 'flagged' is adult (allowed, shown only to adult-on viewers).
            if (!empty($mod['minors'])) {
                $moderation['status'] = 'blocked';
                error_log('[MODERATION][BLOCKED] asset ' . $asset_id . ' creator ' . $creator_id . ' — suspected sexual/minors, quarantined.');
            } else {
                $moderation['status'] = !empty($mod['adult']) ? 'flagged' : 'approved';
            }
            $moderation['score']  = $mod['score'];
            $moderation['labels'] = empty($mod['labels']) ? null : implode(',', $mod['labels']);
        }

        return array(
            'original_key'      => $original_key,
            'display_key'       => $display_key,
            'thumb_key'         => $thumb_key,
            'blurred_key'       => $blurred_key,
            'width'             => $w,
            'height'            => $h,
            'bytes'             => $bytes !== false ? (int) $bytes : null,
            'watermark_applied' => $watermark ? 1 : 0,
            'moderation_status' => $moderation['status'],
            'moderation_score'  => $moderation['score'],
            'moderation_labels' => $moderation['labels'],
        );
    }

    /* ---- video pipeline (ffmpeg) ---- */

    /** Absolute path to ffmpeg/ffprobe, or '' if not installed. */
    private static function bin($name): string
    {
        static $cache = array();
        if (array_key_exists($name, $cache)) { return $cache[$name]; }
        foreach (array('/opt/homebrew/bin/', '/usr/local/bin/', '/usr/bin/', '') as $dir) {
            $path = $dir . $name;
            if ($dir === '') {
                $which = @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null');
                $path  = is_string($which) ? trim($which) : '';
            }
            if ($path !== '' && @is_executable($path)) { $cache[$name] = $path; return $path; }
        }
        $cache[$name] = '';
        return '';
    }

    public static function ffmpeg_available(): bool
    {
        return self::bin('ffmpeg') !== '' && self::bin('ffprobe') !== '';
    }

    /**
     * Read duration (seconds) + dimensions from a video via ffprobe. Accepts a
     * local path or an http(s) URL (ffprobe range-reads the container header, so a
     * signed S3 URL does not download the whole file). Returns
     * ['duration'=>int,'width'=>int,'height'=>int]; zeros if unavailable.
     */
    public static function probe_video($src): array
    {
        $out = array('duration' => 0, 'width' => 0, 'height' => 0);
        $ffprobe = self::bin('ffprobe');
        if ($ffprobe === '' || (string) $src === '') { return $out; }
        $cmd = escapeshellarg($ffprobe)
             . ' -v error -select_streams v:0'
             . ' -show_entries format=duration:stream=width,height'
             . ' -of json ' . escapeshellarg($src) . ' 2>/dev/null';
        $json = @shell_exec($cmd);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (is_array($data)) {
            $out['duration'] = (int) round((float) ($data['format']['duration'] ?? 0));
            if (!empty($data['streams'][0])) {
                $out['width']  = (int) ($data['streams'][0]['width'] ?? 0);
                $out['height'] = (int) ($data['streams'][0]['height'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Extract one poster frame from a video (~1s in) to a temp JPEG. Accepts a
     * local path or signed URL. Returns the temp file path, or '' on failure.
     */
    private static function extract_poster_frame($src): string
    {
        $ffmpeg = self::bin('ffmpeg');
        if ($ffmpeg === '' || (string) $src === '') { return ''; }
        $dest = tempnam(sys_get_temp_dir(), 'poster') . '.jpg';
        // -ss before -i = fast input seek (only fetches the needed bytes over http).
        $cmd = escapeshellarg($ffmpeg)
             . ' -y -ss 1 -i ' . escapeshellarg($src)
             . ' -frames:v 1 -q:v 3 ' . escapeshellarg($dest) . ' 2>/dev/null';
        @shell_exec($cmd);
        if (is_file($dest) && filesize($dest) > 0) { return $dest; }
        // Fallback: some very short clips have no frame at 1s — grab the first frame.
        $cmd = escapeshellarg($ffmpeg)
             . ' -y -i ' . escapeshellarg($src)
             . ' -frames:v 1 -q:v 3 ' . escapeshellarg($dest) . ' 2>/dev/null';
        @shell_exec($cmd);
        return (is_file($dest) && filesize($dest) > 0) ? $dest : '';
    }

    /**
     * Process a completed video upload: extract a poster frame server-side with
     * ffmpeg, then derive poster/thumb/blurred (GD). The original video is already
     * stored at its key and is not touched. $src is a local path or signed URL.
     * Returns keys to persist, or array('error' => '...').
     */
    public static function process_video($creator_id, $asset_id, $src, array $user): array
    {
        $poster_path = self::extract_poster_frame($src);
        if ($poster_path === '') {
            return array('error' => 'Could not read the video to build a preview. Please try again.');
        }
        $res = self::poster_variants($creator_id, $asset_id, $poster_path, $user);
        @unlink($poster_path);
        return $res;
    }

    /**
     * Turn a poster-frame JPEG into poster/thumb/blurred renditions. Returns keys
     * to persist, or array('error' => '...').
     */
    private static function poster_variants($creator_id, $asset_id, $poster_src_path, array $user): array
    {
        $src = @file_get_contents($poster_src_path);
        if ($src === false || $src === '') {
            return array('error' => 'Could not read the video preview frame');
        }
        $img = @imagecreatefromstring($src);
        unset($src);
        if ($img === false) {
            return array('error' => 'The video preview frame could not be processed');
        }

        $poster = self::resized($img, self::MAX_DISPLAY);
        // A visible name overlay is baked into the poster so the still frame is
        // protected too; the live player adds a matching CSS overlay.
        self::apply_watermark($poster, $user);
        $poster_key = self::key($creator_id, $asset_id, 'poster', 'jpg');
        $ok = S3Service::put_private_bytes($poster_key, self::jpeg($poster, 86), 'image/jpeg');
        imagedestroy($poster);

        $thumb = self::resized($img, self::MAX_THUMB);
        self::apply_watermark($thumb, $user);
        $thumb_key = self::key($creator_id, $asset_id, 'thumb', 'jpg');
        $ok = $ok && S3Service::put_private_bytes($thumb_key, self::jpeg($thumb, 82), 'image/jpeg');
        imagedestroy($thumb);

        $blur = self::blurred($img);
        $blurred_key = self::key($creator_id, $asset_id, 'blurred', 'jpg');
        $ok = $ok && S3Service::put_private_bytes($blurred_key, self::jpeg($blur, 60), 'image/jpeg');
        imagedestroy($blur);

        imagedestroy($img);

        if (!$ok) {
            return array('error' => 'Storage failed while processing the video preview');
        }
        return array(
            'poster_key'        => $poster_key,
            'thumb_key'         => $thumb_key,
            'blurred_key'       => $blurred_key,
            'watermark_applied' => 0,   // the video file itself is not watermarked
        );
    }

    /* ---- GD primitives ---- */

    /** Return a new GD image scaled so its longest side is <= $max (never upscaled). */
    private static function resized($img, $max)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        // Flatten any alpha onto white for JPEG delivery.
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefilledrectangle($out, 0, 0, $nw, $nh, $white);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $out;
    }

    /**
     * Locked-preview image: destroy all recognizable detail. Downscale to a tiny
     * size (so features are gone), blur, darken, then upscale that smoothly and
     * blur again — the result reads as soft color blocks, not the photo.
     */
    private static function blurred($img)
    {
        // 1) Crush to ~24px longest side — this alone removes recognizable detail.
        $tiny = self::resized($img, 24);
        for ($i = 0; $i < 4; $i++) { imagefilter($tiny, IMG_FILTER_GAUSSIAN_BLUR); }

        // 2) Upscale the smear back to delivery size (smoothly), then blur again.
        $tw = imagesx($tiny); $th = imagesy($tiny);
        $ow = self::MAX_BLUR;
        $oh = max(1, (int) round($ow * $th / $tw));
        $out = imagecreatetruecolor($ow, $oh);
        imagecopyresampled($out, $tiny, 0, 0, 0, 0, $ow, $oh, $tw, $th);
        imagedestroy($tiny);
        for ($i = 0; $i < 10; $i++) { imagefilter($out, IMG_FILTER_GAUSSIAN_BLUR); }
        imagefilter($out, IMG_FILTER_BRIGHTNESS, -30);
        return $out;
    }

    /** Encode a GD image to JPEG bytes. */
    private static function jpeg($img, $quality): string
    {
        ob_start();
        imagejpeg($img, null, (int) $quality);
        return (string) ob_get_clean();
    }

    /* ---- watermark ---- */

    /**
     * Bake the creator's watermark onto a GD image using the account settings
     * (text, position, opacity from user_accounts). Uses a bundled/system TTF if
     * one is found, else falls back to GD's built-in font so it always renders.
     */
    private static function apply_watermark($img, array $user): void
    {
        $text = trim((string) ($user['watermark_text'] ?? ''));
        if ($text === '') {
            $text = '@' . (string) ($user['u_name'] ?? ($user['first_name'] ?? 'creator'));
        }
        $position = (string) ($user['watermark_position'] ?? 'bottom_right');
        $opacity  = (int) ($user['watermark_opacity'] ?? 40);
        $opacity  = max(5, min(100, $opacity));
        $alpha    = 127 - (int) round($opacity / 100 * 127);   // GD alpha: 0 opaque .. 127 transparent

        $w = imagesx($img);
        $h = imagesy($img);
        $font = self::find_font();

        if ($position === 'tiled') {
            self::watermark_tiled($img, $text, $alpha, $font);
            return;
        }

        // Size the text to roughly the image width; margin from the edges.
        $fontsize = max(11, (int) round($w / 26));
        $margin   = max(8, (int) round($w / 60));
        list($tw, $th) = self::text_box($text, $fontsize, $font);

        switch ($position) {
            case 'bottom_left': $x = $margin;              $y = $h - $margin - $th; break;
            case 'top_right':   $x = $w - $margin - $tw;   $y = $margin;            break;
            case 'top_left':    $x = $margin;              $y = $margin;            break;
            case 'bottom_right':
            default:            $x = $w - $margin - $tw;   $y = $h - $margin - $th; break;
        }
        self::draw_text($img, $text, $x, $y, $fontsize, $alpha, $font);
    }

    private static function watermark_tiled($img, $text, $alpha, $font): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $fontsize = max(10, (int) round($w / 34));
        list($tw, $th) = self::text_box($text, $fontsize, $font);
        $stepX = $tw + max(60, (int) round($w / 5));
        $stepY = $th + max(60, (int) round($h / 6));
        for ($y = $th; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                self::draw_text($img, $text, $x, $y, $fontsize, min(127, $alpha + 20), $font);
            }
        }
    }

    /** Draw text with a subtle shadow for legibility. TTF if available, else built-in. */
    private static function draw_text($img, $text, $x, $y, $fontsize, $alpha, $font): void
    {
        $shadowA = min(127, $alpha + 30);
        if ($font !== '') {
            $white  = imagecolorallocatealpha($img, 255, 255, 255, $alpha);
            $shadow = imagecolorallocatealpha($img, 0, 0, 0, $shadowA);
            // imagettftext y is the baseline; add the ascent (~fontsize).
            $by = $y + $fontsize;
            imagettftext($img, $fontsize, 0, $x + 1, $by + 1, $shadow, $font, $text);
            imagettftext($img, $fontsize, 0, $x, $by, $white, $font, $text);
        } else {
            // Built-in font 5 is 15px tall; scale the position box accordingly.
            $white  = imagecolorallocatealpha($img, 255, 255, 255, $alpha);
            $shadow = imagecolorallocatealpha($img, 0, 0, 0, $shadowA);
            imagestring($img, 5, $x + 1, $y + 1, $text, $shadow);
            imagestring($img, 5, $x, $y, $text, $white);
        }
    }

    /** Approximate rendered [width, height] of the text at $fontsize. */
    private static function text_box($text, $fontsize, $font): array
    {
        if ($font !== '') {
            $bb = @imagettfbbox($fontsize, 0, $font, $text);
            if (is_array($bb)) {
                $tw = abs($bb[2] - $bb[0]);
                $th = abs($bb[7] - $bb[1]);
                return array($tw, $th);
            }
        }
        // Built-in font 5 ≈ 9px wide, 15px tall per char.
        return array(strlen($text) * 9, 15);
    }

    /** Locate a usable TTF across dev (macOS) and typical Linux hosts; '' if none. */
    private static function find_font(): string
    {
        static $cached = null;
        if ($cached !== null) { return $cached; }
        $candidates = array(
            Main::app_path() . '/public/fonts/wm.ttf',              // bundled (preferred if present)
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/Library/Fonts/Arial Unicode.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
        );
        foreach ($candidates as $f) {
            if (is_file($f)) { $cached = $f; return $cached; }
        }
        $cached = '';
        return $cached;
    }

}
