<?php
/**
 * CLS Video call state (live_rooms) and the waiting room (live_waiting). A room's row holds the settings of the call
 * while it runs; it is made from the event's settings when the first person joins (LiveControl::state) and the host
 * changes it from the call. live_waiting keeps, per room and person: 'waiting' (asked to come in), 'admitted' (let in,
 * or joined when no waiting room was on: they can rejoin freely, also once the call is locked) or 'denied'.
 */
class LiveRoomsModel extends Model {

    const SEEN_SECONDS = 20;   // a waiting page checks in every few seconds; older rows are people who gave up

    public function get($room): ?array {
        $r = parent::select("SELECT * FROM live_rooms WHERE room = :room", array('room' => (string) $room));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** First join: store the starting settings (a second request at the same moment keeps the first row). */
    public function init($room, array $s): void {
        parent::sql("INSERT IGNORE INTO live_rooms (room, waiting, share, watch, chat, locked, spotlight, speakers, updated_at)
                     VALUES (:room, :waiting, :share, :watch, :chat, 0, NULL, NULL, UTC_TIMESTAMP())",
            array(':room' => (string) $room, ':waiting' => (int) $s['waiting'], ':share' => (string) $s['share'], ':watch' => (int) $s['watch'], ':chat' => (int) $s['chat']));
    }

    public function set($room, array $f): void {
        $f['updated_at'] = gmdate('Y-m-d H:i:s');
        parent::update('live_rooms', $f, 'room = :room', array('room' => (string) $room));
    }

    /** The event's settings changed: the next call starts from them. People already let in stay let in. */
    public function reset($room): void {
        parent::delete_all('live_rooms', 'room = :room', array('room' => (string) $room));
    }

    /* ---- waiting room ---- */

    public function entry($room, $identity): ?array {
        $r = parent::select("SELECT * FROM live_waiting WHERE room = :room AND identity = :who", array('room' => (string) $room, 'who' => (string) $identity));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    /** Ask to come in (or say "still here"). A decision already made is kept. */
    public function wait($room, $identity, $name): void {
        parent::sql("INSERT INTO live_waiting (room, identity, name, status, requested_at, seen_at)
                     VALUES (:room, :who, :name, 'waiting', UTC_TIMESTAMP(), UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE seen_at = UTC_TIMESTAMP(), name = VALUES(name)",
            array(':room' => (string) $room, ':who' => (string) $identity, ':name' => mb_substr((string) $name, 0, 80)));
    }

    /** Let in (also records everyone who joins with no waiting room on, so they can rejoin a locked call). */
    public function admit($room, $identity, $name = null): void {
        parent::sql("INSERT INTO live_waiting (room, identity, name, status, requested_at, seen_at, decided_at)
                     VALUES (:room, :who, :name, 'admitted', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE status = 'admitted', decided_at = UTC_TIMESTAMP(), seen_at = UTC_TIMESTAMP()"
                     . ($name !== null ? ", name = VALUES(name)" : ''),
            array(':room' => (string) $room, ':who' => (string) $identity, ':name' => mb_substr((string) ($name ?? ''), 0, 80)));
    }

    public function deny($room, $identity): void {
        parent::sql("UPDATE live_waiting SET status = 'denied', decided_at = UTC_TIMESTAMP() WHERE room = :room AND identity = :who AND status = 'waiting'",
            array(':room' => (string) $room, ':who' => (string) $identity));
    }

    /** Turned away whatever their status (the host removed them from the call). */
    public function deny_any($room, $identity): void {
        parent::sql("UPDATE live_waiting SET status = 'denied', decided_at = UTC_TIMESTAMP() WHERE room = :room AND identity = :who",
            array(':room' => (string) $room, ':who' => (string) $identity));
    }

    /** Everyone still waiting (their page checked in lately), longest wait first. */
    public function waiting($room): array {
        return (array) parent::select(
            "SELECT identity, name, requested_at FROM live_waiting
             WHERE room = :room AND status = 'waiting' AND seen_at >= UTC_TIMESTAMP() - INTERVAL " . self::SEEN_SECONDS . " SECOND
             ORDER BY requested_at, id LIMIT 200", array('room' => (string) $room));
    }

    /** Let everyone waiting in at once (the host's Admit All, or the waiting room being switched off). */
    public function admit_all($room): int {
        $n = count($this->waiting($room));
        parent::sql("UPDATE live_waiting SET status = 'admitted', decided_at = UTC_TIMESTAMP() WHERE room = :room AND status = 'waiting'", array(':room' => (string) $room));
        return $n;
    }
}
