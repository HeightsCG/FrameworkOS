<link rel="stylesheet" href="/css/support.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/support.css'); ?>">
<?php
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$fmt = $this->fmt;
$status_label = array('open' => 'Waiting on support', 'answered' => 'Support replied', 'closed' => 'Closed');
$faq = SupportModel::QUICK_ANSWERS;
?>
<div class="sup">
    <header class="sup__head">
        <h1 class="sup__title">Support</h1>
        <button type="button" class="sup-btn sup-btn--primary" id="supCreate"><i class="fa-solid fa-plus"></i> New Request</button>
    </header>

    <?php if (empty($this->tickets)): ?>
    <div class="sup-empty">
        <span class="sup-empty__ic"><i class="fa-solid fa-life-ring"></i></span>
        <h2 class="sup-empty__t">No Requests Yet</h2>
    </div>
    <?php else: ?>
    <div class="sup-table">
        <div class="sup-table__head"><span>Request</span><span>Topic</span><span>Last update</span><span>Status</span></div>
        <div class="sup-table__body">
            <?php foreach ($this->tickets as $t): ?>
            <a class="sup-row" href="/support/ticket/<?php echo (int) $t['id']; ?>">
                <span class="sup-cell sup-cell--title"><span class="sup-cell__name"><?php echo $e($t['subject']); ?></span></span>
                <span class="sup-cell sup-cell--muted"><?php echo $e($this->categories[$t['category']] ?? 'Something else'); ?></span>
                <span class="sup-cell sup-cell--muted"><?php echo $e($fmt($t['last_message_at'])); ?></span>
                <span class="sup-cell"><span class="sup-status sup-status--<?php echo $e($t['status']); ?>"><span class="sup-status__dot"></span><?php echo $e($status_label[$t['status']] ?? $t['status']); ?></span></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <h2 class="sup__h2">Quick Answers</h2>
    <div class="sup-faq">
        <?php foreach ($faq as $qa): ?>
        <details class="sup-faq__item">
            <summary><?php echo $e($qa[0]); ?><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
            <p><?php echo $e($qa[1]); ?> <a href="<?php echo $e($qa[2]); ?>">Go There</a></p>
        </details>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="supportModal" tabindex="-1" aria-hidden="true" aria-labelledby="supModalTitle">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="supModalTitle">New Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="sup-field"><label for="sup_category">Topic</label>
                    <select class="form-control" id="sup_category">
                        <option value="" selected disabled>Choose a topic</option>
                        <?php foreach ($this->categories as $k => $label): ?><option value="<?php echo $e($k); ?>"><?php echo $e($label); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="sup-field"><label for="sup_subject">Subject</label><input type="text" class="form-control" id="sup_subject" maxlength="190" placeholder="Card declined when buying credits"></div>
                <div class="sup-field"><label for="sup_body">Message</label><textarea class="form-control" id="sup_body" rows="6" maxlength="5000" placeholder="What happened, and what you expected"></textarea></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="sup-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="sup-btn sup-btn--primary" id="supSend">Send Request</button>
            </div>
        </div>
    </div>
</div>
<script src="/js/support.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/support.js'); ?>"></script>
