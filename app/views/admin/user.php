<?php include __DIR__ . '/_shell.php'; ?>
<?php
/* /admin/user/<id>: one account on a single scroll: summary header, then sections. Actions: public/js/admin-user.js → ApiAdminController. */
$fmtT = function ($utc) use ($fmt) { return $fmt($utc, true); };
$money = function ($credits) { $n = (int) $credits; return number_format($n) . ' ' . (abs($n) === 1 ? 'credit' : 'credits'); };   // credits are shown as credits, never dollars
$u = $this->u;
$name = trim((string) $u['first_name'] . ' ' . (string) $u['last_name']);
$disabled = ((string) $u['user_status'] === 'Disabled');
$deleted = !empty($u['deleted']);
$is_creator = in_array(strtolower((string) ($u['role_name'] ?? '')), array('creator'), true);
$tier = Plan::tier_name($u);   // Free for a creator with no paid plan
$kind_label = array('ppv' => 'Pay-per-view', 'bundle' => 'Bundle', 'message' => 'Paid message');
$sup_status = array('open' => 'Waiting on us', 'answered' => 'Replied', 'closed' => 'Closed');
$onoff = function ($b) { return $b ? 'On' : '<span class="adm-t__muted">Off</span>'; };
$spent = 0; foreach ($this->purchases as $pp) { $spent += (int) $pp['price_credits']; }
$active_mem = count(array_filter($this->memberships, function ($m) { return $m['status'] === 'active'; }));
$open_req = count(array_filter($this->tickets, function ($t) { return $t['status'] !== 'closed'; }));
$av = isset($this->age_verification) && is_array($this->age_verification) ? $this->age_verification : null;
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
                <?php if (empty($u['email_verified'])): echo adm_pill('Email not verified', 'warn'); endif; ?>
            </p>
        </div>
    </div>
    <div class="adm-head__acts">
        <?php if (!$this->is_me && !$deleted && !$disabled && empty($u['is_admin'])): ?>
        <button type="button" class="adm-btn" data-impersonate="<?php echo (int) $u['user_id']; ?>" data-handle="<?php echo $e($u['u_name']); ?>">Sign In as User</button>
        <?php endif; ?>
        <button type="button" class="adm-btn adm-btn--primary" data-act="adjust">Adjust Balance</button>
        <div class="dropdown">
            <button type="button" class="adm-btn" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false">More <i class="fa-solid fa-chevron-down adm-btn__caret" aria-hidden="true"></i></button>
            <ul class="dropdown-menu dropdown-menu-end adm-menu">
                <li><button type="button" class="dropdown-item" data-act="password">Send Password Reset</button></li>
                <li><button type="button" class="dropdown-item" data-act="mfa_reset">Reset Two-Step Sign-In</button></li>
                <?php if ($av): ?><li><button type="button" class="dropdown-item" data-act="age_reset">Reset Age Verification</button></li><?php endif; ?>
                <?php if (!$this->is_me && !$deleted): ?>
                <li><button type="button" class="dropdown-item" data-act="demo" data-demo="<?php echo !empty($u['is_demo']) ? '0' : '1'; ?>"><?php echo !empty($u['is_demo']) ? 'Unmark Demo' : 'Mark as Demo'; ?></button></li>
                <li><hr class="dropdown-divider"></li>
                <li><button type="button" class="dropdown-item<?php echo $disabled ? '' : ' adm-menu__danger'; ?>" data-act="status" data-status="<?php echo $disabled ? 'Active' : 'Disabled'; ?>"><?php echo $disabled ? 'Reactivate Account' : 'Suspend Account…'; ?></button></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</header>

