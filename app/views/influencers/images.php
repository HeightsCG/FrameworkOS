<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; $gp = InfluencerService::prompts_for((string) ($infl['gender'] ?? 'woman')); ?>


    <div class="inf-gen" id="inf_gen">
        <form class="inf-gen__form" id="inf_gen_form" autocomplete="off" onsubmit="return false;">
            <label class="inf-who inf-who--form" for="inf_who">
                <span class="inf-who__badge"><?php if (!empty($infl['cover_url'])): ?><img src="<?php echo $e($infl['cover_url']); ?>" alt=""><?php else: ?><i class="fa-regular fa-user"></i><?php endif; ?></span>
                <select class="form-select inf-who__select" id="inf_who">
                    <?php foreach ($ready as $r): ?>
                    <option value="<?php echo (int) $r['id']; ?>"<?php echo ((int) $r['id'] === (int) $infl['id']) ? ' selected' : ''; ?>><?php echo $e($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php require __DIR__ . '/_modes.php'; ?>
            <div class="inf-field">
                <div class="inf-field__row"><label class="inf-label" for="inf_prompt">Prompt</label><button type="button" class="inf-link" id="inf_prompt_auto"><i class="fa-solid fa-wand-magic-sparkles"></i> Write a prompt</button></div>
                <textarea class="form-control inf-gen__prompt" id="inf_prompt" maxlength="4000" placeholder="<?php echo $e($gp['image'][0]); ?>"></textarea>
            </div>
            <div class="inf-field">
                <div class="inf-label">Prebuilt</div>
                <div class="inf-chips" id="inf_prompt_chips">
                    <?php foreach ($gp['image'] as $i => $t): ?>
                    <button type="button" class="inf-chip inf-chip--text" data-i="<?php echo (int) $i; ?>" title="<?php echo $e($t); ?>"><?php echo $e(mb_strlen($t) > 60 ? mb_substr($t, 0, 60) . '…' : $t); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="inf-field">
                <div class="inf-label">Model</div>
                <div class="inf-opts" id="inf_model">
                    <?php foreach ((array) ($cfg['pickers']['image'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-field">
                <div class="inf-label">Size</div>
                <div class="inf-seg" id="inf_size" role="group" aria-label="Size">
                    <?php echo Aspect::seg_buttons('inf-seg__opt', 'data-value'); ?>
                </div>
            </div>

            <details class="inf-more" id="inf_more">
                <summary>More settings</summary>
                <div class="inf-grid inf-more__body">
                    <div class="inf-field"><label class="inf-label" for="inf_seed">Seed</label><input type="text" inputmode="numeric" class="form-control" id="inf_seed" placeholder="Random"></div>
                    <div class="inf-field"><div class="inf-label">Images per run</div>
                        <div class="inf-seg" id="inf_n" role="group"><button type="button" class="inf-seg__opt is-on" data-value="1" aria-pressed="true"><span>1</span></button><button type="button" class="inf-seg__opt" data-value="2" aria-pressed="false"><span>2</span></button><button type="button" class="inf-seg__opt" data-value="4" aria-pressed="false"><span>4</span></button></div></div>
                    <div class="inf-field"><label class="inf-label" for="inf_lora">Likeness strength</label><input type="text" inputmode="decimal" class="form-control" id="inf_lora" placeholder="1.0"></div>
                    <div class="inf-field"><label class="inf-label" for="inf_guidance">Guidance</label><input type="text" inputmode="decimal" class="form-control" id="inf_guidance" placeholder="3.5"></div>
                    <div class="inf-field"><label class="inf-label" for="inf_steps">Steps</label><input type="text" inputmode="numeric" class="form-control" id="inf_steps" placeholder="28"></div>
                </div>
            </details>

            <div class="inf-gen__submit">
                <span class="inf-wiz__meta" id="inf_gen_cost"></span>
                <button type="button" class="btn btn-primary" id="inf_gen_go"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
            </div>
        </form>

        <section class="inf-gen__preview" id="inf_preview">
            <div class="inf-gen__stage" id="inf_stage">
                <div class="inf-gen__idle" id="inf_idle">
                    <i class="fa-regular fa-image"></i>
                    <p>Images will appear here.</p>
                </div>
                <div class="inf-gen__busy" id="inf_busy" hidden>
                    <span class="spinner-border text-primary" role="status"></span>
                    <p id="inf_busy_text">Generating</p>
                </div>
                <figure class="inf-gen__main" id="inf_main" hidden>
                    <img id="inf_main_img" alt="">
                    <button type="button" class="inf-gen__expand" id="inf_expand" title="Expand"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>
                </figure>
            </div>
            <div class="inf-strip" id="inf_strip"></div>
            <div class="inf-result" id="inf_result" hidden>
                <div class="inf-result__actions inf-result__actions--only">
                    <button type="button" class="btn btn-secondary" id="inf_res_again"><i class="fa-solid fa-rotate-right"></i> Run Again</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_video"><i class="fa-solid fa-clapperboard"></i> Make Video</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_enhance"><i class="fa-solid fa-magnifying-glass-plus"></i> Enhance · <?php echo number_format(Plan::ai_price('enhance')); ?> AI Credits</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_download"><i class="fa-solid fa-download"></i> Download</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_post"><i class="fa-solid fa-feather-pointed"></i> Use in a Post</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_message"><i class="fa-solid fa-comment-dots"></i> Send in a Message</button>
                    <button type="button" class="btn btn-secondary inf-btn--danger" id="inf_res_delete"><i class="fa-regular fa-trash-can"></i> Delete</button>
                </div>
            </div>
        </section>
    </div>

    <div class="inf-lightbox" id="inf_lightbox" hidden><img id="inf_lightbox_img" alt=""><button type="button" class="inf-lightbox__close" id="inf_lightbox_close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>

<?php require __DIR__ . '/_bottom.php'; ?>
