<?php
/**
 * Thin wrapper around the Post for Me API (https://api.postforme.dev/v1).
 * Handles the social-account connection layer + publishing. Bearer key from app.ini.
 */
class PostForMeService {

    const BASE = 'https://api.postforme.dev/v1';

    private static function key(): string
    {
        return (string) Main::config('global', 'post_for_me_api_key');
    }

    /**
     * Low-level request. Returns [status_code, decoded_body|null].
     */
    private static function request($method, $path, $body = null): array
    {
        $ch = curl_init(self::BASE . $path);
        $headers = array(
            'Authorization: Bearer ' . self::key(),
            'Accept: application/json',
        );
        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 20,
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
            error_log('[postforme] ' . $method . ' ' . $path . ' transport error: ' . $err);
            return array(0, null);
        }
        if ($code >= 400) {
            error_log('[postforme] ' . $method . ' ' . $path . ' http ' . $code . ': ' . $raw);
        }
        return array($code, json_decode($raw, true));
    }

    /** Generate a hosted OAuth/connect URL for one platform, scoped to our user via external_id. */
    public static function create_auth_url($platform, $external_id, $permissions = array('posts'), $platform_data = null)
    {
        $payload = array(
            'platform'    => $platform,
            'external_id' => (string) $external_id,
            'permissions' => $permissions,
        );
        if (!empty($platform_data)) {
            $payload['platform_data'] = $platform_data;
        }
        list($code, $body) = self::request('POST', '/social-accounts/auth-url', $payload);
        if ($code === 200 || $code === 201) {
            return isset($body['url']) ? (string) $body['url'] : '';
        }
        return '';
    }

    /** All social accounts for one of our users (by external_id). Returns array of account rows. */
    public static function get_accounts($external_id): array
    {
        list($code, $body) = self::request('GET', '/social-accounts?external_id=' . urlencode((string) $external_id) . '&limit=100');
        if (($code === 200) && isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }
        return array();
    }

    /** One social account by Post for Me id. Returns the account row or null. */
    public static function get_account($id)
    {
        list($code, $body) = self::request('GET', '/social-accounts/' . urlencode((string) $id));
        return ($code === 200 && is_array($body)) ? $body : null;
    }

    /** Disconnect a connected account. Returns true on success. */
    public static function disconnect($id): bool
    {
        list($code) = self::request('POST', '/social-accounts/' . urlencode((string) $id) . '/disconnect');
        return ($code >= 200 && $code < 300);
    }

    /** Get a signed upload URL + the media_url to reference in a post. Returns [media_url, upload_url] or null. */
    public static function create_upload_url()
    {
        list($code, $body) = self::request('POST', '/media/create-upload-url', array());
        if (($code === 200 || $code === 201) && isset($body['media_url'], $body['upload_url'])) {
            return array($body['media_url'], $body['upload_url']);
        }
        return null;
    }

    /**
     * Create (publish/schedule/draft) a post.
     * $media_urls: array of media_url strings. $scheduled_at: ISO-8601 string or null (null = publish now).
     * Returns the created post row or null.
     */
    public static function create_post($social_account_ids, $caption, $media_urls = array(), $scheduled_at = null, $is_draft = false)
    {
        $payload = array(
            'caption'         => (string) $caption,
            'social_accounts' => array_values($social_account_ids),
        );
        if (!empty($media_urls)) {
            $payload['media'] = array_map(function ($u) { return array('url' => $u); }, $media_urls);
        }
        if (!empty($scheduled_at)) {
            $payload['scheduled_at'] = $scheduled_at;
        }
        if ($is_draft) {
            $payload['isDraft'] = true;
        }
        list($code, $body) = self::request('POST', '/social-posts', $payload);
        return (($code === 200 || $code === 201) && is_array($body)) ? $body : null;
    }

    /** Fetch a post (for status). Returns the post row or null. */
    public static function get_post($id)
    {
        list($code, $body) = self::request('GET', '/social-posts/' . urlencode((string) $id));
        return ($code === 200 && is_array($body)) ? $body : null;
    }
}
