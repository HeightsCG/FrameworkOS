<?php
class Database extends PDO {

    public function __construct() {
        $env     = Main::get_environment();
        $config  = Main::get_config();
        $db_type = $config[$env]['db_type'];
        $db_name = $config[$env]['db_name'];
        $db_user = $config[$env]['db_user'];
        $db_pass = $config[$env]['db_pass'];
        $db_host = $config[$env]['db_host'];
        $dsn = $db_type.':host='.$db_host.';dbname='.$db_name.';charset=utf8mb4';
        $options = array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Native (server-side) prepares — not emulated string interpolation.
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Let the driver return native column types (int/float) rather than
            // stringifying everything.
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            // All timestamps are stored and compared in UTC (PHP is set to UTC in
            // Bootstrap); display layers convert to the viewer's timezone. Keeps
            // NOW()/CURDATE() aligned with PHP's date() so comparisons are consistent.
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        );
        // Non-persistent: persistent PDO connections leak transaction/lock state
        // across requests and interact badly with native prepares.
        parent::__construct($dsn, $db_user, $db_pass, $options);
    }
    
}
