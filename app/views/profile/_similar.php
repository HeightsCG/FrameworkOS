<?php
/**
 * "More Creators Like This": up to 4 directory creators in this creator's niche (CreatorProfileModel::similar), the
 * same set all day. Included once by view.php above "Create Your Page Free"; hidden when the creator turned it off.
 * Links are absolute (custom domains too) and go through the system tracking link /go/clssim<id>?from=<this creator>.
 */
$sim_rows = array();
if (empty($profile['similar_off'])) {
    try {
        $sim_rows = (new CreatorProfileModel())->similar((int) $user['user_id'], (string) ($profile['directory_category'] ?? ''), gmdate('Y-m-d'), 4, (int) Session::get('user_id'));
    } catch (\Throwable $e) {
        error_log('[similar] ' . $e->getMessage());
    }
}
if (!empty($sim_rows)):
    $sim_base = rtrim(Main::get_base_domain(), '/');
?>
        <section class="pf-sim" aria-labelledby="pf_sim_title">
            <h2 class="pf-sim__title" id="pf_sim_title">More Creators Like This</h2>
            <div class="pf-sim__grid">
                <?php foreach ($sim_rows as $sr):
                    $sr_name = trim(html_entity_decode((string) $sr['display_name'], ENT_QUOTES, 'UTF-8'));
                    if ($sr_name === '') { $sr_name = '@' . $sr['u_name']; }
                    if ((int) $sr['paid_from_cents'] > 0) {
                        $sr_unit  = ((string) $sr['paid_from_interval'] === 'year') ? 'yr' : 'mo';
                        $sr_price = 'From $' . number_format((int) $sr['paid_from_cents'] / 100, 2) . '/' . $sr_unit;
                    } else {
                        $sr_price = ((int) $sr['free_plans'] > 0) ? 'Free membership' : 'Free to follow';
                    }
                    $sr_href = $sim_base . '/go/' . TrackingLinks::similar_code((int) $sr['user_id']) . '?from=' . (int) $user['user_id'];
                ?>
                <a class="pf-sim__card" href="<?php echo htmlspecialchars($sr_href, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="pf-sim__avatar" style="background-image:url('<?php echo htmlspecialchars((string) (trim((string) ($sr['avatar_webp_url'] ?? '')) !== '' ? $sr['avatar_webp_url'] : $sr['avatar_url']), ENT_QUOTES, 'UTF-8'); ?>')" aria-hidden="true"></span>
                    <span class="pf-sim__body">
                        <span class="pf-sim__name"><?php echo htmlspecialchars($sr_name, ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($sr['verified'])): ?> <i class="fa-solid fa-circle-check pf-sim__badge" aria-label="Verified"></i><?php endif; ?></span>
                        <span class="pf-sim__handle">@<?php echo htmlspecialchars((string) $sr['u_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="pf-sim__price"><?php echo htmlspecialchars($sr_price, ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
<?php endif; ?>
