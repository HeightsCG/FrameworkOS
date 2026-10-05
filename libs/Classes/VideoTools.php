<?php
/**
 * ffmpeg/ffprobe operations the AI video features share: reading a file, pulling frames, joining
 * clips, laying audio over a clip. Every method takes local paths or signed URLs, returns a temp
 * file path (the caller deletes it) or '' / array() on failure, and never throws. Binaries and the
 * kill-after-timeout runner come from MediaService.
 */
class VideoTools {

    public static function available(){ return MediaService::ffmpeg_available(); }

    private static function tmp($prefix, $ext){
        $base = tempnam(sys_get_temp_dir(), $prefix);
        @unlink($base);   // tempnam() creates the file; only its unique name is wanted
        return $base . '.' . $ext;
    }

    /**
     * What a video or audio file is: ['duration' => float seconds, 'width', 'height', 'fps' => float,
     * 'has_video' => bool, 'has_audio' => bool]; zeros/false when it cannot be read.
     */
    public static function probe($src){
        $out = array('duration' => 0.0, 'width' => 0, 'height' => 0, 'fps' => 0.0, 'has_video' => false, 'has_audio' => false);
        $ffprobe = MediaService::bin('ffprobe');
        if ($ffprobe === '' || (string) $src === '') { return $out; }
        $json = MediaService::run_with_timeout(escapeshellarg($ffprobe) . ' -v error -rw_timeout 20000000'
            . ' -show_entries format=duration:stream=codec_type,width,height,r_frame_rate -of json ' . escapeshellarg($src), 45);
        $d = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($d)) { return $out; }
        $out['duration'] = round((float) ($d['format']['duration'] ?? 0), 3);
        foreach ((array) ($d['streams'] ?? array()) as $s) {
            if (($s['codec_type'] ?? '') === 'audio') { $out['has_audio'] = true; }
            if (($s['codec_type'] ?? '') === 'video' && !$out['has_video']) {
                $out['has_video'] = true;
                $out['width']  = (int) ($s['width'] ?? 0);
                $out['height'] = (int) ($s['height'] ?? 0);
                $fr = explode('/', (string) ($s['r_frame_rate'] ?? '0/1'));
                $out['fps'] = ((float) ($fr[1] ?? 1) > 0) ? round((float) $fr[0] / (float) ($fr[1] ?? 1), 3) : 0.0;
            }
        }
        return $out;
    }

    /** One frame at $seconds as a JPEG (the last frame when $seconds is past the end). */
    public static function frame_at($src, $seconds){
        $ffmpeg = MediaService::bin('ffmpeg');
        if ($ffmpeg === '' || (string) $src === '') { return ''; }
        $dest = self::tmp('frame', 'jpg');
        $t = max(0, (float) $seconds);
        // -ss before -i: fast input seek, frame-accurate on re-encode, and only the needed bytes over http.
        MediaService::run_with_timeout(escapeshellarg($ffmpeg) . ' -y -rw_timeout 20000000 -ss ' . sprintf('%.3f', $t) . ' -i ' . escapeshellarg($src)
            . ' -frames:v 1 -q:v 2 ' . escapeshellarg($dest), 90);
        if (is_file($dest) && filesize($dest) > 0) { return $dest; }
        if ($t > 0) {   // asked for a moment at or past the end: take the final frame instead
            MediaService::run_with_timeout(escapeshellarg($ffmpeg) . ' -y -rw_timeout 20000000 -sseof -0.2 -i ' . escapeshellarg($src)
                . ' -update 1 -frames:v 1 -q:v 2 ' . escapeshellarg($dest), 90);
            if (is_file($dest) && filesize($dest) > 0) { return $dest; }
        }
        @unlink($dest);
        return '';
    }

    /** Up to $max frames spread evenly across the file (for moderating a source video). Returns JPEG paths. */
    public static function sample_frames($src, $max = 8){
        $p = self::probe($src);
        if (!$p['has_video'] || $p['duration'] <= 0) { $one = self::frame_at($src, 0); return ($one !== '') ? array($one) : array(); }
        $n = max(1, min((int) $max, (int) ceil($p['duration'] / 2)));   // about one every two seconds
        $out = array();
        for ($i = 0; $i < $n; $i++) {
            $f = self::frame_at($src, ($i + 0.5) * $p['duration'] / $n);
            if ($f !== '') { $out[] = $f; }
        }
        return $out;
    }

    /**
     * Join clips end to end into one H.264/AAC MP4. Clips are re-encoded to the first clip's size and
     * frame rate so differently-shaped parts still join; a clip with no sound gets silence.
     */
    public static function concat(array $paths, $timeout = 600){
        $ffmpeg = MediaService::bin('ffmpeg');
        $paths = array_values(array_filter(array_map('strval', $paths), 'strlen'));
        if ($ffmpeg === '' || empty($paths)) { return ''; }
        $first = self::probe($paths[0]);
        $w = max(2, (int) $first['width']); $h = max(2, (int) $first['height']);
        $w -= $w % 2; $h -= $h % 2;
        $fps = ($first['fps'] > 0) ? min(60, $first['fps']) : 30;
        $cmd = escapeshellarg($ffmpeg) . ' -y';
        $filters = array(); $joins = '';
        foreach ($paths as $i => $p) {
            $cmd .= ' -i ' . escapeshellarg($p);
            $pi = ($i === 0) ? $first : self::probe($p);
            $filters[] = '[' . $i . ':v:0]scale=' . $w . ':' . $h . ':force_original_aspect_ratio=decrease,pad=' . $w . ':' . $h . ':(ow-iw)/2:(oh-ih)/2,setsar=1,fps=' . $fps . ',format=yuv420p[v' . $i . ']';
            $filters[] = $pi['has_audio']
                ? '[' . $i . ':a:0]aresample=48000,aformat=channel_layouts=stereo[a' . $i . ']'
                : 'anullsrc=r=48000:cl=stereo,atrim=duration=' . sprintf('%.3f', max(0.1, $pi['duration'])) . '[a' . $i . ']';
            $joins .= '[v' . $i . '][a' . $i . ']';
        }
        $dest = self::tmp('join', 'mp4');
        $cmd .= ' -filter_complex ' . escapeshellarg(implode(';', $filters) . ';' . $joins . 'concat=n=' . count($paths) . ':v=1:a=1[v][a]')
            . ' -map ' . escapeshellarg('[v]') . ' -map ' . escapeshellarg('[a]')
            . ' -c:v libx264 -preset veryfast -crf 20 -c:a aac -b:a 160k -movflags +faststart ' . escapeshellarg($dest);
        MediaService::run_with_timeout($cmd, (int) $timeout);
        if (is_file($dest) && filesize($dest) > 0) { return $dest; }
        @unlink($dest);
        return '';
    }

    /** Join audio files end to end into one MP3 (a long script spoken paragraph by paragraph). */
    public static function concat_audio(array $paths, $timeout = 300){
        $ffmpeg = MediaService::bin('ffmpeg');
        $paths = array_values(array_filter(array_map('strval', $paths), 'strlen'));
        if ($ffmpeg === '' || empty($paths)) { return ''; }
        $cmd = escapeshellarg($ffmpeg) . ' -y'; $in = '';
        foreach ($paths as $i => $p) { $cmd .= ' -i ' . escapeshellarg($p); $in .= '[' . $i . ':a:0]'; }
        $dest = self::tmp('speech', 'mp3');
        $cmd .= ' -filter_complex ' . escapeshellarg($in . 'concat=n=' . count($paths) . ':v=0:a=1[a]') . ' -map ' . escapeshellarg('[a]')
            . ' -c:a libmp3lame -q:a 2 ' . escapeshellarg($dest);
        MediaService::run_with_timeout($cmd, (int) $timeout);
        if (is_file($dest) && filesize($dest) > 0) { return $dest; }
        @unlink($dest);
        return '';
    }

    /** SHA-256 of a local file (the fingerprint stored with a source video). */
    public static function file_hash($path){
        $h = @hash_file('sha256', (string) $path);
        return is_string($h) ? $h : '';
    }
}
