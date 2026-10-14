<?php
/**
 * Job handler: cross-post the destinations of a post that were held because an adjusted image was waiting for the
 * creator's approval (SocialShareService / MediaCompliance). It checks back every few minutes; once approved, those
 * accounts are shared (at the scheduled time if it is still ahead). Gives up after HOLD_DAYS or when the post is gone.
 * payload: creator_id, post_id, held (account id => placements waiting: feed / story), scheduled_iso, since (when it was first held), round.
 */
class ShareHeldJob {

    const CHECK_MINUTES = 5;
    const HOLD_DAYS     = 7;

    /** Hold these placements of $post_id (account id => [feed, story]) until their images are approved (one check chain per post and round). */
    public static function queue($cid, $post_id, array $held, $scheduled_iso = null, $since = null, $round = 0): void {
        (new DatabaseJobQueue())->dispatch('share_held', array('creator_id' => (int) $cid, 'post_id' => (int) $post_id,
            'held' => $held, 'scheduled_iso' => $scheduled_iso, 'since' => $since ?: gmdate('c'), 'round' => (int) $round),
            'share_held:' . (int) $post_id . ':' . (int) $round, gmdate('Y-m-d H:i:s', time() + ($round > 0 ? self::CHECK_MINUTES * 60 : 30)));
    }

    public static function handle(array $payload): string {
        $cid = (int) ($payload['creator_id'] ?? 0); $pid = (int) ($payload['post_id'] ?? 0);
        $post = (new PostsModel())->get_one($cid, $pid);
        if (!$post || !in_array((string) $post['state'], array('published', 'scheduled'), true)) { return 'SKIP post gone or not published'; }
        $rows = (new UsersModel())->get_user_by_id($cid);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$user) { return 'SKIP no user'; }
        if (strtotime((string) ($payload['since'] ?? 'now')) < time() - self::HOLD_DAYS * 86400) { return 'SKIP held too long'; }
        $held = array();
        foreach ((array) ($payload['held'] ?? array()) as $id => $pls) { $held[(string) $id] = array_values((array) $pls); }
        if (empty($held)) { return 'OK nothing left to send'; }
        $when = (string) ($payload['scheduled_iso'] ?? '');
        $when = ($when !== '' && strtotime($when) > time() + 60) ? $when : null;
        $r = SocialShareService::share($user, $post, array_keys($held), $when, array('from_held' => true, 'places' => $held));
        $still = array_intersect_key((array) ($r['held_places'] ?? array()), $held);
        if (!empty($still)) {
            self::queue($cid, $pid, $still, $payload['scheduled_iso'] ?? null, $payload['since'] ?? null, (int) ($payload['round'] ?? 0) + 1);
            return 'WAIT ' . count($still) . ' still waiting for approval';
        }
        return 'OK shared ' . (int) ($r['shared'] ?? 0) . ($r['error'] ?? '' ? ' (' . $r['error'] . ')' : '');
    }
}
