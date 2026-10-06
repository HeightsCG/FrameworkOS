<link rel="stylesheet" href="/css/studio.css?v=<?php echo @filemtime(Main::app_path().'/public/css/studio.css'); ?>">

<?php if (empty($this->is_creator)): ?>
    <div class="cs-gate">
        <i class="fa-solid fa-photo-film cs-gate__icon"></i>
        <h1 class="cs-gate__title">The Content Studio is for creators</h1>
        <p class="cs-gate__text">Turn on your creator account to upload media, publish posts, and schedule your content.</p>
        <a href="/account/settings" class="btn btn-primary">Go to Settings</a>
    </div>
<?php else: ?>

<div class="cs<?php echo !empty($this->needs_plan) ? ' cs--free' : ''; ?>" id="cs" data-s3-ready="<?php echo !empty($this->s3_ready) ? '1' : '0'; ?>">

    <header class="cs-head">
        <div>
            <h1 class="cs-head__title">Content Studio</h1>
            <p class="cs-head__sub">Upload once, use everywhere. Everything for your content lives here.</p>
        </div>
        <div class="cs-head__actions">
            <?php echo Tutorials::button('13'); ?>
            <button type="button" class="btn btn-secondary cs-privacy" id="csPrivacy" aria-pressed="false" title="Blur every thumbnail and preview in the Studio. Hover to peek."><i class="fa-solid fa-eye-slash" aria-hidden="true"></i> <span>Privacy</span></button>
            <div class="dropdown cs-create">
                <button type="button" class="btn btn-primary dropdown-toggle" id="csCreateBtn" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-plus"></i> Action
                </button>
                <ul class="dropdown-menu dropdown-menu-end cs-create__menu">
                    <li class="cs-create__label">Content</li>
                    <li><button type="button" class="dropdown-item" id="csNewPostBtn"><span class="cs-create__ic"><i class="fa-solid fa-feather-pointed"></i></span><span><strong>New Post</strong><small>Write, schedule &amp; publish</small></span></button></li>
                    <li><button type="button" class="dropdown-item" id="csSchedNew"><span class="cs-create__ic"><i class="fa-solid fa-robot"></i></span><span><strong>New Automation</strong><small>Auto-generate &amp; post on a schedule</small></span></button></li>
                    <?php if (!empty($this->inbox['can'])): ?>
                    <li><button type="button" class="dropdown-item" id="csSchedNewMsg"><span class="cs-create__ic"><i class="fa-solid fa-paper-plane"></i></span><span><strong>New Scheduled Message</strong><small>Message your fans on a schedule</small></span></button></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li class="cs-create__label">Library</li>
                    <li><button type="button" class="dropdown-item" id="csUploadBtn"><span class="cs-create__ic"><i class="fa-solid fa-arrow-up-from-bracket"></i></span><span><strong>Upload Media</strong><small>Add files to your library</small></span></button></li>
                    <li><a class="dropdown-item" href="/studio/edit" id="csNewEditBtn"><span class="cs-create__ic"><i class="fa-solid fa-scissors"></i></span><span><strong>New Edit</strong><small>Join clips, add text and audio</small></span></a></li>
                    <li><button type="button" class="dropdown-item" id="csCreateCollectionBtn"><span class="cs-create__ic"><i class="fa-solid fa-folder-plus"></i></span><span><strong>Create Collection</strong><small>Group related files</small></span></button></li>
                </ul>
            </div>
        </div>
    </header>
<?php if (!empty($this->needs_plan)): ?>
    <div class="plan-bar" role="status">
        <p class="plan-bar__text"><strong>You're on Free.</strong> Everything you made is still here to view, download or delete. Choose a plan to create, publish and sell.</p>
        <a href="/account/billing" class="btn btn-primary plan-bar__btn">Choose a Plan</a>
    </div>
