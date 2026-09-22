<link rel="stylesheet" href="/css/team.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/team.css'); ?>">
<?php
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$ini = function ($n) { $n = trim((string) $n); return $n === '' ? '?' : mb_strtoupper(mb_substr($n, 0, 1)); };
$tz  = (string) ($this->timezone ?? 'UTC');
$fmt = function ($utc) use ($tz) {
    if ((string) $utc === '') { return 'Never'; }
    try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz ?: 'UTC')); return $d->format('M j, Y g:i A'); }
    catch (\Throwable $x) { return '—'; }
};
$roles = array('manager' => 'Manager', 'editor' => 'Editor', 'viewer' => 'Viewer');
$owner = $this->owner;
$members = $this->members;
$used = (int) $this->seats_used;
$limit = (int) $this->seat_limit;                 // 0 = unlimited
$unlimited = ($limit === 0);
$full = (!$unlimited && $used >= $limit);
$owner_name = trim(($owner['first_name'] ?? '') . ' ' . ($owner['last_name'] ?? ''));
$owner_name = $owner_name !== '' ? $owner_name : ('@' . ($owner['u_name'] ?? ''));
?>
<div class="team">
    <header class="team__head">
        <h1 class="team__title">Team</h1>
        <p class="team__sub">Invite collaborators to work inside your account. They sign in with their own login and act on your behalf, limited by their role.</p>
    </header>

    <div class="team__bar">
        <div class="team__seats">
            <div class="team__seats-meter"><div class="team__seats-fill" style="width:<?php echo $unlimited ? 12 : min(100, round($used / max(1, $limit) * 100)); ?>%"></div></div>
            <span class="team__seats-label"><b><?php echo $used; ?></b><?php echo $unlimited ? ' seats used · unlimited' : (' of ' . $limit . ' seat' . ($limit === 1 ? '' : 's') . ' used'); ?></span>
        </div>
        <button type="button" class="team-btn team-btn--primary" id="teamInviteBtn"<?php echo $full ? ' disabled title="All seats in use — upgrade your plan for more."' : ''; ?>><i class="fa-solid fa-user-plus"></i> Invite Member</button>
    </div>

    <?php if ($full): ?>
    <p class="team__note"><i class="fa-solid fa-circle-info"></i> You're using every seat on your plan. <a href="/account/billing">Upgrade</a> to add more collaborators.</p>
    <?php endif; ?>

    <div class="team-table">
        <div class="team-table__head"><span>Member</span><span>Role</span><span>Status</span><span>Last active</span><span></span></div>
        <div class="team-table__body" id="teamBody">
            <div class="team-row team-row--owner">
                <div class="team-cell team-cell--member">
                    <span class="team-av team-av--owner"><?php echo $e($ini($owner_name)); ?></span>
                    <span class="team-info"><span class="team-info__name"><?php echo $e($owner_name); ?> <span class="team-tag team-tag--owner">Owner</span></span><span class="team-info__meta">@<?php echo $e($owner['u_name'] ?? ''); ?> &middot; <?php echo $e($owner['user_email'] ?? ''); ?></span></span>
                </div>
                <div class="team-cell team-cell--muted">Full access</div>
                <div class="team-cell"><span class="team-status team-status--on"><span class="team-status__dot"></span>Active</span></div>
                <div class="team-cell team-cell--muted">You</div>
                <div class="team-cell team-cell--act">—</div>
            </div>
            <?php foreach ($members as $m):
                $mname = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                $mname = $mname !== '' ? $mname : ('@' . $m['u_name']);
                $disabled = ($m['user_status'] === 'Disabled');
                $pending  = ((int) ($m['reset_pw'] ?? 0) === 1 && empty($m['last_active_at']));
            ?>
            <div class="team-row" data-mid="<?php echo (int) $m['user_id']; ?>">
                <div class="team-cell team-cell--member">
                    <span class="team-av"><?php echo $e($ini($mname)); ?></span>
                    <span class="team-info"><span class="team-info__name"><?php echo $e($mname); ?></span><span class="team-info__meta">@<?php echo $e($m['u_name']); ?> &middot; <?php echo $e($m['user_email']); ?></span></span>
                </div>
                <div class="team-cell">
                    <select class="team-role" data-role>
                        <?php foreach ($roles as $rk => $rv): ?>
                        <option value="<?php echo $rk; ?>"<?php echo ($m['team_role'] === $rk ? ' selected' : ''); ?>><?php echo $rv; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="team-cell" data-status-cell>
                    <?php if ($pending): ?>
                        <span class="team-status team-status--pending"><span class="team-status__dot"></span>Invite pending</span>
                    <?php elseif ($disabled): ?>
                        <span class="team-status team-status--off"><span class="team-status__dot"></span>Suspended</span>
                    <?php else: ?>
                        <span class="team-status team-status--on"><span class="team-status__dot"></span>Active</span>
                    <?php endif; ?>
                </div>
                <div class="team-cell team-cell--muted"><?php echo $e($fmt($m['last_active_at'])); ?></div>
                <div class="team-cell team-cell--act" data-act-cell>
                    <?php if ($disabled): ?>
                        <button type="button" class="team-btn team-btn--sm" data-status="Active">Reactivate</button>
                    <?php else: ?>
                        <button type="button" class="team-btn team-btn--sm" data-status="Disabled">Suspend</button>
                    <?php endif; ?>
                    <button type="button" class="team-btn team-btn--sm team-btn--danger" data-remove>Remove</button>
                </div>
            </div>
            <?php endforeach; ?>
            <p class="team__empty" id="teamEmpty"<?php echo count($members) ? ' hidden' : ''; ?>>No collaborators yet. Invite someone to help run your account.</p>
        </div>
    </div>
</div>

<script src="/js/team.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/team.js'); ?>"></script>
