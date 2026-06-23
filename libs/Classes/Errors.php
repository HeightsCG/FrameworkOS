<?php
class Errors {

    public static function bad_request($message = ''): void
    {
        if (!headers_sent()) {
            http_response_code(400);
        }
        // Log the specifics server-side; never disclose them to the client.
        if ($message !== '') {
            error_log('[bad_request] ' . (string) $message);
        }
        $file = self::bad_request_file();
        if (file_exists($file)) {
            require $file;
        } else {
            echo 'Bad Request';
        }
    }

    public static function bad_request_file(): string
    {
        return Main::lib_path().'/Layout/bad_request.php';
    }

    public static function access_denied(): void
    {
        if (!headers_sent()) {
            http_response_code(403);
        }
        $file = self::access_denied_file();
        if (file_exists($file)) {
            require $file;
        } else {
            echo 'Forbidden';
        }
    }

    public static function access_denied_file(): string
    {
        return Main::lib_path().'/Layout/access_denied.php';
    }

    public static function page_not_found(): void
    {
        if (!headers_sent()) {
            http_response_code(404);
        }
        $file = self::page_not_found_file();
        if (file_exists($file)) {
            require $file;
        }
    }

    public static function page_not_found_file(): string
    {
        return Main::lib_path().'/Layout/page_not_found.php';
    }

}
