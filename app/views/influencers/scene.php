<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config;
      $others = array_values(array_filter($ready, function ($r) use ($infl) { return (int) $r['id'] !== (int) $infl['id']; })); ?>

    <?php /* Canvas first, as on Generate Images: her scenes fill the page; the scene is written in one bar at the bottom. */ ?>
    <div class="inf-cv" id="inf_gen" data-cv>
        <div class="inf-cv__body">
            <section class="inf-cv__canvas" aria-label="Scenes">
                <div class="inf-cv__empty" id="inf_idle">
                    <i class="fa-solid fa-clapperboard" aria-hidden="true"></i>
                    <h2>A Scene Where <?php echo $e($infl['name']); ?> Speaks</h2>
                    <p>Say where it happens and write what is said. The scene is filmed in one take with the words spoken.</p>
                </div>
                <div class="inf-strip inf-cv__grid" id="inf_strip"></div>
            </section>
            <aside class="inf-cv__viewer" id="inf_viewer" aria-label="Selected scene">
                <button type="button" class="inf-cv__close" id="inf_viewer_close" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                <div class="inf-cv__stage" id="inf_stage">
                    <video class="inf-gen__video" id="inf_video" controls playsinline hidden></video>
                </div>
                <div class="inf-cv__actions" id="inf_result" hidden>
                    <div class="inf-result__actions inf-result__actions--only">
                        <button type="button" class="btn btn-secondary" id="inf_res_download"><i class="fa-solid fa-download"></i> Download</button>
                        <button type="button" class="btn btn-secondary" id="inf_res_post"><i class="fa-solid fa-feather-pointed"></i> Use in a Post</button>
                        <button type="button" class="btn btn-secondary" id="inf_res_frame"><i class="fa-regular fa-image"></i> Export Frame</button>
                    </div>
                </div>
            </aside>
        </div>

        <form class="inf-cv__dock inf-cv__dock--scene" id="inf_sc_form" autocomplete="off" onsubmit="return false;">
            <div class="inf-pop inf-pop--wide inf-pop--stay" id="inf_pop_swho" hidden>
                <div class="inf-pop__h">Who Else Is In The Scene</div>
                <div class="inf-seg" id="inf_sc_second" role="group" aria-label="Second character">
                    <button type="button" class="inf-seg__opt is-on" data-value="none" aria-pressed="true"><span>No One</span></button>
                    <button type="button" class="inf-seg__opt" data-value="influencer" aria-pressed="false"<?php echo empty($others) ? ' disabled title="Train another influencer first"' : ''; ?>><span>Another Influencer</span></button>
                    <button type="button" class="inf-seg__opt" data-value="described" aria-pressed="false"><span>Someone I Describe</span></button>
                </div>
                <div class="inf-field" id="inf_sc_other_wrap" hidden>
                <label class="inf-label" for="inf_sc_other">Which Influencer</label>
                <select class="form-select" id="inf_sc_other"><?php foreach ($others as $r): ?><option value="<?php echo (int) $r['id']; ?>"><?php echo $e($r['name']); ?></option><?php endforeach; ?></select>
            </div>
                <div class="inf-grid" id="inf_sc_desc_wrap" hidden>
                <div class="inf-field inf-field--full"><label class="inf-label" for="inf_sc_desc">What The Other Person Looks Like</label><input type="text" class="form-control" id="inf_sc_desc" maxlength="300" placeholder="Tall blonde woman in a denim jacket"></div>
                <div class="inf-field inf-field--full"><div class="inf-label">The Other Person Is A</div>
                    <div class="inf-seg" id="inf_sc_gender" role="group" aria-label="Gender"><button type="button" class="inf-seg__opt is-on" data-value="woman" aria-pressed="true"><span>Woman</span></button><button type="button" class="inf-seg__opt" data-value="man" aria-pressed="false"><span>Man</span></button></div></div>
            </div>
            </div>
            <div class="inf-pop" id="inf_pop_squal" hidden>
                <div class="inf-pop__h">Quality</div>
                <div class="inf-opts" id="inf_sc_model">
                    <?php foreach ((array) ($cfg['pickers']['scene'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-pop" id="inf_pop_ssize" hidden>
                <div class="inf-pop__h">Size</div>
                <div class="inf-seg" id="inf_sc_size" role="group" aria-label="Size"><?php echo Aspect::seg_buttons('inf-seg__opt', 'data-value', Aspect::DEFAULT_VIDEO, InfluencerConfig::resolve_model('scene', '')); ?></div>
            </div>

            <label class="visually-hidden" for="inf_sc_setting">Where it happens</label>
            <input type="text" class="form-control inf-cv__setting" id="inf_sc_setting" maxlength="800" placeholder="Where it happens: a parked car at dusk, phone on the dashboard">
            <ol class="inf-lines" id="inf_sc_lines"></ol>
            <p class="inf-err" id="inf_sc_err" role="alert" hidden></p>
            <div class="inf-cv__bar">
                <button type="button" class="inf-cvchip" id="inf_sc_add"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Another Line</button>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_swho" aria-expanded="false"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> <span data-pop-label="#inf_sc_second .inf-seg__opt.is-on" data-pop-prefix="With " data-pop-none="No One" data-pop-default="Add Someone Else">Add Someone Else</span></button>
                <span class="inf-cv__sep" aria-hidden="true"></span>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_squal" aria-expanded="false"><span data-pop-label="#inf_sc_model .inf-opt.is-on .inf-opt__t">Quality</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>
                <select class="inf-cvchip inf-cvchip--select" aria-label="Length" id="inf_sc_secs"><option value="0">Fit The Script</option><?php for ($n = 4; $n <= 30; $n++): ?><option value="<?php echo $n; ?>"><?php echo $n; ?> seconds</option><?php endfor; ?></select>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_ssize" aria-expanded="false"><span data-pop-label="#inf_sc_size .inf-seg__opt.is-on">Size</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>
                <?php echo Tutorials::button('29'); ?>
                <span class="inf-cv__status" id="inf_busy" role="status" hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <span id="inf_busy_text">Generating</span></span>
                <span class="inf-cv__cost" id="inf_sc_cost"></span>
                <button type="button" class="btn btn-primary inf-cv__go" id="inf_sc_go" disabled><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Scene</button>
            </div>
        </form>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
