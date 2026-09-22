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
$money = function ($credits) { return '$' . number_format(((int) $credits) / 10, 2); };
$u = $this->u;
$name = trim((string) $u['first_name'] . ' ' . (string) $u['last_name']);
$disabled = ((string) $u['user_status'] === 'Disabled');
$deleted = !empty($u['deleted']);
$is_creator = in_array(strtolower((string) ($u['role_name'] ?? '')), array('creator'), true);
$tier = PlanTiers::TIERS[(string) ($u['plan_tier'] ?? '')]['name'] ?? '';
$kind_label = array('ppv' => 'Pay-per-view', 'bundle' => 'Bundle', 'message' => 'Paid message');
$refunded = array(); foreach ($this->refunds as $rf) { $refunded[$rf['kind'] . ':' . $rf['ref_id']] = true; }
$sup_status = array('open' => 'Waiting on us', 'answered' => 'Replied', 'closed' => 'Closed');
$yes = function ($b) { return $b ? '<span class="adm-pill adm-pill--ok">On</span>' : '<span class="adm-pill">Off</span>'; };
?>
<div class="adm" id="admUser" data-user="<?php echo (int) $u['user_id']; ?>">
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
                    <?php if ($deleted): ?><span class="adm-pill adm-pill--bad">Deleted</span><?php elseif ($disabled): ?><span class="adm-pill adm-pill--bad">Suspended</span><?php else: ?><span class="adm-pill adm-pill--ok">Active</span><?php endif; ?>
                    <?php if (empty($u['email_verified'])): ?><span class="adm-pill adm-pill--warn">Email not verified</span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="adm-uhead__acts">
            <button type="button" class="adm-btn" data-act="password"><i class="fa-solid fa-key"></i> Send Password Reset</button>
            <?php if (!$this->is_me && !$deleted): ?>
            <button type="button" class="adm-btn <?php echo $disabled ? 'adm-btn--ok' : 'adm-btn--danger'; ?>" data-act="status" data-status="<?php echo $disabled ? 'Active' : 'Disabled'; ?>"><?php echo $disabled ? 'Reactivate' : 'Suspend'; ?></button>
            <?php endif; ?>
        </div>
    </header>

    <div class="adm-tabs" id="admTabs">
        <button type="button" class="adm-tab is-active" data-panel="overview"><i class="fa-solid fa-user"></i> Overview</button>
        <button type="button" class="adm-tab" data-panel="wallet"><i class="fa-solid fa-wallet"></i> Wallet</button>
        <button type="button" class="adm-tab" data-panel="purchases"><i class="fa-solid fa-bag-shopping"></i> Purchases <b class="adm-tab__count"><?php echo count($this->purchases); ?></b></button>
        <button type="button" class="adm-tab" data-panel="memberships"><i class="fa-solid fa-heart"></i> Memberships <b class="adm-tab__count"><?php echo count($this->memberships); ?></b></button>
        <button type="button" class="adm-tab" data-panel="security"><i class="fa-solid fa-shield-halved"></i> Sign-in &amp; Security</button>
        <button type="button" class="adm-tab" data-panel="support"><i class="fa-solid fa-life-ring"></i> Support <b class="adm-tab__count"><?php echo count($this->tickets); ?></b></button>
    </div>

    <!-- Overview -->
    <section class="adm-sec adm-panel is-active" data-panel="overview">
        <div class="adm-kv-grid">
            <div class="adm-kv-card">
                <h2 class="adm-kv-card__h">Account</h2>
                <dl class="adm-kv">
                    <div><dt>Joined</dt><dd><?php echo $e($fmt($u['created_at'])); ?></dd></div>
                    <div><dt>Last active</dt><dd><?php echo $e($fmt($u['last_active_at'])); ?></dd></div>
                    <div><dt>Email</dt><dd><?php echo $e($u['user_email']); ?></dd></div>
                    <div><dt>Phone</dt><dd><?php echo $e($u['user_phone'] ?: '—'); ?></dd></div>
                    <div><dt>Time zone</dt><dd><?php echo $e($u['content_timezone'] ?: 'UTC'); ?></dd></div>
                    <div><dt>Adult content</dt><dd><?php echo $yes(!empty($u['adult_content_enabled'])); ?></dd></div>
                    <div><dt>Following</dt><dd><?php echo (int) $u['following_n']; ?></dd></div>
                    <?php if ($is_creator): ?><div><dt>Followers</dt><dd><?php echo (int) $u['followers_n']; ?></dd></div>
                    <div><dt>Public page</dt><dd><a href="/@<?php echo $e(rawurlencode((string) $u['u_name'])); ?>" target="_blank" rel="noopener">/@<?php echo $e($u['u_name']); ?></a></dd></div><?php endif; ?>
                </dl>
            </div>
            <div class="adm-kv-card">
                <h2 class="adm-kv-card__h">Balances</h2>
                <dl class="adm-kv">
                    <div><dt>Credits</dt><dd><?php echo number_format((int) $u['credit_balance']); ?> <span class="adm-kv__sub">(<?php echo $money($u['credit_balance']); ?>)</span></dd></div>
                    <div><dt>AI credits</dt><dd><?php echo number_format((int) $u['ai_credit_balance']); ?></dd></div>
                    <div><dt>Automatic top-up</dt><dd><?php echo $yes(!empty($u['autoreplenish_enabled'])); ?></dd></div>
                </dl>
                <button type="button" class="adm-btn" data-act="adjust"><i class="fa-solid fa-plus-minus"></i> Adjust Balance</button>
            </div>
            <div class="adm-kv-card">
                <h2 class="adm-kv-card__h">Creator Plan</h2>
                <?php if ((string) ($u['stripe_subscription_id'] ?? '') === ''): ?>
                <p class="adm-kv__none">No plan</p>
                <?php else: ?>
                <dl class="adm-kv">
                    <div><dt>Plan</dt><dd><?php echo $e($tier !== '' ? $tier : 'Unknown'); ?></dd></div>
                    <div><dt>Status</dt><dd><?php echo $e(ucfirst((string) $u['subscription_status'])); ?><?php if (!empty($u['subscription_cancel_at_period_end'])): ?> <span class="adm-pill adm-pill--warn">Cancels at period end</span><?php endif; ?></dd></div>
                    <div><dt>Current period ends</dt><dd><?php echo $e($fmt($u['subscription_current_period_end'], false)); ?></dd></div>
                </dl>
                <?php if (!empty($u['subscription_cancel_at_period_end'])): ?>
                <button type="button" class="adm-btn adm-btn--ok" data-act="plan" data-cancel="0">Resume Plan</button>
                <?php else: ?>
                <button type="button" class="adm-btn adm-btn--danger" data-act="plan" data-cancel="1">Cancel at Period End</button>
                <?php endif; ?>
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
                    <span class="adm-ucell adm-ucell--act"><button type="button" class="adm-btn adm-btn--danger" data-act="refund">Refund</button></span>
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
                    <span class="adm-ucell adm-ucell--act"><?php if ($active && empty($m['cancel_at_period_end'])): ?><button type="button" class="adm-btn adm-btn--danger" data-act="membership">Cancel</button><?php endif; ?></span>
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
</div>

<div class="modal fade" id="admAdjustModal" tabindex="-1" aria-hidden="true" aria-labelledby="admAdjustTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="admAdjustTitle">Adjust Balance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="adm-field"><label for="adj_wallet">Balance</label>
                    <select class="form-control" id="adj_wallet"><option value="credits">Credits (current <?php echo number_format((int) $u['credit_balance']); ?>)</option><option value="ai">AI credits (current <?php echo number_format((int) $u['ai_credit_balance']); ?>)</option></select>
                </div>
                <div class="adm-field"><label for="adj_amount">Amount</label><input type="number" class="form-control" id="adj_amount" step="1" placeholder="100 or -100"></div>
                <div class="adm-field"><label for="adj_reason">Reason</label><input type="text" class="form-control" id="adj_reason" maxlength="200" placeholder="Goodwill credit for failed unlock"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="adm-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="adm-btn adm-btn--primary" id="adjSave">Save Adjustment</button>
            </div>
        </div>
    </div>
</div>
<script src="/js/admin-user.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-user.js'); ?>"></script>
