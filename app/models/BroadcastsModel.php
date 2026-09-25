<?php
/**
 * Broadcasts (PRD §25): a creator composes one message and it fans out as an
 * individual 1:1 message to every account in the chosen audience segments, so each
 * fan can reply privately in their own thread. Reuses conversations/messages via
 * MessagesModel. A `broadcasts` row logs each send; media and a credit price ride
 * along exactly as on a direct message. Big audiences are delivered by a queue job.
 */
class BroadcastsModel extends Model {

    const INLINE_MAX = 50;   // recipients delivered in-request; above this the queue takes it

    /** Valid audience segments, in display order. */
    public static function segments(){ return array('all', 'followers', 'subscribers', 'expired', 'buyers'); }

    public static function segment_labels(){
        return array('all' => 'Everyone', 'followers' => 'Followers', 'subscribers' => 'Subscribers',
                     'expired' => 'Expired subscribers', 'buyers' => 'Buyers');
    }

    /** Keep only known segments, in canonical order. */
    public static function clean_segments($segments){
        if (is_string($segments)) { $segments = json_decode($segments, true) ?: explode(',', $segments); }
        $segments = array_map('strval', (array) $segments);
        $out = array();
        foreach (self::segments() as $s) { if (in_array($s, $segments, true)) { $out[] = $s; } }
        return $out;
    }

    public function __construct(){ parent::__construct(); }

    /** Recipient counts per segment for a creator. */
    public function counts($creator_id){
        $out = array();
        foreach (self::segments() as $s) { $out[$s] = count($this->segment_ids((int) $creator_id, $s)); }
        return $out;
    }

    /** Distinct recipient user ids for a creator across one or more segments (never the creator). */
    public function recipient_ids($creator_id, $segments){
        $segments = self::clean_segments(is_array($segments) ? $segments : array($segments));
        if (empty($segments)) { return array(); }
        $ids = array();
        foreach ($segments as $s) { $ids = array_merge($ids, $this->segment_ids((int) $creator_id, $s)); }
        $ids = array_values(array_unique(array_filter($ids)));
        // Never reach anyone in a block relationship with the creator (buyers/expired members keep
        // their rows after a block, so the segments alone don't exclude them).
        $blocked = (new BlocksModel())->related_ids((int) $creator_id);
        if ($blocked) { $ids = array_values(array_filter($ids, function ($id) use ($blocked) { return !isset($blocked[(int) $id]); })); }
        return $ids;
    }

    private function segment_ids($cid, $segment){
        $p = array('c' => $cid, 'me' => $cid);
        switch ($segment) {
            case 'followers':
                $sql = "SELECT DISTINCT follower_id AS uid FROM follows WHERE creator_id = :c AND follower_id <> :me";
                break;
            case 'subscribers':
                $sql = "SELECT DISTINCT subscriber_id AS uid FROM creator_subscriptions WHERE creator_id = :c AND status = 'active' AND subscriber_id <> :me";
                break;
            case 'expired':
                $sql = "SELECT DISTINCT cs.subscriber_id AS uid FROM creator_subscriptions cs
                        WHERE cs.creator_id = :c AND cs.status <> 'active' AND cs.subscriber_id <> :me
                          AND NOT EXISTS (SELECT 1 FROM creator_subscriptions a WHERE a.creator_id = cs.creator_id AND a.subscriber_id = cs.subscriber_id AND a.status = 'active')";
                break;
            case 'buyers':
                $sql = "SELECT fan_id AS uid FROM ppv_unlocks WHERE creator_id = :c AND fan_id <> :me
                        UNION SELECT fan_id FROM bundle_unlocks WHERE creator_id = :c2 AND fan_id <> :me2
                        UNION SELECT fan_id FROM message_unlocks WHERE creator_id = :c3 AND fan_id <> :me3";
                $p = array('c' => $cid, 'me' => $cid, 'c2' => $cid, 'me2' => $cid, 'c3' => $cid, 'me3' => $cid);
                break;
            default: // all = followers ∪ active subscribers
                $sql = "SELECT follower_id AS uid FROM follows WHERE creator_id = :c AND follower_id <> :me
                        UNION
                        SELECT subscriber_id AS uid FROM creator_subscriptions WHERE creator_id = :c2 AND status = 'active' AND subscriber_id <> :me2";
                $p = array('c' => $cid, 'me' => $cid, 'c2' => $cid, 'me2' => $cid);
        }
        $ids = array();
        foreach ((array) parent::select($sql, $p) as $r) { $ids[] = (int) $r['uid']; }
        return $ids;
    }

