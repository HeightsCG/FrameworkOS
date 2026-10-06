<?php /* Result column shared by the video tool pages. $idle_text is the empty-stage line. */ ?>
        <section class="inf-gen__preview">
            <div class="inf-gen__stage" id="inf_stage">
                <div class="inf-gen__idle" id="inf_idle"><i class="fa-solid fa-clapperboard"></i><?php if (!empty($idle_title)): ?><h2><?php echo $e($idle_title); ?></h2><?php endif; ?><p><?php echo $e($idle_text); ?></p></div>
                <div class="inf-gen__busy" id="inf_busy" hidden><span class="spinner-border text-primary" role="status"></span><p id="inf_busy_text">Generating</p></div>
                <video class="inf-gen__video" id="inf_video" controls playsinline hidden></video>
            </div>
            <div class="inf-strip" id="inf_strip"></div>
            <div class="inf-result" id="inf_result" hidden>
                <div class="inf-result__actions inf-result__actions--only">
                    <button type="button" class="btn btn-secondary" id="inf_res_download"><i class="fa-solid fa-download"></i> Download</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_post"><i class="fa-solid fa-feather-pointed"></i> Use In Post</button>
                    <button type="button" class="btn btn-secondary" id="inf_res_frame"><i class="fa-regular fa-image"></i> Export Frame</button>
                </div>
            </div>
        </section>
