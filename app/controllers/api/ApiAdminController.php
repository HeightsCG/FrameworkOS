<?php
/** Admin moderation, refunds, user status, reports and creator verification. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiAdminController extends BaseApiController {

    use AuditTrail;
    /** User-facing actions in this controller: not staff actions, so not audited. */
    protected $audit_skip = array('report_submit', 'verification_request');

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
        $this->jsonSuccess(['status' => $status, 'message' => $status === 'Disabled' ? 'Account suspended' : 'Account reactivated']);
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
        if (!in_array($kind, ['ppv', 'bundle', 'message'], true) || $ref_id <= 0 || $fan_id <= 0) {
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

    /* ---------- Admin > user page: tools to fix a user's problem ---------- */

    /** The target user row (any status), or a JSON error. */
    private function target_user(): array{
        $uid = (int) ($this->post['user_id'] ?? 0);
        $u = $uid > 0 ? (new AdminModel())->user_detail($uid) : null;
        if (!$u) { $this->jsonError('User not found'); }
        return $u;
    }

    /** Add or remove wallet credits or AI credits, with a reason the user sees in their history. */
    public function admin_adjust_creditsAction(){
        $this->admin_guard();
        $u = $this->target_user();
        $wallet  = (string) ($this->post['wallet'] ?? 'credits');
        $amount  = (int) ($this->post['amount'] ?? 0);
        $reason  = trim(html_entity_decode((string) ($this->post['reason'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($amount === 0 || abs($amount) > 100000) { $this->jsonError('Enter an amount between -100,000 and 100,000'); }
        if ($reason === '') { $this->jsonError('Add a reason'); }
        $desc = mb_substr('Adjustment by support: ' . $reason, 0, 255);
        if ($wallet === 'ai') {
            $bal = (new AiCreditsModel())->apply_delta((int) $u['user_id'], $amount, 'admin_adjust', $desc);
        } else {
            $bal = (new CreditsModel())->apply_delta((int) $u['user_id'], $amount, 'admin_adjust', $desc);
        }
        if ($bal === false) { $this->jsonError('That would take the balance below zero'); }
        $label = $wallet === 'ai' ? 'AI credits' : 'credits';
        Notify::send((int) $u['user_id'], 'credits', ($amount > 0 ? number_format($amount) . ' ' . $label . ' added' : number_format(abs($amount)) . ' ' . $label . ' removed') . ' by support', $reason, '/account/settings?section=wallet', 'fa-coins');
        $this->jsonSuccess(['balance' => (int) $bal, 'message' => 'Balance updated']);
    }

    /** Email the user a password reset link (same link and email as "Forgot password"). */
    public function admin_send_password_resetAction(){
        $this->admin_guard();
        $u = $this->target_user();
        if ((string) $u['user_email'] === '') { $this->jsonError('This account has no email address'); }
        $token = $this->userModel->set_reset_token((int) $u['user_id']);
        $link  = Main::get_base_domain() . '/account/reset?token=' . urlencode($token);
        $this->notificationsModel->send_password_reset_email($u['user_email'], trim($u['first_name'] . ' ' . $u['last_name']), $link);
        $this->jsonSuccess(['message' => 'Password reset email sent to ' . $u['user_email']]);
    }

    /** Turn off every two-step sign-in method so a locked-out user can sign in with their password. */
    public function admin_reset_mfaAction(){
        $this->admin_guard();
        $u = $this->target_user();
        (new AdminModel())->reset_mfa((int) $u['user_id']);
        Notify::send((int) $u['user_id'], 'security', 'Two-step sign-in was turned off by support', 'Turn it back on in Settings, then Security.', '/account/settings?section=security', 'fa-shield-halved', false, true);
        $this->jsonSuccess(['message' => 'Two-step sign-in reset']);
    }

    /** Turn email sign-in codes on or off. */
    public function admin_set_mfa_emailAction(){
        $this->admin_guard();
        $u = $this->target_user();
        $on = (string) ($this->post['enabled'] ?? '0') === '1';
        (new MfaModel())->set_email_enabled((int) $u['user_id'], $on);
        Notify::send((int) $u['user_id'], 'security', 'Email sign-in codes turned ' . ($on ? 'on' : 'off') . ' by support', '', '/account/settings?section=security', 'fa-shield-halved', false, true);
        $this->jsonSuccess(['message' => 'Email codes turned ' . ($on ? 'on' : 'off')]);
    }

    /** Mark the email address as confirmed (user can't get the verification email). */
    public function admin_verify_emailAction(){
        $this->admin_guard();
        $u = $this->target_user();
        $this->userModel->mark_email_verified((int) $u['user_id']);
        $this->jsonSuccess(['message' => 'Email marked as verified']);
    }

    /** Send a fresh email verification link. */
    public function admin_resend_verificationAction(){
        $this->admin_guard();
        $u = $this->target_user();
        if ((string) $u['user_email'] === '') { $this->jsonError('This account has no email address'); }
        $token = $this->userModel->set_email_verify_token((int) $u['user_id']);
        $this->notificationsModel->send_verification_email($u['user_email'], trim($u['first_name'] . ' ' . $u['last_name']), Main::get_base_domain() . '/account/verify?token=' . urlencode($token), $u['u_name']);
        $this->jsonSuccess(['message' => 'Verification email sent to ' . $u['user_email']]);
    }

    /** Cancel a fan's membership: paid ones end at the end of the paid period, free ones end now. */
    public function admin_cancel_membershipAction(){
        $this->admin_guard();
        $model = new AdminModel();
        $sub = $model->membership((int) ($this->post['membership_id'] ?? 0));
        if (!$sub || $sub['status'] !== 'active') { $this->jsonError('Membership not found or not active'); }
        $subs = new CreatorSubscriptionsModel();
        if (!empty($sub['is_free'])) {
            $subs->set_status((int) $sub['subscriber_id'], (int) $sub['id'], 'canceled');
            $this->jsonSuccess(['message' => 'Membership canceled']);
        }
        $connect = (string) ($sub['creator_connect'] ?? '');
        if ($connect === '' || empty($sub['stripe_subscription_id'])
            || !StripeService::set_subscription_cancel_at_period_end($connect, $sub['stripe_subscription_id'], true)) {
            $this->jsonError('Could not cancel the membership. Please try again.');
        }
        $subs->set_cancel_at_period_end((int) $sub['subscriber_id'], (int) $sub['id'], true);
        $this->jsonSuccess(['message' => 'Membership will end at the end of the paid period']);
    }

    /** Cancel or resume a creator's own plan at the end of the current period. */
    public function admin_set_plan_cancelAction(){
        $this->admin_guard();
        $u = $this->target_user();
        $cancel = (string) ($this->post['cancel'] ?? '1') === '1';
        $sub_id = (string) ($u['stripe_subscription_id'] ?? '');
        if ($sub_id === '') { $this->jsonError('This account has no plan'); }
        try {
            $subscription = StripeService::client()->subscriptions->update($sub_id, ['cancel_at_period_end' => $cancel]);
            $price_id   = $subscription->items->data[0]->price->id ?? ($u['stripe_price_id'] ?? '');
            $period_end = $subscription->items->data[0]->current_period_end ?? null;
            $this->billingModel->save_subscription((int) $u['user_id'], $subscription->id, $price_id, $subscription->status, $period_end, $subscription->cancel_at_period_end ? 1 : 0);
        } catch (\Throwable $e) {
            error_log('[admin] plan cancel: ' . $e->getMessage());
            $this->jsonError('Could not update the plan. Please try again.');
        }
        $this->jsonSuccess(['message' => $cancel ? 'Plan will cancel at the end of the period' : 'Plan resumed']);
    }

    private function admin_guard(): void{
        if ((int) Session::get('user_id') <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (!Permissions::is_admin()) { $this->jsonError('Admins only'); }
    }

}
