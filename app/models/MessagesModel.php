<?php
/**
 * Internal 1:1 messaging between a user and a creator (PRD §24). No PII: participants
 * only ever see platform identity (username, display name, avatar). A conversation is
 * (creator_id, user_id); either party may send once it exists. Creators may attach
 * library media (message_assets) and put a credit price on a message; a fan pays once
 * (message_unlocks) to see the originals.
 */
class MessagesModel extends Model {

    private $_creator_role_id = null;

    public function __construct(){ parent::__construct(); }

    /** Numeric role id for 'Creator' (resolved once per request). */
    private function creator_role_id(){
        if ($this->_creator_role_id === null) {
            $this->_creator_role_id = 0;
            $rr = parent::select("SELECT id FROM user_roles WHERE role_name = 'Creator'");
            if (is_array($rr) && count($rr)) { $this->_creator_role_id = (int) $rr[0]['id']; }
        }
        return $this->_creator_role_id;
    }

    /** Normalize account rows into the widget's picker shape (role-aware Creator flag). */
    private function shape_people($rows){
        $crole = $this->creator_role_id();
        $out = array();
        foreach ((array) $rows as $r) {
            $handle = (string) $r['u_name'];
            $name   = trim((string) ($r['name'] ?? ''));
            $out[] = array(
                'id'         => (int) $r['user_id'],
                'handle'     => $handle,
                'name'       => $name !== '' ? $name : ('@' . $handle),
                'avatar'     => (string) ($r['avatar'] ?? ''),
                'is_creator' => ($crole > 0 && (int) $r['role_id'] === $crole),
            );
        }
        return $out;
    }

