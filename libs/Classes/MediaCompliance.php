<?php
/**
 * Platform image rules for cross-posts, checked as soon as media or destinations change (the composer, schedules and
 * automations), not at Publish. For each destination (social account + placement) every image that would be sent is
 * classed:
 *
 *   compatible   sent unchanged
 *   auto         a safe technical change makes it fit (downscale, re-encode, smaller file): made without asking
 *   approval     the picture itself has to change (its shape: fitted on a blurred fill, or cropped): made in the
 *                background and sent only once the creator approves it
 *   unsupported  the platform cannot take it (an image to YouTube)
 *
 * Prepared versions ("variants") are media rows (source = 'variant', hidden from the Library) linked to their original
 * through source_asset_id. Their tags carry the cache key and state: "v:<key>|s:<auto|same|pending|approved>|m:<fit|crop>|f:<focus>".
 * The key holds the platform, placement, teaser flag and RULES_VERSION, so a rules change starts fresh variants.
 * Publishing re-checks every image (resolve()) and never sends an unapproved or unfitting one.
 */
class MediaCompliance {

    /** Bump when RULES change: every cached variant key changes with it. */
    const RULES_VERSION = 1;

    /**
     * Image rules per platform (Post for Me keys) and placement, from each platform's publishing docs (checked 2026-10-14).
     * formats: what it takes; max_bytes: file size cap; ratio: [narrowest, widest] width / height, or 'exact' for one shape;
     * max_side: longest side it keeps (larger is downscaled); images false: no image posts.
     */
    const RULES = array(
        'instagram' => array(
            'feed'  => array('formats' => array('jpeg'), 'max_bytes' => 8000000, 'ratio' => array(0.8, 1.91), 'max_side' => 1440),
            'story' => array('formats' => array('jpeg'), 'max_bytes' => 8000000, 'exact' => array(9, 16), 'max_side' => 1920),
        ),
        'facebook' => array(
            'feed'  => array('formats' => array('jpeg', 'png'), 'max_bytes' => 10000000, 'max_side' => 2048),
            'story' => array('formats' => array('jpeg', 'png'), 'max_bytes' => 10000000, 'exact' => array(9, 16), 'max_side' => 1920),
        ),
        'threads'   => array('feed' => array('formats' => array('jpeg', 'png'), 'max_bytes' => 8000000, 'ratio' => array(0.1, 10), 'max_side' => 1440)),
        'x'         => array('feed' => array('formats' => array('jpeg', 'png', 'webp'), 'max_bytes' => 5000000, 'max_side' => 4096)),
        'bluesky'   => array('feed' => array('formats' => array('jpeg', 'png', 'webp'), 'max_bytes' => 976000, 'max_side' => 2000)),
        'linkedin'  => array('feed' => array('formats' => array('jpeg', 'png'), 'max_bytes' => 8000000, 'ratio' => array(0.42, 2.4), 'max_side' => 4096)),
        'pinterest' => array('feed' => array('formats' => array('jpeg', 'png', 'webp'), 'max_bytes' => 20000000, 'max_side' => 4096)),
        'tiktok'    => array('feed' => array('formats' => array('jpeg', 'webp'), 'max_bytes' => 20000000, 'max_side' => 1920)),
        'tiktok_business' => array('feed' => array('formats' => array('jpeg', 'webp'), 'max_bytes' => 20000000, 'max_side' => 1920)),
        'youtube'   => array('feed' => array('images' => false)),
    );

    /** Platform names as the creator reads them. */
    const NAMES = array('instagram' => 'Instagram', 'facebook' => 'Facebook', 'threads' => 'Threads', 'x' => 'X', 'bluesky' => 'Bluesky',
        'linkedin' => 'LinkedIn', 'pinterest' => 'Pinterest', 'tiktok' => 'TikTok', 'tiktok_business' => 'TikTok', 'youtube' => 'YouTube');

    /** The rules for a platform and placement; a platform with no entry takes anything. */
    public static function rules($platform, $placement = 'feed'): array {
        $p = self::RULES[strtolower((string) $platform)] ?? array();
        return (array) ($p[(string) $placement] ?? ($p['feed'] ?? array()));
    }