    /**
     * Log the broadcast and deliver it: in-request for small audiences, through the
     * job queue for big ones. Returns array(broadcast_id, recipient_count, queued).
     */
    public function create_and_send($creator_id, $segments, $body, array $asset_ids = array(), $price_credits = 0){
        $cid = (int) $creator_id;
        $segments = self::clean_segments(is_array($segments) ? $segments : array($segments));
        if (empty($segments)) { $segments = array('all'); }
        $ids = $this->recipient_ids($cid, $segments);
        if (empty($ids)) { return array(0, 0, false); }
        $asset_ids = array_values(array_unique(array_filter(array_map('intval', $asset_ids))));
        $price = empty($asset_ids) ? 0 : max(0, (int) $price_credits);

        $bid = (int) parent::insert('broadcasts', array(
            'creator_id'      => $cid,
            'audience'        => $segments[0],
            'segments'        => implode(',', $segments),
            'body'            => (string) $body,
            'price_credits'   => $price,
            'asset_ids'       => empty($asset_ids) ? null : json_encode($asset_ids),
            'recipient_count' => count($ids),
            'created_at'      => date('Y-m-d H:i:s'),
        ));

        if (count($ids) > self::INLINE_MAX && class_exists('DatabaseJobQueue')) {
            try {
                (new DatabaseJobQueue())->dispatch('broadcast_send', array('broadcast_id' => $bid));
                return array($bid, count($ids), true);
            } catch (\Throwable $e) {
                error_log('[broadcast] queue push failed, delivering inline: ' . $e->getMessage());
            }
        }
        $this->deliver($bid, $ids, $cid, (string) $body, $asset_ids, $price);
        return array($bid, count($ids), false);
    }

    /** Deliver a logged broadcast to recipients who have not received it yet (safe to re-run). */
    public function deliver_pending($broadcast_id){
        $b = $this->get((int) $broadcast_id);
        if (!$b) { return 0; }
        $ids  = $this->recipient_ids((int) $b['creator_id'], $b['segments'] !== null && $b['segments'] !== '' ? explode(',', $b['segments']) : array($b['audience']));
        $done = parent::select("SELECT DISTINCT c.user_id FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE m.broadcast_id = :b",
            array('b' => (int) $b['id']));
        $seen = array();
        foreach ((array) $done as $r) { $seen[(int) $r['user_id']] = true; }
        $todo = array();
        foreach ($ids as $uid) { if (empty($seen[$uid])) { $todo[] = $uid; } }
        $assets = json_decode((string) ($b['asset_ids'] ?? ''), true);
        $this->deliver((int) $b['id'], $todo, (int) $b['creator_id'], (string) $b['body'], is_array($assets) ? $assets : array(), (int) $b['price_credits']);
        return count($todo);
    }

    private function deliver($bid, array $ids, $cid, $body, array $asset_ids, $price){
        $messages = new MessagesModel();
        $name  = Notify::name_of($cid) ?: 'A creator you follow';
        $prev  = MessagesModel::preview_text($body, count($asset_ids), $price);
        foreach ($ids as $uid) {
            $conv_id = $messages->get_or_create($cid, $uid);   // creator is always creator_id side
            $messages->send($conv_id, $cid, $body, $bid, $asset_ids, $price);
            Notify::send($uid, 'broadcasts', $name . ' sent a message to fans', mb_substr($prev, 0, 140), '/inbox/thread/' . (int) $conv_id, 'fa-bullhorn', true);
        }
    }

    public function get($broadcast_id){
        $rows = parent::select("SELECT * FROM broadcasts WHERE id = :id", array('id' => (int) $broadcast_id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** A creator's past broadcasts, newest first. */
    public function recent_for_creator($creator_id, $limit = 20){
        $limit = max(1, min(100, (int) $limit));
        return (array) parent::select(
            "SELECT id, audience, segments, body, price_credits, recipient_count, created_at
             FROM broadcasts WHERE creator_id = :c ORDER BY id DESC LIMIT $limit",
            array('c' => (int) $creator_id)
        );
    }
}
