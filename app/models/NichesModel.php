<?php
/**
 * Creator directory niches (sql/2026-10-09_niches.sql): the categories behind /creators/<slug>, the chips and the
 * Settings select. Managed from /admin > Niches. The slug is the URL and never changes once created.
 */
class NichesModel extends Model {

    /** Every niche, in directory order (admin list). */
    public function all(): array {
        return (array) parent::select("SELECT id, slug, name, sort_order, active FROM niches ORDER BY sort_order, name, id");
    }

    /** slug => name for the active niches, in directory order. */
    public function active(): array {
        $out = array();
        foreach ((array) parent::select("SELECT slug, name FROM niches WHERE active = 1 ORDER BY sort_order, name, id") as $r) {
            $out[(string) $r['slug']] = (string) $r['name'];
        }
        return $out;
    }

    /** slug => creators who switched the directory on with that niche (listed or still waiting for the checks). */
    public function listed_counts(): array {
        $out = array();
        foreach ((array) parent::select("SELECT directory_category AS slug, COUNT(*) AS n FROM creator_profiles WHERE directory_listed = 1 AND directory_category IS NOT NULL GROUP BY directory_category") as $r) {
            $out[(string) $r['slug']] = (int) $r['n'];
        }
        return $out;
    }

    public function get($id){
        $r = parent::select("SELECT id, slug, name, sort_order, active FROM niches WHERE id = :id", array('id' => (int) $id));
        return (is_array($r) && count($r) === 1) ? $r[0] : null;
    }

    public function slug_taken($slug): bool {
        $r = parent::select("SELECT 1 FROM niches WHERE slug = :slug", array('slug' => (string) $slug));
        return is_array($r) && count($r) > 0;
    }

    /** New niche at the end of the list, active. Returns the id. */
    public function add($slug, $name): int {
        $r   = parent::select("SELECT COALESCE(MAX(sort_order), 0) AS m FROM niches");
        $now = gmdate('Y-m-d H:i:s');
        return (int) parent::insert('niches', array('slug' => (string) $slug, 'name' => (string) $name, 'sort_order' => (int) ($r[0]['m'] ?? 0) + 10,
            'active' => 1, 'created_at' => $now, 'updated_at' => $now));
    }

    public function rename($id, $name){
        return parent::update('niches', array('name' => (string) $name, 'updated_at' => gmdate('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $id));
    }

    public function set_active($id, $on){
        return parent::update('niches', array('active' => $on ? 1 : 0, 'updated_at' => gmdate('Y-m-d H:i:s')), 'id = :id', array('id' => (int) $id));
    }

    /** Move one niche up (-1) or down (1) a place: renumbers the list in steps of 10 with the two swapped. */
    public function move($id, $dir): bool {
        $ids = array_map(function ($r) { return (int) $r['id']; }, $this->all());
        $i = array_search((int) $id, $ids, true);
        $j = $i === false ? -1 : $i + ($dir < 0 ? -1 : 1);
        if ($i === false || $j < 0 || $j >= count($ids)) { return false; }
        $tmp = $ids[$i]; $ids[$i] = $ids[$j]; $ids[$j] = $tmp;
        foreach ($ids as $k => $nid) { parent::update('niches', array('sort_order' => ($k + 1) * 10), 'id = :id', array('id' => $nid)); }
        return true;
    }
}
