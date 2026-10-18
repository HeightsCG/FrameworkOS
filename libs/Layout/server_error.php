<?php
/** 500 page, shown by the global error handler in public/index.php. Same look as the 404 (error_page.php). */
$ep_code = '500'; $ep_title = 'Something Went Wrong'; $ep_text = "We couldn't load this page. Please try again in a moment."; $ep_btn = 'Back to Home';
require __DIR__ . '/error_page.php';
