<?php
/**
 * Profile photos and cover images, before they go to S3: re-encoded so no EXIF/GPS metadata leaves the upload
 * (JPEGs are turned upright first), then checked by moderation. They show everywhere, including safe-for-work
 * surfaces, so an adult image is refused. If moderation itself is unavailable the upload is allowed (logged).
 * GIFs are kept as uploaded (re-encoding would drop animation; GIF carries no EXIF).
 */
class ProfileImage {

    /** Returns ['ok' => bool, 'path' => clean temp file, 'error' => message]. The caller uploads 'path'. */
    public static function prepare(string $tmp, string $mime): array {
        $path = $tmp;
        if ($mime !== 'image/gif') {
            $clean = self::strip_metadata($tmp, $mime);
            if ($clean === '') { return array('ok' => false, 'path' => '', 'error' => 'Could not read that image. Try a different file.'); }
            $path = $clean;
        }
        $bytes = (string) @file_get_contents($path);
        $mod = ModerationService::classify_image('data:' . $mime . ';base64,' . base64_encode($bytes));
        if (empty($mod['ok'])) {
            error_log('[profile-image] moderation unavailable: ' . ($mod['error'] ?? ''));
        } elseif (!empty($mod['adult'])) {
            return array('ok' => false, 'path' => '', 'error' => 'That image can’t be used as a profile or cover photo. Choose a different one.');
        }
        return array('ok' => true, 'path' => $path, 'error' => '');
    }

    /** Decode and re-encode (drops every metadata block); '' on failure. */
    private static function strip_metadata(string $tmp, string $mime): string {
        $img = null;
        if ($mime === 'image/jpeg') { $img = @imagecreatefromjpeg($tmp); }
        elseif ($mime === 'image/png') { $img = @imagecreatefrompng($tmp); }
        elseif ($mime === 'image/webp') { $img = @imagecreatefromwebp($tmp); }
        if (!$img) { return ''; }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {   // phone photos: apply the orientation tag before it's dropped
            $o = (int) (@exif_read_data($tmp)['Orientation'] ?? 1);
            $turn = array(3 => 180, 6 => -90, 8 => 90);
            if (isset($turn[$o])) { $r = imagerotate($img, $turn[$o], 0); if ($r) { imagedestroy($img); $img = $r; } }
        }
        $out = tempnam(sys_get_temp_dir(), 'pimg');
        if ($mime === 'image/png') { imagealphablending($img, false); imagesavealpha($img, true); $ok = imagepng($img, $out, 6); }
        elseif ($mime === 'image/webp') { imagealphablending($img, false); imagesavealpha($img, true); $ok = imagewebp($img, $out, 88); }
        else { $ok = imagejpeg($img, $out, 88); }
        imagedestroy($img);
        return $ok ? $out : '';
    }
}
