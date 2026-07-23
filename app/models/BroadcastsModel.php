<?php
/**
 * Broadcasts (PRD §25): a creator composes one message and it fans out as an
 * individual 1:1 message to every account in the chosen audience segment, so each
 * fan can reply privately in their own thread. Reuses conversations/messages via
 * MessagesModel. A `broadcasts` row logs each send for history/analytics.
 */
class BroadcastsModel extends Model {

    /** Valid audience segments. */
    public static function segments(){ return array('all', 'followers', 'subscribers'); }

    public function __construct(){ parent::__construct(); }

    /** Recipient counts per segment for a creator: {followers, subscribers, all}. */
    public function counts($creator_id){
        $cid = (int) $creator_id;
        $followers   = count($this->recipient_ids($cid, 'followers'));
        $subscribers = count($this->recipient_ids($cid, 'subscribers'));
        $all         = count($this->recipient_ids($cid, 'all'));
        return array('followers' => $followers, 'subscribers' => $subscribers, 'all' => $all);
    }

    /** Distinct recipient user ids for a creator + segment (never includes the creator). */
    public function recipient_ids($creator_id, $segment){
        $cid = (int) $creator_id;
        $segment = in_array($segment, self::segments(), true) ? $segment : 'all';
        if ($segment === 'followers') {
            $sql = "SELECT DISTINCT follower_id AS uid FROM follows WHERE creator_id = :c AND follower_id <> :me";
        } elseif ($segment === 'subscribers') {
            $sql = "SELECT DISTINCT subscriber_id AS uid FROM creator_subscriptions WHERE creator_id = :c AND status = 'active' AND subscriber_id <> :me";
        } else { // all = followers ∪ active subscribers
            $sql = "SELECT follower_id AS uid FROM follows WHERE creator_id = :c AND follower_id <> :me
                    UNION
                    SELECT subscriber_id AS uid FROM creator_subscriptions WHERE creator_id = :c2 AND status = 'active' AND subscriber_id <> :me2";
        }
        $params = ($segment === 'all')
            ? array('c' => $cid, 'me' => $cid, 'c2' => $cid, 'me2' => $cid)
            : array('c' => $cid, 'me' => $cid);
        $rows = parent::select($sql, $params);
        $ids = array();
        foreach ((array) $rows as $r) { $ids[] = (int) $r['uid']; }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Log the broadcast and deliver it to each recipient as an individual message.
     * Returns array(broadcast_id, recipient_count). recipient_count = 0 if nobody in segment.
     */
    public function create_and_send($creator_id, $segment, $body){
        $cid  = (int) $creator_id;
        $segment = in_array($segment, self::segments(), true) ? $segment : 'all';
        $ids  = $this->recipient_ids($cid, $segment);
        if (empty($ids)) { return array(0, 0); }

        $bid = (int) parent::insert('broadcasts', array(
            'creator_id'      => $cid,
            'audience'        => $segment,
            'body'            => $body,
            'recipient_count' => count($ids),
            'created_at'      => date('Y-m-d H:i:s'),
        ));

        $messages = new MessagesModel();
        foreach ($ids as $uid) {
            $conv_id = $messages->get_or_create($cid, $uid);   // creator is always creator_id side
            $messages->send($conv_id, $cid, $body, $bid);
        }
        return array($bid, count($ids));
    }

    /** A creator's past broadcasts, newest first. */
    public function recent_for_creator($creator_id, $limit = 20){
        $limit = max(1, min(100, (int) $limit));
        return (array) parent::select(
            "SELECT id, audience, body, recipient_count, created_at
             FROM broadcasts WHERE creator_id = :c ORDER BY id DESC LIMIT $limit",
            array('c' => (int) $creator_id)
        );
    }
}
