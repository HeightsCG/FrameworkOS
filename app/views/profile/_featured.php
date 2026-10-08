<?php
/**
 * "Featured Creators": the partners of this creator's running cross-promotion swaps (PromoSwapsModel::featured_for).
 * Included once by view.php right after the header card. Links are absolute and go through /promote/click/<swap>/<to>,
 * which counts the click and lands on the partner's page (their own domain when they have one).
 */
$feat_rows = array();
if (empty($focus_event) && empty($focus_service)) {
    try {
        $feat_rows = (new PromoSwapsModel())->featured_for((int) $user['user_id']);
    } catch (\Throwable $e) {
        error_log('[featured] ' . $e->getMessage());
    }
}
if (!empty($feat_rows)):
    $feat_base = rtrim(Main::get_base_domain(), '/');
?>
        <link rel="stylesheet" href="/css/promote.css?v=<?php echo @filemtime(Main::app_path() . '/public/css/promote.css'); ?>">
        <section class="pf-feat" aria-labelledby="pf_feat_title">
            <h2 class="pf-feat__title" id="pf_feat_title">Featured Creators</h2>
            <div class="pf-feat__grid">
                <?php foreach ($feat_rows as $fr):
                    $fr_name = trim(html_entity_decode((string) $fr['display_name'], ENT_QUOTES, 'UTF-8'));
                    if ($fr_name === '') { $fr_name = '@' . $fr['u_name']; }
                    if ((int) $fr['paid_from_cents'] > 0) {
                        $fr_unit  = ((string) $fr['paid_from_interval'] === 'year') ? 'yr' : 'mo';
                        $fr_price = 'From $' . number_format((int) $fr['paid_from_cents'] / 100, 2) . '/' . $fr_unit;
                    } else {
                        $fr_price = ((int) $fr['free_plans'] > 0) ? 'Free membership' : 'Free to follow';
                    }
                    $fr_href = $feat_base . '/promote/click/' . (int) $fr['swap_id'] . '/' . (int) $fr['user_id'];
                    $fr_av   = trim((string) (trim((string) ($fr['avatar_webp_url'] ?? '')) !== '' ? $fr['avatar_webp_url'] : $fr['avatar_url']));   // webp copy when there is one
                ?>
                <a class="pf-feat__card" href="<?php echo htmlspecialchars($fr_href, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="pf-feat__avatar" aria-hidden="true"><?php if ($fr_av !== ''): ?><img src="<?php echo htmlspecialchars($fr_av, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy" decoding="async"><?php else: echo htmlspecialchars(mb_strtoupper(mb_substr(ltrim($fr_name, '@'), 0, 1)), ENT_QUOTES, 'UTF-8'); endif; ?></span>
                    <span class="pf-feat__body">
                        <span class="pf-feat__name"><?php echo htmlspecialchars($fr_name, ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($fr['verified'])): ?> <i class="fa-solid fa-circle-check pf-feat__badge" aria-label="Verified"></i><?php endif; ?></span>
                        <span class="pf-feat__handle">@<?php echo htmlspecialchars((string) $fr['u_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="pf-feat__price"><?php echo htmlspecialchars($fr_price, ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
<?php endif; ?>