<?php endif; ?>

    <?php if (empty($this->s3_ready)): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation"></i>
        Media storage isn't configured yet, so uploads are turned off. Everything else works.
    </div>
    <?php endif; ?>

    <ul class="nav nav-tabs cs-tabs" id="csTabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" id="csTabPosts" data-bs-toggle="tab" data-bs-target="#csPanePosts" type="button" role="tab"><i class="fa-solid fa-rectangle-list"></i> Posts</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="csTabLibrary" data-bs-toggle="tab" data-bs-target="#csPaneLibrary" type="button" role="tab"><i class="fa-solid fa-images"></i> Library</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="csTabCalendar" data-bs-toggle="tab" data-bs-target="#csPaneCalendar" type="button" role="tab"><i class="fa-solid fa-calendar-days"></i> Calendar</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="csTabCollections" data-bs-toggle="tab" data-bs-target="#csPaneCollections" type="button" role="tab"><i class="fa-solid fa-folder"></i> Collections</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="csTabScheduler" data-bs-toggle="tab" data-bs-target="#csPaneScheduler" type="button" role="tab"><i class="fa-solid fa-robot"></i> Scheduler</button></li>
    </ul>

    <div class="tab-content cs-tabcontent">

        <!-- ============ LIBRARY ============ -->
        <div class="tab-pane fade" id="csPaneLibrary" role="tabpanel">
            <div class="cs-toolbar">
                <div class="input-group cs-search">
                    <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="search" class="form-control" id="csSearch" placeholder="Search files…" autocomplete="off">
                </div>
                <select id="csFilterType" class="form-select cs-filter" aria-label="Filter by type">
                    <option value="">All types</option>
                    <option value="image">Images</option>
                    <option value="video">Videos</option>
                    <option value="gif">GIFs</option>
                    <option value="audio">Audio</option>
                </select>
                <select id="csFilterCollection" class="form-select cs-filter" aria-label="Filter by collection">
                    <option value="">All Collections</option>
                </select>
                <?php if (!empty($this->influencers['all'])): ?>
                <select id="csFilterInfluencer" class="form-select cs-filter" aria-label="Filter by influencer">
                    <option value="">All influencers</option>
                    <?php foreach ($this->influencers['all'] as $inf): ?>
                    <option value="<?php echo (int) $inf['id']; ?>"><?php echo htmlspecialchars($inf['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <select id="csFilterUsage" class="form-select cs-filter" aria-label="Filter by usage">
                    <option value="">Used &amp; unused</option>
                    <option value="used">Used in posts</option>
                    <option value="unused">Not used yet</option>
                </select>
                <button type="button" class="btn btn-link cs-clear" id="csClearFilters" hidden>Clear</button>
                <?php echo Tutorials::button('16', 'data-push'); ?>
            </div>

            <div class="cs-dropzone" id="csDropzone">
                <div class="cs-drophint" id="csDropHint"><i class="fa-solid fa-cloud-arrow-up"></i> Drop files to upload</div>

                <div class="cs-loading" id="csLibLoading">
                    <span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading your library…
                </div>

                <div class="cs-error" id="csLibError" hidden>
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <p>We couldn't load your library.</p>
                    <button type="button" class="btn btn-outline-secondary" id="csLibRetry">Try Again</button>
                </div>

                <div class="cs-empty" id="csLibEmpty" hidden>
                    <i class="fa-solid fa-photo-film cs-empty__icon"></i>
                    <h2 class="cs-empty__title">Your library is ready for its first upload</h2>
                    <p class="cs-empty__text">Drag photos or videos anywhere here, or use the button below. They'll be safe, watermarked, and ready to post.</p>
                </div>

                <div class="cs-grid" id="csGrid" hidden></div>
            </div>
        </div>

        <div class="tab-pane fade show active" id="csPanePosts" role="tabpanel">
            <div class="cs-toolbar">
                <div class="input-group cs-search">
                    <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="search" class="form-control" id="csPostSearch" placeholder="Search posts…" autocomplete="off">
                </div>
                <select id="csPostFilter" class="form-select cs-filter" aria-label="Filter by status">
                    <option value="">All statuses</option>
                    <option value="draft">Drafts</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="published">Published</option>
                    <option value="archived">Archived</option>
                </select>
            </div>

            <div class="cs-loading" id="csPostsLoading"><span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading your posts…</div>
            <div class="cs-error" id="csPostsError" hidden>
                <i class="fa-solid fa-circle-exclamation"></i>
                <p>We couldn't load your posts.</p>
                <button type="button" class="btn btn-outline-secondary" id="csPostsRetry">Try Again</button>
            </div>
            <div class="cs-empty" id="csPostsEmpty" hidden>
                <i class="fa-solid fa-rectangle-list cs-empty__icon"></i>
                <h2 class="cs-empty__title">No Posts Yet</h2>
                <p class="cs-empty__text">Create your first post — publish it now, schedule it, or save a draft.</p>
                <button type="button" class="btn btn-primary" id="csPostsEmptyNew"><i class="fa-solid fa-plus"></i> New Post</button>
            </div>
            <div class="cs-posts" id="csPostsList" hidden></div>
        </div>

        <!-- ============ CALENDAR ============ -->
        <div class="tab-pane fade" id="csPaneCalendar" role="tabpanel">
            <div class="cs-calhead">
                <div class="cs-calnav">
                    <button type="button" class="cs-calnav__today" id="csCalToday">Today</button>
                    <div class="cs-calnav__arrows">
                        <button type="button" id="csCalPrev" aria-label="Previous month"><i class="fa-solid fa-chevron-left"></i></button>
                        <button type="button" id="csCalNext" aria-label="Next month"><i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                    <h2 class="cs-calnav__title" id="csCalTitle">—</h2>
                </div>
                <?php echo Tutorials::button('17', 'data-push'); ?>
                <div class="cs-seg cs-seg--sm" id="csCalView">
                    <button type="button" class="cs-seg__opt is-on" data-cal="month">Month</button>
                    <button type="button" class="cs-seg__opt" data-cal="week">Week</button>
                </div>
            </div>
            <div class="cs-queue" id="csCalQueue" hidden></div>
            <div class="cs-cal" id="csCal"></div>
        </div>

        <!-- ============ COLLECTIONS ============ -->
        <div class="tab-pane fade" id="csPaneCollections" role="tabpanel">
            <!-- list of collections -->
            <div id="csColList">
                <div class="cs-collections" id="csCollections"></div>
                <div class="cs-empty" id="csColEmpty" hidden>
                    <i class="fa-solid fa-folder-open cs-empty__icon"></i>
                    <h2 class="cs-empty__title">No Collections Yet</h2>
                    <p class="cs-empty__text">Group related files so they're easy to find when you post. Use “Create collection” up top to make your first one.</p>
                </div>
            </div>
            <!-- one collection's contents (shown after clicking a collection) -->
            <div id="csColDetail" hidden>
                <div class="cs-coldetail__head">
                    <button type="button" class="btn btn-link cs-coldetail__back" id="csColBack"><i class="fa-solid fa-arrow-left"></i> All Collections</button>
                    <div class="cs-coldetail__title">
                        <h2 class="cs-coldetail__name" id="csColName"></h2>
                        <span class="cs-coldetail__count" id="csColCountLbl"></span>
                    </div>
                    <div class="cs-coldetail__actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="csColRename"><i class="fa-solid fa-pen"></i> Rename</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="csColDelete"><i class="fa-solid fa-trash"></i> Delete</button>
                    </div>
                </div>
                <div class="cs-grid" id="csColGrid"></div>
                <div class="cs-empty" id="csColGridEmpty" hidden>
                    <i class="fa-solid fa-folder-open cs-empty__icon"></i>
                    <h2 class="cs-empty__title">This collection is empty</h2>
                    <p class="cs-empty__text">Add files from the Library — select files and choose “Add to collection”.</p>
                </div>
            </div>
        </div>

        <!-- ============ SCHEDULER ============ -->
        <div class="tab-pane fade" id="csPaneScheduler" role="tabpanel">
            <div class="cs-loading" id="csSchedLoading"><span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading your automations…</div>
            <div class="cs-error" id="csSchedError" hidden>
                <i class="fa-solid fa-circle-exclamation"></i>
                <p>We couldn't load your automations.</p>
                <button type="button" class="btn btn-outline-secondary" id="csSchedRetry">Try Again</button>
            </div>
            <div class="cs-empty" id="csSchedEmpty" hidden>
                <i class="fa-solid fa-robot cs-empty__icon"></i>
                <h2 class="cs-empty__title">No Automations Yet</h2>
                <p class="cs-empty__text">Set one up and the Studio will generate on-brand content and publish it on your schedule, even while you're away.</p>
                <button type="button" class="btn btn-primary" id="csSchedEmptyNew"><i class="fa-solid fa-plus"></i> New Automation</button>
            </div>
            <div class="cs-empty cs-sched-upgrade" id="csSchedUpgrade" hidden>
                <i class="fa-solid fa-lock cs-empty__icon"></i>
                <h2 class="cs-empty__title" id="csSchedUpgradeTitle">Upgrade for Automations</h2>
                <p class="cs-empty__text" id="csSchedUpgradeText">Your plan doesn't include scheduled automations.</p>
                <a href="/account/billing" class="btn btn-primary" id="csSchedUpgradeBtn">See Plans</a>
            </div>
            <div class="cs-sched" id="csSchedList" hidden></div>
        </div>

    </div>

    <!-- selection action bar -->
    <div class="cs-selbar" id="csSelbar" hidden>
        <span class="cs-selbar__count"><strong id="csSelCount">0</strong> selected</span>
        <div class="cs-selbar__actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk="collection_add"><i class="fa-solid fa-folder-plus"></i> Add to Collection</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk="download"><i class="fa-solid fa-download"></i> Download</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-bulk="delete"><i class="fa-solid fa-trash"></i> Remove</button>
            <button type="button" class="btn btn-sm btn-link cs-selbar__cancel" id="csSelClear">Cancel</button>
        </div>
    </div>

    <!-- posts bulk-selection bar -->
    <div class="cs-selbar" id="csPostSelbar" hidden>
        <span class="cs-selbar__count"><strong id="csPostSelCount">0</strong> selected</span>
        <div class="cs-selbar__actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-pbulk="archive"><i class="fa-solid fa-box-archive"></i> Archive</button>
            <button type="button" class="btn btn-sm btn-outline-danger" data-pbulk="delete"><i class="fa-solid fa-trash"></i> Remove</button>
            <button type="button" class="btn btn-sm btn-link cs-selbar__cancel" id="csPostSelClear">Cancel</button>
        </div>
    </div>
</div>

<!-- ============ DETAIL — Bootstrap offcanvas ============ -->
<div class="offcanvas offcanvas-end cs-offcanvas" tabindex="-1" id="csDetail" aria-labelledby="csDetailTitle">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="csDetailTitle">File Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body" id="csDetailBody"></div>
</div>

<!-- ============ REUSABLE DIALOG — Bootstrap modal ============ -->
<div class="modal fade" id="csModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="csModalTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="csModalBody"></div>
            <div class="modal-footer" id="csModalFooter"></div>
        </div>
    </div>
</div>

<!-- ============ UPLOAD TRAY ============ -->
<div class="cs-tray" id="csTray" hidden>
    <div class="cs-tray__head">
        <span id="csTrayTitle">Uploads</span>
        <button type="button" class="btn-close btn-close-sm" id="csTrayClose" aria-label="Hide"></button>
    </div>
    <div class="cs-tray__list" id="csTrayList"></div>
</div>

<input type="file" id="csFileInput" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm" multiple hidden>

<!-- ============ POST COMPOSER (modal) ============ -->
<div class="modal fade cs-composer" id="csComposer" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true" aria-labelledby="csCompTitle">
    <div class="modal-dialog cs-pe">
        <div class="modal-content cs-pe__surface">
            <header class="cs-pe__header">
                <div class="cs-pe__heading">
                    <span class="cs-pe__eyebrow" id="csPeEyebrow">New Post</span>
                    <div class="cs-pe__titlerow">
                        <h2 class="cs-pe__title" id="csCompTitle">Create Post</h2>
                        <span class="cs-pe__status" id="csPeStatus"><span class="cs-pe__statusdot" aria-hidden="true"></span><span id="csPeStatusText">Draft</span></span>
                    </div>
                </div>
                <div class="cs-pe__headtools">
                    <?php echo Tutorials::button('14'); ?>
                    <button type="button" class="cs-pe__pvtoggle" id="csPePreviewBtn" aria-pressed="false" aria-controls="csPePreview">Preview</button>
                    <button type="button" class="btn-close cs-pe__close" id="csPeClose" aria-label="Close"></button>
                </div>
            </header>

            <div class="cs-pe__body">
                <nav class="cs-pe__nav" aria-label="Post sections">
                    <label class="cs-pe__navselect-label" for="csPeNavSelect">Section</label>
                    <select class="form-select cs-pe__navselect" id="csPeNavSelect">
                        <option value="content">Content</option>
                        <option value="audience">Audience</option>
                        <option value="distribution">Distribution</option>
                        <option value="publish">Publish</option>
                    </select>
                    <div class="cs-pe__navlist" id="csPeNav">
                        <button type="button" class="cs-pe__navitem" data-section="content" aria-current="true"><span class="cs-pe__navlabel">Content</span><span class="cs-pe__navsum" data-sum="content"></span><span class="cs-pe__navflag" hidden>Needs attention</span></button>
                        <button type="button" class="cs-pe__navitem" data-section="audience"><span class="cs-pe__navlabel">Audience</span><span class="cs-pe__navsum" data-sum="audience"></span><span class="cs-pe__navflag" hidden>Needs attention</span></button>
                        <button type="button" class="cs-pe__navitem" data-section="distribution"><span class="cs-pe__navlabel">Distribution</span><span class="cs-pe__navsum" data-sum="distribution"></span><span class="cs-pe__navflag" hidden>Needs attention</span></button>
                        <button type="button" class="cs-pe__navitem" data-section="publish"><span class="cs-pe__navlabel">Publish</span><span class="cs-pe__navsum" data-sum="publish"></span><span class="cs-pe__navflag" hidden>Needs attention</span></button>
                    </div>
                </nav>

                <div class="cs-pe__main" id="csPeMain">

                    <section class="cs-pe__section" data-section="content" aria-labelledby="csPeH_content">
                        <h3 class="cs-pe__h" id="csPeH_content" tabindex="-1">Content</h3>
                        <p class="cs-pe__sub">Add media and write the message your audience will see.</p>

                        <div id="csCompModNote" class="cs-comp__modnote" hidden></div>

                        <div class="cs-pe__field">
                            <span class="cs-pe__label" id="csPeMediaLabel">Media</span>
                            <div class="cs-pe__media" id="csCompMedia" role="group" aria-labelledby="csPeMediaLabel"></div>
                            <p class="cs-pe__hint" id="csPeMediaHint" hidden>First item becomes the cover &middot; Drag to reorder</p>
                            <p class="cs-pe__live" id="csPeUploadStatus" aria-live="polite"></p>
                            <p class="cs-pe__error" id="csPeErr_media" role="alert" hidden></p>
                        </div>

                        <div class="cs-pe__field">
                            <div class="cs-pe__labelrow">
                                <label class="cs-pe__label" for="csCompCaption">Caption</label>
                                <span class="cs-pe__labeltools"><label class="visually-hidden" for="csPeCaptionMode">Caption Mode</label><select class="form-select cs-pe__capmode" id="csPeCaptionMode"><?php foreach (BrandService::CAPTION_MODES as $mk => $ml): ?><option value="<?php echo $mk; ?>"><?php echo htmlspecialchars($ml, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><button type="button" class="cs-pe__link" id="csPeCaptionAuto"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Write a caption</button><?php echo Tutorials::button('35'); ?><span class="cs-pe__count" id="csPeCount"><span id="csCompCount">0</span> / 3000</span></span>
                            </div>
                            <textarea id="csCompCaption" class="form-control cs-pe__caption" rows="6" maxlength="3000" placeholder="Write a caption…" aria-describedby="csPeCount csPeErr_caption"></textarea>
                            <div class="cs-pe__hook" id="csPeHook" hidden><span class="cs-pe__hooklabel">On-Video Text</span><span class="cs-pe__hooktext" id="csPeHookText"></span><button type="button" class="cs-pe__link" id="csPeHookCopy"><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy</button></div>
                            <p class="cs-pe__error" id="csPeErr_caption" role="alert" hidden></p>
                        </div>

                        <div class="cs-pe__switchrow">
                            <label class="cs-pe__switchtext" for="csCompComments"><span class="cs-pe__switchlabel">Allow comments</span><span class="cs-pe__switchsub">Fans can comment on this post.</span></label>
                            <div class="form-check form-switch cs-pe__switch"><input class="form-check-input" type="checkbox" role="switch" id="csCompComments" checked></div>
                        </div>
                    </section>

                    <section class="cs-pe__section" data-section="audience" aria-labelledby="csPeH_audience" hidden>
                        <h3 class="cs-pe__h" id="csPeH_audience" tabindex="-1">Audience</h3>
                        <p class="cs-pe__sub">Choose who can access this post.</p>

                        <div class="cs-pe__choices" id="csCompAudience" role="radiogroup" aria-label="Audience">
                            <button type="button" class="cs-pe__choice" role="radio" aria-checked="true" data-aud="free"><span class="cs-pe__choicemark" aria-hidden="true"></span><span class="cs-pe__choicetext"><span class="cs-pe__choicelabel">Everyone</span><span class="cs-pe__choicesub">Anyone can view this post.</span></span></button>
                            <button type="button" class="cs-pe__choice" role="radio" aria-checked="false" data-aud="subscribers"><span class="cs-pe__choicemark" aria-hidden="true"></span><span class="cs-pe__choicetext"><span class="cs-pe__choicelabel">Subscribers</span><span class="cs-pe__choicesub">Only active subscribers can view this post.</span></span></button>
                            <button type="button" class="cs-pe__choice" role="radio" aria-checked="false" data-aud="ppv"><span class="cs-pe__choicemark" aria-hidden="true"></span><span class="cs-pe__choicetext"><span class="cs-pe__choicelabel">Pay-per-view</span><span class="cs-pe__choicesub">Viewers pay to unlock this post.</span></span></button>
                        </div>

                        <div class="cs-pe__field cs-pe__reveal cs-pe__sub-field" id="csCompTier" hidden>
                            <span class="cs-pe__label" id="csCompTiersLabel">Tiers</span>
                            <div class="cs-pe__destlist cs-pe__tierlist" id="csCompTiers" role="group" aria-labelledby="csCompTiersLabel"></div>
                            <p class="cs-pe__error" id="csPeErr_tier" role="alert" hidden></p>
                        </div>

                        <div class="cs-pe__field cs-pe__reveal cs-pe__sub-field" id="csCompPpv" hidden>
                            <label class="cs-pe__label" for="csCompPpvPrice">Unlock price</label>
                            <div class="cs-pe__price">
                                <input type="number" class="form-control" id="csCompPpvPrice" min="10" max="5000" step="1" value="50" inputmode="numeric" aria-describedby="csPeErr_price">
                                <span class="cs-pe__cur" aria-hidden="true">credits</span>
                            </div>
                            <p class="cs-pe__error" id="csPeErr_price" role="alert" hidden></p>
                        </div>
                    </section>

                    <section class="cs-pe__section" data-section="distribution" aria-labelledby="csPeH_distribution" hidden>
                        <h3 class="cs-pe__h" id="csPeH_distribution" tabindex="-1">Distribution</h3>
                        <p class="cs-pe__sub">Choose where this post should be published.</p>

                        <div class="cs-pe__desttools" id="csPeDestTools">
                            <label class="visually-hidden" for="csPeDestSearch">Filter accounts</label>
                            <input type="search" class="form-control cs-pe__destsearch" id="csPeDestSearch" placeholder="Filter accounts" autocomplete="off">
                            <span class="cs-pe__destcount" id="csPeDestCount" aria-live="polite"></span>
                        </div>
                        <div class="cs-pe__destlist" id="csCompSocial" role="group" aria-label="Connected accounts"></div>
                        <p class="cs-pe__empty" id="csPeDestEmpty" hidden></p>
                        <p class="cs-pe__empty" id="csPeDestNone" hidden>No accounts match that filter.</p>
                        <p class="cs-pe__error" id="csPeErr_share" role="alert" hidden></p>
                    </section>

                    <section class="cs-pe__section" data-section="publish" aria-labelledby="csPeH_publish" hidden>
                        <?php echo Tutorials::button('15', 'data-float'); ?>
                        <h3 class="cs-pe__h" id="csPeH_publish" tabindex="-1">Publish</h3>
                        <p class="cs-pe__sub">Choose when this post should become available.</p>

                        <div class="cs-pe__choices" id="csPeMode" role="radiogroup" aria-label="Publishing mode">
                            <button type="button" class="cs-pe__choice" role="radio" aria-checked="true" data-mode="now"><span class="cs-pe__choicemark" aria-hidden="true"></span><span class="cs-pe__choicetext"><span class="cs-pe__choicelabel">Publish now</span><span class="cs-pe__choicesub">Publish immediately after confirmation.</span></span></button>
                            <button type="button" class="cs-pe__choice" role="radio" aria-checked="false" data-mode="schedule"><span class="cs-pe__choicemark" aria-hidden="true"></span><span class="cs-pe__choicetext"><span class="cs-pe__choicelabel">Schedule</span><span class="cs-pe__choicesub">Choose a future date and time.</span></span></button>
                            <button type="button" class="cs-pe__choice" role="radio" aria-checked="false" data-mode="draft"><span class="cs-pe__choicemark" aria-hidden="true"></span><span class="cs-pe__choicetext"><span class="cs-pe__choicelabel">Save as draft</span><span class="cs-pe__choicesub">Save without publishing.</span></span></button>
                        </div>
                        <p class="cs-pe__published" id="csPePublishedNote" hidden>This post is live. Saving applies your changes right away.</p>

                        <div class="cs-pe__reveal cs-pe__sub-field" id="csCompSchedule" hidden>
                            <div class="cs-pe__row">
                                <div class="cs-pe__field">
                                    <label class="cs-pe__label" for="csPeDate">Date</label>
                                    <input type="date" class="form-control" id="csPeDate" aria-describedby="csPeErr_schedule">
                                </div>
                                <div class="cs-pe__field">
                                    <label class="cs-pe__label" for="csPeTime">Time</label>
                                    <input type="time" class="form-control" id="csPeTime" aria-describedby="csPeErr_schedule">
                                </div>
                            </div>
                            <p class="cs-pe__hint">Time zone: <span id="csCompTz">UTC</span></p>
                            <input type="hidden" id="csCompSchedAt" value="">
                            <p class="cs-pe__error" id="csPeErr_schedule" role="alert" hidden></p>
                        </div>

                        <div class="cs-pe__validation" id="csCompValidation" role="alert" hidden></div>
                    </section>

                </div>

                <aside class="cs-pe__preview" id="csPePreview" aria-label="Preview">
                    <div class="cs-pe__pvhead">
                        <span class="cs-pe__pvtitle">Preview</span>
                        <div class="cs-pe__viewseg" id="csCompView" role="group" aria-label="Preview as">
                            <button type="button" class="cs-pe__viewopt is-on" aria-pressed="true" data-view="sub">Subscriber</button>
                            <button type="button" class="cs-pe__viewopt" aria-pressed="false" data-view="pub">Public</button>
                        </div>
                    </div>
                    <div class="cs-preview-wrap">
                        <div class="cs-preview-card" id="csPreviewCard"></div>
                    </div>
                </aside>
            </div>

            <footer class="cs-pe__footer">
                <span class="cs-pe__savestate" id="csCompSave" aria-live="polite"></span>
                <div class="cs-pe__actions">
                    <button type="button" class="btn cs-pe__btn cs-pe__btn--ghost" id="csPeSecondary">Save Draft</button>
                    <button type="button" class="btn btn-primary cs-pe__btn" id="csPePrimary">Continue</button>
                </div>
            </footer>
        </div>
    </div>
</div>

<div class="modal fade" id="csPicker" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add media from your library</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="cs-grid cs-grid--picker" id="csPickerGrid"></div>
                <div class="cs-empty" id="csPickerEmpty" hidden>
                    <i class="fa-solid fa-photo-film cs-empty__icon"></i>
                    <p class="cs-empty__text">Your library is empty. Upload some media first, then add it to a post.</p>
                </div>
            </div>
            <div class="modal-footer">
                <span class="me-auto text-body-secondary"><strong id="csPickerCount">0</strong> selected</span>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="csPickerAdd">Add to Post</button>
            </div>
        </div>
    </div>
</div>
<!-- ============ SCHEDULER — automation form ============ -->
<div class="modal fade cs-ae-modal" id="csSchedulerModal" tabindex="-1" aria-hidden="true" aria-labelledby="csSchedModalTitle">
    <div class="modal-dialog cs-ae">
        <div class="modal-content cs-ae__surface">
            <header class="cs-ae__header">
                <div class="cs-ae__heading">
                    <span class="cs-ae__eyebrow" id="csSchedEyebrow">Automation</span>
                    <div class="cs-ae__titlerow">
                        <h2 class="cs-ae__title" id="csSchedModalTitle">New Automation</h2>
                        <span class="cs-ae__status" id="csSchedStatus" hidden><span class="cs-ae__statusdot" aria-hidden="true"></span><span id="csSchedStatusText">Active</span></span>
                    </div>
                </div>
                <div class="cs-ae__headtools">
                    <?php echo Tutorials::button('18', 'data-kind="post"') . Tutorials::button('19', 'data-kind="message" hidden'); ?>
                    <button type="button" class="btn-close cs-ae__close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </header>

            <div class="cs-ae__body">
                <input type="hidden" id="csSchedId" value="0">
                <input type="hidden" id="csSchedKind" value="post">
                <input type="hidden" id="csSchedSize" value="<?php echo Aspect::DEFAULT_IMAGE; ?>">
                <input type="checkbox" id="csSchedBrand" checked hidden>

                <nav class="cs-ae__nav" aria-label="Automation sections">
                    <label class="cs-ae__navselect-label" for="csSchedNavSelect">Section</label>
                    <select class="form-select cs-ae__navselect" id="csSchedNavSelect">
                        <option value="content">Content</option>
                        <option value="publishing" data-kind="post">Publishing</option>
                        <option value="destinations">Destinations</option>
                        <option value="schedule">Schedule</option>
                    </select>
                    <div class="cs-ae__navlist" id="csSchedNav">
                        <button type="button" class="cs-ae__navitem" data-section="content" aria-current="true">
                            <span class="cs-ae__navlabel">Content</span>
                            <span class="cs-ae__navsum" data-sum="content"></span>
                            <span class="cs-ae__navflag" hidden>Needs attention</span>
                        </button>
                        <button type="button" class="cs-ae__navitem" data-section="publishing" data-kind="post">
                            <span class="cs-ae__navlabel">Publishing</span>
                            <span class="cs-ae__navsum" data-sum="publishing"></span>
                            <span class="cs-ae__navflag" hidden>Needs attention</span>
                        </button>
                        <button type="button" class="cs-ae__navitem" data-section="destinations">
                            <span class="cs-ae__navlabel">Destinations</span>
                            <span class="cs-ae__navsum" data-sum="destinations"></span>
                            <span class="cs-ae__navflag" hidden>Needs attention</span>
                        </button>
                        <button type="button" class="cs-ae__navitem" data-section="schedule">
                            <span class="cs-ae__navlabel">Schedule</span>
                            <span class="cs-ae__navsum" data-sum="schedule"></span>
                            <span class="cs-ae__navflag" hidden>Needs attention</span>
                        </button>
                    </div>
                </nav>

                <div class="cs-ae__main" id="csSchedMain">

                    <section class="cs-ae__section" data-section="content" aria-labelledby="csSchedH_content">
                        <h3 class="cs-ae__h" id="csSchedH_content" tabindex="-1">Content</h3>
                        <p class="cs-ae__sub">Define what this automation creates.</p>

                        <div class="cs-ae__field">
                            <label class="cs-ae__label" for="csSchedName">Name</label>
                            <input type="text" class="form-control" id="csSchedName" maxlength="190" autocomplete="off" aria-describedby="csSchedErr_name">
                            <p class="cs-ae__error" id="csSchedErr_name" role="alert" hidden></p>
                        </div>

                        <div class="cs-ae__field cs-ae__field--switch" data-kind="message" hidden>
                            <label class="cs-ae__label" for="csSchedMsgAi">Write with AI</label>
                            <div class="form-check form-switch cs-ae__switch"><input class="form-check-input" type="checkbox" role="switch" id="csSchedMsgAi"></div>
                        </div>

                        <div class="cs-ae__field" id="csSchedTopicWrap">
                            <label class="cs-ae__label" for="csSchedTopic"><span data-kind="post">Scene</span><span data-kind="message" hidden>Topic</span></label>
                            <textarea class="form-control cs-ae__textarea" id="csSchedTopic" rows="5" aria-describedby="csSchedErr_topic"></textarea>
                            <p class="cs-ae__error" id="csSchedErr_topic" role="alert" hidden></p>
                        </div>

                        <div class="cs-ae__field" id="csSchedMsgTextWrap" data-kind="message" hidden>
                            <label class="cs-ae__label" for="csSchedMsgText">Message</label>
                            <textarea class="form-control cs-ae__textarea" id="csSchedMsgText" rows="5" maxlength="5000" aria-describedby="csSchedErr_message"></textarea>
                            <p class="cs-ae__error" id="csSchedErr_message" role="alert" hidden></p>
                        </div>

                        <div class="cs-ae__row" data-kind="post">
                            <div class="cs-ae__field" id="csSchedMediaWrap">
                                <span class="cs-ae__label" id="csSchedMediaLabel">Post As</span>
                                <div class="cs-seg cs-ae__seg" id="csSchedMedia" role="group" aria-labelledby="csSchedMediaLabel">
                                    <button type="button" class="cs-seg__opt is-on" aria-pressed="true" data-media="image"><i class="fa-regular fa-image" aria-hidden="true"></i><span>Image</span></button>
                                    <button type="button" class="cs-seg__opt" aria-pressed="false" data-media="video"><i class="fa-solid fa-film" aria-hidden="true"></i><span>Video</span></button>
                                </div>
                            </div>
                            <div class="cs-ae__field">
                                <span class="cs-ae__label" id="csSchedShapeLabel">Format</span>
                                <div class="cs-seg cs-ae__seg" id="csSchedShape" role="group" aria-labelledby="csSchedShapeLabel">
                                    <?php echo Aspect::seg_buttons('cs-seg__opt', 'data-size'); ?>
                                </div>
                            </div>
                        </div>

                        <div class="cs-ae__row" data-kind="post">
                            <div class="cs-ae__field">
                                <span class="cs-ae__label" id="csSchedImageSourceLabel">Source</span>
                                <div class="cs-seg cs-ae__seg" id="csSchedImageSource" role="group" aria-labelledby="csSchedImageSourceLabel">
                                    <button type="button" class="cs-seg__opt is-on" aria-pressed="true" data-src="brand"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i><span>Brand Photo</span></button>
                                    <button type="button" class="cs-seg__opt" aria-pressed="false" data-src="influencer"<?php echo empty($this->influencers['ready']) ? ' disabled title="Train an influencer first"' : ''; ?>><i class="fa-regular fa-user" aria-hidden="true"></i><span>Influencer</span></button>
                                </div>
                            </div>
                            <div class="cs-ae__field cs-ae__reveal" id="csSchedInfluencerWrap" hidden>
                                <label class="cs-ae__label" for="csSchedInfluencer">Influencer</label>
                                <select id="csSchedInfluencer" class="form-select" aria-describedby="csSchedErr_influencer">
                                    <?php foreach ((array) ($this->influencers['ready'] ?? array()) as $inf): ?>
                                    <option value="<?php echo (int) $inf['id']; ?>"><?php echo htmlspecialchars((string) $inf['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="cs-ae__error" id="csSchedErr_influencer" role="alert" hidden></p>
                            </div>
                        </div>

                        <div class="cs-ae__group cs-ae__reveal" id="csSchedVideoWrap" data-kind="post" hidden>
                            <span class="cs-ae__grouptitle">Video</span>
                            <div class="cs-ae__field">
                                <label class="cs-ae__label" for="csSchedVideoPrompt">Motion</label>
                                <textarea class="form-control cs-ae__textarea" id="csSchedVideoPrompt" rows="2" maxlength="2000" placeholder="She turns toward the camera and smiles, slow push in"></textarea>
                            </div>
                            <input type="hidden" id="csSchedVideoModel" value="">
                            <input type="hidden" id="csSchedVideoDur" value="">
                            <div class="cs-ae__row">
                                <div class="cs-ae__field">
                                    <span class="cs-ae__label" id="csSchedVideoModelLabel">Style</span>
                                    <div class="cs-seg cs-ae__seg" id="csSchedVideoModels" role="group" aria-labelledby="csSchedVideoModelLabel"></div>
                                </div>
                                <div class="cs-ae__field">
                                    <span class="cs-ae__label" id="csSchedVideoDurLabel">Length</span>
                                    <div class="cs-seg cs-ae__seg" id="csSchedVideoDurs" role="group" aria-labelledby="csSchedVideoDurLabel"></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="cs-ae__section" data-section="publishing" data-kind="post" aria-labelledby="csSchedH_publishing" hidden>
                        <h3 class="cs-ae__h" id="csSchedH_publishing" tabindex="-1">Publishing</h3>
                        <p class="cs-ae__sub">Control who can see the content and how the post behaves.</p>

                        <div class="cs-ae__row" id="csSchedAudienceRow">
                            <div class="cs-ae__field">
                                <label class="cs-ae__label" for="csSchedAudience">Audience</label>
                                <select id="csSchedAudience" class="form-select">
                                    <option value="free">Everyone</option>
                                    <option value="subscribers">Subscribers</option>
                                </select>
                            </div>
                            <div class="cs-ae__field cs-ae__reveal" id="csSchedTier" hidden>
                                <label class="cs-ae__label" for="csSchedTierSel">Tier</label>
                                <select id="csSchedTierSel" class="form-select">
                                    <option value="">All tiers</option>
                                    <?php foreach ((array) $this->plans as $pl): ?>
                                    <option value="<?php echo (int) $pl['id']; ?>"><?php echo htmlspecialchars((string) $pl['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="cs-ae__field cs-ae__field--switch">
                            <label class="cs-ae__label" for="csSchedAi">AI captions</label>
                            <div class="form-check form-switch cs-ae__switch"><input class="form-check-input" type="checkbox" role="switch" id="csSchedAi" checked></div>
                        </div>
                        <div class="cs-ae__field cs-ae__reveal" id="csSchedCapModeWrap">
                            <label class="cs-ae__label" for="csSchedCapMode">Caption Mode</label>
                            <select class="form-select" id="csSchedCapMode"><?php foreach (BrandService::CAPTION_MODES as $mk => $ml): if ($mk === 'hook_overlay') { continue; } /* automations never place the on-video line the caption would pay off */ ?><option value="<?php echo $mk; ?>"><?php echo htmlspecialchars($ml, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                        </div>
                        <div class="cs-ae__field cs-ae__reveal" id="csSchedCaptionWrap" hidden>
                            <label class="cs-ae__label" for="csSchedCaption">Caption</label>
                            <textarea class="form-control cs-ae__textarea" id="csSchedCaption" rows="3" maxlength="5000"></textarea>
                        </div>

                        <div class="cs-ae__field cs-ae__field--switch">
                            <label class="cs-ae__label" for="csSchedComments">Allow comments</label>
                            <div class="form-check form-switch cs-ae__switch"><input class="form-check-input" type="checkbox" role="switch" id="csSchedComments" checked></div>
                        </div>
                    </section>

                    <section class="cs-ae__section" data-section="destinations" aria-labelledby="csSchedH_destinations" hidden>
                        <h3 class="cs-ae__h" id="csSchedH_destinations" tabindex="-1">Destinations</h3>
                        <p class="cs-ae__sub"><span data-kind="post">Choose where this automation publishes.</span><span data-kind="message" hidden>Choose who receives the message.</span></p>

                        <div data-kind="post">
                            <div class="cs-ae__desttools">
                                <label class="visually-hidden" for="csSchedDestSearch">Filter accounts</label>
                                <input type="search" class="form-control cs-ae__destsearch" id="csSchedDestSearch" placeholder="Filter accounts" autocomplete="off">
                                <span class="cs-ae__destcount" id="csSchedDestCount" aria-live="polite"></span>
                            </div>
                            <div class="cs-ae__destlist" id="csSchedSocial" role="group" aria-label="Connected accounts"></div>
                            <p class="cs-ae__empty" id="csSchedDestEmpty" hidden>No connected accounts yet. Connect one in <a href="/account/settings?section=connected">Settings</a> and it will appear here.</p>
                            <p class="cs-ae__empty" id="csSchedDestNone" hidden>No accounts match that filter.</p>
                        </div>

                        <div class="cs-ae__field" data-kind="message" hidden>
                            <span class="cs-ae__label">Send to</span>
                            <div class="cs-ae__targets" id="csSchedTargets"></div>
                            <p class="cs-ae__error" id="csSchedErr_targets" role="alert" hidden></p>
                        </div>
                    </section>

                    <section class="cs-ae__section" data-section="schedule" aria-labelledby="csSchedH_schedule" hidden>
                        <h3 class="cs-ae__h" id="csSchedH_schedule" tabindex="-1">Schedule</h3>
                        <p class="cs-ae__sub">Choose when this automation runs.</p>

                        <div class="cs-ae__row">
                            <div class="cs-ae__field">
                                <span class="cs-ae__label" id="csSchedCadenceLabel">Frequency</span>
                                <div class="cs-seg cs-ae__seg" id="csSchedCadence" role="group" aria-labelledby="csSchedCadenceLabel">
                                    <button type="button" class="cs-seg__opt is-on" aria-pressed="true" data-cad="daily"><span>Daily</span></button>
                                    <button type="button" class="cs-seg__opt" aria-pressed="false" data-cad="weekly"><span>Weekly</span></button>
                                </div>
                            </div>
                            <div class="cs-ae__field">
                                <label class="cs-ae__label" for="csSchedTime">Time</label>
                                <input type="time" id="csSchedTime" class="form-control" value="09:00">
                            </div>
                        </div>

                        <div class="cs-ae__field cs-ae__reveal" id="csSchedDaysRow" hidden>
                            <span class="cs-ae__label" id="csSchedDaysLabel">Days</span>
                            <div class="cs-ae__days" id="csSchedDays" role="group" aria-labelledby="csSchedDaysLabel"></div>
                            <p class="cs-ae__error" id="csSchedErr_days" role="alert" hidden></p>
                        </div>

                        <div class="cs-ae__credits" id="csSchedCredits"></div>
                    </section>

                </div>
            </div>

            <footer class="cs-ae__footer">
                <button type="button" class="cs-ae__delete" id="csSchedDelete" hidden>Delete automation</button>
                <div class="cs-ae__actions">
                    <button type="button" class="btn cs-ae__btn cs-ae__btn--ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary cs-ae__btn" id="csSchedSave">Save Changes</button>
                </div>
            </footer>
        </div>
    </div>
</div>
<script>
window.TUT_BTN = <?php echo json_encode(array('25' => Tutorials::button('25'), '30' => Tutorials::button('30')), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;   /* Watch How buttons for the File Details drawer (studio-ai.js) */
window.CS_CONFIG = <?php echo json_encode(array(
    'creator'  => $this->creator,
    'plans'    => $this->plans,
    'social'   => $this->social,
    'inbox'    => $this->inbox,
    'influencers' => $this->influencers ?? array('all' => array(), 'ready' => array()),
    'brand'    => $this->brand,
    'ai'       => $this->ai ?? array('balance' => 0, 'image_price' => 0),
    'automation_plan' => $this->automation_plan ?? null,
    'aspect'   => Aspect::client(),
    'can_ai'   => !empty($this->can_ai),
    'image_models' => InfluencerConfig::picker_options('image'),
    's3_ready' => !empty($this->s3_ready),
), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script src="/js/studio.js?v=<?php echo @filemtime(Main::app_path().'/public/js/studio.js'); ?>"></script>
<script src="/js/ai-tools.js?v=<?php echo @filemtime(Main::app_path().'/public/js/ai-tools.js'); ?>"></script>
<script src="/js/studio-ai.js?v=<?php echo @filemtime(Main::app_path().'/public/js/studio-ai.js'); ?>"></script>
<?php endif; ?>
