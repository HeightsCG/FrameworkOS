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
     * A pass into one room. $identity must be unique per person in the room (we use "u<user id>"); $name is shown
     * under their video. $host adds room admin rights (remove people, mute everyone).
     */
    public static function token($room, $identity, $name, $host = false, array $metadata = array()): string
    {
        $now = time();
        return self::jwt(array(
            'iss'  => self::cfg('livekit_api_key'),
            'sub'  => (string) $identity,
            'name' => (string) $name,
            'nbf'  => $now - 10,
            'exp'  => $now + self::TTL,
            'metadata' => json_encode($metadata + array('host' => (bool) $host)),
            'video' => array(
                'room' => (string) $room, 'roomJoin' => true,
                'canPublish' => true, 'canSubscribe' => true, 'canPublishData' => true,
                'roomAdmin' => (bool) $host,
            ),
        ));
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
