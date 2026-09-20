<?php
/** Job handler: tell a creator's audience about a newly published post. */
class PostNotifyJob {

    public static function handle(array $payload): string {
        $creator_id = (int) ($payload['creator_id'] ?? 0);
        $post_id    = (int) ($payload['post_id'] ?? 0);
        if ($creator_id <= 0 || $post_id <= 0) { throw new InvalidArgumentException('creator_id and post_id required'); }
        $n = PostNotifier::deliver($creator_id, $post_id, PostNotifier::audience_ids($creator_id));
        return 'notified ' . $n;
    }
}
