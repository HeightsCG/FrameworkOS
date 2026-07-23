<?php
/**
 * Moderation worker — scans recently-uploaded, not-yet-moderated images, runs each
 * through the adult-content classifier, and writes the verdict to media_assets.
 * Invoke from a scheduler (launchd/cron), e.g. every couple of minutes:
 *
 *   * / 2 * * * *  /opt/homebrew/opt/php@8.2/bin/php /private/var/www/contentos.cvk/framework/cron/moderate.php >> /tmp/cls-moderate.log 2>&1
 *
 * Idle and instant when nothing is pending. Only ready images are considered.
 */

if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});

$media = new MediaAssetsModel();
$due   = $media->due_for_moderation(20);
if (empty($due)) { exit(0); }

foreach ($due as $a) {
    $id  = (int) $a['id'];
    $key = $a['display_key'] ?: ($a['original_key'] ?: $a['thumb_key']);
    if ($key === '' || $key === null) {
        $media->set_moderation($id, 'error');
        continue;
    }

    try {
        $url = S3Service::presigned_get_url($key, 600);
        $res = ModerationService::classify_image($url);
    } catch (\Throwable $e) {
        error_log('[moderate] asset ' . $id . ' exception: ' . $e->getMessage());
        $media->set_moderation($id, 'error');
        continue;
    }

    if (empty($res['ok'])) {
        error_log('[moderate] asset ' . $id . ' failed: ' . ($res['error'] ?? 'unknown'));
        $media->set_moderation($id, 'error');
        continue;
    }

    // 'blocked' (suspected minors) is a hard stop — quarantined and unpublishable.
    if (!empty($res['minors'])) {
        $status = 'blocked';
        error_log('[MODERATION][BLOCKED] asset ' . $id . ' creator ' . $a['creator_id'] . ' — suspected sexual/minors, quarantined.');
    } else {
        $status = !empty($res['adult']) ? 'flagged' : 'approved';
    }
    $media->set_moderation($id, $status, $res['score'], $res['labels'], !empty($res['adult']));
    fwrite(STDOUT, date('c') . " asset {$id}: {$status} (sexual " . $res['score'] . ')'
        . (!empty($res['minors']) ? ' [SEXUAL/MINORS — BLOCKED]' : '') . "\n");
}

exit(0);
