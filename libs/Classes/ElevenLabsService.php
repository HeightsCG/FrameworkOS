<?php
/**
 * ElevenLabs client: Voice Design (a description -> candidate voices), saving a candidate as a
 * voice, and text to speech. One platform account (app.ini [global] elevenlabs_api_key); creators
 * pay in AI credits, and each creator only ever sees the voices recorded as theirs
 * (influencer_voices). Raw cURL, never throws: every method returns ['ok' => bool, 'error' => string, ...].
 */
class ElevenLabsService {

    const API          = 'https://api.elevenlabs.io';
    const DESIGN_MODEL = 'eleven_ttv_v3';     // voices designed for Eleven v3 speech
    const SPEECH_MODEL = 'eleven_v3';
    const FORMAT       = 'mp3_44100_128';
    const PREVIEW_MIN  = 100;                 // Voice Design preview text limits (characters)
    const PREVIEW_MAX  = 1000;
    const SPEECH_MAX   = 3000;                // characters per speech request; longer scripts go paragraph by paragraph

    private static function api_key(): string {
        $cfg = Main::get_config();
        return trim((string) ($cfg['global']['elevenlabs_api_key'] ?? ''));
    }

    public static function configured(): bool { return self::api_key() !== ''; }

    /** ['code', 'body' (raw), 'json' (decoded or null), 'error'] */
    private static function request($method, $path, $body = null, $timeout = 90): array {
        $ch = curl_init(self::API . $path);
        $headers = array('xi-api-key: ' . self::api_key());
        $opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => max(10, (int) $timeout), CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CUSTOMREQUEST => $method);
        if ($body !== null) { $headers[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { error_log('[elevenlabs] ' . $method . ' ' . $path . ' transport: ' . $err); return array('code' => 0, 'body' => '', 'json' => null, 'error' => 'Could not reach the voice service.'); }
        $json = json_decode((string) $raw, true);
        if ($code >= 400) { error_log('[elevenlabs] ' . $method . ' ' . $path . ' http ' . $code . ': ' . substr((string) $raw, 0, 300)); }
        return array('code' => $code, 'body' => (string) $raw, 'json' => is_array($json) ? $json : null, 'error' => '');
    }

    /** A message a creator can act on, from a failed response. Provider billing and permission text is never shown. */
    private static function explain(array $r, $fallback){
        if ($r['code'] === 0) { return $r['error']; }
        $d = is_array($r['json']) ? ($r['json']['detail'] ?? null) : null;
        $status = is_array($d) ? (string) ($d['status'] ?? ($d['code'] ?? '')) : '';
        $msg    = is_array($d) ? (string) ($d['message'] ?? '') : (is_string($d) ? $d : '');
        if ($r['code'] === 401 || $r['code'] === 403 || $status === 'missing_permissions' || strpos($status, 'quota') !== false || $r['code'] === 402) { return 'The voice service is not available right now. Please try again later.'; }
        if ($r['code'] === 429) { return 'The voice service is busy. Try again in a minute.'; }
        if ($status === 'voice_limit_reached' || stripos($msg, 'voice limit') !== false || stripos($msg, 'maximum amount of custom voices') !== false) { return 'No more voices can be saved right now. Remove a voice you no longer use and try again.'; }
        if ($r['code'] === 400 || $r['code'] === 422) { return ($msg !== '' && strlen($msg) < 200) ? $msg : $fallback; }
        return $fallback;
    }

    /**
     * Design voices from a description. Returns ['ok', 'previews' => [['generated_voice_id', 'bytes' (mp3), 'duration']], 'text'].
     * $text is the preview script (PREVIEW_MIN to PREVIEW_MAX characters).
     */
    public static function design($description, $text): array {
        if (!self::configured()) { return array('ok' => false, 'error' => 'Voice is not set up yet.'); }
        $r = self::request('POST', '/v1/text-to-voice/design?output_format=' . self::FORMAT, array(
            'voice_description' => (string) $description, 'text' => (string) $text, 'model_id' => self::DESIGN_MODEL), 150);
        if ($r['code'] !== 200 || empty($r['json']['previews'])) { return array('ok' => false, 'error' => self::explain($r, 'The voice could not be designed. Try a different description.')); }
        $out = array();
        foreach ((array) $r['json']['previews'] as $p) {
            $bytes = base64_decode((string) ($p['audio_base_64'] ?? ''), true);
            if ($bytes === false || $bytes === '' || empty($p['generated_voice_id'])) { continue; }
            $out[] = array('generated_voice_id' => (string) $p['generated_voice_id'], 'bytes' => $bytes, 'duration' => (float) ($p['duration_secs'] ?? 0));
        }
        if (empty($out)) { return array('ok' => false, 'error' => 'The voice could not be designed. Try a different description.'); }
        return array('ok' => true, 'error' => '', 'previews' => $out, 'text' => (string) ($r['json']['text'] ?? $text));
    }

    /** Keep a designed candidate as a voice. Returns ['ok', 'voice_id']. */
    public static function save_voice($generated_voice_id, $name, $description): array {
        if (!self::configured()) { return array('ok' => false, 'error' => 'Voice is not set up yet.'); }
        $r = self::request('POST', '/v1/text-to-voice', array('voice_name' => mb_substr((string) $name, 0, 100),
            'voice_description' => mb_substr((string) $description, 0, 1000), 'generated_voice_id' => (string) $generated_voice_id), 60);
        if ($r['code'] !== 200 || empty($r['json']['voice_id'])) { return array('ok' => false, 'error' => self::explain($r, 'That voice could not be saved. Design it again.')); }
        return array('ok' => true, 'error' => '', 'voice_id' => (string) $r['json']['voice_id']);
    }

    /** Remove a saved voice from the platform account (frees its slot). Returns ['ok']. */
    public static function delete_voice($voice_id): array {
        if (!self::configured() || (string) $voice_id === '') { return array('ok' => false, 'error' => 'Voice is not set up yet.'); }
        $r = self::request('DELETE', '/v1/voices/' . rawurlencode((string) $voice_id), null, 30);
        return ($r['code'] === 200 || $r['code'] === 404) ? array('ok' => true, 'error' => '') : array('ok' => false, 'error' => self::explain($r, 'The voice could not be removed.'));
    }

    /** Speak text with a saved voice (Eleven v3; inline audio tags like [whispers] shape the delivery). Returns ['ok', 'bytes' (mp3)]. */
    public static function speak($voice_id, $text, $seed = 0): array {
        if (!self::configured()) { return array('ok' => false, 'error' => 'Voice is not set up yet.'); }
        $body = array('text' => (string) $text, 'model_id' => self::SPEECH_MODEL);
        if ((int) $seed > 0) { $body['seed'] = (int) $seed; }
        $r = self::request('POST', '/v1/text-to-speech/' . rawurlencode((string) $voice_id) . '?output_format=' . self::FORMAT, $body, 180);
        if ($r['code'] !== 200 || $r['body'] === '' || $r['json'] !== null) { return array('ok' => false, 'error' => self::explain($r, 'The speech could not be generated. Try again.')); }
        return array('ok' => true, 'error' => '', 'bytes' => $r['body']);
    }
}
