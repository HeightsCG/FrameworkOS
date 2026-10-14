<?php
/**
 * Fits a picture or video into the shape a platform requires, the way Instagram does it when you post
 * by hand: the whole picture centred, nothing cropped, on a blurred and darkened copy of itself that
 * fills the frame. Used by SocialShareService for Stories (9:16) and Instagram feed pictures that fall
 * outside 4:5 to 1.91:1. The composer's Story preview draws the same layout (studio.js renderStoryPreview).
 */
class SocialFrame {

    /** Story frame (9:16). */
    const STORY_W = 1080;
    const STORY_H = 1920;

    /** Instagram feed accepts width / height from 4:5 (0.8) to 1.91:1. */
    const IG_MIN = 0.8;
    const IG_MAX = 1.91;

    /** The frame an Instagram feed picture of this size needs, or null when it already fits. */
    public static function instagram_frame($width, $height): ?array {
        $w = (int) $width; $h = (int) $height;
        if ($w <= 0 || $h <= 0) { return null; }
        $r = $w / $h;
        if ($r < self::IG_MIN - 0.005) { return array(1080, 1350); }   // taller than 4:5
        if ($r > self::IG_MAX + 0.005) { return array(1080, 566); }    // wider than 1.91:1
        return null;
    }

    /** Picture at $src fitted into a $w x $h JPEG at $dst. False when GD cannot read it. */
    public static function image($src, $dst, $w, $h): bool {
        $bytes = @file_get_contents((string) $src);
        $im = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if (!$im) { return false; }
        $sw = imagesx($im); $sh = imagesy($im);
        $out = imagecreatetruecolor($w, $h);

        // Background: the picture covering the frame, blurred by shrinking it hard and scaling it back up, then darkened.
        $scale = max($w / $sw, $h / $sh);
        $cw = (int) round($w / $scale); $ch = (int) round($h / $scale);
        $small = imagecreatetruecolor(max(1, (int) ($w / 16)), max(1, (int) ($h / 16)));
        imagecopyresampled($small, $im, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), imagesx($small), imagesy($small), $cw, $ch);
        for ($i = 0; $i < 4; $i++) { imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR); }
        // Scale back up in steps, blurring at each one, so the background is smooth rather than blocky.
        foreach (array(4, 1) as $div) {
            $next = imagecreatetruecolor(max(1, (int) ($w / $div)), max(1, (int) ($h / $div)));
            imagecopyresampled($next, $small, 0, 0, 0, 0, imagesx($next), imagesy($next), imagesx($small), imagesy($small));
            for ($i = 0; $i < ($div > 1 ? 6 : 2); $i++) { imagefilter($next, IMG_FILTER_GAUSSIAN_BLUR); }
            imagedestroy($small); $small = $next;
        }
        imagecopy($out, $small, 0, 0, 0, 0, $w, $h);
        imagefilledrectangle($out, 0, 0, $w, $h, imagecolorallocatealpha($out, 0, 0, 0, 80));

        // Foreground: the whole picture, centred.
        $fit = min($w / $sw, $h / $sh);
        $fw = (int) round($sw * $fit); $fh = (int) round($sh * $fit);
        imagecopyresampled($out, $im, (int) (($w - $fw) / 2), (int) (($h - $fh) / 2), 0, 0, $fw, $fh, $sw, $sh);

        $ok = imagejpeg($out, (string) $dst, 90);
        imagedestroy($im); imagedestroy($small); imagedestroy($out);
        return $ok && filesize((string) $dst) > 0;
    }

    /** Video at $src fitted into a $w x $h MP4 at $dst (same layout as image()). False without ffmpeg or on failure. */
    public static function video($src, $dst, $w, $h, $timeout = 300): bool {
        $ffmpeg = MediaService::bin('ffmpeg');
        if ($ffmpeg === '') { return false; }
        $w = (int) $w; $h = (int) $h;
        $filter = "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},boxblur=30:4,eq=brightness=-0.12[bg];"
                . "[0:v]scale={$w}:{$h}:force_original_aspect_ratio=decrease[fg];[bg][fg]overlay=(W-w)/2:(H-h)/2,setsar=1,format=yuv420p[v]";
        @unlink((string) $dst);
        MediaService::run_with_timeout(escapeshellarg($ffmpeg) . ' -y -i ' . escapeshellarg((string) $src)
            . ' -filter_complex ' . escapeshellarg($filter) . ' -map "[v]" -map "0:a?" -c:v libx264 -preset veryfast -crf 22 -c:a aac -b:a 128k -movflags +faststart '
            . escapeshellarg((string) $dst), (int) $timeout);
        return is_file((string) $dst) && filesize((string) $dst) > 0;
    }
}
