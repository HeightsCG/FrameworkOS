<?php
/** Keyword queue for the SEO content engine (spec §5). One row per target keyword; status walks queued → drafting → drafted → published. */
class SeoKeywordsModel extends Model {
    public function __construct(){ parent::__construct(); }

    public function all(){
        return (array) parent::select(
            "SELECT k.*, a.slug AS article_slug, a.title AS article_title, a.status AS article_status
             FROM seo_keywords k LEFT JOIN seo_articles a ON a.id = k.article_id
             ORDER BY FIELD(k.status, 'drafting', 'queued', 'drafted', 'published', 'skipped'), k.priority ASC, k.id ASC");
    }

    public function get($id){
        $rows = parent::select("SELECT * FROM seo_keywords WHERE id = :id", array('id' => (int) $id));
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    /** Lowest priority number wins; ties by age. */
    public function next_queued(){
        $rows = parent::select("SELECT * FROM seo_keywords WHERE status = 'queued' ORDER BY priority ASC, id ASC LIMIT 1");
        return (is_array($rows) && count($rows) === 1) ? $rows[0] : null;
    }

    public function add($keyword, $volume, $difficulty, $priority = 100, $cluster = ''){
        $keyword = trim(mb_substr((string) $keyword, 0, 160));
        if ($keyword === '') { return 0; }
        $existing = parent::select("SELECT id FROM seo_keywords WHERE keyword = :k", array('k' => $keyword));
        if (is_array($existing) && count($existing) === 1) { return (int) $existing[0]['id']; }
        $now = gmdate('Y-m-d H:i:s');
        return (int) parent::insert('seo_keywords', array(
            'keyword' => $keyword, 'volume' => $volume === null || $volume === '' ? null : (int) $volume,
            'difficulty' => in_array($difficulty, array('easy', 'doable', 'hard'), true) ? $difficulty : 'doable',
            'priority' => (int) $priority, 'cluster' => mb_substr((string) $cluster, 0, 40), 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now,
        ));
    }

    public function set_status($id, $status, $article_id = null, $error = null){
        $data = array('status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s'), 'last_error' => $error === null ? null : mb_substr((string) $error, 0, 255));
        if ($article_id !== null) { $data['article_id'] = (int) $article_id; }
        parent::update('seo_keywords', $data, 'id = :id', array('id' => (int) $id));
    }

    /** Unlinks the keyword from its article (article_id NULL, not 0) and sets its status. */
    public function clear_article($id, $status = 'queued'){
        parent::update('seo_keywords', array('status' => (string) $status, 'article_id' => null, 'last_error' => null, 'updated_at' => gmdate('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $id));
    }

    /** Rows left in 'drafting' longer than $minutes (a crashed run) go back to 'queued'. Returns how many. */
    public function requeue_stale(int $minutes = 30): int {
        $cut = gmdate('Y-m-d H:i:s', time() - max(1, $minutes) * 60);
        return (int) parent::update('seo_keywords', array('status' => 'queued', 'updated_at' => gmdate('Y-m-d H:i:s')), "status = 'drafting' AND updated_at < :cut", array('cut' => $cut));
    }

    public function set_priority($id, $priority){
        parent::update('seo_keywords', array('priority' => (int) $priority, 'updated_at' => gmdate('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $id));
    }

    /** $rows: array of array(keyword, volume|null, difficulty, priority). Returns how many were new. */
    public function seed(array $rows){
        $n = 0;
        foreach ($rows as $r) {
            $before = parent::select("SELECT id FROM seo_keywords WHERE keyword = :k", array('k' => $r[0]));
            if (is_array($before) && count($before)) { continue; }
            if ($this->add($r[0], $r[1], $r[2], $r[3], $r[4] ?? '') > 0) { $n++; }
        }
        return $n;
    }
}
