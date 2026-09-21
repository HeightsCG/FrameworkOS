<?php $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }; $a = $this->article; ?>
<link rel="stylesheet" href="/css/admin.css?v=<?php echo @filemtime(Main::app_path().'/public/css/admin.css'); ?>">
<div class="adm adm--editor" id="admEditor" data-article="<?php echo (int) $a['id']; ?>" data-slug="<?php echo $e($a['slug']); ?>">
    <div class="adm__head">
        <div>
            <a class="adm-back" href="/admin"><i class="fa-solid fa-arrow-left"></i> Content</a>
            <h1 class="adm__title"><?php echo $e($a['title']); ?></h1>
            <p class="adm__sub"><span class="adm-tag adm-tag--<?php echo $e($a['status']); ?>"><?php echo $e(ucfirst($a['status'])); ?></span> · keyword “<?php echo $e($a['target_keyword']); ?>” · <?php echo $e($a['model']); ?></p>
        </div>
        <div class="adm-editor__acts">
            <button type="button" class="adm-btn" data-ed="save">Save</button>
            <?php if ($a['status'] !== 'published'): ?><button type="button" class="adm-btn" data-ed="rewrite">Request Rewrite</button><?php endif; ?>
            <?php if ($a['status'] === 'published'): ?><button type="button" class="adm-btn" data-ed="unpublish">Unpublish</button>
            <?php else: ?><button type="button" class="adm-btn adm-btn--ok" data-ed="publish">Publish</button><?php endif; ?>
            <button type="button" class="adm-btn adm-btn--danger" data-ed="discard">Discard</button>
        </div>
    </div>
    <ul class="adm-issues" id="admIssues"<?php if (empty($this->errors)): ?> hidden<?php endif; ?>>
        <?php foreach ($this->errors as $err): ?><li><?php echo $e($err); ?></li><?php endforeach; ?>
    </ul>
    <div class="adm-editor">
        <form class="adm-editor__form" id="admArticleForm">
            <label>Title<input type="text" name="title" maxlength="70" value="<?php echo $e($a['title']); ?>"></label>
            <label>Slug<input type="text" name="slug" maxlength="120" value="<?php echo $e($a['slug']); ?>"<?php if ($a['status'] === 'published'): ?> readonly<?php endif; ?>></label>
            <label>Meta description<input type="text" name="meta_description" maxlength="155" value="<?php echo $e($a['meta_description']); ?>"></label>
            <label>Excerpt<textarea name="excerpt" rows="2"><?php echo $e($a['excerpt']); ?></textarea></label>
            <label>Secondary keywords<input type="text" name="secondary_keywords" value="<?php echo $e(implode(', ', (array) json_decode((string) $a['secondary_keywords'], true))); ?>"></label>
            <label>Body (Markdown)<textarea name="body_md" rows="28" spellcheck="true"><?php echo $e($a['body_md']); ?></textarea></label>
            <div class="adm-faq" id="admFaq">
                <div class="adm-faq__head"><span>Questions</span><button type="button" class="adm-btn" data-faq-add>Add Question</button></div>
                <?php foreach ($this->faq as $f): ?>
                <div class="adm-faq__row"><input type="text" placeholder="Question" value="<?php echo $e($f['q']); ?>" data-faq-q><textarea rows="2" placeholder="Answer" data-faq-a><?php echo $e($f['a']); ?></textarea><button type="button" class="adm-btn adm-btn--danger" data-faq-del aria-label="Remove"><i class="fa-solid fa-xmark"></i></button></div>
                <?php endforeach; ?>
            </div>
        </form>
        <div class="adm-editor__preview">
            <div class="adm-editor__prevhead"><span>Preview</span><a href="/blog/<?php echo $e($a['slug']); ?>?preview=1" target="_blank" rel="noopener">Open</a></div>
            <iframe id="admPreview" src="/blog/<?php echo $e($a['slug']); ?>?preview=1" title="Article preview"></iframe>
        </div>
    </div>
</div>
<script src="/js/admin-content.js?v=<?php echo @filemtime(Main::app_path().'/public/js/admin-content.js'); ?>"></script>
