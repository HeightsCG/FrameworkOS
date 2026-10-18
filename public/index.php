<?php
$path = dirname(__DIR__);
if (is_file($path . '/vendor/autoload.php')) {
    require_once $path . '/vendor/autoload.php';
}
spl_autoload_register( function ($class) {
    
    $path = dirname(__DIR__);
    $lib = dirname($path);
    
    $sources = array(
        $path."/app/controllers/$class.php",
        $path."/app/controllers/api/$class.php",
        $path."/app/models/$class.php",
        $path."/app/$class.php",
        $path."/libs/Classes/$class.php",
    );
    
    foreach ($sources as $source) {
        if (file_exists($source)) {
            require_once $source;
        }
    } 
    
});
// Uncaught errors: log them and answer with the 500 page (JSON for /api), never a blank page or a stack trace.
set_exception_handler(function ($e) { Errors::server_error($e); });
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR), true)) { return; }
    @ini_set('memory_limit', (string) (memory_get_usage() + 32 * 1048576));   // room to render after "memory exhausted"
    Errors::server_error($err);
});
// Outside development, raw PHP errors (paths, fatal messages) are only logged, never printed into the page.
if (Main::get_environment() !== 'development') { ini_set('display_errors', '0'); ini_set('log_errors', '1'); }
$app = new Bootstrap();