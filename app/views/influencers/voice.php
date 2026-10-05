<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; $vc = (array) ($cfg['voice'] ?? array());
      $her = ((string) ($infl['gender'] ?? 'woman') === 'man') ? 'his' : 'her'; ?>

    <div class="inf-gen" id="inf_gen">
        <form class="inf-gen__form" id="inf_vo_form" autocomplete="off" onsubmit="return false;">
            <label class="inf-who inf-who--form" for="inf_who">
                <span class="inf-who__badge"><?php if (!empty($infl['cover_url'])): ?><img src="<?php echo $e($infl['cover_url']); ?>" alt=""><?php else: ?><i class="fa-regular fa-user"></i><?php endif; ?></span>
                <select class="form-select inf-who__select" id="inf_who">
                    <?php foreach ($ready as $r): ?>
                    <option value="<?php echo (int) $r['id']; ?>"<?php echo ((int) $r['id'] === (int) $infl['id']) ? ' selected' : ''; ?>><?php echo $e($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

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
            <div class="inf-takes" id="inf_vo_takes" hidden></div>
        </form>

        <section class="inf-gen__preview">
            <div class="inf-result" id="inf_vo_voices_card">
                <div class="inf-sec__head"><h2 class="inf-sec__h">Voices</h2><span class="inf-wiz__meta" id="inf_vo_count"></span></div>
                <div class="inf-state-loading" id="inf_vo_loading"><span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading voices…</div>
                <div class="inf-state-error" id="inf_vo_loaderr" hidden>Could not load <?php echo $her; ?> voices. <button type="button" class="inf-link" id="inf_vo_reload">Try Again</button></div>
                <div class="inf-empty inf-voices__empty" id="inf_vo_empty" hidden>
                    <span class="inf-empty__ic"><i class="fa-solid fa-microphone-lines"></i></span>
                    <h2 class="inf-empty__title">No Voice Yet</h2>
                    <p class="inf-empty__text">Design a voice below to give <?php echo $her === 'his' ? 'him' : 'her'; ?> one.</p>
                </div>
                <ul class="inf-voices" id="inf_vo_list" hidden></ul>
            </div>

            <div class="inf-result" id="inf_vo_design_card">
                <div class="inf-sec__head"><h2 class="inf-sec__h">Design A Voice</h2></div>
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
                <ol class="inf-cands" id="inf_vd_cands" hidden></ol>
            </div>
        </section>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
