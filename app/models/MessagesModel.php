<?php
/**
 * Internal 1:1 messaging between a user and a creator (PRD §24). Text-only, no PII:
 * participants only ever see platform identity (username, display name, avatar).
 * A conversation is (creator_id, user_id); either party may send once it exists.
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
     * follow or subscription relationship in EITHER direction.
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
             LIMIT 1",
            array('a1' => $v, 'b1' => $o, 'b2' => $o, 'a2' => $v, 'a3' => $v, 'b3' => $o, 'b4' => $o, 'a4' => $v));
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

    /** Insert a message, update the conversation preview, and bump the recipient's unread. */
    public function send($conversation_id, $sender_id, $body, $broadcast_id = null){
        $now = date('Y-m-d H:i:s');
        $mid = parent::insert('messages', array(
            'conversation_id' => (int) $conversation_id,
            'sender_id'       => (int) $sender_id,
            'broadcast_id'    => ($broadcast_id !== null) ? (int) $broadcast_id : null,
            'body'            => $body,
            'created_at'      => $now,
        ));
        $c = $this->get($conversation_id);
        if ($c) {
            $sender_is_creator = ((int) $c['creator_id'] === (int) $sender_id);
            $data = array('last_message_at' => $now, 'last_body' => mb_substr($body, 0, 280), 'last_sender_id' => (int) $sender_id);
            if ($sender_is_creator) { $data['user_unread'] = (int) $c['user_unread'] + 1; $data['user_deleted'] = 0; }
            else { $data['creator_unread'] = (int) $c['creator_unread'] + 1; $data['creator_deleted'] = 0; }
            parent::update('conversations', $data, 'id = :id', array('id' => (int) $conversation_id));
        }
        return (int) $mid;
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
            "SELECT id, sender_id, body, created_at FROM messages WHERE conversation_id = :c ORDER BY id ASC",
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
