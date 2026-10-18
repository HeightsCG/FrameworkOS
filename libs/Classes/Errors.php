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

    const SERVER_ERROR_MESSAGE = 'Something went wrong. Please try again.';

    /**
     * Last stop for an uncaught error (public/index.php): log it with the URI and user, then answer with the 500 page,
     * or JSON for API callers. Never shows the error itself, whatever display_errors says.
     */
    public static function server_error(\Throwable|array $error): void
    {
        try {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $uid = (session_status() === PHP_SESSION_ACTIVE) ? (int) ($_SESSION['user_id'] ?? 0) : 0;
            if ($error instanceof \Throwable) {
                $what = get_class($error) . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine();
            } else {
                $what = 'Fatal: ' . (string) ($error['message'] ?? '') . ' in ' . (string) ($error['file'] ?? '') . ':' . (int) ($error['line'] ?? 0);
            }
            error_log('[uncaught] ' . mb_substr(str_replace(array("\r", "\n"), ' ', $what), 0, 1000) . ' | uri=' . $uri . ' | user=' . $uid);
        } catch (\Throwable $e) { }
        // whatever was buffered so far is half a page: drop it
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (headers_sent()) { return; }   // the page already started printing; nothing safe left to send
        $first = strtolower((string) (Main::get_url()[0] ?? ''));
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $json = in_array($first, array('api', 'mcp', 'webhook', 'csrf-token', 'domain-check'), true)
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || (strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false);
        header_remove('Location');
        header('Cache-Control: no-store');
        if ($json) {
            // /api answers 200 like every other API reply (Controller::jsonError): the pages read success:false and show
            // the message; a 500 would skip their callback and leave the button spinning. Other JSON routes get a 500.
            // Like Controller::jsonError, /api sends no JSON Content-Type: jQuery would parse it and the pages' JSON.parse() would throw.
            http_response_code($first === 'api' ? 200 : 500);
            if ($first !== 'api') { header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(array('success' => false, 'message' => self::SERVER_ERROR_MESSAGE));
            return;
        }
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        $file = Main::lib_path() . '/Layout/server_error.php';
        try {
            if (file_exists($file)) { require $file; return; }
        } catch (\Throwable $e) { }
        echo 'Something went wrong. Please try again.';
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
