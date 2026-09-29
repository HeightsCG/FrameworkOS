<?php
/**
 * CLS Video: built-in video calls for events and services, on our own LiveKit server
 * (self-hosted; https://docs.livekit.io). CLS never carries the video. It only decides who may enter a room and
 * hands them a short-lived signed pass (an HS256 JWT made with the API secret); the browser then connects straight
 * to the LiveKit server, which refuses anyone without a valid pass for that room.
 *
 * app.ini [global]: livekit_url ("wss://live.creatorlinkstudio.com"), livekit_api_key, livekit_api_secret.
 * Until all three are set, CLS Video is hidden everywhere (enabled() is false).
 *
 * Rooms: an event is one room for everyone ("ev-<event id>"); a service booking is a private room for the buyer and
 * the creator ("sv-<purchase id>").
 */
class LiveKit {

    const TTL = 7200;   // a pass is good for 2 hours; the page asks for a new one to rejoin

    private static function cfg($key): string
    {
        $c = Main::get_config();
        return trim((string) ($c['global'][$key] ?? ''));
    }

    public static function enabled(): bool
    {
        return self::cfg('livekit_url') !== '' && self::cfg('livekit_api_key') !== '' && self::cfg('livekit_api_secret') !== '';
    }

    /** The WebSocket address browsers connect to. */
    public static function url(): string { return self::cfg('livekit_url'); }

    public static function room_for_event($event_id): string   { return 'ev-' . (int) $event_id; }
    public static function room_for_booking($purchase_id): string { return 'sv-' . (int) $purchase_id; }

    /**
     * A pass into one room. $identity must be unique per person in the room ("u<user id>", guests "g<hash>"); $name is
     * shown under their video. $host adds room admin rights. $perm (from LiveControl::perm) says what they may publish:
     * ['publish' => mic/camera at all, 'sources' => list of 'camera' | 'microphone' | 'screen_share' |
     * 'screen_share_audio', 'data' => chat]. The server enforces it, so a tampered page can't get round it.
     */
    public static function token($room, $identity, $name, $host = false, array $metadata = array(), array $perm = array()): string
    {
        $now = time();
        $video = array(
            'room' => (string) $room, 'roomJoin' => true, 'canSubscribe' => true,
            'canPublish' => $host ? true : (bool) ($perm['publish'] ?? true),
            'canPublishData' => $host ? true : (bool) ($perm['data'] ?? true),
            'roomAdmin' => (bool) $host,
        );
        if (!$host && isset($perm['sources'])) { $video['canPublishSources'] = array_values((array) $perm['sources']); }
        return self::jwt(array(
            'iss'  => self::cfg('livekit_api_key'),
            'sub'  => (string) $identity,
            'name' => (string) $name,
            'nbf'  => $now - 10,
            'exp'  => $now + self::TTL,
            'metadata' => json_encode($metadata + array('host' => (bool) $host)),
            'video' => $video,
        ));
    }

    /** Make the room now with its settings in the metadata (a room that already exists is left as it is). */
    public static function create_room($room, $metadata): bool
    {
        return self::room_api('CreateRoom', array('name' => (string) $room, 'metadata' => (string) $metadata, 'empty_timeout' => 600), $room) !== null;
    }

    /** Send new settings to everyone in the room (the page listens for RoomMetadataChanged). */
    public static function set_room_metadata($room, $metadata): bool
    {
        return self::room_api('UpdateRoomMetadata', array('room' => (string) $room, 'metadata' => (string) $metadata), $room) !== null;
    }

    /** Everyone in the room: LiveKit ParticipantInfo arrays (identity, name, metadata, attributes, tracks). */
    public static function participants($room): ?array
    {
        $list = self::room_api('ListParticipants', array('room' => (string) $room), $room);
        return $list === null ? null : (array) ($list['participants'] ?? array());
    }

    /**
     * Change what one person may do, now: $perm as in token(). LiveKit takes their tracks down at once if they may no
     * longer publish them. $attributes (optional) replaces keys in their attributes (the raised hand).
     */
    public static function update_participant($room, $identity, ?array $perm, array $attributes = array()): bool
    {
        $body = array('room' => (string) $room, 'identity' => (string) $identity);
        if ($perm !== null) {
            $body['permission'] = array(
                'can_subscribe' => true, 'can_publish' => (bool) $perm['publish'], 'can_publish_data' => (bool) $perm['data'],
                'can_publish_sources' => array_map('strtoupper', array_values((array) $perm['sources'])),
            );
        }
        if (!empty($attributes)) { $body['attributes'] = (object) $attributes; }
        return self::room_api('UpdateParticipant', $body, $room) !== null;
    }

