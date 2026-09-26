<?php
/**
 * RFC 6238 time-based one-time passwords (TOTP) + RFC 4648 Base32.
 *
 * Self-contained (no external dependency). Compatible with Google
 * Authenticator, Authy, 1Password, etc.: SHA1, 6 digits, 30-second period.
 * The QR code is rendered client-side from otpauth_uri(); this service only
 * generates/validates secrets and codes.
 */
class TotpService {

    const DIGITS   = 6;
    const PERIOD   = 30;
    const ALGO     = 'sha1';
    const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh Base32 secret (default 20 random bytes → 32 chars). */
    public static function generate_secret(int $bytes = 20): string
    {
        return self::base32_encode(random_bytes($bytes));
    }

    /** The TOTP code for a Base32 secret at a given unix time (defaults to now). */
    public static function code_at(string $secret, ?int $time = null): string
    {
        $time    = $time ?? time();
        $counter = (int) floor($time / self::PERIOD);

        $key    = self::base32_decode($secret);
        $binctr = pack('N*', 0) . pack('N*', $counter); // 8-byte big-endian counter
        $hash   = hash_hmac(self::ALGO, $binctr, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part   = substr($hash, $offset, 4);
        $value  = (unpack('N', $part)[1]) & 0x7FFFFFFF;

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a user-supplied code against the secret, allowing a ±$window step
     * drift for clock skew. Constant-time comparison.
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if ($secret === '' || !preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code_at($secret, $now + ($i * self::PERIOD)), $code)) {
                return true;
            }
        }
        return false;
    }

    /** The 30-second step a valid code belongs to (±$window for clock skew), or null. Callers store it so a code works once. */
    public static function matched_step(string $secret, string $code, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if ($secret === '' || !preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $now = time();
        for ($i = -$window; $i <= $window; $i++) {
            $t = $now + ($i * self::PERIOD);
            if (hash_equals(self::code_at($secret, $t), $code)) {
                return (int) floor($t / self::PERIOD);
            }
        }
        return null;
    }

    /** otpauth:// URI for enrollment (encode into a QR client-side). */
    public static function otpauth_uri(string $secret, string $label, string $issuer): string
    {
        $params = http_build_query(array(
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => strtoupper(self::ALGO),
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ));
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label) . '?' . $params;
    }

    public static function base32_encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function base32_decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        if ($b32 === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }
        return $out;
    }
}
