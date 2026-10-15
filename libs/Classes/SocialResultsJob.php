<?php
/**
 * Job handler: read back what each platform did with a cross-post. Post for Me accepts a post first and publishes it
 * to each account a moment later; a platform can still refuse it then (YouTube's daily upload limit, an expired login,
 * a file the platform rejects). Without this check the post editor kept showing those accounts as "Posted" and would
 * not send to them again.
 *
 * Queued for every Post for Me post SocialShareService creates (after its scheduled time, when it has one). For each
 * account that failed: the account comes off the social_posts row (so the editor shows it unticked and it can be sent
 * again, from the editor or from Share to Social), and the creator gets one notice naming the platforms and the reason.
 * Checks back while the post is still being published, up to MAX_ROUNDS.
 * payload: user_id, post_id (studio post), pfm_post_id, round.
 */
class SocialResultsJob {

    const FIRST_CHECK_SECONDS = 180;
    const RECHECK_SECONDS     = 300;
    const MAX_ROUNDS          = 12;

    public static function queue($user_id, $post_id, $pfm_post_id, $scheduled_iso = null, $round = 0): void {
        try {
            $at = time() + ($round > 0 ? self::RECHECK_SECONDS : self::FIRST_CHECK_SECONDS);
            if ($scheduled_iso && strtotime($scheduled_iso) > time()) { $at = strtotime($scheduled_iso) + self::FIRST_CHECK_SECONDS; }
            (new DatabaseJobQueue())->dispatch('social_results', array('user_id' => (int) $user_id, 'post_id' => (int) $post_id, 'pfm_post_id' => (string) $pfm_post_id, 'round' => (int) $round),
                'social_results:' . $pfm_post_id . ':' . (int) $round, gmdate('Y-m-d H:i:s', $at));
        } catch (\Throwable $e) {
            error_log('[social results] queue ' . $pfm_post_id . ': ' . $e->getMessage());
        }
    }

    public static function handle(array $payload): string {
        $uid = (int) ($payload['user_id'] ?? 0); $pid = (int) ($payload['post_id'] ?? 0);
        $pfm = (string) ($payload['pfm_post_id'] ?? ''); $round = (int) ($payload['round'] ?? 0);
        if ($uid <= 0 || $pfm === '') { throw new InvalidArgumentException('user_id and pfm_post_id required'); }
        $sp = new SocialPostsModel();
        $row = $sp->get_by_pfm_id($pfm);
        if (!$row) { return 'SKIP social post gone'; }
        $targets = json_decode((string) $row['target_account_ids'], true) ?: array();

        $results = PostForMeService::get_results($pfm);
        if ($results === null) { throw new RuntimeException('Post for Me did not answer'); }   // retried with back-off
        $seen = array(); $failed = array();
        foreach ($results as $r) {
            $acct = (string) ($r['social_account_id'] ?? '');
            $seen[$acct] = true;
            if (empty($r['success'])) { $failed[$acct] = (string) (is_string($r['error'] ?? null) ? $r['error'] : json_encode($r['error'] ?? '')); }
        }
        $waiting = array_diff(array_map('strval', $targets), array_keys($seen));
        if (!empty($waiting) && $round < self::MAX_ROUNDS) { self::queue($uid, $pid, $pfm, null, $round + 1); }   // some platforms not done yet
        if (empty($failed)) { return 'OK ' . count($seen) . ' published' . (!empty($waiting) ? ', ' . count($waiting) . ' still publishing' : ''); }

        $sp->remove_accounts($pfm, array_keys($failed));
        $accts = new SocialAccountsModel();
        $names = array(); $lines = array();
        foreach ($failed as $acct => $err) {
            $a = $accts->get_by_pfm_id($acct);
            $names[] = MediaCompliance::name((string) ($a['platform'] ?? ''));
            $lines[] = end($names) . ': ' . self::reason($err);
        }
        Notify::send($uid, 'system', 'Not posted to ' . implode(', ', array_unique($names)),
            (count($failed) === 1 ? self::reason(reset($failed)) : implode(' ', $lines)) . ' Open the post and tick the account again, or use Share to Social, to send it again.',
            $pid > 0 ? '/studio?post=' . $pid : '/studio', 'fa-triangle-exclamation');
        return 'FAILED on ' . count($failed) . ': ' . mb_substr(implode(' | ', $lines), 0, 300);
    }

    /** The platform's refusal in a few plain words. */
    public static function reason($err): string {
        $e = (string) $err;
        if (stripos($e, 'Video Uploads per day') !== false || (stripos($e, 'youtube') !== false && stripos($e, 'quota') !== false)) {
            return 'YouTube\'s daily upload limit was reached. Try again tomorrow.';
        }
        if (stripos($e, '429') !== false || stripos($e, 'rate limit') !== false || stripos($e, 'Too Many Requests') !== false) { return 'The platform is limiting posts right now. Try again later.'; }
        if (stripos($e, 'token') !== false && (stripos($e, 'expired') !== false || stripos($e, 'invalid') !== false)) { return 'The account needs to be reconnected in Settings.'; }
        $e = trim(preg_replace('/\s+/', ' ', preg_replace('/\{.*$/s', '', $e)));
        return $e !== '' ? rtrim(mb_substr($e, 0, 160), '.') . '.' : 'The platform refused it.';
    }
}