    public static function name($platform): string {
        $p = strtolower((string) $platform);
        return self::NAMES[$p] ?? ucfirst($p);
    }

    /** Cache key of a prepared version: platform, placement, teaser flag and rules version. */
    public static function key($platform, $placement, $teaser): string {
        return strtolower((string) $platform) . '.' . ((string) $placement === 'story' ? 'story' : 'feed') . '.' . (!empty($teaser) ? 't' : 'o') . '.r' . self::RULES_VERSION;
    }

    /** The file of $asset that a cross-post sends: the delivered rendition, or the blurred teaser. */
    public static function sent_key(array $asset, $teaser): string {
        if (!empty($teaser)) { return (string) ($asset['blurred_key'] ?? ''); }
        if ((string) ($asset['type'] ?? 'image') === 'video') { return (string) ($asset['original_key'] ?? ''); }
        return (string) (($asset['display_key'] ?? '') ?: ($asset['original_key'] ?? ''));
    }

    /**
     * What is known of the sent file without downloading it: its shape follows the original (the delivered rendition is
     * the original fitted inside MediaService::MAX_DISPLAY, a JPEG). Bytes are an estimate (null when unknown).
     */
    public static function props_estimate(array $asset): array {
        $w = (int) ($asset['width'] ?? 0); $h = (int) ($asset['height'] ?? 0);
        if ((string) ($asset['type'] ?? 'image') === 'video') { return array('w' => $w, 'h' => $h, 'format' => 'mp4', 'bytes' => null, 'measured' => false); }
        if ($w > 0 && $h > 0) {
            $s = min(1, MediaService::MAX_DISPLAY / max($w, $h));
            $w = (int) round($w * $s); $h = (int) round($h * $s);
        }
        return array('w' => $w, 'h' => $h, 'format' => 'jpeg', 'bytes' => ($w > 0 && $h > 0) ? (int) round($w * $h * 0.42) : null, 'measured' => false);
    }

    /** The sent file's exact properties: dimensions, format and size, read from the file. */
    public static function props_file($path): array {
        $info = @getimagesize((string) $path);
        $fmt = '';
        if ($info) { $fmt = strtolower(str_replace(array('image/', 'jpg'), array('', 'jpeg'), (string) $info['mime'])); }
        return array('w' => (int) ($info[0] ?? 0), 'h' => (int) ($info[1] ?? 0), 'format' => $fmt, 'bytes' => (int) @filesize((string) $path), 'measured' => true);
    }

    /**
     * Class one image against one destination's rules. Returns ['status', 'reasons' => [plain words], 'frame' => [w, h] when
     * the shape must change, 'ops' => the technical changes an auto version makes].
     */
    public static function classify(array $props, array $rules, $is_video = false): array {
        if (array_key_exists('images', $rules) && !$rules['images']) {
            return array('status' => 'unsupported', 'reasons' => array('Takes videos only, not images.'), 'frame' => null, 'ops' => array());
        }
        $w = (int) $props['w']; $h = (int) $props['h'];
        if ($is_video) {   // a video is only reshaped for a fixed-shape placement (a Story); everything else is sent as it is
            if (empty($rules['exact']) || $w <= 0 || $h <= 0) { return array('status' => 'compatible', 'reasons' => array(), 'frame' => null, 'ops' => array()); }
            list($ew, $eh) = $rules['exact'];
            if (abs($w / $h - $ew / $eh) <= 0.02) { return array('status' => 'compatible', 'reasons' => array(), 'frame' => null, 'ops' => array()); }
            return array('status' => 'approval', 'reasons' => array('Needs the ' . $ew . ':' . $eh . ' shape.'), 'frame' => array(1080, (int) round(1080 * $eh / $ew)), 'ops' => array());
        }
        if ($w <= 0 || $h <= 0) { return array('status' => 'auto', 'reasons' => array('Size not known yet.'), 'frame' => null, 'ops' => array('measure')); }
        $r = $w / $h; $reasons = array(); $ops = array(); $frame = null;
        if (!empty($rules['exact'])) {
            list($ew, $eh) = $rules['exact'];
            if (abs($r - $ew / $eh) > 0.02) { $frame = array(1080, (int) round(1080 * $eh / $ew)); $reasons[] = 'Needs the ' . $ew . ':' . $eh . ' shape.'; }
        } elseif (!empty($rules['ratio'])) {
            list($min, $max) = $rules['ratio'];
            if ($r < $min - 0.005) { $frame = self::frame_for($min); $reasons[] = 'Too tall: the narrowest shape it takes is ' . self::ratio_label($min) . '.'; }
            elseif ($r > $max + 0.005) { $frame = self::frame_for($max); $reasons[] = 'Too wide: the widest shape it takes is ' . self::ratio_label($max) . '.'; }
        }
        if (!empty($rules['formats']) && (string) $props['format'] !== '' && !in_array((string) $props['format'], (array) $rules['formats'], true)) { $ops[] = 'convert'; }
        if (!empty($rules['max_side']) && max($w, $h) > (int) $rules['max_side']) { $ops[] = 'downscale'; }
        if (!empty($rules['max_bytes']) && $props['bytes'] !== null && (int) $props['bytes'] > (int) $rules['max_bytes'] * ($props['measured'] ? 1 : 0.9)) { $ops[] = 'compress'; }
        if ($frame) { return array('status' => 'approval', 'reasons' => $reasons, 'frame' => $frame, 'ops' => $ops); }
        if ($ops) { return array('status' => 'auto', 'reasons' => array(), 'frame' => null, 'ops' => $ops); }
        return array('status' => 'compatible', 'reasons' => array(), 'frame' => null, 'ops' => array());
    }

