<link rel="stylesheet" href="/css/support.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/support.css'); ?>">
<?php if ($this->staff_view): ?><link rel="stylesheet" href="/css/admin.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/admin.css'); ?>"><?php endif; ?>
<?php
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$fmt = $this->fmt; $t = $this->ticket; $staff = $this->staff_view;
$status_label = $staff ? array('open' => 'Waiting on us', 'answered' => 'Replied', 'closed' => 'Closed') : array('open' => 'Waiting on support', 'answered' => 'Support replied', 'closed' => 'Closed');
$ini = function ($s) { $s = trim((string) $s); return $s === '' ? '?' : mb_strtoupper(mb_substr($s, 0, 1)); };
$req_name = trim((string) $t['first_name'] . ' ' . (string) $t['last_name']);
$closed = ($t['status'] === 'closed');
?>
<div class="sup sup--thread<?php echo $staff ? ' sup--staff' : ''; ?>" data-ticket="<?php echo (int) $t['id']; ?>">
    <a class="sup__back" href="<?php echo $staff ? '/admin?tab=support' : '/support'; ?>"><i class="fa-solid fa-arrow-left"></i> <?php echo $staff ? 'Support Queue' : 'All Requests'; ?></a>

    <header class="sup-th">
        <div class="sup-th__main">
            <h1 class="sup__title"><?php echo $e($t['subject']); ?></h1>
            <p class="sup-th__meta">
                <span class="sup-status sup-status--<?php echo $e($t['status']); ?>"><span class="sup-status__dot"></span><?php echo $e($status_label[$t['status']] ?? $t['status']); ?></span>
                <span><?php echo $e($this->categories[$t['category']] ?? 'Something else'); ?></span>
                <span>Request #<?php echo (int) $t['id']; ?></span>
                <span>Opened <?php echo $e($fmt($t['created_at'])); ?></span>
            </p>
        </div>
        <button type="button" class="sup-btn" data-close="<?php echo $closed ? '0' : '1'; ?>"><?php echo $closed ? 'Reopen' : 'Close Request'; ?></button>
    </header>

    <div class="sup-work">
        <div class="sup-work__main">
