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

    /** update_participant() for many people at once: [[identity, perm], ...]. Returns how many succeeded. */
    public static function update_participants($room, array $list): int
    {
        $bodies = array();
        foreach ($list as $x) {
            $bodies[] = array('room' => (string) $room, 'identity' => (string) $x[0], 'permission' => array(
                'can_subscribe' => true, 'can_publish' => (bool) $x[1]['publish'], 'can_publish_data' => (bool) $x[1]['data'],
                'can_publish_sources' => array_map('strtoupper', array_values((array) $x[1]['sources']))));
        }
        return self::api_many('RoomService', 'UpdateParticipant', $bodies, $room);
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

    /** Close a room for everyone in it now (the host's End Call for Everyone). Their pages show "The call has ended". */
    public static function delete_room($room): bool
    {
        return self::room_api('DeleteRoom', array('room' => (string) $room), $room) !== null;
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

    /* ---- Recording (LiveKit Egress, running next to the server; it uploads to S3 itself, see docs/live-video.md) ---- */

    /**
     * Start recording a room as one MP4, Zoom-style: the active speaker big with the others in a strip, a shared
     * screen taking the main spot. $filepath is the S3 key it uploads to (bucket and keys are in egress.yaml on the
     * video server). Returns the egress id, or '' when it couldn't start (recorder busy or down).
     */
    public static function start_recording($room, $filepath): string
    {
        $r = self::api('Egress', 'StartRoomCompositeEgress', array(
            'room_name' => (string) $room, 'layout' => 'speaker',
            'file_outputs' => array(array('file_type' => 'MP4', 'filepath' => (string) $filepath)),
        ), $room, true);
        return $r === null ? '' : (string) self::field($r, 'egress_id');
    }

    public static function stop_recording($egress_id): bool
    {
        return self::api('Egress', 'StopEgress', array('egress_id' => (string) $egress_id), '', true) !== null;
    }

    /**
     * Where a recording is: ['status' => EGRESS_STARTING|EGRESS_ACTIVE|EGRESS_ENDING|EGRESS_COMPLETE|EGRESS_FAILED|
     * EGRESS_ABORTED|EGRESS_LIMIT_REACHED, 'error', 'file' => S3 key, 'bytes', 'duration' (seconds)], or null when the
     * video server can't be reached.
     */
    public static function recording_info($egress_id): ?array
    {
        $r = self::api('Egress', 'ListEgress', array('egress_id' => (string) $egress_id), '', true);
        if ($r === null) { return null; }
        $item = (array) (($r['items'] ?? array())[0] ?? array());
        if (!$item) { return array('status' => 'EGRESS_FAILED', 'error' => 'The recorder has no record of this recording.', 'file' => '', 'bytes' => 0, 'duration' => 0); }
        $files = (array) (self::field($item, 'file_results') ?: array());
        $f = (array) ($files[0] ?? (self::field($item, 'file') ?: array()));
        $st = self::field($item, 'status');
        return array(
            'status'   => is_numeric($st) ? (array('EGRESS_STARTING', 'EGRESS_ACTIVE', 'EGRESS_ENDING', 'EGRESS_COMPLETE', 'EGRESS_FAILED', 'EGRESS_ABORTED', 'EGRESS_LIMIT_REACHED')[(int) $st] ?? 'EGRESS_FAILED') : (string) ($st ?: 'EGRESS_STARTING'),
            'error'    => (string) self::field($item, 'error'),
            'file'     => (string) self::field($f, 'filename'),
            'bytes'    => (int) self::field($f, 'size'),
            'duration' => (int) round(((float) self::field($f, 'duration')) / 1e9),   // nanoseconds
        );
    }

    /** A reply field under its proto name (egress_id) or its JSON name (egressId). */
    private static function field(array $a, $snake)
    {
        if (array_key_exists($snake, $a)) { return $a[$snake]; }
        $camel = lcfirst(str_replace('_', '', ucwords($snake, '_')));
        return $a[$camel] ?? '';
    }

    /** LiveKit's server API (Twirp over HTTPS, JSON). Returns the decoded reply, or null on failure. */
    private static function room_api($method, array $body, $room)
    {
        return self::api('RoomService', $method, $body, $room, false);
    }

    /**
     * One request to the video server. The connection is kept open for the rest of the page request (one secure
     * handshake instead of one per call), since a single host action can make several calls.
     */
    private static $conn = null;
    private static function api($service, $method, array $body, $room, $record)
    {
        if (self::$conn === null) { self::$conn = curl_init(); }
        $ch = self::$conn;
        curl_setopt_array($ch, self::request_opts($service, $method, $body, $room, $record));
        return self::reply($method, curl_exec($ch), (int) curl_getinfo($ch, CURLINFO_HTTP_CODE));
    }

    /**
     * The same request for many people at once (a host setting that changes everyone's rights): all sent together
     * over parallel connections instead of one after another. Returns how many succeeded.
     */
    private static function api_many($service, $method, array $bodies, $room): int
    {
        if (count($bodies) <= 1) { $ok = 0; foreach ($bodies as $b) { if (self::api($service, $method, $b, $room, false) !== null) { $ok++; } } return $ok; }
        $ok = 0;
        foreach (array_chunk($bodies, 20) as $chunk) {   // at most 20 at a time
            $mh = curl_multi_init(); $hs = array();
            foreach ($chunk as $b) {
                $h = curl_init(); curl_setopt_array($h, self::request_opts($service, $method, $b, $room, false));
                curl_multi_add_handle($mh, $h); $hs[] = $h;
            }
            do { $st = curl_multi_exec($mh, $running); if ($running) { curl_multi_select($mh, 1.0); } } while ($running && $st === CURLM_OK);
            foreach ($hs as $h) {
                if (self::reply($method, curl_multi_getcontent($h), (int) curl_getinfo($h, CURLINFO_HTTP_CODE)) !== null) { $ok++; }
                curl_multi_remove_handle($mh, $h); curl_close($h);
            }
            curl_multi_close($mh);
        }
        return $ok;
    }

    private static function request_opts($service, $method, array $body, $room, $record): array
    {
        $base = preg_replace('#^ws#', 'http', rtrim(self::url(), '/'));
        $grant = array('room' => (string) $room, 'roomAdmin' => true, 'roomList' => true, 'roomCreate' => true);   // CreateRoom/DeleteRoom need roomCreate
        if ($record) { $grant['roomRecord'] = true; }
        $auth = self::jwt(array('iss' => self::cfg('livekit_api_key'), 'nbf' => time() - 10, 'exp' => time() + 60, 'video' => $grant));
        return array(
            CURLOPT_URL => $base . '/twirp/livekit.' . $service . '/' . $method,
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $auth),
            CURLOPT_POSTFIELDS => json_encode((object) $body),   // always a JSON object, even when empty
        );
    }

    private static function reply($method, $out, $code)
    {
        if ($out === false || $out === null || $code !== 200) {
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
