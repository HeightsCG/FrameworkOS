<?php
/**
 * Covers the face in a source photo before it is sent to an edit model, so the source person's
 * features do not carry over into the influencer's replica. The face is found automatically
 * (Claude vision, as fractions of the image); a hand-brushed mask is the fallback. Regions are
 * always fractions (0..1) of the image, so they hold at any rendition size.
 */
class FaceMask {

    const PAD   = 0.22;                 // grow a detected face box by this share on each side: hairline, jaw, ears
    const COLOR = array(128, 128, 128); // neutral grey: reads as "missing", not as skin or shadow

    /** Clamp a face box to the image and reject nonsense. Returns ['x','y','w','h'] or null. */
    public static function clean_box($b){
        if (!is_array($b)) { return null; }
        foreach (array('x', 'y', 'w', 'h') as $k) { if (!isset($b[$k]) || !is_numeric($b[$k])) { return null; } }
        $x = max(0.0, min(1.0, (float) $b['x'])); $y = max(0.0, min(1.0, (float) $b['y']));
        $w = max(0.0, min(1.0 - $x, (float) $b['w'])); $h = max(0.0, min(1.0 - $y, (float) $b['h']));
        if ($w < 0.01 || $h < 0.01) { return null; }
        return array('x' => round($x, 4), 'y' => round($y, 4), 'w' => round($w, 4), 'h' => round($h, 4));
    }

    /** Brush strokes from the page: [['x','y','r'], ...] as fractions (r of the image width). Capped and clamped. */
    public static function clean_strokes($strokes){
        $out = array();
        foreach ((array) $strokes as $s) {
            if (!is_array($s) || !isset($s['x'], $s['y'], $s['r']) || !is_numeric($s['x']) || !is_numeric($s['y']) || !is_numeric($s['r'])) { continue; }
            $out[] = array('x' => max(0.0, min(1.0, (float) $s['x'])), 'y' => max(0.0, min(1.0, (float) $s['y'])), 'r' => max(0.005, min(0.5, (float) $s['r'])));
            if (count($out) >= 4000) { break; }
        }
        return $out;
    }

    /**
     * Paint the mask. $box: a face box (clean_box) or null; $strokes: brush points (clean_strokes).
     * Returns JPEG bytes, or '' when there is nothing to mask or the image cannot be read.
     */
    public static function apply($bytes, $box, array $strokes = array()){
        $box = self::clean_box($box);
        $strokes = self::clean_strokes($strokes);
        if (($box === null && empty($strokes)) || !function_exists('imagecreatefromstring')) { return ''; }
        $im = @imagecreatefromstring((string) $bytes);
        if (!$im) { return ''; }
        $w = imagesx($im); $h = imagesy($im);
        $grey = imagecolorallocate($im, self::COLOR[0], self::COLOR[1], self::COLOR[2]);
        if ($box !== null) {
            $bw = $box['w'] * $w * (1 + 2 * self::PAD); $bh = $box['h'] * $h * (1 + 2 * self::PAD);
            imagefilledellipse($im, (int) round(($box['x'] + $box['w'] / 2) * $w), (int) round(($box['y'] + $box['h'] / 2) * $h), (int) round($bw), (int) round($bh), $grey);
        }
        foreach ($strokes as $s) {
            $d = (int) max(2, round($s['r'] * $w * 2));
            imagefilledellipse($im, (int) round($s['x'] * $w), (int) round($s['y'] * $h), $d, $d, $grey);
        }
        ob_start();
        imagejpeg($im, null, 92);
        $out = (string) ob_get_clean();
        imagedestroy($im);
        return $out;
    }

    /**
     * Store a masked source where the job engine can sign it for the provider. Returns the S3 key or ''.
     * TODO: these vault/<cid>/tmp/mask_*.jpg copies are never deleted. The only place that knows when the job is over
     * is InfluencerJobService::after_terminal($job): add there, for type 'replicate', a
     * `S3Service::delete_key($p['source_key'])` when `FaceMask::owns_key($job['creator_id'], $p['source_key'])`
     * (params_json carries source_key; the masked copy is only needed while the provider fetches it).
     */
    public static function store($creator_id, $jpeg_bytes){
        if ((string) $jpeg_bytes === '') { return ''; }
        $key = 'vault/' . (int) $creator_id . '/tmp/mask_' . bin2hex(random_bytes(8)) . '.jpg';
        return S3Service::put_private_bytes($key, $jpeg_bytes, 'image/jpeg') ? $key : '';
    }

    /** Is this key one of this creator's own stored files (the only keys a job may sign as its source)? */
    public static function owns_key($creator_id, $key){
        return strpos((string) $key, 'vault/' . (int) $creator_id . '/') === 0 && strpos((string) $key, '..') === false;
    }
}
