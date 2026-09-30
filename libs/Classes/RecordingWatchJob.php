<?php
/** Job handler: check one CLS Video recording (time limit, still writing, finished → Library video). See LiveRecording::tick. */
class RecordingWatchJob {

    public static function handle(array $payload): string {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) { throw new InvalidArgumentException('id required'); }
        return LiveRecording::tick($id);
    }
}
