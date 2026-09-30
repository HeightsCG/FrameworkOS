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
        return (array) parent::select("SELECT * FROM live_recordings WHERE event_id = :e ORDER BY id DESC LIMIT 50", array('e' => (int) $event_id));
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
