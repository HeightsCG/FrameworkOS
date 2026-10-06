<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; $car = (array) ($cfg['carousel'] ?? array()); ?>

    <?php /* Canvas first, as on Generate Images: the carousel fills the page; one bar at the bottom makes it. */ ?>
    <div class="inf-cv" id="inf_gen" data-cv>
        <div class="inf-cv__body">
            <section class="inf-cv__canvas inf-cv__canvas--car" aria-label="Carousel">
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

        <form class="inf-cv__dock" id="inf_car_form" autocomplete="off" onsubmit="return false;">
            <div class="inf-pop" id="inf_pop_cmodel" hidden>
                <div class="inf-pop__h">Model</div>
                <div class="inf-opts" id="inf_car_model">
                    <?php foreach ((array) ($cfg['pickers']['replicate'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?> · <?php echo number_format((int) $o['credits']); ?> AI credits each</span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-pop" id="inf_pop_csize" hidden>
                <div class="inf-pop__h">Size</div>
                <div class="inf-seg" id="inf_car_size" role="group" aria-label="Size"><?php echo Aspect::seg_buttons('inf-seg__opt', 'data-value'); ?></div>
            </div>

            <div class="inf-cv__row">
                <button type="button" class="inf-source inf-cv__source" id="inf_car_pick" aria-label="Choose a seed image">
                    <span class="inf-source__empty"><i class="fa-regular fa-image" aria-hidden="true"></i><span>Choose Image</span></span>
                </button>
                <div class="inf-cv__write">
                    <label class="visually-hidden" for="inf_car_text">Scene</label>
                    <textarea class="form-control inf-cv__prompt" id="inf_car_text" maxlength="2000" rows="2" placeholder="Morning coffee on a balcony, white robe, potted plants, a small dog"></textarea>
                </div>
            </div>
            <p class="inf-err" id="inf_car_err" role="alert" hidden></p>
            <div class="inf-cv__bar">
                <button type="button" class="inf-cvchip" id="inf_car_clear" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i> Remove Image</button>
                <select class="inf-cvchip inf-cvchip--select" aria-label="Variation" id="inf_car_focus">
                        <?php foreach ((array) ($car['focus'] ?? array()) as $fk => $fl): ?><option value="<?php echo $e($fk); ?>"><?php echo $e($fl); ?></option><?php endforeach; ?>
                    </select>
                <select class="inf-cvchip inf-cvchip--select" aria-label="Images" id="inf_car_count">
                        <?php for ($n = (int) ($car['min'] ?? 2); $n <= (int) ($car['max'] ?? 10); $n++): ?><option value="<?php echo $n; ?>"<?php echo $n === 5 ? ' selected' : ''; ?>><?php echo $n; ?> Images</option><?php endfor; ?>
                    </select>
                <span class="inf-cv__sep" aria-hidden="true"></span>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_cmodel" aria-expanded="false"><span data-pop-label="#inf_car_model .inf-opt.is-on .inf-opt__t">Model</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_csize" aria-expanded="false"><span data-pop-label="#inf_car_size .inf-seg__opt.is-on">Size</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>
                <?php echo Tutorials::button('24'); ?>
                <span class="inf-cv__status" id="inf_car_reading" role="status" hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Reading the photo</span>
                <span class="inf-cv__cost" id="inf_car_cost"></span>
                <button type="button" class="btn btn-primary inf-cv__go" id="inf_car_go"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Carousel</button>
            </div>
        </form>
    </div>

    <div class="inf-lightbox" id="inf_lightbox" hidden><img id="inf_lightbox_img" alt=""><button type="button" class="inf-lightbox__close" id="inf_lightbox_close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>

<?php require __DIR__ . '/_bottom.php'; ?>
