<?php
/**
 * Support / help desk API (routed from /api/<action> by ApiRoutes).
 * Any signed-in user can open a request and talk on their own requests; staff (is_admin) can
 * answer and close any request. New requests and user replies notify staff; staff replies
 * notify the user (in-platform + email, "system" category).
 */
class ApiSupportController extends BaseApiController {

    use AuditTrail;
    protected $audit_skip = array('support_create', 'support_assist', 'contact_send');   // assist only drafts text; nothing changes

    /** Only audit staff acting on someone else's request (a staff member's own request is not a staff action). */
    protected function audit_applies(string $action): bool {
        $t = (new SupportModel())->get((int) ($this->post['ticket_id'] ?? 0));
        return $t && (int) $t['user_id'] !== (int) Session::get('user_id');
    }

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

    /**
     * The public /contact form (signed in or not): emails support with reply-to = the sender.
     * 3 per IP per 15 minutes; a filled honeypot ("company") gets a fake success and sends nothing.
     */
    public function contact_sendAction(){
        $ok = 'Message sent. We will reply by email.';
        if ($this->text('company', 190) !== '') { $this->jsonSuccess(['message' => $ok]); }

        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'contact', 15) >= 3) {
            $this->jsonError('Too many messages. Please try again in a few minutes.');
        }
        $name    = $this->text('name', 100);
        $email   = $this->text('email', 190);
        $topic   = (string) ($this->post['topic'] ?? '');
        $message = $this->text('message', 5000);
        if ($name === '') { $this->jsonError('Add your name'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $this->jsonError('Add a valid email address'); }
        if (!isset(PagesController::CONTACT_TOPICS[$topic])) { $this->jsonError('Choose a topic'); }
        if (mb_strlen($message) < 10) { $this->jsonError('Write a few words about your question'); }

        $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $label = PagesController::CONTACT_TOPICS[$topic];
        $uid = (int) Session::get('user_id');
        $html = '<p><strong>Name:</strong> ' . $e($name) . '<br><strong>Email:</strong> ' . $e($email) . '<br><strong>Topic:</strong> ' . $e($label)
              . ($uid > 0 ? '<br><strong>Signed in as user:</strong> ' . $uid : '') . '<br><strong>IP:</strong> ' . $e($ip) . '</p>'
              . '<p>' . nl2br($e($message)) . '</p>';
        $sent = (new Notifications())->send_to_support(PagesController::LEGAL_CONTACT, $email, $name, 'Contact form: ' . $label . ' from ' . $name, $html);
        if (!$sent) { $this->jsonError('Could not send your message. Please email ' . PagesController::LEGAL_CONTACT . ' instead.'); }
        $this->loginAttemptsModel->record($ip, $email, 'contact');   // only a delivered message uses up one of the three
        $this->jsonSuccess(['message' => $ok]);
    }

    /** AI Assist (staff answering someone else's request): three reply options, or a rework of the typed reply. */
    public function support_assistAction(){
        $me = $this->me();
        if (!Permissions::is_admin()) { $this->jsonError('Not authorized'); }
        $model = new SupportModel();
        $t = $this->ticket_for($me, $model);
        if ((int) $t['user_id'] === $me) { $this->jsonError('AI Assist is for answering other people\'s requests'); }
        $am = new AdminModel(); $uid = (int) $t['user_id'];
        $u = $am->user_detail($uid);
        if (!$u) { $this->jsonError('Request not found'); }
        $purchases = $am->purchases_for($uid, 50);
        $memberships = array_values(array_filter((array) (new CreatorSubscriptionsModel())->get_for_subscriber($uid), function ($m) { return $m['status'] === 'active'; }));
        $fmt = function ($utc) { return (string) $utc === '' ? '' : gmdate('M j, Y', strtotime($utc . ' UTC')); };
        $checks = SupportDiagnosis::checks((string) $t['category'], $u, $am, $purchases, $memberships, $fmt);
        $ctx = SupportAssist::context($t, $model->messages((int) $t['id']), $u, $checks);
        $r = SupportAssist::run((string) ($this->post['mode'] ?? 'draft'), $ctx, $this->text('note', 500), $this->text('current', 5000));
        if (!$r['ok']) { $this->jsonError($r['error']); }
        $this->jsonSuccess(['options' => $r['options']]);
    }
}
