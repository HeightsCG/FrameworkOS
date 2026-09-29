<?php
/**
 * Shared base for the JSON API controllers in app/controllers/api/ (split from the old
 * monolithic ApiController). Owns the CSRF gate for POSTs, the models every request needs,
 * and the helpers that more than one API controller calls. Responses go through
 * Controller::jsonSuccess()/jsonError(): always HTTP 200 with no Content-Type header, because
 * the pages JSON.parse() the text themselves.
 */
class BaseApiController extends Controller {

    public $protected = 1;
    protected $userModel;
    protected $notificationsModel;
    protected $loginAttemptsModel;
    protected $billingModel;

    public function __construct(){
        parent::__construct();

        // POST only: every client call is a POST, and a GET would skip CSRF (a top-level
        // link to /api/delete_my_account would otherwise run with the victim's cookie).
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->jsonError('Method not allowed', [], 405);
        }
        if (!CSRF::validate()) {
            // An idle page outlives its session (and the token in it): hand back a fresh token so the page can retry once
            // (public/js/csrf-retry.js). Only this origin can read the reply, so this gives nothing away.
            $this->jsonError('Your session timed out. Please try again.', ['csrf_expired' => 1, 'csrf_token' => CSRF::token()]);
        }

        $this->userModel          = new UsersModel();
        $this->notificationsModel = new NotificationsModel();
        $this->loginAttemptsModel = new LoginAttemptsModel();
        $this->billingModel       = new BillingModel();
    }

    // ---- Helpers shared by several API controllers (moved verbatim from ApiController; private → protected) ----
    /**
     * Auth + creator gate for the given capability tier ('content' | 'manage' | 'owner').
     * Team members operate on the OWNER's account; their role must permit the capability
     * (Editor: content; Manager: content+manage; Viewer: none; owner-only for 'owner').
     * Returns the OWNER's user array, or exits with a JSON error.
     */
    /**
     * True when nothing of this seller's may be bought: the account is suspended or gone, or it has no paid plan
     * (selling needs Creator or Studio; a lapsed plan pauses sales until they pick one again).
     */
    /** The creator's share of a sale in credits: the price less their plan's platform fee. */
    protected function creator_net($creator_id, $credits): int {
        $row = $this->userModel->get_user_by_id((int) $creator_id);
        $row = (is_array($row) && count($row) === 1) ? $row[0] : null;
        return (int) round((int) $credits * (100 - Plan::fee_percent($row)) / 100);
    }

    protected function seller_suspended(int $creator_id): bool{
        $rows = $this->userModel->get_user_by_id($creator_id);
        if (!is_array($rows) || count($rows) !== 1 || (string) ($rows[0]['user_status'] ?? '') === 'Disabled') { return true; }
        return !Plan::can_use_creator_features($rows[0]);
    }

    protected function require_creator(string $capability = 'content'): array{
        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }
        $acting = $this->userModel->get_user_by_id((int) Session::get('user_id'));
        $acting = (is_array($acting) && count($acting) === 1) ? $acting[0] : null;
        if (!$acting) { $this->jsonError('Not authorized'); }
        // The session copy of team_role is from login; refresh it so a demotion applies at once.
        Session::set('team_role', $acting['team_role'] ?? null);

        // Role gate: a collaborator's team role must allow this capability tier.
        if (!Permissions::team_allows($capability)) {
            $msg = ($capability === 'owner')  ? 'Only the account owner can do this.'
                 : (($capability === 'manage') ? 'Your role can\'t change monetization or integration settings.'
                 : 'Your role is view-only.');
            $this->jsonError((string) ($msg));
        }

        // Team members (collaborators) operate on the OWNER's account.
        $is_team = !empty($acting['team_role']) && (int) ($acting['created_by'] ?? 0) > 0;
        if ($is_team) {
            $user = $this->userModel->get_user_by_id((int) $acting['created_by']);   // the owner
            $user = (is_array($user) && count($user) === 1) ? $user[0] : null;
        } else {
            $user = $acting;
        }

        $creator_role_id = $this->userModel->get_role_id_by_name('Creator');
        if (!$user || (int) $user['role_id'] !== $creator_role_id) {
            $this->jsonError('Only creators can do that');
        }
        // Creator features require an active platform plan on the OWNER account. need_plan lets
        // the frontend send the user to /account/billing to choose one.
        if (!Plan::can_use_creator_features($user)) {
            $this->jsonError('An active plan is required to use creator tools. Choose a plan to continue.', ['need_plan' => true]);
        }
        // Refresh the ACTING user's presence (throttled ~once/45s).
        $last = $acting['last_active_at'] ?? null;
        if (($last === null || strtotime((string) $last . ' UTC') < time() - 45) && !UserSession::impersonating()) {   // an admin viewing as them doesn't count
            $this->userModel->touch_last_active((int) $acting['user_id']);
        }
        return $user;   // the creator/owner row — so downstream creator_id = owner
    }

    /**
     * Deliver a notification across every channel the recipient has enabled for the
     * category: the on-site feed (UserNotificationsModel) and email (NotificationsModel).
     * push() self-gates the in-platform pref; email is gated here (fail-closed on an
     * unknown category). Email send is best-effort and never blocks the response path.
     */
    /** Discount codes can't be guessed: 10 wrong codes per 10 minutes, per IP and per account. Call before checking a code. */
    protected function promo_guard(): void{
        $ip = $this->get_ip_address(); $uid = (int) Session::get('user_id');
        if ($this->loginAttemptsModel->count_recent($ip, 'promo', 10) >= 10
            || ($uid > 0 && $this->loginAttemptsModel->count_recent_for('uid:' . $uid, 'promo', 10) >= 10)) {
            $this->jsonError('Too many codes tried. Wait a few minutes and try again.');
        }
    }

    /** Count a wrong discount code toward promo_guard. */
    protected function promo_miss(): void{
        $this->loginAttemptsModel->record($this->get_ip_address(), 'uid:' . (int) Session::get('user_id'), 'promo');
    }

    protected function notify(int $user_id, string $category, string $title, string $body = '', string $link = '', string $icon = '', bool $email_if_offline = false, bool $force_email = false): void{
        Notify::send($user_id, $category, $title, $body, $link, $icon, $email_if_offline, $force_email);
    }

    /** Online = the account was active within the last 5 minutes (last_active_at is UTC). */
    protected function is_online(array $user_row): bool{
        $la = $user_row['last_active_at'] ?? null;
        return $la !== null && strtotime((string) $la . ' UTC') >= time() - 300;
    }

    /** Convert a creator-local 'YYYY-MM-DDTHH:MM' to a UTC 'Y-m-d H:i:s', or null. */
    protected function to_utc(string $local, string $tz): ?string{
        if ((string) $local === '') { return null; }
        try {
            $d = new DateTime((string) $local, new DateTimeZone($tz ?: 'UTC'));
            $d->setTimezone(new DateTimeZone('UTC'));
            return $d->format('Y-m-d H:i:s');
        } catch (\Throwable $e) { return null; }
    }

    protected function scheduler_next_human(?string $utc, string $tz): string{
        if ((string) $utc === '' || $utc === null) { return ''; }
        try {
            $d = new DateTime((string) $utc, new DateTimeZone('UTC'));
            $d->setTimezone(new DateTimeZone($tz ?: 'UTC'));
            return $d->format('M j, g:i A');
        } catch (\Throwable $e) { return ''; }
    }

    // ---- Inbox automation (Settings > Inbox Automation) -------------------------------

    /** Owner account for inbox automation: Manager+ with an active plan (included on every plan). */
    protected function inbox_user(): array{
        return $this->require_creator('manage');
    }

    /** A price the creator typed in credits (10 to 5,000); a bad price answers with the reason. Everything inside is credits. */
    protected function price_credits($raw, $allow_free = false): int{
        $p = Price::from_credits($raw, $allow_free);
        if (!$p['ok']) { $this->jsonError($p['message']); }
        return (int) $p['credits'];
    }

    /** Is a price field filled in at all? (Empty or 0 means the item is free; anything else must be a valid price.) */
    protected function price_given($raw): bool{
        $s = trim(str_replace(array('$', ','), '', (string) $raw));
        return $s !== '' && (!is_numeric($s) || (float) $s != 0.0);
    }

    /** Answer a Plan::check_count() refusal: message + need_plan/need_upgrade so the UI can link to billing. */
    protected function limitError(array $r): void {
        $extra = array_intersect_key($r, array_flip(['need_plan', 'need_upgrade', 'limit', 'used']));
        $this->jsonError((string) ($r['message'] ?? 'Plan limit reached'), $extra);
    }
}
