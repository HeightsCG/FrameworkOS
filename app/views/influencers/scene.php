<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config;
      $others = array_values(array_filter($ready, function ($r) use ($infl) { return (int) $r['id'] !== (int) $infl['id']; })); ?>

    <div class="inf-gen inf-gen--wide" id="inf_gen" data-cv>
        <form class="inf-gen__form" id="inf_sc_form" autocomplete="off" onsubmit="return false;">
            <?php require __DIR__ . '/_vhead.php'; ?>

            <div class="inf-field">
                <label class="inf-label" for="inf_sc_setting">Where It Happens</label>
                <input type="text" class="form-control" id="inf_sc_setting" maxlength="800" placeholder="Parked car at dusk, phone on the dashboard">
            </div>

            <div class="inf-field">
                <div class="inf-field__row"><div class="inf-label">What Is Said</div><button type="button" class="inf-link" id="inf_sc_add"><i class="fa-solid fa-plus"></i> Add Another Line</button></div>
                <ol class="inf-lines" id="inf_sc_lines"></ol>
                <p class="inf-err" id="inf_sc_err" role="alert" hidden></p>
            </div>

            <div class="inf-field">
                <div class="inf-field__row"><div class="inf-label">Someone Else In The Scene</div><?php echo Tutorials::button('29'); ?></div>
                <div class="inf-seg" id="inf_sc_second" role="group" aria-label="Second character">
                    <button type="button" class="inf-seg__opt is-on" data-value="none" aria-pressed="true"><span>No One</span></button>
                    <button type="button" class="inf-seg__opt" data-value="influencer" aria-pressed="false"<?php echo empty($others) ? ' disabled title="Train another influencer first"' : ''; ?>><span>Another Influencer</span></button>
                    <button type="button" class="inf-seg__opt" data-value="described" aria-pressed="false"><span>Someone I Describe</span></button>
                </div>
            </div>
            <div class="inf-field" id="inf_sc_other_wrap" hidden>
                <label class="inf-label" for="inf_sc_other">Influencer</label>
                <select class="form-select" id="inf_sc_other"><?php foreach ($others as $r): ?><option value="<?php echo (int) $r['id']; ?>"><?php echo $e($r['name']); ?></option><?php endforeach; ?></select>
            </div>
            <div class="inf-grid" id="inf_sc_desc_wrap" hidden>
                <div class="inf-field inf-field--full"><label class="inf-label" for="inf_sc_desc">Description</label><input type="text" class="form-control" id="inf_sc_desc" maxlength="300" placeholder="Tall blonde woman in a denim jacket"></div>
                <div class="inf-field inf-field--full"><div class="inf-label">Gender</div>
                    <div class="inf-seg" id="inf_sc_gender" role="group" aria-label="Gender"><button type="button" class="inf-seg__opt is-on" data-value="woman" aria-pressed="true"><span>Woman</span></button><button type="button" class="inf-seg__opt" data-value="man" aria-pressed="false"><span>Man</span></button></div></div>
            </div>
            <details class="inf-fold">
                <summary>Video Settings</summary>
            <div class="inf-field">
                <div class="inf-label">Quality</div>
                <div class="inf-opts" id="inf_sc_model">
                    <?php foreach ((array) ($cfg['pickers']['scene'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-field">
                <label class="inf-label" for="inf_sc_secs">Length</label>
                <select class="form-select" id="inf_sc_secs"><option value="0">Fit The Script</option><?php for ($n = 4; $n <= 30; $n++): ?><option value="<?php echo $n; ?>"><?php echo $n; ?> seconds</option><?php endfor; ?></select>
            </div>
            <div class="inf-field">
                <div class="inf-label">Size</div>
                <div class="inf-seg" id="inf_sc_size" role="group" aria-label="Size"><?php echo Aspect::seg_buttons('inf-seg__opt', 'data-value', Aspect::DEFAULT_VIDEO, InfluencerConfig::resolve_model('scene', '')); ?></div>
            </div>
            </details>

            <div class="inf-gen__submit">
                <span class="inf-wiz__meta" id="inf_sc_cost"></span>
                <button type="button" class="btn btn-primary" id="inf_sc_go" disabled><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Scene</button>
            </div>
        </form>
        <?php $idle_title = 'A Scene Where ' . $infl['name'] . ' Speaks'; $idle_text = 'Say where it happens and write the lines. The scene is filmed in one take with the words spoken.'; require __DIR__ . '/_vresult.php'; ?>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
