<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; ?>

    <?php /* Canvas first, as on Generate Images: her replicas fill the page; one bar at the bottom makes more. */ ?>
    <div class="inf-cv" id="inf_gen" data-cv>
        <div class="inf-cv__body">
            <section class="inf-cv__canvas" aria-label="Replicas">
                <div class="inf-cv__empty" id="inf_idle">
                    <i class="fa-regular fa-clone" aria-hidden="true"></i>
                    <h2>No Replicas Yet</h2>
                    <p>Choose a photo of someone else below. It is recreated with <?php echo $e($infl['name']); ?> in it.</p>
                </div>
                <div class="inf-strip inf-cv__grid" id="inf_strip"></div>
            </section>
            <aside class="inf-cv__viewer" id="inf_viewer" aria-label="Selected image">
                <button type="button" class="inf-cv__close" id="inf_viewer_close" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                <div class="inf-cv__stage" id="inf_stage">
                    <figure class="inf-gen__main" id="inf_main" hidden><img id="inf_main_img" alt=""><button type="button" class="inf-gen__expand" id="inf_expand" title="Expand"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button></figure>
                </div>
                <div class="inf-cv__actions" id="inf_result" hidden>
                    <div class="inf-result__actions inf-result__actions--only">
                    <button type="button" class="btn btn-secondary" id="inf_res_edit"><i class="fa-solid fa-pen"></i> Edit</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_video"><i class="fa-solid fa-clapperboard"></i> Make Video</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_download"><i class="fa-solid fa-download"></i> Download</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_post"><i class="fa-solid fa-feather-pointed"></i> Use in a Post</button>
                </div>
                </div>
            </aside>
        </div>

        <form class="inf-cv__dock" id="inf_rep_form" autocomplete="off" onsubmit="return false;">
            <div class="inf-pop inf-pop--modal inf-pop--src" id="inf_pop_rsrc" role="dialog" aria-modal="true" aria-labelledby="inf_pop_rsrc_h" hidden>
                <div class="inf-pop__h" id="inf_pop_rsrc_h">Source Photo <button type="button" class="inf-pop__x" data-pop-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
                <button type="button" class="inf-source" id="inf_rep_pick" aria-label="Choose a source photo">
                    <span class="inf-source__empty"><i class="fa-regular fa-image" aria-hidden="true"></i><span>Choose From Library Or Upload</span></span>
                </button>
                <div class="inf-mask" id="inf_rep_mask" hidden>
                    <div class="inf-mask__stage" id="inf_rep_stage"><img id="inf_rep_img" alt="Source photo"><canvas id="inf_rep_canvas" aria-label="Face mask"></canvas></div>
                    <div class="inf-mask__tools">
                        <div class="inf-seg" id="inf_rep_maskmode" role="group" aria-label="Face mask">
                            <button type="button" class="inf-seg__opt is-on" data-value="auto" aria-pressed="true"><span>Auto Mask</span></button>
                            <button type="button" class="inf-seg__opt" data-value="brush" aria-pressed="false"><span>Brush</span></button>
                            <button type="button" class="inf-seg__opt" data-value="off" aria-pressed="false"><span>No Mask</span></button>
                        </div>
                        <div class="inf-mask__row">
                            <span class="inf-wiz__meta" id="inf_rep_maskstate"></span>
                            <button type="button" class="inf-link" id="inf_rep_clear" hidden>Clear Brush</button>
                            <button type="button" class="inf-link" id="inf_rep_change">Change Photo</button>
                        </div>
                    </div>
                </div>
                <p class="inf-note" id="inf_rep_own" role="status" hidden><i class="fa-solid fa-circle-info" aria-hidden="true"></i> <span>This photo is already <?php echo $e($infl['name']); ?>, so the replica will look the same. Replicate Photo puts <?php echo ((string) ($infl['gender'] ?? '') === 'man') ? 'him' : 'her'; ?> into a photo of someone else. For new shots from this one, use <a class="inf-link" href="/influencers/carousel/<?php echo (int) $infl['id']; ?>">Carousel</a>.</span></p>
                <div class="inf-pop__foot"><button type="button" class="btn btn-secondary" data-pop-close>Done</button></div>
            </div>
            <div class="inf-pop" id="inf_pop_rmode" hidden>
                <div class="inf-pop__h">Mode</div>
                <div class="inf-seg" id="inf_rep_mode" role="group" aria-label="Mode">
                    <button type="button" class="inf-seg__opt is-on" data-value="style" aria-pressed="true"><span>Style</span></button>
                    <button type="button" class="inf-seg__opt" data-value="exact" aria-pressed="false"><span>Exact</span></button>
                </div>
            </div>
            <div class="inf-pop" id="inf_pop_rmodel" hidden>
                <div class="inf-pop__h">Model</div>
                <div class="inf-opts" id="inf_rep_model">
                    <?php foreach ((array) ($cfg['pickers']['replicate'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?> · <?php echo number_format((int) $o['credits']); ?> AI credits</span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-pop" id="inf_pop_rsize" hidden>
                <div class="inf-pop__h">Size</div>
                <div class="inf-seg" id="inf_rep_size" role="group" aria-label="Size"><?php echo Aspect::seg_buttons('inf-seg__opt', 'data-value'); ?></div>
            </div>
            <div class="inf-pop" id="inf_pop_rmore" hidden>
                <div class="inf-pop__h">More Settings</div>
                <div class="inf-field"><label class="inf-label" for="inf_rep_extra">Extra Instruction</label><input type="text" class="form-control" id="inf_rep_extra" maxlength="500" placeholder="Golden hour light"></div>
                <div class="inf-field" style="margin-top:12px"><div class="inf-label">Images</div><div class="inf-seg" id="inf_rep_n" role="group" aria-label="Images">
                    <?php foreach (array(1, 2, 3, 4) as $n): ?><button type="button" class="inf-seg__opt<?php echo $n === 1 ? ' is-on' : ''; ?>" data-value="<?php echo $n; ?>" aria-pressed="<?php echo $n === 1 ? 'true' : 'false'; ?>"><span><?php echo $n; ?></span></button><?php endforeach; ?>
                </div></div>
            </div>

            <div class="inf-cv__row">
                <button type="button" class="inf-cv__source" id="inf_rep_srcbtn" aria-label="Source photo"><img alt="" data-pop-thumb="#inf_rep_img" hidden><span class="inf-cv__sourceph"><i class="fa-regular fa-image" aria-hidden="true"></i><span>Choose Image</span></span></button>
                <div class="inf-cv__write">
                    <label class="visually-hidden" for="inf_rep_prompt">Prompt</label>
                    <textarea class="form-control inf-cv__prompt" id="inf_rep_prompt" maxlength="4000" rows="2" placeholder="Choose a source photo" disabled></textarea>
                </div>
            </div>
            <p class="inf-err" id="inf_rep_err" role="alert" hidden></p>
            <div class="inf-cv__bar">
                <button type="button" class="inf-cvchip" id="inf_rep_rewrite" hidden><i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Rewrite</button>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_rmode" aria-expanded="false"><span data-pop-label="#inf_rep_mode .inf-seg__opt.is-on">Mode</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>
                <span class="inf-cv__sep" aria-hidden="true"></span>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_rmodel" aria-expanded="false"><span data-pop-label="#inf_rep_model .inf-opt.is-on .inf-opt__t">Model</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_rsize" aria-expanded="false"><span data-pop-label="#inf_rep_size .inf-seg__opt.is-on">Size</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_rmore" aria-expanded="false" aria-label="More settings"><i class="fa-solid fa-sliders" aria-hidden="true"></i></button>
                <span class="inf-cv__status" id="inf_rep_reading" role="status" hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Reading the photo</span>
                <span class="inf-cv__status" id="inf_busy" role="status" hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <span id="inf_busy_text">Generating</span></span>
                <span class="inf-cv__cost" id="inf_rep_cost"></span>
                <button type="button" class="btn btn-primary inf-cv__go" id="inf_rep_go" disabled><i class="fa-solid fa-wand-magic-sparkles"></i> Replicate</button>
            </div>
        </form>
    </div>

    <div class="inf-lightbox" id="inf_lightbox" hidden><img id="inf_lightbox_img" alt=""><button type="button" class="inf-lightbox__close" id="inf_lightbox_close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>

<?php require __DIR__ . '/_bottom.php'; ?>
