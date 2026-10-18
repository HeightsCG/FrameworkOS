<?php
/** CLS Video recordings of event calls (live_recordings); the flow is in LiveRecording. */
class LiveRecordingsModel extends Model {

    public function add(array $f): int {
        return (int) parent::insert('live_recordings', $f + array('started_at' => gmdate('Y-m-d H:i:s')));
    }

    public function get($id): ?array {
        $r = parent::select("SELECT * FROM live_recordings WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** The recording running in a room right now (recording or stopping), if any. */
    public function active($room): ?array {
        $r = parent::select("SELECT * FROM live_recordings WHERE room = :room AND status IN ('recording', 'stopping') ORDER BY id DESC LIMIT 1", array('room' => (string) $room));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Recording right now (not a stopped one that is still being finished): what the badge shows. */
    public function recording_now($room): ?array {
        $r = parent::select("SELECT * FROM live_recordings WHERE room = :room AND status = 'recording' ORDER BY id DESC LIMIT 1", array('room' => (string) $room));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function for_event($event_id): array {
        // asset_gone: marked ready, but its video was deleted from the Library (or never had a file) — it can't be played, only removed
        return (array) parent::select("SELECT r.*, (r.status = 'ready' AND m.id IS NULL) AS asset_gone FROM live_recordings r
            LEFT JOIN media_assets m ON m.id = r.asset_id AND m.deleted_at IS NULL AND m.original_key <> ''
            WHERE r.event_id = :e AND r.status <> 'deleted' ORDER BY r.id DESC LIMIT 50", array('e' => (int) $event_id));
    }

    /** Every recording of an event that isn't deleted yet (any status), for deleting the event. */
    public function all_for_event($event_id): array {
        return (array) parent::select("SELECT * FROM live_recordings WHERE event_id = :e AND status <> 'deleted'", array('e' => (int) $event_id));
    }

    /** Minutes a recording may sit in stopping/processing before the creator can clear it. */
    const STUCK_MINUTES = 60;

    /** Can the creator delete it: ready or failed, or stuck finishing for over STUCK_MINUTES. */
    public static function deletable(array $rec): bool {
        $st = (string) $rec['status'];
        if (in_array($st, array('ready', 'failed'), true)) { return true; }
        if (!in_array($st, array('stopping', 'processing'), true)) { return false; }
        $since = strtotime((string) (!empty($rec['ended_at']) ? $rec['ended_at'] : $rec['started_at']) . ' UTC');
        return $since !== false && $since < time() - self::STUCK_MINUTES * 60;
    }

    public function set($id, array $f): void {
        parent::update('live_recordings', $f, 'id = :id', array('id' => (int) $id));
    }

    /** recording -> stopping, once. */
    public function mark_stopping($id): bool {
        $sth = $this->db->prepare("UPDATE live_recordings SET status = 'stopping', ended_at = UTC_TIMESTAMP() WHERE id = :id AND status = 'recording'");
        $sth->execute(array(':id' => (int) $id));
        return $sth->rowCount() === 1;
    }

    /** Claim the finished file for processing: only one worker ever wins, so a recording becomes one Library video. */
    public function claim_processing($id): bool {
        $sth = $this->db->prepare("UPDATE live_recordings SET status = 'processing', ended_at = COALESCE(ended_at, UTC_TIMESTAMP()) WHERE id = :id AND status IN ('recording', 'stopping')");
        $sth->execute(array(':id' => (int) $id));
        return $sth->rowCount() === 1;
    }
}
