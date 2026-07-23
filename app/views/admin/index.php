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
            <div class="adm-kpi__top"><span class="adm-kpi__label">Gross revenue</span><span class="adm-kpi__ic"><i class="fa-solid fa-coins"></i></span></div>
            <div class="adm-kpi__val">$<?php echo number_format(((int) $s['revenue_credits']) / 10, 2); ?></div>
            <div class="adm-kpi__sub">pay-per-view + bundles</div>
        </div>
        <div class="adm-kpi">
            <div class="adm-kpi__top"><span class="adm-kpi__label">Subscriptions</span><span class="adm-kpi__ic"><i class="fa-solid fa-heart"></i></span></div>
            <div class="adm-kpi__val"><?php echo number_format((int) $s['active_subs']); ?></div>
            <div class="adm-kpi__sub">$<?php echo number_format(((int) $s['mrr_cents']) / 100, 2); ?>/mo recurring</div>
        </div>
        <div class="adm-kpi<?php echo $review > 0 ? ' adm-kpi--alert' : ''; ?>">
            <div class="adm-kpi__top"><span class="adm-kpi__label">Needs review</span><span class="adm-kpi__ic"><i class="fa-solid fa-shield-halved"></i></span></div>
            <div class="adm-kpi__val"><?php echo number_format($review); ?></div>
            <div class="adm-kpi__sub"><?php echo number_format((int) $s['mod_flagged']); ?> flagged &middot; <?php echo number_format((int) $s['mod_pending']); ?> unscanned</div>
        </div>
    </div>

    <section class="adm-sec">
        <div class="adm-sec__head">
            <h2 class="adm-sec__title">Moderation queue</h2>
            <span class="adm-sec__meta"><?php echo count($queue); ?> awaiting review</span>
        </div>
        <?php if (empty($queue)): ?>
            <div class="adm-empty">
                <span class="adm-empty__ic"><i class="fa-solid fa-circle-check"></i></span>
                <p class="adm-empty__t">Nothing to review</p>
                <p class="adm-empty__x">Flagged and unscanned content will appear here for approval.</p>
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

    <section class="adm-sec">
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
