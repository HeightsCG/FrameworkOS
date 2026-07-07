<link rel="stylesheet" href="/css/studio.css">

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
            <button type="button" class="btn btn-primary" id="csUploadBtn"><i class="fa-solid fa-arrow-up-from-bracket"></i> Upload Media</button>
            <button type="button" class="btn btn-primary" id="csNewPostBtn"><i class="fa-solid fa-plus"></i> New Post</button>
            <button type="button" class="btn btn-primary" id="csCreateCollectionBtn"><i class="fa-solid fa-folder-plus"></i> Create Collection</button>
        </div>
    </header>

    <?php if (empty($this->s3_ready)): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="alert">
        <i class="fa-solid fa-triangle-exclamation"></i>
        Media storage isn't configured yet, so uploads are turned off. Everything else works.
    </div>
    <?php endif; ?>

    <ul class="nav nav-tabs cs-tabs" id="csTabs" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" id="csTabLibrary" data-bs-toggle="tab" data-bs-target="#csPaneLibrary" type="button" role="tab"><i class="fa-solid fa-images"></i> Library</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="csTabPosts" data-bs-toggle="tab" data-bs-target="#csPanePosts" type="button" role="tab"><i class="fa-solid fa-rectangle-list"></i> Posts</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="csTabCalendar" data-bs-toggle="tab" data-bs-target="#csPaneCalendar" type="button" role="tab"><i class="fa-solid fa-calendar-days"></i> Calendar</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="csTabCollections" data-bs-toggle="tab" data-bs-target="#csPaneCollections" type="button" role="tab"><i class="fa-solid fa-folder"></i> Collections</button></li>
    </ul>

    <div class="tab-content cs-tabcontent">

        <!-- ============ LIBRARY ============ -->
        <div class="tab-pane fade show active" id="csPaneLibrary" role="tabpanel">
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

        <!-- ============ POSTS (next checkpoint) ============ -->
        <div class="tab-pane fade" id="csPanePosts" role="tabpanel">
            <div class="cs-empty">
                <i class="fa-solid fa-rectangle-list cs-empty__icon"></i>
                <h2 class="cs-empty__title">Posts arrive in the next step</h2>
                <p class="cs-empty__text">The Library is ready to verify now. Post creating, publishing, and scheduling come next.</p>
            </div>
        </div>

        <!-- ============ CALENDAR (later checkpoint) ============ -->
        <div class="tab-pane fade" id="csPaneCalendar" role="tabpanel">
            <div class="cs-empty">
                <i class="fa-solid fa-calendar-days cs-empty__icon"></i>
                <h2 class="cs-empty__title">Calendar is coming soon</h2>
                <p class="cs-empty__text">Once posts and scheduling are in, your whole release schedule shows up here.</p>
            </div>
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
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header cs-comp__head">
                <h5 class="modal-title" id="csCompTitle">New post</h5>
                <span class="cs-comp__save" id="csCompSave"></span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body cs-comp__body">

                <!-- editing controls -->
                <div class="cs-comp__edit">
                    <label class="cs-dv__label">Media</label>
                    <div class="cs-comp__media" id="csCompMedia"></div>
                    <div class="cs-comp__mediaactions">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="csCompAdd"><i class="fa-solid fa-photo-film"></i> Add from library</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="csCompUpload"><i class="fa-solid fa-arrow-up-from-bracket"></i> Upload</button>
                    </div>

                    <label class="cs-dv__label mt-3" for="csCompCaption">Caption</label>
                    <textarea id="csCompCaption" class="form-control" rows="4" maxlength="3000" placeholder="Write a caption…"></textarea>
                    <div class="cs-comp__count"><span id="csCompCount">0</span> / 3000</div>

                    <label class="cs-dv__label mt-3">Who can see this</label>
                    <div class="cs-seg" id="csCompAudience">
                        <button type="button" class="cs-seg__opt is-on" data-aud="free"><i class="fa-solid fa-globe"></i> <span><strong>Everyone</strong><small>Anyone can see it</small></span></button>
                        <button type="button" class="cs-seg__opt" data-aud="subscribers"><i class="fa-solid fa-lock"></i> <span><strong>Subscribers only</strong><small>Members only</small></span></button>
                    </div>
                    <div class="cs-comp__tier" id="csCompTier" hidden>
                        <label class="cs-dv__label mt-3" for="csCompTierSel">Available to</label>
                        <select id="csCompTierSel" class="form-select"><option value="">All Subscribers</option></select>
                    </div>

                    <label class="cs-dv__label mt-3">Publishing</label>
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
<script>
window.CS_CONFIG = <?php echo json_encode(array(
    'creator'  => $this->creator,
    'plans'    => $this->plans,
    's3_ready' => !empty($this->s3_ready),
), JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="/js/studio.js"></script>
<?php endif; ?>
