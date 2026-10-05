<?php
/**
 * The clip editor's renderer (Content Studio, New Edit): turns a saved timeline into one
 * 1080p H.264 MP4 with ffmpeg. A timeline is ordered clips and stills (trimmed, stills with a set
 * length), text overlays, image overlays and an optional audio track.
 *
 * Text is drawn here with GD into transparent PNGs and composited as overlays, so the result does
 * not depend on how the server's ffmpeg was built (many builds have no drawtext filter).
 *
 * Nothing here charges AI credits: an export is the creator's own files joined on our server.
 */
class ClipRenderer {

    const SIZES      = array('9:16' => array(1080, 1920), '3:4' => array(1080, 1440));
    const FPS        = 30;
    const MAX_CLIPS  = 30;
    const MAX_TEXTS  = 20;
    const MAX_OVERLAYS = 10;
    const MAX_SECONDS  = 300;
    const STILL_DEFAULT = 3.0;

    /** Text positions: where the block sits, as a share of the frame height (its centre). */
    const POSITIONS = array('top' => 0.14, 'upper' => 0.30, 'middle' => 0.50, 'lower' => 0.70, 'bottom' => 0.86);
    /** Text sizes: cap height as a share of the frame width. */
    const TEXT_SIZES = array('small' => 0.042, 'medium' => 0.058, 'large' => 0.078, 'huge' => 0.104);
    /** Image overlay anchors. */
    const ANCHORS = array('top_left', 'top_right', 'center', 'bottom_left', 'bottom_right');

    /**
     * Fonts offered, each with the files to try in order (a bundled public/fonts/<key>.ttf wins, then the usual
     * macOS and Linux locations). Only fonts whose file is found are offered.
     */
    const FONTS = array(
        'sans'      => array('label' => 'Sans',      'files' => array('/System/Library/Fonts/Supplemental/Arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf', '/usr/share/fonts/dejavu/DejaVuSans.ttf')),
        'sans_bold' => array('label' => 'Sans Bold', 'files' => array('/System/Library/Fonts/Supplemental/Arial Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf')),
        'serif'     => array('label' => 'Serif',     'files' => array('/System/Library/Fonts/Supplemental/Georgia.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf', '/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf', '/usr/share/fonts/dejavu/DejaVuSerif.ttf')),
        'mono'      => array('label' => 'Mono',      'files' => array('/System/Library/Fonts/Supplemental/Courier New Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf', '/usr/share/fonts/truetype/liberation/LiberationMono-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSansMono-Bold.ttf')),
        'heavy'     => array('label' => 'Heavy',     'files' => array('/System/Library/Fonts/Supplemental/Impact.ttf', '/usr/share/fonts/truetype/msttcorefonts/Impact.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSansCondensed-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSansCondensed-Bold.ttf')),
    );

    /** key => ['label', 'path'] for every font this server can draw. */
    public static function fonts(){
        static $found = null;
        if ($found !== null) { return $found; }
        $found = array();
        foreach (self::FONTS as $key => $f) {
            $files = array_merge(array(Main::app_path() . '/public/fonts/' . $key . '.ttf'), $f['files']);
            foreach ($files as $file) { if (is_file($file)) { $found[$key] = array('label' => $f['label'], 'path' => $file); break; } }
        }
        return $found;
    }

    public static function available(){
        return VideoTools::available() && function_exists('imagettftext') && !empty(self::fonts());
    }

    private static function num($v, $min, $max, $default){
        return is_numeric($v) ? max($min, min($max, (float) $v)) : (float) $default;
    }