<?php
$first = $this->messages[0] ?? null;
$rest  = array_slice($this->messages, 1);
$name_of = function ($m) use ($staff) {
    if ($m['is_staff']) { return $staff ? (trim($m['first_name'] . ' ' . $m['last_name']) ?: 'Support') : 'Support'; }
    $n = trim($m['first_name'] . ' ' . $m['last_name']); return $n !== '' ? $n : '@' . $m['u_name'];
};
?>
            <div class="sup-doc" id="supConv">
                <?php if ($first): ?>
                <section class="sup-desc">
                    <p class="sup-desc__by"><b><?php echo $e($name_of($first)); ?></b> opened this request &middot; <?php echo $e($fmt($first['created_at'])); ?></p>
                    <div class="sup-desc__body"><?php echo nl2br($e($first['body'])); ?></div>
                </section>
                <?php endif; ?>

                <section class="sup-act">
                    <h2 class="sup-act__h">Activity</h2>
                    <ol class="sup-act__list">
                        <li class="sup-ev sup-ev--event"><span class="sup-ev__dot"></span><div class="sup-ev__line"><b><?php echo $e($first ? $name_of($first) : ''); ?></b> opened this request<time><?php echo $e($fmt($t['created_at'])); ?></time></div></li>
                        <?php foreach ($rest as $m): ?>
                        <li class="sup-ev<?php echo $m['is_staff'] ? ' sup-ev--staff' : ''; ?>">
                            <span class="sup-ev__dot"></span>
                            <div class="sup-ev__line"><b><?php echo $e($name_of($m)); ?></b> <?php echo $m['is_staff'] ? ($staff ? 'replied as Support' : 'replied') : 'replied'; ?><time><?php echo $e($fmt($m['created_at'])); ?></time></div>
                            <div class="sup-ev__text"><?php echo nl2br($e($m['body'])); ?></div>
                        </li>
                        <?php endforeach; ?>
                        <?php if ($t['status'] === 'closed' && !empty($t['closed_at'])): ?>
                        <li class="sup-ev sup-ev--event"><span class="sup-ev__dot sup-ev__dot--closed"></span><div class="sup-ev__line">Request closed<time><?php echo $e($fmt($t['closed_at'])); ?></time></div></li>
                        <?php endif; ?>
                    </ol>
                </section>
            </div>

            <form class="sup-compose" id="supReply" novalidate>
                <label class="sup-compose__label" for="sup_reply">Add a Response</label>
                <textarea class="form-control" id="sup_reply" rows="4" maxlength="5000"></textarea>
                <div class="sup-compose__acts">
                    <?php if ($staff && !$closed): ?><button type="button" class="sup-btn" id="supReplyClose">Send and Close</button><?php endif; ?>
                    <button type="submit" class="sup-btn sup-btn--primary" id="supReplySend">Send Reply</button>
                </div>
            </form>
        </div>

        <?php if ($staff && $this->requester): $u = $this->requester; $tier = PlanTiers::TIERS[(string) ($u['plan_tier'] ?? '')]['name'] ?? ''; ?>
        <aside class="sup-side" data-admin-user="<?php echo (int) $u['user_id']; ?>">
            <div class="sup-side__who">
                <div class="sup-who">
                    <span class="sup-who__av" style="<?php echo !empty($u['avatar_url']) ? 'background-image:url(\'' . $e($u['avatar_url']) . '\')' : ''; ?>"><?php echo empty($u['avatar_url']) ? $e($ini($req_name !== '' ? $req_name : $u['u_name'])) : ''; ?></span>
                    <div class="sup-who__txt">
                        <a class="sup-who__name" href="/admin/user/<?php echo (int) $u['user_id']; ?>"><?php echo $e($req_name !== '' ? $req_name : '@' . $u['u_name']); ?></a>
                        <span class="sup-who__sub">@<?php echo $e($u['u_name']); ?> &middot; <?php echo $e($u['user_email']); ?></span>
                    </div>
                </div>
                <div class="sup-who__tags">
                    <?php if ((string) $u['user_status'] === 'Disabled'): ?><span class="adm-pill adm-pill--bad">Suspended</span><?php else: ?><span class="adm-pill adm-pill--ok">Active</span><?php endif; ?>
                    <span class="adm-pill"><?php echo $e($u['role_name'] ?: 'User'); ?></span>
                    <?php if ($tier !== ''): ?><span class="adm-pill adm-pill--violet"><?php echo $e($tier); ?> plan</span><?php endif; ?>
                    <?php if (empty($u['email_verified'])): ?><span class="adm-pill adm-pill--warn">Email not verified</span><?php endif; ?>
                </div>
            </div>

            <div class="sup-stabs" role="tablist" aria-label="Requester">
                <button type="button" class="sup-stab is-on" role="tab" aria-selected="true" data-stab="details">Details</button>
                <button type="button" class="sup-stab" role="tab" aria-selected="false" data-stab="fixes">Quick Fixes</button>
                <button type="button" class="sup-stab" role="tab" aria-selected="false" data-stab="requests">Requests <b><?php echo count($this->others) + 1; ?></b></button>
            </div>

            <div class="sup-spanel" data-stab="details">
                <?php $is_cr = strtolower((string) ($u['role_name'] ?? '')) === 'creator'; ?>
                <dl class="sup-facts">
                    <div><dt>Credits</dt><dd>$<?php echo number_format(((int) $u['credit_balance']) / 10, 2); ?> <span class="sup-facts__sub"><?php echo number_format((int) $u['credit_balance']); ?> credits</span></dd></div>
                    <?php if ($is_cr): ?><div><dt>AI credits</dt><dd><?php echo number_format((int) $u['ai_credit_balance']); ?></dd></div><?php endif; ?>
                    <div><dt>Purchases</dt><dd><?php echo count($this->purchases); ?></dd></div>
                    <div><dt>Active memberships</dt><dd><?php echo count($this->memberships); ?></dd></div>
                    <div><dt>Two-step sign-in</dt><dd><?php $m2 = array(); if (!empty($u['mfa_totp_enabled'])) { $m2[] = 'Authenticator app'; } if (!empty($u['mfa_email_enabled'])) { $m2[] = 'Email codes'; } echo $e($m2 ? implode(', ', $m2) : 'Off'); ?></dd></div>
                    <div><dt>Email</dt><dd><?php echo !empty($u['email_verified']) ? 'Verified' : 'Not verified'; ?></dd></div>
                    <?php if ($is_cr): ?><div><dt>Plan</dt><dd><?php echo $e($tier !== '' ? $tier . ' (' . ucfirst((string) $u['subscription_status']) . ')' : 'None'); ?></dd></div><?php endif; ?>
                    <div><dt>Last active</dt><dd><?php echo $e($fmt($u['last_active_at'])); ?></dd></div>
                    <div><dt>Joined</dt><dd><?php echo $e($fmt($u['created_at'])); ?></dd></div>
                    <div><dt>Time zone</dt><dd><?php echo $e($u['content_timezone'] ?: 'UTC'); ?></dd></div>
                </dl>
            </div>

            <div class="sup-spanel" data-stab="fixes" hidden>
                <div class="sup-fix">
                    <button type="button" class="sup-fix__btn" data-act="adjust"><i class="fa-solid fa-plus-minus"></i><span>Adjust Balance</span></button>
                    <button type="button" class="sup-fix__btn" data-act="password"><i class="fa-solid fa-key"></i><span>Send Password Reset</span></button>
                    <button type="button" class="sup-fix__btn" data-act="mfa_reset"><i class="fa-solid fa-shield-halved"></i><span>Reset Two-Step Sign-In</span></button>
                    <?php if (empty($u['email_verified'])): ?><button type="button" class="sup-fix__btn" data-act="verify"><i class="fa-solid fa-envelope-circle-check"></i><span>Mark Email Verified</span></button><?php endif; ?>
                    <button type="button" class="sup-fix__btn" data-act="resend"><i class="fa-solid fa-envelope"></i><span>Resend Verification Email</span></button>
                    <a class="sup-fix__btn" href="/admin/user/<?php echo (int) $u['user_id']; ?>?tab=purchases"><i class="fa-solid fa-rotate-left"></i><span>Refund a Purchase</span><b><?php echo count($this->purchases); ?></b></a>
                    <a class="sup-fix__btn" href="/admin/user/<?php echo (int) $u['user_id']; ?>?tab=memberships"><i class="fa-solid fa-heart"></i><span>Manage Memberships</span><b><?php echo count($this->memberships); ?></b></a>
                    <a class="sup-fix__btn" href="/admin/user/<?php echo (int) $u['user_id']; ?>"><i class="fa-solid fa-user"></i><span>Open Full Profile</span></a>
                </div>
            </div>

            <div class="sup-spanel" data-stab="requests" hidden>
                <ul class="sup-others">
                    <li><a class="is-current" href="/support/ticket/<?php echo (int) $t['id']; ?>"><span><?php echo $e($t['subject']); ?></span><span class="sup-status sup-status--<?php echo $e($t['status']); ?>"><span class="sup-status__dot"></span><?php echo $e($status_label[$t['status']] ?? ''); ?></span></a></li>
                    <?php foreach ($this->others as $o): ?>
                    <li><a href="/support/ticket/<?php echo (int) $o['id']; ?>"><span><?php echo $e($o['subject']); ?></span><span class="sup-status sup-status--<?php echo $e($o['status']); ?>"><span class="sup-status__dot"></span><?php echo $e($status_label[$o['status']] ?? ''); ?></span></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </aside>
        <?php endif; ?>
    </div>
</div>
<?php if ($staff && $this->requester): $u = $this->requester; include Main::app_path() . '/app/views/admin/_adjust_modal.php'; ?>
<script src="/js/admin-user.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-user.js'); ?>"></script>
<?php endif; ?>
<script src="/js/support.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/support.js'); ?>"></script>
