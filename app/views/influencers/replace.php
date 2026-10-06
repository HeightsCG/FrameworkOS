<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; ?>

    <div class="inf-gen" id="inf_gen" data-cv>
        <form class="inf-gen__form" id="inf_rp_form" autocomplete="off" onsubmit="return false;">
            <?php require __DIR__ . '/_vhead.php'; ?>

            <div class="inf-field">
                <div class="inf-field__row"><div class="inf-label" id="inf_rp_vid_label">Source Video</div><?php echo Tutorials::button('28'); ?></div>
                <button type="button" class="inf-source inf-source--sm" id="inf_rp_video" aria-labelledby="inf_rp_vid_label">
                    <span class="inf-source__empty"><i class="fa-solid fa-film" aria-hidden="true"></i><span>Choose The Video To Put <?php echo $e($infl['name']); ?> In</span></span>
                </button>
                <p class="inf-wiz__meta inf-mo__len" id="inf_rp_len" hidden></p>
            </div>
            <div hidden><a id="inf_rp_angles_link" href="/influencers/references/<?php echo (int) $infl['id']; ?>"></a><p id="inf_rp_angles"></p></div>
            <div class="inf-after" id="inf_rp_after">
            <div class="inf-field">
                <label class="inf-label" for="inf_rp_subject">Who Does <?php echo $e($infl['name']); ?> Replace</label>
                <input type="text" class="form-control" id="inf_rp_subject" maxlength="200" placeholder="Woman in the red jacket">
            </div>
            <div class="inf-field">
                <div class="inf-label">What <?php echo ((string) ($infl['gender'] ?? '') === 'man') ? 'He Wears' : 'She Wears'; ?></div>
                <div class="inf-seg" id="inf_rp_outfit" role="group" aria-label="Outfit">
                    <button type="button" class="inf-seg__opt is-on" data-value="video" aria-pressed="true"><span>The Video's Outfit</span></button>
                    <button type="button" class="inf-seg__opt" data-value="reference" aria-pressed="false"><span><?php echo ((string) ($infl['gender'] ?? '') === 'man') ? 'His' : 'Her'; ?> Own Outfit</span></button>
                </div>
            </div>
            <div class="inf-switches">
                <label class="form-check form-switch inf-switch" for="inf_rp_lock"><input class="form-check-input" type="checkbox" id="inf_rp_lock" checked><span>Leave Other People Unchanged</span></label>
                <label class="form-check form-switch inf-switch" for="inf_rp_text"><input class="form-check-input" type="checkbox" id="inf_rp_text"><span>Remove On-Screen Text And Subtitles</span></label>
            </div>
            <div class="inf-field">
                <div class="inf-label">Model</div>
                <div class="inf-opts" id="inf_rp_model">
                    <?php foreach ((array) ($cfg['pickers']['replace'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <details class="inf-fold" id="inf_rp_fold">
                <summary>Prompt</summary>
                <div class="inf-field">
                    <div class="inf-field__row"><label class="visually-hidden" for="inf_rp_prompt">Prompt</label><button type="button" class="inf-link" id="inf_rp_rewrite" hidden><i class="fa-solid fa-rotate-right"></i> Rewrite</button></div>
                <textarea class="form-control inf-gen__prompt" id="inf_rp_prompt" maxlength="4000" rows="7" placeholder="Choose a source video" disabled></textarea>
                </div>
            </details>
                <p class="inf-err" id="inf_rp_err" role="alert" hidden></p>
            <label class="form-check inf-attest" for="inf_rp_attest">
                <input class="form-check-input" type="checkbox" id="inf_rp_attest">
                <span>I own this video or have the rights to use it</span>
            </label>
            </div>

            <div class="inf-gen__submit">
                <span class="inf-wiz__meta" id="inf_rp_cost"></span>
                <button type="button" class="btn btn-primary" id="inf_rp_go" disabled><i class="fa-solid fa-wand-magic-sparkles"></i> Replace Character</button>
            </div>
        </form>
        <?php $idle_title = 'Put ' . $infl['name'] . ' Into A Video'; $idle_text = 'Choose a video of someone else. ' . $infl['name'] . ' takes the place of the person you name, and everything else stays as it is.'; require __DIR__ . '/_vresult.php'; ?>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
