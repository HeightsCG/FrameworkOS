<?php
/**
 * Free-creator nurture email (marketing): verified creators on Free who haven't started a plan get up to three
 * getting-started emails and, while the Founding Creator price runs, two deadline emails (once per account, ever).
 *
 *   - day 1  "Your page isn't public yet"         (24h after sign-up, sign-ups in the last 7 days)
 *   - day 3  "Make your first AI photo"            (72h after sign-up, sign-ups in the last 7 days)
 *   - day 7  "What you get with Creator"           (7 days after sign-up, sign-ups from FROM on)
 *   - deadline "The $49 Founding Creator price ends {date}": 3 days before CreatorPromo's end date and on the last day,
 *     from 9 AM Eastern, to Free creators who signed up in the last 60 days. ref = end date + stage. Each account gets each
 *     stage once ever: extending the offer never re-sends a deadline someone already got (no repeated fake deadlines).
 *
 * Marketing rules: sent on the Postmark broadcast stream (Postmark adds List-Unsubscribe and suppresses whoever
 * unsubscribes), every email carries Notifications::POSTAL_ADDRESS and the {{{ pm:unsubscribe }}} link, and the
 * Tips and Offers email switch in Settings stops them. Everything is rechecked at send time (a plan started a minute
 * ago stops it). Each email is claimed in lifecycle_emails first, so it goes out once however many runs overlap.
 */
class NurtureEmails {

    const CAP        = 300;                    // most emails per run, all kinds together
    const CATEGORY   = 'tips_offers';          // NotificationPrefsModel category (email switch)
    const FROM       = '2026-10-18 00:00:00';  // the day-7 email only goes to sign-ups from this moment on (UTC)
    const GAP_HOURS  = 20;                     // at least this long between two getting-started emails to one account
    const DEADLINE_GAP_HOURS = 6;              // a deadline email waits this long after a getting-started email
    const DEADLINE_DAYS = 60;                  // deadline emails: sign-ups in the last 60 days
    const DEADLINE_HOUR = 9;                   // deadline emails go from 9 AM Eastern
    const UNSUBSCRIBE = '{{{ pm:unsubscribe }}}';   // Postmark replaces it with the recipient's unsubscribe link
    const SETTINGS    = '/account/settings?section=notifications';

    /** Getting-started steps: kind => [min age h, max age h, sign-ups since (null = no floor beyond max age)]. */
    const STEPS = array(
        'nurture_day1' => array(24, 168, null),
        'nurture_day3' => array(72, 168, null),
        'nurture_day7' => array(168, 336, self::FROM),
    );
    const DEADLINE_KIND = 'nurture_deadline';

    /** Test seam: the clock (unix time) the deadline stage is judged by. */
    public static $now = null;

    private static function now(): int { return self::$now !== null ? (int) self::$now : time(); }

    private static function skip_for_test($uid): bool {
        return is_array(LifecycleEmails::$only_users) && !in_array((int) $uid, LifecycleEmails::$only_users, true);
    }

    /* ---------------------------------------------------------------- who */

    /** May this account get nurture email right now (fresh row; checked again just before sending)? */
    public static function eligible(array $u): bool {
        $uid = (int) ($u['user_id'] ?? 0);
        if ($uid <= 0 || (int) ($u['deleted'] ?? 0) === 1 || (string) ($u['user_status'] ?? '') !== 'Active' || (int) ($u['email_verified'] ?? 0) !== 1) { return false; }
        if ((int) ($u['is_demo'] ?? 0) === 1 || (string) ($u['team_role'] ?? '') !== '') { return false; }
        $role = (int) ($u['role_id'] ?? 0);
        $users = new UsersModel();
        $creator = $role === (int) $users->get_role_id_by_name('Creator');
        $admin   = $role === (int) $users->get_role_id_by_name('Admin');
        if (!$creator && !((string) ($u['signup_role'] ?? '') === 'creator' && !$admin)) { return false; }
        if (Plan::has_paid_plan($u) || BillingService::is_paid(BillingService::account($uid))) { return false; }
        if (!LifecycleEmails::deliverable($u)) { return false; }
        return (new NotificationPrefsModel())->email_on($uid, self::CATEGORY);
    }

