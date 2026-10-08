<link rel="stylesheet" href="/css/events.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/events.css'); ?>">
<link rel="stylesheet" href="/css/affiliates.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/affiliates.css'); ?>">
<?php
/* /affiliates/apply: the affiliate application, or where it stands (AffiliatesController::applyAction). */
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$d = function ($s) { return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'); };
$a = $this->affiliate;
$st = $a ? (string) $a['status'] : '';
?>
<div class="ev afl">
    <header class="ev__head">
        <div>
            <h1 class="ev__title">Become an Affiliate</h1>
            <p class="ev__sub">Earn <?php echo (int) Affiliates::RATE_PERCENT; ?>% of the plan payments of every account you refer. <a href="/affiliates" target="_blank" rel="noopener">How the program works</a></p>
        </div>
    </header>

    <?php if ($st === 'pending'): ?>
    <div class="ev-empty">
        <span class="ev-empty__ic"><i class="fa-regular fa-clock" aria-hidden="true"></i></span>
        <h2 class="ev-empty__t">Application Received</h2>
        <p class="ev-empty__x">Our team reviews every application. You will get an email and a notification once it is decided.</p>
    </div>
    <?php elseif ($st === 'disabled'): ?>
    <div class="ev-empty">
        <span class="ev-empty__ic"><i class="fa-solid fa-ban" aria-hidden="true"></i></span>
        <h2 class="ev-empty__t">Affiliate Account Paused</h2>
        <p class="ev-empty__x">Your affiliate account is paused. <a href="/support">Contact support</a> if you have questions.</p>
    </div>
    <?php else: ?>
    <form class="afl-card" id="aflApply" novalidate>
        <?php if ($st === 'rejected'): ?><p class="afl-note">Your last application was not approved. You can apply again below.</p><?php endif; ?>
        <div class="form-floating mb-3">
            <input type="url" class="form-control" id="aflWebsite" name="website" placeholder="https://instagram.com/yourname" maxlength="255" value="<?php echo $e($a ? $d($a['website']) : ''); ?>" required>
            <label for="aflWebsite">Website or Social Profile</label>
        </div>
        <div class="form-floating mb-3">
            <textarea class="form-control afl-textarea" id="aflNote" name="note" placeholder="Newsletter, YouTube reviews, blog posts" maxlength="2000" required><?php echo $e($a ? $d($a['note']) : ''); ?></textarea>
            <label for="aflNote">How You Will Promote It</label>
        </div>
        <label class="afl-agree">
            <input type="checkbox" class="form-check-input" id="aflAgree" value="1">
            <span>I Agree to the <a href="/affiliates" target="_blank" rel="noopener">Affiliate Program Terms</a></span>
        </label>
        <div class="afl-card__foot">
            <button type="submit" class="ev-btn ev-btn--primary" id="aflSubmit">Submit Application</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<script src="/js/affiliates.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/affiliates.js'); ?>"></script>
