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

    /**
     * Keyword wording that is out of scope since 2026-11-09 (the product no longer sells or compares itself with creator
     * platforms): a queued row that matches it, or whose cluster is no longer in SeoDrafter::CLUSTERS, is never drafted.
     */
    const OUT_OF_SCOPE = '/\b(?:onlyfans|fansly|fanvue|patreon|ko-?fi|passes|linktree|beacons|stan store|pay[- ]per[- ]view|ppv|paywall|paid (?:dms?|messages?|live|event)|payouts?|get(?:ting)? paid|fees?|make money|making money|monetiz\w*|income|revenue|sell\w*|subscriptions?|subscribers?|memberships?|members|tiers?|bundles?|tickets?|charge|fans? (?:to )?pay|adult|nsfw|chargebacks?|refunds?|taxes)\b/i';

    /** Is a keyword still draftable: a live cluster and no out-of-scope wording. */
    public static function in_scope(array $k): bool {
        return SeoDrafter::cluster((string) ($k['cluster'] ?? '')) !== '' && !preg_match(self::OUT_OF_SCOPE, (string) ($k['keyword'] ?? ''));
    }

    /** Lowest priority number wins (P1 = 1, P2 = 2, the rest 3); ties by age. Out-of-scope rows are passed over. */
    public function next_queued(){
        $rows = parent::select("SELECT * FROM seo_keywords WHERE status = 'queued' ORDER BY priority ASC, id ASC");
        foreach ((array) $rows as $r) { if (self::in_scope($r)) { return $r; } }
        return null;
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

    /**
     * Cluster for each keyword (and each article's target keyword) after the 2026-10-09 re-cluster. The selling,
     * payout and platform-comparison clusters were removed 2026-11-09, so only the kept clusters are listed here.
     */
    const RECLUSTER = array(
        'cross-post to social media from one place' => 'social-publishing',
        // dev-only demo articles (target keywords without a queue row)
        'social cross-post' => 'social-publishing',
        'ai captions' => 'social-publishing',
        'social scheduling' => 'social-publishing',
        'social automation' => 'social-publishing',
    );

    /** Keyword counts per cluster, all statuses. */
    public function cluster_counts(){
        $out = array();
        foreach ((array) parent::select("SELECT cluster, COUNT(*) AS c FROM seo_keywords GROUP BY cluster ORDER BY cluster") as $r) { $out[(string) $r['cluster']] = (int) $r['c']; }
        return $out;
    }

    /** Applies RECLUSTER to keywords and their articles. Safe to run again (only rows that differ change). Returns rows changed. */
    public function recluster(){
        $n = 0; $articles = new SeoArticlesModel();
        foreach (self::RECLUSTER as $k => $c) {
            $n += (int) parent::update('seo_keywords', array('cluster' => $c, 'updated_at' => gmdate('Y-m-d H:i:s')), 'keyword = :k AND cluster <> :c', array('k' => $k, 'c' => $c));
            $n += $articles->recluster_by_keyword($k, $c);
        }
        return $n;
    }

    /**
     * Queue load: each row in $rows (keyword, volume, difficulty, priority, cluster) is added, or, when already queued,
     * takes that priority and cluster. Keywords named in $demotable (the loader's own earlier rows) that are still queued
     * and no longer listed drop to $rest_priority. An existing row is only changed while its priority is still one the
     * loader set (1, 2, 3, or its seed priority in $seed_priority): a priority set by hand in /admin is never touched.
     * Returns array(added, updated, rested).
     */
    public function load_queue(array $rows, $rest_priority = 3, array $demotable = array(), array $seed_priority = array()){
        $own = function ($keyword, $priority) use ($seed_priority) {
            return in_array((int) $priority, array(1, 2, 3), true) || (isset($seed_priority[(string) $keyword]) && (int) $seed_priority[(string) $keyword] === (int) $priority);
        };
        $listed = array_map(function ($r) { return (string) $r[0]; }, $rows);
        $rested = 0;
        $demotable = array_map('strtolower', array_map('strval', $demotable));
        foreach ((array) parent::select("SELECT id, keyword, priority FROM seo_keywords WHERE status = 'queued'") as $q) {
            if (in_array((string) $q['keyword'], $listed, true) || !in_array(strtolower((string) $q['keyword']), $demotable, true) || !$own($q['keyword'], $q['priority'])) { continue; }
            $rested += (int) parent::update('seo_keywords', array('priority' => (int) $rest_priority, 'updated_at' => gmdate('Y-m-d H:i:s')), 'id = :id AND priority <> :p', array('id' => (int) $q['id'], 'p' => (int) $rest_priority));
        }
        $added = 0; $updated = 0;
        foreach ($rows as $r) {
            $before = parent::select("SELECT id, keyword, status, priority FROM seo_keywords WHERE keyword = :k", array('k' => (string) $r[0]));
            if (is_array($before) && count($before) === 1) {
                if ((string) $before[0]['status'] !== 'queued' || !$own($before[0]['keyword'], $before[0]['priority'])) { continue; }
                $updated += (int) parent::update('seo_keywords', array('priority' => (int) $r[3], 'cluster' => mb_substr((string) ($r[4] ?? ''), 0, 40), 'updated_at' => gmdate('Y-m-d H:i:s')), 'id = :id AND (priority <> :p OR cluster <> :c)', array('id' => (int) $before[0]['id'], 'p' => (int) $r[3], 'c' => mb_substr((string) ($r[4] ?? ''), 0, 40)));
                continue;
            }
            if ($this->add($r[0], $r[1], $r[2], $r[3], $r[4] ?? '') > 0) { $added++; }
        }
        return array($added, $updated, $rested);
    }

    /** The first $limit queued rows in drafting order. */
    public function queue($limit = 10){
        $limit = max(1, min(200, (int) $limit));
        return (array) parent::select("SELECT id, keyword, cluster, priority, created_at FROM seo_keywords WHERE status = 'queued' ORDER BY priority ASC, id ASC LIMIT $limit");
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
