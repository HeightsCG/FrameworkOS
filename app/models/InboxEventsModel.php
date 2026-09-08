<?php
/**
 * Inbound inbox events (Fanvue webhooks now, internal DMs later). One row per
 * provider event: the UNIQUE (provider, event_id) key IS the idempotency check,
 * `status` makes it a small work queue (webhook request processes it right
 * away; the scheduler worker drains anything left behind), and the stored
 * payload is the audit trail (purged after 30 days).
 */
class InboxEventsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    /** Insert a new event; returns its id, or 0 when this provider event was already recorded. */
    public function record($provider, $event_id, $creator_id, $event_type, $raw_json){
        try {
            return (int) parent::insert('inbox_events', array(
                'provider'    => mb_substr((string) $provider, 0, 10),
                'event_id'    => mb_substr((string) $event_id, 0, 191),
                'creator_id'  => (int) $creator_id,
                'event_type'  => mb_substr((string) $event_type, 0, 64),
                'payload'     => (string) $raw_json,
                'status'      => 'pending',
                'attempts'    => 0,
                'received_at' => date('Y-m-d H:i:s'),
            ));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { return 0; }   // duplicate delivery
            throw $e;
        }
    }

    public function get($id){
        $rows = parent::select("SELECT * FROM inbox_events WHERE id = :id", array('id' => (int) $id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /**
     * Atomically move a pending event to processing. Returns the row, or null if
     * another worker got there first (or it was already finished).
     */
    public function claim($id){
        $n = parent::update('inbox_events',
            array('status' => 'processing', 'attempts' => 1),
            'id = :id AND status = :s', array('id' => (int) $id, 's' => 'pending'));
        if ($n !== 1) { return null; }
        // attempts=1 above resets on a first claim; bump properly for re-claims.
        return $this->get($id);
    }

    /** Re-claim: same as claim() but for a row already in processing (worker retry). */
    public function reclaim($id){
        $row = $this->get($id);
        if (!$row) { return null; }
        $n = parent::update('inbox_events',
            array('status' => 'processing', 'attempts' => (int) $row['attempts'] + 1),
            'id = :id AND status IN (\'pending\', \'processing\')', array('id' => (int) $id));
        return ($n === 1) ? $this->get($id) : null;
    }

    /**
     * Work the in-request path left behind: pending rows older than $older_than_sec,
     * or processing rows stuck > 10 minutes with fewer than 3 attempts.
     */
    public function stale($older_than_sec = 90, $limit = 20){
        return parent::select(
            "SELECT * FROM inbox_events
             WHERE (status = 'pending'    AND received_at < :p)
                OR (status = 'processing' AND received_at < :q AND attempts < 3)
             ORDER BY received_at ASC
             LIMIT " . (int) $limit,
            array(
                'p' => date('Y-m-d H:i:s', time() - (int) $older_than_sec),
                'q' => date('Y-m-d H:i:s', time() - 600),
            )
        );
    }

    public function finish($id, $status, $result = ''){
        return parent::update('inbox_events', array(
            'status'       => mb_substr((string) $status, 0, 12),
            'result'       => mb_substr((string) $result, 0, 255),
            'processed_at' => date('Y-m-d H:i:s'),
        ), 'id = :id', array('id' => (int) $id));
    }

    public function purge_older_than($days = 30){
        return parent::delete_all('inbox_events', 'received_at < :d',
            array('d' => date('Y-m-d H:i:s', time() - (int) $days * 86400)));
    }

}
