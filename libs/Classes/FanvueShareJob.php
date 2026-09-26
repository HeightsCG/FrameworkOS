<?php
/** Job handler: mirror a post with video to Fanvue in the background (see SocialShareService::share). */
class FanvueShareJob {

    public static function handle(array $payload): string {
        $user_id = (int) ($payload['user_id'] ?? 0);
        $post_id = (int) ($payload['post_id'] ?? 0);
        if ($user_id <= 0 || $post_id <= 0) { throw new InvalidArgumentException('user_id and post_id required'); }
        $rows = (new UsersModel())->get_user_by_id($user_id);
        $user = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        $post = (new PostsModel())->get_by_id($post_id);
        if (!$user || !$post || (int) $post['creator_id'] !== $user_id) { return 'skipped: post or user gone'; }
        if (!empty($post['fanvue_post_uuid'])) { return 'already on Fanvue'; }
        $res = FanvueShareService::share($user, $post, $payload['scheduled_iso'] ?? null);
        if (empty($res['ok'])) {
            Notify::send($user_id, 'system', 'Fanvue share failed', 'Your post could not be shared to Fanvue: ' . $res['error'], '/studio', 'fa-triangle-exclamation');
            return 'failed: ' . $res['error'];
        }
        return 'shared ' . $res['uuid'];
    }
}
