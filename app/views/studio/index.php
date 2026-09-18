<link rel="stylesheet" href="/css/studio.css?v=<?php echo @filemtime(Main::app_path().'/public/css/studio.css'); ?>">

<?php if (empty($this->is_creator)): ?>
    <div class="cs-gate">
        <i class="fa-solid fa-photo-film cs-gate__icon"></i>
        <h1 class="cs-gate__title">The Content Studio is for creators</h1>
        <p class="cs-gate__text">Turn on your creator account to upload media, publish posts, and schedule your content.</p>
        <a href="/account/settings" class="btn btn-primary">Go to settings</a>
    </div>
<?php else: ?>

<div class="cs" id="cs" data-s3-ready="<?php echo !empty($this->s3_ready) ? '1' : '0'; ?>">

    <header class="cs-head">
        <div>
            <h1 class="cs-head__title">Content Studio</h1>
            <p class="cs-head__sub">Upload once, use everywhere. Everything for your content lives here.</p>
        </div>
        <div class="cs-head__actions">
            <div class="dropdown cs-create">
                <button type="button" class="btn btn-primary dropdown-toggle" id="csCreateBtn" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-plus"></i> Action
                </button>
                <ul class="dropdown-menu dropdown-menu-end cs-create__menu">
                    <li class="cs-create__label">Content</li>
                    <li><button type="button" class="dropdown-item" id="csNewPostBtn"><span class="cs-create__ic"><i class="fa-solid fa-feather-pointed"></i></span><span><strong>New Post</strong><small>Write, schedule &amp; publish</small></span></button></li>
                    <?php if (!empty($this->can_ai)): ?>
                    <li><button type="button" class="dropdown-item" id="csGenerateBtn"><span class="cs-create__ic"><i class="fa-solid fa-wand-magic-sparkles"></i></span><span><strong>Generate Image</strong><small>Create an on-brand image with AI</small></span></button></li>
                    <?php endif; ?>
                    <li><button type="button" class="dropdown-item" id="csSchedNew"><span class="cs-create__ic"><i class="fa-solid fa-robot"></i></span><span><strong>New Automation</strong><small>Auto-generate &amp; post on a schedule</small></span></button></li>
                    <?php if (!empty($this->inbox['can'])): ?>
                    <li><button type="button" class="dropdown-item" id="csSchedNewMsg"><span class="cs-create__ic"><i class="fa-solid fa-paper-plane"></i></span><span><strong>New Scheduled Message</strong><small>Message your fans on a schedule</small></span></button></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li class="cs-create__label">Library</li>
                    <li><button type="button" class="dropdown-item" id="csUploadBtn"><span class="cs-create__ic"><i class="fa-solid fa-arrow-up-from-bracket"></i></span><span><strong>Upload Media</strong><small>Add files to your library</small></span></button></li>
                    <li><button type="button" class="dropdown-item" id="csCreateCollectionBtn"><span class="cs-create__ic"><i class="fa-solid fa-folder-plus"></i></span><span><strong>Create Collection</strong><small>Group related files</small></span></button></li>
                </ul>
            </div>
        </div>
    </header>

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
                </select>
                <select id="csFilterCollection" class="form-select cs-filter" aria-label="Filter by collection">
                    <option value="">All collections</option>
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
            </div>

            <div class="cs-dropzone" id="csDropzone">
                <div class="cs-drophint" id="csDropHint"><i class="fa-solid fa-cloud-arrow-up"></i> Drop files to upload</div>

                <div class="cs-loading" id="csLibLoading">
                    <span class="spinner-border spinner-border-sm text-primary" role="status"></span> Loading your library…
                </div>

                <div class="cs-error" id="csLibError" hidden>
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <p>We couldn't load your library.</p>
                    <button type="button" class="btn btn-outline-secondary" id="csLibRetry">Try again</button>
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
                <button type="button" class="btn btn-outline-secondary" id="csPostsRetry">Try again</button>
            </div>
            <div class="cs-empty" id="csPostsEmpty" hidden>
                <i class="fa-solid fa-rectangle-list cs-empty__icon"></i>
                <h2 class="cs-empty__title">No posts yet</h2>
                <p class="cs-empty__text">Create your first post — publish it now, schedule it, or save a draft.</p>
                <button type="button" class="btn btn-primary" id="csPostsEmptyNew"><i class="fa-solid fa-plus"></i> New post</button>
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
                    <h2 class="cs-empty__title">No collections yet</h2>
                    <p class="cs-empty__text">Group related files so they're easy to find when you post. Use “Create collection” up top to make your first one.</p>
                </div>
            </div>
            <!-- one collection's contents (shown after clicking a collection) -->
            <div id="csColDetail" hidden>
                <div class="cs-coldetail__head">
                    <button type="button" class="btn btn-link cs-coldetail__back" id="csColBack"><i class="fa-solid fa-arrow-left"></i> All collections</button>
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
                <button type="button" class="btn btn-outline-secondary" id="csSchedRetry">Try again</button>
            </div>
            <div class="cs-empty" id="csSchedEmpty" hidden>
                <i class="fa-solid fa-robot cs-empty__icon"></i>
                <h2 class="cs-empty__title">No automations yet</h2>
                <p class="cs-empty__text">Set one up and the Studio will generate on-brand content and publish it on your schedule, even while you're away.</p>
                <button type="button" class="btn btn-primary" id="csSchedEmptyNew"><i class="fa-solid fa-plus"></i> New automation</button>
            </div>
            <div class="cs-sched" id="csSchedList" hidden></div>
        </div>

    </div>

    <!-- selection action bar -->
    <div class="cs-selbar" id="csSelbar" hidden>
        <span class="cs-selbar__count"><strong id="csSelCount">0</strong> selected</span>
        <div class="cs-selbar__actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk="collection_add"><i class="fa-solid fa-folder-plus"></i> Add to Collection</button>
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
<div class="modal fade cs-composer" id="csComposer" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header cs-comp__head">
                <h5 class="modal-title" id="csCompTitle">New post</h5>
                <span class="cs-comp__save" id="csCompSave"></span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body cs-comp__body">

                <!-- editing controls -->
                <div class="cs-comp__edit">
                    <div class="cs-comp__media" id="csCompMedia"></div>
                    <div class="cs-comp__mediaactions">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="csCompAdd"><i class="fa-solid fa-photo-film"></i> Add from Library</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="csCompUpload"><i class="fa-solid fa-arrow-up-from-bracket"></i> Upload</button>
                    </div>

                    <label class="cs-flabel" for="csCompCaption">Caption</label>
                    <textarea id="csCompCaption" class="form-control" rows="4" maxlength="3000" placeholder="Write a caption…"></textarea>
                    <div class="cs-comp__count"><span id="csCompCount">0</span> / 3000</div>

                    <label class="cs-flabel">Who can see this post</label>
                    <div class="cs-seg cs-seg--3" id="csCompAudience">
                        <button type="button" class="cs-seg__opt is-on" data-aud="free"><i class="fa-solid fa-globe"></i> <span><strong>Everyone</strong><small>Anyone can see it</small></span></button>
                        <button type="button" class="cs-seg__opt" data-aud="subscribers"><i class="fa-solid fa-lock"></i> <span><strong>Subscribers</strong><small>Members only</small></span></button>
                        <button type="button" class="cs-seg__opt" data-aud="ppv"><i class="fa-solid fa-dollar-sign"></i> <span><strong>Pay-per-view</strong><small>Unlock to view</small></span></button>
                    </div>
                    <div class="cs-comp__tier" id="csCompTier" hidden>
                        <label class="cs-flabel" for="csCompTierSel">Available to</label>
                        <select id="csCompTierSel" class="form-select"><option value="">All Subscribers</option></select>
                    </div>
                    <div class="cs-comp__ppv" id="csCompPpv" hidden>
                        <label class="cs-flabel" for="csCompPpvPrice">Unlock price</label>
                        <div class="cs-ppvprice">
                            <span class="cs-ppvprice__cur">$</span>
                            <input type="number" class="form-control" id="csCompPpvPrice" min="3" max="500" step="1" value="5" inputmode="numeric">
                            <span class="cs-ppvprice__hint" id="csCompPpvCredits">= 50 credits</span>
                        </div>
                        <p class="cs-ppvprice__note">Fans spend credits to unlock this post. $3–$500.</p>
                    </div>

                    <label class="cs-flabel">Options</label>
                    <label class="cs-comp__opt" for="csCompComments">
                        <span>Allow comments<small>Fans can comment on this post.</small></span>
                        <span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="csCompComments" checked></span>
                    </label>

                    <label class="cs-flabel" id="csCompSocialLabel">Cross-post to social</label>
                    <div id="csCompSocial" class="cs-comp__social"></div>

                    <div class="cs-comp__sched" id="csCompSchedule" hidden>
                        <input type="datetime-local" id="csCompSchedAt" class="form-control">
                        <small class="text-muted">Times are in your timezone (<span id="csCompTz">UTC</span>).</small>
                    </div>
                    <div class="cs-comp__validation" id="csCompValidation" hidden></div>
                    <div class="cs-comp__actions">
                        <button type="button" class="btn btn-primary" id="csPublishNow">Publish Now</button>
                        <button type="button" class="btn btn-outline-secondary" id="csSchedule">Schedule</button>
                        <button type="button" class="btn btn-outline-secondary" id="csSaveDraft">Save as Draft</button>
                    </div>
                </div>

                <!-- live preview -->
                <div class="cs-comp__preview">
                    <div class="cs-comp__pvhead">
                        <span class="cs-dv__label mb-0">Preview</span>
                        <div class="cs-seg cs-seg--sm" id="csCompView">
                            <button type="button" class="cs-seg__opt is-on" data-view="sub">Subscriber</button>
                            <button type="button" class="cs-seg__opt" data-view="pub">Non-Subscribers</button>
                        </div>
                    </div>
                    <div class="cs-preview-wrap">
                        <div class="cs-preview-card" id="csPreviewCard"></div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ============ MEDIA PICKER (modal) ============ -->
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
                <button type="button" class="btn btn-primary" id="csPickerAdd">Add to post</button>
            </div>
        </div>
    </div>
