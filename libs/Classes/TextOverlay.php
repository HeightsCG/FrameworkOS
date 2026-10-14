<?php
/**
 * Burns an automation's hook line onto its picture or video in code, so no image or video model is ever asked to
 * draw letters (they misspell them). White bold sans-serif with a thin black outline, centred in the upper third,
 * inside the 9:16 safe area (clear of the platform's top bar and side buttons), on screen from the first frame to the last.
 *
 * The line is drawn with GD into a transparent PNG the size of the frame (ClipRenderer's approach: it does not depend
 * on the server's ffmpeg having the drawtext filter), then composited by ffmpeg (video) or GD (image).
 */
class TextOverlay {

    /** Cap height of the letters as a share of the frame width. */
    const SIZE = 0.064;
    /** Widest a line may run, as a share of the frame width (side margins for the platform's buttons). */
    const MAX_WIDTH = 0.82;
    /** Where the block's centre sits, as a share of the frame height: upper third, below the platform's top bar. */
    const CENTER = 0.20;

    public static function available(){
        return function_exists('imagettftext') && self::font() !== '';
    }

    private static function font(){
        $f = ClipRenderer::fonts();
        return (string) ($f['sans_bold']['path'] ?? ($f['sans']['path'] ?? ''));
    }

    /** The line as a transparent PNG the size of a $W x $H frame. Returns the file path ('' on failure). */
    public static function png($line, $W, $H){
        $line = trim(preg_replace('/\s+/u', ' ', (string) $line));
        $font = self::font();
        $W = (int) $W; $H = (int) $H;
        if ($line === '' || $font === '' || $W <= 0 || $H <= 0 || !function_exists('imagettftext')) { return ''; }
        $px    = max(14, (int) round($W * self::SIZE));
        $lines = self::wrap($line, $font, $px, (int) ($W * self::MAX_WIDTH));
        // A long line: step the size down until it fits in three lines.
        while (count($lines) > 3 && $px > 14) { $px = (int) round($px * 0.9); $lines = self::wrap($line, $font, $px, (int) ($W * self::MAX_WIDTH)); }
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true); imagesavealpha($im, true);
        $white  = imagecolorallocate($im, 255, 255, 255);
        $black  = imagecolorallocate($im, 0, 0, 0);
        $stroke = max(2, (int) round($px * 0.07));   // the thin outline
        $lh     = (int) round($px * 1.3);
        $block  = $lh * count($lines);
        $y0     = (int) round($H * self::CENTER - $block / 2 + $px);
        $y0     = max((int) round($H * 0.12) + $px, $y0);   // never under the platform's top bar
        foreach ($lines as $i => $l) {
            $box = imagettfbbox($px, 0, $font, $l);
            $lx  = (int) round(($W - abs($box[2] - $box[0])) / 2 - min($box[0], $box[6]));
            $ly  = $y0 + $i * $lh;
            for ($dx = -$stroke; $dx <= $stroke; $dx++) {
                for ($dy = -$stroke; $dy <= $stroke; $dy++) {
                    if (($dx * $dx + $dy * $dy) > $stroke * $stroke) { continue; }
                    imagettftext($im, $px, 0, $lx + $dx, $ly + $dy, $black, $font, $l);
                }
            }
            imagettftext($im, $px, 0, $lx, $ly, $white, $font, $l);
        }
        $base = tempnam(sys_get_temp_dir(), 'hook'); @unlink($base); $path = $base . '.png';
        $ok = imagepng($im, $path);
        imagedestroy($im);
        return $ok ? $path : '';
    }

    /** Burn the line onto a video file. Writes a new MP4 and returns its path; '' on failure (the caller keeps the original). */
    public static function burn_video($src, $line){
        $p = VideoTools::probe($src);
        if (empty($p['has_video']) || $p['width'] <= 0 || $p['height'] <= 0) { return ''; }
        $png = self::png($line, $p['width'], $p['height']);
        if ($png === '') { return ''; }
        $ffmpeg = MediaService::bin('ffmpeg');
        $base = tempnam(sys_get_temp_dir(), 'hookv'); @unlink($base); $out = $base . '.mp4';
        // The PNG is a single frame; overlay holds it to the end (eof_action=repeat), so the line shows on every frame.
        $cmd = escapeshellarg($ffmpeg) . ' -y -i ' . escapeshellarg($src) . ' -i ' . escapeshellarg($png)
            . ' -filter_complex ' . escapeshellarg('[0:v][1:v]overlay=0:0:eof_action=repeat:format=auto,format=yuv420p[v]')
            . ' -map "[v]" -map "0:a?" -c:v libx264 -preset veryfast -crf 18 -c:a copy -movflags +faststart ' . escapeshellarg($out) . ' 2>&1';
        MediaService::run_with_timeout($cmd, 300);
        @unlink($png);
        if (!is_file($out) || filesize($out) <= 0) { error_log('[text_overlay] ffmpeg produced no file for ' . $src); @unlink($out); return ''; }
        return $out;
    }

    /** Burn the line onto image bytes. Returns PNG bytes; '' on failure. */
    public static function burn_image($bytes, $line){
        $im = @imagecreatefromstring((string) $bytes);
        if (!$im) { return ''; }
        $W = imagesx($im); $H = imagesy($im);
        $png = self::png($line, $W, $H);
        if ($png === '') { imagedestroy($im); return ''; }
        $ov = imagecreatefrompng($png);
        @unlink($png);
        imagealphablending($im, true);
        imagecopy($im, $ov, 0, 0, 0, 0, $W, $H);
        imagedestroy($ov);
        ob_start(); imagepng($im); $out = (string) ob_get_clean();
        imagedestroy($im);
        return $out;
    }

    /** Greedy word wrap to $max pixels. */
    private static function wrap($text, $font, $px, $max){
        $lines = array(); $cur = '';
        foreach (explode(' ', $text) as $w) {
            $try = ($cur === '') ? $w : $cur . ' ' . $w;
            $b = imagettfbbox($px, 0, $font, $try);
            if ($cur !== '' && abs($b[2] - $b[0]) > $max) { $lines[] = $cur; $cur = $w; } else { $cur = $try; }
        }
        if ($cur !== '') { $lines[] = $cur; }
        return $lines;
    }
}
