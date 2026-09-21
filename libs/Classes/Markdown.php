<?php
/**
 * Small allow-list Markdown renderer for model-written articles. Everything is escaped first; only the
 * constructs below are turned into tags. Links survive only when relative and (if a list is given) allowed.
 */
class Markdown {
    public static function render($md, array $allowed_paths = array()){
        $lines = preg_split("/\r\n|\r|\n/", (string) $md);
        $out = array(); $i = 0; $n = count($lines);
        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') { $i++; continue; }
            // heading
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $level = strlen($m[1]) <= 2 ? 2 : 3;
                $out[] = "<h$level>" . self::inline($m[2], $allowed_paths) . "</h$level>"; $i++; continue;
            }
            // table: header row + separator row
            if (strpos($line, '|') !== false && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{3,}/', $lines[$i + 1])) {
                $head = self::cells($line); $i += 2; $rows = array();
                while ($i < $n && strpos($lines[$i], '|') !== false && trim($lines[$i]) !== '') { $rows[] = self::cells($lines[$i]); $i++; }
                $h = '<table><thead><tr>';
                foreach ($head as $c) { $h .= '<th>' . self::inline($c, $allowed_paths) . '</th>'; }
                $h .= '</tr></thead><tbody>';
                foreach ($rows as $r) { $r = array_pad(array_slice($r, 0, count($head)), count($head), ''); $h .= '<tr>'; foreach ($r as $c) { $h .= '<td>' . self::inline($c, $allowed_paths) . '</td>'; } $h .= '</tr>'; }
                $out[] = $h . '</tbody></table>'; continue;
            }
            // unordered list
            if (preg_match('/^\s*[-*]\s+/', $line)) {
                $items = array();
                while ($i < $n && preg_match('/^\s*[-*]\s+(.*)$/', $lines[$i], $m)) { $items[] = '<li>' . self::inline($m[1], $allowed_paths) . '</li>'; $i++; }
                $out[] = '<ul>' . implode('', $items) . '</ul>'; continue;
            }
            // ordered list
            if (preg_match('/^\s*\d+[.)]\s+/', $line)) {
                $items = array();
                while ($i < $n && preg_match('/^\s*\d+[.)]\s+(.*)$/', $lines[$i], $m)) { $items[] = '<li>' . self::inline($m[1], $allowed_paths) . '</li>'; $i++; }
                $out[] = '<ol>' . implode('', $items) . '</ol>'; continue;
            }
            // blockquote
            if (preg_match('/^\s*>\s?/', $line)) {
                $buf = array();
                while ($i < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $m)) { $buf[] = $m[1]; $i++; }
                $out[] = '<blockquote><p>' . self::inline(implode(' ', $buf), $allowed_paths) . '</p></blockquote>'; continue;
            }
            // paragraph: consecutive non-blank, non-block lines
            $buf = array();
            while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^(#{1,6}\s|\s*[-*]\s+|\s*\d+[.)]\s+|\s*>)/', $lines[$i])
                   && !(strpos($lines[$i], '|') !== false && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{3,}/', $lines[$i + 1]))) {
                $buf[] = trim($lines[$i]); $i++;
            }
            if (!empty($buf)) { $out[] = '<p>' . self::inline(implode(' ', $buf), $allowed_paths) . '</p>'; }
            else { $i++; }
        }
        return implode("\n", $out);
    }

    private static function cells($line){
        $line = trim($line); $line = preg_replace('/^\|/', '', $line); $line = preg_replace('/\|$/', '', $line);
        return array_map('trim', explode('|', $line));
    }

    /** Escape, then apply inline marks. Code spans are protected first so nothing inside them is re-marked.
     *  Links: relative (single leading slash, no backslashes, no scheme/host) + allowed → <a>; otherwise the text alone. */
    private static function inline($text, array $allowed_paths){
        $e = htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        $codes = array();
        $e = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) { $codes[] = '<code>' . $m[1] . '</code>'; return "\x00" . (count($codes) - 1) . "\x00"; }, $e);
        $e = preg_replace_callback('/\[([^\]]+)\]\(((?:[^()\s]|\([^()\s]*\))+)\)/', function ($m) use ($allowed_paths) {
            $target = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            $path = preg_replace('/[#?].*$/', '', $target);
            $parts = @parse_url($target);
            $ok = preg_match('#^/(?![/\\\\])[^\\\\]*$#', $target) === 1
                && is_array($parts) && empty($parts['scheme']) && empty($parts['host'])
                && (empty($allowed_paths) || in_array($path, $allowed_paths, true));
            return $ok ? '<a href="' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">' . $m[1] . '</a>' : $m[1];
        }, $e);
        $e = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $e);
        $e = preg_replace('/(?<![*\w])\*([^*\s][^*]*?)\*(?![*\w])/', '<em>$1</em>', $e);
        $e = preg_replace_callback("/\x00(\d+)\x00/", function ($m) use ($codes) { return $codes[(int) $m[1]] ?? ''; }, $e);
        return $e;
    }

    public static function links($md){
        preg_match_all('/\]\(([^)\s]+)\)/', (string) $md, $m);
        return array_values($m[1]);
    }

    public static function word_count($md){
        $t = preg_replace('/\]\([^)]*\)/', ']', (string) $md);          // drop link targets
        $t = preg_replace('/[#*`>|\-]+/', ' ', $t);                        // drop marks
        $t = preg_replace('/[\[\]]/', '', $t);
        return count(preg_split('/\s+/', trim($t), -1, PREG_SPLIT_NO_EMPTY));
    }

    public static function headings($md, $level){
        return preg_match_all('/^#{' . (int) $level . '}\s+\S/m', (string) $md);
    }
}
