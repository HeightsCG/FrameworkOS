<?php
/**
 * Authenticated symmetric encryption for sensitive fields at rest (e.g. co-star legal
 * name / DOB). AES-256-GCM with a random IV per value; key from app.ini `app_crypto_key`
 * (base64, 32 bytes). Output layout: IV(12) || TAG(16) || ciphertext, as raw bytes.
 */
class Crypto {

    const CIPHER = 'aes-256-gcm';

    private static function key(){
        $b64 = (string) Main::config('global', 'app_crypto_key');
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('app_crypto_key missing or not a base64 32-byte key');
        }
        return $key;
    }

    /** Encrypt a string → raw bytes (for a VARBINARY column). '' / null → null. */
    public static function encrypt($plaintext){
        if ($plaintext === null || $plaintext === '') { return null; }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt((string) $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) { throw new RuntimeException('encryption failed'); }
        return $iv . $tag . $ct;
    }

    /** Decrypt raw bytes back to the string. null/short → ''. Returns '' on tamper/failure. */
    public static function decrypt($blob){
        if ($blob === null || strlen((string) $blob) < 29) { return ''; }
        $iv  = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $ct  = substr($blob, 28);
        $pt  = openssl_decrypt($ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return ($pt === false) ? '' : $pt;
    }
}