    /** The deadline stage due now: array(ref, stage) with stage '3d' or 'last', or null. */
    public static function deadline_stage(?int $at = null): ?array {
        $at = $at ?? self::now();
        if (!CreatorPromo::running($at)) { return null; }
        $tz   = new DateTimeZone(CreatorPromo::TZ);
        $end  = new DateTimeImmutable(CreatorPromo::ends(), $tz);
        $now  = (new DateTimeImmutable('@' . $at))->setTimezone($tz);
        if ((int) $now->format('G') < self::DEADLINE_HOUR) { return null; }
        $day  = $now->format('Y-m-d');
        $date = $end->format('Y-m-d');
        if ($day === $date) { return array($date . ':last', 'last'); }
        if ($day === $end->modify('-3 days')->format('Y-m-d')) { return array($date . ':3d', '3d'); }
        return null;
    }

    /* ---------------------------------------------------------------- send */

    /** cron/lifecycle.php: every nurture email due now, at most $limit. Returns how many went out. */
    public static function run(int $limit = self::CAP): int {
        $users = new UsersModel();
        $m     = new LifecycleEmailsModel();
        $sent  = 0;
        $steps = array_keys(self::STEPS);

        $due = array();
        $stage = self::deadline_stage();
        if ($stage) { $due[] = array(self::DEADLINE_KIND, $stage[0], 12, self::DEADLINE_DAYS * 24, '2000-01-01 00:00:00', self::DEADLINE_GAP_HOURS, $stage[1], $steps); }
        foreach (self::STEPS as $kind => $w) {
            $since = $w[2] ?? gmdate('Y-m-d H:i:s', time() - $w[1] * 3600);
            $due[] = array($kind, '', $w[0], $w[1], $since, self::GAP_HOURS, '', array_merge($steps, array(self::DEADLINE_KIND)));
        }

        foreach ($due as $d) {
            list($kind, $ref, $min_h, $max_h, $since, $gap, $st, $spaced) = $d;
            foreach ($users->nurture_candidates($kind, $ref, $min_h, $max_h, $since, $limit) as $c) {
                if ($sent >= $limit) { return $sent; }
                $uid = (int) $c['user_id'];
                if (self::skip_for_test($uid)) { continue; }
                try {
                    if ($m->sent_kinds_since($uid, $spaced, gmdate('Y-m-d H:i:s', time() - $gap * 3600))) { continue; }   // spaced out: a later run
                    if (self::send($uid, $kind, $ref, $st)) { $sent++; }
                } catch (\Throwable $e) { error_log('[nurture] ' . $kind . ' ' . $uid . ': ' . $e->getMessage()); }
            }
        }
        return $sent;
    }

