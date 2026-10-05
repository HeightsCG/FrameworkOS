<?php /* The three ways to generate images, as a segmented switch between their pages. Expects $image_modes, $page, $infl, $e from _top.php. */ ?>
            <nav class="inf-seg inf-modes" aria-label="How to generate">
                <?php foreach ($image_modes as $mk => $ml): ?>
                <a class="inf-seg__opt<?php echo $page === $mk ? ' is-on' : ''; ?>" href="/influencers/<?php echo $mk; ?>/<?php echo (int) $infl['id']; ?>"<?php echo $page === $mk ? ' aria-current="page"' : ''; ?>><span><?php echo $e($ml); ?></span></a>
                <?php endforeach; ?>
            </nav>