<section class="adm-box adm-box--strip">
    <dl class="adm-strip">
        <div><dt>Credits</dt><dd><?php echo number_format((int) $u['credit_balance']); ?></dd><dd class="adm-strip__sub">wallet</dd></div>
        <div><dt>AI credits</dt><dd><?php echo number_format((int) $u['ai_credit_balance']); ?></dd><dd class="adm-strip__sub">for generation</dd></div>
        <div><dt>Purchases</dt><dd><?php echo count($this->purchases); ?></dd><dd class="adm-strip__sub"><?php echo $money($spent); ?> spent</dd></div>
        <div><dt>Memberships</dt><dd><?php echo $active_mem; ?></dd><dd class="adm-strip__sub"><?php echo count($this->memberships); ?> total</dd></div>
        <div><dt>Support</dt><dd><?php echo $open_req; ?></dd><dd class="adm-strip__sub">open · <?php echo count($this->tickets); ?> total</dd></div>
        <div><dt>Plan</dt><dd class="adm-strip__text"><?php echo $e($tier !== '' ? $tier : 'None'); ?></dd><dd class="adm-strip__sub"><?php echo $tier !== '' ? $e(ucfirst((string) $u['subscription_status'])) : 'Fan account'; ?></dd></div>
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
        <div><dt>Phone</dt><dd><?php echo $e($u['user_phone'] ?: '—'); ?></dd></div>
        <div><dt>Two-step sign-in</dt><dd><?php echo $e($m2 ? implode(', ', $m2) : 'Off'); ?></dd></div>
        <div><dt>Time zone</dt><dd><?php echo $e($u['content_timezone'] ?: 'UTC'); ?></dd></div>
        <div><dt>Adult content</dt><dd><?php echo !empty($u['adult_content_enabled']) ? 'Shown' : 'Hidden'; ?></dd></div>
        <div><dt>Age verification</dt><dd><?php
            if (!$av) { echo 'Not started'; }
            elseif ($av['status'] === 'verified') { echo adm_pill('Verified') . ' ' . $e($fmt($av['verified_at'])) . ' via ' . $e(ucfirst((string) $av['provider'])); }
            elseif ($av['status'] === 'pending') { echo adm_pill('Pending') . ' since ' . $e($fmt($av['updated_at'])) . ' via ' . $e(ucfirst((string) $av['provider'])); }
            else { echo adm_pill('Failed') . ' ' . $e($fmt($av['updated_at'])) . ' via ' . $e(ucfirst((string) $av['provider'])); }
        ?></dd></div>
        <div><dt>Automatic top-up</dt><dd><?php echo !empty($u['autoreplenish_enabled']) ? 'On' : 'Off'; ?></dd></div>
        <div><dt>Following / followers</dt><dd><?php echo number_format((int) $u['following_n']); ?> / <?php echo number_format((int) $u['followers_n']); ?></dd></div>
        <?php if ($is_creator): ?><div><dt>Public page</dt><dd><a href="/@<?php echo $e(rawurlencode((string) $u['u_name'])); ?>" target="_blank" rel="noopener">/@<?php echo $e($u['u_name']); ?></a></dd></div>
        <div><dt>Creator since</dt><dd><?php echo $e($fmt($u['creator_since'])); ?></dd></div><?php endif; ?>
        <div><dt>Business</dt><dd><?php echo $e($u['business_name'] ?: '—'); ?></dd></div>
        <div><dt>Website</dt><dd><?php echo (string) $u['website_url'] !== '' ? '<a href="' . $e($u['website_url']) . '" target="_blank" rel="noopener nofollow">' . $e(preg_replace('#^https?://#', '', (string) $u['website_url'])) . '</a>' : '—'; ?></dd></div>
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
    <header class="adm-box__h"><h2 class="adm-box__t">Wallet</h2><span class="adm-box__note">Credit history, newest first</span></header>
    <table class="adm-t">
        <thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="adm-r">Credits</th><th class="adm-r">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($this->credit_tx as $tx): ?>
            <tr><td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmtT($tx['created_at'])); ?></td><td><?php echo $e(ucwords(str_replace('_', ' ', $tx['type']))); ?></td><td class="adm-t__muted adm-t__wrap"><?php echo $e($tx['description']); ?></td><td class="adm-r adm-t__num <?php echo (int) $tx['credits'] < 0 ? 'adm-neg' : 'adm-pos'; ?>"><?php echo ((int) $tx['credits'] > 0 ? '+' : '') . number_format((int) $tx['credits']); ?></td><td class="adm-r adm-t__num"><?php echo number_format((int) $tx['balance_after']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (empty($this->credit_tx)): ?><?php echo adm_empty('No credit activity', 'fa-wallet'); ?><?php endif; ?>
    <h3 class="adm-h3 adm-h3--gap">AI credits</h3>
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

<section class="adm-box adm-usec" id="purchases">
    <header class="adm-box__h"><h2 class="adm-box__t">Purchases</h2><span class="adm-box__note"><?php echo count($this->purchases); ?> · <?php echo $money($spent); ?> spent</span></header>
    <table class="adm-t">
        <thead><tr><th>Item</th><th>Type</th><th>Creator</th><th class="adm-r">Price</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($this->purchases as $p): $item = trim(html_entity_decode(strip_tags((string) $p['item']), ENT_QUOTES, 'UTF-8')); ?>
            <tr class="adm-uprow" data-kind="<?php echo $e($p['kind']); ?>" data-ref="<?php echo (int) $p['ref_id']; ?>">
                <td class="adm-t__main adm-t__ellipsis"><?php echo $e($item !== '' ? mb_substr($item, 0, 90) : 'Deleted item'); ?></td>
                <td class="adm-t__muted"><?php echo $e($kind_label[$p['kind']] ?? $p['kind']); ?></td>
                <td class="adm-t__muted">@<?php echo $e($p['creator_handle']); ?></td>
                <td class="adm-r adm-t__num"><?php echo $money($p['price_credits']); ?></td>
                <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmtT($p['created_at'])); ?></td>
                <td class="adm-t__act"><?php echo adm_row_menu('Purchase actions', array(array('text' => 'Refund…', 'attrs' => 'data-act="refund"', 'danger' => true))); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (empty($this->purchases)): ?><?php echo adm_empty('No purchases', 'fa-bag-shopping'); ?><?php endif; ?>
    <?php if (!empty($this->refunds)): ?>
    <h3 class="adm-h3 adm-h3--gap">Refunds issued</h3>
    <table class="adm-t">
        <thead><tr><th>Date</th><th>Type</th><th>Reason</th><th class="adm-r">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($this->refunds as $rf): ?>
            <tr><td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmtT($rf['created_at'])); ?></td><td><?php echo $e($kind_label[$rf['kind']] ?? $rf['kind']); ?></td><td class="adm-t__muted adm-t__wrap"><?php echo $e($rf['reason'] ?: '—'); ?></td><td class="adm-r adm-t__num"><?php echo $money($rf['amount_credits']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<section class="adm-box adm-usec" id="memberships">
    <header class="adm-box__h"><h2 class="adm-box__t">Memberships</h2><span class="adm-box__note"><?php echo $active_mem; ?> active of <?php echo count($this->memberships); ?></span></header>
    <table class="adm-t">
        <thead><tr><th>Creator</th><th>Plan</th><th class="adm-r">Price</th><th>Status</th><th>Renews</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($this->memberships as $m): $active = ($m['status'] === 'active'); ?>
            <tr class="adm-memrow" data-membership="<?php echo (int) $m['id']; ?>">
                <td class="adm-t__who"><?php echo adm_who($m['creator_name'], $m['creator_handle'], '/@' . rawurlencode((string) $m['creator_handle'])); ?></td>
                <td><?php echo $e($m['plan_name']); ?></td>
                <td class="adm-r adm-t__num"><?php echo !empty($m['is_free']) ? 'Free' : '$' . number_format(((int) $m['price_cents']) / 100, 2) . '/' . $e($m['billing_interval'] ?: 'month'); ?></td>
                <td><?php echo adm_pill(ucfirst((string) $m['status'])); ?><?php if ($active && !empty($m['cancel_at_period_end'])): ?> <?php echo adm_pill('Ends at period end', 'warn'); ?><?php endif; ?></td>
                <td class="adm-t__muted adm-t__nowrap"><?php echo $e($fmt($m['current_period_end'])); ?></td>
                <td class="adm-t__act"><?php echo ($active && empty($m['cancel_at_period_end'])) ? adm_row_menu('Membership actions', array(array('text' => 'Cancel membership…', 'attrs' => 'data-act="membership"', 'danger' => true))) : ''; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (empty($this->memberships)): ?><?php echo adm_empty('No memberships', 'fa-heart'); ?><?php endif; ?>
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
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
<script src="/js/admin-user.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-user.js'); ?>"></script>
