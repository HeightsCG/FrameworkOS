<?php
/**
 * Audience / lightweight CRM (PRD §26). A creator's audience is the union of everyone
 * connected to them — followers, active subscribers, and buyers (PPV + bundles) — with
 * platform identity only (no PII, §24.6). Creators can tag and note each fan and message
 * them via the existing messenger. Feeds broadcast targeting.
 */
class AudienceModel extends Model {

    public function __construct(){ parent::__construct(); }

    /**
     * Full audience for a creator, one row per fan, richest first (by spend then name):
     * { id, handle, name, avatar, is_creator, is_follower, is_subscriber, is_buyer,
     *   spend_credits, purchases, followed_at, subscribed_at, last_at, tags[], note }
     */
    public function list_for_creator($creator_id){
        $cid = (int) $creator_id;
        $fans = array();   // fan_id => row

        $ensure = function (&$fans, $id) {
            $id = (int) $id;
            if ($id > 0 && !isset($fans[$id])) {
                $fans[$id] = array(
                    'id' => $id, 'is_follower' => false, 'is_subscriber' => false, 'is_buyer' => false,
                    'spend_credits' => 0, 'purchases' => 0,
                    'followed_at' => null, 'subscribed_at' => null, 'last_at' => null,
                    'tags' => array(), 'note' => '',
                );
            }
        };
        $bump_last = function (&$row, $ts) {
            if ($ts && ($row['last_at'] === null || $ts > $row['last_at'])) { $row['last_at'] = $ts; }
        };

        // Followers
        foreach ((array) parent::select(
            "SELECT follower_id AS uid, MIN(created_at) AS since FROM follows WHERE creator_id = :c GROUP BY follower_id",
            array('c' => $cid)) as $r) {
            $ensure($fans, $r['uid']);
            $fans[(int) $r['uid']]['is_follower'] = true;
            $fans[(int) $r['uid']]['followed_at'] = (string) $r['since'];
            $bump_last($fans[(int) $r['uid']], (string) $r['since']);
        }

        // Active subscribers
        foreach ((array) parent::select(
            "SELECT subscriber_id AS uid, MIN(created_at) AS since FROM creator_subscriptions
             WHERE creator_id = :c AND status = 'active' GROUP BY subscriber_id",
            array('c' => $cid)) as $r) {
            $ensure($fans, $r['uid']);
            $fans[(int) $r['uid']]['is_subscriber'] = true;
            $fans[(int) $r['uid']]['subscribed_at'] = (string) $r['since'];
            $bump_last($fans[(int) $r['uid']], (string) $r['since']);
        }

        // Buyers (PPV + bundles) — count + spend
        $buys = "SELECT fan_id AS uid, COUNT(*) AS c, SUM(price_credits) AS s, MAX(created_at) AS last
                 FROM ppv_unlocks WHERE creator_id = :c1 GROUP BY fan_id
                 UNION ALL
                 SELECT fan_id AS uid, COUNT(*) AS c, SUM(price_credits) AS s, MAX(created_at) AS last
                 FROM bundle_unlocks WHERE creator_id = :c2 GROUP BY fan_id";
        foreach ((array) parent::select($buys, array('c1' => $cid, 'c2' => $cid)) as $r) {
            $ensure($fans, $r['uid']);
            $row =& $fans[(int) $r['uid']];
            $row['is_buyer']       = true;
            $row['purchases']     += (int) $r['c'];
            $row['spend_credits'] += (int) $r['s'];
            $bump_last($row, (string) $r['last']);
            unset($row);
        }

        if (empty($fans)) { return array(); }
        $ids = array_keys($fans);

        // Identity (reuse the messenger's no-PII identity map)
        $identity = (new MessagesModel())->identity_map($ids);

        // Tags
        $in = implode(',', array_map('intval', $ids));
        foreach ((array) parent::select(
            "SELECT fan_id, tag FROM audience_tags WHERE creator_id = :c AND fan_id IN ($in) ORDER BY tag ASC",
            array('c' => $cid)) as $r) {
            if (isset($fans[(int) $r['fan_id']])) { $fans[(int) $r['fan_id']]['tags'][] = (string) $r['tag']; }
        }
        // Notes
        foreach ((array) parent::select(
            "SELECT fan_id, note FROM audience_notes WHERE creator_id = :c AND fan_id IN ($in)",
            array('c' => $cid)) as $r) {
            if (isset($fans[(int) $r['fan_id']])) { $fans[(int) $r['fan_id']]['note'] = (string) $r['note']; }
        }

        $blocked = (new BlocksModel())->related_ids($creator_id);
        $out = array();
        foreach ($fans as $id => $row) {
            if (isset($blocked[(int) $id])) { continue; }
            $idn = $identity[$id] ?? array('handle' => '', 'name' => 'Unknown', 'avatar' => '', 'is_creator' => false);
            $row['handle']     = $idn['handle'];
            $row['name']       = $idn['name'];
            $row['avatar']     = $idn['avatar'];
            $row['is_creator'] = !empty($idn['is_creator']);
            $out[] = $row;
        }
        // Richest audience first, then alphabetical.
        usort($out, function ($a, $b) {
            if ($a['spend_credits'] !== $b['spend_credits']) { return $b['spend_credits'] - $a['spend_credits']; }
            return strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /** Segment counts for the filter chips. */
    public function segment_counts(array $rows){
        $c = array('all' => count($rows), 'followers' => 0, 'subscribers' => 0, 'buyers' => 0);
        foreach ($rows as $r) {
            if (!empty($r['is_follower']))   { $c['followers']++; }
            if (!empty($r['is_subscriber'])) { $c['subscribers']++; }
            if (!empty($r['is_buyer']))      { $c['buyers']++; }
        }
        return $c;
    }

    /* ---------- Tags ---------- */

    public function tags_for($creator_id, $fan_id){
        $rows = parent::select(
            "SELECT tag FROM audience_tags WHERE creator_id = :c AND fan_id = :f ORDER BY tag ASC",
            array('c' => (int) $creator_id, 'f' => (int) $fan_id));
        $out = array();
        foreach ((array) $rows as $r) { $out[] = (string) $r['tag']; }
        return $out;
    }

    public function add_tag($creator_id, $fan_id, $tag){
        $tag = trim((string) $tag);
        if ($tag === '') { return false; }
        if (mb_strlen($tag) > 40) { $tag = mb_substr($tag, 0, 40); }
        $exists = parent::select(
            "SELECT id FROM audience_tags WHERE creator_id = :c AND fan_id = :f AND tag = :t LIMIT 1",
            array('c' => (int) $creator_id, 'f' => (int) $fan_id, 't' => $tag));
        if (is_array($exists) && count($exists)) { return true; }
        return (bool) parent::insert('audience_tags', array(
            'creator_id' => (int) $creator_id, 'fan_id' => (int) $fan_id,
            'tag' => $tag, 'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    public function remove_tag($creator_id, $fan_id, $tag){
        return parent::delete('audience_tags', 'creator_id = :c AND fan_id = :f AND tag = :t', 1,
            array('c' => (int) $creator_id, 'f' => (int) $fan_id, 't' => (string) $tag));
    }

    /* ---------- Notes ---------- */

    public function save_note($creator_id, $fan_id, $note){
        $note = (string) $note;
        if (mb_strlen($note) > 2000) { $note = mb_substr($note, 0, 2000); }
        $now = date('Y-m-d H:i:s');
        $exists = parent::select(
            "SELECT id FROM audience_notes WHERE creator_id = :c AND fan_id = :f LIMIT 1",
            array('c' => (int) $creator_id, 'f' => (int) $fan_id));
        if (is_array($exists) && count($exists)) {
            return parent::update('audience_notes', array('note' => $note, 'updated_at' => $now),
                'creator_id = :c AND fan_id = :f', array('c' => (int) $creator_id, 'f' => (int) $fan_id));
        }
        return (bool) parent::insert('audience_notes', array(
            'creator_id' => (int) $creator_id, 'fan_id' => (int) $fan_id,
            'note' => $note, 'updated_at' => $now,
        ));
    }

    /** Does this fan belong to the creator's audience? (authorization for tag/note actions) */
    public function is_audience_member($creator_id, $fan_id){
        $cid = (int) $creator_id; $fid = (int) $fan_id;
        $rows = parent::select(
            "SELECT 1 AS ok FROM follows WHERE creator_id = :c1 AND follower_id = :f1
             UNION SELECT 1 FROM creator_subscriptions WHERE creator_id = :c2 AND subscriber_id = :f2 AND status = 'active'
             UNION SELECT 1 FROM ppv_unlocks WHERE creator_id = :c3 AND fan_id = :f3
             UNION SELECT 1 FROM bundle_unlocks WHERE creator_id = :c4 AND fan_id = :f4
             LIMIT 1",
            array('c1' => $cid, 'f1' => $fid, 'c2' => $cid, 'f2' => $fid, 'c3' => $cid, 'f3' => $fid, 'c4' => $cid, 'f4' => $fid));
        return is_array($rows) && count($rows) > 0;
    }
}
