<?php
/**
 * Didit (https://docs.didit.me) as the age-verification vendor: one hosted session per attempt, the result by a signed
 * webhook. The workflow (selfie age estimation with ID fallback, "Adaptive Age Verification") is configured in the
 * Didit console; this class only opens sessions and reads outcomes.
 *
 * Also runs the creator ID check (IdentityVerification): a second workflow, didit_identity_workflow_id.
 *
 * app.ini [global]: didit_api_key, didit_workflow_id, didit_identity_workflow_id, didit_webhook_secret (the destination's secret_shared_key),
 * didit_sandbox_scenario (dev only: forces an outcome in the sandbox, e.g. "approved" / "declined").
 */
class DiditProvider implements AgeVerificationProvider {

    const BASE = 'https://verification.didit.me/v3';
    const WEBHOOK_TOLERANCE = 300;   // seconds between X-Timestamp and now

    public static function key(): string { return 'didit'; }

    /** A config value, '' when the key is absent (Main::config() warns on a missing key). */
    private static function cfg(string $key): string {
        $c = Main::get_config();
        return trim((string) ($c['global'][$key] ?? ''));
    }

    public static function start(int $user_id, string $return_url): array {
        if (self::cfg('didit_workflow_id') === '') { return array('ok' => false, 'ref' => '', 'url' => '', 'error' => 'Age verification is not configured.'); }
        return self::start_workflow($user_id, $return_url, self::cfg('didit_workflow_id'));
    }

    /** A session on any Didit workflow: the age check (start) or the creator ID check (IdentityVerification, didit_identity_workflow_id). Same answer shape as start(). */
    public static function start_workflow(int $user_id, string $return_url, string $workflow_id): array {
        if (self::cfg('didit_api_key') === '' || $workflow_id === '') {
            return array('ok' => false, 'ref' => '', 'url' => '', 'error' => 'Verification is not configured.');
        }
        $body = array(
            'workflow_id'     => $workflow_id,
            'vendor_data'     => (string) $user_id,   // our account id travels with the session and comes back on the webhook
            'callback'        => $return_url,
            'callback_method' => 'both',
        );
        $scenario = self::cfg('didit_sandbox_scenario');
        if ($scenario !== '') { $body['sandbox_scenario'] = $scenario; }
        list($code, $resp) = self::request('POST', '/session/', $body);
        if (($code !== 200 && $code !== 201) || empty($resp['session_id']) || empty($resp['url'])) {
            $why = '';
            if (is_array($resp)) {   // {"detail": "..."} or a field-keyed validation error such as {"sandbox_scenario": ["..."]}
                $first = $resp['detail'] ?? ($resp['message'] ?? ($resp['error'] ?? reset($resp)));
                $why = is_array($first) ? (string) reset($first) : (string) $first;
            }
            return array('ok' => false, 'ref' => '', 'url' => '', 'error' => 'Didit: HTTP ' . $code . ($why !== '' ? ' ' . $why : ''));
        }
        return array('ok' => true, 'ref' => (string) $resp['session_id'], 'url' => (string) $resp['url'], 'error' => '');
    }

    public static function verify_webhook(string $raw, array $headers): bool {
        return self::verify_webhook_with($raw, $headers, self::cfg('didit_webhook_secret'));
    }

    /**
     * X-Signature = HMAC-SHA256 (hex) of the exact raw body with the destination's shared secret; X-Timestamp must be
     * within WEBHOOK_TOLERANCE of now. Fails closed with no secret, so an unconfigured install never trusts a payload.
     */
    public static function verify_webhook_with(string $raw, array $headers, string $secret): bool {
        $h = array();
        foreach ($headers as $k => $v) { $h[strtolower((string) $k)] = (string) $v; }
        $sig = trim((string) ($h['x-signature'] ?? ''));
        $ts  = (int) ($h['x-timestamp'] ?? 0);
        if ($secret === '' || $sig === '' || $ts <= 0 || abs(time() - $ts) > self::WEBHOOK_TOLERANCE) { return false; }
        return hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($sig));
    }

    public static function parse_webhook(array $payload): array {
        return array(
            'ref'     => (string) ($payload['session_id'] ?? ''),
            'status'  => self::map_status((string) ($payload['status'] ?? '')),
            'user_id' => (int) ($payload['vendor_data'] ?? 0),
        );
    }

    public static function fetch_status(string $ref): string {
        if ($ref === '' || self::cfg('didit_api_key') === '') { return ''; }
        list($code, $resp) = self::request('GET', '/session/' . rawurlencode($ref) . '/decision/');
        return ($code === 200 && is_array($resp)) ? self::map_status((string) ($resp['status'] ?? '')) : '';
    }

    /**
     * The decision for an ID-check session, reduced to what the admin record keeps: array('status' => ours, 'name' =>
     * the full name read from the ID ('' when none), 'reason' => Didit's first warning, for a decline). Null when
     * Didit cannot be read. Images, document numbers and birth dates are never kept.
     */
    public static function decision(string $ref) {
        if ($ref === '' || self::cfg('didit_api_key') === '') { return null; }
        list($code, $resp) = self::request('GET', '/session/' . rawurlencode($ref) . '/decision/');
        if ($code !== 200 || !is_array($resp)) { return null; }
        $id = $resp['id_verifications'][0] ?? ($resp['id_verification'] ?? array());
        $name = is_array($id) ? trim((string) ($id['full_name'] ?? '')) : '';
        if ($name === '' && is_array($id)) { $name = trim((string) ($id['first_name'] ?? '') . ' ' . (string) ($id['last_name'] ?? '')); }
        $reason = '';
        foreach (array('id_verifications', 'liveness_checks', 'face_matches') as $k) {
            foreach ((array) ($resp[$k] ?? array()) as $part) {
                foreach ((array) ($part['warnings'] ?? array()) as $w) {
                    if ($reason === '' && !empty($w['short_description'])) { $reason = (string) $w['short_description']; }
                }
            }
        }
        return array('status' => self::map_status((string) ($resp['status'] ?? '')), 'name' => $name, 'reason' => $reason);
    }

    /** Didit session statuses -> ours. Only "Approved" verifies; the final negative states fail; everything else is still pending. */
    public static function map_status(string $s): string {
        if ($s === 'Approved') { return 'verified'; }
        if (in_array($s, array('Declined', 'Abandoned', 'Expired', 'Kyc Expired'), true)) { return 'failed'; }
        return 'pending';
    }

    /** [status code, decoded JSON|null]. Errors (>= 400 or transport) are logged with the path, never the key. */
    private static function request(string $method, string $path, $body = null): array {
        $ch = curl_init(self::BASE . $path);
        $headers = array('x-api-key: ' . self::cfg('didit_api_key'), 'Accept: application/json');
        $opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10);
        if ($body !== null) { $headers[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = json_encode($body); }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) { error_log('[didit] ' . $method . ' ' . $path . ' transport error: ' . $err); return array(0, null); }
        if ($code >= 400) { error_log('[didit] ' . $method . ' ' . $path . ' http ' . $code . ': ' . substr((string) $raw, 0, 300)); }
        $decoded = json_decode((string) $raw, true);
        return array($code, is_array($decoded) ? $decoded : null);
    }
}
