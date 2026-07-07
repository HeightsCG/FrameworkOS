<?php
/**
 * AI image generation via the OpenAI Images API. Returns raw PNG bytes for the
 * caller to ingest through the normal media pipeline. Key from app.ini.
 */
class ImageGenService {

    const API   = 'https://api.openai.com/v1/images/generations';
    const MODEL = 'gpt-image-1';

    /** Valid sizes per size-key. */
    public static function dimensions($key){
        switch ($key) {
            case 'portrait':  return '1024x1536';
            case 'landscape': return '1536x1024';
            default:          return '1024x1024';
        }
    }

    /** Generate one image. Returns ['ok'=>bool, 'bytes'|'error']. */
    public static function generate($prompt, $size = '1024x1024'){
        $key = (string) Main::config('global', 'openai_api_key');
        if ($key === '') { return array('ok' => false, 'error' => 'Image generation is not configured.'); }

        $body = array(
            'model'   => self::MODEL,
            'prompt'  => $prompt,
            'size'    => $size,
            'quality' => 'high',
            'n'       => 1,
        );
        $ch = curl_init(self::API);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 150,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ),
            CURLOPT_POSTFIELDS => json_encode($body),
        ));
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) { error_log('[imagegen] transport: ' . $err); return array('ok' => false, 'error' => 'Generation failed. Try again.'); }
        if ($code >= 400)   { error_log('[imagegen] http ' . $code . ': ' . substr($raw, 0, 600)); return array('ok' => false, 'error' => self::friendly($code, $raw)); }

        $d   = json_decode($raw, true);
        $b64 = $d['data'][0]['b64_json'] ?? '';
        if ($b64 === '') { error_log('[imagegen] no image in response: ' . substr($raw, 0, 400)); return array('ok' => false, 'error' => 'Generation returned no image. Try again.'); }
        $bytes = base64_decode($b64, true);
        if ($bytes === false || strlen($bytes) < 100) { return array('ok' => false, 'error' => 'Generation returned an invalid image.'); }
        return array('ok' => true, 'bytes' => $bytes);
    }

    private static function friendly($code, $raw){
        $d   = json_decode($raw, true);
        $msg = (string) ($d['error']['message'] ?? '');
        if (stripos($msg, 'safety') !== false || stripos($msg, 'content policy') !== false || stripos($msg, 'moderation') !== false) {
            return 'That prompt was rejected by the safety system. Try describing something different.';
        }
        if ($code === 429) { return 'Rate limited — wait a moment and try again.'; }
        return 'Generation failed. Try again.';
    }
}