    /** A frame 1080 wide (1080 tall for wide shapes) at width / height = $ratio. */
    private static function frame_for($ratio): array {
        $ratio = (float) $ratio;
        return $ratio >= 1 ? array((int) round(1080 * $ratio), 1080) : array(1080, (int) round(1080 / $ratio));
    }

    /** 0.8 -> "4:5", 1.91 -> "1.91:1" */
    private static function ratio_label($r): string {
        foreach (array('4:5' => 0.8, '9:16' => 0.5625, '1:1' => 1.0, '16:9' => 1.7778) as $l => $v) { if (abs($r - $v) < 0.01) { return $l; } }
        return rtrim(rtrim(number_format((float) $r, 2), '0'), '.') . ':1';
    }

    /* ---------------------------------------------------------------------------------------------------------------
     * Check (composer, automations): class every image for every destination and start the versions that are missing
     * ------------------------------------------------------------------------------------------------------------- */

    /**
     * $items: [['asset' => media row, 'teaser' => bool, 'video' => bool], ...] as a cross-post sends them (cover first);
     * $dests: [['id', 'platform', 'placement' => feed|story], ...]. Starts background versions for anything that needs one.
     * Returns ['destinations' => [...], 'busy' => bool (versions still being made), 'review' => count waiting for approval].
     */
    public static function check($cid, array $items, array $dests, $start = true): array {
        $mm = new MediaAssetsModel();
        $out = array(); $busy = false; $review = 0;
        foreach ($dests as $d) {
            $platform = strtolower((string) $d['platform']); $placement = ((string) ($d['placement'] ?? 'feed') === 'story') ? 'story' : 'feed';
            $rules = self::rules($platform, $placement);
            $list = $placement === 'story' ? array_slice($items, 0, 1) : SocialShareService::media_for($platform, $items);
            $rows = array(); $worst = 'compatible';
            foreach ($list as $it) {
                $a = (array) $it['asset'];
                $c = self::classify(self::props_estimate($a), $rules, !empty($it['video']));
                $row = array('asset_id' => (int) $a['id'], 'teaser' => !empty($it['teaser']), 'video' => !empty($it['video']), 'status' => $c['status'], 'reasons' => $c['reasons'], 'variant' => null);
                if (in_array($c['status'], array('auto', 'approval'), true)) {
                    $key = self::key($platform, $placement, !empty($it['teaser']));
                    $v = $mm->variant_for((int) $cid, (int) $a['id'], $key);
                    if ($v) {
                        $st = self::variant_state($v);
                        if ($st === 'same') { $row['status'] = 'compatible'; }   // the background check found nothing to change
                        else { $row['variant'] = self::variant_json((int) $cid, $v); }
                    } else {
                        $row['variant'] = array('id' => 0, 'state' => 'processing');
                        if ($start) { self::start_variant((int) $cid, (int) $a['id'], !empty($it['teaser']), $platform, $placement, 'fit', 'center'); }
                    }
                    if ($row['variant'] && $row['variant']['state'] === 'processing') { $busy = true; }
                    if ($row['status'] === 'approval' && (!$row['variant'] || $row['variant']['state'] !== 'approved')) { $review++; }
                }
                $rows[] = $row;
                $worst = self::worse($worst, self::row_state($row));
            }
            $out[] = array('account_id' => (string) $d['id'], 'platform' => $platform, 'name' => self::name($platform), 'placement' => $placement,
                'status' => $worst, 'items' => $rows);
        }
        return array('destinations' => $out, 'busy' => $busy, 'review' => $review);
    }

