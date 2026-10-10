<?php include __DIR__ . '/_shell.php'; ?>
<?php /* /admin/users — one table panel. Search runs on the server (?q=) and narrows the rows on the page as you type. */
$users = (array) $this->users; $q = (string) $this->q; $me = (int) $this->me; ?>
<header class="adm-head">
    <div><h1 class="adm-head__title">Users</h1><p class="adm-head__sub"><?php echo $q !== '' ? count($users) . ' match' . (count($users) === 1 ? '' : 'es') . ' for “' . $e($q) . '”' : 'Newest ' . count($users) . ' accounts'; ?></p></div>
</header>
<section class="adm-box">
    <header class="adm-box__h">
        <form class="adm-search" method="get" action="/admin/users" role="search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" name="q" value="<?php echo $e($q); ?>" placeholder="Search name, handle or email" autocomplete="off" maxlength="80" aria-label="Search users" data-search-for="admUsers">
            <?php if ($q !== ''): ?><a class="adm-search__clear" href="/admin/users" aria-label="Clear search"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a><?php endif; ?>
        </form>
        <div class="adm-seg" role="tablist" aria-label="Status" data-filter-for="admUsers" data-filter-attr="data-status-f">
            <?php $cnt = array('all' => count($users), 'active' => 0, 'suspended' => 0, 'creator' => 0, 'admin' => 0);
            foreach ($users as $u) { $cnt[$u['user_status'] === 'Disabled' ? 'suspended' : 'active']++; if (strtolower((string) $u['role_name']) === 'creator') { $cnt['creator']++; } if (!empty($u['is_admin'])) { $cnt['admin']++; } } ?>
            <?php foreach (array('all' => 'All', 'active' => 'Active', 'suspended' => 'Suspended', 'creator' => 'Creators', 'admin' => 'Staff') as $k => $l): ?><button type="button" role="tab" class="adm-seg__b<?php echo $k === 'all' ? ' is-on' : ''; ?>" aria-selected="<?php echo $k === 'all' ? 'true' : 'false'; ?>" data-filter="<?php echo $k; ?>"><?php echo $l; ?> <b><?php echo $cnt[$k]; ?></b></button><?php endforeach; ?>
        </div>
    </header>
    <?php if (empty($users)): ?><?php echo adm_empty('No users match', 'fa-user'); ?><?php else: ?>
    <table class="adm-t" id="admUsers" data-sortable data-pager>
        <thead><tr><th data-sort="text">User</th><th data-sort="text">Email</th><th data-sort="text">Role</th><th data-sort="text">Status</th><th data-sort="text" data-sorted="desc">Joined</th><th data-sort="text">Last active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u):
            $name  = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); $name = $name !== '' ? $name : ('@' . $u['u_name']);
            $isMe  = ((int) $u['user_id'] === $me); $isAdm = !empty($u['is_admin']); $dis = ($u['user_status'] === 'Disabled');
            $role  = (string) ($u['role_name'] ?: 'User');
            $flt   = ($dis ? 'suspended' : 'active') . ' ' . strtolower($role) . ($isAdm ? ' admin' : ''); ?>
        <tr class="adm-urow adm-t__link" data-uid="<?php echo (int) $u['user_id']; ?>" data-href="/admin/user/<?php echo (int) $u['user_id']; ?>" data-status-f="<?php echo $e($flt); ?>" data-search="<?php echo $e(mb_strtolower($name . ' @' . $u['u_name'] . ' ' . $u['user_email'])); ?>">
            <td class="adm-t__who" data-value="<?php echo $e(mb_strtolower($name)); ?>"><?php echo adm_who($name, $u['u_name'], '/admin/user/' . (int) $u['user_id'], (string) ($u['avatar_url'] ?? '')); ?></td>
            <td class="adm-t__muted adm-t__trunc" title="<?php echo $e($u['user_email']); ?>"><?php echo $e($u['user_email']); ?></td>
            <td><?php echo adm_pill($role, $isAdm ? 'acc' : 'gray'); ?></td>
            <td data-status-cell><?php echo adm_pill($dis ? 'Suspended' : 'Active'); ?></td>
            <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($u['created_at']); ?>"><?php echo $e($fmt($u['created_at'])); ?></td>
            <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($u['last_active_at']); ?>"><?php echo $e($fmt($u['last_active_at'], true)); ?></td>
            <td class="adm-t__act"><?php echo adm_row_menu('Actions for @' . $u['u_name'], array(
                array('text' => 'Open account', 'href' => '/admin/user/' . (int) $u['user_id']),
                (!$isMe && !$isAdm && !$dis) ? array('text' => 'Sign in as user', 'attrs' => 'data-impersonate="' . (int) $u['user_id'] . '" data-handle="' . $e($u['u_name']) . '"') : null,
                (!$isMe && !$isAdm) ? array('text' => $dis ? 'Reactivate' : 'Suspend…', 'attrs' => 'data-status="' . ($dis ? 'Active' : 'Disabled') . '"', 'danger' => !$dis) : null,
            )); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="adm-quiet" id="admUsersNone" hidden>No users match.</p>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin.js'); ?>"></script>
