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

    /** Every API answer passes the TikTok funnel hook first (checkout started, card added, paywall hit); it never throws. */
    protected function jsonSuccess(array $data = [], int $httpCode = 200): void {
        TikTokEventJob::from_api($this->api_action(), true, $data, (array) $this->post);
        parent::jsonSuccess($data, $httpCode);
    }

    protected function jsonError(string $message, array $extra = [], int $httpCode = 200): void {
        TikTokEventJob::from_api($this->api_action(), false, $extra, (array) $this->post);
        parent::jsonError($message, $extra, $httpCode);
    }

    private function api_action(): string {
        return strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) (Main::get_url()[1] ?? '')));
    }

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
    /** Affiliate payouts need a confirmed email; nothing else does. need_verify lets a page offer Resend Email. */
    protected function require_verified_email(int $user_id): void{
        $rows = $this->userModel->get_user_by_id($user_id);
        if (is_array($rows) && count($rows) === 1 && (int) ($rows[0]['email_verified'] ?? 0) === 1) { return; }
        $this->jsonError('Confirm your email before setting up payouts. Use Resend Email on your dashboard if the link did not arrive.', ['need_verify' => true]);
    }

    /**
     * True when this creator has no public presence: the account is suspended or gone, or it has no paid plan
     * (Creator or Studio; a lapsed plan hides it until they pick one again).
     */
    protected function seller_suspended(int $creator_id): bool{
        $rows = $this->userModel->get_user_by_id($creator_id);
        if (!is_array($rows) || count($rows) !== 1 || (string) ($rows[0]['user_status'] ?? '') === 'Disabled') { return true; }
        return !Plan::can_use_creator_features($rows[0]);
    }

    protected function require_creator(string $capability = 'content', bool|string $need_plan = true, string $action = ''): array{
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

        if ($is_team && !Plan::account_active($user)) { $this->jsonError(Plan::OWNER_SUSPENDED_MESSAGE); }   // owner suspended or gone
        if (!$is_team && !Plan::account_active($user)) { $this->jsonError(LoginGate::SUSPENDED_MESSAGE); }

        $creator_role_id = $this->userModel->get_role_id_by_name('Creator');
        if (!$user || (int) $user['role_id'] !== $creator_role_id) {
            $this->jsonError('Only creators can do that');
        }
        // $need_plan: true = a paid plan (creator tools), 'ai' = a paid plan for AI, false = building, which Free can do
        // (Plan::can_build). Billing passes false too: a Free creator has to be able to buy the plan.
        if ($need_plan === 'ai' && !Plan::can_use_ai($user)) { $this->need_plan($action !== '' ? $action : 'use AI tools'); }
        if ($need_plan === true && !Plan::can_use_creator_features($user)) { $this->need_plan($action !== '' ? $action : 'use this'); }
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

    /** Owner account for inbox automation: Manager+ with a paid plan (the DM agent is AI). */
    protected function inbox_user(): array{
        return $this->require_creator('manage', 'ai', 'use inbox automation');
    }

    /** On Free a creator can switch things off but not back on: turning on needs a paid plan. */
    protected function plan_to_turn_on(array $user, bool $on): void{
        if ($on && !Plan::can_use_creator_features($user)) { $this->need_plan('turn this on'); }
    }

    /** Refuse with the upgrade prompt (site.js cls_need_plan): the Creator plan, and the page to come back to after checkout. */
    protected function need_plan(string $action): void{
        $t = PlanTiers::TIERS['creator'];
        if (in_array($action, array('use this', 'turn this on', 'use AI tools'), true)) {   // the generic defaults: name the action this request was for
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $f) {
                if (preg_match('/^([a-z0-9_]+)Action$/', (string) ($f['function'] ?? ''), $m)) { $w = UpgradePrompt::action_words($m[1]); if ($w !== '') { $action = $w; } break; }
            }
        }
        // founding_eligible: the prompt offers the 14-day trial and goes straight into checkout
        $this->jsonError('Upgrade to ' . $t['name'] . ' to ' . $action . '.', ['plan' => $t['key']] + UpgradePrompt::payload(UpgradePrompt::owner(), $this->upgrade_return()));
    }

    /** The same-site page this request came from (path and query), or ''. */
    protected function upgrade_return(): string{
        $p = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if (!is_array($p) || $host === '' || strtolower((string) ($p['host'] ?? '')) !== $host) { return ''; }
        $path = (string) ($p['path'] ?? '');
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || strpos($path, '\\') !== false) { return ''; }
        return $path . ((string) ($p['query'] ?? '') !== '' ? '?' . $p['query'] : '');
    }

    /** Answer a Plan::check_count() refusal: message + need_plan/need_upgrade so the UI can link to billing. */
    protected function limitError(array $r): void {
        $extra = array_intersect_key($r, array_flip(['need_plan', 'need_upgrade', 'limit', 'used']));
        $this->jsonError((string) ($r['message'] ?? 'Plan limit reached'), $extra);
    }
}