</div>
<!-- ============ GENERATE IMAGE ============ -->
<div class="modal fade" id="csGenerate" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate an image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="cs-gen">
                    <div id="csGenInputs">
                        <div class="cs-gen__field">
                            <label for="csGenPrompt">Describe the image</label>
                            <textarea class="form-control" id="csGenPrompt" rows="3" placeholder="Sunset over Lake Eola, golden hour"></textarea>
                        </div>
                        <div class="cs-gen__field">
                            <label for="csGenSize">Shape</label>
                            <select class="form-select" id="csGenSize">
                                <option value="square">Square (1:1)</option>
                                <option value="portrait">Portrait (2:3)</option>
                                <option value="landscape">Landscape (3:2)</option>
                            </select>
                        </div>
                        <label class="cs-gen__brand" id="csGenBrandRow" hidden>
                            <input type="checkbox" class="form-check-input" id="csGenBrand" checked>
                            <span>Use my brand <strong id="csGenBrandName"></strong> — its colours, voice, and keywords steer the look.</span>
                        </label>
                    </div>
                    <div class="cs-gen__preview" id="csGenPreview" hidden>
                        <img id="csGenPreviewImg" alt="Generated image">
                    </div>
                    <div class="cs-gen__status" id="csGenStatus" hidden></div>
                    <div class="cs-gen__result" id="csGenResult" hidden>
                        <p class="cs-gen__saved"><i class="fa-solid fa-circle-check"></i> <span id="csGenSavedMsg">Saved to your Library.</span></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="csGenRun"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
                <button type="button" class="btn btn-outline-secondary" id="csGenEdit" hidden><i class="fa-solid fa-rotate"></i> Regenerate</button>
                <button type="button" class="btn btn-primary" id="csGenUse" hidden><i class="fa-solid fa-share-from-square"></i> Use in a post</button>
            </div>
        </div>
    </div>
