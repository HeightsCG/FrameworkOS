<?php
class NotificationsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function send_password_reset_email($to_email, $to_name, $reset_link){
        $subject = 'Reset your password';
        $body    = '<p style="' . self::P . '">We received a request to reset the password for your '
                 . self::brand_name() . ' account.</p>'
                 . '<p style="' . self::P . '">Click the button below to choose a new password. '
                 . 'This link is valid for <strong>1 hour</strong>.</p>';
        $message = self::brand_wrap('Reset your password', $body, 'Reset my password', $reset_link,
                   'If you did not request this, you can safely ignore this email; your password will stay the same.');

        return $this->deliver($to_email, $to_name, $subject, $message);
    }

    public function send_verification_email($to_email, $to_name, $verify_link, $handle = ''){
        $subject = 'Confirm your email';
        $body    = '<p style="' . self::P . '">Welcome to ' . self::brand_name() . '! '
                 . 'You\'re one step away. Confirm your email address to activate your account.</p>'
                 . '<p style="' . self::P . '">This link is valid for <strong>24 hours</strong>.</p>';
        if ($handle !== '') {
            $safe = htmlspecialchars($handle, ENT_QUOTES, 'UTF-8');
            $body .= '<p style="' . self::P . ' margin-top:2px;">Your username is <strong>' . $safe . '</strong>. '
                   . 'You can sign in with either your username or your email address.</p>';
        }
        $message = self::brand_wrap('Confirm your email', $body, 'Verify my email', $verify_link,
                   'If you did not create an account, you can safely ignore this email.');

        return $this->deliver($to_email, $to_name, $subject, $message);
    }

    public function send_mfa_code_email($to_email, $to_name, $code){
        $subject = 'Your verification code';
        $safe    = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $body    = '<p style="' . self::P . '">Use this code to finish signing in to your '
                 . self::brand_name() . ' account:</p>'
                 . '<div style="margin:20px 0; padding:16px; text-align:center; background:#f4f3fb; '
                 . 'border:1px solid #e7e4f6; border-radius:10px; font-family:Arial,Helvetica,sans-serif; '
                 . 'font-size:30px; font-weight:700; letter-spacing:8px; color:#4636c4;">' . $safe . '</div>';
        $message = self::brand_wrap('Your verification code', $body, '', '',
                   'This code expires in 10 minutes. If you did not request it, you can ignore this email.');

        return $this->deliver($to_email, $to_name, $subject, $message);
    }

    /**
     * The email channel for an in-platform notification (PRD §27). Delivered only
     * when the recipient has the category's email channel enabled — the caller
     * (BaseApiController::notify) makes that decision. Mirrors the notification's
     * title/body and links back to the on-site destination.
     */
    public function send_notification_email($to_email, $to_name, $title, $body = '', $link = ''){
        if ((string) $to_email === '' || (string) $title === '') { return false; }
        return $this->deliver($to_email, $to_name, (string) $title, self::build_notification_email($title, $body, $link));
    }

    /** Build the branded notification email HTML (pure — no send). */
    public static function build_notification_email($title, $body = '', $link = ''){
        $body_html = '';
        if (trim((string) $body) !== '') {
            $body_html = '<p style="' . self::P . '">' . nl2br(htmlspecialchars((string) $body, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        $url       = self::absolute_url($link);
        $cta_label = $url !== '' ? 'View on ' . Main::site_name() : '';
        $footer    = 'You\'re receiving this because email notifications are on for your account. '
                   . 'Manage which notifications you get in your account settings.';
        return self::brand_wrap((string) $title, $body_html, $cta_label, $url, $footer);
    }

    /** Turn a stored notification link (often a relative app path) into an absolute URL. */
    private static function absolute_url($link){
        $link = (string) $link;
        if ($link === '') { return ''; }
        if (preg_match('#^https?://#i', $link)) { return $link; }
        return rtrim(Main::get_base_domain(), '/') . '/' . ltrim($link, '/');
    }

    /** Shared send helper. */
    /**
     * Platform billing email (receipt, failed payment, confirm payment): a heading, an intro line,
     * optional itemized lines [[label, '$49.00'], ...] with a total, and a button.
     */
    public function send_billing_email($to_email, $to_name, $subject, $intro, array $lines = array(), $total = '', $button_label = '', $button_url = '', $footer = ''){
        $body = '<p style="' . self::P . '">' . htmlspecialchars((string) $intro, ENT_QUOTES, 'UTF-8') . '</p>';
        if (!empty($lines)) {
            $td = 'padding:8px 0; border-bottom:1px solid #ecebf3; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#2b2940;';
            $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 16px;">';
            foreach ($lines as $l) {
                $body .= '<tr><td style="' . $td . '">' . htmlspecialchars((string) $l[0], ENT_QUOTES, 'UTF-8') . '</td>'
                       . '<td align="right" style="' . $td . '">' . htmlspecialchars((string) $l[1], ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            if ((string) $total !== '') {
                $body .= '<tr><td style="' . $td . ' font-weight:700; border-bottom:0;">Total</td><td align="right" style="' . $td . ' font-weight:700; border-bottom:0;">'
                       . htmlspecialchars((string) $total, ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            $body .= '</table>';
        }
        $message = self::brand_wrap((string) $subject, $body, (string) $button_label, (string) $button_url,
            $footer !== '' ? (string) $footer : 'You can change or cancel your plan anytime from Billing.');
        return $this->deliver($to_email, $to_name, (string) $subject, $message);
    }

    private function deliver($to_email, $to_name, $subject, $message){
        $to = array(array('email' => $to_email, 'name' => $to_name));
        $notifications = new Notifications();
        return $notifications->send_email($to, 0, $subject, $message);
    }

    // Shared paragraph style (email clients need inline styles, no <style> blocks).
    const P = 'margin:0 0 14px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:1.6; color:#4b4863;';

    private static function brand_name(){
        return htmlspecialchars(Main::site_name(), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Wrap message body in the branded email shell: centered white card, violet
     * brand header, an optional CTA button, and a muted footer note. Table-based
     * with inline styles for broad email-client support.
     */
    private static function brand_wrap($heading, $body_html, $button_label, $button_url, $footer_note){
        $name = self::brand_name();

        $button = '';
        if ($button_label !== '' && $button_url !== '') {
            $button = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 4px;">'
                    . '<tr><td style="border-radius:8px; background:#5b4be0;">'
                    . '<a href="' . $button_url . '" target="_blank" '
                    . 'style="display:inline-block; padding:13px 26px; font-family:Arial,Helvetica,sans-serif; '
                    . 'font-size:15px; font-weight:600; line-height:1; color:#ffffff; text-decoration:none; border-radius:8px;">'
                    . htmlspecialchars($button_label, ENT_QUOTES, 'UTF-8')
                    . '</a></td></tr></table>';
        }

        return
        '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
      . '<meta name="viewport" content="width=device-width, initial-scale=1">'
      . '<title>' . $name . '</title></head>'
      . '<body style="margin:0; padding:0; background:#f4f4f7;">'
      . '<div style="margin:0; padding:32px 12px; background:#f4f4f7;">'
      .   '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center">'
      .     '<table role="presentation" width="480" cellpadding="0" cellspacing="0" border="0" '
      .            'style="width:480px; max-width:480px; background:#ffffff; border:1px solid #e7e7ee; border-radius:14px;">'
                  // brand header
      .       '<tr><td style="padding:26px 32px 6px;">'
      .         '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
      .           '<td style="width:30px; height:30px; background:#5b4be0; border-radius:9px; font-size:0; line-height:0;">&nbsp;</td>'
      .           '<td style="padding-left:11px; font-family:Arial,Helvetica,sans-serif; font-size:17px; '
      .                  'font-weight:700; color:#1c1830; letter-spacing:-.3px;">' . $name . '</td>'
      .         '</tr></table>'
      .       '</td></tr>'
                  // heading + body
      .       '<tr><td style="padding:14px 32px 4px;">'
      .         '<h1 style="margin:0 0 14px; font-family:Arial,Helvetica,sans-serif; font-size:20px; '
      .              'font-weight:700; color:#1c1830;">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>'
      .         $body_html
      .       '</td></tr>'
                  // button
      .       ($button !== '' ? '<tr><td style="padding:2px 32px 20px;">' . $button . '</td></tr>' : '')
                  // footer
      .       '<tr><td style="padding:18px 32px 22px; border-top:1px solid #f0f0f5;">'
      .         '<p style="margin:0; font-family:Arial,Helvetica,sans-serif; font-size:12px; '
      .              'line-height:1.55; color:#9a97a8;">' . htmlspecialchars($footer_note, ENT_QUOTES, 'UTF-8') . '</p>'
      .         '<p style="margin:10px 0 0; font-family:Arial,Helvetica,sans-serif; font-size:12px; '
      .              'color:#c2c0cc;">&copy; ' . $name . '</p>'
      .       '</td></tr>'
      .     '</table>'
      .   '</td></tr></table>'
      . '</div></body></html>';
    }

}
