<link rel="stylesheet" href="/css/admin.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/admin.css'); ?>">
<?php
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$ini = function ($n) { $n = trim((string) $n); return $n === '' ? '?' : mb_strtoupper(mb_substr($n, 0, 1)); };
$tz  = (string) ($this->timezone ?? 'UTC');
$fmt = function ($utc, $withTime = false) use ($tz) {
    if ((string) $utc === '') { return '—'; }
    try {
        $d = new DateTime((string) $utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone($tz ?: 'UTC'));
        return $d->format($withTime ? 'M j, Y g:i A' : 'M j, Y');
    } catch (\Throwable $ex) { return '—'; }
};
$s = $this->stats;
$review = (int) $s['mod_pending'] + (int) $s['mod_flagged'];
$me = (int) $this->me;
$queue = $this->queue;
$users = $this->users;
?>
<div class="adm">
    <header class="adm__head">
        <h1 class="adm__title">Admin</h1>
        <p class="adm__sub">Platform overview, content moderation, and user management.</p>
    </header>

    <div class="adm-kpis">
        <div class="adm-kpi">
            <div class="adm-kpi__top"><span class="adm-kpi__label">Users</span><span class="adm-kpi__ic"><i class="fa-solid fa-users"></i></span></div>
            <div class="adm-kpi__val"><?php echo number_format((int) $s['users']); ?></div>
            <div class="adm-kpi__sub"><?php echo number_format((int) $s['creators']); ?> creators</div>
        </div>
        <div class="adm-kpi">
            <div class="adm-kpi__top"><span class="adm-kpi__label">Total revenue</span><span class="adm-kpi__ic"><i class="fa-solid fa-sack-dollar"></i></span></div>
            <div class="adm-kpi__val">$<?php echo number_format(((int) $s['gross_cents']) / 100, 2); ?></div>
            <div class="adm-kpi__sub">pay-per-view + bundles</div>
        </div>
        <div class="adm-kpi">
            <div class="adm-kpi__top"><span class="adm-kpi__label">Platform revenue</span><span class="adm-kpi__ic"><i class="fa-solid fa-coins"></i></span></div>
            <div class="adm-kpi__val">$<?php echo number_format(((int) $s['platform_cents']) / 100, 2); ?></div>
            <div class="adm-kpi__sub">our cut, after creator payouts</div>
        </div>
        <div class="adm-kpi">
            <div class="adm-kpi__top"><span class="adm-kpi__label">Subscriptions</span><span class="adm-kpi__ic"><i class="fa-solid fa-heart"></i></span></div>
            <div class="adm-kpi__val"><?php echo number_format((int) $s['active_subs']); ?></div>
            <div class="adm-kpi__sub">$<?php echo number_format(((int) $s['mrr_cents']) / 100, 2); ?>/mo<?php if ((int) $s['sub_fee_cents'] > 0): ?> &middot; $<?php echo number_format(((int) $s['sub_fee_cents']) / 100, 2); ?>/mo our fee<?php endif; ?></div>
        </div>
        <div class="adm-kpi<?php echo $review > 0 ? ' adm-kpi--alert' : ''; ?>">
            <div class="adm-kpi__top"><span class="adm-kpi__label">Needs review</span><span class="adm-kpi__ic"><i class="fa-solid fa-shield-halved"></i></span></div>
            <div class="adm-kpi__val"><?php echo number_format($review); ?></div>
            <div class="adm-kpi__sub"><?php echo number_format((int) $s['mod_flagged']); ?> flagged &middot; <?php echo number_format((int) $s['mod_pending']); ?> unscanned</div>
        </div>
    </div>

    <div class="adm-tabs" id="admTabs">
        <button type="button" class="adm-tab is-active" data-panel="moderation"><i class="fa-solid fa-shield-halved"></i> Moderation<?php if ($review > 0): ?> <b class="adm-tab__badge"><?php echo (int) $review; ?></b><?php endif; ?></button>
        <button type="button" class="adm-tab" data-panel="reports"><i class="fa-solid fa-flag"></i> Reports<?php if ((int) $this->reports_open > 0): ?> <b class="adm-tab__badge"><?php echo (int) $this->reports_open; ?></b><?php endif; ?></button>
        <button type="button" class="adm-tab" data-panel="verification"><i class="fa-solid fa-user-check"></i> Verification<?php if ((int) $this->verif_pending > 0): ?> <b class="adm-tab__badge"><?php echo (int) $this->verif_pending; ?></b><?php endif; ?></button>
        <button type="button" class="adm-tab" data-panel="sales"><i class="fa-solid fa-receipt"></i> Sales</button>
        <button type="button" class="adm-tab" data-panel="users"><i class="fa-solid fa-users"></i> Users</button>
    </div>

    <section class="adm-sec adm-panel is-active" data-panel="moderation">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Moderation queue</h2>
            <span class="adm-sec__meta"><?php echo count($queue); ?> flagged or unscanned · adult content is already live to opted-in fans</span>
        </div>
        <?php if (empty($queue)): ?>
            <div class="adm-empty">
                <span class="adm-empty__ic"><i class="fa-solid fa-circle-check"></i></span>
                <p class="adm-empty__t">Nothing to review</p>
                <p class="adm-empty__x">Flagged and unscanned content is listed here for oversight. Nothing waits on you.</p>
            </div>
        <?php else: ?>
            <div class="adm-mod" id="admMod">
                <?php foreach ($queue as $a): ?>
                <div class="adm-card" data-asset="<?php echo (int) $a['id']; ?>">
                    <div class="adm-card__img" style="background-image:url('<?php echo $e($a['thumb']); ?>')">
                        <span class="adm-card__badge adm-card__badge--<?php echo $a['status'] === 'flagged' ? 'flag' : 'pend'; ?>">
                            <?php echo $a['status'] === 'flagged' ? 'Flagged' : 'Unscanned'; ?>
                        </span>
                    </div>
                    <div class="adm-card__body">
                        <a class="adm-card__creator" href="/@<?php echo $e($a['creator_handle']); ?>" target="_blank" rel="noopener">@<?php echo $e($a['creator_handle']); ?></a>
                        <?php if ($a['status'] === 'flagged' && ($a['labels'] !== '' || $a['score'] !== null)): ?>
                            <span class="adm-card__ai"><i class="fa-solid fa-robot"></i> <?php echo $a['labels'] !== '' ? $e($a['labels']) : 'adult'; ?><?php echo $a['score'] !== null ? ' &middot; ' . number_format($a['score'] * 100) . '%' : ''; ?></span>
                        <?php endif; ?>
                        <span class="adm-card__when"><?php echo $e($fmt($a['created_at'])); ?></span>
                    </div>
                    <div class="adm-card__acts">
                        <button type="button" class="adm-btn adm-btn--ok" data-mod="approve"><i class="fa-solid fa-check"></i> Approve</button>
                        <button type="button" class="adm-btn adm-btn--danger" data-mod="block"><i class="fa-solid fa-ban"></i> Block</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="adm-sec adm-panel" data-panel="reports">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Reports</h2>
            <span class="adm-sec__meta"><?php echo (int) $this->reports_open; ?> open</span>
        </div>
        <?php if (empty($this->reports_queue)): ?>
            <div class="adm-empty">
                <span class="adm-empty__ic"><i class="fa-solid fa-flag"></i></span>
                <p class="adm-empty__t">No open reports</p>
                <p class="adm-empty__x">User reports of content or creators will appear here for review.</p>
            </div>
        <?php else: ?>
        <div class="adm-table adm-table--reports">
            <div class="adm-table__head"><span>Reported</span><span>Reason</span><span>By</span><span>When</span><span></span></div>
            <div class="adm-table__body" id="admReports">
                <?php foreach ($this->reports_queue as $rp): ?>
                <div class="adm-rrow" data-report="<?php echo (int) $rp['id']; ?>" data-type="<?php echo $e($rp['target_type']); ?>">
                    <div class="adm-ucell adm-rcell--target">
                        <?php if ($rp['target_type'] === 'post'): ?>
                            <span class="adm-tag adm-tag--ppv">Post</span>
                            <span class="adm-rcell__what"><?php echo $e($rp['post_caption'] !== '' ? mb_substr($rp['post_caption'], 0, 70) : ('#' . $rp['target_id'])); ?></span>
                            <?php if ($rp['creator_handle'] !== ''): ?><a class="adm-rcell__who" href="/@<?php echo $e($rp['creator_handle']); ?>" target="_blank" rel="noopener">@<?php echo $e($rp['creator_handle']); ?></a><?php endif; ?>
                        <?php else: ?>
                            <span class="adm-tag adm-tag--flag">Creator</span>
                            <a class="adm-rcell__what" href="/@<?php echo $e($rp['creator_handle']); ?>" target="_blank" rel="noopener">@<?php echo $e($rp['creator_handle']); ?></a>
                        <?php endif; ?>
                    </div>
                    <div class="adm-ucell">
                        <span class="adm-rcell__reason"><?php echo $e($rp['reason_label']); ?></span>
                        <?php if ($rp['details'] !== ''): ?><span class="adm-rcell__detail" title="<?php echo $e($rp['details']); ?>"><?php echo $e(mb_substr($rp['details'], 0, 60)); ?></span><?php endif; ?>
                    </div>
                    <div class="adm-ucell adm-ucell--muted">@<?php echo $e($rp['reporter_handle']); ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($rp['created_at'], true)); ?></div>
                    <div class="adm-ucell adm-ucell--act">
                        <button type="button" class="adm-btn" data-report-action="dismiss">Dismiss</button>
                        <?php if ($rp['target_type'] === 'post'): ?>
                        <button type="button" class="adm-btn adm-btn--danger" data-report-action="remove">Remove</button>
                        <?php endif; ?>
                        <button type="button" class="adm-btn adm-btn--danger" data-report-action="suspend">Suspend</button>
                    </div>
                </div>
                <?php endforeach; ?>
                <p class="adm__none" id="admReportsNone" hidden>All reports resolved.</p>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <section class="adm-sec adm-panel" data-panel="verification">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Verification requests</h2>
            <span class="adm-sec__meta"><?php echo (int) $this->verif_pending; ?> pending</span>
        </div>
        <?php if (empty($this->verifications)): ?>
            <div class="adm-empty">
                <span class="adm-empty__ic"><i class="fa-solid fa-user-check"></i></span>
                <p class="adm-empty__t">No pending requests</p>
                <p class="adm-empty__x">Creators requesting verification will appear here for review.</p>
            </div>
        <?php else: ?>
        <div class="adm-table adm-table--verif">
            <div class="adm-table__head"><span>Creator</span><span>Legal name</span><span>Note</span><span>When</span><span></span></div>
            <div class="adm-table__body" id="admVerif">
                <?php foreach ($this->verifications as $v):
                    $vname = trim((string) ($v['name'] ?? '')); $vname = $vname !== '' ? $vname : ('@' . $v['u_name']);
                ?>
                <div class="adm-vrow" data-verif="<?php echo (int) $v['id']; ?>">
                    <div class="adm-ucell adm-ucell--user">
                        <span class="adm-uav"><?php echo $e($ini($vname)); ?></span>
                        <span class="adm-uinfo"><span class="adm-uinfo__name"><?php echo $e($vname); ?></span><span class="adm-uinfo__meta"><a href="/@<?php echo $e($v['u_name']); ?>" target="_blank" rel="noopener">@<?php echo $e($v['u_name']); ?></a></span></span>
                    </div>
                    <div class="adm-ucell"><?php echo $e($v['full_name'] !== '' ? $v['full_name'] : '—'); ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $v['note'] !== '' ? $e(mb_substr((string) $v['note'], 0, 80)) : '—'; ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($v['created_at'])); ?></div>
                    <div class="adm-ucell adm-ucell--act">
                        <button type="button" class="adm-btn adm-btn--ok" data-verif-action="approve">Approve</button>
                        <button type="button" class="adm-btn adm-btn--danger" data-verif-action="reject">Reject</button>
                    </div>
                </div>
                <?php endforeach; ?>
                <p class="adm__none" id="admVerifNone" hidden>No pending requests.</p>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <section class="adm-sec adm-panel" data-panel="sales">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Recent sales</h2>
            <span class="adm-sec__meta">
                <?php $rf = $this->refunds; if ((int) $rf['refund_count'] > 0): ?>
                    $<?php echo number_format(((int) $rf['refund_credits']) / 10, 2); ?> refunded &middot; <?php echo (int) $rf['refund_count']; ?> refund<?php echo (int) $rf['refund_count'] === 1 ? '' : 's'; ?>
                <?php else: ?>No refunds yet<?php endif; ?>
            </span>
        </div>
        <?php if (empty($this->sales)): ?>
            <div class="adm-empty">
                <span class="adm-empty__ic" style="background:#f2f0f9;color:#8b83c4;"><i class="fa-solid fa-receipt"></i></span>
                <p class="adm-empty__t">No sales yet</p>
                <p class="adm-empty__x">Pay-per-view and bundle purchases will appear here, refundable in one click.</p>
            </div>
        <?php else: ?>
        <div class="adm-table adm-table--sales">
            <div class="adm-table__head">
                <span>Buyer</span><span>Item</span><span>Type</span><span class="adm-r">Amount</span><span>Date</span><span></span>
            </div>
            <div class="adm-table__body" id="admSales">
                <?php foreach ($this->sales as $sale): ?>
                <div class="adm-srow" data-kind="<?php echo $e($sale['kind']); ?>" data-ref="<?php echo (int) $sale['ref_id']; ?>" data-fan="<?php echo (int) $sale['fan_id']; ?>">
                    <div class="adm-ucell adm-ucell--user">
                        <span class="adm-uav"><?php echo $e($ini($sale['fan_name'])); ?></span>
                        <span class="adm-uinfo"><span class="adm-uinfo__name">@<?php echo $e($sale['fan_handle']); ?></span></span>
                    </div>
                    <div class="adm-ucell adm-scell--item"><?php echo $e($sale['item'] !== '' ? $sale['item'] : 'Untitled'); ?></div>
                    <div class="adm-ucell"><span class="adm-tag adm-tag--<?php echo $sale['kind']; ?>"><?php echo $sale['kind'] === 'bundle' ? 'Bundle' : ($sale['kind'] === 'message' ? 'Message' : 'PPV'); ?></span></div>
                    <div class="adm-ucell adm-r adm-scell--amt">$<?php echo number_format(((int) $sale['credits']) / 10, 2); ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($sale['created_at'], true)); ?></div>
                    <div class="adm-ucell adm-ucell--act">
                        <button type="button" class="adm-btn adm-btn--danger" data-refund>Refund</button>
                    </div>
                </div>
                <?php endforeach; ?>
                <p class="adm__none" id="admSalesNone" hidden>All matching sales refunded.</p>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <?php if (!empty($this->chargebacks)): ?>
    <section class="adm-sec adm-panel" data-panel="sales">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Chargebacks</h2>
            <span class="adm-sec__meta">Disputed Stripe charges &middot; account auto-suspended</span>
        </div>
        <div class="adm-table adm-table--cb">
            <div class="adm-table__head"><span>Account</span><span>Reason</span><span class="adm-r">Amount</span><span>Status</span><span>Date</span></div>
            <div class="adm-table__body">
                <?php foreach ($this->chargebacks as $cb): ?>
                <div class="adm-cbrow">
                    <div class="adm-ucell"><?php echo $cb['handle'] ? '@' . $e($cb['handle']) : '<span class="adm-ucell--muted">Unmatched</span>'; ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($cb['reason'] ?: '—'); ?></div>
                    <div class="adm-ucell adm-r">$<?php echo number_format(((int) $cb['amount_cents']) / 100, 2); ?></div>
                    <div class="adm-ucell"><span class="adm-tag adm-tag--flag">Suspended</span></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($cb['created_at'], true)); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="adm-sec adm-panel" data-panel="users">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Users</h2>
            <div class="adm-usearch">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="admUserSearch" placeholder="Search name, @handle, or email" autocomplete="off" maxlength="80">
            </div>
        </div>
        <div class="adm-table">
            <div class="adm-table__head">
                <span>User</span><span>Role</span><span>Status</span><span>Joined</span><span>Last active</span><span></span>
            </div>
            <div class="adm-table__body" id="admUsers">
                <?php foreach ($users as $u):
                    $name  = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                    $name  = $name !== '' ? $name : ('@' . $u['u_name']);
                    $isMe  = ((int) $u['user_id'] === $me);
                    $isAdm = !empty($u['is_admin']);
                    $dis   = ($u['user_status'] === 'Disabled');
                    $search = mb_strtolower($name . ' @' . $u['u_name'] . ' ' . $u['user_email']);
                ?>
                <div class="adm-urow" data-uid="<?php echo (int) $u['user_id']; ?>" data-search="<?php echo $e($search); ?>">
                    <div class="adm-ucell adm-ucell--user">
                        <span class="adm-uav"><?php echo $e($ini($name)); ?></span>
                        <span class="adm-uinfo">
                            <span class="adm-uinfo__name"><?php echo $e($name); ?><?php if ($isAdm): ?> <span class="adm-tag adm-tag--admin">Admin</span><?php endif; ?></span>
                            <span class="adm-uinfo__meta">@<?php echo $e($u['u_name']); ?> &middot; <?php echo $e($u['user_email']); ?></span>
                        </span>
                    </div>
                    <div class="adm-ucell"><span class="adm-role"><?php echo $e($u['role_name'] ?: 'User'); ?></span></div>
                    <div class="adm-ucell">
                        <span class="adm-status adm-status--<?php echo $dis ? 'off' : 'on'; ?>"><span class="adm-status__dot"></span><?php echo $dis ? 'Suspended' : 'Active'; ?></span>
                    </div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($u['created_at'])); ?></div>
                    <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($u['last_active_at'], true)); ?></div>
                    <div class="adm-ucell adm-ucell--act">
                        <?php if ($isMe || $isAdm): ?>
                            <span class="adm-ucell--muted" style="font-size:.78rem;">—</span>
                        <?php elseif ($dis): ?>
                            <button type="button" class="adm-btn adm-btn--ok" data-status="Active">Reactivate</button>
                        <?php else: ?>
                            <button type="button" class="adm-btn adm-btn--danger" data-status="Disabled">Suspend</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <p class="adm__none" id="admUsersNone" hidden>No users match that search.</p>
            </div>
        </div>
    </section>
</div>

<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
