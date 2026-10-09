<?php
/* Audit log rows, shared by /admin > Audit Log and /admin/user/<id> > Audit. Needs $audit_rows, $fmt, $e; $audit_show_target (bool). */
$audit_hide = array('user_id', 'fan_id', 'id', 'result');
foreach ($audit_rows as $ar):
    $d = json_decode((string) $ar['details'], true) ?: array();
    $bits = array();
    foreach ($d as $k => $v) { if (in_array($k, $audit_hide, true) || $v === '') { continue; } $bits[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $v; }
    $admin = $ar['admin_name'] !== '' ? $ar['admin_name'] : '@' . $ar['admin_handle'];
    $target = $ar['target_user_id'] ? ($ar['target_name'] !== '' ? $ar['target_name'] : '@' . $ar['target_handle']) : '';
    $search = strtolower($admin . ' ' . $target . ' ' . ($ar['target_handle'] ?? '') . ' ' . (AuditModel::LABELS[$ar['action']] ?? $ar['action']) . ' ' . implode(' ', $bits) . ' ' . ($d['result'] ?? ''));
?>
<div class="adm-row adm-aurow" data-search="<?php echo $e($search); ?>">
    <span class="adm-ucell adm-ucell--muted"><?php echo $e($fmt($ar['created_at'], true)); ?></span>
    <span class="adm-ucell"><?php echo $e($admin); ?></span>
    <span class="adm-ucell"><b class="adm-aurow__act"><?php echo $e(AuditModel::LABELS[$ar['action']] ?? $ar['action']); ?></b><?php if (!empty($d['result'])): ?><span class="adm-aurow__res"><?php echo $e($d['result']); ?></span><?php endif; ?></span>
    <?php if (!empty($audit_show_target)): ?><span class="adm-ucell"><?php if ($target !== ''): ?><a class="adm-user-link" href="/admin/user/<?php echo (int) $ar['target_user_id']; ?>"><?php echo $e($target); ?></a><?php else: ?><span class="adm-ucell--muted"><?php echo $e($ar['target_type'] ? ucfirst($ar['target_type']) . ' ' . (int) $ar['target_id'] : '—'); ?></span><?php endif; ?></span><?php endif; ?>
    <span class="adm-ucell adm-ucell--muted adm-aurow__det"><?php echo $e(implode(' · ', $bits)); ?></span>
    <span class="adm-ucell adm-ucell--muted"><?php echo $e($ar['ip_address']); ?></span>
</div>
<?php endforeach; ?>
