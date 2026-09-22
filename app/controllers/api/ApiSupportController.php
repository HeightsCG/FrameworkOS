<?php
/**
 * Support / help desk API (routed from /api/<action> by ApiRoutes).
 * Any signed-in user can open a request and talk on their own requests; staff (is_admin) can
 * answer and close any request. New requests and user replies notify staff; staff replies
 * notify the user (in-platform + email, "system" category).
 */
class ApiSupportController extends BaseApiController {

    /** POST text arrives html-encoded by clean_post_data(); store it decoded (views escape on output). */
    private function text($key, $max){
        $v = trim(html_entity_decode((string) ($this->post[$key] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return mb_substr($v, 0, $max);
    }

    private function me(){
        $id = (int) Session::get('user_id');
        if ($id <= 0) { $this->jsonError('Not authorized'); }
        return $id;
    }

    /** The ticket, if the caller owns it or is staff; otherwise a JSON error. */
    private function ticket_for($me, SupportModel $model){
        $t = $model->get((int) ($this->post['ticket_id'] ?? 0));
        if (!$t || ((int) $t['user_id'] !== $me && !Permissions::is_admin())) { $this->jsonError('Request not found'); }
        return $t;
    }

    public function support_createAction(){
        $me = $this->me();
        $model    = new SupportModel();
        $category = (string) ($this->post['category'] ?? '');
        $subject  = $this->text('subject', 190);
        $body     = $this->text('body', 5000);
        if (!isset(SupportModel::CATEGORIES[$category])) { $this->jsonError('Choose a topic'); }
        if ($subject === '') { $this->jsonError('Add a subject'); }
        if (mb_strlen($body) < 10) { $this->jsonError('Describe the problem in a few words'); }
        if ($model->recent_count($me) >= 5) { $this->jsonError('You have opened several requests in the last hour. Add to an existing one instead.'); }

        $id = $model->create($me, $category, $subject, $body);
        if ($id <= 0) { $this->jsonError('Could not send your request'); }
        foreach ($model->staff_ids() as $sid) {
            if ($sid === $me) { continue; }
            Notify::send($sid, 'system', 'New support request: ' . $subject, SupportModel::CATEGORIES[$category], '/support/ticket/' . $id, 'fa-life-ring');
        }
        $this->jsonSuccess(['ticket_id' => $id, 'message' => 'Request sent. We will reply here and by email.']);
    }

    public function support_replyAction(){
        $me = $this->me();
        $model = new SupportModel();
        $t = $this->ticket_for($me, $model);
        $body = $this->text('body', 5000);
        if ($body === '') { $this->jsonError('Write a reply first'); }

        $staff = Permissions::is_admin() && (int) $t['user_id'] !== $me;
        $model->add_message((int) $t['id'], $me, $staff, $body);
        if ($staff) {
            Notify::send((int) $t['user_id'], 'system', 'Support replied: ' . $t['subject'], mb_substr($body, 0, 140), '/support/ticket/' . (int) $t['id'], 'fa-life-ring');
        } else {
            foreach ($model->staff_ids() as $sid) {
                if ($sid === $me) { continue; }
                Notify::send($sid, 'system', 'Reply on support request: ' . $t['subject'], mb_substr($body, 0, 140), '/support/ticket/' . (int) $t['id'], 'fa-life-ring');
            }
        }
        $this->jsonSuccess(['message' => 'Reply sent']);
    }

    public function support_closeAction(){
        $me = $this->me();
        $model = new SupportModel();
        $t = $this->ticket_for($me, $model);
        $close = (string) ($this->post['closed'] ?? '1') === '1';
        $model->set_closed((int) $t['id'], $close);
        $this->jsonSuccess(['message' => $close ? 'Request closed' : 'Request reopened']);
    }
}
