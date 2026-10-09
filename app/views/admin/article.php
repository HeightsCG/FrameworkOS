<?php include __DIR__ . '/_shell.php'; ?>
<?php $a = $this->article; ?>
<div class="adm--editor" id="admEditor" data-article="<?php echo (int) $a['id']; ?>" data-slug="<?php echo $e($a['slug']); ?>">
    <a class="adm-back" href="/admin/articles"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Articles</a>
    <header class="adm-head">
        <div>
            <h1 class="adm-head__title"><?php echo $e($a['title']); ?></h1>
            <p class="adm-head__sub"><span class="adm-tag adm-tag--<?php echo $e($a['status']); ?>"><?php echo $e(ucfirst($a['status'])); ?></span> · keyword “<?php echo $e($a['target_keyword']); ?>” · <?php echo $e($a['model']); ?> · <?php echo $e($fmt($a['updated_at'], true)); ?></p>
        </div>
        <div class="adm-head__acts adm-editor__acts">
            <button type="button" class="adm-btn adm-btn--danger" data-ed="discard">Discard</button>
            <?php if ($a['status'] !== 'published'): ?><button type="button" class="adm-btn" data-ed="rewrite">Request Rewrite</button><?php endif; ?>
            <button type="button" class="adm-btn" data-ed="save">Save</button>
            <?php if ($a['status'] === 'published'): ?><button type="button" class="adm-btn" data-ed="unpublish">Unpublish</button>
            <?php else: ?><button type="button" class="adm-btn adm-btn--primary" data-ed="publish">Publish</button><?php endif; ?>
        </div>
    </header>
    <ul class="adm-issues" id="admIssues"<?php if (empty($this->errors)): ?> hidden<?php endif; ?>>
        <?php foreach ($this->errors as $err): ?><li><?php echo $e($err); ?></li><?php endforeach; ?>
    </ul>
    <div class="adm-editor">
        <form class="adm-editor__form" id="admArticleForm">
            <div class="adm-cover">
                <span class="adm-cover__label">Cover</span>
                <div class="adm-cover__row">
                    <?php if (trim((string) $a['cover_image_url']) !== ''): ?><img class="adm-cover__img" id="admCoverImg" src="<?php echo $e($a['cover_image_url']); ?>" alt=""><?php else: ?><span class="adm-cover__none" id="admCoverImg">No cover yet</span><?php endif; ?>
                    <button type="button" class="adm-btn adm-btn--sm" data-ed="cover">New Cover</button>
                </div>
            </div>
            <label>Title<input type="text" name="title" maxlength="70" value="<?php echo $e($a['title']); ?>"></label>
            <label>Slug<input type="text" name="slug" maxlength="120" value="<?php echo $e($a['slug']); ?>"<?php if ($a['status'] === 'published'): ?> readonly<?php endif; ?>></label>
            <label>Meta Description<input type="text" name="meta_description" maxlength="155" value="<?php echo $e($a['meta_description']); ?>"></label>
            <label>Excerpt<textarea name="excerpt" rows="2"><?php echo $e($a['excerpt']); ?></textarea></label>
            <label>Secondary Keywords<input type="text" name="secondary_keywords" value="<?php echo $e(implode(', ', (array) json_decode((string) $a['secondary_keywords'], true))); ?>"></label>
            <label>Body (Markdown)<textarea name="body_md" rows="28" spellcheck="true"><?php echo $e($a['body_md']); ?></textarea></label>
            <div class="adm-faq" id="admFaq">
                <div class="adm-faq__head"><span>Questions</span><button type="button" class="adm-btn adm-btn--sm" data-faq-add>Add Question</button></div>
                <?php foreach ($this->faq as $f): ?>
                <div class="adm-faq__row"><input type="text" placeholder="Question" value="<?php echo $e($f['q']); ?>" data-faq-q><textarea rows="2" placeholder="Answer" data-faq-a><?php echo $e($f['a']); ?></textarea><button type="button" class="adm-more" data-faq-del aria-label="Remove question"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
                <?php endforeach; ?>
            </div>
        </form>
        <div class="adm-editor__preview">
            <div class="adm-editor__prevhead"><span>Preview</span><a href="/blog/<?php echo $e($a['slug']); ?>?preview=1" target="_blank" rel="noopener">Open</a></div>
            <iframe id="admPreview" src="/blog/<?php echo $e($a['slug']); ?>?preview=1" title="Article preview"></iframe>
        </div>
    </div>
</div>
<?php include __DIR__ . '/_shell_end.php'; ?>
<script src="/js/admin-content.js?v=<?php echo @filemtime(Main::app_path() . '/public/js/admin-content.js'); ?>"></script>
