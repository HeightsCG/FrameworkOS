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

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !CSRF::validate()) {
            $this->jsonError('Invalid or expired request token');
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
    protected function require_creator(string $capability = 'content'): array{
        if (empty(Session::get('user_id'))) {
            $this->jsonError('Not authorized');
        }
        $acting = $this->userModel->get_user_by_id((int) Session::get('user_id'));
        $acting = (is_array($acting) && count($acting) === 1) ? $acting[0] : null;
        if (!$acting) { $this->jsonError('Not authorized'); }

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
        if ($last === null || strtotime((string) $last . ' UTC') < time() - 45) {
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
    protected function notify(int $user_id, string $category, string $title, string $body = '', string $link = '', string $icon = '', bool $email_if_offline = false): void{
        $user_id = (int) $user_id;
        if ($user_id <= 0 || (string) $title === '') { return; }

        // On-site feed (push re-checks the in-platform pref and no-ops if opted out).
        (new UserNotificationsModel())->push($user_id, $category, $title, $body, $link, $icon);

        // Email channel — only when the category's email pref is on.
        $prefs = (new NotificationPrefsModel())->get_prefs_map($user_id);
        if (empty($prefs[$category]['email'])) { return; }
        $rows = $this->userModel->get_user_by_id($user_id);
        $u    = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$u || (string) $u['user_email'] === '') { return; }
        // Presence-gated categories (DMs): skip the email if the recipient is online and
        // will see it in real time — email is a catch-up nudge for people who are away.
        if ($email_if_offline && $this->is_online($u)) { return; }
        (new NotificationsModel())->send_notification_email(
            (string) $u['user_email'],
            trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')),
            (string) $title, (string) $body, (string) $link);
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

    // ---- Fanvue welcome & trigger messages (Fanvue is the source of truth) --------------

    /** Connected Fanvue account with inbox scopes + a live token, or a JSON error + exit. */
    protected function inbox_fanvue_token(array $user): string{
        $fv = (new FanvueAccountsModel())->get_connected_for_user((int) $user['user_id']);
        if (!$fv) { $this->jsonError('Connect Fanvue in Integrations first.'); }
        if (!FanvueAccountsModel::has_chat_scope($fv)) {
            $this->jsonError('Reconnect Fanvue to grant inbox access.', ['need_reconnect' => true]);
        }
        $token = FanvueService::access_token_for($fv);
        if ($token === '') { $this->jsonError('Fanvue session expired. Reconnect in Settings > Integrations.'); }
        return $token;
    }

    /** Answer a Plan::check_count() refusal: message + need_plan/need_upgrade so the UI can link to billing. */
    protected function limitError(array $r): void {
        $extra = array_intersect_key($r, array_flip(['need_plan', 'need_upgrade', 'limit', 'used']));
        $this->jsonError((string) ($r['message'] ?? 'Plan limit reached'), $extra);
    }
}
