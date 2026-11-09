<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin/user/<id>: one account on a single scroll: summary header, then sections. Actions: public/js/admin-user.js → ApiAdminController. */
$fmtT = function ($utc) use ($fmt) { return $fmt($utc, true); };
$u = $this->u;
$name = trim((string) $u['first_name'] . ' ' . (string) $u['last_name']);
$disabled = ((string) $u['user_status'] === 'Disabled');
$deleted = !empty($u['deleted']);
$is_creator = in_array(strtolower((string) ($u['role_name'] ?? '')), array('creator'), true);
$tier = Plan::tier_name($u);
if ($tier === '' && $is_creator) { $tier = PlanTiers::get(PlanTiers::FREE_KEY)['name']; }   // tier_name() is '' for anyone without a paid plan, so a Creator with no paid plan read as "None / Fan account"
$sup_status = array('open' => 'Waiting on us', 'answered' => 'Replied', 'closed' => 'Closed');
$onoff = function ($b) { return $b ? 'On' : '<span class="adm-t__muted">Off</span>'; };
$open_req = count(array_filter($this->tickets, function ($t) { return $t['status'] !== 'closed'; }));
$bacct = BillingService::account((int) $u['user_id']);
$acq = implode(' / ', array_filter(array((string) ($u['acq_source'] ?? ''), (string) ($u['acq_medium'] ?? ''), (string) ($u['acq_campaign'] ?? ''))));
if ($acq === '' && !empty($u['acq_referrer'])) { $acq = (string) parse_url($u['acq_referrer'], PHP_URL_HOST); }
if ($acq === '' && !empty($u['acq_gclid'])) { $acq = 'Google Ads'; }
$m2 = array(); if (!empty($u['mfa_totp_enabled'])) { $m2[] = 'Authenticator app'; } if (!empty($u['mfa_email_enabled'])) { $m2[] = 'Email codes'; }
?>
<div id="admUser" data-admin-user="<?php echo (int) $u['user_id']; ?>">
<a class="adm-back" href="/admin/users"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Users</a>
<header class="adm-head adm-head--user">
    <div class="adm-head__who">
        <?php echo adm_avatar($name !== '' ? $name : $u['u_name'], (string) ($u['avatar_url'] ?? ''), 48); ?>
        <div>
            <h1 class="adm-head__title"><?php echo $e($name !== '' ? $name : '@' . $u['u_name']); ?></h1>
            <p class="adm-head__sub">@<?php echo $e($u['u_name']); ?> · <?php echo $e($u['user_email']); ?> · User <?php echo (int) $u['user_id']; ?>
                <?php echo adm_pill($u['role_name'] ?: 'User', 'gray'); ?>
                <?php if ($deleted): echo adm_pill('Deleted'); elseif ($disabled): echo adm_pill('Suspended'); else: echo adm_pill('Active'); endif; ?>
                <?php if (!empty($u['is_admin'])): echo adm_pill('Staff', 'acc'); endif; ?>
                <?php if (!empty($u['is_demo'])): echo adm_pill('Demo'); endif; ?>
                <?php if (!empty($u['verified'])): echo adm_pill('Verified', 'ok'); endif; ?>
                <?php if (empty($u['email_verified'])): echo adm_pill('Email not verified', 'warn'); endif; ?>
            </p>
        </div>
    </div>
    <div class="adm-head__acts">
        <?php if (!$this->is_me && !$deleted && !$disabled && empty($u['is_admin'])): ?>
        <button type="button" class="adm-btn" data-impersonate="<?php echo (int) $u['user_id']; ?>" data-handle="<?php echo $e($u['u_name']); ?>">Sign In as User</button>
        <?php endif; ?>
        <?php if (!$deleted && !PlatformAccount::is($u['user_id'])): ?>
        <button type="button" class="adm-btn" data-message-user="<?php echo (int) $u['user_id']; ?>" data-name="<?php echo $e(trim($u['first_name'] . ' ' . $u['last_name']) ?: '@' . $u['u_name']); ?>" data-handle="<?php echo $e($u['u_name']); ?>" data-avatar="<?php echo $e($u['avatar_url'] ?? ''); ?>">Message</button>
        <?php endif; ?>
        <?php if (!$deleted): ?><button type="button" class="adm-btn" data-act="edit">Edit User</button><?php endif; ?>
        <button type="button" class="adm-btn adm-btn--primary" data-act="adjust">Adjust Balance</button>
        <div class="dropdown">
            <button type="button" class="adm-btn" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false">More <i class="fa-solid fa-chevron-down adm-btn__caret" aria-hidden="true"></i></button>
            <ul class="dropdown-menu dropdown-menu-end adm-menu">
                <li><button type="button" class="dropdown-item" data-act="password">Send Password Reset</button></li>
                <li><button type="button" class="dropdown-item" data-act="mfa_reset">Reset Two-Step Sign-In</button></li>
                <?php if (!empty($u['verified'])): ?><li><button type="button" class="dropdown-item adm-menu__danger" data-act="badge_revoke">Remove Verified Badge…</button></li><?php endif; ?>
                <?php if (!$this->is_me && !$deleted): ?>
                <li><button type="button" class="dropdown-item" data-act="demo" data-demo="<?php echo !empty($u['is_demo']) ? '0' : '1'; ?>"><?php echo !empty($u['is_demo']) ? 'Unmark Demo' : 'Mark as Demo'; ?></button></li>
                <li><hr class="dropdown-divider"></li>
                <li><button type="button" class="dropdown-item<?php echo $disabled ? '' : ' adm-menu__danger'; ?>" data-act="status" data-status="<?php echo $disabled ? 'Active' : 'Disabled'; ?>"><?php echo $disabled ? 'Reactivate Account' : 'Suspend Account…'; ?></button></li>
                <?php if (empty($u['is_admin']) && !PlatformAccount::is($u['user_id'])): ?><li><button type="button" class="dropdown-item adm-menu__danger" data-act="delete" data-handle="<?php echo $e($u['u_name']); ?>">Delete Account…</button></li><?php endif; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</header>

