<?php
/* Shared by every admin view (included from _shell.php): escaping, the viewer's local time, initials, money. */
$e   = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
$ini = function ($n) { $n = trim((string) $n); return $n === '' ? '?' : mb_strtoupper(mb_substr($n, 0, 1)); };
$tz  = (string) ($this->timezone ?? 'UTC'); if ($tz === '') { $tz = 'UTC'; }
$fmt = function ($utc, $withTime = false) use ($tz) {
    if ((string) $utc === '') { return '—'; }
    try {
        $d = new DateTime((string) $utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone($tz));
        return $d->format($withTime ? 'M j, Y g:i A' : 'M j, Y');
    } catch (\Throwable $ex) { return '—'; }
};
$ago = function ($utc) {
    if ((string) $utc === '') { return ''; }
    $s = time() - strtotime((string) $utc . ' UTC');
    if ($s < 60) { return 'just now'; }
    if ($s < 3600) { return floor($s / 60) . ' min ago'; }
    if ($s < 86400) { return floor($s / 3600) . ' h ago'; }
    if ($s < 86400 * 14) { return floor($s / 86400) . ' d ago'; }
    return gmdate('M j', strtotime((string) $utc . ' UTC'));
};
$usd = function ($c) { return ((int) $c < 0 ? '−$' : '$') . number_format(abs((int) $c) / 100, 2); };
$nav = (array) ($this->nav ?? array());
$badge = function ($n) { return (int) $n > 0 ? '<b class="adm-rail__n">' . (int) $n . '</b>' : ''; };
