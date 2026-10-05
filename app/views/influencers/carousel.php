<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; $car = (array) ($cfg['carousel'] ?? array()); ?>

    <div class="inf-gen" id="inf_gen">
        <form class="inf-gen__form" id="inf_car_form" autocomplete="off" onsubmit="return false;">
            <label class="inf-who inf-who--form" for="inf_who">
                <span class="inf-who__badge"><?php if (!empty($infl['cover_url'])): ?><img src="<?php echo $e($infl['cover_url']); ?>" alt=""><?php else: ?><i class="fa-regular fa-user"></i><?php endif; ?></span>
                <select class="form-select inf-who__select" id="inf_who">
                    <?php foreach ($ready as $r): ?>
                    <option value="<?php echo (int) $r['id']; ?>"<?php echo ((int) $r['id'] === (int) $infl['id']) ? ' selected' : ''; ?>><?php echo $e($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="inf-field">
                <div class="inf-field__row"><div class="inf-label" id="inf_car_seed_label">Seed Image</div><?php echo Tutorials::button('24'); ?></div>
                <button type="button" class="inf-source inf-source--sm" id="inf_car_pick" aria-labelledby="inf_car_seed_label">
                    <span class="inf-source__empty"><i class="fa-regular fa-image" aria-hidden="true"></i><span>Choose From Library Or Upload</span></span>
                </button>
                <button type="button" class="inf-link inf-source__clear" id="inf_car_clear" hidden>Remove Image</button>
            </div>
            <div class="inf-field">
                <label class="inf-label" for="inf_car_text">Scene</label>
                <textarea class="form-control" id="inf_car_text" maxlength="2000" rows="3" placeholder="Morning coffee on a balcony, white robe, potted plants, a small dog"></textarea>
                <p class="inf-err" id="inf_car_err" role="alert" hidden></p>
            </div>
            <div class="inf-grid">
                <div class="inf-field">
                    <label class="inf-label" for="inf_car_focus">Variation</label>
                    <select class="form-select" id="inf_car_focus">
                        <?php foreach ((array) ($car['focus'] ?? array()) as $fk => $fl): ?><option value="<?php echo $e($fk); ?>"><?php echo $e($fl); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="inf-field">
                    <label class="inf-label" for="inf_car_count">Images</label>
                    <select class="form-select" id="inf_car_count">
                        <?php for ($n = (int) ($car['min'] ?? 2); $n <= (int) ($car['max'] ?? 10); $n++): ?><option value="<?php echo $n; ?>"<?php echo $n === 5 ? ' selected' : ''; ?>><?php echo $n; ?></option><?php endfor; ?>
                    </select>
                </div>
            </div>
            <div class="inf-field">
                <div class="inf-label">Model</div>
                <div class="inf-opts" id="inf_car_model">
                    <?php foreach ((array) ($cfg['pickers']['replicate'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?> · <?php echo number_format((int) $o['credits']); ?> AI credits each</span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-field">
                <div class="inf-label">Size</div>
                <div class="inf-seg" id="inf_car_size" role="group" aria-label="Size"><?php echo Aspect::seg_buttons('inf-seg__opt', 'data-value'); ?></div>
            </div>
            <div class="inf-gen__submit">
                <span class="inf-wiz__meta" id="inf_car_cost"></span>
                <button type="button" class="btn btn-primary" id="inf_car_go"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Carousel</button>
            </div>
        </form>

        <section class="inf-gen__preview">
            <div class="inf-car__head">
                <div>
                    <h2 class="inf-sec__h" id="inf_car_title">Review</h2>
                    <p class="inf-wiz__meta" id="inf_car_constants"></p>
                </div>
                <label class="inf-car__recent" for="inf_car_recent" hidden id="inf_car_recent_wrap"><span class="visually-hidden">Earlier carousels</span>
                    <select class="form-select" id="inf_car_recent"></select>
                </label>
            </div>
            <div class="inf-empty inf-car__empty" id="inf_car_empty">
                <span class="inf-empty__ic"><i class="fa-regular fa-images"></i></span>
                <h2 class="inf-empty__title">No Carousel Yet</h2>
                <p class="inf-empty__text">Your images appear here to reorder, drop or regenerate.</p>
            </div>
            <div class="inf-state-loading" id="inf_car_planning" hidden><span class="spinner-border spinner-border-sm text-primary" role="status"></span> Planning the shots…</div>
            <div class="inf-state-error" id="inf_car_loaderr" hidden>Could not load that carousel. <button type="button" class="inf-link" id="inf_car_reload">Try Again</button></div>
            <ol class="inf-car" id="inf_car_grid" hidden></ol>
            <div class="inf-car__foot" id="inf_car_foot" hidden>
                <span class="inf-wiz__meta" id="inf_car_kept"></span>
                <button type="button" class="btn btn-secondary" id="inf_car_post"><i class="fa-solid fa-feather-pointed"></i> Use In Post</button>
            </div>
        </section>
    </div>

    <div class="inf-lightbox" id="inf_lightbox" hidden><img id="inf_lightbox_img" alt=""><button type="button" class="inf-lightbox__close" id="inf_lightbox_close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>

<?php require __DIR__ . '/_bottom.php'; ?>