    /**
     * Check and tidy a timeline against the creator's Library. Returns
     * ['timeline' => clean, 'duration' => seconds, 'errors' => [strings], 'assets' => id => row].
     * Clip times are clamped to the file; an overlay's end of 0 means "to the end of the video".
     */
    public static function normalize($cid, array $t){
        $mm = new MediaAssetsModel();
        $errors = array(); $assets = array();
        $get = function ($id) use ($mm, $cid, &$assets) {
            $id = (int) $id;
            if ($id <= 0) { return null; }
            if (!array_key_exists($id, $assets)) {
                $a = $mm->get_one($cid, $id);
                $assets[$id] = ($a && (string) $a['status'] === 'ready' && (string) $a['moderation_status'] !== 'blocked') ? $a : null;
            }
            return $assets[$id];
        };
        $clips = array(); $total = 0.0;
        foreach (array_slice(array_values((array) ($t['clips'] ?? array())), 0, self::MAX_CLIPS) as $c) {
            if (!is_array($c)) { continue; }
            $a = $get($c['asset_id'] ?? 0);
            if (!$a || !in_array((string) $a['type'], array('video', 'image', 'gif'), true)) { $errors[] = 'A clip is no longer in your Library. Remove it from the edit.'; continue; }
            if ((string) $a['type'] === 'video') {
                $len   = max(0.1, (float) $a['duration_sec']);
                $start = self::num($c['start'] ?? 0, 0, max(0, $len - 0.1), 0);
                $end   = self::num($c['end'] ?? 0, 0, $len + 1, 0);
                if ($end <= $start) { $end = $len; }
                $dur = round($end - $start, 2);
                $clips[] = array('asset_id' => (int) $a['id'], 'type' => 'video', 'start' => round($start, 2), 'end' => round($end, 2), 'duration' => $dur, 'mute' => !empty($c['mute']));
            } else {
                $dur = round(self::num($c['duration'] ?? self::STILL_DEFAULT, 0.5, 30, self::STILL_DEFAULT), 2);
                $clips[] = array('asset_id' => (int) $a['id'], 'type' => 'image', 'start' => 0, 'end' => $dur, 'duration' => $dur, 'mute' => true);
            }
            $total += $dur;
        }
        if (empty($clips)) { $errors[] = 'Add at least one clip or image.'; }
        if ($total > self::MAX_SECONDS) { $errors[] = 'The edit is ' . round($total) . ' seconds. An export can be up to ' . self::MAX_SECONDS . ' seconds.'; }
        $span = function ($row) use ($total) {
            $s = self::num($row['start'] ?? 0, 0, max(0, $total), 0);
            $e = self::num($row['end'] ?? 0, 0, max(0, $total), 0);
            if ($e <= $s) { $e = $total; }
            return array(round($s, 2), round($e, 2));
        };
        $fonts = self::fonts(); $texts = array();
        foreach (array_slice(array_values((array) ($t['texts'] ?? array())), 0, self::MAX_TEXTS) as $x) {
            if (!is_array($x)) { continue; }
            $text = trim(mb_substr((string) ($x['text'] ?? ''), 0, 300));
            if ($text === '') { continue; }
            list($s, $e) = $span($x);
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($x['color'] ?? '')) ? strtoupper((string) $x['color']) : '#FFFFFF';
            $texts[] = array('text' => $text, 'font' => isset($fonts[$x['font'] ?? '']) ? (string) $x['font'] : (string) (array_key_first($fonts) ?? 'sans'),
                'size' => isset(self::TEXT_SIZES[$x['size'] ?? '']) ? (string) $x['size'] : 'medium', 'color' => $color,
                'position' => isset(self::POSITIONS[$x['position'] ?? '']) ? (string) $x['position'] : 'lower',
                'start' => $s, 'end' => $e, 'shadow' => !array_key_exists('shadow', $x) || !empty($x['shadow']));   // shadow is on unless switched off
        }
        $overlays = array();
        foreach (array_slice(array_values((array) ($t['overlays'] ?? array())), 0, self::MAX_OVERLAYS) as $o) {
            if (!is_array($o)) { continue; }
            $a = $get($o['asset_id'] ?? 0);
            if (!$a || (string) $a['type'] !== 'image') { $errors[] = 'An image overlay is no longer in your Library. Remove it from the edit.'; continue; }
            if (stripos((string) $a['mime'], 'png') === false) { $errors[] = 'Image overlays need to be PNG files.'; continue; }
            list($s, $e) = $span($o);
            $overlays[] = array('asset_id' => (int) $a['id'], 'anchor' => in_array($o['anchor'] ?? '', self::ANCHORS, true) ? (string) $o['anchor'] : 'top_right',
                'scale' => (int) self::num($o['scale'] ?? 25, 5, 100, 25), 'start' => $s, 'end' => $e);
        }
        $audio = null;
        if (!empty($t['audio']['asset_id'])) {
            $a = $get($t['audio']['asset_id']);
            if (!$a || (string) $a['type'] !== 'audio') { $errors[] = 'The audio track is no longer in your Library. Remove it from the edit.'; }
            else { $audio = array('asset_id' => (int) $a['id'], 'volume' => (int) self::num($t['audio']['volume'] ?? 100, 0, 100, 100)); }
        }
        return array('timeline' => array('clips' => $clips, 'texts' => $texts, 'overlays' => $overlays, 'audio' => $audio),
            'duration' => round($total, 2), 'errors' => array_values(array_unique($errors)), 'assets' => array_filter($assets));
    }

    /** Break a line of text to fit $max_w pixels. Returns lines. */
    private static function wrap($text, $font, $px, $max_w){
        $lines = array();
        foreach (preg_split('/\R/u', (string) $text) as $para) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($para)) as $word) {
                $try = ($line === '') ? $word : $line . ' ' . $word;
                $box = imagettfbbox($px, 0, $font, $try);
                if ($line !== '' && abs($box[2] - $box[0]) > $max_w) { $lines[] = $line; $line = $word; } else { $line = $try; }
            }
            $lines[] = $line;
        }
        return array_slice($lines, 0, 8);
    }

    /**
     * One text overlay as a transparent PNG the size of the frame. Returns the file path ('' on failure).
     * $x: a normalized text row. A soft dark shadow sits behind the letters when $x['shadow'] is on.
     */
    public static function text_png(array $x, $W, $H){
        $fonts = self::fonts();
        $font  = $fonts[$x['font']]['path'] ?? (reset($fonts)['path'] ?? '');
        if ($font === '' || !function_exists('imagettftext')) { return ''; }
        $px   = (int) round($W * (self::TEXT_SIZES[$x['size']] ?? self::TEXT_SIZES['medium']));
        $im   = imagecreatetruecolor($W, $H);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true); imagesavealpha($im, true);
        $rgb   = sscanf($x['color'], '#%02x%02x%02x');
        $ink   = imagecolorallocate($im, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2]);
        $lines = self::wrap($x['text'], $font, $px, (int) ($W * 0.86));
        $lh    = (int) round($px * 1.32);
        $block = $lh * count($lines);
        $y0    = (int) round($H * (self::POSITIONS[$x['position']] ?? 0.7) - $block / 2 + $px);
        $y0    = max($px + 8, min($H - $block + $px - 8, $y0));
        foreach ($lines as $i => $line) {
            $box = imagettfbbox($px, 0, $font, $line);
            $lx  = (int) round(($W - abs($box[2] - $box[0])) / 2 - min($box[0], $box[6]));
            $ly  = $y0 + $i * $lh;
            if (!empty($x['shadow'])) {
                $off = max(2, (int) round($px * 0.06));
                foreach (array(array(64, $off * 2), array(40, $off)) as $s) {   // two passes: a wide faint one, then a tight darker one
                    $shade = imagecolorallocatealpha($im, 0, 0, 0, $s[0]);
                    foreach (array(array($s[1], $s[1]), array(-1, $s[1]), array($s[1], -1), array(0, $s[1]), array($s[1], 0)) as $d) { imagettftext($im, $px, 0, $lx + $d[0], $ly + $d[1], $shade, $font, $line); }
                }
            }
            imagettftext($im, $px, 0, $lx, $ly, $ink, $font, $line);
        }
        $base = tempnam(sys_get_temp_dir(), 'ctxt'); @unlink($base); $path = $base . '.png';
        $ok = imagepng($im, $path);
        imagedestroy($im);
        return $ok ? $path : '';
    }

    /** x:y expressions for an image overlay anchored in a corner or the centre, with a margin. */
    private static function anchor_xy($anchor, $W){
        $m = (int) round($W * 0.04);
        switch ($anchor) {
            case 'top_left':     return array((string) $m, (string) $m);
            case 'bottom_left':  return array((string) $m, 'H-h-' . $m);
            case 'bottom_right': return array('W-w-' . $m, 'H-h-' . $m);
            case 'center':       return array('(W-w)/2', '(H-h)/2');
            default:             return array('W-w-' . $m, (string) $m);
        }
    }

    /**
     * Render a normalized timeline. Returns ['ok' => bool, 'path' => mp4, 'duration' => seconds, 'error'].
     * $norm is the result of normalize(); $aspect '9:16' or '3:4'.
     */
    public static function render($cid, array $norm, $aspect){
        if (!self::available()) { return array('ok' => false, 'error' => 'Exporting is not available on this server right now.'); }
        if (!empty($norm['errors'])) { return array('ok' => false, 'error' => $norm['errors'][0]); }
        list($W, $H) = self::SIZES[$aspect] ?? self::SIZES['9:16'];
        $t = $norm['timeline']; $assets = $norm['assets']; $total = (float) $norm['duration'];
        $ffmpeg = MediaService::bin('ffmpeg');
        $tmp = array(); $inputs = ''; $filters = array(); $n = 0;
        $done = function ($r) use (&$tmp) { foreach ($tmp as $f) { if (is_file($f)) { @unlink($f); } } return $r; };
        $fetch = function ($key, $ext) use (&$tmp) {
            $base = tempnam(sys_get_temp_dir(), 'clip'); @unlink($base); $path = $base . '.' . preg_replace('/[^a-z0-9]/', '', strtolower((string) $ext));
            if ((string) $key === '' || !S3Service::get_private_to_file((string) $key, $path)) { return ''; }
            $tmp[] = $path;
            return $path;
        };
        $fit = 'scale=' . $W . ':' . $H . ':force_original_aspect_ratio=increase,crop=' . $W . ':' . $H . ',setsar=1,fps=' . self::FPS . ',format=yuv420p';

        // 1. clips and stills, each fitted to the frame, with sound or silence, then joined
        $pairs = '';
        foreach ($t['clips'] as $i => $c) {
            $a = $assets[$c['asset_id']];
            if ($c['type'] === 'video') {
                $path = $fetch($a['original_key'], pathinfo((string) $a['original_key'], PATHINFO_EXTENSION) ?: 'mp4');
                if ($path === '') { return $done(array('ok' => false, 'error' => 'A clip could not be read from your Library.')); }
                $inputs .= ' -i ' . escapeshellarg($path);
                $filters[] = '[' . $n . ':v:0]trim=start=' . $c['start'] . ':end=' . $c['end'] . ',setpts=PTS-STARTPTS,' . $fit . '[v' . $i . ']';
                $has_audio = !$c['mute'] && VideoTools::probe($path)['has_audio'];
                $filters[] = $has_audio
                    ? '[' . $n . ':a:0]atrim=start=' . $c['start'] . ':end=' . $c['end'] . ',asetpts=PTS-STARTPTS,aresample=48000,aformat=channel_layouts=stereo[a' . $i . ']'
                    : 'anullsrc=r=48000:cl=stereo,atrim=duration=' . $c['duration'] . '[a' . $i . ']';
            } else {
                $path = $fetch($a['original_key'] ?: $a['display_key'], pathinfo((string) ($a['original_key'] ?: $a['display_key']), PATHINFO_EXTENSION) ?: 'jpg');
                if ($path === '') { return $done(array('ok' => false, 'error' => 'An image could not be read from your Library.')); }
                $inputs .= ' -loop 1 -framerate ' . self::FPS . ' -t ' . $c['duration'] . ' -i ' . escapeshellarg($path);
                $filters[] = '[' . $n . ':v:0]' . $fit . ',trim=duration=' . $c['duration'] . ',setpts=PTS-STARTPTS[v' . $i . ']';
                $filters[] = 'anullsrc=r=48000:cl=stereo,atrim=duration=' . $c['duration'] . '[a' . $i . ']';
            }
            $pairs .= '[v' . $i . '][a' . $i . ']';
            $n++;
        }
        $filters[] = $pairs . 'concat=n=' . count($t['clips']) . ':v=1:a=1[vc][ac]';
        $v = 'vc';

        // 2. image overlays, then text overlays on top
        $k = 0;
        foreach ($t['overlays'] as $o) {
            $a = $assets[$o['asset_id']];
            $path = $fetch($a['original_key'] ?: $a['display_key'], 'png');
            if ($path === '') { return $done(array('ok' => false, 'error' => 'An image overlay could not be read from your Library.')); }
            $inputs .= ' -loop 1 -framerate ' . self::FPS . ' -t ' . max(0.1, $total) . ' -i ' . escapeshellarg($path);
            list($x, $y) = self::anchor_xy($o['anchor'], $W);
            $filters[] = '[' . $n . ':v:0]scale=' . (int) round($W * $o['scale'] / 100) . ':-1,format=rgba[o' . $k . ']';
            $filters[] = '[' . $v . '][o' . $k . ']overlay=x=' . $x . ':y=' . $y . ':shortest=1:enable=' . "'between(t," . $o['start'] . ',' . $o['end'] . ")'" . '[vo' . $k . ']';
            $v = 'vo' . $k; $k++; $n++;
        }
        foreach ($t['texts'] as $x) {
            $png = self::text_png($x, $W, $H);
            if ($png === '') { return $done(array('ok' => false, 'error' => 'A text overlay could not be drawn.')); }
            $tmp[] = $png;
            $inputs .= ' -loop 1 -framerate ' . self::FPS . ' -t ' . max(0.1, $total) . ' -i ' . escapeshellarg($png);
            $filters[] = '[' . $v . '][' . $n . ':v:0]overlay=x=0:y=0:shortest=1:enable=' . "'between(t," . $x['start'] . ',' . $x['end'] . ")'" . '[vo' . $k . ']';
            $v = 'vo' . $k; $k++; $n++;
        }

        // 3. the audio track, mixed over whatever the clips still carry
        $aout = 'ac';
        if (!empty($t['audio'])) {
            $a = $assets[$t['audio']['asset_id']];
            $path = $fetch($a['original_key'], pathinfo((string) $a['original_key'], PATHINFO_EXTENSION) ?: 'mp3');
            if ($path === '') { return $done(array('ok' => false, 'error' => 'The audio track could not be read from your Library.')); }
            $inputs .= ' -i ' . escapeshellarg($path);
            $filters[] = '[' . $n . ':a:0]atrim=duration=' . $total . ',asetpts=PTS-STARTPTS,aresample=48000,aformat=channel_layouts=stereo,volume=' . round($t['audio']['volume'] / 100, 2) . ',apad=whole_dur=' . $total . '[am]';
            $filters[] = '[ac][am]amix=inputs=2:duration=first:normalize=0[amx]';
            $aout = 'amx'; $n++;
        }

        $base = tempnam(sys_get_temp_dir(), 'edit'); @unlink($base); $out = $base . '.mp4';
        $cmd = escapeshellarg($ffmpeg) . ' -y' . $inputs . ' -filter_complex ' . escapeshellarg(implode(';', $filters))
            . ' -map ' . escapeshellarg('[' . $v . ']') . ' -map ' . escapeshellarg('[' . $aout . ']')
            . ' -t ' . $total . ' -r ' . self::FPS . ' -c:v libx264 -preset veryfast -crf 20 -pix_fmt yuv420p -c:a aac -b:a 160k -ar 48000 -movflags +faststart ' . escapeshellarg($out);
        MediaService::run_with_timeout($cmd, max(300, (int) ($total * 20)));
        if (!is_file($out) || filesize($out) <= 0) { @unlink($out); error_log('[clip] render produced nothing: ' . substr($cmd, 0, 1500)); return $done(array('ok' => false, 'error' => 'The export failed while rendering. Try again.')); }
        return $done(array('ok' => true, 'error' => '', 'path' => $out, 'duration' => $total));
    }
}
