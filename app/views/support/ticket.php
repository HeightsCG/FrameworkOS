<link rel="stylesheet" href="/css/support.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/support.css'); ?>">
<?php if ($this->staff_view): ?><link rel="stylesheet" href="/css/admin.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/admin.css'); ?>"><?php endif; ?>
<?php
/* Support request as one issue workspace (sw-*): title, description, activity and response on the left; request
   properties on the right, plus Diagnosis / Quick Fixes / Account / Requests for staff. Fix buttons: public/js/admin-user.js. */
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
<?php
$last_at = !empty($this->messages) ? end($this->messages)['created_at'] : $t['created_at'];
$topic = $this->categories[$t['category']] ?? 'Something else';
$u = ($staff && $this->requester) ? $this->requester : null;
?>
<div class="sw<?php echo $u ? ' sw--staff' : ''; ?> sup--thread" data-ticket="<?php echo (int) $t['id']; ?>">
    <header class="sw-bar">
        <nav class="sw-crumb" aria-label="Breadcrumb">
            <a href="<?php echo $staff ? '/admin/moderation?show=support' : '/support'; ?>"><i class="fa-solid fa-arrow-left"></i><?php echo $staff ? 'Support Queue' : 'All Requests'; ?></a>
            <i class="fa-solid fa-chevron-right sw-crumb__sep" aria-hidden="true"></i>
            <span>Request <?php echo (int) $t['id']; ?></span>
        </nav>
        <div class="sw-bar__acts">
            <button type="button" class="sw-btn" data-close="<?php echo $closed ? '0' : '1'; ?>"><i class="fa-solid <?php echo $closed ? 'fa-rotate-left' : 'fa-check'; ?>"></i><?php echo $closed ? 'Reopen' : 'Close Request'; ?></button>
        </div>
    </header>

    <div class="sw-body">
        <main class="sw-main">
            <h1 class="sw-title"><?php echo $e($t['subject']); ?></h1>
            <?php if ($first): ?><div class="sw-desc"><?php echo nl2br($e($first['body'])); ?></div><?php endif; ?>

            <section class="sw-activity" id="supConv" aria-labelledby="swActH">
                <h2 class="sw-h2" id="swActH">Activity</h2>
                <ol class="sw-feed">
                    <li class="sw-ev">
                        <span class="sw-ev__mk" aria-hidden="true"><i class="fa-solid fa-circle-dot"></i></span>
                        <p class="sw-ev__line"><b><?php echo $e($req_name); ?></b> opened this request <time><?php echo $e($fmt($t['created_at'])); ?></time></p>
                    </li>
                    <?php foreach ($replies as $m): ?>
                    <li class="sw-ev sw-ev--reply<?php echo $m['is_staff'] ? ' sw-ev--staff' : ''; ?>">
                        <span class="sw-ev__mk" aria-hidden="true"><i class="fa-solid <?php echo $m['is_staff'] ? 'fa-headset' : 'fa-reply'; ?>"></i></span>
                        <p class="sw-ev__line"><b><?php echo $e($name_of($m)); ?></b> <?php echo $m['is_staff'] && $staff ? 'replied as Support' : 'replied'; ?> <time><?php echo $e($fmt($m['created_at'])); ?></time></p>
                        <div class="sw-ev__text"><?php echo nl2br($e($m['body'])); ?></div>
                    </li>
                    <?php endforeach; ?>
                    <?php if ($closed): ?>
                    <li class="sw-ev sw-ev--closed">
                        <span class="sw-ev__mk" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                        <p class="sw-ev__line">Request closed <time><?php echo $e($fmt($t['closed_at'])); ?></time></p>
                    </li>
                    <?php endif; ?>
                </ol>
            </section>

            <form class="sw-compose" id="supReply" novalidate>
                <?php if ($u): ?>
                <div class="sw-ai" id="swAi" hidden>
                    <div class="sw-ai__head">
                        <span class="sw-ai__title"><i class="fa-solid fa-wand-magic-sparkles"></i>AI Assist</span>
                        <button type="button" class="sw-ai__x" id="swAiClose" aria-label="Close AI Assist"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <div class="sw-ai__ask">
                        <label class="sr-only" for="swAiNote">Key point to include</label>
                        <input type="text" id="swAiNote" maxlength="500" placeholder="Key point (optional)" autocomplete="off">
                        <button type="button" class="sw-btn sw-btn--sm" id="swAiDraft" data-ai="draft">Draft Options</button>
                    </div>
                    <div class="sw-ai__rework">
                        <span>Rework your reply</span>
                        <?php foreach (array('shorter' => 'Shorter', 'friendly' => 'Friendlier', 'detail' => 'More Detail', 'grammar' => 'Fix Grammar') as $k => $l): ?><button type="button" class="sw-chip" data-ai="<?php echo $k; ?>" disabled><?php echo $l; ?></button><?php endforeach; ?>
                    </div>
                    <div class="sw-ai__out" id="swAiOut" aria-live="polite"></div>
                </div>
                <?php endif; ?>
                <label class="sr-only" for="sup_reply">Your response</label>
                <textarea id="sup_reply" rows="4" maxlength="5000" placeholder="<?php echo $staff ? 'Write a response to ' . $e($req_name) : 'Add more detail or reply to support'; ?>"></textarea>
                <div class="sw-compose__bar">
                    <?php if ($u): ?><button type="button" class="sw-btn sw-btn--ghost sw-compose__ai" id="swAiToggle" aria-expanded="false" aria-controls="swAi"><i class="fa-solid fa-wand-magic-sparkles"></i>AI Assist</button><?php endif; ?>
                    <?php if ($staff && !$closed): ?><button type="button" class="sw-btn" id="supReplyClose">Send and Close</button><?php endif; ?>
                    <button type="submit" class="sw-btn sw-btn--primary" id="supReplySend">Send Reply</button>
                </div>
            </form>
        </main>

        <aside class="sw-side"<?php echo $u ? ' data-admin-user="' . (int) $u['user_id'] . '"' : ''; ?>>
            <dl class="sw-props">
                <div><dt>Status</dt><dd><span class="sup-status sup-status--<?php echo $e($t['status']); ?>"><span class="sup-status__dot"></span><?php echo $e($status_label[$t['status']] ?? $t['status']); ?></span></dd></div>
                <div><dt>Topic</dt><dd><?php echo $e($topic); ?></dd></div>
                <div><dt>Requester</dt><dd class="sw-props__who">
                    <?php if ($u): ?><span class="sw-av" style="<?php echo !empty($u['avatar_url']) ? 'background-image:url(\'' . $e($u['avatar_url']) . '\')' : ''; ?>"><?php echo empty($u['avatar_url']) ? $e($ini($req_name)) : ''; ?></span><a href="/admin/user/<?php echo (int) $u['user_id']; ?>"><?php echo $e($req_name); ?></a>
                    <?php else: ?><span class="sw-av"><?php echo $e($ini($req_name)); ?></span><span><?php echo $e($req_name); ?></span><?php endif; ?>
                </dd></div>
                <div><dt>Opened</dt><dd><?php echo $e($fmt($t['created_at'])); ?></dd></div>
                <div><dt>Last update</dt><dd><?php echo $e($fmt($closed && !empty($t['closed_at']) ? $t['closed_at'] : $last_at)); ?></dd></div>
                <div><dt>Replies</dt><dd><?php echo count($replies); ?></dd></div>
            </dl>

            <?php if ($u): $tier = Plan::tier_name($u); ?>
            <div class="sw-tabs" role="tablist" aria-label="Requester">
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
                        <?php if (!empty($fix['act'])): ?><button type="button" class="sw-btn sw-btn--sm sc-check__fix" data-act="<?php echo $e($fix['act']); ?>"<?php echo isset($fix['status']) ? ' data-status="' . $e($fix['status']) . '"' : ''; ?><?php echo isset($fix['cancel']) ? ' data-cancel="' . $e($fix['cancel']) . '"' : ''; ?>><?php echo $e($fix['label']); ?></button>
                        <?php elseif (!empty($fix['href'])): ?><a class="sw-btn sw-btn--sm sc-check__fix" href="<?php echo $e($fix['href']); ?>"><?php echo $e($fix['label']); ?></a><?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
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
                <dl class="sw-props sw-props--flush">
                    <div><dt>Username</dt><dd>@<?php echo $e($u['u_name']); ?></dd></div>
                    <div><dt>Email</dt><dd class="sw-props__long"><?php echo $e($u['user_email']); ?></dd></div>
                    <div><dt>Role</dt><dd><?php echo $e($u['role_name'] ?: 'User'); ?><?php echo $tier !== '' ? ', ' . $e($tier) . ' plan' : ''; ?></dd></div>
                    <div><dt>Credits</dt><dd><?php echo number_format((int) $u['credit_balance']); ?></dd></div>
                    <?php if (strtolower((string) $u['role_name']) === 'creator'): ?><div><dt>AI credits</dt><dd><?php echo number_format((int) $u['ai_credit_balance']); ?></dd></div><?php endif; ?>
                    <div><dt>Purchases</dt><dd><?php echo count($this->purchases); ?></dd></div>
                    <div><dt>Memberships</dt><dd><?php echo count($this->memberships); ?></dd></div>
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
            <?php endif; ?>
        </aside>
    </div>
</div>
<?php if ($staff && $this->requester): $u = $this->requester; include Main::app_path() . '/app/views/admin/_adjust_modal.php'; ?>
<script src="/js/admin-user.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-user.js'); ?>"></script>
<?php endif; ?>
<script src="/js/support.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/support.js'); ?>"></script>
