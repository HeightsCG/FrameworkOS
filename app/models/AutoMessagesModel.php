<?php
/**
 * Welcome / trigger messages a creator sends automatically on Creator Link Studio events
 * (new follower, new subscriber, first message, new purchase). One row per trigger per
 * creator; auto_message_sends records each delivery so a fan gets a trigger once
 * (purchases append the purchase reference so they can repeat).
 */
class AutoMessagesModel extends Model {

    const TRIGGERS = array('new_follower', 'new_subscriber', 'first_message', 'new_purchase');

    /** Every account starts with these, switched on. A saved row (even a disabled one) replaces the default. */
    const DEFAULTS = array(
        'new_follower'   => 'Thanks for the follow! I post new content here all the time. Message me anytime, I read everything.',
        'new_subscriber' => 'Welcome in! You now have full access to everything I post. Say hi anytime, I love hearing from you.',
        'first_message'  => 'Hey! Thanks for reaching out. I read every message and reply as soon as I can.',
        'new_purchase'   => 'Thank you for the support! Enjoy, and let me know what you think.',
    );

    public static function is_trigger($key){ return in_array((string) $key, self::TRIGGERS, true); }

    private static function default_row($creator_id, $trigger){
        return array('id' => 0, 'creator_id' => (int) $creator_id, 'trigger_key' => (string) $trigger, 'enabled' => 1,
            'text' => (string) (self::DEFAULTS[$trigger] ?? ''), 'price_credits' => 0, 'asset_ids' => array(), 'updated_at' => null, 'is_default' => true);
    }

    /** trigger_key => row for a creator: the saved row, or the built-in default. */
    public function get_for_creator($creator_id){
        $rows = parent::select("SELECT * FROM auto_messages WHERE creator_id = :c", array('c' => (int) $creator_id));
        $out = array();
        foreach (self::TRIGGERS as $t) { $out[$t] = self::default_row($creator_id, $t); }
        foreach ((array) $rows as $r) {
            $r['asset_ids'] = self::decode_ids($r['asset_ids'] ?? null);
            $r['is_default'] = false;
            $out[(string) $r['trigger_key']] = $r;
        }
        return $out;
    }

    /** The saved row, or the built-in default (enabled) when the creator never touched this trigger. */
    public function get_one($creator_id, $trigger){
        if (!self::is_trigger($trigger)) { return null; }
        $rows = parent::select("SELECT * FROM auto_messages WHERE creator_id = :c AND trigger_key = :t",
            array('c' => (int) $creator_id, 't' => (string) $trigger));
        if (!is_array($rows) || count($rows) !== 1) { return self::default_row($creator_id, $trigger); }
        $r = $rows[0];
        $r['asset_ids'] = self::decode_ids($r['asset_ids'] ?? null);
        $r['is_default'] = false;
        return $r;
    }

    /** True when a row is stored for this trigger (as opposed to the built-in default). */
    public function has_saved($creator_id, $trigger){
        $rows = parent::select("SELECT id FROM auto_messages WHERE creator_id = :c AND trigger_key = :t", array('c' => (int) $creator_id, 't' => (string) $trigger));
        return is_array($rows) && count($rows) === 1;
    }

    /** Upsert one trigger. $asset_ids are trusted (the caller verifies ownership). */
    public function save($creator_id, $trigger, $text, $enabled, array $asset_ids = array(), $price_credits = 0){
        if (!self::is_trigger($trigger)) { return false; }
        $asset_ids = array_values(array_unique(array_filter(array_map('intval', $asset_ids))));
        $data = array(
            'enabled'       => $enabled ? 1 : 0,
            'text'          => mb_substr((string) $text, 0, 2000),
            'price_credits' => empty($asset_ids) ? 0 : max(0, (int) $price_credits),
            'asset_ids'     => empty($asset_ids) ? null : json_encode($asset_ids),
            'updated_at'    => date('Y-m-d H:i:s'),
        );
        if ($this->has_saved($creator_id, $trigger)) {
            return parent::update('auto_messages', $data, 'creator_id = :c AND trigger_key = :t', array('c' => (int) $creator_id, 't' => (string) $trigger));
        }
        $data['creator_id']  = (int) $creator_id;
        $data['trigger_key'] = (string) $trigger;
        return parent::insert('auto_messages', $data);
    }

    /** Turn a trigger off. Keeps the text (a stored, disabled row also stops the default from coming back). */
    public function delete_one($creator_id, $trigger){
        $cur = $this->get_one($creator_id, $trigger);
        if (!$cur) { return false; }
        return $this->save($creator_id, $trigger, (string) $cur['text'], false, (array) $cur['asset_ids'], (int) $cur['price_credits']);
    }

    /** Claim a delivery slot. False when this fan already got this trigger (ref). */
    public function claim_send($creator_id, $fan_id, $trigger_ref){
        try {
            parent::insert('auto_message_sends', array(
                'creator_id' => (int) $creator_id, 'fan_id' => (int) $fan_id,
                'trigger_ref' => mb_substr((string) $trigger_ref, 0, 64), 'sent_at' => date('Y-m-d H:i:s'),
            ));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function release_send($creator_id, $fan_id, $trigger_ref){
        return parent::delete_all('auto_message_sends', 'creator_id = :c AND fan_id = :f AND trigger_ref = :t',
            array('c' => (int) $creator_id, 'f' => (int) $fan_id, 't' => mb_substr((string) $trigger_ref, 0, 64)));
    }

    /** Sends per trigger for a creator (settings page counts). */
    public function send_counts($creator_id){
        $rows = parent::select(
            "SELECT SUBSTRING_INDEX(trigger_ref, ':', 1) AS k, COUNT(*) AS n
             FROM auto_message_sends WHERE creator_id = :c GROUP BY k", array('c' => (int) $creator_id));
        $out = array();
        foreach ((array) $rows as $r) { $out[(string) $r['k']] = (int) $r['n']; }
        return $out;
    }

    private static function decode_ids($json){
        $ids = json_decode((string) $json, true);
        return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : array();
    }
}
