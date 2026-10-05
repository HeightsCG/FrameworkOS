<?php /* The ways to generate video, as a segmented switch between their pages. Expects $video_modes, $page, $infl, $e from _top.php. */ ?>
            <nav class="inf-seg inf-modes inf-modes--4" aria-label="How to generate">
                <?php foreach ($video_modes as $mk => $ml): ?>
                <a class="inf-seg__opt<?php echo $page === $mk ? ' is-on' : ''; ?>" href="/influencers/<?php echo $mk; ?>/<?php echo (int) $infl['id']; ?>"<?php echo $page === $mk ? ' aria-current="page"' : ''; ?>><span><?php echo $e($ml); ?></span></a>
                <?php endforeach; ?>
            </nav>
