<?php
/**
 * Tenth one-time text pass (2026-10-14): the studio publishes to ten platforms (nine social networks plus Fanvue), so every
 * "nine networks" / "nine social networks" in a published article (title, meta description, excerpt, body and FAQ) becomes
 * "ten platforms", capitals kept. Pure text, no AI calls. Ends by listing any "nine ... networks" left.
 * Usage: APPLICATION_ENV=production php cron/seo_text_fixes_2026-10-14j.php [--dry-run|--apply]
 */
$path = dirname(__DIR__);
putenv('APPLICATION_ENV=' . (getenv('APPLICATION_ENV') ?: 'production'));
if (is_file($path . '/vendor/autoload.php')) { require_once $path . '/vendor/autoload.php'; }
spl_autoload_register(function ($c) use ($path) { foreach (array("/app/models/$c.php", "/libs/Classes/$c.php", "/app/controllers/$c.php", "/app/$c.php") as $s) { if (file_exists($path . $s)) { require_once $path . $s; return; } } });
$opts = getopt('', array('dry-run', 'apply'));
$apply = isset($opts['apply']) && !isset($opts['dry-run']);
if (!$apply) { echo "DRY RUN (add --apply to write)\n"; }

// "nine social networks", "Nine Networks", "nine networks" => "ten platforms" in the same capitals
$fix = function (string $s, int &$n): string {
    return preg_replace_callback('/\b(nine)( social)? (networks)\b/i', function ($m) use (&$n) {
        $n++;
        if ($m[1] === 'NINE') { return 'TEN PLATFORMS'; }
        if (ctype_upper($m[3][0])) { return 'Ten Platforms'; }   // a Title Case heading
        return ctype_upper($m[1][0]) ? 'Ten platforms' : 'ten platforms';
    }, $s);
};

$articles = new SeoArticlesModel(); $total = 0; $left = 0;
$dir = sys_get_temp_dir() . '/cls_seo_backup_' . gmdate('Y-m-d');
foreach ($articles->published_markdown() as $row) {
    $a = $articles->get((int) $row['id']); if (!$a) { continue; }
    $slug = (string) $a['slug']; $set = array(); $n = 0;
    foreach (array('title', 'meta_description', 'excerpt', 'body_md') as $f) {
        $v = $fix((string) ($a[$f] ?? ''), $n);
        if ($v !== (string) ($a[$f] ?? '')) { $set[$f] = $v; }
    }
    $fq = json_decode((string) ($a['faq'] ?? ''), true);
    if (is_array($fq)) {
        $fq_new = $fq;
        foreach ($fq_new as $i => $q) { if (is_array($q)) { foreach (array('q', 'a') as $k) { if (isset($q[$k])) { $fq_new[$i][$k] = $fix((string) $q[$k], $n); } } } }
        if ($fq_new !== $fq) { $set['faq'] = json_encode($fq_new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    }
    if (isset($set['body_md'])) { $set['body_html'] = SeoDrafter::render_body($set['body_md']); }
    $now = array_merge($a, $set);
    if (preg_match_all('/[^.\n]{0,50}\bnine\b[^.\n]{0,20}\bnetworks?\b[^.\n]{0,30}/i', $now['title'] . "\n" . $now['meta_description'] . "\n" . $now['excerpt'] . "\n" . $now['body_md'] . "\n" . (string) ($now['faq'] ?? ''), $mm)) {
        foreach ($mm[0] as $x) { echo "LEFT: /blog/$slug: " . trim($x) . "\n"; $left++; }
    }
    if (empty($set)) { continue; }
    $total += $n;
    echo date('c'), " /blog/$slug: $n change(s) in ", implode(', ', array_keys($set)), "\n";
    if (!$apply) { continue; }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    if (@file_put_contents($dir . '/' . (int) $a['id'] . '-textfix10-' . gmdate('His') . '.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) { echo "  backup failed, skipped\n"; continue; }
    $articles->update_fields((int) $a['id'], $set);
    try { IndexNow::ping(array('/blog/' . $slug), true); } catch (\Throwable $e) {}
    echo "  -> saved\n";
}
echo "\nchanges: $total, \"nine ... networks\" left: $left\n";
echo $apply ? "applied\n" : "dry run only\n";
