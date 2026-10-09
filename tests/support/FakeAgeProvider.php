<?php
/** Test double for AgeVerificationProvider: no network, outcomes set by the test. */
class FakeAgeProvider implements AgeVerificationProvider {
    public static $counter = 0;
    public static $last_ref = '';
    public static $last_return = '';
    public static $fail_start = false;
    public static $fetched = '';   // what fetch_status() answers

    public static function key(): string { return 'fake'; }

    public static function start(int $user_id, string $return_url): array {
        if (self::$fail_start) { return array('ok' => false, 'ref' => '', 'url' => '', 'error' => 'fake down'); }
        self::$counter++; self::$last_ref = 'fake-' . self::$counter; self::$last_return = $return_url;
        return array('ok' => true, 'ref' => self::$last_ref, 'url' => 'https://fake.example/verify/' . self::$last_ref, 'error' => '');
    }

    public static function verify_webhook(string $raw, array $headers): bool { return (string) ($headers['X-Fake'] ?? '') === 'ok'; }

    public static function parse_webhook(array $payload): array {
        return array('ref' => (string) ($payload['ref'] ?? ''), 'status' => (string) ($payload['status'] ?? 'pending'), 'user_id' => (int) ($payload['user_id'] ?? 0));
    }

    public static function fetch_status(string $ref): string { return self::$fetched; }
}
