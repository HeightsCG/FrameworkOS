<?php
/**
 * Every reply the inbox automation considered: drafts waiting for approval,
 * sent messages (with the provider's message id so we can tell our own sends
 * apart from the creator's), dismissed drafts, and skips with their reason.
 */
class InboxRepliesModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public function create(array $d){
        return (int) parent::insert('inbox_replies', array(
            'creator_id'          => (int) $d['creator_id'],
            'channel'             => mb_substr((string) $d['channel'], 0, 10),
            'peer_key'            => mb_substr((string) $d['peer_key'], 0, 64),
            'peer_name'           => mb_substr((string) ($d['peer_name'] ?? ''), 0, 255),
            'event_id'            => isset($d['event_id']) ? (int) $d['event_id'] : null,
            'inbound_text'        => isset($d['inbound_text']) ? (string) $d['inbound_text'] : null,
            'draft_text'          => isset($d['draft_text']) ? (string) $d['draft_text'] : null,
            'final_text'          => null,
            'status'              => mb_substr((string) $d['status'], 0, 16),
            'reason'              => isset($d['reason']) ? mb_substr((string) $d['reason'], 0, 64) : null,
            'provider_message_id' => null,
            'decided_by'          => null,
            'error'               => null,
            'created_at'          => date('Y-m-d H:i:s'),
            'sent_at'             => null,
        ));
    }

    public function get_one($creator_id, $id){
        $rows = parent::select("SELECT * FROM inbox_replies WHERE id = :id AND creator_id = :c",
            array('id' => (int) $id, 'c' => (int) $creator_id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function pending_for_creator($creator_id, $limit = 50){
        return parent::select(
            "SELECT * FROM inbox_replies WHERE creator_id = :c AND status = 'pending_approval'
             ORDER BY created_at DESC LIMIT " . (int) $limit,
            array('c' => (int) $creator_id));
    }

    public function recent_for_creator($creator_id, $limit = 20){
        return parent::select(
            "SELECT * FROM inbox_replies WHERE creator_id = :c AND status <> 'pending_approval'
             ORDER BY created_at DESC LIMIT " . (int) $limit,
            array('c' => (int) $creator_id));
    }

    public function count_pending($creator_id){
        $rows = parent::select("SELECT COUNT(*) AS n FROM inbox_replies WHERE creator_id = :c AND status = 'pending_approval'",
            array('c' => (int) $creator_id));
        return (int) ($rows[0]['n'] ?? 0);
    }

    public function set_status($id, $status, $reason = null){
        $data = array('status' => mb_substr((string) $status, 0, 16));
        if ($reason !== null) { $data['reason'] = mb_substr((string) $reason, 0, 64); }
        return parent::update('inbox_replies', $data, 'id = :id', array('id' => (int) $id));
    }

    public function mark_sent($id, $final_text, $provider_message_id, $decided_by = null){
        return parent::update('inbox_replies', array(
            'status'              => 'sent',
            'final_text'          => (string) $final_text,
            'provider_message_id' => mb_substr((string) $provider_message_id, 0, 64),
            'decided_by'          => ($decided_by !== null) ? (int) $decided_by : null,
            'error'               => null,
            'sent_at'             => date('Y-m-d H:i:s'),
        ), 'id = :id', array('id' => (int) $id));
    }

    public function mark_failed($id, $error){
        return parent::update('inbox_replies', array(
            'status' => 'failed',
            'error'  => mb_substr((string) $error, 0, 255),
        ), 'id = :id', array('id' => (int) $id));
    }

    public function mark_dismissed($id, $decided_by, $reason = 'dismissed'){
        return parent::update('inbox_replies', array(
            'status'     => 'dismissed',
            'reason'     => mb_substr((string) $reason, 0, 64),
            'decided_by' => ($decided_by !== null) ? (int) $decided_by : null,
        ), 'id = :id AND status = \'pending_approval\'', array('id' => (int) $id));
    }

    /** Drop every waiting draft for one fan (creator answered by hand, or a newer draft supersedes it). */
    public function dismiss_pending_for_peer($creator_id, $channel, $peer_key, $reason){
        return parent::update('inbox_replies', array(
            'status' => 'dismissed',
            'reason' => mb_substr((string) $reason, 0, 64),
        ), 'creator_id = :c AND channel = :ch AND peer_key = :p AND status = \'pending_approval\'',
           array('c' => (int) $creator_id, 'ch' => (string) $channel, 'p' => (string) $peer_key));
    }

    /**
     * Non-blocking per-conversation lock (MySQL GET_LOCK, freed when this request's connection
     * closes). A burst of fan messages is handled in parallel requests; only the one holding the
     * lock drafts a reply, so the burst can't multiply AI calls past the rate cap.
     */
    public function try_peer_lock($creator_id, $channel, $peer_key){
        $key  = 'ibx:' . substr(sha1((int) $creator_id . '|' . (string) $channel . '|' . (string) $peer_key), 0, 40);
        $rows = parent::select("SELECT GET_LOCK(:k, 0) AS got", array('k' => $key));
        return (int) ($rows[0]['got'] ?? 0) === 1;
    }

    public function last_sent_at_for_peer($creator_id, $channel, $peer_key){
        $rows = parent::select(
            "SELECT MAX(sent_at) AS t FROM inbox_replies
             WHERE creator_id = :c AND channel = :ch AND peer_key = :p AND status = 'sent'",
            array('c' => (int) $creator_id, 'ch' => (string) $channel, 'p' => (string) $peer_key));
        $t = $rows[0]['t'] ?? null;
        return ($t !== null && $t !== '') ? (string) $t : null;
    }

    /** Provider message ids of our sends to this fan (to spot AI-vs-human creator messages in history). */
    public function sent_provider_ids_for_peer($creator_id, $channel, $peer_key, $limit = 50){
        $rows = parent::select(
            "SELECT provider_message_id FROM inbox_replies
             WHERE creator_id = :c AND channel = :ch AND peer_key = :p AND status = 'sent' AND provider_message_id IS NOT NULL
             ORDER BY sent_at DESC LIMIT " . (int) $limit,
            array('c' => (int) $creator_id, 'ch' => (string) $channel, 'p' => (string) $peer_key));
        $out = array();
        foreach ((array) $rows as $r) { $out[] = (string) $r['provider_message_id']; }
        return $out;
    }

    public function is_our_message($creator_id, $channel, $provider_message_id){
        $rows = parent::select(
            "SELECT id FROM inbox_replies WHERE creator_id = :c AND channel = :ch AND provider_message_id = :m LIMIT 1",
            array('c' => (int) $creator_id, 'ch' => (string) $channel, 'm' => (string) $provider_message_id));
        return is_array($rows) && count($rows) === 1;
    }

    /** Last time we told the creator the AI paused for this fan (rate-limits that notification). */
    public function last_pause_notice_for_peer($creator_id, $channel, $peer_key){
        $rows = parent::select(
            "SELECT MAX(created_at) AS t FROM inbox_replies
             WHERE creator_id = :c AND channel = :ch AND peer_key = :p AND status = 'skipped' AND reason = 'max_consecutive'",
            array('c' => (int) $creator_id, 'ch' => (string) $channel, 'p' => (string) $peer_key));
        $t = $rows[0]['t'] ?? null;
        return ($t !== null && $t !== '') ? (string) $t : null;
    }

}
