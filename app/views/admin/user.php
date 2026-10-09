<?php require_once __DIR__ . '/_rowmenu.php'; ?>
<link rel="stylesheet" href="/css/admin.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/admin.css'); ?>">
<?php
/* /admin/user/<id>: one account and the tools to fix its problem. Actions: public/js/admin-user.js → ApiAdminController. */
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$tz  = (string) ($this->timezone ?: 'UTC');
$fmt = function ($utc, $time = true) use ($tz) {
    if ((string) $utc === '') { return '—'; }
    try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz)); return $d->format($time ? 'M j, Y g:i A' : 'M j, Y'); }
    catch (\Throwable $ex) { return '—'; }
};
$money = function ($credits) { $n = (int) $credits; return number_format($n) . ' ' . (abs($n) === 1 ? 'credit' : 'credits'); };   // credits are shown as credits, never dollars
$u = $this->u;
$name = trim((string) $u['first_name'] . ' ' . (string) $u['last_name']);
$disabled = ((string) $u['user_status'] === 'Disabled');
$deleted = !empty($u['deleted']);
$is_creator = in_array(strtolower((string) ($u['role_name'] ?? '')), array('creator'), true);
$tier = Plan::tier_name($u);   // Free for a creator with no paid plan
$kind_label = array('ppv' => 'Pay-per-view', 'bundle' => 'Bundle', 'message' => 'Paid message');
$refunded = array(); foreach ($this->refunds as $rf) { $refunded[$rf['kind'] . ':' . $rf['ref_id']] = true; }
$sup_status = array('open' => 'Waiting on us', 'answered' => 'Replied', 'closed' => 'Closed');
$yes = function ($b) { return $b ? '<span class="adm-pill adm-pill--ok">On</span>' : '<span class="adm-pill">Off</span>'; };
?>
<div class="adm" id="admUser" data-admin-user="<?php echo (int) $u['user_id']; ?>">
    <a class="adm-back" href="/admin?tab=users"><i class="fa-solid fa-arrow-left"></i> Users</a>

    <header class="adm-uhead">
        <div class="adm-uhead__who">
            <span class="adm-uhead__av" style="<?php echo !empty($u['avatar_url']) ? 'background-image:url(\'' . $e($u['avatar_url']) . '\')' : ''; ?>"><?php echo empty($u['avatar_url']) ? $e(mb_strtoupper(mb_substr($name !== '' ? $name : (string) $u['u_name'], 0, 1))) : ''; ?></span>
            <div>
                <h1 class="adm__title"><?php echo $e($name !== '' ? $name : '@' . $u['u_name']); ?></h1>
                <p class="adm-uhead__meta">@<?php echo $e($u['u_name']); ?> &middot; <?php echo $e($u['user_email']); ?> &middot; User <?php echo (int) $u['user_id']; ?></p>
                <div class="adm-uhead__tags">
                    <span class="adm-pill"><?php echo $e($u['role_name'] ?: 'User'); ?></span>
                    <?php if ($tier !== ''): ?><span class="adm-pill adm-pill--violet"><?php echo $e($tier); ?> plan</span><?php endif; ?>
                    <?php if (!empty($u['is_admin'])): ?><span class="adm-pill adm-pill--violet">Admin</span><?php endif; ?>
                    <?php if (!empty($u['is_demo'])): ?><span class="adm-pill adm-pill--warn">Demo</span><?php endif; ?>
                    <?php if ($deleted): ?><span class="adm-pill adm-pill--bad">Deleted</span><?php elseif ($disabled): ?><span class="adm-pill adm-pill--bad">Suspended</span><?php else: ?><span class="adm-pill adm-pill--ok">Active</span><?php endif; ?>
                    <?php if (empty($u['email_verified'])): ?><span class="adm-pill adm-pill--warn">Email not verified</span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="adm-uhead__acts">
            <?php if (!$this->is_me && !$deleted && !$disabled && empty($u['is_admin'])): ?>
            <button type="button" class="adm-btn" data-impersonate="<?php echo (int) $u['user_id']; ?>" data-handle="<?php echo $e($u['u_name']); ?>"><i class="fa-solid fa-user-secret"></i> Sign In as User</button>
            <?php endif; ?>
            <button type="button" class="adm-btn" data-act="password"><i class="fa-solid fa-key"></i> Send Password Reset</button>
            <button type="button" class="adm-btn" data-act="mfa_reset"><i class="fa-solid fa-shield-halved"></i> Reset Two-Step</button>
            <?php if (!$this->is_me && !$deleted): ?>
            <button type="button" class="adm-btn <?php echo $disabled ? 'adm-btn--ok' : 'adm-btn--danger'; ?>" data-act="status" data-status="<?php echo $disabled ? 'Active' : 'Disabled'; ?>"><?php echo $disabled ? 'Reactivate' : 'Suspend'; ?></button>
            <button type="button" class="adm-btn" data-act="demo" data-demo="<?php echo !empty($u['is_demo']) ? '0' : '1'; ?>"><?php echo !empty($u['is_demo']) ? 'Unmark Demo' : 'Mark as Demo'; ?></button>
            <?php endif; ?>
            <button type="button" class="adm-btn adm-btn--primary" data-act="adjust"><i class="fa-solid fa-plus-minus"></i> Adjust Balance</button>
        </div>
    </header>

