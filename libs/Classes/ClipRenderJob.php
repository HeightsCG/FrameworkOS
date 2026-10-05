<?php
/** Queue handler for clip editor exports (type clip_render, payload {project_id, creator_id, token}). The render itself is ClipEditActions::run_export. */
class ClipRenderJob {

    public static function handle(array $payload): string {
        $id = (int) ($payload['project_id'] ?? 0); $cid = (int) ($payload['creator_id'] ?? 0);
        if ($id <= 0 || $cid <= 0) { return 'SKIP bad payload'; }
        return ClipEditActions::run_export($id, $cid, (string) ($payload['token'] ?? ''));
    }
}
