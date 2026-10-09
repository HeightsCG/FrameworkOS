<?php include __DIR__ . '/_shell.php'; ?>
<?php /* /admin/people — accounts, newest first. The search runs on the server (?q=) and also narrows the rows already on the page. */
$users = (array) $this->users; $q = (string) $this->q; $me = (int) $this->me; ?>
<header class="adm-head">
    <div>
        <h1 class="adm-head__title">Users</h1>
        <p class="adm-head__sub"><?php echo $q !== '' ? count($users) . ' match' . (count($users) === 1 ? '' : 'es') . ' for “' . $e($q) . '”' : 'Newest ' . count($users) . ' accounts'; ?></p>
    </div>
    <form class="adm-search" method="get" action="/admin/people" role="search">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input type="search" name="q" id="admUserSearch" value="<?php echo $e($q); ?>" placeholder="Name, @handle or email" autocomplete="off" maxlength="80" aria-label="Search users">
        <?php if ($q !== ''): ?><a class="adm-search__clear" href="/admin/people" aria-label="Clear search"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a><?php endif; ?>
    </form>
</header>
<div class="adm-table adm-table--users">
    <div class="adm-table__head"><span>User</span><span>Role</span><span>Status</span><span>Joined</span><span>Last active</span><span></span></div>
    <div class="adm-table__body" id="admUsers">
        <?php foreach ($users as $u):
            $name  = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); $name = $name !== '' ? $name : ('@' . $u['u_name']);
            $isMe  = ((int) $u['user_id'] === $me); $isAdm = !empty($u['is_admin']); $dis = ($u['user_status'] === 'Disabled');
            $search = mb_strtolower($name . ' @' . $u['u_name'] . ' ' . $u['user_email']); ?>
        <div class="adm-row adm-urow" data-uid="<?php echo (int) $u['user_id']; ?>" data-search="<?php echo $e($search); ?>">
            <div class="adm-ucell adm-ucell--user">
                <span class="adm-uav"><?php echo $e($ini($name)); ?></span>
                <span class="adm-uinfo">
                    <a class="adm-uinfo__name adm-user-link" href="/admin/user/<?php echo (int) $u['user_id']; ?>"><?php echo $e($name); ?></a><?php if ($isAdm): ?> <span class="adm-tag adm-tag--admin">Admin</span><?php endif; ?>
                    <span class="adm-uinfo__meta">@<?php echo $e($u['u_name']); ?> · <?php echo $e($u['user_email']); ?></span>
                </span>
            </div>
            <div class="adm-ucell adm-ucell--muted"><?php echo $e($u['role_name'] ?: 'User'); ?></div>
            <div class="adm-ucell"><span class="adm-status adm-status--<?php echo $dis ? 'off' : 'on'; ?>"><span class="adm-status__dot"></span><?php echo $dis ? 'Suspended' : 'Active'; ?></span></div>
            <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($u['created_at'])); ?></div>
            <div class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($u['last_active_at'], true)); ?></div>
            <div class="adm-ucell adm-ucell--act">
                <?php echo adm_row_menu('Actions for @' . $u['u_name'], array(
                    array('text' => 'Open account', 'href' => '/admin/user/' . (int) $u['user_id']),
                    (!$isMe && !$isAdm && !$dis) ? array('text' => 'Sign in as user', 'attrs' => 'data-impersonate="' . (int) $u['user_id'] . '" data-handle="' . $e($u['u_name']) . '"') : null,
                    (!$isMe && !$isAdm) ? array('text' => $dis ? 'Reactivate' : 'Suspend…', 'attrs' => 'data-status="' . ($dis ? 'Active' : 'Disabled') . '"', 'danger' => !$dis) : null,
                )); ?>
            </div>
        </div>
        <?php endforeach; ?>
        <p class="adm-none" id="admUsersNone"<?php echo empty($users) ? '' : ' hidden'; ?>>No users match that search.</p>
    </div>
</div>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