<?php
$spent = 0; foreach ($this->purchases as $pp) { $spent += (int) $pp['price_credits']; }
$active_mem = count(array_filter($this->memberships, function ($m) { return $m['status'] === 'active'; }));
$open_req = count(array_filter($this->tickets, function ($t) { return $t['status'] !== 'closed'; }));
?>
    <div class="adm-ustrip">
        <div class="adm-ustrip__cell"><span>Credits</span><b><?php echo number_format((int) $u['credit_balance']); ?></b><small>credits</small></div>
        <div class="adm-ustrip__cell"><span>AI credits</span><b><?php echo number_format((int) $u['ai_credit_balance']); ?></b><small>&nbsp;</small></div>
        <div class="adm-ustrip__cell"><span>Purchases</span><b><?php echo count($this->purchases); ?></b><small><?php echo $money($spent); ?> spent</small></div>
        <div class="adm-ustrip__cell"><span>Memberships</span><b><?php echo $active_mem; ?></b><small><?php echo count($this->memberships); ?> total</small></div>
        <div class="adm-ustrip__cell"><span>Support</span><b><?php echo $open_req; ?> open</b><small><?php echo count($this->tickets); ?> total</small></div>
        <div class="adm-ustrip__cell"><span>Plan</span><b><?php echo $e($tier !== '' ? $tier : 'None'); ?></b><small><?php echo $tier !== '' ? $e(ucfirst((string) $u['subscription_status'])) : 'Fan account'; ?></small></div>
    </div>

    <div class="adm-tabs" id="admTabs">
        <button type="button" class="adm-tab is-active" data-panel="overview"><i class="fa-solid fa-user"></i> Overview</button>
        <button type="button" class="adm-tab" data-panel="wallet"><i class="fa-solid fa-wallet"></i> Wallet</button>
        <button type="button" class="adm-tab" data-panel="purchases"><i class="fa-solid fa-bag-shopping"></i> Purchases <b class="adm-tab__count"><?php echo count($this->purchases); ?></b></button>
        <button type="button" class="adm-tab" data-panel="memberships"><i class="fa-solid fa-heart"></i> Memberships <b class="adm-tab__count"><?php echo count($this->memberships); ?></b></button>
        <button type="button" class="adm-tab" data-panel="security"><i class="fa-solid fa-shield-halved"></i> Sign-in &amp; Security</button>
        <button type="button" class="adm-tab" data-panel="support"><i class="fa-solid fa-life-ring"></i> Support <b class="adm-tab__count"><?php echo count($this->tickets); ?></b></button>
        <button type="button" class="adm-tab" data-panel="audit"><i class="fa-solid fa-clipboard-list"></i> Audit <b class="adm-tab__count"><?php echo count($this->audit); ?></b></button>
    </div>

    <!-- Overview -->
    <section class="adm-sec adm-panel is-active" data-panel="overview">
        <div class="adm-ov">
            <div class="adm-ov__main">
                <div class="adm-box">
                    <h2 class="adm-box__h">Details</h2>
                    <dl class="adm-facts">
                        <div><dt>Joined</dt><dd><?php echo $e($fmt($u['created_at'])); ?></dd></div>
                        <?php
                            // First touch at signup (user_accounts.acq_*): source / medium / campaign, else the referring site, else Direct.
                            $acq = implode(' / ', array_filter(array((string) ($u['acq_source'] ?? ''), (string) ($u['acq_medium'] ?? ''), (string) ($u['acq_campaign'] ?? ''))));
                            if ($acq === '' && !empty($u['acq_referrer'])) { $acq = (string) parse_url($u['acq_referrer'], PHP_URL_HOST); }
                            if ($acq === '' && !empty($u['acq_gclid'])) { $acq = 'Google Ads'; }
                        ?>
                        <div><dt>Came from</dt><dd><?php echo $e($acq !== '' ? $acq : (array_key_exists('acq_landing', $u) && $u['acq_landing'] !== null ? 'Direct' : '—')); ?><?php if (!empty($u['acq_landing'])): ?> <small>· landed on <?php echo $e(strtok((string) $u["acq_landing"], "?")); ?></small><?php endif; ?></dd></div>
                        <div><dt>Last active</dt><dd><?php echo $e($fmt($u['last_active_at'])); ?></dd></div>
                        <div><dt>Email</dt><dd><?php echo $e($u['user_email']); ?> <?php echo !empty($u['email_verified']) ? '<span class="adm-pill adm-pill--ok">Verified</span>' : '<span class="adm-pill adm-pill--warn">Not verified</span>'; ?></dd></div>
                        <div><dt>Phone</dt><dd><?php echo $e($u['user_phone'] ?: '—'); ?></dd></div>
                        <div><dt>Two-step sign-in</dt><dd><?php $m2 = array(); if (!empty($u['mfa_totp_enabled'])) { $m2[] = 'Authenticator app'; } if (!empty($u['mfa_email_enabled'])) { $m2[] = 'Email codes'; } echo $e($m2 ? implode(', ', $m2) : 'Off'); ?></dd></div>
                        <div><dt>Time zone</dt><dd><?php echo $e($u['content_timezone'] ?: 'UTC'); ?></dd></div>
                        <div><dt>Adult content</dt><dd><?php echo !empty($u['adult_content_enabled']) ? 'Shown' : 'Hidden'; ?></dd></div>
                        <div><dt>Age verification</dt><dd><?php
                            $av = isset($this->age_verification) && is_array($this->age_verification) ? $this->age_verification : null;
                            if (!$av) { echo 'Not started'; }
                            elseif ($av['status'] === 'verified') { echo '<span class="adm-pill adm-pill--ok">Verified</span> ' . $e($fmt($av['verified_at'])) . ' via ' . $e(ucfirst((string) $av['provider'])); }
                            elseif ($av['status'] === 'pending') { echo 'Pending since ' . $e($fmt($av['updated_at'])) . ' via ' . $e(ucfirst((string) $av['provider'])); }
                            else { echo '<span class="adm-pill adm-pill--warn">Failed</span> ' . $e($fmt($av['updated_at'])) . ' via ' . $e(ucfirst((string) $av['provider'])); }
                        ?></dd></div>
                        <div><dt>Automatic top-up</dt><dd><?php echo !empty($u['autoreplenish_enabled']) ? 'On' : 'Off'; ?></dd></div>
                        <div><dt>Following</dt><dd><?php echo number_format((int) $u['following_n']); ?></dd></div>
                        <div><dt>Followers</dt><dd><?php echo number_format((int) $u['followers_n']); ?></dd></div>
                        <?php if ($is_creator): ?><div><dt>Public page</dt><dd><a href="/@<?php echo $e(rawurlencode((string) $u['u_name'])); ?>" target="_blank" rel="noopener">/@<?php echo $e($u['u_name']); ?></a></dd></div><?php endif; ?>
                        <div><dt>Business</dt><dd><?php echo $e($u['business_name'] ?: '—'); ?></dd></div>
                        <div><dt>Website</dt><dd><?php echo (string) $u['website_url'] !== '' ? '<a href="' . $e($u['website_url']) . '" target="_blank" rel="noopener nofollow">' . $e(preg_replace('#^https?://#', '', (string) $u['website_url'])) . '</a>' : '—'; ?></dd></div>
                        <?php if ($is_creator): ?><div><dt>Creator since</dt><dd><?php echo $e($fmt($u['creator_since'], false)); ?></dd></div><?php endif; ?>
                    </dl>
                </div>
                <?php $bacct = BillingService::account((int) $u['user_id']); if (BillingService::is_paid($bacct)): ?>
                <div class="adm-box">
                    <div class="adm-box__row">
                        <h2 class="adm-box__h">Creator Plan</h2>
                        <?php if (!empty($u['subscription_cancel_at_period_end'])): ?>
                        <button type="button" class="adm-btn adm-btn--ok" data-act="plan" data-cancel="0">Resume Plan</button>
                        <?php else: ?>
                        <button type="button" class="adm-btn adm-btn--danger" data-act="plan" data-cancel="1">Cancel at Period End</button>
                        <?php endif; ?>
                    </div>
                    <dl class="adm-facts">
                        <div><dt>Plan</dt><dd><?php echo $e($tier !== '' ? $tier : 'Unknown'); ?></dd></div>
                        <div><dt>Status</dt><dd><?php echo $e(ucfirst((string) $u['subscription_status'])); ?><?php if (!empty($u['subscription_cancel_at_period_end'])): ?> <span class="adm-pill adm-pill--warn">Cancels at period end</span><?php endif; ?></dd></div>
                        <div><dt>Period ends</dt><dd><?php echo $e($fmt($u['subscription_current_period_end'], false)); ?></dd></div>
                        <div><dt>Next charge</dt><dd><?php $nx = BillingService::next_charge($bacct); echo $nx ? $e(BillingService::money($nx['total']) . ' on ' . $fmt($nx['at'], false)) : '—'; ?></dd></div>
                    </dl>
                </div>
                <?php endif; ?>
            </div>
            <div class="adm-box adm-ov__feed">
                <h2 class="adm-box__h">Recent Activity</h2>
                <?php if (empty($this->activity)): ?>
                <p class="adm-kv__none">No activity yet.</p>
                <?php else: ?>
                <ol class="adm-feed">
                    <?php foreach ($this->activity as $ev): ?>
                    <li class="adm-feed__item">
                        <span class="adm-feed__ic adm-feed__ic--<?php echo $e($ev['tone']); ?>"><i class="fa-solid <?php echo $e($ev['icon']); ?>"></i></span>
                        <div class="adm-feed__body">
                            <div class="adm-feed__top"><b><?php if (!empty($ev['link'])): ?><a href="<?php echo $e($ev['link']); ?>"><?php echo $e($ev['title']); ?></a><?php else: echo $e($ev['title']); endif; ?></b><?php if ($ev['detail'] !== ''): ?><span class="adm-feed__detail adm-feed__detail--<?php echo $e($ev['tone']); ?>"><?php echo $e($ev['detail']); ?></span><?php endif; ?></div>
                            <?php if ($ev['sub'] !== '' && $ev['sub'] !== $ev['title']): ?><div class="adm-feed__sub"><?php echo $e($ev['sub']); ?></div><?php endif; ?>
                            <time class="adm-feed__at"><?php echo $e($fmt($ev['at'])); ?></time>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Wallet -->
    <section class="adm-sec adm-panel" data-panel="wallet">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Credit History</h2>
            <button type="button" class="adm-btn" data-act="adjust"><i class="fa-solid fa-plus-minus"></i> Adjust Balance</button>
        </div>
        <div class="adm-table adm-table--tx">
            <div class="adm-table__head"><span>Date</span><span>Type</span><span>Description</span><span class="adm-r">Credits</span><span class="adm-r">Balance</span></div>
            <div class="adm-table__body">
                <?php foreach ($this->credit_tx as $tx): ?>
                <div class="adm-txrow"><span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($tx['created_at'])); ?></span><span class="adm-ucell"><?php echo $e(ucwords(str_replace('_', ' ', $tx['type']))); ?></span><span class="adm-ucell adm-ucell--muted"><?php echo $e($tx['description']); ?></span><span class="adm-ucell adm-r <?php echo (int) $tx['credits'] < 0 ? 'adm-neg' : 'adm-pos'; ?>"><?php echo ((int) $tx['credits'] > 0 ? '+' : '') . number_format((int) $tx['credits']); ?></span><span class="adm-ucell adm-r"><?php echo number_format((int) $tx['balance_after']); ?></span></div>
                <?php endforeach; ?>
                <?php if (empty($this->credit_tx)): ?><p class="adm__none">No credit activity.</p><?php endif; ?>
            </div>
        </div>
        <div class="adm-sec__head adm-sec__head--gap"><h2 class="adm-sec__title">AI Credit History</h2></div>
        <div class="adm-table adm-table--tx">
            <div class="adm-table__head"><span>Date</span><span>Type</span><span>Description</span><span class="adm-r">AI credits</span><span class="adm-r">Balance</span></div>
            <div class="adm-table__body">
                <?php foreach ($this->ai_tx as $tx): ?>
                <div class="adm-txrow"><span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($tx['created_at'])); ?></span><span class="adm-ucell"><?php echo $e(ucwords(str_replace('_', ' ', $tx['type']))); ?></span><span class="adm-ucell adm-ucell--muted"><?php echo $e($tx['description']); ?></span><span class="adm-ucell adm-r <?php echo (int) $tx['credits'] < 0 ? 'adm-neg' : 'adm-pos'; ?>"><?php echo ((int) $tx['credits'] > 0 ? '+' : '') . number_format((int) $tx['credits']); ?></span><span class="adm-ucell adm-r"><?php echo number_format((int) $tx['balance_after']); ?></span></div>
                <?php endforeach; ?>
                <?php if (empty($this->ai_tx)): ?><p class="adm__none">No AI credit activity.</p><?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Purchases -->
    <section class="adm-sec adm-panel" data-panel="purchases">
        <div class="adm-table adm-table--upur">
            <div class="adm-table__head"><span>Item</span><span>Type</span><span>Creator</span><span class="adm-r">Price</span><span>Date</span><span></span></div>
            <div class="adm-table__body">
                <?php foreach ($this->purchases as $p): $item = trim(html_entity_decode(strip_tags((string) $p['item']), ENT_QUOTES, 'UTF-8')); ?>
                <div class="adm-uprow" data-kind="<?php echo $e($p['kind']); ?>" data-ref="<?php echo (int) $p['ref_id']; ?>">
                    <span class="adm-ucell adm-uprow__item"><?php echo $e($item !== '' ? mb_substr($item, 0, 90) : 'Deleted item'); ?></span>
                    <span class="adm-ucell"><span class="adm-tag adm-tag--<?php echo $e($p['kind']); ?>"><?php echo $e($kind_label[$p['kind']] ?? $p['kind']); ?></span></span>
                    <span class="adm-ucell adm-ucell--muted">@<?php echo $e($p['creator_handle']); ?></span>
                    <span class="adm-ucell adm-r"><?php echo $money($p['price_credits']); ?></span>
                    <span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($p['created_at'])); ?></span>
                    <span class="adm-ucell adm-ucell--act"><?php echo adm_row_menu('Purchase actions', array(array('text' => 'Refund…', 'attrs' => 'data-act="refund"', 'danger' => true))); ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($this->purchases)): ?><p class="adm__none">No purchases to refund.</p><?php endif; ?>
            </div>
        </div>
        <?php if (!empty($this->refunds)): ?>
        <div class="adm-sec__head adm-sec__head--gap"><h2 class="adm-sec__title">Refunds Issued</h2></div>
        <div class="adm-table adm-table--tx">
            <div class="adm-table__head"><span>Date</span><span>Type</span><span>Reason</span><span class="adm-r">Amount</span><span></span></div>
            <div class="adm-table__body">
                <?php foreach ($this->refunds as $rf): ?>
                <div class="adm-txrow"><span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($rf['created_at'])); ?></span><span class="adm-ucell"><?php echo $e($kind_label[$rf['kind']] ?? $rf['kind']); ?></span><span class="adm-ucell adm-ucell--muted"><?php echo $e($rf['reason'] ?: '—'); ?></span><span class="adm-ucell adm-r"><?php echo $money($rf['amount_credits']); ?></span><span></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <!-- Memberships -->
    <section class="adm-sec adm-panel" data-panel="memberships">
        <div class="adm-table adm-table--mem">
            <div class="adm-table__head"><span>Creator</span><span>Plan</span><span class="adm-r">Price</span><span>Status</span><span>Renews</span><span></span></div>
            <div class="adm-table__body">
                <?php foreach ($this->memberships as $m): $active = ($m['status'] === 'active'); ?>
                <div class="adm-memrow" data-membership="<?php echo (int) $m['id']; ?>">
                    <span class="adm-ucell"><span class="adm-uinfo"><span class="adm-uinfo__name"><?php echo $e($m['creator_name']); ?></span><span class="adm-uinfo__sub">@<?php echo $e($m['creator_handle']); ?></span></span></span>
                    <span class="adm-ucell"><?php echo $e($m['plan_name']); ?></span>
                    <span class="adm-ucell adm-r"><?php echo !empty($m['is_free']) ? 'Free' : '$' . number_format(((int) $m['price_cents']) / 100, 2) . '/' . $e($m['billing_interval'] ?: 'month'); ?></span>
                    <span class="adm-ucell"><?php echo $e(ucfirst((string) $m['status'])); ?><?php if ($active && !empty($m['cancel_at_period_end'])): ?> <span class="adm-pill adm-pill--warn">Ends at period end</span><?php endif; ?></span>
                    <span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($m['current_period_end'], false)); ?></span>
                    <span class="adm-ucell adm-ucell--act"><?php echo ($active && empty($m['cancel_at_period_end'])) ? adm_row_menu('Membership actions', array(array('text' => 'Cancel membership…', 'attrs' => 'data-act="membership"', 'danger' => true))) : ''; ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($this->memberships)): ?><p class="adm__none">No memberships.</p><?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Security -->
    <section class="adm-sec adm-panel" data-panel="security">
        <div class="adm-kv-grid">
            <div class="adm-kv-card">
                <h2 class="adm-kv-card__h">Email</h2>
                <dl class="adm-kv"><div><dt>Verified</dt><dd><?php echo $yes(!empty($u['email_verified'])); ?></dd></div></dl>
                <div class="adm-kv-card__acts">
                    <?php if (empty($u['email_verified'])): ?><button type="button" class="adm-btn adm-btn--ok" data-act="verify">Mark Verified</button><?php endif; ?>
                    <button type="button" class="adm-btn" data-act="resend">Resend Verification</button>
                </div>
            </div>
            <div class="adm-kv-card">
                <h2 class="adm-kv-card__h">Two-Step Sign-In</h2>
                <dl class="adm-kv">
                    <div><dt>Authenticator app</dt><dd><?php echo $yes(!empty($u['mfa_totp_enabled'])); ?></dd></div>
                    <div><dt>Email codes</dt><dd><?php echo $yes(!empty($u['mfa_email_enabled'])); ?></dd></div>
                    <div><dt>Backup codes left</dt><dd><?php echo (int) $u['backup_codes_left']; ?></dd></div>
                </dl>
                <div class="adm-kv-card__acts">
                    <button type="button" class="adm-btn" data-act="mfa_email" data-enabled="<?php echo !empty($u['mfa_email_enabled']) ? '0' : '1'; ?>"><?php echo !empty($u['mfa_email_enabled']) ? 'Turn Off Email Codes' : 'Turn On Email Codes'; ?></button>
                    <button type="button" class="adm-btn adm-btn--danger" data-act="mfa_reset">Reset Two-Step Sign-In</button>
                </div>
            </div>
            <div class="adm-kv-card">
                <h2 class="adm-kv-card__h">Password</h2>
                <dl class="adm-kv"><div><dt>Last reset link</dt><dd><?php echo $e($fmt(!empty($u['reset_token_expires']) ? date('Y-m-d H:i:s', strtotime($u['reset_token_expires'] . ' UTC') - 3600) : '')); ?></dd></div></dl>
                <div class="adm-kv-card__acts"><button type="button" class="adm-btn" data-act="password"><i class="fa-solid fa-key"></i> Send Password Reset</button></div>
            </div>
        </div>
        <div class="adm-sec__head adm-sec__head--gap"><h2 class="adm-sec__title">Recent Sign-In Activity</h2></div>
        <div class="adm-table adm-table--signin">
            <div class="adm-table__head"><span>Date</span><span>Activity</span><span>Entered as</span><span>IP address</span></div>
            <div class="adm-table__body">
                <?php $act_label = array('login' => 'Sign-in attempt', 'forgot' => 'Password reset request', 'mfa' => 'Two-step code attempt'); foreach ($this->sign_ins as $si): ?>
                <div class="adm-sirow"><span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($si['created_at'])); ?></span><span class="adm-ucell"><?php echo $e($act_label[$si['action']] ?? $si['action']); ?></span><span class="adm-ucell adm-ucell--muted"><?php echo $e($si['identifier']); ?></span><span class="adm-ucell adm-ucell--muted"><?php echo $e($si['ip_address']); ?></span></div>
                <?php endforeach; ?>
                <?php if (empty($this->sign_ins)): ?><p class="adm__none">No recent sign-in activity.</p><?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Support -->
    <section class="adm-sec adm-panel" data-panel="support">
        <div class="adm-table adm-table--support">
            <div class="adm-table__head"><span>Request</span><span>Topic</span><span>Messages</span><span>Last activity</span><span>Status</span><span></span></div>
            <div class="adm-table__body">
                <?php foreach ($this->tickets as $st): ?>
                <a class="adm-suprow adm-suprow--user" href="/support/ticket/<?php echo (int) $st['id']; ?>">
                    <span class="adm-ucell adm-suprow__subject"><?php echo $e($st['subject']); ?></span>
                    <span class="adm-ucell adm-ucell--muted"><?php echo $e(SupportModel::CATEGORIES[$st['category']] ?? 'Something else'); ?></span>
                    <span class="adm-ucell adm-ucell--muted"><?php echo (int) $st['message_count']; ?></span>
                    <span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($st['last_message_at'])); ?></span>
                    <span class="adm-ucell"><span class="adm-tag adm-tag--sup-<?php echo $e($st['status']); ?>"><?php echo $e($sup_status[$st['status']] ?? $st['status']); ?></span></span>
                    <span></span>
                </a>
                <?php endforeach; ?>
                <?php if (empty($this->tickets)): ?><p class="adm__none">No support requests.</p><?php endif; ?>
            </div>
        </div>
    </section>
    <section class="adm-sec adm-panel" data-panel="audit" id="admUserAudit">
        <div class="adm-table adm-table--audit adm-table--audit-user">
            <div class="adm-table__head"><span>When</span><span>Staff</span><span>Action</span><span>Details</span><span>IP address</span></div>
            <div class="adm-table__body">
                <?php $audit_rows = $this->audit; $audit_show_target = false; $fmt2 = $fmt; include __DIR__ . '/_audit_rows.php'; ?>
                <?php if (empty($this->audit)): ?><p class="adm__none">No staff actions on this account yet.</p><?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/_adjust_modal.php'; ?>
<script src="/js/admin-user.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-user.js'); ?>"></script>
