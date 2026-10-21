<?php
/**
 * Creator verification by ID check (2026-10-21, replacing the typed-name request an admin approved by hand). The creator
 * opens a Didit session on the identity workflow (WORKFLOW_ID: ID document + live selfie + face match); Didit's decision turns the verified badge on or off, with no admin step. The admin Verification list is the
 * record: result, the name read from the ID, when. Staff can remove a badge (revoke).
 *
 * The age check (AgeVerification) is separate: it estimates age from a selfie and never reads an ID, so it does not verify
 * identity. Both arrive on the same Didit webhook (WebhookController::diditAction tries the age check first).
 */
class IdentityVerification {

    const PROVIDER = 'didit';
    const WORKFLOW_ID = 'ad62fa05-54ca-4e92-93e0-26a054928dad';   // Didit "Free KYC" (ID document + liveness + face match) in the Creator Link Studio app; app.ini didit_identity_workflow_id overrides it

    private static function workflow(): string {
        $c = Main::get_config();
        $w = trim((string) ($c['global']['didit_identity_workflow_id'] ?? ''));
        return $w !== '' ? $w : self::WORKFLOW_ID;
    }

    /** 'none' | 'pending' | 'approved' | 'rejected' | 'revoked' for the Settings card, plus the decline reason. */
    public static function state(int $user_id): array {
        $r = (new VerificationsModel())->latest_for($user_id);
        if (!$r || (string) ($r['provider'] ?? '') === '') { return array('status' => 'none', 'reason' => ''); }
        return array('status' => (string) $r['status'], 'reason' => (string) ($r['review_note'] ?? ''));
    }

    /**
     * Open the ID check, or carry on with the one already open (Didit keeps a session for 7 days). Returns
     * array('ok', 'url', 'error').
     */
    public static function start(int $user_id): array {
        $u = (new UsersModel())->get_user_by_id($user_id)[0] ?? null;
        if (!$u) { return array('ok' => false, 'url' => '', 'error' => 'Account not found.'); }
        if (!empty($u['verified'])) { return array('ok' => false, 'url' => '', 'error' => 'You\'re already verified.'); }
        $m = new VerificationsModel();
        $last = $m->latest_for($user_id);
        if ($last && (string) $last['status'] === 'pending' && (string) ($last['session_url'] ?? '') !== '' && strtotime($last['created_at'] . ' UTC') > time() - 6 * 86400) {
            $st = DiditProvider::fetch_status((string) $last['provider_ref']);
            if ($st === 'pending' || $st === '') { return array('ok' => true, 'url' => (string) $last['session_url'], 'error' => ''); }
            self::apply_result((string) $last['provider_ref'], $st, $user_id);   // finished meanwhile: record it, then a new session if it failed
            if ($st === 'verified') { return array('ok' => false, 'url' => '', 'error' => 'You\'re already verified.'); }
        }
        if (self::workflow() === '') { return array('ok' => false, 'url' => '', 'error' => 'ID checks are not set up yet. Please try again later.'); }
        $s = DiditProvider::start_workflow($user_id, SeoMeta::base() . '/account/identity_verification', self::workflow());
        if (empty($s['ok'])) {
            error_log('[identity] start failed for user ' . $user_id . ': ' . (string) ($s['error'] ?? ''));
            return array('ok' => false, 'url' => '', 'error' => 'The ID check could not be opened. Please try again.');
        }
        $m->start_session($user_id, self::PROVIDER, (string) $s['ref'], (string) $s['url']);
        return array('ok' => true, 'url' => (string) $s['url'], 'error' => '');
    }

    /** A Didit webhook the age check didn't recognise (signature already verified). True when the session was ours. */
    public static function apply_payload(array $payload): bool {
        $r = DiditProvider::parse_webhook($payload);
        return self::apply_result((string) $r['ref'], (string) $r['status'], (int) $r['user_id']);
    }

    /** Record a final Didit status for one of our sessions: the badge follows it. Unknown or still-pending sessions change nothing. */
    public static function apply_result(string $ref, string $status, int $hint_user_id = 0): bool {
        $m = new VerificationsModel();
        $row = $ref !== '' ? $m->by_ref(self::PROVIDER, $ref) : null;
        if (!$row) { return false; }
        $uid = (int) $row['user_id'];
        if ($hint_user_id > 0 && $hint_user_id !== $uid) { return true; }
        if ((string) $row['status'] !== 'pending' || !in_array($status, array('verified', 'failed'), true)) { return true; }
        $d = DiditProvider::decision($ref) ?: array('name' => '', 'reason' => '');
        $ok = $status === 'verified';
        $m->decide((int) $row['id'], $ok, (string) $d['name'], $ok ? '' : ((string) $d['reason'] !== '' ? (string) $d['reason'] : 'The ID check was not completed or did not pass.'));
        Notify::send($uid, 'system', $ok ? 'You\'re verified' : 'We couldn\'t verify your ID',
            $ok ? 'Your ID check passed. The verified badge now shows on your profile.' : 'Your ID check didn\'t pass. You can try again from Settings.',
            '/account/settings?section=creator', $ok ? 'fa-circle-check' : 'fa-id-card');
        return true;
    }

    /** On the return from Didit: if the webhook hasn't landed yet, ask Didit (a few tries, about 6 s). Returns state()['status']. */
    public static function refresh_if_pending(int $user_id, int $attempts = 4): string {
        for ($i = 0; $i < max(1, $attempts); $i++) {
            $last = (new VerificationsModel())->latest_for($user_id);
            if (!$last || (string) $last['status'] !== 'pending' || (string) ($last['provider_ref'] ?? '') === '') { break; }
            $st = DiditProvider::fetch_status((string) $last['provider_ref']);
            if ($st === 'verified' || $st === 'failed') { self::apply_result((string) $last['provider_ref'], $st, $user_id); break; }
            if ($i + 1 < $attempts) { sleep(2); }
        }
        return self::state($user_id)['status'];
    }
}
