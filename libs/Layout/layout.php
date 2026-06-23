<?php
    if ($this->protected == 1 && Session::get('user_id') == 0) {
        $this->login_form();
    } else {
        $this->site_header();
        $this->content();
        $this->site_footer();
    }
?>