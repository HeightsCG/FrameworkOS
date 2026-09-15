<?php
/** Creator broadcasts to followers/subscribers. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiBroadcastController extends BaseApiController {

    /** Audience counts for the broadcast composer (creators only). */
    public function broadcast_infoAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (!Permissions::has_role('Creator')) { $this->jsonError('Creators only'); }
        $this->jsonSuccess(['counts' => (new BroadcastsModel())->counts($me)]);
    }

    /** Send a broadcast to a segment of the creator's audience. */
    public function broadcast_sendAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Sign in first.', ['need_login' => true]); }
        if (!Permissions::has_role('Creator')) { $this->jsonError('Only creators can broadcast.'); }
        $segment = (string) ($this->post['segment'] ?? 'all');
        if (!in_array($segment, BroadcastsModel::segments(), true)) { $segment = 'all'; }
        $body = trim(html_entity_decode((string) ($this->post['body'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($body === '') { $this->jsonError('Type a message.'); }
        if (mb_strlen($body) > 2000) { $body = mb_substr($body, 0, 2000); }
        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'broadcast', 60) >= 10) {
            $this->jsonError('You\'re broadcasting too often. Try again later.');
        }
        list($bid, $count) = (new BroadcastsModel())->create_and_send($me, $segment, $body);
        if ($count <= 0) { $this->jsonError('No one is in that audience yet.'); }
        $this->loginAttemptsModel->record($ip, (string) $me, 'broadcast');
        $this->jsonSuccess(['count' => $count]);
    }

}