    /** One image's state for its destination: compatible | adjusting | adjusted | review | approved | unsupported. */
    private static function row_state(array $row): string {
        if ($row['status'] === 'unsupported') { return 'unsupported'; }
        if ($row['status'] === 'compatible') { return 'compatible'; }
        $st = $row['variant']['state'] ?? 'processing';
        if ($st === 'processing') { return 'adjusting'; }
        if ($row['status'] === 'approval') { return $st === 'approved' ? 'approved' : 'review'; }
        return 'adjusted';
    }

    /** The more urgent of two states (what a destination shows when it has several images). */
    private static function worse($a, $b): string {
        $rank = array('compatible' => 0, 'adjusted' => 1, 'approved' => 2, 'adjusting' => 3, 'review' => 4, 'unsupported' => 5);
        return ($rank[$b] ?? 0) > ($rank[$a] ?? 0) ? $b : $a;
    }

    /** Queue a version in the background (one job per image, destination shape and method at a time). */
    public static function start_variant($cid, $asset_id, $teaser, $platform, $placement, $method = 'fit', $focus = 'center'): void {
        $key = self::key($platform, $placement, $teaser);
        (new DatabaseJobQueue())->dispatch('media_variant', array('creator_id' => (int) $cid, 'asset_id' => (int) $asset_id, 'teaser' => !empty($teaser),
            'platform' => (string) $platform, 'placement' => (string) $placement, 'method' => (string) $method, 'focus' => (string) $focus),
            'media_variant:' . (int) $asset_id . ':' . $key . ':' . $method . ':' . $focus);
    }

    /* ---------------------------------------------------------------------------------------------------------------
     * Making a version
     * ------------------------------------------------------------------------------------------------------------- */

