<?php
/** Shared layout for the legal documents (/terms, /privacy). The including view sets $doc:
 *  title, updated (Y-m-d), intro (array of paragraphs), sections (array of [heading, blocks]),
 *  where a block is a paragraph string or array('list' => [...]). Text is plain; {site} and {contact} are filled in. */
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$fill = function ($s) { return str_replace(array('{site}', '{contact}'), array(Main::site_name(), PagesController::LEGAL_CONTACT), (string) $s); };
$anchor = function ($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'); };
$para = function ($s) use ($e, $fill) {
    // Only the contact address and our own page paths become links; everything else is plain text.
    $h = $e($fill($s));
    $h = str_replace($e(PagesController::LEGAL_CONTACT), '<a href="mailto:' . $e(PagesController::LEGAL_CONTACT) . '">' . $e(PagesController::LEGAL_CONTACT) . '</a>', $h);
    $h = preg_replace('#(?<![\w/])/(terms|privacy|pricing)\b#', '<a href="/$1">/$1</a>', $h);
    return $h;
};
?>
<section class="sx lg">
    <div class="ld-wrap sx__in">
        <header class="lg__head">
            <h1 class="lg__title"><?php echo $e($fill($doc['title'])); ?></h1>
            <p class="lg__updated">Last updated <?php echo $e(date('F j, Y', strtotime($doc['updated']))); ?></p>
        </header>
        <div class="lg__grid">
            <nav class="lg__toc" aria-label="On this page">
                <p class="lg__toc-h">On this page</p>
                <ol><?php foreach ($doc['sections'] as $i => $sec): ?><li><a href="#<?php echo $e($anchor($sec[0])); ?>"><?php echo $e($fill($sec[0])); ?></a></li><?php endforeach; ?></ol>
            </nav>
            <article class="lg__body">
                <?php foreach ($doc['intro'] as $p): ?><p><?php echo $para($p); ?></p><?php endforeach; ?>
                <?php foreach ($doc['sections'] as $i => $sec): ?>
                <h2 id="<?php echo $e($anchor($sec[0])); ?>"><?php echo ($i + 1) . '. ' . $e($fill($sec[0])); ?></h2>
                <?php foreach ($sec[1] as $b): ?>
                    <?php if (is_array($b)): ?><ul><?php foreach ($b['list'] as $li): ?><li><?php echo $para($li); ?></li><?php endforeach; ?></ul>
                    <?php else: ?><p><?php echo $para($b); ?></p><?php endif; ?>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </article>
        </div>
    </div>
</section>