</div>

<!-- ============ SCHEDULER — automation form ============ -->
<div class="modal fade" id="csSchedulerModal" tabindex="-1" aria-hidden="true" aria-labelledby="csSchedModalTitle">
    <div class="modal-dialog modal-dialog-centered cs-am">
        <div class="modal-content">
            <div class="modal-header cs-am__header">
                <h5 class="modal-title cs-am__title" id="csSchedModalTitle">New automation</h5>
                <button type="button" class="btn-close cs-am__close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body cs-am__body">
                <input type="hidden" id="csSchedId" value="0">
                <input type="hidden" id="csSchedKind" value="post">
                <input type="hidden" id="csSchedSize" value="square">

                <div class="cs-am__section">
                    <div class="cs-am__field cs-am__field--full">
                        <label class="cs-am__label" for="csSchedName">Name</label>
                        <input type="text" class="form-control" id="csSchedName" maxlength="190" placeholder="Daily Orlando tip">
                    </div>
                    <div class="cs-am__field cs-am__field--full" id="csSchedTopicWrap">
                        <label class="cs-am__label" for="csSchedTopic"><span data-kind="post">Scene</span><span data-kind="message" hidden>Topic</span></label>
                        <textarea class="form-control" id="csSchedTopic" rows="4" placeholder="A scenic Orlando spot with a short caption"></textarea>
                    </div>

                    <div class="cs-am__group" data-kind="message" hidden>
                        <div class="cs-am__field cs-am__field--full">
                            <div class="cs-am__label">Send to</div>
                            <div class="cs-comp__social cs-am__chips" id="csSchedTargets"></div>
                        </div>
                        <div class="cs-am__field cs-am__field--toggle">
                            <label class="cs-am__label" for="csSchedMsgAi">Write with AI</label>
                            <div class="cs-am__toggle form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="csSchedMsgAi"></div>
                        </div>
                        <div class="cs-am__field cs-am__field--full" id="csSchedMsgTextWrap">
                            <label class="cs-am__label" for="csSchedMsgText">Message</label>
                            <textarea class="form-control" id="csSchedMsgText" rows="4" maxlength="5000" placeholder="New drop this week. Check your inbox Friday."></textarea>
                        </div>
                    </div>

                    <div class="cs-am__group" data-kind="post">
                        <div class="cs-am__field">
                            <div class="cs-am__label">Image</div>
                            <div class="cs-seg cs-seg--compact cs-am__seg" id="csSchedImageSource" role="group" aria-label="Image source">
                                <button type="button" class="cs-seg__opt is-on" aria-pressed="true" data-src="brand"><span>Brand photo</span></button>
                                <button type="button" class="cs-seg__opt" aria-pressed="false" data-src="character"><span>My character</span></button>
                                <?php if (!empty($this->influencers['ready'])): ?>
                                <button type="button" class="cs-seg__opt" aria-pressed="false" data-src="influencer"><span>Influencer</span></button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="cs-am__field">
                            <div class="cs-am__label">Shape</div>
                            <div class="cs-seg cs-seg--compact cs-am__seg" id="csSchedShape" role="group" aria-label="Shape">
                                <button type="button" class="cs-seg__opt is-on" aria-pressed="true" data-size="square"><i class="fa-regular fa-square" aria-hidden="true"></i><span>Square</span></button>
                                <button type="button" class="cs-seg__opt" aria-pressed="false" data-size="portrait"><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i><span>Portrait</span></button>
                                <button type="button" class="cs-seg__opt" aria-pressed="false" data-size="landscape"><i class="fa-regular fa-rectangle-list" aria-hidden="true"></i><span>Landscape</span></button>
                            </div>
                        </div>
                        <div class="cs-am__group" id="csSchedCharacterWrap" hidden>
                            <div class="cs-am__field cs-am__field--full">
                                <label class="cs-am__label" for="csSchedCharacter">Character</label>
                                <select id="csSchedCharacter" class="form-select"><option value="">Loading…</option></select>
                            </div>
                        </div>
                        <div class="cs-am__group" id="csSchedInfluencerWrap" hidden>
                            <div class="cs-am__field cs-am__field--full">
                                <label class="cs-am__label" for="csSchedInfluencer">Influencer</label>
                                <select id="csSchedInfluencer" class="form-select"></select>
                            </div>
                        </div>
                    </div>

                    <input type="checkbox" id="csSchedBrand" checked hidden>
                </div>

                <div class="cs-am__section" data-kind="post">
                    <div class="cs-am__field">
                        <div class="cs-am__label">Content</div>
                        <div class="cs-seg cs-seg--compact cs-am__seg" id="csSchedLevel" role="group" aria-label="Content level">
                            <button type="button" class="cs-seg__opt is-on" aria-pressed="true" data-level="safe"><span>Feed-safe</span></button>
                            <button type="button" class="cs-seg__opt" aria-pressed="false" data-level="spicy"><span>Spicy</span></button>
                        </div>
                    </div>
                    <div class="cs-am__field">
                        <div class="cs-am__label">Audience</div>
                        <div class="cs-seg cs-seg--compact cs-am__seg" id="csSchedAudience" role="group" aria-label="Audience">
                            <button type="button" class="cs-seg__opt is-on" aria-pressed="true" data-aud="free"><span>Everyone</span></button>
                            <button type="button" class="cs-seg__opt" aria-pressed="false" data-aud="subscribers"><span>Subscribers</span></button>
                        </div>
                    </div>
                    <div class="cs-am__field cs-am__field--start">
                        <div class="cs-am__label">AI captions</div>
                        <div class="cs-seg cs-seg--compact cs-am__seg" id="csSchedAiSeg" role="group" aria-label="AI captions">
                            <button type="button" class="cs-seg__opt is-on" aria-pressed="true" data-ai="1"><span>Yes</span></button>
                            <button type="button" class="cs-seg__opt" aria-pressed="false" data-ai="0"><span>No</span></button>
                        </div>
                        <input type="checkbox" id="csSchedAi" checked hidden>
                    </div>
                    <div class="cs-am__field">
                        <div class="cs-am__label">Allow comments</div>
                        <div class="cs-seg cs-seg--compact cs-am__seg" id="csSchedCommentsSeg" role="group" aria-label="Allow comments">
                            <button type="button" class="cs-seg__opt is-on" aria-pressed="true" data-on="1"><span>Yes</span></button>
                            <button type="button" class="cs-seg__opt" aria-pressed="false" data-on="0"><span>No</span></button>
                        </div>
                        <input type="checkbox" id="csSchedComments" checked hidden>
                    </div>
                    <div class="cs-am__field cs-am__field--full" id="csSchedCaptionWrap" hidden>
                        <label class="cs-am__label" for="csSchedCaption">Caption</label>
                        <textarea class="form-control" id="csSchedCaption" rows="2" maxlength="5000" placeholder="Posted exactly as written with every run"></textarea>
                    </div>
                    <div class="cs-am__field cs-am__field--full" id="csSchedTier" hidden>
                        <label class="cs-am__label" for="csSchedTierSel">Tier</label>
                        <select id="csSchedTierSel" class="form-select"><option value="">All subscribers</option></select>
                    </div>
                </div>

                <div class="cs-am__section">
                    <div class="cs-am__field cs-am__field--full" id="csSchedSocialRow" hidden>
                        <div class="cs-am__label">Share to</div>
                        <div id="csSchedSocial" class="cs-comp__social cs-am__chips"></div>
                    </div>
                    <div class="cs-am__field">
                        <div class="cs-am__label">Schedule</div>
                        <div class="cs-seg cs-seg--compact cs-am__seg" id="csSchedCadence" role="group" aria-label="Schedule frequency">
                            <button type="button" class="cs-seg__opt is-on" aria-pressed="true" data-cad="daily"><span>Daily</span></button>
                            <button type="button" class="cs-seg__opt" aria-pressed="false" data-cad="weekly"><span>Weekly</span></button>
                        </div>
                    </div>
                    <div class="cs-am__field">
                        <label class="cs-am__label" for="csSchedTime">Time</label>
                        <input type="time" id="csSchedTime" class="form-control" value="09:00">
                    </div>
                    <div class="cs-am__field cs-am__field--full" id="csSchedDaysRow" hidden>
                        <div class="cs-sched__days" id="csSchedDays" role="group" aria-label="Days of the week"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer cs-am__footer">
                <button type="button" class="btn cs-am__btn cs-am__btn--ghost" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary cs-am__btn" id="csSchedSave">Save automation</button>
            </div>
        </div>
    </div>
</div>
<script>
window.CS_CONFIG = <?php echo json_encode(array(
    'creator'  => $this->creator,
    'plans'    => $this->plans,
    'social'   => $this->social,
    'inbox'    => $this->inbox,
    'eromify'  => array('connected' => !empty($this->eromify_connected)),
    'influencers' => $this->influencers ?? array('all' => array(), 'ready' => array()),
    'brand'    => $this->brand,
    's3_ready' => !empty($this->s3_ready),
), JSON_UNESCAPED_SLASHES); ?>;
</script>
<?php if (empty($this->needs_plan)): ?>
<script src="/js/studio.js?v=<?php echo @filemtime(Main::app_path().'/public/js/studio.js'); ?>"></script>
<?php else: ?>
<div class="plan-lock">
    <div class="plan-lock__card">
        <div class="plan-lock__icon"><i class="fa-solid fa-lock"></i></div>
        <h2 class="plan-lock__title">Subscribe to a Plan</h2>
        <p class="plan-lock__text">Unlock the Content Studio, publishing, scheduling, and analytics.</p>
        <a href="/account/billing" class="plan-lock__btn">Choose a Plan</a>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
