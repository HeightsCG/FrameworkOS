<?php
/** Job handler: build the public WebP renditions for a newly published post's media, or purge copies that stopped qualifying (PublicThumbService). */
class PublicThumbJob {

    public static function handle(array $payload): string {
        if (!empty($payload['purge']) && is_array($payload['purge'])) {
            $out = 'public copies purged ' . PublicThumbService::purge_stale($payload['purge']);
            // a post edit can also make media qualify (switched to free): build what's missing.
            if (!empty($payload['purge']['post'])) { $out .= ', built ' . PublicThumbService::sync_post((int) $payload['purge']['post']); }
            return $out;
        }
        $post_id = (int) ($payload['post_id'] ?? 0);
        if ($post_id <= 0) { throw new InvalidArgumentException('post_id required'); }
        return 'public renditions ' . PublicThumbService::sync_post($post_id);
    }
}
