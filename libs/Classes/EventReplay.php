<?php
/**
 * Paid event replays. A creator picks one of an event's CLS Video recordings as its replay and sets a price in
 * credits; it's watched on the public event page (never through Content Studio). Who can watch: the creator and
 * their team, anyone who bought it (replay_unlocks), and, when the creator chose so, people who registered for the
 * event. Buying works like any credit sale: the fan pays, the creator gets their share at once, and sales are final.
 */
class EventReplay {

    /** This event's replay with its video (on sale, or taken off sale but still watchable by buyers), or null. */
    public static function info(array $ev): ?array
    {
        $rid = (int) ($ev['replay_recording_id'] ?? 0);   // stays set after "Stop Selling" so buyers keep watching (price 0 = off sale)
        if ($rid <= 0) { return null; }
        $rec = (new LiveRecordingsModel())->get($rid);
        if (!$rec || (int) $rec['event_id'] !== (int) $ev['id'] || (string) $rec['status'] !== 'ready' || (int) $rec['asset_id'] <= 0) { return null; }
        $asset = (new MediaAssetsModel())->get_one((int) $ev['creator_id'], (int) $rec['asset_id']);
        if (!$asset || (string) $asset['original_key'] === '') { return null; }
        return array('recording' => $rec, 'asset' => $asset, 'price' => (int) $ev['replay_price_credits'], 'on_sale' => (int) $ev['replay_price_credits'] > 0,
                     'free_attendees' => (int) ($ev['replay_free_attendees'] ?? 1) === 1, 'duration' => (int) $rec['duration_sec']);
    }

    /** Why this viewer may watch: 'host' | 'bought' | 'attendee', or '' when they need to buy it. */
    public static function access(array $ev, array $info, $viewer_id): string
    {
        $viewer_id = (int) $viewer_id;
        if ($viewer_id <= 0) { return ''; }
        if (LiveAccess::is_host((int) $ev['creator_id'])) { return 'host'; }
        if ((new ReplayUnlocksModel())->has((int) $ev['id'], $viewer_id)) { return 'bought'; }
        if ($info['free_attendees'] && (new EventsModel())->going_registration((int) $ev['id'], $viewer_id)) { return 'attendee'; }
        return '';
    }

    /** "1:02:10" / "42:07". */
    public static function length($secs): string
    {
        $secs = (int) $secs;
        if ($secs <= 0) { return ''; }
        return ($secs >= 3600 ? floor($secs / 3600) . ':' . sprintf('%02d', floor($secs % 3600 / 60)) : (string) floor($secs / 60)) . ':' . sprintf('%02d', $secs % 60);
    }
}
