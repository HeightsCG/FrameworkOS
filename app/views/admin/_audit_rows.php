<?php
/* Audit log rows, shared by /admin/system and /admin/user/<id> > Audit. Needs $audit_rows, $fmt, $e; $audit_show_target (bool).
   Staff and account sit on one line; when the actor is the target, the target is left out. */
$audit_hide = array('user_id', 'fan_id', 'id', 'result');
foreach ($audit_rows as $ar):
    $d = json_decode((string) $ar['details'], true) ?: array();
    $bits = array();
    foreach ($d as $k => $v) { if (in_array($k, $audit_hide, true) || $v === '') { continue; } $bits[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $v; }
    $admin = $ar['admin_name'] !== '' ? $ar['admin_name'] : '@' . $ar['admin_handle'];
    $same = (int) $ar['target_user_id'] === (int) $ar['admin_id'];
    $target = ($ar['target_user_id'] && !$same) ? ($ar['target_name'] !== '' ? $ar['target_name'] : '@' . $ar['target_handle']) : '';
    $label = AuditModel::LABELS[$ar['action']] ?? $ar['action'];
    $search = strtolower($admin . ' ' . $target . ' ' . ($ar['target_handle'] ?? '') . ' ' . $label . ' ' . implode(' ', $bits) . ' ' . ($d['result'] ?? ''));
?>
<tr class="adm-aurow" data-search="<?php echo $e($search); ?>">
    <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($ar['created_at']); ?>"><?php echo $e($fmt($ar['created_at'], true)); ?></td>
    <td class="adm-t__nowrap adm-t__trunc"><?php echo $e($admin); ?><?php if (!empty($audit_show_target) && $target !== ''): ?> <span class="adm-t__muted">→</span> <a href="/admin/user/<?php echo (int) $ar['target_user_id']; ?>"><?php echo $e($target); ?></a><?php endif; ?></td>
    <td class="adm-t__main adm-t__nowrap"><?php echo $e($label); ?><?php if (!empty($d['result'])): ?><span class="adm-t__sub"><?php echo $e($d['result']); ?></span><?php endif; ?></td>
    <td class="adm-t__muted adm-t__trunc" title="<?php echo $e(implode(' · ', $bits)); ?>"><?php echo $e(implode(' · ', $bits)); ?></td>
    <td class="adm-t__muted adm-t__nowrap"><?php echo $e($ar['ip_address']); ?></td>
</tr>
<?php endforeach; ?>
