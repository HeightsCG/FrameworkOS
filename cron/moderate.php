<?php
/**
 * Moderation worker — scans recently-uploaded, not-yet-moderated images, runs each
 * through the adult-content classifier, and writes the verdict to media_assets.
 * Invoke from a scheduler (launchd/cron), e.g. every couple of minutes:
 *
 *   * / 2 * * * *  /opt/homebrew/opt/php@8.2/bin/php /private/var/www/contentos.cvk/framework/cron/moderate.php >> /tmp/cls-moderate.log 2>&1
 *
 * Prod crontab (remove the space in "* /2"; it only keeps this comment valid PHP):
 *   * /2 * * * * APPLICATION_ENV=production php /var/www/creatorlinkstudio.com/www/cron/moderate.php >> /var/www/creatorlinkstudio.com/www/cron/moderate.log 2>&1
 *
 * Idle and instant when nothing is pending. Only ready images are considered.
 */

if (php_sapi_name() !== 'cli') { exit(1); }
if (!getenv('APPLICATION_ENV')) { putenv('APPLICATION_ENV=development'); }
date_default_timezone_set('UTC');   // same as Bootstrap: every stored timestamp is UTC, whatever the server's clock is set to

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(function ($class) use ($root) {
    foreach (array("$root/app/models/$class.php", "$root/libs/Classes/$class.php", "$root/app/$class.php") as $src) {
        if (file_exists($src)) { require_once $src; return; }
    }
});

/** Media just marked adult: hold the posts using it whose creator is not age verified (published during the scan). */
function cls_rehold(int $asset_id): void {
    try {
        foreach (AgeVerification::rehold_posts_for_asset($asset_id) as $pid) {
            fwrite(STDOUT, date('c') . " post {$pid} held: adult media, creator not age verified, moved to drafts\n");
        }
    } catch (\Throwable $e) {
        error_log('[moderate] re-hold for asset ' . $asset_id . ' failed: ' . $e->getMessage());
    }
}

CronRuns::start('moderate');
$media = new MediaAssetsModel();
$due   = $media->due_for_moderation(20);
if (empty($due)) { CronRuns::finish('moderate', true, 'nothing due'); exit(0); }

foreach ($due as $a) {
    $id  = (int) $a['id'];
    // Videos: frames taken across the stored file itself (MediaService::moderate_video), never the poster alone,
    // which can come from the uploader's browser. Images: their display rendition.
    if (($a['type'] ?? '') === 'video') {
        try {
            $res = MediaService::moderate_video($a);
        } catch (\Throwable $e) {
            $res = array('ok' => false, 'error' => $e->getMessage());
        }
        if (empty($res['ok'])) {
            error_log('[moderate] video ' . $id . ' failed: ' . ($res['error'] ?? 'unknown'));
            $media->set_moderation($id, 'error');   // stays hidden; retried in 30 minutes
            continue;
        }
        if ($res['status'] === 'blocked') {
            error_log('[MODERATION][BLOCKED] video ' . $id . ' creator ' . $a['creator_id'] . ' — suspected sexual/minors, quarantined.');
        }
        $media->set_moderation($id, $res['status'], $res['score'], $res['labels'], !empty($res['adult']));
        fwrite(STDOUT, date('c') . " video {$id}: {$res['status']} ({$res['frames']} frames, sexual " . $res['score'] . ")\n");
        if ($res['status'] === 'flagged' || !empty($res['adult'])) { cls_rehold($id); }
        continue;
    }
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
    if ($status === 'flagged' || !empty($res['adult'])) { cls_rehold($id); }
}

CronRuns::finish('moderate', true, count($due) . ' item(s)');
exit(0);