    /** A message to every page in the room from the server itself (no participant can fake it: it has no sender). */
    public static function send_data($room, array $payload, $topic): bool
    {
        return self::room_api('SendData', array('room' => (string) $room, 'data' => base64_encode(json_encode($payload)), 'kind' => 'RELIABLE', 'topic' => (string) $topic), $room) !== null;
    }

    /** Turn off one person's microphone ('MICROPHONE') or camera ('CAMERA') from the host's side. */
    public static function mute_source($room, $identity, $source): bool
    {
        foreach ((array) self::participants($room) as $p) {
            if ((string) ($p['identity'] ?? '') !== (string) $identity) { continue; }
            foreach ((array) ($p['tracks'] ?? array()) as $t) {
                if (($t['source'] ?? '') !== $source || !empty($t['muted'])) { continue; }
                return self::room_api('MutePublishedTrack', array('room' => (string) $room, 'identity' => (string) $identity, 'track_sid' => (string) $t['sid'], 'muted' => true), $room) !== null;
            }
            return true;   // nothing on to turn off
        }
        return false;
    }

    /** Who is in a room right now: ['people' => everyone but hosts, 'host_in' => a host is there]. Null if unreachable. */
    public static function room_status($room): ?array
    {
        $list = self::room_api('ListParticipants', array('room' => (string) $room), $room);
        if ($list === null) { return null; }
        $people = 0; $host_in = false;
        foreach ((array) ($list['participants'] ?? array()) as $p) {
            $meta = json_decode((string) ($p['metadata'] ?? ''), true);
            if (!empty($meta['host'])) { $host_in = true; } else { $people++; }
        }
        return array('people' => $people, 'host_in' => $host_in);
    }

    /** Take someone out of a room now (host action). They can't come back with that pass's identity kicked. */
    public static function remove_participant($room, $identity): bool
    {
        return self::room_api('RemoveParticipant', array('room' => (string) $room, 'identity' => (string) $identity), $room) !== null;
    }

    /** Mute every microphone in the room except the host's own (host action). */
    public static function mute_all($room, $except_identity = ''): int
    {
        $list = self::room_api('ListParticipants', array('room' => (string) $room), $room);
        $n = 0;
        foreach ((array) ($list['participants'] ?? array()) as $p) {
            if ((string) ($p['identity'] ?? '') === (string) $except_identity) { continue; }
            foreach ((array) ($p['tracks'] ?? array()) as $t) {
                if (($t['type'] ?? '') !== 'AUDIO' || !empty($t['muted'])) { continue; }
                if (self::room_api('MutePublishedTrack', array('room' => (string) $room, 'identity' => (string) $p['identity'], 'track_sid' => (string) $t['sid'], 'muted' => true), $room) !== null) { $n++; }
            }
        }
        return $n;
    }

    /** LiveKit's server API (Twirp over HTTPS, JSON). Returns the decoded reply, or null on failure. */
    private static function room_api($method, array $body, $room)
    {
        $base = preg_replace('#^ws#', 'http', rtrim(self::url(), '/'));
        $auth = self::jwt(array('iss' => self::cfg('livekit_api_key'), 'nbf' => time() - 10, 'exp' => time() + 60,
            'video' => array('room' => (string) $room, 'roomAdmin' => true, 'roomList' => true)));
        $ch = curl_init($base . '/twirp/livekit.RoomService/' . $method);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $auth),
            CURLOPT_POSTFIELDS => json_encode((object) $body),   // always a JSON object, even when empty
        ));
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out === false || $code !== 200) {
            error_log('[livekit] ' . $method . ' HTTP ' . $code . ': ' . substr((string) $out, 0, 200));
            return null;
        }
        $j = json_decode((string) $out, true);
        return is_array($j) ? $j : array();
    }

    private static function jwt(array $claims): string
    {
        $b64 = function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
        $head = $b64(json_encode(array('alg' => 'HS256', 'typ' => 'JWT')));
        $body = $b64(json_encode($claims));
        return $head . '.' . $body . '.' . $b64(hash_hmac('sha256', $head . '.' . $body, self::cfg('livekit_api_secret'), true));
    }
}
