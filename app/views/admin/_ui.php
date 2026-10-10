<?php
/* Small view helpers shared by the admin pages (included by _shell.php). Avatars, status pills, sparklines, deltas. */
if (!function_exists('adm_avatar')) {
    /** 28px avatar: the photo, or the initial on a tinted circle. */
    function adm_avatar($name, $url = '', $size = 28): string {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $name = trim((string) $name); $init = $name === '' ? '?' : mb_strtoupper(mb_substr(ltrim($name, '@'), 0, 1));
        $tint = (crc32($name) % 6);
        if ((string) $url !== '') { return '<span class="adm-av adm-av--img" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px;background-image:url(\'' . $h($url) . '\')"></span>'; }
        return '<span class="adm-av adm-av--t' . $tint . '" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px">' . $h($init) . '</span>';
    }
    /** Name + handle stacked next to the avatar; $href wraps the name. */
    function adm_who($name, $handle, $href = '', $avatar = '', $sub = ''): string {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $name = trim((string) $name) !== '' ? (string) $name : '@' . $handle;
        $n = $href !== '' ? '<a href="' . $h($href) . '">' . $h($name) . '</a>' : $h($name);
        $s = $sub !== '' ? $sub : ($handle !== '' && '@' . $handle !== $name ? '@' . $handle : '');
        return '<span class="adm-who">' . adm_avatar($name, $avatar) . '<span class="adm-who__t"><span class="adm-who__n">' . $n . '</span>' . ($s !== '' ? '<span class="adm-who__s">' . $h($s) . '</span>' : '') . '</span></span>';
    }
    /** One status pill for the whole admin: green / amber / red / gray by meaning. */
    function adm_pill($text, $tone = ''): string {
        $h = htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        if ($tone === '') {
            $k = strtolower((string) $text);
            $tone = in_array($k, array('active', 'verified', 'approved', 'paid', 'ok', 'live', 'published', 'replied', 'succeeded', 'on'), true) ? 'ok'
                  : (in_array($k, array('pending', 'claimed', 'requested', 'earned', 'queued', 'drafting', 'waiting on us', 'overdue', 'stale', 'canceling', 'draft', 'review', 'unscanned', 'demo'), true) ? 'warn'
                  : (in_array($k, array('failed', 'past due', 'suspended', 'rejected', 'reversed', 'deleted', 'flagged', 'blocked', 'refused'), true) ? 'bad' : 'gray'));
        }
        return '<span class="adm-pill adm-pill--' . $tone . '">' . $h . '</span>';
    }
    /** Inline sparkline (SVG) for a list of numbers; flat lines stay visible. */
    function adm_spark(array $vals, $w = 120, $h = 28): string {
        $n = count($vals); if ($n < 2) { return ''; }
        $max = max($vals); $min = min($vals); $span = $max - $min; if ($span <= 0) { $span = 1; $min = $min - 0.5; }
        $pts = array();
        foreach (array_values($vals) as $i => $v) { $x = $i * ($w - 2) / ($n - 1) + 1; $y = $h - 2 - (($v - $min) / $span) * ($h - 4); $pts[] = round($x, 1) . ',' . round($y, 1); }
        $line = implode(' ', $pts);
        $area = '1,' . $h . ' ' . $line . ' ' . ($w - 1) . ',' . $h;
        return '<svg class="adm-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true"><polygon points="' . $area . '"/><polyline points="' . $line . '"/></svg>';
    }
    /** "▲ 12%" in green or red (or "—" when there is nothing to compare). */
    function adm_delta($now, $before, $inverse = false): string {
        $now = (float) $now; $before = (float) $before;
        if ($before == 0.0 && $now == 0.0) { return '<span class="adm-delta adm-delta--flat">No change</span>'; }
        if ($before == 0.0) { return '<span class="adm-delta adm-delta--' . ($inverse ? 'down' : 'up') . '">&#9650; New</span>'; }
        $pct = round(($now - $before) / abs($before) * 100);
        $up = $pct > 0; $good = $inverse ? !$up : $up;
        if ($pct == 0) { return '<span class="adm-delta adm-delta--flat">No change</span>'; }
        return '<span class="adm-delta adm-delta--' . ($good ? 'up' : 'down') . '">' . ($up ? '&#9650;' : '&#9660;') . ' ' . abs($pct) . '%</span>';
    }
    /** A KPI card: label, value, change, sparkline. */
    function adm_kpi($label, $value, $delta_html, array $spark, $sub = ''): string {
        $h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        return '<div class="adm-kpi"><span class="adm-kpi__l">' . $h($label) . '</span><span class="adm-kpi__v">' . $h($value) . '</span>'
             . '<span class="adm-kpi__d">' . $delta_html . ($sub !== '' ? ' <span class="adm-kpi__s">' . $h($sub) . '</span>' : '') . '</span>'
             . '<span class="adm-kpi__spark">' . adm_spark($spark) . '</span></div>';
    }
    /** Centered empty state inside a panel. */
    function adm_empty($text, $icon = 'fa-inbox'): string {
        return '<div class="adm-empty"><i class="fa-regular ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i><p>' . htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8') . '</p></div>';
    }
}
