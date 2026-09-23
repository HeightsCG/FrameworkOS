<?php
/** Admin endpoints for the SEO content engine (keyword queue + article review). Routed by ApiRoutes; extends BaseApiController. */
class ApiSeoContentController extends BaseApiController {

    use AuditTrail;
    private function guard(){ if (!Permissions::is_admin()) { $this->jsonError('Admins only'); } }

    public function seo_keyword_addAction(){
        $this->guard();
        $id = (new SeoKeywordsModel())->add(html_entity_decode((string) ($this->post['keyword'] ?? ''), ENT_QUOTES, 'UTF-8'), ($this->post['volume'] ?? '') === '' ? null : (int) $this->post['volume'], (string) ($this->post['difficulty'] ?? 'doable'), (int) ($this->post['priority'] ?? 100));
        if ($id <= 0) { $this->jsonError('Enter a keyword'); }
        $this->jsonSuccess(['id' => $id, 'keyword' => (new SeoKeywordsModel())->get($id)]);
    }

    public function seo_keyword_updateAction(){
        $this->guard();
        $m = new SeoKeywordsModel(); $id = (int) ($this->post['id'] ?? 0); $k = $m->get($id);
        if (!$k) { $this->jsonError('Keyword not found'); }
        if (isset($this->post['priority'])) { $m->set_priority($id, (int) $this->post['priority']); }
        if (isset($this->post['status']) && in_array($this->post['status'], ['queued', 'skipped'], true)) { $m->set_status($id, (string) $this->post['status']); }
        $this->jsonSuccess(['keyword' => $m->get($id)]);
    }

    public function seo_draft_nowAction(){
        $this->guard();
        set_time_limit(300);
        $m = new SeoKeywordsModel(); $k = $m->get((int) ($this->post['keyword_id'] ?? 0));
        if (!$k) { $this->jsonError('Keyword not found'); }
        if ($k['status'] === 'drafting') { $this->jsonError('A draft is already running for this keyword'); }
        $r = SeoDrafter::draft($k);
        if (empty($r['ok'])) { $this->jsonError('Draft failed: ' . $r['error']); }
        $this->jsonSuccess(['article_id' => $r['article_id'], 'url' => '/admin/article/' . $r['article_id']]);
    }

