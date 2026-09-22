<link rel="stylesheet" href="/css/support.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/support.css'); ?>">
<?php
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$fmt = $this->fmt;
$status_label = array('open' => 'Waiting on support', 'answered' => 'Support replied', 'closed' => 'Closed');
$faq = array(
    array('How do I change my username, email or password?', 'Go to Settings from the menu at the top right. Account holds your username, profile photo and details; Security holds your password and two-step sign-in.', '/account/settings'),
    array('How do I buy credits or turn on automatic top-ups?', 'Open Settings, then Wallet. You can buy credits there and set a balance at which more credits are bought automatically.', '/account/settings?section=wallet'),
    array('How do I cancel a membership?', 'Open Settings, then My Subscriptions, and cancel the membership. You keep access until the end of the period you paid for.', '/account/settings?section=subscriptions'),
    array('Where is something I bought?', 'Everything you unlock or buy is kept on your Purchases page.', '/purchases'),
    array('How do I stop someone contacting me?', 'Block them from their profile or from your inbox. Blocked users can\'t see your content or message you. You can review blocks in Settings, then Blocked Users.', '/account/settings?section=blocked'),
    array('How do creators get paid?', 'Earnings collect in the creator\'s balance and can be cashed out to their bank account once payout setup is complete, from Settings, then Wallet.', '/account/settings?section=wallet'),
);
?>
<div class="sup">
    <header class="sup__head">
        <h1 class="sup__title">Support</h1>
    </header>

    <div class="sup__grid">
        <section class="sup-card sup-new" aria-labelledby="sup_new_h">
            <h2 class="sup-card__title" id="sup_new_h">New Request</h2>
            <form id="supNew" novalidate>
                <div class="form-floating mb-3">
                    <select class="form-select" id="sup_category" name="category" required>
                        <option value="" selected disabled>Choose a topic</option>
                        <?php foreach ($this->categories as $k => $label): ?><option value="<?php echo $e($k); ?>"><?php echo $e($label); ?></option><?php endforeach; ?>
                    </select>
                    <label for="sup_category">Topic</label>
                </div>
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="sup_subject" name="subject" maxlength="190" placeholder="Card declined when buying credits" required>
                    <label for="sup_subject">Subject</label>
                </div>
                <div class="form-floating mb-3">
                    <textarea class="form-control sup-new__body" id="sup_body" name="body" maxlength="5000" placeholder="What happened, and what you expected" required></textarea>
                    <label for="sup_body">Message</label>
                </div>
                <button type="submit" class="btn btn-primary" id="supSend">Send Request</button>
            </form>
        </section>

        <section class="sup-card sup-list" aria-labelledby="sup_list_h">
            <h2 class="sup-card__title" id="sup_list_h">Your Requests</h2>
            <?php if (empty($this->tickets)): ?>
            <div class="sup-empty">
                <span class="sup-empty__ic"><i class="fa-solid fa-life-ring"></i></span>
                <p class="sup-empty__t">No Requests Yet</p>
            </div>
            <?php else: ?>
            <div class="sup-list__body">
                <?php foreach ($this->tickets as $t): ?>
                <a class="sup-row" href="/support/ticket/<?php echo (int) $t['id']; ?>">
                    <span class="sup-row__main">
                        <span class="sup-row__subject"><?php echo $e($t['subject']); ?></span>
                        <span class="sup-row__meta"><?php echo $e($this->categories[$t['category']] ?? 'Something else'); ?> &middot; <?php echo $e($fmt($t['last_message_at'])); ?></span>
                    </span>
                    <span class="sup-badge sup-badge--<?php echo $e($t['status']); ?>"><?php echo $e($status_label[$t['status']] ?? $t['status']); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="sup-card sup-faq" aria-labelledby="sup_faq_h">
        <h2 class="sup-card__title" id="sup_faq_h">Quick Answers</h2>
        <div class="sup-faq__list">
            <?php foreach ($faq as $qa): ?>
            <details class="sup-faq__item">
                <summary><?php echo $e($qa[0]); ?><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
                <p><?php echo $e($qa[1]); ?> <a href="<?php echo $e($qa[2]); ?>">Go There</a></p>
            </details>
            <?php endforeach; ?>
        </div>
    </section>
</div>
<script src="/js/support.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/support.js'); ?>"></script>
