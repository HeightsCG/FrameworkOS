<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; ?>

    <header class="inf-head">
        <div>
            <h1 class="inf-head__title">Generate Videos</h1>
            <p class="inf-head__sub">A still of her supplies the likeness; the prompt describes the motion.</p>
        </div>
        <div class="inf-head__actions">
            <label class="inf-who" for="inf_who">
                <span class="inf-who__badge"><?php if (!empty($infl['cover_url'])): ?><img src="<?php echo $e($infl['cover_url']); ?>" alt=""><?php else: ?><i class="fa-regular fa-user"></i><?php endif; ?></span>
                <select class="form-select inf-who__select" id="inf_who">
                    <?php foreach ($ready as $r): ?>
                    <option value="<?php echo (int) $r['id']; ?>"<?php echo ((int) $r['id'] === (int) $infl['id']) ? ' selected' : ''; ?>><?php echo $e($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </header>

    <div class="inf-gen" id="inf_gen" data-still="<?php echo (int) ($this->still_asset_id ?? 0); ?>">
        <form class="inf-gen__form" id="inf_vid_form" autocomplete="off" onsubmit="return false;">
            <div class="inf-field">
                <div class="inf-field__row"><div class="inf-label">Still</div><a class="inf-link" href="/influencers/images/<?php echo (int) $infl['id']; ?>">Generate more</a></div>
                <div class="inf-photos inf-stills" id="inf_stills"><span class="inf-wiz__meta">Loading her images…</span></div>
            </div>
            <div class="inf-field">
                <label class="inf-label" for="inf_vprompt">Motion</label>
                <textarea class="form-control" id="inf_vprompt" maxlength="2000" placeholder="She turns toward the camera and smiles, hair moving in a light breeze, slow push in"></textarea>
            </div>
            <div class="inf-field">
                <div class="inf-label">Model</div>
                <div class="inf-opts" id="inf_vmodel">
                    <?php foreach ((array) ($cfg['pickers']['video'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>" data-durations="<?php echo $e(implode(',', (array) $o['durations'])); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-field">
                <div class="inf-label">Duration</div>
                <div class="inf-seg" id="inf_vdur" role="group" aria-label="Duration"></div>
            </div>
            <details class="inf-more" id="inf_vmore">
                <summary>More settings</summary>
                <div class="inf-grid inf-more__body">
                    <div class="inf-field"><div class="inf-label">Content</div>
                        <div class="inf-seg" id="inf_vlevel" role="group"><button type="button" class="inf-seg__opt is-on" data-value="safe" aria-pressed="true"><span>Safe</span></button><button type="button" class="inf-seg__opt" data-value="spicy" aria-pressed="false"><span>Spicy</span></button></div></div>
                </div>
            </details>
            <div class="inf-gen__submit">
                <span class="inf-wiz__meta" id="inf_vcost"></span>
                <button type="button" class="btn btn-primary" id="inf_vgo" disabled><i class="fa-solid fa-clapperboard"></i> Generate video</button>
            </div>
        </form>

        <section class="inf-gen__preview">
            <div class="inf-gen__stage" id="inf_vstage">
                <div class="inf-gen__idle" id="inf_vidle"><i class="fa-solid fa-clapperboard"></i><p>Her videos will appear here.</p></div>
                <div class="inf-gen__busy" id="inf_vbusy" hidden><span class="spinner-border text-primary" role="status"></span><p id="inf_vbusy_text">Generating</p></div>
                <video class="inf-gen__video" id="inf_video" controls playsinline hidden></video>
            </div>
            <div class="inf-strip" id="inf_vstrip"></div>
            <div class="inf-result" id="inf_vresult" hidden>
                <div class="inf-grid">
                    <div class="inf-field"><div class="inf-label">Model</div><div class="inf-result__model" id="inf_vres_model"></div></div>
                    <div class="inf-field"><div class="inf-label">Duration</div><div class="inf-result__model" id="inf_vres_dur"></div></div>
                    <div class="inf-field inf-field--full"><div class="inf-label">Motion</div><div class="inf-result__text" id="inf_vres_prompt"></div></div>
                </div>
                <div class="inf-result__actions">
                    <span class="inf-result__spacer"></span>
                    <button type="button" class="btn btn-secondary" id="inf_vres_download"><i class="fa-solid fa-download"></i> Download</button>
                    <button type="button" class="btn btn-secondary" id="inf_vres_post"><i class="fa-solid fa-feather-pointed"></i> Use in a post</button>
                    <button type="button" class="btn btn-secondary inf-btn--danger" id="inf_vres_delete"><i class="fa-regular fa-trash-can"></i> Delete</button>
                </div>
            </div>
        </section>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
