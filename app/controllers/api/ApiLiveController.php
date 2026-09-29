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
        if (!empty($r['guest'])) {
            $name = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode((string) ($this->post['name'] ?? ''), ENT_QUOTES, 'UTF-8'))));
            if ($name === '') { $this->jsonError('Enter your name.', ['need_name' => true]); }
            if ($this->loginAttemptsModel->count_recent($ip, 'guestjoin', 10) >= 30) { $this->jsonError('Too many joins from this connection. Wait a few minutes and try again.'); }
            $this->loginAttemptsModel->record($ip, 'guest', 'guestjoin');
            // One identity per browser session, so a guest who rejoins replaces their old seat instead of doubling up.
            $identity = 'g' . substr(hash('sha256', session_id() . '|' . $r['room']), 0, 16);
            $name = mb_substr($name, 0, 40);
        } else {
            $identity = 'u' . $me;
            $name = Notify::name_of($me) ?: 'Guest';
        }
        $this->jsonSuccess([
            'url'   => LiveKit::url(),
            'token' => LiveKit::token($r['room'], $identity, $name, (bool) $r['host'], $me > 0 ? ['uid' => $me] : ['guest' => true], $me > 0),   // guests: no screen sharing
            'host'  => (bool) $r['host'],
            'title' => $r['title'],
            'me'    => $identity,
        ]);
    }

    /** Host: take one person out of the call. {kind, id, identity} */
    public function live_removeAction(){
        $r = $this->host_room();
        $who = preg_replace('/[^a-z0-9]/i', '', (string) ($this->post['identity'] ?? ''));
        if ($who === '' || $who === 'u' . (int) Session::get('user_id')) { $this->jsonError('Pick someone else.'); }   // u<id> members, g<hash> guests
        if (!LiveKit::remove_participant($r['room'], $who)) { $this->jsonError('Could not remove them. Try again.'); }
        $this->jsonSuccess(['message' => 'Removed from the call']);
    }

    /** Host: mute every microphone but their own. {kind, id} */
    public function live_mute_allAction(){
        $r = $this->host_room();
        $n = LiveKit::mute_all($r['room'], 'u' . (int) Session::get('user_id'));
        $this->jsonSuccess(['muted' => $n, 'message' => $n > 0 ? 'Everyone else is muted' : 'Nobody else was talking']);
    }

    private function host_room(): array{
        $r = LiveAccess::check((string) ($this->post['kind'] ?? ''), (int) ($this->post['id'] ?? 0), (int) Session::get('user_id'));
        if (!$r['ok'] || empty($r['host'])) { $this->jsonError('Only the host can do that.'); }
        return $r;
    }
}
