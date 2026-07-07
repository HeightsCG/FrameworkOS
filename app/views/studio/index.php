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
            <button type="button" class="btn btn-primary" id="csUploadBtn"><i class="fa-solid fa-arrow-up-from-bracket"></i> Upload media</button>
            <button type="button" class="btn btn-primary" id="csNewPostBtn"><i class="fa-solid fa-plus"></i> New post</button>
            <button type="button" class="btn btn-primary" id="csCreateCollectionBtn"><i class="fa-solid fa-folder-plus"></i> Create collection</button>
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
            <div class="cs-collections" id="csCollections"></div>
            <div class="cs-empty" id="csColEmpty" hidden>
                <i class="fa-solid fa-folder-open cs-empty__icon"></i>
                <h2 class="cs-empty__title">No collections yet</h2>
                <p class="cs-empty__text">Group related files so they're easy to find when you post. Create your first one above.</p>
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

<script>
window.CS_CONFIG = <?php echo json_encode(array(
    'creator'  => $this->creator,
    's3_ready' => !empty($this->s3_ready),
), JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="/js/studio.js"></script>

<?php endif; ?>
