<?php
/** Blog articles for the SEO content engine (spec §5). status: draft|review|published|archived. */
class SeoArticlesModel extends Model {
    public function __construct(){ parent::__construct(); }

    public function get($id){
        $rows = parent::select("SELECT * FROM seo_articles WHERE id = :id", array('id' => (int) $id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function get_by_slug($slug, $published_only = true){
        $sql = "SELECT * FROM seo_articles WHERE slug = :s" . ($published_only ? " AND status = 'published'" : '');
        $rows = parent::select($sql, array('s' => (string) $slug));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function slug_exists($slug, $except_id = 0){
        $rows = parent::select("SELECT id FROM seo_articles WHERE slug = :s AND id <> :x", array('s' => (string) $slug, 'x' => (int) $except_id));
        return is_array($rows) && count($rows) > 0;
    }

    public function create(array $f){
        $now = gmdate('Y-m-d H:i:s');
        $f['created_at'] = $now; $f['updated_at'] = $now;
        if (!isset($f['status'])) { $f['status'] = 'review'; }
        return (int) parent::insert('seo_articles', $f);
    }

    public function update_fields($id, array $f){
        $f['updated_at'] = gmdate('Y-m-d H:i:s');
        parent::update('seo_articles', $f, 'id = :id', array('id' => (int) $id));
    }

    /** published → sets published_at (first time) and published_by; anything else leaves the dates alone. */
    public function set_status($id, $status, $by = null){
        $row = $this->get($id);
        $data = array('status' => (string) $status, 'updated_at' => gmdate('Y-m-d H:i:s'));
        if ($status === 'published') {
            if (empty($row['published_at'])) { $data['published_at'] = gmdate('Y-m-d H:i:s'); }
            if ($by !== null) { $data['published_by'] = (int) $by; }
        }
        parent::update('seo_articles', $data, 'id = :id', array('id' => (int) $id));
    }

    public function published($limit = 12, $offset = 0){
        $limit = max(1, min(50, (int) $limit)); $offset = max(0, (int) $offset);
        return (array) parent::select(
            "SELECT id, slug, title, meta_description, excerpt, target_keyword, secondary_keywords, reading_minutes, cover_image_url, published_at, updated_at, views
             FROM seo_articles WHERE status = 'published' ORDER BY published_at DESC, id DESC LIMIT $offset, $limit");
    }

    public function count_published(){
        $rows = parent::select("SELECT COUNT(*) AS c FROM seo_articles WHERE status = 'published'");
        return isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
    }

    public function newest_published($limit = 3){ return $this->published($limit, 0); }

    /** WHERE fragment + params for a blog search: every word must appear in title, excerpt, topic keywords or body. */
    private function search_where($q, array &$params){
        $words = array_slice(array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim((string) $q))), function ($w) { return mb_strlen($w) >= 2; })), 0, 6);
        $clauses = array();
        foreach ($words as $i => $w) {
            $like = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $w) . '%';
            $n = $i + 1;
            $clauses[] = "(title LIKE :t$n OR excerpt LIKE :x$n OR target_keyword LIKE :k$n OR secondary_keywords LIKE :s$n OR body_md LIKE :b$n)";
            $params["t$n"] = $like; $params["x$n"] = $like; $params["k$n"] = $like; $params["s$n"] = $like; $params["b$n"] = $like;
        }
        return empty($clauses) ? '' : ' AND ' . implode(' AND ', $clauses);
    }

    /** Published articles matching $q, title matches first, then newest. */
    public function search($q, $limit = 24, $offset = 0){
        $limit = max(1, min(50, (int) $limit)); $offset = max(0, (int) $offset);
        $params = array(); $where = $this->search_where($q, $params);
        if ($where === '') { return array(); }
        $first = '%' . str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), mb_strtolower(trim((string) $q))) . '%';
        $params['tq'] = $first;
        return (array) parent::select(
            "SELECT id, slug, title, meta_description, excerpt, target_keyword, secondary_keywords, reading_minutes, cover_image_url, published_at, updated_at, views
             FROM seo_articles WHERE status = 'published'$where
             ORDER BY (title LIKE :tq) DESC, published_at DESC, id DESC LIMIT $offset, $limit", $params);
    }

    public function count_search($q){
        $params = array(); $where = $this->search_where($q, $params);
        if ($where === '') { return 0; }
        $rows = parent::select("SELECT COUNT(*) AS c FROM seo_articles WHERE status = 'published'$where", $params);
        return isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
    }

    public function by_status(array $statuses){
        $in = array(); $params = array(); $i = 0;
        foreach ($statuses as $s) { $i++; $in[] = ':s' . $i; $params['s' . $i] = (string) $s; }
        if (empty($in)) { return array(); }
        return (array) parent::select(
            "SELECT id, slug, title, meta_description, target_keyword, status, reading_minutes, views, model, rewrite_note, created_at, updated_at, published_at
             FROM seo_articles WHERE status IN (" . implode(',', $in) . ") ORDER BY updated_at DESC, id DESC", $params);
    }

    /** Up to $limit other published articles sharing a secondary keyword or the target keyword; newest fill the rest. */
    public function related(array $article, $limit = 3){
        $limit = max(1, min(6, (int) $limit));
        $terms = array_filter(array_map('strtolower', array_merge(array((string) ($article['target_keyword'] ?? '')), (array) json_decode((string) ($article['secondary_keywords'] ?? '[]'), true))));
        $pool = $this->published(60, 0);
        $scored = array();
        foreach ($pool as $p) {
            if ((int) $p['id'] === (int) ($article['id'] ?? 0)) { continue; }
            $pt = array_filter(array_map('strtolower', array_merge(array((string) $p['target_keyword']), (array) json_decode((string) $p['secondary_keywords'], true))));
            $p['_score'] = count(array_intersect($terms, $pt));
            $scored[] = $p;
        }
        usort($scored, function ($x, $y) { return ($y['_score'] <=> $x['_score']) ?: strcmp((string) $y['published_at'], (string) $x['published_at']); });
        return array_slice($scored, 0, $limit);
    }

    /** One view per (article, viewer_key); bumps the cached counter only on a new pair. */
    public function record_view($id, $viewer_key){
        $id = (int) $id; $viewer_key = substr((string) $viewer_key, 0, 64);
        if ($viewer_key === '' || $viewer_key === 'bot') { return; }   // crawlers never count
        // One atomic statement: concurrent requests with the same key can't collide on uq_view.
        $sth = $this->db->prepare("INSERT IGNORE INTO seo_page_views (article_id, viewer_key, created_at) VALUES (:a, :k, :t)");
        $sth->execute(array('a' => $id, 'k' => $viewer_key, 't' => gmdate('Y-m-d H:i:s')));
        if ($sth->rowCount() < 1) { return; }
        parent::sql("UPDATE seo_articles SET views = views + 1 WHERE id = :id", array('id' => $id));
    }

    /** Published bodies for llms-full.txt, newest first, one query. */
    public function published_bodies($limit = 200){
        $limit = max(1, min(500, (int) $limit));
        return (array) parent::select("SELECT id, slug, title, body_html FROM seo_articles WHERE status = 'published' ORDER BY published_at DESC, id DESC LIMIT $limit");
    }
}
