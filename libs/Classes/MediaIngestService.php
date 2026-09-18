<?php
/**
 * Shared "bytes/URL -> media library asset" path. Lifted verbatim from the MCP tools
 * (upload_image_from_url / upload_video_from_url / generate_image) so every server-side
 * ingest — MCP, influencer generations — runs the same SSRF guard, the same magic-byte
 * type verification and the same MediaService pipeline. Throws RuntimeException /
 * InvalidArgumentException with user-safe messages, exactly as the MCP helpers did.
 */
class MediaIngestService {

    const MAX_IMAGE_BYTES = 20971520;      // 20 MB in-memory image fetch
    const MAX_VIDEO_BYTES = 1073741824;    // 1 GB streamed video fetch
    const USER_AGENT      = 'CreatorLinkStudio/1.0 (+https://creatorlinkstudio.com)';

    /**
     * Validate a public http(s) URL and pin its host to a resolved, non-private address
     * (SSRF guard). Returns ['url', 'resolve' => curl CURLOPT_RESOLVE entry].
     */
    public static function safe_url($url, $what = 'URL'){
        $url = trim((string) $url);
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), array('http', 'https'), true)) {
            throw new InvalidArgumentException('Provide a valid http(s) ' . $what);
        }
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : (array) @gethostbynamel($host);
        if (empty($ips)) { throw new RuntimeException('Could not resolve that host'); }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('That URL resolves to a private/blocked address');
            }
        }
        $port = isset($p['port']) ? (int) $p['port'] : (strtolower($p['scheme']) === 'https' ? 443 : 80);
        return array('url' => $url, 'resolve' => $host . ':' . $port . ':' . $ips[0]);
    }

    /** Fetch a PUBLIC image URL with SSRF protection; validate it is a real image. ['bytes','ext','mime','width','height']. */
    public static function fetch_image($url, $max = self::MAX_IMAGE_BYTES, $timeout = 20){
        $u = self::safe_url($url, 'image URL');
        $url = $u['url'];
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => (int) $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_RESOLVE => array($u['resolve']),
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$buf, $max) { $buf .= $chunk; return (strlen($buf) > $max) ? 0 : strlen($chunk); },
        ));
        $okc = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if (($okc === false && $buf === '') || $code >= 400 || $buf === '') { throw new RuntimeException('Could not fetch that image URL'); }
        return self::verify_image_bytes($buf);
    }

    /** Sniff image bytes; throws unless JPG/PNG/WebP/GIF. */
    public static function verify_image_bytes($buf){
        $info = @getimagesizefromstring($buf);
        $map = array(IMAGETYPE_JPEG => array('jpg', 'image/jpeg'), IMAGETYPE_PNG => array('png', 'image/png'),
                     IMAGETYPE_WEBP => array('webp', 'image/webp'), IMAGETYPE_GIF => array('gif', 'image/gif'));
        if (!$info || !isset($map[$info[2]])) { throw new RuntimeException('That URL is not a supported image (JPG/PNG/WebP/GIF)'); }
        return array('bytes' => $buf, 'ext' => $map[$info[2]][0], 'mime' => $map[$info[2]][1], 'width' => (int) $info[0], 'height' => (int) $info[1]);
    }

    /**
     * Stream a PUBLIC video URL to a temp file (never into memory), capped at $max bytes.
     * Returns ['path', 'bytes', 'ext', 'mime'] — type is sniffed from the file's magic
     * bytes, not the URL, so a mislabeled link can't smuggle another format in.
     */
    public static function fetch_video($url, $max = self::MAX_VIDEO_BYTES, $timeout = 540){
        $u  = self::safe_url($url, 'video URL');
        $tmp = tempnam(sys_get_temp_dir(), 'ingvid');
        $fh  = @fopen($tmp, 'wb');
        if ($tmp === false || $fh === false) { throw new RuntimeException('Could not buffer the video'); }
        $got = 0; $over = false;
        $ch = curl_init($u['url']);
        curl_setopt_array($ch, array(
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => (int) $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_RESOLVE => array($u['resolve']),
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use ($fh, &$got, &$over, $max) {
                $got += strlen($chunk);
                if ($got > $max) { $over = true; return 0; }
                return fwrite($fh, $chunk);
            },
        ));
        $okc = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        fclose($fh);
        if ($over) { @unlink($tmp); throw new RuntimeException('That video is larger than the ' . (int) ($max / 1073741824) . ' GB limit'); }
        if (($okc === false && $got === 0) || $code >= 400 || $got === 0) { @unlink($tmp); throw new RuntimeException('Could not fetch that video URL'); }
        $t = self::verify_video_file($tmp);
        if ($t === null) { @unlink($tmp); throw new RuntimeException('That URL is not a supported video (MP4/MOV/WebM)'); }
        return array('path' => $tmp, 'bytes' => $got, 'ext' => $t['ext'], 'mime' => $t['mime']);
    }

    /** Sniff a video file's magic bytes: ['ext','mime'] or null when it is not MP4/MOV/WebM. */
    public static function verify_video_file($path){
        $head = (string) @file_get_contents($path, false, null, 0, 64);
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            if (strpos($brand, 'qt') === 0) { return array('ext' => 'mov', 'mime' => 'video/quicktime'); }
            return array('ext' => 'mp4', 'mime' => 'video/mp4');
        }
        if (strlen($head) >= 4 && substr($head, 0, 4) === "\x1A\x45\xDF\xA3") { return array('ext' => 'webm', 'mime' => 'video/webm'); }
        return null;
    }

    /**
     * Write image bytes into the media library via the normal pipeline. When $asset_id is
     * given the existing 'processing' placeholder row is used instead of creating one.
     * Returns ['asset_id', 'type' => 'image'].
     */
    public static function ingest_image($cid, array $user, $bytes, $ext, $mime, $label, $watermark = null, $asset_id = 0){
        $tmp = tempnam(sys_get_temp_dir(), 'ingimg');
        if ($tmp === false || file_put_contents($tmp, $bytes) === false) { throw new RuntimeException('Could not buffer the image'); }
        $mm  = new MediaAssetsModel();
        $aid = (int) $asset_id;
        if ($aid <= 0) { $aid = (int) $mm->add($cid, 'image', mb_substr($label, 0, 60) . '.' . $ext, $mime, 'processing'); }
        if ($aid <= 0) { @unlink($tmp); throw new RuntimeException('Could not create the media asset'); }
        if ($watermark === null) { $watermark = !empty($user['watermark_enabled']); }
        $r = MediaService::process_image($cid, $aid, $tmp, $ext, $mime, $user, (bool) $watermark);
        @unlink($tmp);
        if (isset($r['error'])) { $mm->set_failed($cid, $aid, $r['error']); throw new RuntimeException($r['error']); }
        $mm->set_ready($cid, $aid, $r);
        return array('asset_id' => $aid, 'type' => 'image');
    }

    /**
     * Store an already-downloaded video file as a library asset: original to S3, poster/thumb
     * via ffmpeg (else $poster_path), duration/dimensions via ffprobe, mark ready. Deletes
     * $path when done. When $asset_id is given the existing placeholder row is used.
     */
    public static function ingest_video_file($cid, array $user, $path, $ext, $mime, $bytes, $label, $poster_path = '', $asset_id = 0, $check_quota = true){
        if ($check_quota) {
            $gb = Plan::limit($user, 'storage_gb');
            if ($gb !== null && (int) $gb > 0) {
                $used = (int) (new MediaAssetsModel())->total_bytes($cid);
                if ($used + (int) $bytes > (int) $gb * 1073741824) {
                    @unlink($path); if ($poster_path !== '') { @unlink($poster_path); }
                    throw new RuntimeException('Not enough storage left on your plan for this video');
                }
            }
        }
        $mm  = new MediaAssetsModel();
        $aid = (int) $asset_id;
        if ($aid <= 0) { $aid = (int) $mm->add($cid, 'video', mb_substr($label, 0, 60) . '.' . $ext, $mime, 'processing'); }
        if ($aid <= 0) { @unlink($path); if ($poster_path !== '') { @unlink($poster_path); } throw new RuntimeException('Could not create the media asset'); }

        $key = MediaService::key($cid, $aid, 'original', $ext);
        if (!S3Service::put_private($key, $path, $mime)) {
            @unlink($path); if ($poster_path !== '') { @unlink($poster_path); }
            $mm->set_failed($cid, $aid, 'Storage failed');
            throw new RuntimeException('Could not store the video');
        }
        $probe = MediaService::probe_video($path);
        $res   = MediaService::process_video($cid, $aid, $path, $user, $poster_path);
        @unlink($path); if ($poster_path !== '') { @unlink($poster_path); }
        if (isset($res['error'])) {
            $mm->set_failed($cid, $aid, $res['error']);
            throw new RuntimeException($res['error']);
        }
        $mm->set_ready($cid, $aid, array_merge($res, array(
            'original_key' => $key,
            'bytes'        => (int) $bytes,
            'duration_sec' => (int) ($probe['duration'] ?? 0),
            'width'        => (int) ($probe['width'] ?? 0),
            'height'       => (int) ($probe['height'] ?? 0),
        )));
        return array('asset_id' => $aid, 'type' => 'video', 'bytes' => (int) $bytes, 'duration_sec' => (int) ($probe['duration'] ?? 0));
    }

    /** Video from a URL (MCP path): poster first, then the download, then ingest_video_file(). */
    public static function ingest_video_from_url($cid, array $user, $url, $poster_url, $label){
        $poster_tmp = '';
        if (trim((string) $poster_url) !== '') {
            $img = self::fetch_image($poster_url);
            $poster_tmp = tempnam(sys_get_temp_dir(), 'ingposter');
            file_put_contents($poster_tmp, $img['bytes']);
        }
        try {
            $vid = self::fetch_video($url, self::MAX_VIDEO_BYTES);
        } catch (\Throwable $e) {
            if ($poster_tmp !== '') { @unlink($poster_tmp); }
            throw $e;
        }
        try {
            return self::ingest_video_file($cid, $user, $vid['path'], $vid['ext'], $vid['mime'], $vid['bytes'], $label, $poster_tmp);
        } catch (RuntimeException $e) {
            $m = $e->getMessage();
            if (strpos($m, 'preview') !== false && trim((string) $poster_url) === '') { $m .= ' Pass poster_url with a thumbnail image of the video.'; }
            throw new RuntimeException($m);
        }
    }
}
