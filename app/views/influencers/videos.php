<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config; $gp = InfluencerService::prompts_for((string) ($infl['gender'] ?? 'woman')); ?>


    <div class="inf-gen" id="inf_gen" data-still="<?php echo (int) ($this->still_asset_id ?? 0); ?>">
        <form class="inf-gen__form" id="inf_vid_form" autocomplete="off" onsubmit="return false;">
            <label class="inf-who inf-who--form" for="inf_who">
                <span class="inf-who__badge"><?php if (!empty($infl['cover_url'])): ?><img src="<?php echo $e($infl['cover_url']); ?>" alt=""><?php else: ?><i class="fa-regular fa-user"></i><?php endif; ?></span>
                <select class="form-select inf-who__select" id="inf_who">
                    <?php foreach ($ready as $r): ?>
                    <option value="<?php echo (int) $r['id']; ?>"<?php echo ((int) $r['id'] === (int) $infl['id']) ? ' selected' : ''; ?>><?php echo $e($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="inf-field">
                <div class="inf-chips inf-stills__roles" id="inf_still_roles" role="group" aria-label="Filter images" hidden></div>
                <div class="inf-photos inf-stills" id="inf_stills"><span class="inf-wiz__meta">Loading images…</span></div>
            </div>
            <div class="inf-field">
                <div class="inf-field__row"><label class="visually-hidden" for="inf_vprompt">Prompt</label><button type="button" class="inf-link" id="inf_vprompt_auto"><i class="fa-solid fa-wand-magic-sparkles"></i> Write a prompt</button></div>
                <textarea class="form-control" id="inf_vprompt" maxlength="2000" placeholder="<?php echo $e($gp['video'][0]); ?>"></textarea>
            </div>
            <div class="inf-field">
                <div class="inf-label">Prebuilt</div>
                <div class="inf-chips" id="inf_vprompt_chips">
                    <?php foreach ($gp['video'] as $i => $t): ?>
                    <button type="button" class="inf-chip inf-chip--text" data-i="<?php echo (int) $i; ?>" title="<?php echo $e($t); ?>"><?php echo $e(mb_strlen($t) > 60 ? mb_substr($t, 0, 60) . '…' : $t); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-field">
                <div class="inf-label">Model</div>
                <div class="inf-opts" id="inf_vmodel">
                    <?php foreach ((array) ($cfg['pickers']['video'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>" data-durations="<?php echo $e(implode(',', (array) $o['durations'])); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?> · <?php $vp = array_values((array) ($o['credits_by_duration'] ?? array())); echo $vp ? 'from ' . number_format((int) min($vp)) . ' AI credits' : ''; ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-field">
                <div class="inf-label">Duration</div>
                <div class="inf-seg" id="inf_vdur" role="group" aria-label="Duration"></div>
            </div>
            <div class="inf-gen__submit">
                <span class="inf-wiz__meta" id="inf_vcost"></span>
                <button type="button" class="btn btn-primary" id="inf_vgo" disabled><i class="fa-solid fa-clapperboard"></i> Generate Video</button>
            </div>
        </form>

        <section class="inf-gen__preview">
            <div class="inf-gen__stage" id="inf_vstage">
                <div class="inf-gen__idle" id="inf_vidle"><i class="fa-solid fa-clapperboard"></i><p>Videos will appear here.</p></div>
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
                    <button type="button" class="btn btn-secondary" id="inf_vres_download"><i class="fa-solid fa-download"></i> Download</button>
                    <button type="button" class="btn btn-secondary" id="inf_vres_post"><i class="fa-solid fa-feather-pointed"></i> Use in a Post</button>
                    <button type="button" class="btn btn-secondary" id="inf_vres_message"><i class="fa-solid fa-comment-dots"></i> Send in a Message</button>
                    <button type="button" class="btn btn-secondary inf-btn--danger" id="inf_vres_delete"><i class="fa-regular fa-trash-can"></i> Delete</button>
                </div>
            </div>
        </section>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
