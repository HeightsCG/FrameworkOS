<?php
/**
 * Fanvue API client (https://api.fanvue.com) + OAuth 2.0 (PKCE, client_secret_basic).
 * Creators authorize once; tokens live in user_fanvue_accounts (encrypted) and are
 * refreshed here transparently. Credentials from app.ini (environment section):
 * fanvue_client_id, fanvue_client_secret.
 *
 * Docs: https://api.fanvue.com/docs/authentication/implementation-guide
 */
class FanvueService {

    const API_BASE    = 'https://api.fanvue.com';
    const AUTH_URL    = 'https://auth.fanvue.com/oauth2/auth';
    const TOKEN_URL   = 'https://auth.fanvue.com/oauth2/token';
    const API_VERSION = '2025-06-26';
    const SCOPES      = 'openid offline_access offline read:self read:post write:post read:media write:media';

    // ---- config -------------------------------------------------------------

    private static function cfg($key): string {
        $cfg = Main::get_config();
        $env = Main::get_environment();
        if (!empty($cfg[$env][$key]))    { return (string) $cfg[$env][$key]; }
        if (!empty($cfg['global'][$key])) { return (string) $cfg['global'][$key]; }
        return '';
    }

    public static function client_id(): string     { return self::cfg('fanvue_client_id'); }
    public static function client_secret(): string { return self::cfg('fanvue_client_secret'); }
    public static function configured(): bool      { return self::client_id() !== '' && self::client_secret() !== ''; }
    public static function redirect_uri(): string  { return Main::get_base_domain() . '/account/fanvue_callback'; }

    // ---- OAuth ----------------------------------------------------------------

    /**
     * Build the authorize URL. Returns [url, state, code_verifier]; the caller keeps
     * state + verifier in the session and checks them on the callback.
     */
    public static function authorize_url(): array {
        $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state     = bin2hex(random_bytes(16));
        $url = self::AUTH_URL . '?' . http_build_query(array(
            'client_id'             => self::client_id(),
            'redirect_uri'          => self::redirect_uri(),
            'response_type'         => 'code',
            'scope'                 => self::SCOPES,
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ), '', '&', PHP_QUERY_RFC3986);
        return array($url, $state, $verifier);
    }

    /** Exchange the callback code for tokens. Returns the token array or null. */
    public static function exchange_code($code, $verifier){
        return self::token_request(array(
            'grant_type'    => 'authorization_code',
            'code'          => (string) $code,
            'redirect_uri'  => self::redirect_uri(),
            'code_verifier' => (string) $verifier,
        ));
    }

