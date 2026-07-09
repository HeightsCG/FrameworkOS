<?php
class CSRF {

    const SESSION_KEY = 'csrf_token';
    const FIELD       = 'csrf_token';
    const HEADER      = 'HTTP_X_CSRF_TOKEN';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="'
             . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function meta(): string
    {
        return '<meta name="csrf-token" content="'
             . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function validate(): bool
    {
        $sessionToken = Session::get(self::SESSION_KEY);
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }
        $requestToken = '';
        if (isset($_POST[self::FIELD])) {
            $requestToken = (string) $_POST[self::FIELD];
        } elseif (isset($_SERVER[self::HEADER])) {
            $requestToken = (string) $_SERVER[self::HEADER];
        }
        if ($requestToken === '') {
            return false;
        }
        return hash_equals($sessionToken, $requestToken);
    }

}
