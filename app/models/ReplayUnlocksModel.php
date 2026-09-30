<?php
/** Who bought an event's replay (replay_unlocks). The flow is in EventReplay. */
class ReplayUnlocksModel extends Model {

    public function has($event_id, $fan_id): bool {
        $r = parent::select("SELECT id FROM replay_unlocks WHERE event_id = :e AND fan_id = :f", array('e' => (int) $event_id, 'f' => (int) $fan_id));
        return is_array($r) && count($r) > 0;
    }

    /** Record the sale first: the UNIQUE (event, fan) key makes a double click one purchase. False if they already own it. */
    public function record($event_id, $creator_id, $fan_id, $price): bool {
        try {
            parent::insert('replay_unlocks', array('event_id' => (int) $event_id, 'fan_id' => (int) $fan_id, 'creator_id' => (int) $creator_id,
                'price_credits' => (int) $price, 'created_at' => gmdate('Y-m-d H:i:s')));
            return true;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { return false; }
            throw $e;
        }
    }

    public function remove($event_id, $fan_id): void {
        parent::delete_all('replay_unlocks', 'event_id = :e AND fan_id = :f', array('e' => (int) $event_id, 'f' => (int) $fan_id));
    }

    public function set_net($event_id, $fan_id, $net): void {
        parent::update('replay_unlocks', array('net_credits' => (int) $net), 'event_id = :e AND fan_id = :f', array('e' => (int) $event_id, 'f' => (int) $fan_id));
    }

    public function count_for_event($event_id): int {
        $r = parent::select("SELECT COUNT(*) AS n FROM replay_unlocks WHERE event_id = :e", array('e' => (int) $event_id));
        return (int) ($r[0]['n'] ?? 0);
    }

    /** A fan's bought replays, for their Purchases page. */
    public function for_fan($fan_id): array {
        return (array) parent::select(
            "SELECT ru.event_id, ru.price_credits, ru.created_at AS purchased_at, e.title,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.u_name) AS creator_name, u.u_name AS creator_handle
             FROM replay_unlocks ru
             JOIN events e ON e.id = ru.event_id
             JOIN user_accounts u ON u.user_id = ru.creator_id
             LEFT JOIN creator_profiles cp ON cp.user_id = u.user_id
             WHERE ru.fan_id = :f ORDER BY ru.id DESC", array('f' => (int) $fan_id));
    }
}
