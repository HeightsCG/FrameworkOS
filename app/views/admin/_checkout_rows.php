<?php
/* Checkout attempt rows (Admin > Billing > Checkout attempts, Today). Needs $co_rows, plus the shell's $e, $fmt, $usd.
   Each row carries data-cstatus for the status filter. */
$co_tone = array('succeeded' => 'ok', 'failed' => 'bad', 'abandoned' => 'warn', 'card_entered' => 'gray', 'opened' => 'gray');
foreach ((array) $co_rows as $ca):
    $cn = trim($ca['first_name'] . ' ' . $ca['last_name']); $cn = $cn !== '' ? $cn : '@' . $ca['u_name'];
    $cst = (string) $ca['status'];
    $why = (string) ($ca['failure_reason'] ?? '');
    if ($cst === 'succeeded') { $why = (int) $ca['declines'] > 0 ? 'After ' . (int) $ca['declines'] . ' decline' . ((int) $ca['declines'] === 1 ? '' : 's') . ($why !== '' ? ': ' . $why : '') : ''; }
    if ($cst === 'opened') { $why = ''; }
    $code = (string) ($ca['failure_code'] ?? ''); ?>
<tr class="adm-t__link" data-href="/admin/user/<?php echo (int) $ca['user_id']; ?>" data-cstatus="<?php echo $e($cst); ?>">
    <td class="adm-t__who"><?php echo adm_who($cn, $ca['u_name'], '/admin/user/' . (int) $ca['user_id'], '', (string) $ca['user_email']); ?></td>
    <td class="adm-t__nowrap"><?php echo $e(CheckoutAttempts::item_label($ca)); ?></td>
    <td class="adm-r adm-t__nowrap adm-t__num" data-value="<?php echo (int) $ca['amount_cents']; ?>"><?php echo $e($usd($ca['amount_cents'])); ?><?php if ((int) $ca['recurring_cents'] > 0 && (int) $ca['recurring_cents'] !== (int) $ca['amount_cents']): ?><span class="adm-t__sub">then <?php echo $e($usd($ca['recurring_cents'])); ?> / month</span><?php endif; ?></td>
    <td class="adm-t__muted adm-t__nowrap" data-value="<?php echo $e($ca['opened_at']); ?>"><?php echo $e($fmt($ca['opened_at'], true)); ?></td>
    <td><?php echo adm_pill(CheckoutAttempts::label($cst), $co_tone[$cst] ?? 'gray'); ?></td>
    <td class="adm-t__muted adm-t__why" title="<?php echo $e($why . ($code !== '' ? ' (' . $code . ')' : '')); ?>"><?php echo $why !== '' ? $e($why) : '—'; ?></td>
</tr>
<?php endforeach; ?>
