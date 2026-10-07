<?php
/** /account/domain_login while signed in: confirm before the session is handed to a creator's own domain. The handoff is a POST (CSRF). */
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
?>
<section class="sx lg">
    <div class="ld-wrap sx__in">
        <div class="lg__head">
            <h1 class="lg__title">Continue to <?php echo $e($host); ?>?</h1>
            <p class="lg__updated">This is @<?php echo $e($handle); ?>'s own site. You'll be signed in there as @<?php echo $e($me_handle); ?> to follow, subscribe and buy from them.</p>
        </div>
        <form method="post" action="/account/domain_login">
            <?php echo CSRF::field(); ?>
            <input type="hidden" name="host" value="<?php echo $e($host); ?>">
            <input type="hidden" name="path" value="<?php echo $e($path); ?>">
            <button type="submit" class="ld-btn ld-btn--primary">Continue</button>
            <a class="ld-btn ld-btn--quiet" href="/">Cancel</a>
        </form>
    </div>
</section>
