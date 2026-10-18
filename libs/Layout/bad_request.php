<?php
/** 400 page (Errors::bad_request). Same look as the 404 (error_page.php). */
$ep_code = '400'; $ep_title = 'Bad Request'; $ep_text = "We couldn't process that request. Check the link and try again."; $ep_btn = 'Back to Home';
require __DIR__ . '/error_page.php';
