<?php
/**
 * CLS Video calls (LiveKit). The page asks here for a pass into a room; LiveAccess decides who may enter and when,
 * LiveKit signs the pass. Hosts (the creator or their team) can also remove someone and mute everyone.
 * Routed from /api/<action> by ApiRoutes.
 */
class ApiLiveController extends BaseApiController {

    /**
     * A pass into an event call or a booking call: {kind: event|booking, id, password?, name? (guests)}.
     * Guests (not signed in) can only join a free event open to everyone; they give the name others will see.
     */
    public function live_joinAction(){
        $me = (int) Session::get('user_id');
        $ip = $this->get_ip_address();
        $kind = (string) ($this->post['kind'] ?? ''); $id = (int) ($this->post['id'] ?? 0);
        $pw = html_entity_decode((string) ($this->post['password'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($pw !== '' && $this->loginAttemptsModel->count_recent($ip, 'callpw', 10) >= 10) {
            $this->jsonError('Too many wrong passwords. Wait a few minutes and try again.', ['need_password' => true]);
        }
        $r = LiveAccess::check($kind, $id, $me, $pw);
        if (!$r['ok']) {
            if (!empty($r['wrong_password'])) { $this->loginAttemptsModel->record($ip, 'uid:' . $me, 'callpw'); }
            $extra = array();
            foreach (array('need_login', 'need_password') as $k) { if (!empty($r[$k])) { $extra[$k] = true; } }
            if (!empty($r['opens_at'])) { $extra['opens_at'] = (int) $r['opens_at']; }
            $this->jsonError((string) $r['message'], $extra);
        }
        $room = $r['room'];
        if (!empty($r['guest'])) {
            $name = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) ($this->post['name'] ?? ''), ENT_QUOTES, 'UTF-8'))));
            if ($name === '') { $this->jsonError('Enter your name.', ['need_name' => true]); }
            $name = mb_substr($name, 0, 40);
        } else {
            $name = Notify::name_of($me) ?: 'Guest';
        }
        // One identity per person (guests: per browser session), so rejoining replaces the old seat instead of doubling up.
        $identity = LiveControl::identity($me, $room);
        $s = LiveControl::state($room, (array) $r['defaults']);

        if (empty($r['host'])) {
            $rooms = new LiveRoomsModel();
            $entry = $rooms->entry($room, $identity);
            $status = $entry ? (string) $entry['status'] : '';
            if ($status === 'denied') { $this->jsonError('The host didn’t let you into this call.', ['denied' => true]); }
            if ($status !== 'admitted') {
                if ($s['locked']) { $this->jsonError('The host locked this call, so no one new can join.', ['locked' => true]); }
                if ($s['waiting']) {
                    $rooms->wait($room, $identity, $name);   // the page asks again every few seconds until the host decides
                    $this->jsonSuccess(['waiting' => true, 'message' => 'Waiting for the host to let you in.']);
                }
                $rooms->admit($room, $identity, $name);
            }
        }
        if (!empty($r['guest'])) {
            if ($this->loginAttemptsModel->count_recent($ip, 'guestjoin', 10) >= 30) { $this->jsonError('Too many joins from this connection. Wait a few minutes and try again.'); }
            $this->loginAttemptsModel->record($ip, 'guest', 'guestjoin');
        }
        LiveControl::open_room($room, $s);   // the room starts with its settings (a room that's already open keeps its own)
        $this->jsonSuccess([
            'url'   => LiveKit::url(),
            'token' => LiveKit::token($room, $identity, $name, (bool) $r['host'], $me > 0 ? ['uid' => $me] : ['guest' => true],
                                      LiveControl::perm($s, $identity, !empty($r['guest']))),
            'host'  => (bool) $r['host'],
            'title' => $r['title'],
            'me'    => $identity,
            'state' => json_decode(LiveControl::meta($s), true),
        ]);
    }

    /** Host: how many people are in an event's call and whether a host is there (the manage page polls this). {id} */
    public function live_statusAction(){
        $ev = (new EventsModel())->get_public((int) ($this->post['id'] ?? 0));
        if (!$ev || (string) ($ev['format'] ?? '') !== 'cls_video' || !LiveAccess::is_host((int) $ev['creator_id'])) { $this->jsonError('Not available'); }
        $s = LiveKit::room_status(LiveKit::room_for_event((int) $ev['id']));
        if ($s === null) { $this->jsonError('Could not reach the video server'); }
        $this->jsonSuccess($s + ['open' => LiveAccess::event_phase($ev) !== 'closed']);
    }

    /** Host: take one person out of the call. {kind, id, identity} */
    public function live_removeAction(){
        $r = $this->host_room();
        $who = preg_replace('/[^a-z0-9]/i', '', (string) ($this->post['identity'] ?? ''));
        if ($who === '' || $who === 'u' . (int) Session::get('user_id')) { $this->jsonError('Pick someone else.'); }   // u<id> members, g<hash> guests
        if (!LiveKit::remove_participant($r['room'], $who)) { $this->jsonError('Could not remove them. Try again.'); }
        (new LiveRoomsModel())->admit($r['room'], $who);   // make sure there's a row, then turn it away:
        (new LiveRoomsModel())->deny_any($r['room'], $who);   // someone removed can't come straight back in
        $this->jsonSuccess(['message' => 'Removed from the call']);
    }

    /** Host: mute every microphone but their own. {kind, id} */
    public function live_mute_allAction(){
        $r = $this->host_room();
        $n = LiveKit::mute_all($r['room'], 'u' . (int) Session::get('user_id'));
        $this->jsonSuccess(['muted' => $n, 'message' => $n > 0 ? 'Everyone else is muted' : 'Nobody else was talking']);
    }

    /** Host: change a call setting now. {kind, id, key: waiting|share|watch|chat|locked, value} */
    public function live_settingsAction(){
        $r = $this->host_room();
        $key = (string) ($this->post['key'] ?? ''); $v = (string) ($this->post['value'] ?? '');
        $rooms = new LiveRoomsModel();
        $s = LiveControl::state($r['room'], (array) $r['defaults']);
        $f = array();
        switch ($key) {
            case 'waiting': $f['waiting'] = $v === '1' ? 1 : 0; break;
            case 'share':   $f['share'] = $v === 'everyone' ? 'everyone' : 'host'; break;
            case 'watch':   $f['watch'] = $v === '1' ? 1 : 0; if ($v !== '1') { $f['speakers'] = null; } break;
            case 'chat':    $f['chat'] = $v === '1' ? 1 : 0; break;
            case 'locked':  $f['locked'] = $v === '1' ? 1 : 0; break;
            default: $this->jsonError('Unknown setting.');
        }
        $rooms->set($r['room'], $f);
        $admitted = ($key === 'waiting' && $v !== '1') ? $rooms->admit_all($r['room']) : 0;   // no waiting room: everyone waiting comes in
        $s = LiveControl::state($r['room'], (array) $r['defaults']);
        if (!LiveControl::push($r['room'], $s)) { $this->jsonError('Could not reach the video server. Try again.'); }
        $this->jsonSuccess(['state' => json_decode(LiveControl::meta($s), true), 'admitted' => $admitted]);
    }

    /** Host: who is waiting to be let in. {kind, id} */
    public function live_waitingAction(){
        $r = $this->host_room();
        $list = array();
        foreach ((new LiveRoomsModel())->waiting($r['room']) as $w) {
            $list[] = array('identity' => (string) $w['identity'], 'name' => (string) $w['name'], 'since' => strtotime((string) $w['requested_at'] . ' UTC'));
        }
        $this->jsonSuccess(['waiting' => $list]);
    }

    /** Host: let one person in, or everyone waiting (all=1). {kind, id, identity | all} */
    public function live_admitAction(){
        $r = $this->host_room();
        $rooms = new LiveRoomsModel();
        if (!empty($this->post['all'])) { $n = $rooms->admit_all($r['room']); $this->jsonSuccess(['admitted' => $n]); }
        $who = $this->identity_param();
        $e = $rooms->entry($r['room'], $who);
        if (!$e || (string) $e['status'] !== 'waiting') { $this->jsonError('They’re no longer waiting.'); }
        $rooms->admit($r['room'], $who);
        $this->jsonSuccess(['admitted' => 1]);
    }

    /** Host: turn one person away. {kind, id, identity} */
    public function live_denyAction(){
        $r = $this->host_room();
        (new LiveRoomsModel())->deny($r['room'], $this->identity_param());
        $this->jsonSuccess(['message' => 'They won’t be let in']);
    }

    /**
     * Host: act on one person in the call. {kind, id, identity, action}
     * mute | camera_off | talk (watch-only: let them use mic and camera) | stop_talk | spotlight | unspotlight | lower_hand
     */
    public function live_personAction(){
        $r = $this->host_room();
        $who = $this->identity_param();
        $action = (string) ($this->post['action'] ?? '');
        $rooms = new LiveRoomsModel();
        $s = LiveControl::state($r['room'], (array) $r['defaults']);
        switch ($action) {
            case 'mute':       $ok = LiveKit::mute_source($r['room'], $who, 'MICROPHONE'); break;
            case 'camera_off': $ok = LiveKit::mute_source($r['room'], $who, 'CAMERA'); break;
            case 'talk':
            case 'stop_talk':
                $sp = array_values(array_diff($s['speakers'], array($who)));
                if ($action === 'talk') { $sp[] = $who; }
                $rooms->set($r['room'], array('speakers' => json_encode($sp)));
                $s = LiveControl::state($r['room'], (array) $r['defaults']);
                $ok = LiveControl::push($r['room'], $s, $who);
                if ($action === 'talk') { LiveKit::update_participant($r['room'], $who, null, array('hand' => '')); }   // their hand is answered
                break;
            case 'spotlight':
            case 'unspotlight':
                $rooms->set($r['room'], array('spotlight' => $action === 'spotlight' ? $who : null));
                $s = LiveControl::state($r['room'], (array) $r['defaults']);
                $ok = LiveKit::set_room_metadata($r['room'], LiveControl::meta($s));
                break;
            case 'lower_hand': $ok = LiveKit::update_participant($r['room'], $who, null, array('hand' => '')); break;
            default: $this->jsonError('Unknown action.');
        }
        if (!$ok) { $this->jsonError('Could not reach the video server. Try again.'); }
        $this->jsonSuccess(['state' => json_decode(LiveControl::meta($s), true)]);
    }

    /** Anyone in the call: raise or lower their own hand. {kind, id, up} */
    public function live_handAction(){
        $kind = (string) ($this->post['kind'] ?? ''); $id = (int) ($this->post['id'] ?? 0);
        $room = $kind === 'event' ? LiveKit::room_for_event($id) : ($kind === 'booking' ? LiveKit::room_for_booking($id) : '');
        if ($room === '') { $this->jsonError('This call is not available.'); }
        $me = LiveControl::identity((int) Session::get('user_id'), $room);
        $in = false;
        foreach ((array) LiveKit::participants($room) as $p) { if ((string) ($p['identity'] ?? '') === $me) { $in = true; break; } }
        if (!$in) { $this->jsonError('Join the call first.'); }
        $up = (string) ($this->post['up'] ?? '') === '1';
        if (!LiveKit::update_participant($room, $me, null, array('hand' => $up ? (string) time() : ''))) { $this->jsonError('Could not reach the video server. Try again.'); }
        $this->jsonSuccess(['up' => $up]);
    }

    /** A participant identity from the page: u<user id> or g<hash>. */
    private function identity_param(): string{
        $who = preg_replace('/[^a-z0-9]/i', '', (string) ($this->post['identity'] ?? ''));
        if ($who === '' || $who === 'u' . (int) Session::get('user_id')) { $this->jsonError('Pick someone else.'); }
        return $who;
    }

    private function host_room(): array{
        $r = LiveAccess::check((string) ($this->post['kind'] ?? ''), (int) ($this->post['id'] ?? 0), (int) Session::get('user_id'));
        if (!$r['ok'] || empty($r['host'])) { $this->jsonError('Only the host can do that.'); }
        return $r;
    }
}
