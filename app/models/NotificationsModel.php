<?php
class NotificationsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function send_password_reset_email($to_email, $to_name, $reset_link){
        $subject = 'Password Reset';
        $message = '<p>A password reset was requested for your account.</p>'
                 . '<p><a href="' . $reset_link . '">Reset your password</a> (valid for 1 hour).</p>'
                 . '<p>If you did not request this, you can safely ignore this email.</p>';

        $to = array(
            array('email' => $to_email, 'name' => $to_name),
        );

        $notifications = new Notifications();
        $notifications->send_email($to, 0, $subject, $message);
    }

}
