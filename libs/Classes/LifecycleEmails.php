<?php
/**
 * Account lifecycle email (transactional, except the abandoned-checkout nudge; the marketing nurture sequence for Free creators is NurtureEmails, on
 * Postmark's broadcast stream with the postal address + unsubscribe footer).
 *
 *   - verification reminders for accounts that never confirmed (cron/lifecycle.php): up to 3, each with a fresh link
 *   - "Your 14-day free trial has started" when a founding trial starts ($0 subscribe, BillingService::receipt)
 *   - "Your trial ends {date}" 3 days before the first charge (cron/billing.php)
 *   - "Your plan didn't start" about an hour after a plan checkout that never finished (cron/lifecycle.php). This one is
 *     marketing: broadcast stream, postal address + unsubscribe footer, and the Tips and Offers switch stops it.
 *   - Postmark hard bounces / transactional spam complaints (/webhook/postmark): the address is marked and never emailed
 *     again. A spam complaint about a marketing (broadcast) email only turns Tips and Offers off.
 *
 * Every email is claimed before it is sent (lifecycle_emails, or user_accounts.verify_reminders_sent for reminders),
 * so each goes out once however many runs overlap. Dates are shown in the account's own timezone.
 */
class LifecycleEmails {

    const REMINDER_CAP      = 200;    // most emails of one kind per run
    const TRIAL_NOTICE_HOURS = 72;    // the trial-ending email goes this long before the first charge
    const ABANDON_AFTER_MIN = 60;     // a checkout with no activity for this long counts as abandoned
    const ABANDON_EVERY_DAYS = 7;     // at most one abandoned-checkout email per account per this many days
    const BOUNCED_MESSAGE   = 'We couldn\'t deliver email to %s. Check it or use another.';
    const PLAN_TAB          = '/account/billing?tab=plan';

    /** Test seam: the webhook secret for this process (tests/lifecycle_emails_test.php). */
    public static $secret_for_test = null;

    /** Test seam: when an array, the cron sweeps only look at these account ids (other dev accounts are left alone). */
    public static $only_users = null;

    private static function skip_for_test($user_id): bool {
        return is_array(self::$only_users) && !in_array((int) $user_id, self::$only_users, true);
    }

    /** Verification reminder windows, by step: [min age h, max age h, founding trial sign-ups only]. */
    const REMINDER_STEPS = array(
        1 => array(2, 23, false),     // "Confirm your email to open your studio"
        2 => array(23, 72, false),    // before the first link expires: "Your link expires soon"
        3 => array(72, 168, true),    // founding sign-ups, while spots remain: "your trial starts when you confirm"
    );

    /* ---------------------------------------------------------------- helpers */

    /** Postmark reported the account's current address as a hard bounce or spam complaint. */
    public static function bounced(array $u): bool {
        $at = (string) ($u['email_bounced_at'] ?? '');
        return $at !== '' && strcasecmp(trim((string) ($u['email_bounced_address'] ?? '')), trim((string) ($u['user_email'] ?? ''))) === 0;
    }

    /** The sign-in / resend message for a bounced address. */
    public static function bounced_message(array $u): string {
        return sprintf(self::BOUNCED_MESSAGE, (string) ($u['user_email'] ?? ''));
    }

    /** May this account get mail at all: an address, not a reserved test domain, not bounced. */
    public static function deliverable(array $u): bool {
        $e = trim((string) ($u['user_email'] ?? ''));
        return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !Notifications::undeliverable($e) && !self::bounced($u);
    }

    /** "Nov 1, 2026" for a stored UTC time, in the account's timezone (UTC when it has none). */
    public static function local_date($utc, $tz = ''): string {
        try { $zone = new DateTimeZone((string) $tz !== '' ? (string) $tz : 'UTC'); } catch (\Throwable $e) { $zone = new DateTimeZone('UTC'); }
        $d = new DateTimeImmutable((string) $utc, new DateTimeZone('UTC'));
        return $d->setTimezone($zone)->format('M j, Y');
    }