    /**
     * People the viewer can start a conversation with: anyone they follow or subscribe to,
     * plus anyone who follows or subscribes to them (their audience). Optional name/handle filter.
     */
    public function connections($viewer_id, $q = '', $limit = 50){
        $vid = (int) $viewer_id;
        $limit = max(1, min(100, (int) $limit));
        $params = array('me1' => $vid, 'me2' => $vid, 'me3' => $vid, 'me4' => $vid, 'me5' => $vid);
        $where_q = '';
        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\%', '\_'), $q) . '%';
            $where_q = " AND (u.u_name LIKE :q1 OR COALESCE(cp.display_name, '') LIKE :q2 OR TRIM(CONCAT(u.first_name, ' ', u.last_name)) LIKE :q3)";
            $params['q1'] = $like; $params['q2'] = $like; $params['q3'] = $like;
        }
        $rows = parent::select(
            "SELECT u.user_id, u.u_name, u.role_id,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '')) AS name,
                    cp.avatar_url AS avatar
             FROM user_accounts u
             LEFT JOIN creator_profiles cp ON cp.user_id = u.user_id
             WHERE u.deleted = 0 AND u.user_id <> :me1
               AND u.user_id IN (
                   SELECT creator_id    FROM follows               WHERE follower_id   = :me2
                   UNION SELECT follower_id   FROM follows               WHERE creator_id    = :me3
                   UNION SELECT creator_id    FROM creator_subscriptions WHERE subscriber_id = :me4
                   UNION SELECT subscriber_id FROM creator_subscriptions WHERE creator_id    = :me5
               )$where_q
             ORDER BY name ASC
             LIMIT $limit",
            $params
        );
        return $this->shape_people($rows);
    }

    /**
     * Find or create the conversation between a creator and a user. Returns the id.
     * Lookup matches either ordering so a pair never ends up with two threads.
     */
    public function get_or_create($creator_id, $user_id){
        $a = (int) $creator_id; $b = (int) $user_id;
        $rows = parent::select(
            "SELECT id FROM conversations
             WHERE (creator_id = :c1 AND user_id = :u1) OR (creator_id = :u2 AND user_id = :c2)
             LIMIT 1",
            array('c1' => $a, 'u1' => $b, 'u2' => $b, 'c2' => $a));
        if (is_array($rows) && count($rows) === 1) { return (int) $rows[0]['id']; }
        return (int) parent::insert('conversations', array(
            'creator_id' => $a,
            'user_id'    => $b,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    /** Is this account a Creator? (role check) */
    public function is_creator($user_id){
        $rid = $this->creator_role_id();
        if ($rid <= 0) { return false; }
        $rows = parent::select(
            "SELECT user_id FROM user_accounts WHERE user_id = :u AND role_id = :r AND deleted = 0",
            array('u' => (int) $user_id, 'r' => $rid));
        return is_array($rows) && count($rows) === 1;
    }

    /**
     * The messaging gate: two accounts may start a conversation only if they have a
     * relationship in EITHER direction — a follow, a subscription, or a purchase
     * (PPV / bundle / DM unlock). This lets a creator message any audience member, including buyers.
     */
    public function can_message($viewer_id, $other_id){
        $v = (int) $viewer_id; $o = (int) $other_id;
        if ($v <= 0 || $o <= 0 || $v === $o) { return false; }
        $rows = parent::select(
            "SELECT 1 AS ok FROM follows
               WHERE (follower_id = :a1 AND creator_id = :b1) OR (follower_id = :b2 AND creator_id = :a2)
             UNION
             SELECT 1 AS ok FROM creator_subscriptions
               WHERE (subscriber_id = :a3 AND creator_id = :b3) OR (subscriber_id = :b4 AND creator_id = :a4)
             UNION
             SELECT 1 AS ok FROM ppv_unlocks
               WHERE (fan_id = :a5 AND creator_id = :b5) OR (fan_id = :b6 AND creator_id = :a6)
             UNION
             SELECT 1 AS ok FROM bundle_unlocks
               WHERE (fan_id = :a7 AND creator_id = :b7) OR (fan_id = :b8 AND creator_id = :a8)
             UNION
             SELECT 1 AS ok FROM message_unlocks
               WHERE (fan_id = :a9 AND creator_id = :b9) OR (fan_id = :b10 AND creator_id = :a10)
             LIMIT 1",
            array('a1' => $v, 'b1' => $o, 'b2' => $o, 'a2' => $v, 'a3' => $v, 'b3' => $o, 'b4' => $o, 'a4' => $v,
                  'a5' => $v, 'b5' => $o, 'b6' => $o, 'a6' => $v, 'a7' => $v, 'b7' => $o, 'b8' => $o, 'a8' => $v,
                  'a9' => $v, 'b9' => $o, 'b10' => $o, 'a10' => $v));
        return is_array($rows) && count($rows) > 0;
    }

    /**
     * Resolve (or create) the conversation between the viewer and another account,
     * honoring the (creator_id, user_id) column shape. Returns 0 if not permitted.
     */
    public function open_between($viewer_id, $other_id){
        if (!$this->can_message($viewer_id, $other_id)) { return 0; }
        $v = (int) $viewer_id; $o = (int) $other_id;
        if ($this->is_creator($o)) { return $this->get_or_create($o, $v); }
        if ($this->is_creator($v)) { return $this->get_or_create($v, $o); }
        return $this->get_or_create(min($v, $o), max($v, $o));   // both fans (edge case): deterministic ordering
    }

    public function get($conversation_id){
        $rows = parent::select("SELECT * FROM conversations WHERE id = :id", array('id' => (int) $conversation_id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function is_participant($conversation_id, $account_id){
        $c = $this->get($conversation_id);
        return $c && ((int) $c['creator_id'] === (int) $account_id || (int) $c['user_id'] === (int) $account_id);
    }

    /**
     * Insert a message (text and/or media, optionally priced), update the conversation
     * preview, and bump the recipient's unread. $asset_ids are trusted (the caller checks
     * ownership and readiness). Returns the message id.
     */
    public function send($conversation_id, $sender_id, $body, $broadcast_id = null, array $asset_ids = array(), $price_credits = 0, $trigger_key = null){
        $now = date('Y-m-d H:i:s');
        $asset_ids = array_values(array_unique(array_filter(array_map('intval', $asset_ids))));
        $price = (int) $price_credits;
        if (empty($asset_ids)) { $price = 0; }   // a price only makes sense with something to unlock
        $mid = parent::insert('messages', array(
            'conversation_id' => (int) $conversation_id,
            'sender_id'       => (int) $sender_id,
            'broadcast_id'    => ($broadcast_id !== null) ? (int) $broadcast_id : null,
            'body'            => (string) $body,
            'price_credits'   => $price,
            'media_count'     => count($asset_ids),
            'trigger_key'     => ($trigger_key !== null && $trigger_key !== '') ? mb_substr((string) $trigger_key, 0, 32) : null,
            'created_at'      => $now,
        ));
        foreach ($asset_ids as $i => $aid) {
            parent::insert('message_assets', array('message_id' => (int) $mid, 'asset_id' => (int) $aid, 'sort_order' => (int) $i));
        }
        $c = $this->get($conversation_id);
        if ($c) {
            $sender_is_creator = ((int) $c['creator_id'] === (int) $sender_id);
            $preview = self::preview_text((string) $body, count($asset_ids), $price);
            $data = array('last_message_at' => $now, 'last_body' => $preview, 'last_sender_id' => (int) $sender_id);
            if ($sender_is_creator) { $data['user_unread'] = (int) $c['user_unread'] + 1; $data['user_deleted'] = 0; }
            else { $data['creator_unread'] = (int) $c['creator_unread'] + 1; $data['creator_deleted'] = 0; }
            parent::update('conversations', $data, 'id = :id', array('id' => (int) $conversation_id));
        }
        return (int) $mid;
    }

    /** Inbox preview line: the text, or what was attached when there is none. */
    public static function preview_text($body, $media_count, $price_credits){
        $body = trim((string) $body);
        if ($body !== '') { return mb_substr($body, 0, 280); }
        $n = (int) $media_count;
        if ($n <= 0) { return ''; }
        if ((int) $price_credits > 0) { return 'Locked ' . ($n === 1 ? 'media' : $n . ' items') . ' · ' . (int) $price_credits . ' credits'; }
        return $n === 1 ? 'Sent media' : 'Sent ' . $n . ' items';
    }

    /** Number of messages a given sender has in a conversation (first-message trigger). */
    public function count_from($conversation_id, $sender_id){
        $rows = parent::select("SELECT COUNT(*) AS n FROM messages WHERE conversation_id = :c AND sender_id = :s",
            array('c' => (int) $conversation_id, 's' => (int) $sender_id));
        return (is_array($rows) && count($rows)) ? (int) $rows[0]['n'] : 0;
    }

    public function get_message($message_id){
        $rows = parent::select("SELECT * FROM messages WHERE id = :id", array('id' => (int) $message_id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /**
     * Media rows for a set of messages: message_id => [asset rows in sort order].
     * No creator filter on purpose (the fan side reads it); callers gate on the unlock.
     */
    public function assets_for_messages(array $message_ids){
        $ids = array_values(array_unique(array_filter(array_map('intval', $message_ids))));
        if (empty($ids)) { return array(); }
        $in = implode(',', $ids);
        $rows = parent::select(
            "SELECT ma.message_id, ma.sort_order, a.*
             FROM message_assets ma JOIN media_assets a ON a.id = ma.asset_id
             WHERE ma.message_id IN ($in) AND a.deleted_at IS NULL AND a.status = 'ready'
             ORDER BY ma.message_id ASC, ma.sort_order ASC, a.id ASC");
        $out = array();
        foreach ((array) $rows as $r) { $out[(int) $r['message_id']][] = $r; }
        return $out;
    }

    /** How many fans unlocked each priced message (creator's own bubbles): message_id => n. */
    public function unlock_counts(array $message_ids){
        $ids = array_values(array_unique(array_filter(array_map('intval', $message_ids))));
        if (empty($ids)) { return array(); }
        $in = implode(',', $ids);
        $rows = parent::select("SELECT message_id, COUNT(*) AS n FROM message_unlocks WHERE message_id IN ($in) GROUP BY message_id");
        $out = array();
        foreach ((array) $rows as $r) { $out[(int) $r['message_id']] = (int) $r['n']; }
        return $out;
    }

    /** Raw conversations for an account's inbox (either side), newest activity first. */
    public function inbox_rows($account_id){
        $aid = (int) $account_id;
        return (array) parent::select(
            "SELECT * FROM conversations
             WHERE ((creator_id = :a1 AND creator_deleted = 0) OR (user_id = :a2 AND user_deleted = 0))
               AND last_message_at IS NOT NULL
             ORDER BY last_message_at DESC",
            array('a1' => $aid, 'a2' => $aid)
        );
    }

    /** Messages in a conversation, oldest first. */
    public function thread($conversation_id){
        return (array) parent::select(
            "SELECT id, sender_id, body, price_credits, media_count, trigger_key, created_at
             FROM messages WHERE conversation_id = :c ORDER BY id ASC",
            array('c' => (int) $conversation_id)
        );
    }

    /** Clear the viewer's unread count for a conversation. */
    public function mark_read($conversation_id, $account_id){
        $c = $this->get($conversation_id);
        if (!$c) { return false; }
        $col = ((int) $c['creator_id'] === (int) $account_id) ? 'creator_unread' : 'user_unread';
        return parent::update('conversations', array($col => 0), 'id = :id', array('id' => (int) $conversation_id));
    }

    /** Total unread across an account's conversations — for the launcher badge. */
    public function total_unread($account_id){
        $aid = (int) $account_id;
        $rows = parent::select(
            "SELECT COALESCE(SUM(IF(creator_id = :a1, creator_unread, 0)) + SUM(IF(user_id = :a2, user_unread, 0)), 0) AS n
             FROM conversations
             WHERE (creator_id = :a3 AND creator_deleted = 0) OR (user_id = :a4 AND user_deleted = 0)",
            array('a1' => $aid, 'a2' => $aid, 'a3' => $aid, 'a4' => $aid)
        );
        return is_array($rows) && count($rows) ? (int) $rows[0]['n'] : 0;
    }

    /** Creator-side facts about a fan: relationship, spend, tenure. No PII. */
    public function peer_summary($creator_id, $fan_id){
        $c = (int) $creator_id; $f = (int) $fan_id;
        $follow = parent::select("SELECT created_at FROM follows WHERE follower_id = :f AND creator_id = :c", array('f' => $f, 'c' => $c));
        $sub = parent::select(
            "SELECT cs.status, cs.is_free, cs.created_at, p.name AS plan_name
             FROM creator_subscriptions cs LEFT JOIN creator_plans p ON p.id = cs.plan_id
             WHERE cs.subscriber_id = :f AND cs.creator_id = :c
             ORDER BY (cs.status = 'active') DESC, cs.created_at DESC LIMIT 1", array('f' => $f, 'c' => $c));
        $spend = parent::select(
            "SELECT COUNT(*) AS n, COALESCE(SUM(price_credits), 0) AS credits FROM (
                SELECT price_credits FROM ppv_unlocks WHERE fan_id = :f1 AND creator_id = :c1
                UNION ALL SELECT price_credits FROM bundle_unlocks WHERE fan_id = :f2 AND creator_id = :c2
                UNION ALL SELECT price_credits FROM message_unlocks WHERE fan_id = :f3 AND creator_id = :c3
             ) s", array('f1' => $f, 'c1' => $c, 'f2' => $f, 'c2' => $c, 'f3' => $f, 'c3' => $c));
        $acct = parent::select("SELECT created_at, last_active_at FROM user_accounts WHERE user_id = :f", array('f' => $f));
        $s = (is_array($sub) && count($sub)) ? $sub[0] : null;
        return array(
            'follows'       => is_array($follow) && count($follow) > 0,
            'followed_at'   => (is_array($follow) && count($follow)) ? (string) $follow[0]['created_at'] : '',
            'subscription'  => $s ? array('status' => (string) $s['status'], 'plan' => (string) ($s['plan_name'] ?? ''), 'free' => !empty($s['is_free']), 'since' => (string) $s['created_at']) : null,
            'purchases'     => (is_array($spend) && count($spend)) ? (int) $spend[0]['n'] : 0,
            'spent_credits' => (is_array($spend) && count($spend)) ? (int) $spend[0]['credits'] : 0,
            'member_since'  => (is_array($acct) && count($acct)) ? (string) $acct[0]['created_at'] : '',
            'last_active'   => (is_array($acct) && count($acct)) ? (string) ($acct[0]['last_active_at'] ?? '') : '',
        );
    }

    /** Platform-identity map (NO PII): id => {handle, name, avatar, is_creator}. */
    public function identity_map(array $ids){
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) { return array(); }
        $in = implode(',', $ids);
        $creator_role = $this->creator_role_id();
        $rows = parent::select(
            "SELECT u.user_id, u.u_name, u.role_id,
                    COALESCE(NULLIF(TRIM(cp.display_name), ''), NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '')) AS name,
                    cp.avatar_url AS avatar
             FROM user_accounts u LEFT JOIN creator_profiles cp ON cp.user_id = u.user_id
             WHERE u.user_id IN ($in) AND u.deleted = 0"
        );
        $map = array();
        foreach ((array) $rows as $r) {
            $handle = (string) $r['u_name'];
            $name   = trim((string) ($r['name'] ?? ''));
            $map[(int) $r['user_id']] = array(
                'handle'     => $handle,
                'name'       => $name !== '' ? $name : ('@' . $handle),
                'avatar'     => (string) ($r['avatar'] ?? ''),
                'is_creator' => ($creator_role > 0 && (int) $r['role_id'] === $creator_role),
            );
        }
        return $map;
    }
}