<section class="adm-box adm-box--strip">
    <dl class="adm-strip">
        <div><dt>AI credits</dt><dd><?php echo number_format((int) $u['ai_credit_balance']); ?></dd><dd class="adm-strip__sub">for generation</dd></div>
        <div><dt>Support</dt><dd><?php echo $open_req; ?></dd><dd class="adm-strip__sub">open · <?php echo count($this->tickets); ?> total</dd></div>
        <div><dt>Plan</dt><dd class="adm-strip__text"><?php echo $e($tier !== '' ? $tier : 'None'); ?></dd><dd class="adm-strip__sub"><?php echo !Plan::has_paid_plan($u) ? ($is_creator ? 'Creator account, no paid plan' : 'Fan account') : $e(ucfirst((string) $u['subscription_status'])); ?></dd></div>
    </dl>
</section>

<section class="adm-box adm-usec" id="overview">
    <header class="adm-box__h"><h2 class="adm-box__t">Account</h2></header>
    <dl class="adm-facts">
        <div><dt>Role</dt><dd><?php echo $e($u['role_name'] ?: 'User'); ?></dd></div>
        <div><dt>Joined</dt><dd><?php echo $e($fmt($u['created_at'])); ?></dd></div>
        <div><dt>Came from</dt><dd><?php echo $e($acq !== '' ? $acq : (array_key_exists('acq_landing', $u) && $u['acq_landing'] !== null ? 'Direct' : '—')); ?><?php if (!empty($u['acq_landing'])): ?> <span class="adm-t__muted">· landed on <?php echo $e(strtok((string) $u["acq_landing"], "?")); ?></span><?php endif; ?></dd></div>
        <div><dt>Last active</dt><dd><?php echo $e($fmtT($u['last_active_at'])); ?></dd></div>
        <div><dt>Email</dt><dd><?php echo $e($u['user_email']); ?> <?php echo !empty($u['email_verified']) ? '<span class="adm-t__muted">· verified</span>' : adm_pill('Not verified', 'warn'); ?></dd></div>
        <?php if (LifecycleEmails::bounced($u)): ?><div><dt>Email bounced</dt><dd><?php echo adm_pill('Bounced', 'warn'); ?> <?php echo $e($fmtT($u['email_bounced_at'])); ?> <span class="adm-t__muted">· <?php echo $e((string) $u['email_bounce_reason']); ?> · no email is sent to this address</span></dd></div><?php endif; ?>
        <div><dt>Phone</dt><dd><?php echo $e($u['user_phone'] ?: '—'); ?></dd></div>
        <div><dt>Two-step sign-in</dt><dd><?php echo $e($m2 ? implode(', ', $m2) : 'Off'); ?></dd></div>
        <div><dt>Time zone</dt><dd><?php echo $e($u['content_timezone'] ?: 'UTC'); ?></dd></div>
        <div><dt>Following / followers</dt><dd><?php echo number_format((int) $u['following_n']); ?> / <?php echo number_format((int) $u['followers_n']); ?></dd></div>
        <?php if ($is_creator): ?><div><dt>Public page</dt><dd><a href="/@<?php echo $e(rawurlencode((string) $u['u_name'])); ?>" target="_blank" rel="noopener">/@<?php echo $e($u['u_name']); ?></a></dd></div>
        <div><dt>Creator since</dt><dd><?php echo $e($fmt($u['creator_since'])); ?></dd></div><?php endif; ?>
        <div><dt>Business</dt><dd><?php echo $e($u['business_name'] ?: '—'); ?></dd></div>
        <div><dt>Website</dt><dd><?php echo (string) $u['website_url'] !== '' ? '<a href="' . $e($u['website_url']) . '" target="_blank" rel="noopener nofollow">' . $e(preg_replace('#^https?://#', '', (string) $u['website_url'])) . '</a>' : '—'; ?></dd></div>
        <?php if ((string) ($u['left_reason'] ?? '') !== '' || (string) ($u['left_note'] ?? '') !== ''): $lr = (string) ($u['left_reason'] ?? ''); ?>
        <div><dt><?php echo ($u['left_kind'] ?? '') === 'creator' ? 'Stopped being a creator' : 'Why they left'; ?></dt><dd><?php echo $e($lr !== '' ? (UsersModel::LEAVE_REASONS[$lr] ?? $lr) : '—'); ?><?php if ((string) ($u['left_note'] ?? '') !== ''): ?><br><span class="adm-t__muted">“<?php echo $e($u['left_note']); ?>”</span><?php endif; ?><?php if (!empty($u['left_at'])): ?><br><span class="adm-t__muted"><?php echo $e($fmt($u['left_at'])); ?></span><?php endif; ?></dd></div>
        <?php endif; ?>
    </dl>
    <?php if (BillingService::is_paid($bacct)): ?>
    <h3 class="adm-h3 adm-h3--gap">Creator plan
        <?php if (!empty($u['subscription_cancel_at_period_end'])): ?><button type="button" class="adm-btn adm-btn--sm adm-h3__act" data-act="plan" data-cancel="0">Resume Plan</button>
        <?php else: ?><button type="button" class="adm-btn adm-btn--sm adm-btn--danger adm-h3__act" data-act="plan" data-cancel="1">Cancel at Period End</button><?php endif; ?>
    </h3>
    <dl class="adm-facts">
        <div><dt>Plan</dt><dd><?php echo $e($tier !== '' ? $tier : 'Unknown'); ?></dd></div>
        <div><dt>Status</dt><dd><?php echo $e(ucfirst((string) $u['subscription_status'])); ?><?php if (!empty($u['subscription_cancel_at_period_end'])): ?> <?php echo adm_pill('Cancels at period end', 'warn'); ?><?php endif; ?></dd></div>
        <div><dt>Period ends</dt><dd><?php echo $e($fmt($u['subscription_current_period_end'])); ?></dd></div>
        <div><dt>Next charge</dt><dd><?php $nx = BillingService::next_charge($bacct); echo $nx ? $e(BillingService::money($nx['total']) . ' on ' . $fmt($nx['at'])) : '—'; ?></dd></div>
    </dl>
    <?php endif; ?>
