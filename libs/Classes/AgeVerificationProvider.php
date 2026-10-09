<?php
/**
 * The age-verification vendor, behind one small contract so it can be swapped (AgeVerification::$provider_class).
 * A provider never hands the app anything but a session reference, a hosted URL and a status: no images, no
 * documents, no birth dates reach this codebase.
 */
interface AgeVerificationProvider {

    /** Short stable name stored in age_verifications.provider ('didit'). */
    public static function key(): string;

    /**
     * Open a verification session for one account. $return_url is where the vendor sends the person afterwards.
     * Returns array('ok' => bool, 'ref' => provider session id, 'url' => hosted verification URL, 'error' => string).
     */
    public static function start(int $user_id, string $return_url): array;

    /** Is this result callback genuinely from the vendor? ($raw = exact request body, $headers = request headers.) */
    public static function verify_webhook(string $raw, array $headers): bool;

    /** The decoded callback, reduced to what we store: array('ref' => string, 'status' => 'pending'|'verified'|'failed', 'user_id' => int). */
    public static function parse_webhook(array $payload): array;

    /** Ask the vendor for a session's current status: 'pending'|'verified'|'failed', or '' when it cannot be read. */
    public static function fetch_status(string $ref): string;
}