    /**
     * Make (or replace) the version of $asset for one destination. $method fit | crop applies only when the shape must
     * change; $focus top | center | bottom says where a crop keeps the picture. Returns the variant row, or null.
     */
    public static function make_variant($cid, array $asset, $teaser, $platform, $placement, $method = 'fit', $focus = 'center') {
        $cid = (int) $cid;
        $src_key = self::sent_key($asset, $teaser);
        if ($src_key === '') { return null; }
        $rules = self::rules($platform, $placement);
        $key = self::key($platform, $placement, $teaser);
        $mm = new MediaAssetsModel();
        if ((string) ($asset['type'] ?? 'image') === 'video' && empty($teaser)) { return self::make_video_variant($cid, $asset, $rules, $key, $mm); }
        $src = tempnam(sys_get_temp_dir(), 'mcsrc');
        if ($src === false || !S3Service::get_private_to_file($src_key, $src)) { @unlink($src); return null; }
        $props = self::props_file($src);
        $c = self::classify($props, $rules);
        $old = $mm->variant_for($cid, (int) $asset['id'], $key);
        if ($c['status'] === 'compatible') {   // nothing to change: remember that, pointing at the file itself
            @unlink($src);
            if ($old) { $mm->soft_delete($cid, (int) $old['id']); }
            return $mm->add_variant($cid, (int) $asset['id'], 'v:' . $key . '|s:same', array('original_key' => $src_key, 'display_key' => $src_key,
                'thumb_key' => (string) ($asset['thumb_key'] ?? ''), 'width' => $props['w'], 'height' => $props['h'], 'bytes' => $props['bytes']), $asset);
        }
        if ($c['status'] === 'unsupported') { @unlink($src); return null; }
        $im = @imagecreatefromstring((string) @file_get_contents($src));
        @unlink($src);
        if (!$im) { return null; }
        if ($c['frame']) {
            list($fw, $fh) = $c['frame'];
            $im = ($method === 'crop') ? self::crop($im, $fw, $fh, $focus) : self::fit($im, $fw, $fh);
        }
        if (!empty($rules['max_side']) && max(imagesx($im), imagesy($im)) > (int) $rules['max_side']) {
            $s = (int) $rules['max_side'] / max(imagesx($im), imagesy($im));
            $im = self::scale($im, (int) round(imagesx($im) * $s), (int) round(imagesy($im) * $s));
        }
        // JPEG, stepping the quality down until it is under the size cap.
        $cap = (int) ($rules['max_bytes'] ?? 0); $bytes = '';
        foreach (array(90, 84, 78, 72, 64, 56) as $q) {
            ob_start(); imagejpeg($im, null, $q); $bytes = (string) ob_get_clean();
            if ($cap <= 0 || strlen($bytes) <= $cap) { break; }
        }
        $w = imagesx($im); $h = imagesy($im);
        $thumb = self::scale($im, max(1, (int) round($w * min(1, 600 / max($w, $h)))), max(1, (int) round($h * min(1, 600 / max($w, $h)))));
        ob_start(); imagejpeg($thumb, null, 82); $tbytes = (string) ob_get_clean();
        imagedestroy($im); imagedestroy($thumb);
        $base = 'vault/' . $cid . '/variants/' . (int) $asset['id'] . '/' . str_replace('.', '-', $key) . '-' . substr(md5(uniqid('', true)), 0, 8);
        if (!S3Service::put_private_bytes($base . '.jpg', $bytes, 'image/jpeg') || !S3Service::put_private_bytes($base . '-thumb.jpg', $tbytes, 'image/jpeg')) { return null; }
        if ($old) { $mm->soft_delete($cid, (int) $old['id']); }
        $state = $c['status'] === 'approval' ? 'pending' : 'auto';
        return $mm->add_variant($cid, (int) $asset['id'], 'v:' . $key . '|s:' . $state . '|m:' . ($c['frame'] ? ($method === 'crop' ? 'crop' : 'fit') : '') . '|f:' . ($method === 'crop' ? $focus : ''),
            array('original_key' => $base . '.jpg', 'display_key' => $base . '.jpg', 'thumb_key' => $base . '-thumb.jpg', 'width' => $w, 'height' => $h, 'bytes' => strlen($bytes)), $asset);
    }

    /** A video reshaped for a fixed-shape placement (a Story): the whole video centred on a blurred copy of itself. Needs approval. */
    private static function make_video_variant($cid, array $asset, array $rules, $key, MediaAssetsModel $mm) {
        $c = self::classify(self::props_estimate($asset), $rules, true);
        $old = $mm->variant_for($cid, (int) $asset['id'], $key);
        if ($c['status'] !== 'approval') {
            if ($old) { $mm->soft_delete($cid, (int) $old['id']); }
            return $mm->add_variant($cid, (int) $asset['id'], 'v:' . $key . '|s:same', array('original_key' => (string) $asset['original_key'], 'display_key' => (string) $asset['original_key'],
                'thumb_key' => (string) ($asset['thumb_key'] ?? ''), 'poster_key' => (string) ($asset['poster_key'] ?? ''), 'width' => (int) $asset['width'], 'height' => (int) $asset['height']), $asset, 'video');
        }
        $src = tempnam(sys_get_temp_dir(), 'mcvid');
        if ($src === false || !S3Service::get_private_to_file((string) $asset['original_key'], $src)) { @unlink($src); return null; }
        $dst = $src . '.mp4';
        list($fw, $fh) = $c['frame'];
        $ok = SocialFrame::video($src, $dst, $fw, $fh);
        @unlink($src);
        if (!$ok) { @unlink($dst); return null; }
        $poster = $dst . '.jpg';
        MediaService::run_with_timeout(escapeshellarg(MediaService::bin('ffmpeg')) . ' -y -ss 0.5 -i ' . escapeshellarg($dst) . ' -frames:v 1 -vf scale=600:-2 ' . escapeshellarg($poster), 60);
        $base = 'vault/' . $cid . '/variants/' . (int) $asset['id'] . '/' . str_replace('.', '-', $key) . '-' . substr(md5(uniqid('', true)), 0, 8);
        $up = S3Service::put_private($base . '.mp4', $dst, 'video/mp4') && (!is_file($poster) || S3Service::put_private($base . '-poster.jpg', $poster, 'image/jpeg'));
        $bytes = (int) @filesize($dst);
        @unlink($dst); @unlink($poster);
        if (!$up) { return null; }
        if ($old) { $mm->soft_delete($cid, (int) $old['id']); }
        return $mm->add_variant($cid, (int) $asset['id'], 'v:' . $key . '|s:pending|m:fit|f:', array('original_key' => $base . '.mp4', 'display_key' => $base . '.mp4',
            'thumb_key' => $base . '-poster.jpg', 'poster_key' => $base . '-poster.jpg', 'width' => $fw, 'height' => $fh, 'bytes' => $bytes, 'duration_sec' => (int) ($asset['duration_sec'] ?? 0)), $asset, 'video');
    }

