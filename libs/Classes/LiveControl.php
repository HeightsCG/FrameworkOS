<?php
/**
 * CLS Video host controls. A call's settings live in live_rooms (LiveRoomsModel) while it runs and are sent to every
 * page in the call as the LiveKit room metadata, so everyone's controls update at once:
 *   waiting   people wait in the lobby until the host lets them in
 *   share     'host' = only hosts share a screen, 'everyone' = attendees with an account can too
 *   watch     watch-only: attendees can't use their mic or camera unless the host lets them talk (speakers)
 *   chat      chat in the call on or off
 *   locked    nobody new gets in (people let in before can rejoin)
 *   spotlight one person shown big for everyone (a shared screen still comes first)
 * What each attendee may publish is enforced by LiveKit itself: set in their pass at join (LiveKit::token) and changed
 * on the fly with LiveKit::update_participant when the host changes a setting.
 */
class LiveControl {

    /** An event's starting settings (chosen in the event editor), or a booking's (a private 1-to-1 call). */
    public static function defaults(?array $ev): array
    {
        if ($ev === null) { return array('waiting' => 0, 'share' => 'everyone', 'watch' => 0, 'chat' => 1); }
        return array(
            'waiting' => (int) ($ev['call_waiting_room'] ?? 0) === 1 ? 1 : 0,
            'share'   => (string) ($ev['call_screen_share'] ?? 'host') === 'everyone' ? 'everyone' : 'host',
            'watch'   => (string) ($ev['call_attendees'] ?? 'talk') === 'watch' ? 1 : 0,
            'chat'    => (int) ($ev['call_chat'] ?? 1) === 0 ? 0 : 1,
        );
    }

    /** The call's settings now (made from $defaults the first time). */
    public static function state($room, array $defaults): array
    {
        $m = new LiveRoomsModel();
        $row = $m->get($room);
        if ($row === null) { $m->init($room, $defaults); $row = $m->get($room); }
        $row = (array) $row;
        $speakers = json_decode((string) ($row['speakers'] ?? ''), true);
        return array(
            'waiting'   => (int) ($row['waiting'] ?? $defaults['waiting']) === 1,
            'share'     => (string) ($row['share'] ?? $defaults['share']) === 'everyone' ? 'everyone' : 'host',
            'watch'     => (int) ($row['watch'] ?? $defaults['watch']) === 1,
            'chat'      => (int) ($row['chat'] ?? $defaults['chat']) === 1,
            'locked'    => (int) ($row['locked'] ?? 0) === 1,
            'spotlight' => (string) ($row['spotlight'] ?? ''),
            'speakers'  => is_array($speakers) ? array_values(array_map('strval', $speakers)) : array(),
            'offer'     => is_array($o = json_decode((string) ($row['pinned'] ?? ''), true)) ? $o : null,
        );
    }

    /** What one attendee may publish under these settings (hosts may do everything). */
    public static function perm(array $s, $identity, $guest): array
    {
        $sources = array('camera', 'microphone');
        if ($s['share'] === 'everyone' && !$guest) { $sources[] = 'screen_share'; $sources[] = 'screen_share_audio'; }
        return array(
            'publish' => !$s['watch'] || in_array((string) $identity, $s['speakers'], true),
            'sources' => $sources,
            'data'    => (bool) $s['chat'],
        );
    }

    /** The settings as the pages see them (room metadata). */
    public static function meta(array $s): string
    {
        return json_encode(array('waiting' => $s['waiting'], 'share' => $s['share'], 'watch' => $s['watch'], 'chat' => $s['chat'],
                                 'locked' => $s['locked'], 'spotlight' => $s['spotlight'], 'speakers' => $s['speakers'], 'offer' => $s['offer'] ?? null));
    }

    /**
     * Before someone connects: the room is made with the settings already in it, and a room that's still open gets
     * the current settings too (they may have changed since, e.g. the event was saved).
     */
    public static function open_room($room, array $s): void
    {
        LiveKit::create_room($room, self::meta($s));
        LiveKit::set_room_metadata($room, self::meta($s));
    }

    /**
     * The host changed something: tell every page and apply the new rights to everyone in the call now.
     * $who limits the rights update to one person (letting one person talk). Returns false if the video server
     * couldn't be reached.
     */
    public static function push($room, array $s, $who = null): bool
    {
        $ok = LiveKit::set_room_metadata($room, self::meta($s));
        $people = LiveKit::participants($room);
        if ($people === null) { return false; }
        foreach ($people as $p) {
            $id = (string) ($p['identity'] ?? '');
            if ($who !== null && $id !== (string) $who) { continue; }
            $meta = json_decode((string) ($p['metadata'] ?? ''), true);
            if (!empty($meta['host'])) { continue; }   // hosts keep every right
            $ok = LiveKit::update_participant($room, $id, self::perm($s, $id, !empty($meta['guest']))) && $ok;
        }
        return $ok;
    }

    /**
     * What a host can pin in their call: their live bundles, published services and active membership plans, each as
     * the card attendees see (type, id, title, price, cta, url). Bundles and services are in credits; memberships are
     * billed to a card, so their price is in dollars.
     */
    public static function offers($creator_id): array
    {
        $rows  = (new UsersModel())->get_user_by_id((int) $creator_id);
        $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$owner) { return array(); }
        $d = function ($s) { return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'); };
        $out = array();
        foreach ((array) (new ContentBundlesModel())->get_active_for_creator($creator_id) as $b) {
            $out[] = array('type' => 'bundle', 'id' => (int) $b['id'], 'title' => $d($b['name']), 'price' => Price::credits((int) $b['price_credits']),
                           'cta' => 'Buy', 'url' => CustomDomains::share_url($owner, '') . '#plans');
        }
        foreach ((new ServicesModel())->list_for_creator($creator_id) as $sv) {
            if ((string) $sv['status'] !== 'published') { continue; }
            $out[] = array('type' => 'service', 'id' => (int) $sv['id'], 'title' => $d($sv['name']),
                           'price' => (int) $sv['price_credits'] > 0 ? Price::credits((int) $sv['price_credits']) : 'Free',
                           'cta' => 'Book', 'url' => CustomDomains::share_url($owner, 'services/' . (int) $sv['id']));
        }
        foreach ((array) (new CreatorPlansModel())->get_active_for_user($creator_id) as $pl) {
            $cents = (int) $pl['price_cents'];
            $out[] = array('type' => 'plan', 'id' => (int) $pl['id'], 'title' => $d($pl['name']),
                           'price' => $cents > 0 ? '$' . number_format($cents / 100, 2) . ' / ' . ((string) $pl['billing_interval'] === 'year' ? 'year' : 'month') : 'Free',
                           'cta' => 'Join', 'url' => CustomDomains::share_url($owner, '') . '#plans');
        }
        return $out;
    }

    /** The identity a signed-in person or a guest (by browser session) has in a room. */
    public static function identity($user_id, $room): string
    {
        return (int) $user_id > 0 ? 'u' . (int) $user_id : 'g' . substr(hash('sha256', session_id() . '|' . $room), 0, 16);
    }
}
