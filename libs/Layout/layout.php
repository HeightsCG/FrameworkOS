<?php
    if ($this->protected == 1 && Session::get('user_id') == 0) {
        if (!headers_sent() && strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') !== '/') { Controller::bounce(); }   // other pages: to the sign-in dialog with ?next= this page (home is the sign-in page)
        $this->login_form();
    } else {
        $this->site_header();
        $this->content();
        $this->site_footer();
    }
?>