    /** Normalise the editor's fields into the shape SeoDrafter::validate() and the table expect. */
    private function fields_from_post(): array {
        $faq = json_decode(html_entity_decode((string) ($this->post['faq'] ?? '[]'), ENT_QUOTES, 'UTF-8'), true);
        $faq = is_array($faq) ? array_values(array_filter($faq, function ($f) { return is_array($f) && trim((string) ($f['q'] ?? '')) !== '' && trim((string) ($f['a'] ?? '')) !== ''; })) : [];
        $sec = array_values(array_filter(array_map('trim', explode(',', html_entity_decode((string) ($this->post['secondary_keywords'] ?? ''), ENT_QUOTES, 'UTF-8')))));
        return [
            'title' => trim(html_entity_decode((string) ($this->post['title'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'slug' => SeoDrafter::slugify(html_entity_decode((string) ($this->post['slug'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'meta_description' => trim(html_entity_decode((string) ($this->post['meta_description'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'excerpt' => trim(html_entity_decode((string) ($this->post['excerpt'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'body_md' => html_entity_decode((string) ($this->post['body_md'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'faq' => $faq, 'secondary_keywords' => $sec,
        ];
    }

    public function seo_article_saveAction(){
        $this->guard();
        $m = new SeoArticlesModel(); $id = (int) ($this->post['id'] ?? 0); $a = $m->get($id);
        if (!$a) { $this->jsonError('Article not found'); }
        $f = $this->fields_from_post();
        $live = $a['status'] === 'published';
        if ($live || $f['slug'] === '') { $f['slug'] = (string) $a['slug']; }   // the URL is locked once published
        $errors = SeoDrafter::validate($f, $id);
        if ($live && !empty($errors)) { $this->jsonError('Fix before saving a live article: ' . implode('; ', $errors), ['errors' => $errors]); }
        $m->update_fields($id, [
            'title' => $f['title'], 'slug' => $f['slug'], 'meta_description' => $f['meta_description'], 'excerpt' => $f['excerpt'],
            'body_md' => $f['body_md'], 'body_html' => Markdown::render($f['body_md'], SeoDrafter::allowed_paths()),
            'faq' => json_encode($f['faq'], JSON_UNESCAPED_UNICODE), 'secondary_keywords' => json_encode($f['secondary_keywords'], JSON_UNESCAPED_UNICODE),
            'reading_minutes' => SeoDrafter::reading_minutes($f['body_md']),
        ]);
        $this->jsonSuccess(['errors' => $errors, 'slug' => $m->get($id)['slug'], 'message' => empty($errors) ? 'Saved' : 'Saved with ' . count($errors) . ' issue(s)']);
    }

    public function seo_article_publishAction(){
        $this->guard();
        $m = new SeoArticlesModel(); $id = (int) ($this->post['id'] ?? 0); $a = $m->get($id);
        if (!$a) { $this->jsonError('Article not found'); }
        $errors = SeoDrafter::validate(['title' => $a['title'], 'slug' => $a['slug'], 'meta_description' => $a['meta_description'], 'body_md' => $a['body_md'], 'faq' => json_decode((string) $a['faq'], true) ?: []], $id);
        if (!empty($errors)) { $this->jsonError('Fix before publishing: ' . implode('; ', $errors), ['errors' => $errors]); }
        $m->set_status($id, 'published', (int) Session::get('user_id'));
        $this->link_keyword($a, 'published', $id);
        try { IndexNow::ping(array('/blog/' . $a['slug'], '/blog', '/sitemap.xml')); } catch (\Throwable $e) { error_log('[seo] indexnow: ' . $e->getMessage()); }
        $this->jsonSuccess(['url' => '/blog/' . $a['slug'], 'message' => 'Published']);
    }

    public function seo_article_unpublishAction(){
        $this->guard();
        $m = new SeoArticlesModel(); $id = (int) ($this->post['id'] ?? 0); $a = $m->get($id);
        if (!$a) { $this->jsonError('Article not found'); }
        $m->set_status($id, 'review'); $this->link_keyword($a, 'drafted', $id);
        try { IndexNow::ping(array('/blog/' . $a['slug'], '/blog', '/sitemap.xml')); } catch (\Throwable $e) { error_log('[seo] indexnow: ' . $e->getMessage()); }   // tell them it is gone too
        $this->jsonSuccess(['message' => 'Back in review']);
    }

    public function seo_article_rewriteAction(){
        $this->guard();
        set_time_limit(300);
        $m = new SeoArticlesModel(); $id = (int) ($this->post['id'] ?? 0); $a = $m->get($id);
        if (!$a) { $this->jsonError('Article not found'); }
        if ($a['status'] === 'published') { $this->jsonError('Unpublish this article before requesting a rewrite'); }
        $note = trim(html_entity_decode((string) ($this->post['note'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($note === '') { $this->jsonError('Say what should change'); }
        $km = new SeoKeywordsModel();
        $rows = $km->all(); $kw = null; foreach ($rows as $r) { if ((int) $r['article_id'] === $id) { $kw = $r; break; } }
        if (!$kw) { $kid = $km->add($a['target_keyword'], null, 'doable', 100); $kw = $km->get($kid); }
        $m->update_fields($id, ['rewrite_note' => $note]);
        $r = SeoDrafter::draft($kw, $note, $id);
        if (empty($r['ok'])) { $this->jsonError('Rewrite failed: ' . $r['error']); }
        $this->jsonSuccess(['message' => 'Rewritten — review the new draft']);
    }

    /** Generate a new cover image for an article (replaces the old one). One fal render, ~30-90 s. */
    public function seo_article_coverAction(){
        $this->guard();
        set_time_limit(240);
        $m = new SeoArticlesModel(); $a = $m->get((int) ($this->post['id'] ?? 0));
        if (!$a) { $this->jsonError('Article not found'); }
        $url = SeoDrafter::make_cover($a);
        if ($url === '') { $this->jsonError('Could not make a cover right now. Try again.'); }
        $this->jsonSuccess(['url' => $url, 'message' => 'New cover ready']);
    }

    public function seo_article_discardAction(){
        $this->guard();
        $m = new SeoArticlesModel(); $id = (int) ($this->post['id'] ?? 0); $a = $m->get($id);
        if (!$a) { $this->jsonError('Article not found'); }
        $m->set_status($id, 'archived'); $this->link_keyword($a, 'queued', null);
        $this->jsonSuccess(['message' => 'Discarded']);
    }

    private function link_keyword(array $a, string $status, $article_id): void {
        $km = new SeoKeywordsModel();
        foreach ($km->all() as $r) {
            if ((int) $r['article_id'] !== (int) $a['id']) { continue; }
            if ($article_id === null) { $km->clear_article((int) $r['id'], $status); } else { $km->set_status((int) $r['id'], $status, $article_id); }
            return;
        }
    }
}
