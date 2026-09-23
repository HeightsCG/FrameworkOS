<?php
/** Topic-specific account checks for a support request (SupportController view + SupportAssist). */
class SupportDiagnosis {

    /**
     * Account checks relevant to the request's topic, shown in the staff Diagnosis panel and given to AI Assist as facts. Each check:
     * label, value, state (ok|warn|info), and an optional fix: array('act' => data-act, 'label' => ...) or array('href' => ..., 'label' => ...).
     */
    public static function checks($topic, array $u, AdminModel $am, array $purchases, array $memberships, callable $fmt){
        $uid = (int) $u['user_id']; $c = array();
        $money = function ($cr) { $n = (int) $cr; return number_format($n) . ' ' . (abs($n) === 1 ? 'credit' : 'credits'); };   // credits, never dollars
        $since = function ($rows, $hours) { $n = 0; $cut = time() - $hours * 3600; foreach ($rows as $r) { if (strtotime($r['created_at'] . ' UTC') >= $cut) { $n++; } } return $n; };

        // Always: can they get in at all?
        $susp = ((string) $u['user_status'] === 'Disabled');
        $c[] = array('Account', $susp ? 'Suspended' : 'Active', $susp ? 'warn' : 'ok', $susp ? array('act' => 'status', 'status' => 'Active', 'label' => 'Reactivate') : null);
        $ver = !empty($u['email_verified']);
        $c[] = array('Email', $ver ? 'Verified' : 'Not verified, so they can\'t sign in', $ver ? 'ok' : 'warn', $ver ? null : array('act' => 'verify', 'label' => 'Mark Verified'));

        $signins = $am->sign_in_history((string) $u['u_name'], (string) $u['user_email'], 100);
        $failed  = array_filter($signins, function ($r) { return $r['action'] === 'login'; });
        $badmfa  = array_filter($signins, function ($r) { return $r['action'] === 'mfa'; });
        $tx = (array) (new CreditsModel())->get_transactions($uid, 100);

        if ($topic === 'account') {
            $apps = array(); if (!empty($u['mfa_totp_enabled'])) { $apps[] = 'authenticator app'; } if (!empty($u['mfa_email_enabled'])) { $apps[] = 'email codes'; }
            $c[] = array('Two-step sign-in', $apps ? 'On (' . implode(', ', $apps) . '), ' . (int) $u['backup_codes_left'] . ' backup codes left' : 'Off', $apps ? 'warn' : 'ok', $apps ? array('act' => 'mfa_reset', 'label' => 'Reset') : null);
            $n = $since($failed, 24);
            $c[] = array('Failed sign-ins, last 24 hours', (string) $n, $n >= 3 ? 'warn' : 'ok', null);
            $n2 = $since($badmfa, 24);
            $c[] = array('Wrong two-step codes, last 24 hours', (string) $n2, $n2 >= 1 ? 'warn' : 'ok', null);
        } elseif ($topic === 'payments') {
            $buys = array_values(array_filter($tx, function ($r) { return $r['type'] === 'purchase'; }));
            $dupe = false;
            for ($i = 1; $i < count($buys); $i++) { if ((int) $buys[$i]['credits'] === (int) $buys[$i - 1]['credits'] && abs(strtotime($buys[$i]['created_at']) - strtotime($buys[$i - 1]['created_at'])) <= 900) { $dupe = true; break; } }
            $c[] = array('Credit balance', $money($u['credit_balance']), 'info', array('act' => 'adjust', 'label' => 'Adjust'));
            $last = $buys[0] ?? null;
            $c[] = array('Last credit purchase', $last ? $money($last['credits']) . ', ' . $fmt($last['created_at']) : 'None', 'info', null);
            $c[] = array('Duplicate purchases', $dupe ? 'Same amount bought twice within 15 minutes' : 'None found', $dupe ? 'warn' : 'ok', null);
            $c[] = array('Purchases', count($purchases) . ' one-time', 'info', count($purchases) ? array('href' => '/admin/user/' . $uid . '?tab=purchases', 'label' => 'Refund') : null);
            $c[] = array('Automatic top-up', !empty($u['autoreplenish_enabled']) ? 'On' : 'Off', 'info', null);
        } elseif ($topic === 'payouts') {
            $conn = (string) ($u['stripe_connect_account_id'] ?? '') !== '';
            $c[] = array('Payout account', $conn ? 'Connected' : 'Not set up', $conn ? 'ok' : 'warn', null);
            $c[] = array('Balance owed', $money($u['credit_balance']), 'info', null);
            $po = array_values(array_filter($tx, function ($r) { return $r['type'] === 'payout'; }));
            $c[] = array('Last cash-out', $po ? $money(abs((int) $po[0]['credits'])) . ', ' . $fmt($po[0]['created_at']) : 'None yet', 'info', null);
        } elseif ($topic === 'content') {
            $c[] = array('Purchases', count($purchases) . ' one-time', 'info', count($purchases) ? array('href' => '/admin/user/' . $uid . '?tab=purchases', 'label' => 'Refund') : null);
            $c[] = array('Active memberships', (string) count($memberships), 'info', count($memberships) ? array('href' => '/admin/user/' . $uid . '?tab=memberships', 'label' => 'Manage') : null);
            $c[] = array('Adult content', !empty($u['adult_content_enabled']) ? 'Shown' : 'Hidden, so adult posts stay hidden to them', 'info', null);
        } elseif ($topic === 'billing') {
            $tier = Plan::tier_name($u);   // Free for a creator with no paid plan
            if ($tier !== '') {
                $c[] = array('Creator plan', $tier . ($u['subscription_status'] ? ', ' . ucfirst((string) $u['subscription_status']) : ''), Plan::has_paid_plan($u) || Plan::tier($u) === PlanTiers::FREE_KEY ? 'ok' : 'warn', null);
                if (Plan::has_paid_plan($u)) $c[] = array('Renews', !empty($u['subscription_cancel_at_period_end']) ? 'Cancels on ' . $fmt($u['subscription_current_period_end']) : $fmt($u['subscription_current_period_end']), !empty($u['subscription_cancel_at_period_end']) ? 'warn' : 'info',
                    array('act' => 'plan', 'cancel' => !empty($u['subscription_cancel_at_period_end']) ? '0' : '1', 'label' => !empty($u['subscription_cancel_at_period_end']) ? 'Resume' : 'Cancel'));
            }
            $c[] = array('Active memberships', (string) count($memberships), 'info', count($memberships) ? array('href' => '/admin/user/' . $uid . '?tab=memberships', 'label' => 'Manage') : null);
        } elseif ($topic === 'social') {
            $acc = $am->social_accounts($uid);
            $c[] = array('Connected accounts', $acc ? implode(', ', array_map(function ($a) { return ucfirst($a['platform']); }, $acc)) : 'None', $acc ? 'ok' : 'warn', null);
            $tier = Plan::tier_name($u);   // Free for a creator with no paid plan
            $c[] = array('Creator plan', $tier !== '' ? $tier . ($u['subscription_status'] ? ', ' . ucfirst((string) $u['subscription_status']) : '') : 'None: not a creator account', $tier !== '' ? 'ok' : 'warn', null);
        } else {
            $c[] = array('Credit balance', $money($u['credit_balance']), 'info', null);
            $c[] = array('Purchases', (string) count($purchases), 'info', null);
        }
        return $c;
    }
}