    private static function user($user_id): array {
        $r = (new UsersModel())->get_user_by_id((int) $user_id);
        return is_array($r) && count($r) === 1 ? $r[0] : array();
    }

    private static function name(array $u): string { return trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')); }

    private static function plan_name($plan_key): string {
        $t = PlanTiers::get((string) $plan_key);
        return (string) ($t['name'] ?? 'Creator');
    }

    /* ---------------------------------------------------------------- verification */

    /**
     * A fresh verification link for an unconfirmed account (sign-up, Resend, reminders). $capped: at most 3 an hour per
     * account (resend + reminders; the first sign-up email is not counted). $step: 0 = sign-up / resend, 1..3 = reminder.
     * Never sends to a bounced address. Returns true when the email was handed to Postmark.
     */
    public static function send_verify_link(array $user, string $ip, bool $capped = true, int $step = 0): bool {
        $uid = (int) ($user['user_id'] ?? 0);
        if ($uid <= 0 || (string) ($user['user_email'] ?? '') === '' || self::bounced($user)) { return false; }
        if ($capped) {
            $att = new LoginAttemptsModel();
            if ($att->count_recent_for('uid:' . $uid, 'verify_sent', 60) >= 3) { return false; }
            $att->record($ip, 'uid:' . $uid, 'verify_sent');
        }
        $token = (new UsersModel())->set_email_verify_token($uid);
        $link  = Main::get_base_domain() . '/account/verify?token=' . urlencode($token);
        $founding = (int) ($user['signup_founding'] ?? 0) === 1 && Founding::open();
        return (bool) (new NotificationsModel())->send_verification_email((string) $user['user_email'], self::name($user), $link, (string) ($user['u_name'] ?? ''),
            array('founding' => $founding, 'step' => $step, 'trial_days' => Founding::TRIAL_DAYS));
    }

    /** cron/lifecycle.php: reminder emails to accounts that never confirmed. Returns how many went out. */
    public static function run_verify_reminders(int $limit = self::REMINDER_CAP): int {
        $users = new UsersModel();
        $att   = new LoginAttemptsModel();
        $sent  = 0;
        foreach (self::REMINDER_STEPS as $step => $w) {
            if ($w[2] && !Founding::open()) { continue; }   // the trial pitch only while spots remain
            foreach ((array) $users->verify_reminder_candidates($step, $w[0], $w[1], $limit, $w[2]) as $u) {
                if ($sent >= $limit) { return $sent; }
                if (self::skip_for_test($u['user_id'])) { continue; }
                if ($att->count_recent_for('uid:' . (int) $u['user_id'], 'verify_sent', 60) > 0) { continue; }   // a link went out in the last hour: next run
                if ($users->claim_verify_reminder((int) $u['user_id'], $step) < 1) { continue; }
                if (!self::deliverable($u)) { continue; }   // test domain: the step is used up, nothing sent
                try {
                    if (self::send_verify_link($u, 'cron', true, $step)) { $sent++; }
                } catch (\Throwable $e) { error_log('[lifecycle] verify reminder ' . (int) $u['user_id'] . ': ' . $e->getMessage()); }
            }
        }
        return $sent;
    }

    /* ---------------------------------------------------------------- trial */

    /**
     * A founding trial just started (a $0 subscribe charge with the FOUNDING promo applied; BillingService::receipt).
     * Once per account. Returns true when it was sent.
     */
    public static function trial_started(array $charge): bool {
        $fx = (array) json_decode((string) ($charge['effects'] ?? ''), true);
        if ((string) ($charge['kind'] ?? '') !== 'subscribe' || (string) ($fx['promo']['promo_code'] ?? '') !== Founding::CODE) { return false; }
        $uid  = (int) $charge['user_id'];
        $u    = self::user($uid);
        if (!$u) { return false; }
        $acct = BillingService::account($uid);
        $next = BillingService::next_charge($acct);
        if (!$next) { return false; }
        $m = new LifecycleEmailsModel();
        $id = $m->claim($uid, 'trial_started', '', self::deliverable($u) ? 'sent' : 'skipped');
        if ($id < 1 || !self::deliverable($u)) { return false; }
        $mail = self::trial_started_email($u, $acct, $next);
        try { (new UserNotificationsModel())->push($uid, 'subscriptions', $mail['subject'], $mail['notice'], self::PLAN_TAB, 'fa-credit-card'); }
        catch (\Throwable $e) { error_log('[lifecycle] trial started notice ' . $uid . ': ' . $e->getMessage()); }
        $ok = (new NotificationsModel())->send_lifecycle_email((string) $u['user_email'], self::name($u), $mail['subject'], $mail['subject'], $mail['paras'], $mail['facts'],
            'Manage Plan', self::PLAN_TAB, $mail['footer']);
        if (!$ok) { $m->set_status($id, 'failed'); }
        return (bool) $ok;
    }

    /** Subject, paragraphs, fact rows and footer of the trial-started email (pure). */
    public static function trial_started_email(array $u, array $acct, array $next): array {
        $tz    = (string) ($u['content_timezone'] ?? '');
        $plan  = self::plan_name($next['plan']);
        $when  = self::local_date($next['at'], $tz);
        $amt   = BillingService::money((int) $next['total']);
        $days  = Founding::TRIAL_DAYS;
        return array(
            'subject' => 'Your ' . $days . '-day free trial has started',
            'paras'   => array(
                'Your ' . $plan . ' plan is on. Everything in it is yours, free, for the next ' . $days . ' days.',
                'Cancel anytime in Billing before ' . $when . ' and you won\'t be charged.',
            ),
            'notice'  => 'Free until ' . $when . ', then ' . $amt . ' a month. Cancel anytime in Billing.',
            'facts'   => array(array('Plan', $plan), array('Free until', $when), array('First charge', $amt . ' on ' . $when), array('After that', $amt . ' / month')),
            'footer'  => 'You\'re receiving this because you started a free trial on ' . Main::site_name() . '. Cancel anytime in Billing.',
        );
    }

    /** cron/billing.php: "Your trial ends {date}" to founding trials whose first charge is TRIAL_NOTICE_HOURS away. Returns how many went out. */
    public static function send_trial_ending(int $limit = self::REMINDER_CAP): int {
        $m = new LifecycleEmailsModel();
        $sent = 0;
        foreach ($m->trial_ending_candidates(self::TRIAL_NOTICE_HOURS, $limit) as $c) {
            $uid  = (int) $c['user_id'];
            if (self::skip_for_test($uid)) { continue; }
            try {
                $u    = self::user($uid);
                $acct = BillingService::account($uid);
                $next = BillingService::next_charge($acct);
                if (!$u || !$next || (int) $next['total'] <= 0) { continue; }
                $ok_to_send = self::deliverable($u);
                $id = $m->claim($uid, 'trial_ending', 'claim:' . (int) $c['claim_id'], $ok_to_send ? 'sent' : 'skipped');
                if ($id < 1 || !$ok_to_send) { continue; }
                $mail = self::trial_ending_email($u, $acct, $next);
                $link = $mail['link'] ?? self::PLAN_TAB;
                try { (new UserNotificationsModel())->push($uid, 'subscriptions', $mail['subject'], $mail['paras'][0], $link, 'fa-credit-card'); }
                catch (\Throwable $e) { error_log('[lifecycle] trial ending notice ' . $uid . ': ' . $e->getMessage()); }
                $ok = (new NotificationsModel())->send_lifecycle_email((string) $u['user_email'], self::name($u), $mail['subject'], $mail['subject'], $mail['paras'], $mail['facts'],
                    $mail['button'] ?? 'Manage Plan', $link, $mail['footer']);
                if ($ok) { $sent++; } else { $m->release($id); }   // Postmark refused it: the next run tries again
            } catch (\Throwable $e) { error_log('[lifecycle] trial ending ' . $uid . ': ' . $e->getMessage()); }
        }
        return $sent;
    }

    /** Subject, paragraphs, fact rows and footer of the trial-ending email (pure). */
    public static function trial_ending_email(array $u, array $acct, array $next): array {
        $tz    = (string) ($u['content_timezone'] ?? '');
        $t     = PlanTiers::get((string) $next['plan']);
        $plan  = (string) ($t['name'] ?? 'Creator');
        $when  = self::local_date($next['at'], $tz);
        $amt   = BillingService::money((int) $next['total']);
        $l     = (array) ($t['limits'] ?? array());
        $inc   = array();
        if ((int) ($l['ai_credits'] ?? 0) > 0)  { $inc[] = number_format((int) $l['ai_credits']) . ' AI credits a month'; }
        if ((int) ($l['influencers'] ?? 0) > 0) { $inc[] = (int) $l['influencers'] . ' AI influencer' . ((int) $l['influencers'] === 1 ? '' : 's'); }
        if (isset($l['automations']) && (int) $l['automations'] >= 0) { $inc[] = (int) $l['automations'] > 0 ? (int) $l['automations'] . ' automations' : 'unlimited automations'; }
        if ((int) ($l['storage_gb'] ?? 0) > 0)  { $inc[] = (int) $l['storage_gb'] . ' GB of storage'; }
        $list  = count($inc) > 1 ? implode(', ', array_slice($inc, 0, -1)) . ' and ' . end($inc) : implode('', $inc);
        $card  = (string) ($acct['card_last4'] ?? '') !== '' ? ucfirst((string) ($acct['card_brand'] ?: 'Card')) . ' ending ' . $acct['card_last4'] : 'Your saved card';
        if (BillingService::in_no_card_trial($acct)) {   // started with no card: keeping the plan needs one, else Free on that date
            $held = (int) ($acct['trial_credits_held'] ?? 0);
            return array(
                'subject' => 'Your trial ends ' . $when,
                'paras'   => array(
                    'Your free trial of the ' . $plan . ' plan ends on ' . $when . '. Add a card before then to keep ' . $plan . ' for ' . $amt . ' a month' . ($held > 0 ? ', and get the other ' . number_format($held) . ' AI credits of your trial right away' : '') . '.',
                    'With ' . $plan . ' you keep selling to your fans, plus ' . $list . '. Without a card, your account moves to Free on ' . $when . '. Everything you made is kept, and it all comes back when you pick a plan.',
                ),
                'facts'   => array(array('Plan', $plan), array('Trial ends', $when), array('After the trial', $amt . ' / month'), array('Card', 'None yet')),
                'footer'  => 'You\'re receiving this because your free trial on ' . Main::site_name() . ' is ending. Nothing is charged without a card.',
                'button'  => 'Add Card', 'link' => '/account/billing?add_card=1',
            );
        }
        return array(
            'subject' => 'Your trial ends ' . $when,
            'paras'   => array(
                'Your free trial of the ' . $plan . ' plan ends on ' . $when . '. After that, ' . $plan . ' continues for ' . $amt . ' a month and everything you\'ve set up stays as it is.',
                'With ' . $plan . ' you keep selling to your fans, plus ' . $list . '. Nothing to do if you want to keep it. To stop, cancel in Billing before ' . $when . ' and you won\'t be charged.',
            ),
            'facts'   => array(array('Plan', $plan), array('First charge', $when), array('Amount', $amt . ' / month'), array('Card', $card)),
            'footer'  => 'You\'re receiving this because your free trial on ' . Main::site_name() . ' is ending. You can change or cancel your plan anytime from Billing.',
        );
    }

    /* ---------------------------------------------------------------- abandoned checkout */

    /**
     * cron/lifecycle.php: about an hour after a plan checkout that didn't finish (a plan quote, or a subscribe charge that
     * failed or waits for authentication) with no plan started since, one email; at most one per account every
     * ABANDON_EVERY_DAYS days, and only for activity after the last one. Returns how many went out.
     */
    public static function run_abandoned_checkouts(int $limit = self::REMINDER_CAP): int {
        $m = new LifecycleEmailsModel();
        $starts = $m->checkout_starts(self::ABANDON_EVERY_DAYS);
        $fails  = $m->failed_subscribes(self::ABANDON_EVERY_DAYS);
        $all = array();
        foreach ($starts as $uid => $s) {
            if (strpos($s['meta'], 'subscribe ') !== 0) { continue; }   // upgrades and downgrades of a running plan are not "didn't start"
            $p = explode(' ', $s['meta']);
            $all[$uid] = array('last_ts' => $s['last_ts'], 'plan' => (string) ($p[1] ?? ''));
        }
        foreach ($fails as $uid => $f) {
            if (!isset($all[$uid]) || $f['last_ts'] > $all[$uid]['last_ts']) { $all[$uid] = array('last_ts' => $f['last_ts'], 'plan' => $f['plan']); }
        }
        $cut = gmdate('Y-m-d H:i:s', time() - self::ABANDON_AFTER_MIN * 60);
        $week = gmdate('Y-m-d H:i:s', time() - self::ABANDON_EVERY_DAYS * 86400);
        $sent = 0;
        foreach ($all as $uid => $a) {
            if ($sent >= $limit) { break; }
            if (self::skip_for_test($uid)) { continue; }
            if ($a['last_ts'] > $cut) { continue; }   // still at it (or came back within the hour)
            try {
                $last = $m->last($uid, 'checkout_abandoned');
                if ($last && ((string) $last['created_at'] > $week || (string) $last['created_at'] >= $a['last_ts'])) { continue; }
                $acct = BillingService::account($uid);
                if (BillingService::is_paid($acct) || $m->subscribed_since($uid, $a['last_ts'])) { continue; }
                $u = self::user($uid);
                if (!$u || (int) ($u['deleted'] ?? 0) === 1 || (int) ($u['email_verified'] ?? 0) !== 1) { continue; }
                // marketing (a nudge, not a transaction): the Tips and Offers switch decides, like NurtureEmails
                $ok_to_send = self::deliverable($u) && (new NotificationPrefsModel())->email_on($uid, NurtureEmails::CATEGORY);
                $id = $m->claim($uid, 'checkout_abandoned', 'w' . (int) floor(time() / (self::ABANDON_EVERY_DAYS * 86400)), $ok_to_send ? 'sent' : 'skipped');
                if ($id < 1 || !$ok_to_send) { continue; }
                $plan = PlanTiers::get($a['plan']) && $a['plan'] !== 'free' ? $a['plan'] : Founding::PLAN;
                $mail = self::abandoned_email($u, $plan, Founding::eligible($uid));
                // sent on the broadcast stream with the postal address and unsubscribe link (Postmark suppresses whoever unsubscribes)
                $html = NotificationsModel::build_lifecycle_email($mail['subject'], $mail['paras'], array(), 'Pick Up Where You Left Off', $mail['link'], $mail['footer'], NurtureEmails::footer_html());
                $ok = (new Notifications())->send_email(array(array('email' => (string) $u['user_email'], 'name' => self::name($u))), 0, $mail['subject'], $html, Notifications::STREAM_BROADCAST);
                if ($ok) { $sent++; }
                elseif ((int) Notifications::$last_error_code === 406) {   // unsubscribed or suppressed on the broadcast stream: stop for good
                    (new NotificationPrefsModel())->save_prefs($uid, array(NurtureEmails::CATEGORY => array('in_platform' => 0, 'email' => 0)));
                    $m->set_status($id, 'suppressed');
                } else { $m->release($id); }
            } catch (\Throwable $e) { error_log('[lifecycle] abandoned checkout ' . (int) $uid . ': ' . $e->getMessage()); }
        }
        return $sent;
    }

    /** Subject, paragraphs, link and footer of the abandoned-checkout email (pure). */
    public static function abandoned_email(array $u, string $plan, bool $founding): array {
        $name = self::plan_name($plan);
        $paras = array('Your card wasn\'t charged. Pick up where you left off: everything you\'ve set up is saved.');
        if ($founding) { $paras[] = 'Your ' . Founding::TRIAL_DAYS . '-day free trial is still available, so nothing is charged until it ends.'; }
        return array(
            'subject' => 'Your ' . $name . ' plan didn\'t start',
            'paras'   => $paras,
            'link'    => self::PLAN_TAB . '&plan=' . rawurlencode($plan) . ($founding ? '&founding=1' : ''),
            'footer'  => 'You\'re receiving this because you started choosing a plan on ' . Main::site_name() . '.',
        );
    }

    /* ---------------------------------------------------------------- Postmark bounces */

    public static function webhook_secret(): string {
        if (self::$secret_for_test !== null) { return (string) self::$secret_for_test; }
        $s = (string) Main::config(Main::get_environment(), 'postmark_webhook_secret', '');
        return $s !== '' ? $s : (string) Main::config('global', 'postmark_webhook_secret', '');
    }

    /**
     * A Postmark bounce / spam-complaint webhook delivery. $given is the secret that came with it (URL path segment or
     * HTTP basic auth password). Hard bounces, bad addresses and spam complaints mark the address; everything else is
     * acknowledged and ignored. Returns array(http code, body text).
     */
    public static function postmark_webhook(string $raw, string $given): array {
        $secret = self::webhook_secret();
        if ($secret === '') { error_log('[postmark webhook] postmark_webhook_secret not configured'); return array(503, 'not configured'); }
        if ($given === '' || !hash_equals($secret, $given)) { return array(401, 'unauthorized'); }
        $e = json_decode($raw, true);
        if (!is_array($e)) { return array(400, 'bad request'); }
        $record = (string) ($e['RecordType'] ?? '');
        if ($record === 'SubscriptionChange') {   // broadcast stream unsubscribe / resubscribe: the Tips and Offers switch follows it
            $n = NurtureEmails::subscription_change($e);
            return array(200, $n > 0 ? 'ok' : 'ignored');
        }
        $type   = (string) ($e['Type'] ?? '');
        $email  = trim((string) ($e['Email'] ?? ''));
        // A spam complaint about a marketing email only stops marketing: Tips and Offers goes off, and password resets,
        // receipts and trial notices still arrive. A complaint on the transactional stream, or a hard bounce, stops everything.
        if (($record === 'SpamComplaint' || $type === 'SpamComplaint') && (string) ($e['MessageStream'] ?? '') === Notifications::STREAM_BROADCAST) {
            if ($email === '') { return array(200, 'ignored'); }
            $n = NurtureEmails::subscription_change(array('Recipient' => $email, 'MessageStream' => Notifications::STREAM_BROADCAST, 'SuppressSending' => true));
            error_log('[postmark webhook] SpamComplaint on ' . Notifications::STREAM_BROADCAST . ' for ' . $email . ': Tips and Offers off (' . (int) $n . ' account' . ((int) $n === 1 ? '' : 's') . ')');
            return array(200, 'ok');
        }
        $hard   = ($record === 'Bounce' && in_array($type, array('HardBounce', 'BadEmailAddress'), true)) || $record === 'SpamComplaint' || $type === 'SpamComplaint';
        if (!$hard || $email === '') { return array(200, 'ignored'); }
        $reason = ($record === 'SpamComplaint' ? 'SpamComplaint' : $type) . (!empty($e['Description']) ? ': ' . (string) $e['Description'] : '');
        $n = (new UsersModel())->mark_email_bounced($email, $reason);
        error_log('[postmark webhook] ' . $reason . ' for ' . $email . ' (' . (int) $n . ' account' . ((int) $n === 1 ? '' : 's') . ')');
        return array(200, 'ok');
    }
}