    /** Refresh an access token (Fanvue rotates the refresh token — save what comes back). */
    public static function refresh($refresh_token){
        return self::token_request(array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => (string) $refresh_token,
        ));
    }

    private static function token_request(array $fields){
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Basic ' . base64_encode(self::client_id() . ':' . self::client_secret()),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ),
        ));
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { error_log('[fanvue] token transport error: ' . $err); return null; }
        $body = json_decode($raw, true);
        if ($code >= 400 || !is_array($body) || empty($body['access_token'])) {
            error_log('[fanvue] token ' . ($fields['grant_type'] ?? '') . ' http ' . $code . ': ' . substr((string) $raw, 0, 300));
            return null;
        }
        return $body;
    }

    // ---- authenticated API access ------------------------------------------

    /**
     * A valid access token for the user, refreshing (and persisting) if it is within
     * 2 minutes of expiry. '' when not connected or refresh failed (the account is
     * marked with last_error so Settings can show it).
     */
    public static function access_token_for(array $account): string {
        if (($account['status'] ?? '') !== 'connected') { return ''; }
        $token = (string) ($account['access_token'] ?? '');
        $exp   = strtotime((string) ($account['expires_at'] ?? '')) ?: 0;
        if ($token !== '' && $exp > time() + 120) { return $token; }

        $refresh = (string) ($account['refresh_token'] ?? '');
        if ($refresh === '') { return ''; }
        $tokens = self::refresh($refresh);
        $m = new FanvueAccountsModel();
        if (!$tokens) {
            $m->set_error((int) $account['user_id'], 'Fanvue session expired. Reconnect in Settings > Integrations.');
            return '';
        }
        $m->save_tokens((int) $account['user_id'], $tokens);
        return (string) $tokens['access_token'];
    }

    /** Low-level API call. Returns [status_code, decoded_body|null, raw]. */
    public static function request($access_token, $method, $path, $body = null, $extra_headers = array()): array {
        $ch = curl_init(self::API_BASE . $path);
        $headers = array_merge(array(
            'Authorization: Bearer ' . $access_token,
            'X-Fanvue-API-Version: ' . self::API_VERSION,
            'Accept: application/json',
        ), $extra_headers);
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 30,
        );
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            error_log('[fanvue] ' . $method . ' ' . $path . ' transport error: ' . $err);
            return array(0, null, '');
        }
        if ($code >= 400) {
            error_log('[fanvue] ' . $method . ' ' . $path . ' http ' . $code . ': ' . substr((string) $raw, 0, 400));
        }
        return array($code, json_decode($raw, true), (string) $raw);
    }

    /** A readable error line from a failed response body. */
    public static function error_text($code, $body): string {
        if (is_array($body)) {
            foreach (array('message', 'error', 'detail') as $k) {
                if (!empty($body[$k]) && is_string($body[$k])) { return 'Fanvue: ' . $body[$k]; }
            }
        }
        return 'Fanvue request failed (HTTP ' . (int) $code . ')';
    }

    /** GET /users/me */
    public static function whoami($access_token){
        list($code, $body) = self::request($access_token, 'GET', '/users/me');
        return ($code === 200 && is_array($body)) ? $body : null;
    }

    /**
     * Upload a file into the creator's Fanvue vault via the multipart session API.
     * Returns the media uuid, or throws with a readable message. Waits (≤ $wait_sec)
     * for Fanvue to finish processing so the uuid can be attached to a post.
     */
    public static function upload_media($access_token, $bytes, $filename, $media_type, $wait_sec = 60): string {
        $size = strlen($bytes);
        if ($size <= 0) { throw new RuntimeException('Fanvue upload: empty file'); }
        $name = pathinfo($filename, PATHINFO_FILENAME) ?: 'upload';

        list($code, $sess) = self::request($access_token, 'POST', '/media/uploads', array(
            'name'      => mb_substr($name, 0, 255),
            'filename'  => mb_substr($filename, 0, 255),
            'mediaType' => $media_type,
            'sizeBytes' => $size,
        ));
        if ($code !== 200 && $code !== 201 || !is_array($sess) || empty($sess['uploadId'])) {
            throw new RuntimeException(self::error_text($code, $sess));
        }
        $upload_id  = (string) $sess['uploadId'];
        $media_uuid = (string) ($sess['mediaUuid'] ?? '');
        $part_size  = max(1, (int) ($sess['partSize'] ?? $size));
        $total      = (int) ($sess['totalParts'] ?? 0);
        if ($total <= 0) { $total = (int) ceil($size / $part_size); }

        $parts = array();
        for ($n = 1; $n <= $total; $n++) {
            list($ucode, $url_body, $raw) = self::request($access_token, 'GET', '/media/uploads/' . rawurlencode($upload_id) . '/parts/' . $n . '/url');
            $url = is_string($url_body) ? $url_body : (is_array($url_body) ? (string) ($url_body['url'] ?? '') : trim($raw, "\" \n"));
            if ($ucode !== 200 || $url === '') { throw new RuntimeException('Fanvue upload: could not get part URL'); }

            $chunk = substr($bytes, ($n - 1) * $part_size, $part_size);
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_POSTFIELDS     => $chunk,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => array('Content-Type: application/octet-stream', 'Content-Length: ' . strlen($chunk)),
            ));
            $resp  = curl_exec($ch);
            $pcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($resp === false || $pcode < 200 || $pcode >= 300) {
                throw new RuntimeException('Fanvue upload: part ' . $n . ' failed (HTTP ' . $pcode . ')');
            }
            $etag = '';
            if (preg_match('/^etag:\s*(.+)$/mi', (string) $resp, $m)) { $etag = trim($m[1]); }
            $part = array('PartNumber' => $n);
            if ($etag !== '') { $part['ETag'] = $etag; }
            $parts[] = $part;
        }

        list($ccode, $done) = self::request($access_token, 'PATCH', '/media/uploads/' . rawurlencode($upload_id), array('parts' => $parts));
        if ($ccode !== 200 || !is_array($done)) { throw new RuntimeException(self::error_text($ccode, $done)); }
        if (($done['status'] ?? '') === 'error') { throw new RuntimeException('Fanvue rejected the media upload'); }

        // Wait for processing so the post can reference it.
        $deadline = time() + max(0, (int) $wait_sec);
        $status   = (string) ($done['status'] ?? 'processing');
        while ($status !== 'ready' && $media_uuid !== '' && time() < $deadline) {
            sleep(3);
            list($mcode, $media) = self::request($access_token, 'GET', '/media/' . rawurlencode($media_uuid));
            if ($mcode === 200 && is_array($media)) { $status = (string) ($media['status'] ?? $status); }
            if ($status === 'error') { throw new RuntimeException('Fanvue could not process the media'); }
        }
        return $media_uuid;
    }

    /**
     * POST /posts. $audience: 'subscribers' | 'followers-and-subscribers'.
     * $price_cents: 0 for free, else ≥ 300. $publish_at: ISO 8601 or null.
     * Returns the created post (uuid, ...) or throws.
     */
    public static function create_post($access_token, $text, array $media_uuids, $audience, $price_cents = 0, $publish_at = null): array {
        $body = array(
            'audience' => ($audience === 'subscribers') ? 'subscribers' : 'followers-and-subscribers',
        );
        $text = trim((string) $text);
        if ($text !== '') { $body['text'] = mb_substr($text, 0, 5000); }
        if (!empty($media_uuids)) { $body['mediaUuids'] = array_values($media_uuids); }
        if ((int) $price_cents >= 300 && !empty($media_uuids)) { $body['price'] = (int) $price_cents; }
        if (!empty($publish_at)) { $body['publishAt'] = (string) $publish_at; }

        list($code, $post) = self::request($access_token, 'POST', '/posts', $body,
            array('Idempotency-Key: cls-' . bin2hex(random_bytes(8))));
        if (($code !== 200 && $code !== 201) || !is_array($post) || empty($post['uuid'])) {
            throw new RuntimeException(self::error_text($code, $post));
        }
        return $post;
    }

}
