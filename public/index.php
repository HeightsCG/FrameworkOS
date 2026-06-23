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
$app = new Bootstrap();