</section>

<section class="adm-box adm-usec" id="activity">
    <header class="adm-box__h"><h2 class="adm-box__t">Recent activity</h2></header>
    <?php if (empty($this->activity)): ?><?php echo adm_empty('No activity yet', 'fa-clock'); ?><?php else: ?>
    <table class="adm-t">
        <tbody>
        <?php foreach ($this->activity as $ev): ?>
            <tr>
                <td class="adm-t__muted adm-t__nowrap adm-t__w180"><?php echo $e($fmtT($ev['at'])); ?></td>
                <td class="adm-t__main"><?php if (!empty($ev['link'])): ?><a href="<?php echo $e($ev['link']); ?>"><?php echo $e($ev['title']); ?></a><?php else: echo $e($ev['title']); endif; ?><?php if ($ev['sub'] !== '' && $ev['sub'] !== $ev['title']): ?><span class="adm-t__sub"><?php echo $e($ev['sub']); ?></span><?php endif; ?></td>
                <td class="adm-r adm-t__num <?php echo $ev['tone'] === 'pos' ? 'adm-pos' : ($ev['tone'] === 'neg' ? 'adm-neg' : 'adm-t__muted'); ?>"><?php echo $e($ev['detail']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<section class="adm-box adm-usec" id="wallet">
    <header class="adm-box__h"><h2 class="adm-box__t">AI credits</h2><span class="adm-box__note">Credit history, newest first</span></header>
    <table class="adm-t">
        <thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="adm-r">AI credits</th><th class="adm-r">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($this->ai_tx as $tx): ?>
            <tr><td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmtT($tx['created_at'])); ?></td><td><?php echo $e(ucwords(str_replace('_', ' ', $tx['type']))); ?></td><td class="adm-t__muted adm-t__wrap"><?php echo $e($tx['description']); ?></td><td class="adm-r adm-t__num <?php echo (int) $tx['credits'] < 0 ? 'adm-neg' : 'adm-pos'; ?>"><?php echo ((int) $tx['credits'] > 0 ? '+' : '') . number_format((int) $tx['credits']); ?></td><td class="adm-r adm-t__num"><?php echo number_format((int) $tx['balance_after']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (empty($this->ai_tx)): ?><?php echo adm_empty('No AI credit activity', 'fa-wand-magic-sparkles'); ?><?php endif; ?>
</section>

<section class="adm-box adm-usec" id="security">
    <header class="adm-box__h"><h2 class="adm-box__t">Sign-in and security</h2></header>
    <dl class="adm-facts adm-facts--acts">
        <div><dt>Email verified</dt><dd><?php echo $onoff(!empty($u['email_verified'])); ?></dd><dd class="adm-facts__acts"><?php if (empty($u['email_verified'])): ?><button type="button" class="adm-btn adm-btn--sm" data-act="verify">Mark Verified</button><?php endif; ?><button type="button" class="adm-btn adm-btn--sm" data-act="resend">Resend Verification</button></dd></div>
        <div><dt>Authenticator app</dt><dd><?php echo $onoff(!empty($u['mfa_totp_enabled'])); ?></dd><dd class="adm-facts__acts"><button type="button" class="adm-btn adm-btn--sm adm-btn--danger" data-act="mfa_reset">Reset Two-Step</button></dd></div>
        <div><dt>Email codes</dt><dd><?php echo $onoff(!empty($u['mfa_email_enabled'])); ?> · <?php echo (int) $u['backup_codes_left']; ?> backup codes left</dd><dd class="adm-facts__acts"><button type="button" class="adm-btn adm-btn--sm" data-act="mfa_email" data-enabled="<?php echo !empty($u['mfa_email_enabled']) ? '0' : '1'; ?>"><?php echo !empty($u['mfa_email_enabled']) ? 'Turn Off Email Codes' : 'Turn On Email Codes'; ?></button></dd></div>
        <div><dt>Last password reset link</dt><dd><?php echo $e($fmtT(!empty($u['reset_token_expires']) ? date('Y-m-d H:i:s', strtotime($u['reset_token_expires'] . ' UTC') - 3600) : '')); ?></dd><dd class="adm-facts__acts"><button type="button" class="adm-btn adm-btn--sm" data-act="password">Send Password Reset</button></dd></div>
    </dl>
    <h3 class="adm-h3 adm-h3--gap">Recent sign-in activity</h3>
    <table class="adm-t">
        <thead><tr><th>Date</th><th>Activity</th><th>Entered as</th><th>IP address</th></tr></thead>
        <tbody>
        <?php $act_label = array('login' => 'Sign-in attempt', 'forgot' => 'Password reset request', 'mfa' => 'Two-step code attempt'); foreach ($this->sign_ins as $si): ?>
            <tr><td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmtT($si['created_at'])); ?></td><td><?php echo $e($act_label[$si['action']] ?? $si['action']); ?></td><td class="adm-t__muted"><?php echo $e($si['identifier']); ?></td><td class="adm-t__muted"><?php echo $e($si['ip_address']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (empty($this->sign_ins)): ?><?php echo adm_empty('No recent sign-in activity', 'fa-right-to-bracket'); ?><?php endif; ?>
</section>

<section class="adm-box adm-usec" id="support">
    <header class="adm-box__h"><h2 class="adm-box__t">Support</h2><span class="adm-box__note"><?php echo $open_req; ?> open of <?php echo count($this->tickets); ?></span></header>
    <table class="adm-t">
        <thead><tr><th>Request</th><th>Topic</th><th class="adm-r">Messages</th><th>Last activity</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($this->tickets as $st): ?>
            <tr class="adm-t__link" data-href="/support/ticket/<?php echo (int) $st['id']; ?>">
                <td class="adm-t__main"><a href="/support/ticket/<?php echo (int) $st['id']; ?>"><?php echo $e($st['subject']); ?></a></td>
                <td class="adm-t__muted"><?php echo $e(SupportModel::CATEGORIES[$st['category']] ?? 'Something else'); ?></td>
                <td class="adm-r adm-t__num adm-t__muted"><?php echo (int) $st['message_count']; ?></td>
                <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmtT($st['last_message_at'])); ?></td>
                <td><?php echo adm_pill($sup_status[$st['status']] ?? $st['status'], $st['status'] === 'open' ? 'warn' : ($st['status'] === 'answered' ? 'ok' : 'gray')); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (empty($this->tickets)): ?><?php echo adm_empty('No support requests', 'fa-life-ring'); ?><?php endif; ?>
</section>

<section class="adm-box adm-usec" id="audit">
    <header class="adm-box__h"><h2 class="adm-box__t">Audit</h2><span class="adm-box__note">Staff actions on this account</span></header>
    <table class="adm-t">
        <thead><tr><th>When</th><th>Staff</th><th>Action</th><th>Details</th><th>IP address</th></tr></thead>
        <tbody>
        <?php $audit_rows = $this->audit; $audit_show_target = false; include __DIR__ . '/_audit_rows.php'; ?>
        </tbody>
    </table>
    <?php if (empty($this->audit)): ?><?php echo adm_empty('No staff actions on this account yet', 'fa-clipboard'); ?><?php endif; ?>
</section>
</div>
<?php include __DIR__ . '/_shell_end.php'; ?>
<?php include __DIR__ . '/_adjust_modal.php'; ?>
<?php if (!$deleted) { include __DIR__ . '/_edit_user_modal.php'; } ?>
<?php include __DIR__ . '/_message_modal.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
<script src="/js/admin-messages.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-messages.js'); ?>"></script>
<script src="/js/admin-user.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-user.js'); ?>"></script>
