<?php
/**
 * Sign in / sign up with Google: OAuth 2.0 authorization code + PKCE, server side. The account's identity comes
 * from Google's userinfo endpoint, called with the access token from the back-channel code exchange (the token
 * never touches the browser, so no id_token signature check is needed). Scopes: openid email profile.
 *
 * Config (app/config/app.ini, environment section first, then [global], then the GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET
 * environment variables): google_client_id, google_client_secret.
 * Without them configured() is false and the "Continue with Google" button is not shown.
 * The routes are AccountController::google_startAction / google_callbackAction.
 */
class GoogleAuth {

    const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    const SCOPES       = 'openid email profile';

    /** Test hook: callable(code, verifier) → userinfo array|null, used instead of calling Google (dev tests only). */
    public static $stub = null;

    private static function cfg($key): string {
        $cfg = Main::get_config();
        $env = Main::get_environment();
        if (!empty($cfg[$env][$key]))     { return (string) $cfg[$env][$key]; }
        if (!empty($cfg['global'][$key])) { return (string) $cfg['global'][$key]; }
        return (string) getenv(strtoupper($key));   // GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET from the environment, if set there instead
    }

    public static function client_id(): string     { return self::cfg('google_client_id'); }
    public static function client_secret(): string { return self::cfg('google_client_secret'); }
    /**
     * Keys are set AND Google can send people back here: Google only accepts an https return address (or localhost),
     * so on a plain-http host such as dev the button stays hidden instead of ending on Google's "Access blocked".
     */
    public static function configured(): bool {
        if (self::client_id() === '' || self::client_secret() === '') { return false; }
        $u = parse_url(self::redirect_uri());
        return ($u['scheme'] ?? '') === 'https' || in_array(strtolower((string) ($u['host'] ?? '')), array('localhost', '127.0.0.1'), true);
    }
    public static function redirect_uri(): string  { return Main::get_base_domain() . '/account/google_callback'; }

    /** [url, state, verifier]: the caller keeps state + verifier in the session and checks them on the callback. */
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
            'prompt'                => 'select_account',
        ), '', '&', PHP_QUERY_RFC3986);
        return array($url, $state, $verifier);
    }

    /**
     * Exchange the callback code and read who signed in. Returns ['sub', 'email', 'email_verified', 'given_name',
     * 'family_name', 'picture'] or null (logged).
     */
    public static function identify($code, $verifier) {
        if (self::$stub !== null) { return call_user_func(self::$stub, $code, $verifier); }
        $tok = self::http(self::TOKEN_URL, array(
            'grant_type'    => 'authorization_code',
            'code'          => (string) $code,
            'redirect_uri'  => self::redirect_uri(),
            'client_id'     => self::client_id(),
            'client_secret' => self::client_secret(),
            'code_verifier' => (string) $verifier,
        ));
        if (!$tok || empty($tok['access_token'])) { return null; }
        $me = self::http(self::USERINFO_URL, null, (string) $tok['access_token']);
        if (!$me || empty($me['sub'])) { return null; }
        return array(
            'sub'            => (string) $me['sub'],
            'email'          => strtolower(trim((string) ($me['email'] ?? ''))),
            'email_verified' => !empty($me['email_verified']),
            'given_name'     => trim((string) ($me['given_name'] ?? '')),
            'family_name'    => trim((string) ($me['family_name'] ?? '')),
            'picture'        => (string) ($me['picture'] ?? ''),
        );
    }

    /** POST a form ($fields) or GET with a bearer token; decoded JSON or null. */
    private static function http($url, $fields = null, $bearer = '') {
        $ch = curl_init($url);
        $headers = array('Accept: application/json');
        if ($bearer !== '') { $headers[] = 'Authorization: Bearer ' . $bearer; }
        $opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers);
        if ($fields !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = http_build_query($fields); }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { error_log('[google] transport error: ' . $err); return null; }
        $body = json_decode((string) $raw, true);
        if ($code >= 400 || !is_array($body)) { error_log('[google] http ' . $code . ' from ' . parse_url($url, PHP_URL_HOST) . ': ' . substr((string) $raw, 0, 300)); return null; }
        return $body;
    }

    /**
     * Use the Google profile photo as the account's photo, once, only when it has none. Same path as an upload
     * (re-encoded, moderated, stored on S3). Never fails the sign-in.
     */
    public static function import_avatar($user_id, $picture_url): void {
        try {
            $user_id = (int) $user_id;
            $host = strtolower((string) parse_url((string) $picture_url, PHP_URL_HOST));
            if ($user_id <= 0 || !preg_match('/(^|\.)googleusercontent\.com$/', $host) || !S3Service::configured()) { return; }
            $cp = new CreatorProfileModel();
            $row = $cp->get_for_user($user_id);
            if (is_array($row) && trim((string) ($row['avatar_url'] ?? '')) !== '') { return; }
            $url = preg_replace('/=s\d+(-c)?$/', '=s400-c', (string) $picture_url);   // a 400px square instead of the 96px default
            $ch = curl_init($url);
            curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS));
            $bytes = curl_exec($ch);
            $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($bytes) || $code !== 200 || strlen($bytes) === 0 || strlen($bytes) > 5 * 1024 * 1024) { return; }
            $tmp = tempnam(sys_get_temp_dir(), 'gavt');
            file_put_contents($tmp, $bytes);
            $info = @getimagesize($tmp);
            $ext_map = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
            if ($info === false || !isset($ext_map[$info['mime']])) { @unlink($tmp); return; }
            $img = ProfileImage::prepare($tmp, $info['mime']);   // no metadata, and moderated
            if ($img['ok']) {
                $s3 = S3Service::upload_file('creator/u' . $user_id . '_avatar_' . bin2hex(random_bytes(8)) . '.' . $ext_map[$info['mime']], $img['path'], $info['mime']);
                if ($s3 !== '') { $cp->set_image($user_id, 'avatar_url', $s3); }
                if ($img['path'] !== $tmp) { @unlink($img['path']); }
            }
            @unlink($tmp);
        } catch (\Throwable $e) {
            error_log('[google] avatar import: ' . $e->getMessage());
        }
    }
}
