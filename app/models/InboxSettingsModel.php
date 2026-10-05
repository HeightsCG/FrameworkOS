<?php
/**
 * Per-creator inbox-automation guardrails (one row per creator). Like
 * CreatorBrandModel, get_for_creator() returns a defaults array when no row
 * exists so callers never null-check.
 */
class InboxSettingsModel extends Model {

    public function __construct(){
        parent::__construct();
    }

    public static function defaults(){
        return array(
            'fanvue_enabled'  => 0,
            'cls_enabled'     => 0,
            'mode'            => 'approve',
            'quiet_start'     => null,
            'quiet_end'       => null,
            'quiet_action'    => 'hold',
            'max_consecutive' => 3,
            'persona'         => '',
            'avoid_topics'    => '',
            'upsell_enabled'  => 0,
            'disclose_ai'     => 0,
            'ai_disclosure_text'    => AiDisclosure::DEFAULT_FIRST_REPLY,   // sent with the first automated reply to each fan; never blank
            'persona_influencer_id' => 0,                                    // whose persona the replies are written in (0 = brand voice only)
        );
    }

    /** Claim the one-time disclosure for (creator, fan): true for the first caller only. */
    public function claim_disclosure($creator_id, $fan_id){
        try {
            parent::insert('inbox_disclosures', array('creator_id' => (int) $creator_id, 'fan_id' => (int) $fan_id, 'created_at' => date('Y-m-d H:i:s')));
            return true;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') { return false; }   // already disclosed to this fan
            throw $e;
        }
    }

    /** Give the claim back (the reply that carried the disclosure was not sent). */
    public function release_disclosure($creator_id, $fan_id){
        return parent::delete_all('inbox_disclosures', 'creator_id = :c AND fan_id = :f', array('c' => (int) $creator_id, 'f' => (int) $fan_id));
    }

    public function get_for_creator($creator_id){
        $rows = parent::select(
            "SELECT * FROM inbox_settings WHERE creator_id = :c",
            array('c' => (int) $creator_id)
        );
        $out = self::defaults();
        if (is_array($rows) && count($rows) === 1) {
            foreach ($out as $k => $v) {
                if (array_key_exists($k, $rows[0])) { $out[$k] = $rows[0][$k]; }
            }
            $out['quiet_start'] = self::hhmm($out['quiet_start']);
            $out['quiet_end']   = self::hhmm($out['quiet_end']);
        }
        $out['creator_id'] = (int) $creator_id;
        return $out;
    }

    /** Validate + clamp then upsert. Returns the cleaned array. */
    public function save($creator_id, array $f){
        $clean = array(
            'fanvue_enabled'  => 0,
            'cls_enabled'     => !empty($f['cls_enabled']) ? 1 : 0,
            'mode'            => (($f['mode'] ?? '') === 'auto') ? 'auto' : 'approve',
            'quiet_start'     => self::hhmm($f['quiet_start'] ?? null),
            'quiet_end'       => self::hhmm($f['quiet_end'] ?? null),
            'quiet_action'    => (($f['quiet_action'] ?? '') === 'skip') ? 'skip' : 'hold',
            'max_consecutive' => max(1, min(10, (int) ($f['max_consecutive'] ?? 3))),
            'persona'         => mb_substr(trim(strip_tags((string) ($f['persona'] ?? ''))), 0, 2000),
            'avoid_topics'    => mb_substr(trim(strip_tags((string) ($f['avoid_topics'] ?? ''))), 0, 2000),
            'upsell_enabled'  => !empty($f['upsell_enabled']) ? 1 : 0,
            'disclose_ai'     => !empty($f['disclose_ai']) ? 1 : 0,
            'ai_disclosure_text'    => AiDisclosure::first_reply_text($f['ai_disclosure_text'] ?? ''),   // blank falls back to the default: it cannot be empty
            'persona_influencer_id' => ((int) ($f['persona_influencer_id'] ?? 0) > 0) ? (int) $f['persona_influencer_id'] : null,
            'updated_at'      => date('Y-m-d H:i:s'),
        );
        // A half-set window is no window.
        if ($clean['quiet_start'] === null || $clean['quiet_end'] === null) {
            $clean['quiet_start'] = null; $clean['quiet_end'] = null;
        }
        $exists = parent::select("SELECT id FROM inbox_settings WHERE creator_id = :c", array('c' => (int) $creator_id));
        if (is_array($exists) && count($exists) === 1) {
            parent::update('inbox_settings', $clean, 'creator_id = :c', array('c' => (int) $creator_id));
        } else {
            $clean['creator_id'] = (int) $creator_id;
            $clean['created_at'] = $clean['updated_at'];
            parent::insert('inbox_settings', $clean);
        }
        return $clean;
    }

    /**
     * Is "now" inside the creator's quiet window? Times are wall-clock in $tz;
     * a window whose start is after its end wraps past midnight (22:00–07:00).
     */
    public static function is_quiet(array $s, $tz, $now_utc = null): bool {
        $start = self::hhmm($s['quiet_start'] ?? null);
        $end   = self::hhmm($s['quiet_end'] ?? null);
        if ($start === null || $end === null || $start === $end) { return false; }
        try { $zone = new DateTimeZone((string) $tz ?: 'UTC'); } catch (\Throwable $e) { $zone = new DateTimeZone('UTC'); }
        $now = new DateTime($now_utc ?: 'now', new DateTimeZone('UTC'));
        $now->setTimezone($zone);
        $cur = (int) $now->format('H') * 60 + (int) $now->format('i');
        list($sh, $sm) = explode(':', $start); list($eh, $em) = explode(':', $end);
        $a = (int) $sh * 60 + (int) $sm; $b = (int) $eh * 60 + (int) $em;
        return ($a < $b) ? ($cur >= $a && $cur < $b) : ($cur >= $a || $cur < $b);
    }

    /** Normalise "H:MM", "HH:MM:SS" → "HH:MM"; anything else → null. */
    private static function hhmm($v){
        $v = trim((string) $v);
        if ($v === '' || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $v, $m)) { return null; }
        $h = (int) $m[1]; $mi = (int) $m[2];
        if ($h > 23 || $mi > 59) { return null; }
        return sprintf('%02d:%02d', $h, $mi);
    }

}
