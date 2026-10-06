<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; $gp = InfluencerService::prompts_for((string) ($infl['gender'] ?? 'woman')); ?>


    <?php /* Canvas first: what she has made fills the page; one bar at the bottom makes more. The page itself does not scroll. */ ?>
    <div class="inf-cv" id="inf_gen" data-cv>
        <div class="inf-cv__body">
            <section class="inf-cv__canvas" id="inf_preview" aria-label="Images">
                <div class="inf-cv__empty" id="inf_idle">
                    <i class="fa-regular fa-images" aria-hidden="true"></i>
                    <h2>Nothing Made Yet</h2>
                    <p>Describe a photo below, or pick an idea, and it appears here.</p>
                </div>
                <div class="inf-strip inf-cv__grid" id="inf_strip"></div>
            </section>
            <aside class="inf-cv__viewer" id="inf_viewer" aria-label="Selected image">
                <button type="button" class="inf-cv__close" id="inf_viewer_close" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                <div class="inf-cv__stage" id="inf_stage">
                    <figure class="inf-gen__main" id="inf_main" hidden>
                        <img id="inf_main_img" alt="">
                        <button type="button" class="inf-gen__expand" id="inf_expand" title="Expand"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>
                    </figure>
                </div>
                <div class="inf-cv__actions" id="inf_result" hidden>
                    <div class="inf-result__actions inf-result__actions--only">
                    <button type="button" class="btn btn-secondary" id="inf_res_again"><i class="fa-solid fa-rotate-right"></i> Run Again</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_video"><i class="fa-solid fa-clapperboard"></i> Make Video</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_enhance"><i class="fa-solid fa-magnifying-glass-plus"></i> Enhance · <?php echo number_format(Plan::ai_price('enhance')); ?> AI Credits</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_download"><i class="fa-solid fa-download"></i> Download</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_post"><i class="fa-solid fa-feather-pointed"></i> Use In Post</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_message"><i class="fa-solid fa-comment-dots"></i> Send in a Message</button>
                    <button type="button" class="btn btn-secondary inf-btn--danger" id="inf_res_delete"><i class="fa-regular fa-trash-can"></i> Delete</button>
                </div>
                </div>
            </aside>
        </div>

        <form class="inf-cv__dock" id="inf_gen_form" autocomplete="off" onsubmit="return false;">
            <div class="inf-pop inf-pop--modal" id="inf_pop_ideas" role="dialog" aria-modal="true" aria-labelledby="inf_pop_ideas_h" hidden>
                <div class="inf-pop__h" id="inf_pop_ideas_h">Ideas <button type="button" class="inf-pop__x" data-pop-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
                <div class="inf-chips" id="inf_prompt_chips">
                    <?php foreach ($gp['image'] as $i => $t): ?>
                    <button type="button" class="inf-chip inf-chip--text" data-i="<?php echo (int) $i; ?>" title="<?php echo $e($t); ?>"><?php echo $e($t); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-pop" id="inf_pop_model" hidden>
                <div class="inf-pop__h">Model</div>
                <div class="inf-opts" id="inf_model">
                    <?php foreach ((array) ($cfg['pickers']['image'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-pop" id="inf_pop_size" hidden>
                <div class="inf-pop__h">Size</div>
                <div class="inf-seg" id="inf_size" role="group" aria-label="Size">
                    <?php echo Aspect::seg_buttons('inf-seg__opt', 'data-value'); ?>
                </div>
            </div>
            <div class="inf-pop inf-pop--wide" id="inf_pop_more" hidden>
                <div class="inf-pop__h">More Settings</div>
                <div class="inf-grid inf-more__body">
                    <div class="inf-field"><label class="inf-label" for="inf_seed">Seed</label><input type="text" inputmode="numeric" class="form-control" id="inf_seed" placeholder="Random"></div>
                    <div class="inf-field"><div class="inf-label">Images per run</div>
                        <div class="inf-seg" id="inf_n" role="group"><button type="button" class="inf-seg__opt is-on" data-value="1" aria-pressed="true"><span>1</span></button><button type="button" class="inf-seg__opt" data-value="2" aria-pressed="false"><span>2</span></button><button type="button" class="inf-seg__opt" data-value="4" aria-pressed="false"><span>4</span></button></div></div>
                    <div class="inf-field"><label class="inf-label" for="inf_lora">Likeness strength</label><input type="text" inputmode="decimal" class="form-control" id="inf_lora" placeholder="1.0"></div>
                    <div class="inf-field"><label class="inf-label" for="inf_guidance">Guidance</label><input type="text" inputmode="decimal" class="form-control" id="inf_guidance" placeholder="3.5"></div>
                    <div class="inf-field"><label class="inf-label" for="inf_steps">Steps</label><input type="text" inputmode="numeric" class="form-control" id="inf_steps" placeholder="28"></div>
                </div>
            </div>

            <label class="visually-hidden" for="inf_prompt">Describe the photo</label>
            <textarea class="form-control inf-cv__prompt" id="inf_prompt" rows="2" maxlength="4000" placeholder="<?php echo $e($gp['image'][0]); ?>"></textarea>
            <div class="inf-cv__bar">
                <button type="button" class="inf-cvchip" data-pop="inf_pop_ideas" aria-expanded="false"><i class="fa-regular fa-lightbulb" aria-hidden="true"></i> Ideas</button>
                <button type="button" class="inf-cvchip" id="inf_prompt_auto"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Write It For Me</button>
                <span class="inf-cv__sep" aria-hidden="true"></span>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_model" aria-expanded="false"><span data-pop-label="#inf_model .inf-opt.is-on .inf-opt__t">Model</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_size" aria-expanded="false"><span data-pop-label="#inf_size .inf-seg__opt.is-on">Size</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_more" aria-expanded="false" aria-label="More settings"><i class="fa-solid fa-sliders" aria-hidden="true"></i></button>
                <span class="inf-cv__status" id="inf_busy" role="status" hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <span id="inf_busy_text">Generating</span></span>
                <span class="inf-cv__cost" id="inf_gen_cost"></span>
                <button type="button" class="btn btn-primary inf-cv__go" id="inf_gen_go"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
            </div>
        </form>
    </div>

    <div class="inf-lightbox" id="inf_lightbox" hidden><img id="inf_lightbox_img" alt=""><button type="button" class="inf-lightbox__close" id="inf_lightbox_close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>

<?php require __DIR__ . '/_bottom.php'; ?>
