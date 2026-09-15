<?php
/** Admin moderation, refunds, user status, reports and creator verification. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiAdminController extends BaseApiController {

    /** Suspend or reactivate a user account. */
    public function admin_set_user_statusAction(){
        $this->admin_guard();
        $me     = (int) Session::get('user_id');
        $uid    = (int) ($this->post['user_id'] ?? 0);
        $status = (string) ($this->post['status'] ?? '');
        if ($uid <= 0 || !in_array($status, ['Active', 'Disabled'], true)) {
            $this->jsonError('Invalid request');
        }
        if ($uid === $me) { $this->jsonError('You cannot suspend your own account.'); }
        $rows = $this->userModel->get_user_by_id($uid);
        $u    = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        if (!$u) { $this->jsonError('User not found'); }
        if ($status === 'Disabled' && !empty($u['is_admin'])) {
            $this->jsonError('You cannot suspend another admin.');
        }
        (new AdminModel())->set_user_status($uid, $status);
        $this->jsonSuccess(['status' => $status]);
    }

    /** Approve or block a piece of content in the moderation queue. */
    public function admin_moderateAction(){
        $this->admin_guard();
        $asset_id = (int) ($this->post['asset_id'] ?? 0);
        $action   = (string) ($this->post['action'] ?? '');
        $map = ['approve' => 'approved', 'block' => 'blocked'];
        if ($asset_id <= 0 || !isset($map[$action])) {
            $this->jsonError('Invalid request');
        }
        (new AdminModel())->set_moderation($asset_id, $map[$action]);
        $this->jsonSuccess();
    }

    /** Refund a PPV or bundle purchase (reverses credits both ways + revokes access). */
    public function admin_refundAction(){
        $this->admin_guard();
        $me     = (int) Session::get('user_id');
        $kind   = (string) ($this->post['kind'] ?? '');
        $ref_id = (int) ($this->post['ref_id'] ?? 0);
        $fan_id = (int) ($this->post['fan_id'] ?? 0);
        $reason = trim(html_entity_decode((string) ($this->post['reason'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if (!in_array($kind, ['ppv', 'bundle'], true) || $ref_id <= 0 || $fan_id <= 0) {
            $this->jsonError('Invalid request');
        }
        $res = (new RefundsModel())->refund($kind, $ref_id, $fan_id, $me, $reason);
        if (empty($res['ok'])) { $this->jsonError((string) ($res['message'] ?? 'Refund failed')); }
        $this->jsonSuccess(['amount' => $res['amount'], 'clawback_ok' => $res['clawback_ok']]);
    }

    /* ---------- Team / seats (PRD §40) ---------- */

    /** Anyone signed in can report a post or a creator. */
    public function report_submitAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { $this->jsonError('Sign in to report.', ['need_login' => true]); }
        $type      = (string) ($this->post['target_type'] ?? '');
        $target_id = (int) ($this->post['target_id'] ?? 0);
        $reason    = (string) ($this->post['reason'] ?? '');
        $details   = trim(html_entity_decode((string) ($this->post['details'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $ip = $this->get_ip_address();
        if ($this->loginAttemptsModel->count_recent($ip, 'report', 10) >= 15) {
            $this->jsonError('You\'re reporting too fast. Try again shortly.');
        }
        $res = (new ReportsModel())->submit($me, $type, $target_id, $reason, $details);
        if (empty($res['ok'])) { $this->jsonError((string) ($res['message'])); }
        $this->loginAttemptsModel->record($ip, (string) $me, 'report');
        $this->jsonSuccess(['message' => $res['message']]);
    }

    /** Admin resolves a report: dismiss, remove the content, or suspend the account. */
    public function report_resolveAction(){
        $this->admin_guard();
        $me     = (int) Session::get('user_id');
        $rid    = (int) ($this->post['report_id'] ?? 0);
        $action = (string) ($this->post['action'] ?? '');
        $model  = new ReportsModel();
        $rep    = $model->get($rid);
        if (!$rep) { $this->jsonError('Report not found'); }

        if ($action === 'dismiss') {
            $model->resolve($rid, $me, 'dismissed', 'Dismissed');
        } elseif ($action === 'remove') {
            if ((string) $rep['target_type'] !== 'post') { $this->jsonError('Remove applies to content only'); }
            $model->takedown_post((int) $rep['target_id']);
            $model->resolve($rid, $me, 'actioned', 'Content removed');
        } elseif ($action === 'suspend') {
            $cid = (int) $rep['creator_id'];
            if ($cid > 0 && $cid !== $me) {
                $u = $this->userModel->get_user_by_id($cid);
                $u = (is_array($u) && count($u) === 1) ? $u[0] : null;
                if ($u && empty($u['is_admin'])) { (new AdminModel())->set_user_status($cid, 'Disabled'); }
            }
            $model->resolve($rid, $me, 'actioned', 'Account suspended');
        } else {
            $this->jsonError('Invalid action');
        }
        $this->jsonSuccess(['action' => $action]);
    }

    /* ---------- Creator verification (PRD §33) ---------- */

    /** A creator requests verification (a legal name + optional note the admin reviews). */
    public function verification_requestAction(){
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (!Permissions::is_owner_creator()) { $this->jsonError('Only creators can request verification.'); }
        $rows = $this->userModel->get_user_by_id($me);
        if (is_array($rows) && count($rows) === 1 && !empty($rows[0]['verified'])) {
            $this->jsonSuccess(['status' => 'approved', 'message' => 'You\'re already verified.']);
        }
        $full_name = trim(html_entity_decode((string) ($this->post['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $note      = trim(html_entity_decode((string) ($this->post['note'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($full_name === '') { $this->jsonError('Enter your legal name.'); }
        (new VerificationsModel())->request($me, $full_name, $note);
        $this->jsonSuccess(['status' => 'pending', 'message' => 'Verification requested — we\'ll review it shortly.']);
    }

    /** Admin approves or rejects a verification request. */
    public function verification_resolveAction(){
        $this->admin_guard();
        $me     = (int) Session::get('user_id');
        $vid    = (int) ($this->post['verification_id'] ?? 0);
        $action = (string) ($this->post['action'] ?? '');
        if (!in_array($action, ['approve', 'reject'], true)) { $this->jsonError('Invalid action'); }
        $vmodel = new VerificationsModel();
        $v = $vmodel->get($vid);
        if (!$v || !$vmodel->resolve($vid, $me, $action === 'approve')) {
            $this->jsonError('Request not found');
        }
        $this->notify((int) $v['user_id'], 'system',
            $action === 'approve' ? 'You\'re verified' : 'Verification update',
            $action === 'approve' ? 'Your account is now verified — the badge shows on your profile.' : 'Your verification request wasn\'t approved. You can re-apply anytime.',
            '/account/settings', $action === 'approve' ? 'fa-circle-check' : 'fa-shield-halved');
        $this->jsonSuccess(['action' => $action]);
    }

    /* ---------- Blocked accounts ---------- */

    private function admin_guard(): void{
        if ((int) Session::get('user_id') <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (!Permissions::is_admin()) { $this->jsonError('Admins only'); }
    }

}
