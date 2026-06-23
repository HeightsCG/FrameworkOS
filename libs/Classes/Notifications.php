<?php
/**
 * Email helpers (the transport layer). Apps build their messages in
 * NotificationsModel and call send_email() here to deliver them.
 *
 * send_email() is a minimal HTML sender using PHP's mail(); swap its body for
 * your provider's SMTP/API. The From address comes from app.ini
 * ([global] mail_from / mail_from_name) — nothing is hardcoded.
 */
class Notifications {

    /** Load an HTML template from app/email_templates and substitute {vars}. */
    public function clean_template($template_name, $variables){
        $template = $this->get_email_template($template_name);
        if (!is_string($template)) {
            return '';
        }
        if (is_array($variables)) {
            foreach ($variables as $key => $value) {
                $template = str_replace('{' . $key . '}', (string) $value, $template);
            }
        }
        return $template;
    }

    public function get_email_template($template_name){
        $file = Main::app_path() . '/app/email_templates/' . $template_name . '.html';
        if (file_exists($file)) {
            return file_get_contents($file);
        }
        return 0;
    }

    /**
     * Send an HTML email to each recipient in $to_array (each ['email'=>, 'name'=>]).
     * $cc_array is an optional array of the same shape (or 0/null for none).
     * Returns true if at least one message was handed to the MTA, false otherwise.
     */
    public function send_email($to_array, $cc_array, $subject, $message){
        $from = (string) Main::config('global', 'mail_from', '');
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            error_log('[mailer] mail_from is not configured in app.ini; email not sent');
            return false;
        }

        $from_name = (string) Main::config('global', 'mail_from_name', '');
        if ($from_name === '') {
            $from_name = Main::site_name();
        }

        // Defend against header injection.
        $subject   = $this->strip_header($subject);
        $from_name = $this->strip_header($from_name);

        $headers   = array();
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'From: ' . ($from_name !== '' ? '"' . $from_name . '" <' . $from . '>' : $from);

        if (is_array($cc_array)) {
            foreach ($cc_array as $cc) {
                if (!empty($cc['email']) && filter_var($cc['email'], FILTER_VALIDATE_EMAIL)) {
                    $headers[] = 'Cc: ' . $cc['email'];
                }
            }
        }
        $header_string = implode("\r\n", $headers);

        $sent = false;
        if (is_array($to_array)) {
            foreach ($to_array as $to) {
                if (empty($to['email']) || !filter_var($to['email'], FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                if (mail($to['email'], $subject, $message, $header_string)) {
                    $sent = true;
                }
            }
        }
        return $sent;
    }

    private function strip_header($value){
        return trim(str_replace(array("\r", "\n"), '', (string) $value));
    }

}
