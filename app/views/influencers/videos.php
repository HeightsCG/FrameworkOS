<?php require __DIR__ . '/_top.php'; ?>
<?php $infl = $this->influencer; $ready = (array) ($this->ready ?? array()); $cfg = (array) $this->config;
      /* Brand mode (/influencers/videos/0): any Library image becomes a video with media_generate_video; no influencer prompts or facts. */
      $brand = !empty($this->brand_mode);
      $gp = $brand ? array('video' => array('Slow push-in, light shifting across the scene')) : InfluencerService::prompts_for((string) ($infl['gender'] ?? 'woman')); ?>


    <?php /* Canvas first, as on Generate Images: her videos fill the page; one bar at the bottom makes more. */ ?>
    <div class="inf-cv" id="inf_gen" data-cv data-still="<?php echo (int) ($this->still_asset_id ?? 0); ?>">
        <div class="inf-cv__body">
            <section class="inf-cv__canvas" aria-label="Videos">
                <div class="inf-cv__empty" id="inf_vidle">
                    <i class="fa-solid fa-clapperboard" aria-hidden="true"></i>
                    <h2>No Videos Yet</h2>
                    <p>Choose an image below, say how it should move, and the clip appears here.</p>
                </div>
                <div class="inf-strip inf-cv__grid" id="inf_vstrip"></div>
            </section>
            <aside class="inf-cv__viewer" id="inf_viewer" aria-label="Selected video">
                <button type="button" class="inf-cv__close" id="inf_viewer_close" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                <div class="inf-cv__stage" id="inf_vstage">
                    <video class="inf-gen__video" id="inf_video" controls playsinline hidden></video>
                </div>
                <div class="inf-cv__actions" id="inf_vresult" hidden>
                    <?php if (!$brand): ?>
                    <div class="inf-cv__facts"><div class="inf-grid">
                    <div class="inf-field"><div class="inf-label">Model</div><div class="inf-result__model" id="inf_vres_model"></div></div>
                    <div class="inf-field"><div class="inf-label">Duration</div><div class="inf-result__model" id="inf_vres_dur"></div></div>
                    <div class="inf-field inf-field--full"><div class="inf-label">Motion</div><div class="inf-result__text" id="inf_vres_prompt"></div></div>
                </div></div>
                    <?php endif; ?>
                    <div class="inf-result__actions">
                    <button type="button" class="btn btn-secondary" id="inf_vres_download"><i class="fa-solid fa-download"></i> Download</button>
                    <button type="button" class="btn btn-secondary" id="inf_vres_post"><i class="fa-solid fa-feather-pointed"></i> Use In Post</button>
                    <button type="button" class="btn btn-secondary" id="inf_vres_message"><i class="fa-solid fa-comment-dots"></i> Send In Message</button>
                    <button type="button" class="btn btn-secondary inf-btn--danger" id="inf_vres_delete"><i class="fa-regular fa-trash-can"></i> Delete</button>
                </div>
                </div>
            </aside>
        </div>

        <form class="inf-cv__dock" id="inf_vid_form" autocomplete="off" onsubmit="return false;">
            <div class="inf-pop inf-pop--modal inf-pop--pick" id="inf_pop_still" role="dialog" aria-modal="true" aria-labelledby="inf_pop_still_h" hidden>
                <div class="inf-pop__h" id="inf_pop_still_h">Choose The Image To Animate <button type="button" class="inf-pop__x" data-pop-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
                <div class="inf-chips inf-stills__roles" id="inf_still_roles" role="group" aria-label="Filter images" hidden></div>
                <div class="inf-photos inf-stills" id="inf_stills"><span class="inf-wiz__meta">Loading images…</span></div>
            </div>
            <?php if (!$brand): ?>
            <div class="inf-pop inf-pop--modal" id="inf_pop_videas" role="dialog" aria-modal="true" aria-labelledby="inf_pop_videas_h" hidden>
                <div class="inf-pop__h" id="inf_pop_videas_h">Ideas <button type="button" class="inf-pop__x" data-pop-close aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
                <div class="inf-chips" id="inf_vprompt_chips">
                    <?php foreach ($gp['video'] as $i => $t): ?>
                    <button type="button" class="inf-chip inf-chip--text" data-i="<?php echo (int) $i; ?>" title="<?php echo $e($t); ?>"><?php echo $e($t); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="inf-pop" id="inf_pop_vmodel" hidden>
                <div class="inf-pop__h">Model</div>
                <div class="inf-opts" id="inf_vmodel">
                    <?php foreach ((array) ($cfg['pickers']['video'] ?? array()) as $i => $o): ?>
                    <button type="button" class="inf-opt<?php echo $i === 0 ? ' is-on' : ''; ?>" data-key="<?php echo $e($o['key']); ?>" data-durations="<?php echo $e(implode(',', (array) $o['durations'])); ?>"><span class="inf-opt__t"><?php echo $e($o['label']); ?></span><span class="inf-opt__p"><?php echo $e($o['purpose']); ?> · <?php $vp = array_values((array) ($o['credits_by_duration'] ?? array())); echo $vp ? 'from ' . number_format((int) min($vp)) . ' AI credits' : ''; ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="inf-pop" id="inf_pop_vdur" hidden>
                <div class="inf-pop__h">Length</div>
                <div class="inf-seg" id="inf_vdur" role="group" aria-label="Duration"></div>
            </div>

            <div class="inf-cv__row">
                <button type="button" class="inf-cv__source" data-pop="inf_pop_still" aria-expanded="false" aria-label="Choose the image to animate"><img alt="" data-pop-thumb="#inf_stills .inf-photo.is-on img" hidden><span class="inf-cv__sourceph"><i class="fa-regular fa-image" aria-hidden="true"></i><span>Choose Image</span></span></button>
                <div class="inf-cv__write">
                    <label class="visually-hidden" for="inf_vprompt">How it should move</label>
                    <textarea class="form-control inf-cv__prompt" id="inf_vprompt" rows="2" maxlength="2000" placeholder="<?php echo $e($gp['video'][0]); ?>"></textarea>
                </div>
            </div>
            <div class="inf-cv__bar">
                <?php if (!$brand): ?>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_videas" aria-expanded="false"><i class="fa-regular fa-lightbulb" aria-hidden="true"></i> Ideas</button>
                <button type="button" class="inf-cvchip" id="inf_vprompt_auto"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Write It For Me</button>
                <span class="inf-cv__sep" aria-hidden="true"></span>
                <?php endif; ?>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_vmodel" aria-expanded="false"><span data-pop-label="#inf_vmodel .inf-opt.is-on .inf-opt__t">Model</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>
                <button type="button" class="inf-cvchip" data-pop="inf_pop_vdur" aria-expanded="false"><span data-pop-label="#inf_vdur .inf-seg__opt.is-on">Length</span> <i class="fa-solid fa-chevron-up" aria-hidden="true"></i></button>
                <span class="inf-cv__status" id="inf_vbusy" role="status" hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> <span id="inf_vbusy_text">Generating</span></span>
                <span class="inf-cv__cost" id="inf_vcost"></span>
                <button type="button" class="btn btn-primary inf-cv__go" id="inf_vgo" disabled><i class="fa-solid fa-clapperboard"></i> Generate Video</button>
            </div>
        </form>
    </div>

<?php require __DIR__ . '/_bottom.php'; ?>
