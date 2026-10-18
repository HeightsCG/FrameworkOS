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
    public function connections($viewer_id, $q = '', $limit = 50, $no_creators = false){
        $vid = (int) $viewer_id;
        $limit = max(1, min(100, (int) $limit));
        $params = array('me1' => $vid, 'me2' => $vid, 'me3' => $vid, 'me4' => $vid, 'me5' => $vid, 'bv1' => $vid, 'bv2' => $vid);
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
               )
               AND " . BlocksModel::exclude_sql('u.user_id', 'bv1', 'bv2') . "$where_q" . ($no_creators && $this->creator_role_id() > 0 ? ' AND u.role_id <> ' . (int) $this->creator_role_id() : '') . "
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
        if ((new BlocksModel())->either_blocked($v, $o)) { return false; }
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
             UNION
             SELECT 1 AS ok FROM service_purchases sp JOIN services s ON s.id = sp.service_id
               WHERE (sp.buyer_id = :a11 AND s.creator_id = :b11) OR (sp.buyer_id = :b12 AND s.creator_id = :a12)
             UNION
             SELECT 1 AS ok FROM event_registrations er JOIN events e ON e.id = er.event_id
               WHERE (er.user_id = :a13 AND e.creator_id = :b13) OR (er.user_id = :b14 AND e.creator_id = :a14)
             LIMIT 1",
            array('a1' => $v, 'b1' => $o, 'b2' => $o, 'a2' => $v, 'a3' => $v, 'b3' => $o, 'b4' => $o, 'a4' => $v,
                  'a5' => $v, 'b5' => $o, 'b6' => $o, 'a6' => $v, 'a7' => $v, 'b7' => $o, 'b8' => $o, 'a8' => $v,
                  'a9' => $v, 'b9' => $o, 'b10' => $o, 'a10' => $v, 'a11' => $v, 'b11' => $o, 'b12' => $o, 'a12' => $v,
                  'a13' => $v, 'b13' => $o, 'b14' => $o, 'a14' => $v));
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
        Analytics::event('message_sent', array('user_id' => (int) $sender_id, 'creator_id' => (int) $sender_id, 'value_cents' => (int) round((int) $price_credits * 100 / Price::CREDITS_PER_DOLLAR), 'meta' => ($broadcast_id ? 'broadcast' : ($trigger_key ? 'auto ' . $trigger_key : 'direct')) . ((int) $price_credits > 0 ? ' paid' : '')));
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
            $data[$sender_is_creator ? 'user_deleted' : 'creator_deleted'] = 0;
            parent::update('conversations', $data, 'id = :id', array('id' => (int) $conversation_id));
            // In the database, not read-then-write: two messages sent at once both count.
            $col = $sender_is_creator ? 'user_unread' : 'creator_unread';
            parent::sql("UPDATE conversations SET $col = $col + 1 WHERE id = :id", array(':id' => (int) $conversation_id));
        }
        return (int) $mid;
    }

    /** Inbox preview line: the text, or what was attached when there is none. */
    public static function preview_text($body, $media_count, $price_credits){
        $body = trim((string) $body);
        if ($body !== '') { return mb_substr($body, 0, 280); }
        $n = (int) $media_count;
        if ($n <= 0) { return ''; }
        if ((int) $price_credits > 0) { return 'Locked ' . ($n === 1 ? 'media' : $n . ' items') . ' · ' . Price::credits((int) $price_credits); }
        return $n === 1 ? 'Sent media' : 'Sent ' . $n . ' items';
    }

    /** Number of messages a given sender has in a conversation (first-message trigger). */
    public function count_from($conversation_id, $sender_id){
        $rows = parent::select("SELECT COUNT(*) AS n FROM messages WHERE conversation_id = :c AND sender_id = :s",
            array('c' => (int) $conversation_id, 's' => (int) $sender_id));
        return (is_array($rows) && count($rows)) ? (int) $rows[0]['n'] : 0;
    }

    public function get_message($message_id){
        $rows = parent::select("SELECT * FROM messages WHERE id = :id AND deleted_at IS NULL", array('id' => (int) $message_id));
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
             WHERE ma.message_id IN ($in) AND a.deleted_at IS NULL AND a.status = 'ready' AND a.moderation_status <> 'blocked'
             ORDER BY ma.message_id ASC, ma.sort_order ASC, a.id ASC");
        $out = array();
        foreach ((array) $rows as $r) { $out[(int) $r['message_id']][] = $r; }
        return $out;
    }

    /** unlock_counts limited to priced messages of one conversation (the thread poll's watch list): message_id => n, 0 included. */
    public function unlock_counts_in($conversation_id, array $message_ids){
        $ids = array_values(array_unique(array_filter(array_map('intval', $message_ids))));
        if (empty($ids)) { return array(); }
        $in = implode(',', $ids);
        $rows = parent::select("SELECT m.id, (SELECT COUNT(*) FROM message_unlocks u WHERE u.message_id = m.id) AS n
             FROM messages m WHERE m.conversation_id = :c AND m.id IN ($in) AND m.price_credits > 0", array('c' => (int) $conversation_id));
        $out = array();
        foreach ((array) $rows as $r) { $out[(int) $r['id']] = (int) $r['n']; }
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

    /** Conversations of either side with nobody blocked between the two (inbox_rows, total_unread). */
    const NOT_BLOCKED = "NOT EXISTS (SELECT 1 FROM user_blocks ub
                    WHERE (ub.user_id = :bv1 AND ub.blocked_user_id IN (c.creator_id, c.user_id))
                       OR (ub.blocked_user_id = :bv2 AND ub.user_id IN (c.creator_id, c.user_id)))";

    /**
     * Raw conversations for an account's inbox (either side), newest activity first. $limit 0 = all.
     * Paging: $before = [last_message_at, id] of the last row shown (older ones). Polling: $since = activity at or after this (UTC).
     */
    public function inbox_rows($account_id, $limit = 0, array $before = array(), $since = ''){
        $aid = (int) $account_id;
        $p = array('a1' => $aid, 'a2' => $aid, 'bv1' => $aid, 'bv2' => $aid);
        $extra = '';
        if ((string) $since !== '') { $extra .= ' AND c.last_message_at >= :since'; $p['since'] = (string) $since; }
        if (!empty($before[0])) {
            $extra .= ' AND (c.last_message_at < :ba1 OR (c.last_message_at = :ba2 AND c.id < :bid))';
            $p['ba1'] = (string) $before[0]; $p['ba2'] = (string) $before[0]; $p['bid'] = (int) ($before[1] ?? 0);
        }
        return (array) parent::select(
            "SELECT c.* FROM conversations c
             WHERE ((c.creator_id = :a1 AND c.creator_deleted = 0) OR (c.user_id = :a2 AND c.user_deleted = 0))
               AND c.last_message_at IS NOT NULL
               AND " . self::NOT_BLOCKED . $extra . "
             ORDER BY c.last_message_at DESC, c.id DESC" . ((int) $limit > 0 ? ' LIMIT ' . (int) $limit : ''),
            $p
        );
    }

    /**
     * Search the whole inbox (not just the loaded page): the other person's name or handle, or the last message, and/or
     * only conversations with unread messages. Newest first, at most $limit. The team side matches "Creator Link Studio".
     */
    public function inbox_find($account_id, $q = '', $unread_only = false, $limit = 100){
        $aid = (int) $account_id;
        $p = array('a1' => $aid, 'a2' => $aid, 'a3' => $aid, 'bv1' => $aid, 'bv2' => $aid);
        $extra = '';
        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
            $extra .= " AND (o.u_name LIKE :q1 OR CONCAT(o.first_name, ' ', o.last_name) LIKE :q2 OR op.display_name LIKE :q3 OR c.last_body LIKE :q4"
                    . " OR (IF(c.creator_id = :a4, c.user_id, c.creator_id) = 0 AND :q5 LIKE :q6))";
            $p += array('q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'a4' => $aid, 'q5' => PlatformAccount::NAME, 'q6' => $like);
        }
        if ($unread_only) {
            $extra .= ' AND ((c.creator_id = :a5 AND c.creator_unread > 0) OR (c.user_id = :a6 AND c.user_unread > 0))';
            $p += array('a5' => $aid, 'a6' => $aid);
        }
        return (array) parent::select(
            "SELECT c.* FROM conversations c
             LEFT JOIN user_accounts o ON o.user_id = IF(c.creator_id = :a3, c.user_id, c.creator_id)
             LEFT JOIN creator_profiles op ON op.user_id = o.user_id
             WHERE ((c.creator_id = :a1 AND c.creator_deleted = 0) OR (c.user_id = :a2 AND c.user_deleted = 0))
               AND c.last_message_at IS NOT NULL
               AND " . self::NOT_BLOCKED . $extra . "
             ORDER BY c.last_message_at DESC, c.id DESC LIMIT " . max(1, (int) $limit),
            $p
        );
    }

    /** How many conversations have unread messages for this account (the Unread tab's number). */
    public function unread_conversations($account_id){
        $aid = (int) $account_id;
        $r = parent::select(
            "SELECT COUNT(*) AS n FROM conversations c
             WHERE ((c.creator_id = :a1 AND c.creator_deleted = 0 AND c.creator_unread > 0) OR (c.user_id = :a2 AND c.user_deleted = 0 AND c.user_unread > 0))
               AND " . self::NOT_BLOCKED, array('a1' => $aid, 'a2' => $aid, 'bv1' => $aid, 'bv2' => $aid));
        return (int) ($r[0]['n'] ?? 0);
    }

    /**
     * Messages in a conversation, oldest first; deleted ones never, and only newer than $after_id (what the viewer
     * cleared, or the last one the page has). $limit > 0: only the newest $limit (older than $before_id when given).
     */
    public function thread($conversation_id, $after_id = 0, $limit = 0, $before_id = 0){
        $p = array('c' => (int) $conversation_id, 'a' => (int) $after_id);
        $extra = '';
        if ((int) $before_id > 0) { $extra = ' AND id < :b'; $p['b'] = (int) $before_id; }
        if ((int) $limit <= 0) {
            return (array) parent::select(
                "SELECT id, sender_id, body, price_credits, media_count, trigger_key, created_at
                 FROM messages WHERE conversation_id = :c AND id > :a AND deleted_at IS NULL" . $extra . " ORDER BY id ASC", $p);
        }
        $rows = (array) parent::select(
            "SELECT id, sender_id, body, price_credits, media_count, trigger_key, created_at
             FROM messages WHERE conversation_id = :c AND id > :a AND deleted_at IS NULL" . $extra . " ORDER BY id DESC LIMIT " . (int) $limit, $p);
        return array_reverse($rows);
    }

    /** The highest message id this account deleted in the conversation (0 = nothing cleared). */
    public static function cleared_id(array $c, $account_id){
        return (int) (((int) $c['creator_id'] === (int) $account_id) ? ($c['creator_cleared_id'] ?? 0) : ($c['user_cleared_id'] ?? 0));
    }

    /** Delete a conversation for one side only: it leaves their inbox and its history is cleared for them. A new message brings it back. */
    public function delete_for($conversation_id, $account_id){
        $c = $this->get($conversation_id);
        if (!$c) { return false; }
        $max = parent::select("SELECT COALESCE(MAX(id), 0) AS m FROM messages WHERE conversation_id = :c", array('c' => (int) $conversation_id));
        $side = ((int) $c['creator_id'] === (int) $account_id) ? 'creator' : 'user';
        return parent::update('conversations', array($side . '_deleted' => 1, $side . '_cleared_id' => (int) ($max[0]['m'] ?? 0), $side . '_unread' => 0),
            'id = :id', array('id' => (int) $conversation_id));
    }

    /** Delete a sent message for both sides, then point the inbox preview at the newest message left. */
    public function delete_message($message_id){
        $m = $this->get_message($message_id);
        if (!$m) { return false; }
        $conv_id = (int) $m['conversation_id'];
        parent::update('messages', array('deleted_at' => date('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $message_id));
        $c = $this->get($conv_id);
        if (!$c) { return true; }
        $last = parent::select("SELECT id, sender_id, body, media_count, price_credits, created_at FROM messages
            WHERE conversation_id = :c AND deleted_at IS NULL ORDER BY id DESC LIMIT 1", array('c' => $conv_id));
        $last = (is_array($last) && count($last)) ? $last[0] : null;
        $data = $last
            ? array('last_message_at' => $last['created_at'], 'last_body' => self::preview_text((string) $last['body'], (int) $last['media_count'], (int) $last['price_credits']), 'last_sender_id' => (int) $last['sender_id'])
            : array('last_body' => '', 'last_sender_id' => null);
        // It was the newest message and the other side hadn't read it yet: take it off their unread count.
        parent::update('conversations', $data, 'id = :id', array('id' => $conv_id));
        if (!$last || (int) $last['id'] < (int) $message_id) {
            $col = ((int) $c['creator_id'] === (int) $m['sender_id']) ? 'user_unread' : 'creator_unread';
            parent::sql("UPDATE conversations SET $col = $col - 1 WHERE id = :id AND $col > 0", array(':id' => $conv_id));
        }
        return true;
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
            "SELECT COALESCE(SUM(IF(c.creator_id = :a1, c.creator_unread, 0)) + SUM(IF(c.user_id = :a2, c.user_unread, 0)), 0) AS n
             FROM conversations c
             WHERE ((c.creator_id = :a3 AND c.creator_deleted = 0) OR (c.user_id = :a4 AND c.user_deleted = 0))
               AND " . self::NOT_BLOCKED,   // a blocked conversation is hidden from the inbox, so it can't hold the badge up
            array('a1' => $aid, 'a2' => $aid, 'a3' => $aid, 'a4' => $aid, 'bv1' => $aid, 'bv2' => $aid)
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
        $spend = (new FanSpendModel())->for_fan($c, $f);   // the one definition of what a fan has spent
        $acct = parent::select("SELECT created_at, last_active_at FROM user_accounts WHERE user_id = :f", array('f' => $f));
        $s = (is_array($sub) && count($sub)) ? $sub[0] : null;
        return array(
            'follows'       => is_array($follow) && count($follow) > 0,
            'followed_at'   => (is_array($follow) && count($follow)) ? (string) $follow[0]['created_at'] : '',
            'subscription'  => $s ? array('status' => (string) $s['status'], 'plan' => (string) ($s['plan_name'] ?? ''), 'free' => !empty($s['is_free']), 'since' => (string) $s['created_at']) : null,
            'purchases'     => $spend['purchases'],
            'spent_credits' => $spend['credits'],
            'member_since'  => (is_array($acct) && count($acct)) ? (string) $acct[0]['created_at'] : '',
            'last_active'   => (is_array($acct) && count($acct)) ? (string) ($acct[0]['last_active_at'] ?? '') : '',
        );
    }

    /** Platform-identity map (NO PII): id => {handle, name, avatar, is_creator}. */
    public function identity_map(array $ids){
        $team = in_array(PlatformAccount::ID, array_map('intval', $ids), true);   // the Creator Link Studio side of a team conversation
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) { return $team ? array(PlatformAccount::ID => PlatformAccount::identity()) : array(); }
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
        if ($team) { $map[PlatformAccount::ID] = PlatformAccount::identity(); }
        return $map;
    }
}