    /** The picture whole and centred on a blurred copy of itself (SocialFrame's layout), as a GD image. */
    private static function fit($im, $w, $h) {
        $a = tempnam(sys_get_temp_dir(), 'mcfa'); $b = $a . '.jpg';
        imagejpeg($im, $a, 95); imagedestroy($im);
        $ok = SocialFrame::image($a, $b, $w, $h);
        $out = $ok ? @imagecreatefromjpeg($b) : null;
        @unlink($a); @unlink($b);
        return $out ?: imagecreatetruecolor($w, $h);
    }

    /** Cut the picture to $w x $h's shape, keeping the top, centre or bottom (left, centre or right for a wide cut). */
    private static function crop($im, $w, $h, $focus) {
        $sw = imagesx($im); $sh = imagesy($im);
        $target = $w / $h;
        if ($sw / $sh > $target) { $cw = (int) round($sh * $target); $ch = $sh; } else { $cw = $sw; $ch = (int) round($sw / $target); }
        $x = (int) (($sw - $cw) / 2); $y = (int) (($sh - $ch) / 2);
        if ($focus === 'top') { $y = 0; $x = ($cw < $sw) ? 0 : $x; }
        if ($focus === 'bottom') { $y = $sh - $ch; $x = ($cw < $sw) ? $sw - $cw : $x; }
        $out = imagecreatetruecolor($w, $h);
        imagecopyresampled($out, $im, 0, 0, $x, $y, $w, $h, $cw, $ch);
        imagedestroy($im);
        return $out;
    }

    private static function scale($im, $w, $h) {
        $out = imagecreatetruecolor(max(1, $w), max(1, $h));
        imagecopyresampled($out, $im, 0, 0, 0, 0, max(1, $w), max(1, $h), imagesx($im), imagesy($im));
        return $out;
    }

    /** The state in a variant's tags: auto | same | pending | approved. */
    public static function variant_state(array $v): string {
        return preg_match('/\|s:([a-z]+)/', (string) ($v['tags'] ?? ''), $m) ? $m[1] : 'auto';
    }

    private static function tag(array $v, $name): string {
        return preg_match('/\|' . preg_quote($name, '/') . ':([a-z]*)/', (string) ($v['tags'] ?? ''), $m) ? $m[1] : '';
    }

    /** A variant for the composer: its picture, state and how it was made. */
    public static function variant_json($cid, array $v): array {
        $st = self::variant_state($v);
        $video = (string) $v['type'] === 'video';
        $signable = array('creator_id' => (int) $cid, 'type' => $video ? 'video' : 'image', 'thumb_key' => (string) $v['thumb_key'], 'display_key' => (string) $v['display_key'],
            'blurred_key' => '', 'poster_key' => (string) ($v['poster_key'] ?? ''), 'original_key' => (string) $v['original_key']);
        return array('id' => (int) $v['id'], 'state' => $st === 'auto' ? 'ready' : $st, 'method' => self::tag($v, 'm'), 'focus' => self::tag($v, 'f'),
            'width' => (int) $v['width'], 'height' => (int) $v['height'], 'video' => $video,
            'thumb_url' => MediaService::signed_url($signable, $video ? 'poster' : 'thumb', (int) $cid),
            'display_url' => MediaService::signed_url($signable, $video ? 'original' : 'display', (int) $cid));
    }

