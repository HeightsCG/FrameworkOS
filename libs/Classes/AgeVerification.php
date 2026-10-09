<?php
/**
 * Age verification, triggered by exactly two things: a fan turning on "Show adult content" and a creator publishing a
 * post whose media is adult (media_assets.is_adult / flagged). Nothing else in the product calls this class
 * (tests/age_verification_test.php keeps a list of the files that may).
 *
 * One verification per account, reused by both triggers. The vendor sits behind AgeVerificationProvider ($provider_class,
 * from app.ini age_verification_provider: 'didit' -> DiditProvider). We store status + the vendor's session reference,
 * never images, documents or birth dates.
 */
class AgeVerification {

    /** Provider class; tests set 'FakeAgeProvider'. */
    public static $provider_class = '';

    const HELD_REASON = 'age_verification';   // posts.held_reason for an adult post held at release time

    public static function provider(): string {
        if (self::$provider_class !== '') { return self::$provider_class; }
        $c = Main::get_config();
        $key = strtolower(trim((string) ($c['global']['age_verification_provider'] ?? 'didit')));
        $map = array('didit' => 'DiditProvider');
        return $map[$key] ?? 'DiditProvider';
    }

    /** 'none' | 'pending' | 'verified' | 'failed' */
    public static function status(int $user_id): string {
        $r = (new AgeVerificationsModel())->get($user_id);
        return $r ? (string) $r['status'] : 'none';
    }

    public static function is_verified(int $user_id): bool {
        return (new AgeVerificationsModel())->is_verified($user_id);
    }

    /** The row (status, provider, provider_ref, verified_at, created_at, updated_at) or null. For the admin record and Settings. */
    public static function record(int $user_id) {
        return (new AgeVerificationsModel())->get($user_id);
    }

    /**
     * THE fan gate. Explicit content is shown only to an account that turned the toggle on AND is age verified.
     * $user is a user_accounts row (needs user_id and adult_content_enabled).
     */
    public static function adult_allowed($user): bool {
        if (!is_array($user) || empty($user['adult_content_enabled'])) { return false; }
        return self::is_verified((int) ($user['user_id'] ?? 0));
    }

    /**
     * Open (or retry) a verification for an account. Refused when already verified: one verification per account.
     * $return_path: the same-site page to land on afterwards (the vendor's redirect goes through /account/age_verification).
     * Returns array('ok' => bool, 'url' => hosted URL, 'error' => string).
     */
    public static function start(int $user_id, string $return_path): array {
        $model = new AgeVerificationsModel();
        if ($model->is_verified($user_id)) { return array('ok' => false, 'url' => '', 'error' => 'This account is already age verified.'); }
        $p = self::provider();
        $return_url = SeoMeta::base() . '/account/age_verification?return=' . rawurlencode(self::safe_path($return_path));
        $s = $p::start($user_id, $return_url);
        if (empty($s['ok']) || (string) ($s['ref'] ?? '') === '' || (string) ($s['url'] ?? '') === '') {
            error_log('[age_verification] start failed for user ' . $user_id . ': ' . (string) ($s['error'] ?? 'no session'));
            return array('ok' => false, 'url' => '', 'error' => (string) ($s['error'] ?? 'The verification service did not answer.'));
        }
        if (!$model->start_pending($user_id, $p::key(), (string) $s['ref'])) {
            return array('ok' => false, 'url' => '', 'error' => 'Could not record the verification.');
        }
        return array('ok' => true, 'url' => (string) $s['url'], 'error' => '');
    }

    /**
     * The vendor's result callback. Verifies the signature first; an unknown reference is acknowledged and ignored
     * (so the vendor stops retrying) and a verified account is never downgraded. Returns array('ok', 'user_id', 'status').
     */
    public static function apply_webhook(string $raw, array $headers): array {
        $p = self::provider();
        if (!$p::verify_webhook($raw, $headers)) { return array('ok' => false, 'user_id' => 0, 'status' => ''); }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) { return array('ok' => false, 'user_id' => 0, 'status' => ''); }
        $r = $p::parse_webhook($payload);
        return self::apply_result((string) $r['ref'], (string) $r['status'], (int) $r['user_id']);
    }

    /** Record a provider status for a session reference. Only the account that owns the reference is touched. */
    public static function apply_result(string $ref, string $status, int $hint_user_id = 0): array {
        $model = new AgeVerificationsModel();
        $row = $ref !== '' ? $model->by_ref(self::provider()::key(), $ref) : null;
        if (!$row) { return array('ok' => true, 'user_id' => 0, 'status' => 'ignored'); }   // not ours (or an old session): acknowledged, nothing changes
        $uid = (int) $row['user_id'];
        if ($hint_user_id > 0 && $hint_user_id !== $uid) { return array('ok' => true, 'user_id' => 0, 'status' => 'ignored'); }
        if ((string) $row['status'] === 'verified') { return array('ok' => true, 'user_id' => $uid, 'status' => 'verified'); }
        if ($status === 'verified') { $model->mark($uid, 'verified', gmdate('Y-m-d H:i:s')); }
        elseif ($status === 'failed') { $model->mark($uid, 'failed', null); }
        return array('ok' => true, 'user_id' => $uid, 'status' => self::status($uid));
    }

    /** On the return landing: if the webhook has not arrived yet, ask the vendor once. Returns the status afterwards. */
    public static function refresh_if_pending(int $user_id): string {
        $row = (new AgeVerificationsModel())->get($user_id);
        if (!$row || (string) $row['status'] !== 'pending') { return $row ? (string) $row['status'] : 'none'; }
        $p = self::provider();
        $s = $p::fetch_status((string) $row['provider_ref']);
        if ($s === 'verified' || $s === 'failed') { self::apply_result((string) $row['provider_ref'], $s, $user_id); }
        return self::status($user_id);
    }

    /** Trigger 2's predicate: a post is adult when any of its media is flagged or approved-as-adult (PostsModel). */
    public static function post_is_adult(int $post_id): bool {
        $map = (new PostsModel())->studio_moderation_map(array($post_id));
        return (string) ($map[$post_id] ?? '') === 'adult';
    }

    /**
     * A due scheduled post with adult media whose creator is not verified goes back to drafts (nothing is deleted),
     * like Plan::hold_unsellable_post. True when the post was held.
     */
    public static function hold_unverified_adult_post(int $creator_id, int $post_id): bool {
        if (!self::post_is_adult($post_id) || self::is_verified($creator_id)) { return false; }
        (new PostsModel())->hold_for_plan($creator_id, $post_id, self::HELD_REASON);
        Notify::send($creator_id, 'creator_activity', 'Your scheduled post was held',
            'It is marked adult, and adult posts publish once your age is verified. It is in your drafts: verify once in Settings, then publish it.', '/account/settings#privacy', 'fa-id-card');
        return true;
    }

    /** A same-site path (no host, no scheme), else '/'. */
    public static function safe_path(string $path): string {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || strpos($path, '\\') !== false) { return '/'; }
        return $path;
    }
}
