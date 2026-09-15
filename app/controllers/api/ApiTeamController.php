<?php
/** Team seats / collaborators. Routed from /api/<action> by ApiRoutes; extends BaseApiController. */
class ApiTeamController extends BaseApiController {

    /** Invite a collaborator: creates their login on the owner's account and emails a set-password link. */
    public function team_inviteAction(){
        $owner_id = $this->team_owner_guard();
        $name  = trim(html_entity_decode((string) ($this->post['name'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $email = trim(strtolower(html_entity_decode((string) ($this->post['email'] ?? ''), ENT_QUOTES, 'UTF-8')));
        $role  = (string) ($this->post['role'] ?? 'viewer');
        if (!in_array($role, TeamModel::roles(), true)) { $role = 'viewer'; }
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->jsonError('Enter a name and a valid email.');
        }
        $rows  = $this->userModel->get_user_by_id($owner_id);
        $owner = (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
        $team  = new TeamModel();
        $limit = Plan::limit($owner, 'seats'); $limit = ($limit === null) ? 1 : (int) $limit;
        if ($limit > 0 && $team->seats_used($owner_id) >= $limit) {
            $this->jsonError('You\'ve used all ' . $limit . ' seat' . ($limit === 1 ? '' : 's') . ' on your plan. Upgrade for more.', ['need_upgrade' => true]);
        }
        $exists = $this->userModel->get_user_by_login($email);
        if (is_array($exists) && count($exists) >= 1) {
            $this->jsonError('An account with that email already exists.');
        }
        $parts = preg_split('/\s+/', $name, 2);
        $first = $parts[0]; $last = isset($parts[1]) ? $parts[1] : '';
        $base  = preg_replace('/[^a-z0-9]/', '', strtolower(explode('@', $email)[0]));
        if ($base === '') { $base = 'member'; }
        $u_name = $base; $i = 0;
        while (($u = $this->userModel->get_user_by_login($u_name)) && is_array($u) && count($u) >= 1) { $i++; $u_name = $base . $i; }
        $enc = password_hash(bin2hex(random_bytes(18)), PASSWORD_DEFAULT);
        $member_id = (int) $this->userModel->create_user($u_name, $enc, $first, $last, $email, $owner_id, $owner_id);
        if ($member_id <= 0) { $this->jsonError('Could not create the account.'); }
        $team->mark_as_member($member_id, $role);

        $token = $this->userModel->set_reset_token($member_id);
        $link  = Main::get_base_domain() . '/account/reset?token=' . urlencode($token);
        if (!empty($email)) { $this->notificationsModel->send_password_reset_email($email, $name, $link); }

        $this->jsonSuccess(['member' => ['user_id' => $member_id, 'name' => $name, 'email' => $email, 'handle' => $u_name, 'role' => $role], 'invite_link' => $link]);
    }

    public function team_set_roleAction(){
        $owner_id = $this->team_owner_guard();
        $mid  = (int) ($this->post['member_id'] ?? 0);
        $role = (string) ($this->post['role'] ?? '');
        if (!(new TeamModel())->set_role($owner_id, $mid, $role)) { $this->jsonError('Could not update role'); }
        $this->jsonSuccess(['role' => $role]);
    }

    public function team_set_statusAction(){
        $owner_id = $this->team_owner_guard();
        $mid    = (int) ($this->post['member_id'] ?? 0);
        $status = (string) ($this->post['status'] ?? '');
        if (!(new TeamModel())->set_status($owner_id, $mid, $status)) { $this->jsonError('Could not update'); }
        $this->jsonSuccess(['status' => $status]);
    }

    public function team_removeAction(){
        $owner_id = $this->team_owner_guard();
        $mid = (int) ($this->post['member_id'] ?? 0);
        if (!(new TeamModel())->remove($owner_id, $mid)) { $this->jsonError('Could not remove member'); }
        $this->jsonSuccess();
    }

    /* ---------- Events (PRD §23) ---------- */

    private function team_owner_guard(): array{
        $me = (int) Session::get('user_id');
        if ($me <= 0) { echo json_encode(['success' => false, 'need_login' => true]); exit; }
        if (!Permissions::is_owner_creator()) { $this->jsonError('Only the account owner can manage the team.'); }
        return $me;
    }

}