    /** Claim and send one nurture email to one account. Returns true when Postmark accepted it. */
    public static function send(int $uid, string $kind, string $ref = '', string $stage = ''): bool {
        $rows = (new UsersModel())->get_user_by_id($uid);
        $u = is_array($rows) && count($rows) === 1 ? $rows[0] : array();
        if (!$u || !self::eligible($u)) { return false; }   // a plan started, switched off, bounced ...: nothing claimed
        if ($kind === self::DEADLINE_KIND && !self::deadline_stage()) { return false; }
        $m  = new LifecycleEmailsModel();
        if ($kind === self::DEADLINE_KIND && $m->has_ref_suffix($uid, $kind, substr($ref, (int) strrpos($ref, ':')))) { return false; }   // this stage already went out for an earlier end date
        $id = $m->claim($uid, $kind, $ref, 'sent');
        if ($id < 1) { return false; }
        $mail = self::build($kind, $u, Founding::eligible($uid), $stage);
        $html = NotificationsModel::build_lifecycle_email($mail['subject'], $mail['paras'], $mail['facts'], $mail['button'], $mail['link'], $mail['footer'], self::footer_html());
        $to   = array(array('email' => (string) $u['user_email'], 'name' => trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''))));
        $ok   = (new Notifications())->send_email($to, 0, $mail['subject'], $html, Notifications::STREAM_BROADCAST);
        if ($ok) { return true; }
        if ((int) Notifications::$last_error_code === 406) {   // Postmark: inactive recipient (unsubscribed or bounced there): stop for good
            (new NotificationPrefsModel())->save_prefs($uid, array(self::CATEGORY => array('in_platform' => 0, 'email' => 0)));
            $m->set_status($id, 'suppressed');
        } else {
            $m->set_status($id, 'failed');   // not retried: marketing never loops on a broken send
        }
        error_log('[nurture] ' . $kind . ' ' . $uid . ' not sent: ' . Notifications::$last_error);
        return false;
    }

    /** The marketing footer: postal address, unsubscribe link (Postmark), email settings link. */
    public static function footer_html(): string {
        $f = 'margin:10px 0 0; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.55; color:#9a97a8;';
        $a = 'color:#6f6c7b; text-decoration:underline;';
        $settings = rtrim(Main::get_base_domain(), '/') . self::SETTINGS;
        return '<p style="' . $f . '">' . htmlspecialchars(Notifications::POSTAL_ADDRESS, ENT_QUOTES, 'UTF-8') . '</p>'
             . '<p style="' . $f . '"><a href="' . self::UNSUBSCRIBE . '" style="' . $a . '">Unsubscribe</a> &nbsp;·&nbsp; '
             . '<a href="' . htmlspecialchars($settings, ENT_QUOTES, 'UTF-8') . '" style="' . $a . '">Email Settings</a></p>';
    }

    /* ---------------------------------------------------------------- copy (pure) */

    /** Subject, paragraphs, facts, button, link and footer note of one nurture email. */
    public static function build(string $kind, array $u, bool $founding, string $stage = ''): array {
        $plan  = UpgradePrompt::plan($u);
        $t     = PlanTiers::get($plan) ?: PlanTiers::get('creator');
        $name  = (string) ($t['name'] ?? 'Creator');
        $price = (int) ($t['price'] ?? CreatorPromo::list_price());
        $l     = (array) ($t['limits'] ?? array());
        $days  = Founding::TRIAL_DAYS;
        $site  = Main::site_name();
        $return = $kind === 'nurture_day3' ? '/influencers/create' : '';
        $button = $founding ? 'Start Free Trial' : 'Choose a Plan';
        $link   = $founding ? UpgradePrompt::checkout_url($plan, true, $return) : UpgradePrompt::plan_url($plan, $return);
        $trial  = 'Start the ' . $days . '-day free trial: $0 today, and you can cancel in Billing before it ends.';
        $footer = 'You\'re receiving this because you signed up as a creator on ' . $site . '.';
        $facts  = array();

        if ($kind === 'nurture_day1') {
            $subject = 'Your page isn\'t public yet';
            $paras = array(
                'On the Free plan you can build your page, upload media and save drafts. Your page stays private, and fans can\'t follow, subscribe or buy yet.',
                'Going live and selling starts with ' . $name . '. ' . ($founding ? $trial : $name . ' is $' . $price . ' a month.') . ' Everything you\'ve built on Free stays as it is.',
            );
        } elseif ($kind === 'nurture_day3') {
            $subject = 'Make your first AI photo';
            $ai = (int) ($l['ai_credits'] ?? 0);
            $inf = (int) ($l['influencers'] ?? 0);
            $paras = array(
                'Your AI influencer is a consistent character you create once, then use for photos that look like real phone shots. Free accounts can\'t generate images, so this step needs ' . $name . '.',
                $name . ' includes ' . ($inf > 0 ? $inf . ' AI influencer' . ($inf === 1 ? '' : 's') . ' and ' : '') . number_format($ai) . ' AI credits a month. '
                    . ($founding ? $trial . ' When it starts, you go straight to creating your influencer.' : 'Pick the plan and you go straight to creating your influencer.'),
            );
        } elseif ($kind === 'nurture_day7') {
            $subject = 'What you get with ' . $name;
            $paras = array(
                'Here\'s what the ' . $name . ' plan includes, in case you\'re still deciding.',
                $founding ? $trial . ' Your page goes public as soon as it starts.' : 'Your page goes public as soon as the plan starts, and everything you\'ve built on Free stays as it is.',
            );
            $facts[] = array('Price', '$' . $price . ' / month');
            if ($plan === CreatorPromo::PLAN && CreatorPromo::running()) { $facts[] = array('$' . CreatorPromo::price() . ' price ends', CreatorPromo::ends_label()); }
            $facts[] = array('Platform fee', (int) ($l['fee_percent'] ?? 0) . '% of sales');
            if ((int) ($l['ai_credits'] ?? 0) > 0)  { $facts[] = array('AI credits', number_format((int) $l['ai_credits']) . ' / month'); }
            if ((int) ($l['influencers'] ?? 0) > 0) { $facts[] = array('AI influencers', (string) (int) $l['influencers']); }
            if (isset($l['automations']) && (int) $l['automations'] >= 0) { $facts[] = array('Automations', (int) $l['automations'] > 0 ? (string) (int) $l['automations'] : 'Unlimited'); }
            $facts[] = array('Selling', 'Memberships, paid posts, bundles');
        } else {
            $when = CreatorPromo::ends_label();
            $subject = 'The $' . CreatorPromo::price() . ' Founding Creator price ends ' . $when;
            $first = $stage === 'last'
                ? 'Today is the last day to start Creator at $' . CreatorPromo::price() . ' a month. The offer ends tonight at 11:59 PM Eastern.'
                : 'Start Creator by ' . $when . ' (11:59 PM Eastern) and you pay $' . CreatorPromo::price() . ' a month for as long as you stay subscribed.';
            $paras = array(
                $first,
                'From ' . CreatorPromo::regular_from_long() . ', Creator is $' . CreatorPromo::regular() . ' a month for new subscribers. '
                    . ($founding ? 'The ' . $days . '-day free trial still applies: $0 today, then $' . CreatorPromo::price() . ' a month when it ends.' : 'Your price stays at $' . CreatorPromo::price() . ' if you start before then.'),
            );
            $plan = 'creator';
            $link = $founding ? UpgradePrompt::checkout_url('creator', true) : UpgradePrompt::plan_url('creator');
        }
        return array('subject' => $subject, 'paras' => $paras, 'facts' => $facts, 'button' => $button, 'link' => $link, 'footer' => $footer);
    }

    /* ---------------------------------------------------------------- Postmark */

    /**
     * A Postmark SubscriptionChange webhook (broadcast stream): an unsubscribe there turns the Tips and Offers email
     * switch off for that address, a reactivation turns it back on. Returns how many accounts changed.
     */
    public static function subscription_change(array $e): int {
        $email = trim((string) ($e['Recipient'] ?? ''));
        if ($email === '' || (string) ($e['MessageStream'] ?? '') !== Notifications::STREAM_BROADCAST) { return 0; }
        $on = empty($e['SuppressSending']);
        $prefs = new NotificationPrefsModel();
        $n = 0;
        foreach ((new UsersModel())->ids_by_email($email) as $uid) {
            $prefs->save_prefs($uid, array(self::CATEGORY => array('in_platform' => 0, 'email' => $on ? 1 : 0)));
            $n++;
        }
        return $n;
    }
}
