<?php
/** Creator broadcasts to audience segments, with optional media and a price. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiBroadcastController extends BaseApiController {

    /** Audience counts for the broadcast composer (creators only). */
    public function broadcast_infoAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (!Permissions::has_role('Creator')) { $this->jsonError('Creators only'); }
        $this->jsonSuccess(['counts' => (new BroadcastsModel())->counts($me), 'labels' => BroadcastsModel::segment_labels()]);
    }

    /** Send a broadcast to one or more segments of the creator's audience. */
    public function broadcast_sendAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Sign in first.', ['need_login' => true]); }
        if (!Permissions::has_role('Creator')) { $this->jsonError('Only creators can broadcast.'); }
        $segments = BroadcastsModel::clean_segments($this->post['segments'] ?? ($this->post['segment'] ?? 'all'));
        if (empty($segments)) { $this->jsonError('Pick who receives it.'); }
        $body = trim(html_entity_decode((string) ($this->post['body'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if (mb_strlen($body) > 2000) { $body = mb_substr($body, 0, 2000); }
        $asset_ids = []; $price = 0;
        $wanted = array_slice(array_map('intval', (array) ($this->post['asset_ids'] ?? [])), 0, 10);
        if (!empty($wanted)) {
            foreach ((new MediaAssetsModel())->get_owned_ready($me, $wanted) as $a) { $asset_ids[] = (int) $a['id']; }
            if (empty($asset_ids)) { $this->jsonError('Those files are not ready to send.'); }
            if ((int) ($this->post['price'] ?? 0) > 0) { $price = $this->ppv_credits_from_dollars($this->post['price']); }
        }
        if ($body === '' && empty($asset_ids)) { $this->jsonError('Type a message.'); }
        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'broadcast', 60) >= 10) {
            $this->jsonError('You\'re broadcasting too often. Try again later.');
        }
        list($bid, $count, $queued) = (new BroadcastsModel())->create_and_send($me, $segments, $body, $asset_ids, $price);
        if ($count <= 0) { $this->jsonError('No one is in that audience yet.'); }
        $this->loginAttemptsModel->record($ip, (string) $me, 'broadcast');
        $this->jsonSuccess(['count' => $count, 'queued' => $queued,
            'message' => ($queued ? 'Sending to ' : 'Sent to ') . $count . ' ' . ($count === 1 ? 'person' : 'people')]);
    }

}
