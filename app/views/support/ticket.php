<link rel="stylesheet" href="/css/support.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/support.css'); ?>">
<?php if ($this->staff_view): ?><link rel="stylesheet" href="/css/admin.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/admin.css'); ?>"><?php endif; ?>
<?php
/* Support request as a case file: the problem (description + replies + response) and, for staff, a Diagnosis
   panel of account checks for this topic with the fix beside each one. Fix buttons: public/js/admin-user.js. */
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$fmt = $this->fmt; $t = $this->ticket; $staff = $this->staff_view;
$status_label = $staff ? array('open' => 'Waiting on us', 'answered' => 'Replied', 'closed' => 'Closed') : array('open' => 'Waiting on support', 'answered' => 'Support replied', 'closed' => 'Closed');
$ini = function ($s) { $s = trim((string) $s); return $s === '' ? '?' : mb_strtoupper(mb_substr($s, 0, 1)); };
$closed = ($t['status'] === 'closed');
$req_name = trim((string) $t['first_name'] . ' ' . (string) $t['last_name']); if ($req_name === '') { $req_name = '@' . $t['u_name']; }
$name_of = function ($m) use ($staff) {
    if ($m['is_staff']) { return $staff ? (trim($m['first_name'] . ' ' . $m['last_name']) ?: 'Support') : 'Support'; }
    $n = trim($m['first_name'] . ' ' . $m['last_name']); return $n !== '' ? $n : '@' . $m['u_name'];
};
$first = $this->messages[0] ?? null;
$replies = array_slice($this->messages, 1);
?>
<div class="sc<?php echo $staff ? ' sc--staff' : ''; ?> sup--thread" data-ticket="<?php echo (int) $t['id']; ?>">
    <a class="sup__back" href="<?php echo $staff ? '/admin?tab=support' : '/support'; ?>"><i class="fa-solid fa-arrow-left"></i> <?php echo $staff ? 'Support Queue' : 'All Requests'; ?></a>

    <div class="sc__grid">
        <article class="sc-case">
            <header class="sc-case__head">
                <div class="sc-case__titles">
                    <h1 class="sc-case__title"><?php echo $e($t['subject']); ?></h1>
                    <div class="sc-case__meta">
                        <span class="sc-case__who"><span class="sc-av"><?php echo $e($ini($req_name)); ?></span><?php if ($staff): ?><a href="/admin/user/<?php echo (int) $t['user_id']; ?>"><?php echo $e($req_name); ?></a><?php else: ?><?php echo $e($req_name); ?><?php endif; ?></span>
                        <span class="sc-chip"><?php echo $e($this->categories[$t['category']] ?? 'Something else'); ?></span>
                        <span>Request <?php echo (int) $t['id']; ?>, opened <?php echo $e($fmt($t['created_at'])); ?></span>
                    </div>
                </div>
                <div class="sc-case__state">
                    <span class="sup-status sup-status--<?php echo $e($t['status']); ?>"><span class="sup-status__dot"></span><?php echo $e($status_label[$t['status']] ?? $t['status']); ?></span>
                    <button type="button" class="sup-btn" data-close="<?php echo $closed ? '0' : '1'; ?>"><?php echo $closed ? 'Reopen' : 'Close Request'; ?></button>
                </div>
            </header>

            <div class="sc-case__body" id="supConv">
                <?php if ($first): ?><div class="sc-desc"><?php echo nl2br($e($first['body'])); ?></div><?php endif; ?>

                <?php if (!empty($replies) || $closed): ?>
                <ol class="sc-trail">
                    <?php foreach ($replies as $m): ?>
                    <li class="sc-step<?php echo $m['is_staff'] ? ' sc-step--staff' : ''; ?>">
                        <p class="sc-step__who"><b><?php echo $e($name_of($m)); ?></b> <?php echo $m['is_staff'] && $staff ? 'replied as Support' : 'replied'; ?> <time><?php echo $e($fmt($m['created_at'])); ?></time></p>
                        <div class="sc-step__text"><?php echo nl2br($e($m['body'])); ?></div>
                    </li>
                    <?php endforeach; ?>
                    <?php if ($closed): ?><li class="sc-step sc-step--closed"><p class="sc-step__who">Request closed <time><?php echo $e($fmt($t['closed_at'])); ?></time></p></li><?php endif; ?>
                </ol>
                <?php endif; ?>
            </div>

            <form class="sc-reply" id="supReply" novalidate>
                <label class="sr-only" for="sup_reply">Your response</label>
                <textarea class="form-control" id="sup_reply" rows="3" maxlength="5000" placeholder="<?php echo $staff ? 'Write a response to ' . $e($req_name) : 'Add more detail or reply to support'; ?>"></textarea>
                <div class="sc-reply__acts">
                    <?php if ($staff && !$closed): ?><button type="button" class="sup-btn" id="supReplyClose">Send and Close</button><?php endif; ?>
                    <button type="submit" class="sup-btn sup-btn--primary" id="supReplySend">Send Reply</button>
                </div>
            </form>
        </article>

        <?php if ($staff && $this->requester): $u = $this->requester; $tier = PlanTiers::TIERS[(string) ($u['plan_tier'] ?? '')]['name'] ?? ''; ?>
        <aside class="sup-side" data-admin-user="<?php echo (int) $u['user_id']; ?>">
            <div class="sup-stabs" role="tablist" aria-label="Requester">
                <button type="button" class="sup-stab is-on" role="tab" aria-selected="true" data-stab="diagnosis">Diagnosis</button>
                <button type="button" class="sup-stab" role="tab" aria-selected="false" data-stab="fixes">Quick Fixes</button>
                <button type="button" class="sup-stab" role="tab" aria-selected="false" data-stab="details">Account</button>
                <button type="button" class="sup-stab" role="tab" aria-selected="false" data-stab="requests">Requests <b><?php echo count($this->others) + 1; ?></b></button>
            </div>

            <div class="sup-spanel" data-stab="diagnosis">
                <ul class="sc-checks">
                    <?php foreach ($this->diagnosis as $d): list($label, $value, $state, $fix) = $d; ?>
                    <li class="sc-check sc-check--<?php echo $e($state); ?>">
                        <?php if ($state !== 'info'): ?><span class="sc-check__ic" aria-hidden="true"><i class="fa-solid <?php echo $state === 'ok' ? 'fa-check' : 'fa-exclamation'; ?>"></i></span><?php endif; ?>
                        <span class="sc-check__txt"><b><?php echo $e($label); ?></b><span><?php echo $e($value); ?></span></span>
                        <?php if (!empty($fix['act'])): ?><button type="button" class="adm-btn sc-check__fix" data-act="<?php echo $e($fix['act']); ?>"<?php echo isset($fix['status']) ? ' data-status="' . $e($fix['status']) . '"' : ''; ?><?php echo isset($fix['cancel']) ? ' data-cancel="' . $e($fix['cancel']) . '"' : ''; ?>><?php echo $e($fix['label']); ?></button>
                        <?php elseif (!empty($fix['href'])): ?><a class="adm-btn sc-check__fix" href="<?php echo $e($fix['href']); ?>"><?php echo $e($fix['label']); ?></a><?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <a class="sc-profile" href="/admin/user/<?php echo (int) $u['user_id']; ?>">Open full profile</a>
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

            <div class="sup-spanel" data-stab="details" hidden>
                <div class="sup-who">
                    <span class="sup-who__av" style="<?php echo !empty($u['avatar_url']) ? 'background-image:url(\'' . $e($u['avatar_url']) . '\')' : ''; ?>"><?php echo empty($u['avatar_url']) ? $e($ini($req_name)) : ''; ?></span>
                    <div class="sup-who__txt">
                        <a class="sup-who__name" href="/admin/user/<?php echo (int) $u['user_id']; ?>"><?php echo $e($req_name); ?></a>
                        <span class="sup-who__sub">@<?php echo $e($u['u_name']); ?>, <?php echo $e($u['user_email']); ?></span>
                    </div>
                </div>
                <dl class="sup-facts">
                    <div><dt>Role</dt><dd><?php echo $e($u['role_name'] ?: 'User'); ?><?php echo $tier !== '' ? ', ' . $e($tier) . ' plan' : ''; ?></dd></div>
                    <div><dt>Credits</dt><dd><?php echo number_format((int) $u['credit_balance']); ?></dd></div>
                    <?php if (strtolower((string) $u['role_name']) === 'creator'): ?><div><dt>AI credits</dt><dd><?php echo number_format((int) $u['ai_credit_balance']); ?></dd></div><?php endif; ?>
                    <div><dt>Purchases</dt><dd><?php echo count($this->purchases); ?></dd></div>
                    <div><dt>Active memberships</dt><dd><?php echo count($this->memberships); ?></dd></div>
                    <div><dt>Last active</dt><dd><?php echo $e($fmt($u['last_active_at'])); ?></dd></div>
                    <div><dt>Joined</dt><dd><?php echo $e($fmt($u['created_at'])); ?></dd></div>
                    <div><dt>Time zone</dt><dd><?php echo $e($u['content_timezone'] ?: 'UTC'); ?></dd></div>
                </dl>
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
