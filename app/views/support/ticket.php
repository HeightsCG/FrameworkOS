<link rel="stylesheet" href="/css/support.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/support.css'); ?>">
<?php
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$fmt = $this->fmt; $t = $this->ticket;
$status_label = array('open' => 'Waiting on support', 'answered' => 'Support replied', 'closed' => 'Closed');
$requester = trim((string) $t['first_name'] . ' ' . (string) $t['last_name']);
$back = $this->staff_view ? '/admin?tab=support' : '/support';
?>
<div class="sup sup--thread" data-ticket="<?php echo (int) $t['id']; ?>">
    <a class="sup__back" href="<?php echo $e($back); ?>"><i class="fa-solid fa-arrow-left"></i> <?php echo $this->staff_view ? 'Support Queue' : 'All Requests'; ?></a>
    <header class="sup-thread__head">
        <div>
            <h1 class="sup__title"><?php echo $e($t['subject']); ?></h1>
            <p class="sup-thread__meta">
                <?php echo $e($this->categories[$t['category']] ?? 'Something else'); ?> &middot; Opened <?php echo $e($fmt($t['created_at'])); ?>
                <?php if ($this->staff_view): ?> &middot; <?php echo $e($requester !== '' ? $requester : '@' . $t['u_name']); ?> (@<?php echo $e($t['u_name']); ?>, <?php echo $e($t['user_email']); ?>)<?php endif; ?>
            </p>
        </div>
        <div class="sup-thread__acts">
            <span class="sup-badge sup-badge--<?php echo $e($t['status']); ?>"><?php echo $e($status_label[$t['status']] ?? $t['status']); ?></span>
            <?php if ($t['status'] === 'closed'): ?>
            <button type="button" class="btn btn-secondary" data-close="0">Reopen</button>
            <?php else: ?>
            <button type="button" class="btn btn-secondary" data-close="1">Close Request</button>
            <?php endif; ?>
        </div>
    </header>

    <div class="sup-card sup-conv">
        <ol class="sup-conv__list" id="supConv">
            <?php foreach ($this->messages as $m): $mine = ((int) $m['user_id'] === (int) $this->me); $name = $m['is_staff'] ? 'Support' : ($mine ? 'You' : '@' . $m['u_name']); ?>
            <li class="sup-msg<?php echo $m['is_staff'] ? ' sup-msg--staff' : ''; ?><?php echo $mine ? ' sup-msg--mine' : ''; ?>">
                <div class="sup-msg__head"><b><?php echo $e($name); ?></b><span><?php echo $e($fmt($m['created_at'])); ?></span></div>
                <div class="sup-msg__body"><?php echo nl2br($e($m['body'])); ?></div>
            </li>
            <?php endforeach; ?>
        </ol>
        <form class="sup-reply" id="supReply" novalidate>
            <div class="form-floating">
                <textarea class="form-control sup-reply__body" id="sup_reply" maxlength="5000" placeholder="Write a reply" required></textarea>
                <label for="sup_reply"><?php echo $this->staff_view ? 'Reply to the user' : 'Reply'; ?></label>
            </div>
            <button type="submit" class="btn btn-primary" id="supReplySend">Send Reply</button>
        </form>
    </div>
</div>
<script src="/js/support.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/support.js'); ?>"></script>
