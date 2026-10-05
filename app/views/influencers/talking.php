<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; ?>

    <div class="inf-gen" id="inf_gen">
        <form class="inf-gen__form" id="inf_tk_form" autocomplete="off" onsubmit="return false;">
            <?php require __DIR__ . '/_vhead.php'; ?>

            <div class="inf-field">
                <div class="inf-field__row"><div class="inf-label" id="inf_tk_img_label">Close-Up Image</div><?php echo Tutorials::button('32'); ?></div>
                <button type="button" class="inf-source inf-source--sm" id="inf_tk_image" aria-labelledby="inf_tk_img_label">
                    <span class="inf-source__empty"><i class="fa-regular fa-image" aria-hidden="true"></i><span>Choose From Library Or Upload</span></span>
                </button>
            </div>
            <div class="inf-field">
                <div class="inf-label">Speech</div>
                <div class="inf-seg" id="inf_tk_source" role="group" aria-label="Speech">
                    <button type="button" class="inf-seg__opt is-on" data-value="script" aria-pressed="true"><span>Script</span></button>
                    <button type="button" class="inf-seg__opt" data-value="audio" aria-pressed="false"><span>Audio File</span></button>
                </div>
            </div>
            <div class="inf-field" id="inf_tk_script_wrap">
                <div class="inf-field__row"><label class="inf-label" for="inf_tk_script">Script</label><a class="inf-link" href="/influencers/voice/<?php echo (int) $infl['id']; ?>" id="inf_tk_voice">Voice</a></div>
                <textarea class="form-control inf-gen__prompt" id="inf_tk_script" rows="7" maxlength="20000" placeholder="Okay, I have to tell you something"></textarea>
            </div>
            <div class="inf-field" id="inf_tk_audio_wrap" hidden>
                <div class="inf-label" id="inf_tk_aud_label">Audio File</div>
                <button type="button" class="inf-source inf-source--row" id="inf_tk_audio" aria-labelledby="inf_tk_aud_label">
                    <span class="inf-source__empty"><i class="fa-solid fa-music" aria-hidden="true"></i><span>Choose From Library Or Upload</span></span>
                </button>
            </div>
            <p class="inf-err" id="inf_tk_err" role="alert" hidden></p>

            <div class="inf-gen__submit">
                <span class="inf-wiz__meta" id="inf_tk_cost"></span>
                <button type="button" class="btn btn-primary" id="inf_tk_go" disabled><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Video</button>
            </div>
        </form>
        <?php $idle_text = 'Your talking video will appear here.'; require __DIR__ . '/_vresult.php'; ?>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
