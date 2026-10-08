<?php
/**
 * Emails every admin (user_accounts.is_admin) when someone signs up: who they are, how they signed up (email or
 * Google), when, and where they came from (the first-touch attribution saved at signup), with a link to their page
 * in Admin. Queued at signup (SignupAlertJob::queue) so signing up never waits on email.
 */
class SignupAlertJob {

    /** Called right after a new account is created. $method: 'email' | 'google'. */
    public static function queue($user_id, $method): void {
        try {
            (new DatabaseJobQueue())->dispatch('signup_alert', array('user_id' => (int) $user_id, 'method' => (string) $method), 'signup_alert:' . (int) $user_id);
        } catch (\Throwable $e) {
            error_log('[signup_alert] queue ' . (int) $user_id . ': ' . $e->getMessage());   // never breaks signing up
        }
    }

    public static function handle(array $payload): string {
        $uid = (int) ($payload['user_id'] ?? 0);
        if ($uid <= 0) { throw new InvalidArgumentException('user_id required'); }
        $users = new UsersModel();
        $rows  = $users->get_user_by_id($uid);
        if (!is_array($rows) || count($rows) !== 1) { return 'account gone'; }
        $u    = $rows[0];
        $name = trim((string) $u['first_name'] . ' ' . (string) $u['last_name']);
        if ($name === '') { $name = (string) $u['user_email']; }   // email sign-up asks for no name
        $d    = function ($v) { return html_entity_decode(trim((string) $v), ENT_QUOTES, 'UTF-8'); };

        // Where they came from: campaign tags if any, else the site that sent them, else direct.
        $src = $d($u['acq_source'] ?? '');
        $med = $d($u['acq_medium'] ?? '');
        $ref = $d($u['acq_referrer'] ?? '');
        $came = $src !== '' ? $src . ($med !== '' ? ' / ' . $med : '') : ($ref !== '' ? (parse_url($ref, PHP_URL_HOST) ?: $ref) : 'Direct');

        $sent = 0;
        $mail = new NotificationsModel();
        foreach ($users->admin_ids() as $aid) {
            $ar = $users->get_user_by_id((int) $aid);
            $a  = (is_array($ar) && count($ar) === 1) ? $ar[0] : null;
            if (!$a || (string) ($a['user_email'] ?? '') === '' || (int) $aid === $uid) { continue; }
            $tz = (string) ($a['content_timezone'] ?? '') !== '' ? (string) $a['content_timezone'] : 'America/New_York';
            try { $when = (new DateTime((string) $u['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('M j, Y \a\t g:i A T'); }
            catch (\Throwable $e) { $when = (string) $u['created_at'] . ' UTC'; }
            $lines = array(
                array('Name', $d($name) !== '' ? $d($name) : '—'),
                array('Username', '@' . (string) $u['u_name']),
                array('Email', (string) $u['user_email']),
                array('Signed up with', ((string) ($payload['method'] ?? '')) === 'google' ? 'Google' : 'Email'),
                array('Signed up', $when),
                array('Came from', $came),
            );
            if ($d($u['acq_campaign'] ?? '') !== '') { $lines[] = array('Campaign', $d($u['acq_campaign'])); }
            if ($d($u['acq_landing'] ?? '') !== '')  { $lines[] = array('First page', $d($u['acq_landing'])); }
            $ok = $mail->send_billing_email((string) $a['user_email'], trim((string) $a['first_name'] . ' ' . (string) $a['last_name']),
                'New signup: ' . ($d($name) !== '' ? $d($name) : '@' . $u['u_name']),
                'Someone just created an account on ' . Main::site_name() . '.', $lines, '',
                'View in Admin', rtrim(Main::get_base_domain(), '/') . '/admin/user/' . $uid,
                'You get this email because you\'re an admin on ' . Main::site_name() . '.');
            if ($ok) { $sent++; }
        }
        return 'sent to ' . $sent . ' admin(s)';
    }
}
