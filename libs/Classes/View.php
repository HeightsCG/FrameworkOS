<?php
#[\AllowDynamicProperties]

class View {

    public $protected;
    public $controller;
    public $method;
    public $config;

    public function __construct($p) {
        $this->controller = preg_replace('/controller/', '', strtolower( Main::controller_name() ));
        $this->method = preg_replace('/action/', '', strtolower( Main::method_name() ));
        $this->protected = $p;
        $this->config = Main::get_config();
    }

    public function content(): void
    {
        $file = $this->view_file();
        if (file_exists($file)) {
            require $file;
        } else {
            Errors::page_not_found();
        }
    }

    public function force_reset_form(): void
    {
        $file = $this->force_reset_form_file();
        if (file_exists($file)) {
            require $file;
        } else {
            Errors::page_not_found();
        }
    }   
    
    public function forgot_password(): void
    {
        $file = $this->forgot_password_file();
        if (file_exists($file)) {
            require $file;
        } else {
            Errors::page_not_found();
        }
    }

    public function reset_password(): void
    {
        $file = $this->reset_password_file();
        if (file_exists($file)) {
            require $file;
        } else {
            Errors::page_not_found();
        }
    }

    public function verify_email(): void
    {
        $file = $this->verify_email_file();
        if (file_exists($file)) {
            require $file;
        } else {
            Errors::page_not_found();
        }
    }

    public function render($p = false): void
    {
        $file = $this->layout_file();
        if (file_exists($file)) {
            require $file;
        } else {
            Errors::page_not_found();
        }
    }

    private function check_protection($protected): int
    {
        $this->class_protected = $this->protected;
        if ($protected == 1 || $this->protected == 1) {
            $this->protected = 1;
        }
        return $this->protected;
    }

    public function login_form(): void
    {
        $file = $this->login_file();
        if (file_exists($file)) {
            require $file;
        } else {
            Errors::page_not_found();
        }
    }

    public function site_header(): void
    {
        $file = $this->header_file();
        if (file_exists($file)) {
            require $file;
        }
    }

    public function site_footer(): void
    {
        $file = $this->footer_file();
        if (file_exists($file)) {
            require $file;
        }
    }

    public function layout_file(): string
    {
        return Main::lib_path().'/Layout/layout.php';
    }

    public function view_file(): string
    {
        return Main::app_path().'/app/views/'.preg_replace('/controller/', '', strtolower( Main::controller_name() )) .'/'. preg_replace('/action/', '', strtolower( Main::method_name() ) ).'.php';
    }

    public function login_file(): string
    {
        return Main::lib_path().'/Layout/login_form.php';
    }

    public function header_file(): string
    {
        return Main::lib_path().'/Layout/site_header.php';
    }

    public function footer_file(): string
    {
        return Main::lib_path().'/Layout/site_footer.php';
    }

    public function forgot_password_file(): string
    {
        return Main::lib_path().'/Layout/forgot_password.php';
    }

    public function reset_password_file(): string
    {
        return Main::lib_path().'/Layout/reset_password.php';
    }

    public function verify_email_file(): string
    {
        return Main::lib_path().'/Layout/verify_email.php';
    }

    public function force_reset_form_file(): string
    {
        return Main::lib_path().'/Layout/force_reset.php';
    }

    public function page_not_found_file(): string
    {
        return Main::lib_path().'/Layout/page_not_found.php';
    }

    public function maintenance_mode_file(): string
    {
        return Main::lib_path().'/Layout/maintenance_mode.php';
    }

}
