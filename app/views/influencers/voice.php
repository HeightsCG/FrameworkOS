<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; $vc = (array) ($cfg['voice'] ?? array());
      $her = ((string) ($infl['gender'] ?? 'woman') === 'man') ? 'his' : 'her'; ?>

    <?php /* Two jobs, in the order they happen: give her a voice, then make her speak. */ ?>
    <nav class="inf-sub" id="inf_vo_tabs" role="tablist" aria-label="Voice">
        <button type="button" class="inf-sub__item is-on" role="tab" id="inf_vo_tab_voices" data-tab="voices" aria-selected="true" aria-controls="inf_vo_panel_voices">Her Voices</button>
        <button type="button" class="inf-sub__item" role="tab" id="inf_vo_tab_speech" data-tab="speech" aria-selected="false" aria-controls="inf_vo_panel_speech">Text To Speech</button>
    </nav>

    <div class="inf-gen inf-gen--wide" data-cv id="inf_vo_panel_voices" role="tabpanel" aria-labelledby="inf_vo_tab_voices">
        <form class="inf-gen__form" id="inf_vo_design_card" autocomplete="off" onsubmit="return false;">
                <div class="inf-sec__head"><h2 class="inf-sec__h">Design A Voice</h2><span class="inf-working" id="inf_vd_filling" role="status" hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Generating</span><button type="button" class="inf-link" id="inf_vd_refill"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Generate</button></div>
                <div class="inf-grid">
                    <div class="inf-field inf-field--full"><label class="inf-label" for="inf_vd_age">Age And Vibe</label><input type="text" class="form-control" id="inf_vd_age" maxlength="200" placeholder="24 year old, warm and playful, slightly breathy"><p class="inf-err" data-err="age_vibe" role="alert" hidden></p></div>
                    <div class="inf-field"><label class="inf-label" for="inf_vd_keyword">Social Keyword</label>
                        <select class="form-select" id="inf_vd_keyword"><?php foreach ((array) ($vc['keywords'] ?? array()) as $k): ?><option value="<?php echo $e($k); ?>"><?php echo $e(ucfirst($k)); ?></option><?php endforeach; ?></select></div>
                    <div class="inf-field"><label class="inf-label" for="inf_vd_tone">Tone</label><input type="text" class="form-control" id="inf_vd_tone" maxlength="200" placeholder="Relaxed, a little teasing"></div>
                    <div class="inf-field"><label class="inf-label" for="inf_vd_city">Accent City</label><input type="text" class="form-control" id="inf_vd_city" maxlength="80" placeholder="Miami"></div>
                    <div class="inf-field"><label class="inf-label" for="inf_vd_country">Accent Country</label><input type="text" class="form-control" id="inf_vd_country" maxlength="80" placeholder="United States"></div>
                    <div class="inf-field inf-field--full">
                        <div class="inf-field__row"><label class="inf-label" for="inf_vd_text">Preview Text</label><span class="inf-wiz__meta" id="inf_vd_count"></span></div>
                        <textarea class="form-control" id="inf_vd_text" rows="3" maxlength="<?php echo (int) ($vc['preview_max'] ?? 1000); ?>">Okay, I have to tell you something, and you are not allowed to laugh. I have been thinking about this all week and I finally decided to just say it.</textarea>
                        <p class="inf-err" data-err="preview_text" role="alert" hidden></p>
                    </div>
                </div>
                <div class="inf-gen__submit">
                    <span class="inf-wiz__meta" id="inf_vd_cost"></span>
                    <button type="button" class="btn btn-secondary" id="inf_vd_go"><i class="fa-solid fa-sliders"></i> Design Voice</button>
                </div>
                <p class="inf-err" id="inf_vd_err" role="alert" hidden></p>
            </form>
        <section class="inf-gen__preview">
            <?php /* The voices just designed appear here, beside the form, to listen to and save: never under it. */ ?>
            <div class="inf-result inf-candcard" id="inf_vd_cands_card">
                <div class="inf-sec__head"><h2 class="inf-sec__h">Listen And Save</h2></div>
                <ol class="inf-cands" id="inf_vd_cands" hidden></ol>
            </div>
            <div class="inf-result" id="inf_vo_voices_card">
                <div class="inf-sec__head"><h2 class="inf-sec__h">Voices</h2><span class="inf-wiz__meta" id="inf_vo_count"></span></div>
                <div class="inf-state-loading" id="inf_vo_loading"><span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading voices…</div>
                <div class="inf-state-error" id="inf_vo_loaderr" hidden>Could not load <?php echo $her; ?> voices. <button type="button" class="inf-link" id="inf_vo_reload">Try Again</button></div>
                <div class="inf-empty inf-voices__empty" id="inf_vo_empty" hidden>
                    <span class="inf-empty__ic"><i class="fa-solid fa-microphone-lines"></i></span>
                    <h2 class="inf-empty__title">No Voice Yet</h2>
                    <p class="inf-empty__text">Design a voice on the left to give <?php echo $her === 'his' ? 'him' : 'her'; ?> one.</p>
                </div>
                <ul class="inf-voices" id="inf_vo_list" hidden></ul>
            </div>
        </section>
    </div>

    <div class="inf-gen inf-gen--wide" data-cv id="inf_vo_panel_speech" role="tabpanel" aria-labelledby="inf_vo_tab_speech" hidden>
        <form class="inf-gen__form" id="inf_vo_form" autocomplete="off" onsubmit="return false;">

            <div class="inf-field">
                <div class="inf-field__row"><h2 class="inf-sec__h">Text To Speech</h2><?php echo Tutorials::button('31'); ?></div>
            </div>
            <div class="inf-field">
                <label class="inf-label" for="inf_vo_voice">Voice</label>
                <select class="form-select" id="inf_vo_voice" disabled><option>Loading</option></select>
            </div>
            <div class="inf-field">
                <div class="inf-field__row"><label class="inf-label" for="inf_vo_text">Script</label><button type="button" class="inf-link" id="inf_vo_enhance"><i class="fa-solid fa-wand-magic-sparkles"></i> Enhance</button></div>
                <textarea class="form-control inf-gen__prompt" id="inf_vo_text" rows="6" maxlength="<?php echo (int) ($vc['speech_max'] ?? 3000); ?>" placeholder="Okay, I have to tell you something"></textarea>
                <p class="inf-err" id="inf_vo_err" role="alert" hidden></p>
            </div>
            <div class="inf-field">
                <div class="inf-label">Audio Tags</div>
                <div class="inf-tags" id="inf_vo_tags">
                    <?php foreach ((array) ($vc['tags'] ?? array()) as $group => $tags): ?>
                    <div class="inf-tags__group"><span class="inf-tags__name"><?php echo $e($group); ?></span>
                        <div class="inf-chips"><?php foreach ($tags as $t): ?><button type="button" class="inf-chip" data-tag="<?php echo $e($t); ?>"><?php echo $e($t); ?></button><?php endforeach; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-gen__submit">
                <span class="inf-wiz__meta" id="inf_vo_cost"></span>
                <button type="button" class="btn btn-primary" id="inf_vo_go" disabled><i class="fa-solid fa-microphone-lines"></i> Generate Speech</button>
            </div>
        </form>
        <section class="inf-gen__preview">
            <div class="inf-result">
                <div class="inf-sec__head"><h2 class="inf-sec__h">Takes</h2></div>
                <div class="inf-empty inf-voices__empty" id="inf_vo_takes_empty">
                    <span class="inf-empty__ic"><i class="fa-solid fa-waveform-lines"></i></span>
                    <h2 class="inf-empty__title">No Takes Yet</h2>
                    <p class="inf-empty__text">Write a script and generate speech. Each run gives you takes to compare and save.</p>
                </div>
                <div class="inf-takes" id="inf_vo_takes" hidden></div>
            </div>
        </section>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
