<?php
/** In-platform notification bell. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiNotificationsController extends BaseApiController {

    public function notifications_listAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        $model = new UserNotificationsModel();
        $out = [];
        foreach ($model->recent($me, 15) as $r) {
            $out[] = ['id' => (int) $r['id'], 'category' => (string) $r['category'], 'icon' => (string) $r['icon'],
                'title' => (string) $r['title'], 'body' => (string) $r['body'], 'link' => (string) $r['link'],
                'read' => ((int) $r['is_read'] === 1), 'created_at' => (string) $r['created_at']];
        }
        $this->jsonSuccess(['notifications' => $out, 'unread' => $model->unread_count($me)]);
    }

    public function notifications_unread_countAction(){
        $me = (int) Session::get('user_id');
        $this->jsonSuccess(['count' => $me > 0 ? (new UserNotificationsModel())->unread_count($me) : 0]);
    }

    public function notifications_mark_readAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false]); exit; }
        $model = new UserNotificationsModel();
        $id = (int) ($this->post['id'] ?? 0);
        if ($id > 0) { $model->mark_read($me, $id); } else { $model->mark_all_read($me); }
        $this->jsonSuccess();
    }

    /* ---------- Platform admin (PRD §38) ---------- */

}
