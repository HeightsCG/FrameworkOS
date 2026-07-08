<?php
/**
 * Content moderation — decides whether an image is adult/sexual content.
 *
 * Provider seam (per the compliance spec): this currently uses OpenAI's free,
 * image-capable "omni-moderation" endpoint. To swap vendors (AWS Rekognition,
 * Hive, Thorn), reimplement classify_image() — callers depend only on its shape.
 *
 * Returns ['ok'=>bool, 'adult'=>bool, 'minors'=>bool, 'score'=>float 0..1,
 *          'labels'=>string[], 'error'=>string].
 */
class ModerationService {

    const API             = 'https://api.openai.com/v1/moderations';
    const MODEL           = 'omni-moderation-latest';
    const ADULT_THRESHOLD = 0.5;   // sexual-content score at/above which we flag as adult
    const MINORS_THRESHOLD = 0.2;  // far more conservative — any real signal is escalated

    /** Classify an image by URL (a short-lived presigned URL the provider can fetch). */
    public static function classify_image($image_url){
        $key = (string) Main::config('global', 'openai_api_key');
        if ($key === '')                       { return array('ok' => false, 'error' => 'Moderation is not configured.'); }
        if (trim((string) $image_url) === '')  { return array('ok' => false, 'error' => 'No image URL.'); }

        $body = array(
            'model' => self::MODEL,
            'input' => array(array('type' => 'image_url', 'image_url' => array('url' => $image_url))),
        );
        $ch = curl_init(self::API);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $key, 'Content-Type: application/json'),
            CURLOPT_POSTFIELDS     => json_encode($body),
        ));
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) { error_log('[moderation] transport: ' . $err); return array('ok' => false, 'error' => 'transport error'); }
        if ($code >= 400)   { error_log('[moderation] http ' . $code . ': ' . substr($raw, 0, 400)); return array('ok' => false, 'error' => 'http ' . $code); }

        $d = json_decode($raw, true);
        $r = $d['results'][0] ?? null;
        if (!is_array($r)) { return array('ok' => false, 'error' => 'no result'); }

        $cats   = $r['categories'] ?? array();
        $scores = $r['category_scores'] ?? array();
        $sexual = (float) ($scores['sexual'] ?? 0);
        $minors_score = (float) ($scores['sexual/minors'] ?? 0);

        $adult  = !empty($cats['sexual']) || $sexual >= self::ADULT_THRESHOLD;
        $minors = !empty($cats['sexual/minors']) || $minors_score >= self::MINORS_THRESHOLD;

        $labels = array();
        foreach ($cats as $name => $on) { if ($on) { $labels[] = $name; } }
        if ($adult && !in_array('sexual', $labels, true)) { $labels[] = 'sexual'; }

        return array(
            'ok'     => true,
            'adult'  => ($adult || $minors),
            'minors' => $minors,
            'score'  => round($sexual, 4),
            'labels' => array_values(array_unique($labels)),
            'error'  => '',
        );
    }
}
