<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; ?>

    <div class="inf-gen" id="inf_gen">
        <form class="inf-gen__form" id="inf_rep_form" autocomplete="off" onsubmit="return false;">
            <label class="inf-who inf-who--form" for="inf_who">
                <span class="inf-who__badge"><?php if (!empty($infl['cover_url'])): ?><img src="<?php echo $e($infl['cover_url']); ?>" alt=""><?php else: ?><i class="fa-regular fa-user"></i><?php endif; ?></span>
                <select class="form-select inf-who__select" id="inf_who">
                    <?php foreach ($ready as $r): ?>
                    <option value="<?php echo (int) $r['id']; ?>"<?php echo ((int) $r['id'] === (int) $infl['id']) ? ' selected' : ''; ?>><?php echo $e($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="inf-field">
                <div class="inf-field__row"><div class="inf-label" id="inf_rep_src_label">Source Photo</div><?php echo Tutorials::button('23'); ?></div>
                <button type="button" class="inf-source" id="inf_rep_pick" aria-labelledby="inf_rep_src_label">
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
            </div>

            <div class="inf-field">
                <div class="inf-label">Mode</div>
                <div class="inf-seg" id="inf_rep_mode" role="group" aria-label="Mode">
                    <button type="button" class="inf-seg__opt is-on" data-value="style" aria-pressed="true"><span>Style</span></button>
                    <button type="button" class="inf-seg__opt" data-value="exact" aria-pressed="false"><span>Exact</span></button>
                </div>
            </div>
            <div class="inf-field">
                <label class="inf-label" for="inf_rep_extra">Extra Instruction</label>
                <input type="text" class="form-control" id="inf_rep_extra" maxlength="500" placeholder="Golden hour light">
            </div>
            <div class="inf-field">
                <div class="inf-field__row"><label class="inf-label" for="inf_rep_prompt">Prompt</label><span class="inf-working" id="inf_rep_reading" role="status" hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Reading the photo</span><button type="button" class="inf-link" id="inf_rep_rewrite" hidden><i class="fa-solid fa-rotate-right"></i> Rewrite</button></div>
                <textarea class="form-control inf-gen__prompt" id="inf_rep_prompt" maxlength="4000" rows="7" placeholder="Choose a source photo" disabled></textarea>
                <div class="inf-imgkey" aria-label="What the image names in the prompt mean"><span class="inf-imgkey__item"><code>@img1</code> Source Photo</span><span class="inf-imgkey__item"><code>@img2</code> <?php echo $e($infl['name']); ?></span></div>
                <p class="inf-err" id="inf_rep_err" role="alert" hidden></p>
            </div>

            <div class="inf-field">
                <div class="inf-label">Model</div>
                <div class="inf-opts" id="inf_rep_model">
                    <?php foreach ((array) ($cfg['pickers']['replicate'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?> · <?php echo number_format((int) $o['credits']); ?> AI credits</span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-field">
                <div class="inf-label">Size</div>
                <div class="inf-seg" id="inf_rep_size" role="group" aria-label="Size"><?php echo Aspect::seg_buttons('inf-seg__opt', 'data-value'); ?></div>
            </div>
            <div class="inf-field">
                <div class="inf-label">Images</div>
                <div class="inf-seg" id="inf_rep_n" role="group" aria-label="Images">
                    <?php foreach (array(1, 2, 3, 4) as $n): ?><button type="button" class="inf-seg__opt<?php echo $n === 1 ? ' is-on' : ''; ?>" data-value="<?php echo $n; ?>" aria-pressed="<?php echo $n === 1 ? 'true' : 'false'; ?>"><span><?php echo $n; ?></span></button><?php endforeach; ?>
                </div>
            </div>

            <div class="inf-gen__submit">
                <span class="inf-wiz__meta" id="inf_rep_cost"></span>
                <button type="button" class="btn btn-primary" id="inf_rep_go" disabled><i class="fa-solid fa-wand-magic-sparkles"></i> Replicate</button>
            </div>
        </form>

        <section class="inf-gen__preview">
            <div class="inf-gen__stage" id="inf_stage">
                <div class="inf-gen__idle" id="inf_idle"><i class="fa-regular fa-image"></i><p>Replicas will appear here.</p></div>
                <div class="inf-gen__busy" id="inf_busy" hidden><span class="spinner-border text-primary" role="status"></span><p id="inf_busy_text">Generating</p></div>
                <figure class="inf-gen__main" id="inf_main" hidden><img id="inf_main_img" alt=""><button type="button" class="inf-gen__expand" id="inf_expand" title="Expand"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button></figure>
            </div>
            <div class="inf-strip" id="inf_strip"></div>
            <div class="inf-result" id="inf_result" hidden>
                <div class="inf-result__actions inf-result__actions--only">
                    <button type="button" class="btn btn-secondary" id="inf_res_edit"><i class="fa-solid fa-pen"></i> Edit</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_video"><i class="fa-solid fa-clapperboard"></i> Make Video</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_download"><i class="fa-solid fa-download"></i> Download</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_post"><i class="fa-solid fa-feather-pointed"></i> Use in a Post</button>
                </div>
            </div>
        </section>
    </div>

    <div class="inf-lightbox" id="inf_lightbox" hidden><img id="inf_lightbox_img" alt=""><button type="button" class="inf-lightbox__close" id="inf_lightbox_close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>

<?php require __DIR__ . '/_bottom.php'; ?>
