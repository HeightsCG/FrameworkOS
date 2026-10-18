<?php
/** Queue handler for clip editor exports (type clip_render, payload {project_id, creator_id, token}). The render itself is ClipEditActions::run_export. */
class ClipRenderJob {

    public static function handle(array $payload): string {
        $id = (int) ($payload['project_id'] ?? 0); $cid = (int) ($payload['creator_id'] ?? 0);
        if ($id <= 0 || $cid <= 0) { return 'SKIP bad payload'; }
        return ClipEditActions::run_export($id, $cid, (string) ($payload['token'] ?? ''));
    }

    /** Queue-worker sweep: exports stuck 'rendering' with no live queue row (their worker died) are marked failed, as opening the project would. */
    public static function sweep_stuck(): int {
        $live = array();
        foreach ((new JobsModel())->live_payloads('clip_render') as $p) { $live[] = (int) ($p['project_id'] ?? 0); }
        return (new EditProjectsModel())->fail_all_stale_renders(ClipEditActions::STALE_MINUTES, ClipEditActions::STALE_ERROR, $live);
    }
}
