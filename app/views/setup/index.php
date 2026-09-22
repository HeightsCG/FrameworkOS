<link rel="stylesheet" href="/css/setup.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/setup.css'); ?>">
<?php
$p   = $this->progress;
$tz  = (string) ($this->timezone ?? 'UTC');
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$fmt = function ($utc) use ($tz) {
    if ((string) $utc === '') { return ''; }
    try { $d = new DateTime((string) $utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone($tz ?: 'UTC')); return $d->format('M j'); }
    catch (\Throwable $ex) { return ''; }
};
$next_key = $p['next'] ? $p['next']['key'] : '';
$pct      = $p['required_total'] > 0 ? (int) round($p['required_done'] / $p['required_total'] * 100) : 0;
$required = array_filter($p['steps'], function ($s) { return empty($s['optional']); });
$optional = array_filter($p['steps'], function ($s) { return !empty($s['optional']); });
$n = 0;
?>
<div class="setup" id="setupPage">
    <header class="setup__head">
        <div>
            <h1 class="setup__title">Set up your studio</h1>
            <p class="setup__sub"><?php echo $p['complete'] ? 'Everything required is done. The optional step below is worth a look.' : (int) $p['required_done'] . ' of ' . (int) $p['required_total'] . ' steps done. Each one opens the page where you finish it.'; ?></p>
        </div>
        <div class="setup__progress" aria-label="<?php echo (int) $pct; ?>% complete">
            <span class="setup__pct"><?php echo (int) $pct; ?>%</span>
            <span class="setup__track"><span class="setup__fill" style="width:<?php echo (int) $pct; ?>%"></span></span>
        </div>
    </header>

    <ol class="setup__list">
        <?php foreach ($required as $s): $n++; $is_next = ($s['key'] === $next_key); ?>
        <li class="setup__step<?php echo $s['done'] ? ' is-done' : (!empty($s['skipped']) ? ' is-skipped' : ($is_next ? ' is-next' : '')); ?>">
            <span class="setup__mark" aria-hidden="true"><?php echo $s['done'] ? '<i class="fa-solid fa-check"></i>' : (int) $n; ?></span>
            <span class="setup__body">
                <span class="setup__name"><?php echo $e($s['title']); ?></span>
                <span class="setup__text"><?php echo $s['done'] ? 'Done' . ($fmt($s['done_at']) !== '' ? ' · ' . $e($fmt($s['done_at'])) : '') : (!empty($s['skipped']) ? 'Skipped · ' . $e($s['text']) : $e($s['text'])); ?></span>
            </span>
            <?php if (!$s['done']): ?>
            <a class="btn <?php echo $is_next ? 'btn-primary' : 'btn-secondary'; ?> setup__cta" href="<?php echo $e($s['url']); ?>"><?php echo $e($s['cta']); ?></a>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>

    <?php if (!empty($optional)): ?>
    <h2 class="setup__h2">Nice to Have</h2>
    <ol class="setup__list setup__list--optional">
        <?php foreach ($optional as $s): $is_next = ($s['key'] === $next_key); ?>
        <li class="setup__step<?php echo $s['done'] ? ' is-done' : ($is_next ? ' is-next' : ''); ?>">
            <span class="setup__mark setup__mark--opt" aria-hidden="true"><?php echo $s['done'] ? '<i class="fa-solid fa-check"></i>' : '<i class="fa-solid fa-plus"></i>'; ?></span>
            <span class="setup__body">
                <span class="setup__name"><?php echo $e($s['title']); ?></span>
                <span class="setup__text"><?php echo $s['done'] ? 'Done' . ($fmt($s['done_at']) !== '' ? ' · ' . $e($fmt($s['done_at'])) : '') : $e($s['text']); ?></span>
            </span>
            <?php if (!$s['done']): ?>
            <a class="btn <?php echo $is_next ? 'btn-primary' : 'btn-secondary'; ?> setup__cta" href="<?php echo $e($s['url']); ?>"><?php echo $e($s['cta']); ?></a>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <?php if (!$p['dismissed']): ?>
    <p class="setup__foot"><?php if ($p['complete']): ?><button type="button" class="btn btn-primary" data-setup-dismiss data-setup-complete="1">Done</button><?php else: ?><button type="button" class="setup__hide" data-setup-dismiss>Hide this checklist</button><?php endif; ?></p>
    <?php endif; ?>
</div>
