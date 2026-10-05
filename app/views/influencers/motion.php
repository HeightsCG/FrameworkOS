<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; ?>

    <div class="inf-gen" id="inf_gen">
        <form class="inf-gen__form" id="inf_mo_form" autocomplete="off" onsubmit="return false;">
            <?php require __DIR__ . '/_vhead.php'; ?>

            <div class="inf-field">
                <div class="inf-field__row"><div class="inf-label" id="inf_mo_vid_label">Motion Video</div><?php echo Tutorials::button('27'); ?></div>
                <button type="button" class="inf-source inf-source--sm" id="inf_mo_video" aria-labelledby="inf_mo_vid_label">
                    <span class="inf-source__empty"><i class="fa-solid fa-film" aria-hidden="true"></i><span>Choose a Video From Your Library</span></span>
                </button>
                <p class="inf-wiz__meta inf-mo__len" id="inf_mo_len" hidden></p>
            </div>

            <div class="inf-field">
                <div class="inf-field__row"><div class="inf-label" id="inf_mo_img_label">First Frame</div><button type="button" class="inf-link" id="inf_mo_make" hidden><i class="fa-solid fa-wand-magic-sparkles"></i> Make First Frame</button></div>
                <button type="button" class="inf-source inf-source--sm" id="inf_mo_image" aria-labelledby="inf_mo_img_label">
                    <span class="inf-source__empty"><i class="fa-regular fa-image" aria-hidden="true"></i><span>Choose From Library Or Upload</span></span>
                </button>
            </div>

            <ul class="inf-notes" id="inf_mo_notes" hidden></ul>

            <div class="inf-field">
                <div class="inf-label">Quality</div>
                <div class="inf-seg" id="inf_mo_quality" role="group" aria-label="Quality">
                    <?php foreach ((array) ($cfg['pickers']['motion'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-seg__opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-value="<?php echo $e($o['key']); ?>" aria-pressed="<?php echo $i === 0 ? 'true' : 'false'; ?>"><span><?php echo $e($o['label']); ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-field">
                <label class="inf-label" for="inf_mo_prompt">Prompt</label>
                <input type="text" class="form-control" id="inf_mo_prompt" maxlength="1500" placeholder="Dancing in a bedroom, smiling">
            </div>

            <div class="inf-gen__submit">
                <span class="inf-wiz__meta" id="inf_mo_cost"></span>
                <button type="button" class="btn btn-primary" id="inf_mo_go" disabled><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Video</button>
            </div>
        </form>
        <?php $idle_text = 'Your video will appear here.'; require __DIR__ . '/_vresult.php'; ?>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
