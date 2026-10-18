<?php
/** One post card in the profile grid. Rendered by view.php for the first page and by ProfilePosts::render() for "Load More". Expects $c (a ProfilePosts card). */
$cov = htmlspecialchars((string) $c['cover'], ENT_QUOTES, 'UTF-8');
?>
                        <?php $pc_cap = trim((string) $c['caption']); $pc_label = ($c['entitled'] ? 'Open post' : 'Locked post') . ($pc_cap !== '' ? ': ' . mb_substr($pc_cap, 0, 80) : ''); ?>
                        <button type="button" class="pf-pc<?php echo $c['entitled'] ? '' : ' pf-pc--locked'; ?>" aria-label="<?php echo htmlspecialchars($pc_label, ENT_QUOTES, 'UTF-8'); ?>" data-post-id="<?php echo (int) $c['id']; ?>" data-search="<?php echo htmlspecialchars(strtolower((string) $c['caption']), ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="pf-pc__thumb"><?php if ($cov !== ''): ?><img class="pf-pc__img" src="<?php echo $cov; ?>" alt="<?php echo htmlspecialchars((string) ($c['cover_alt'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" width="480" height="480" loading="lazy" decoding="async"><?php endif; ?>
                                <?php if (!$c['entitled']): ?><span class="pf-pc__lockbadge"><i class="fa-solid fa-lock"></i></span><?php endif; ?>
                                <?php if ($c['entitled'] && $c['has_video']): ?><span class="pf-pc__play"><i class="fa-solid fa-play"></i></span><?php endif; ?>
                                <?php if ((int) $c['media_count'] > 1): ?><span class="pf-pc__count"><i class="fa-solid fa-layer-group"></i> <?php echo (int) $c['media_count']; ?></span><?php endif; ?>
                            </span>
                            <?php if (trim((string) $c['excerpt']) !== ''): ?><span class="pf-pc__cap"><?php echo htmlspecialchars((string) $c['excerpt'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                        </button>