    /** Approve a proposed version: it may now be published. */
    public static function approve($cid, $variant_id): array {
        $mm = new MediaAssetsModel();
        $v = $mm->get_variant((int) $cid, (int) $variant_id);
        if (!$v) { return array('ok' => false, 'error' => 'That adjusted image was not found.'); }
        if (self::variant_state($v) !== 'pending' && self::variant_state($v) !== 'approved') { return array('ok' => false, 'error' => 'That image needs no approval.'); }
        $mm->set_tags((int) $cid, (int) $v['id'], preg_replace('/\|s:[a-z]+/', '|s:approved', (string) $v['tags']));
        return array('ok' => true, 'variant' => self::variant_json((int) $cid, $mm->get_variant((int) $cid, (int) $v['id'])));
    }

    /* ---------------------------------------------------------------------------------------------------------------
     * Publishing: the authoritative check, per destination
     * ------------------------------------------------------------------------------------------------------------- */

    /**
     * The file to send for one image to one destination, decided from the file itself. Returns ['key' => S3 key to send,
     * 'state' => compatible | adjusted | approved | held | unsupported, 'reason' => plain words for held / unsupported].
     * An auto version is made here when the background one is missing; a shape change is only ever sent approved.
     */
    public static function resolve($cid, array $asset, $teaser, $platform, $placement, $is_video = false): array {
        $rules = self::rules($platform, $placement);
        if ($is_video && empty($teaser)) {
            $c = self::classify(self::props_estimate($asset), $rules, true);
            if ($c['status'] === 'unsupported') { return array('key' => '', 'state' => 'unsupported', 'reason' => implode(' ', $c['reasons'])); }
            if ($c['status'] === 'compatible') { return array('key' => (string) $asset['original_key'], 'state' => 'compatible', 'reason' => ''); }
        }
        $key = self::key($platform, $placement, $teaser);
        $mm = new MediaAssetsModel();
        $v = $mm->variant_for((int) $cid, (int) $asset['id'], $key);
        $sent = self::sent_key($asset, $teaser);
        if ($v) {
            $st = self::variant_state($v);
            if ($st === 'same') { return array('key' => $sent, 'state' => 'compatible', 'reason' => ''); }
            if ($st === 'auto') { return array('key' => (string) $v['display_key'], 'state' => 'adjusted', 'reason' => ''); }
            if ($st === 'approved') { return array('key' => (string) $v['display_key'], 'state' => 'approved', 'reason' => ''); }
            return array('key' => '', 'state' => 'held', 'reason' => 'the adjusted image is waiting for your approval');
        }
        // No version yet: measure the file and make one now (auto) or propose one and hold (shape change).
        $made = self::make_variant((int) $cid, $asset, $teaser, $platform, $placement, 'fit', 'center');
        if (!$made) {
            $c = self::classify(self::props_estimate($asset), $rules);
            if ($c['status'] === 'unsupported') { return array('key' => '', 'state' => 'unsupported', 'reason' => implode(' ', $c['reasons'])); }
            if ($c['status'] === 'compatible') { return array('key' => $sent, 'state' => 'compatible', 'reason' => ''); }
            return array('key' => '', 'state' => 'held', 'reason' => 'the image could not be prepared');
        }
        return self::resolve($cid, $asset, $teaser, $platform, $placement, false);
    }

    /** Ask the creator to review a proposed version (automations, held shares): one notice per post. */
    public static function notify_review($cid, $post_id, array $names): void {
        try {
            Notify::send((int) $cid, 'system', 'An image adjustment is ready for review',
                implode(', ', array_unique($names)) . ' needs a different image shape. Open the post to compare the original with the adjusted image and approve it; it is published to those accounts once approved.',
                '/studio?post=' . (int) $post_id, 'fa-crop-simple');
        } catch (\Throwable $e) {
            error_log('[media compliance] notify ' . (int) $post_id . ': ' . $e->getMessage());
        }
    }
}
