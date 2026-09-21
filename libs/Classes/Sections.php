<?php
/**
 * The marketing-site section system. Every public page (home, features, pricing, compare, guides) is
 * built from these blocks so the site has one rhythm: full-width sections that alternate white and
 * light gray, a 1200px content column, black and white with color only from imagery, 4px corners.
 * Styles: public/css/sx.css. Each method returns HTML; text arguments are escaped here unless the
 * parameter says it takes HTML (only ever passed from our own views, never user input).
 */
class Sections {

    public static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

    /** Lucide icons (24px grid, 1.5 stroke). */
    public static function icon(string $name, int $size = 22): string {
        $p = array(
            'page'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>',
            'layers'   => '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
            'lock'     => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'send'     => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4z"/>',
            'message'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'bank'     => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
            'package'  => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'ticket'   => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/>',
            'star'     => '<path d="M12 2 15.1 8.3 22 9.3l-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
            'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'sparkles' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8"/>',
            'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'chart'    => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
            'check'    => '<path d="M20 6 9 17l-5-5"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'repeat' => '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
            'megaphone' => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
            'tag' => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1"/>',
            'wallet' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
            'bot' => '<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2M20 14h2M15 13v2M9 13v2"/>',
            'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'plug' => '<path d="M12 22v-5"/><path d="M9 8V2M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
            'badge' => '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/>',
            'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
            'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
            'image' => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
            'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
            'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'palette' => '<circle cx="13.5" cy="6.5" r="1"/><circle cx="17.5" cy="10.5" r="1"/><circle cx="8.5" cy="7.5" r="1"/><circle cx="6.5" cy="12.5" r="1"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
            'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
            'ban' => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
            'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            'eye' => '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>',
            'arrows'   => '<path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/>',
        );
        return '<svg class="sx-ic" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? $p['check']) . '</svg>';
    }

    /** Buttons: array(label, href, 'primary'|'secondary', auth?) — auth = 'login'|'register' opens the sign-in dialog. */
    public static function buttons(array $btns, string $class = 'sx-acts'): string {
        if (empty($btns)) { return ''; }
        $h = '<div class="' . $class . '">';
        foreach ($btns as $b) {
            $auth = !empty($b[3]) ? ' data-auth="' . self::e($b[3]) . '"' : '';
            $h .= '<a class="sx-btn sx-btn--' . self::e($b[2] ?? 'secondary') . '" href="' . self::e($b[1]) . '"' . $auth . '>' . self::e($b[0]) . '</a>';
        }
        return $h . '</div>';
    }

    /**
     * Page hero. $h: title, lead, buttons (see buttons()), image (url, optional),
     * size ('page' default | 'home' for the larger home-page hero), media_html (trusted HTML from our own views, shown where the image goes), after_html (trusted HTML under the hero grid), bg_image (url shown blurred and darkened behind the hero).
     */
    public static function hero(array $h): string {
        // One hero template site-wide: a page photo is shown blurred behind the text, never as a side image.
        if (empty($h['bg_image']) && !empty($h['image'])) { $h['bg_image'] = $h['image']; }
        $img = '';
        $home = ($h['size'] ?? '') === 'home';
        $bg = trim((string) ($h['bg_image'] ?? ''));
        $o = '<section class="sx sx--hero' . ($home ? ' sx--home' : '') . ($img === '' && empty($h['media_html']) ? ' sx--noimg' : '') . ($bg !== '' ? ' sx--bgimg' : '') . '"' . ($bg !== '' ? ' style="--hero-bg:url(\'' . self::e($bg) . '\')"' : '') . '><div class="ld-wrap sx__in sx-hero">';
        $o .= '<div class="sx-hero__text">';
        $o .= '<h1 class="sx-hero__title">' . self::e($h['title']) . '</h1>';
        if (!empty($h['lead'])) { $o .= '<p class="sx-hero__lead">' . self::e($h['lead']) . '</p>'; }
        $o .= self::buttons((array) ($h['buttons'] ?? array())) . '</div>';
        if ($img !== '') { $o .= '<div class="sx-hero__media"><img src="' . self::e($img) . '" alt="" width="1024" height="768" fetchpriority="high"></div>'; }
        elseif (!empty($h['media_html'])) { $o .= '<div class="sx-hero__media sx-hero__media--live">' . $h['media_html'] . '</div>'; }
        if (!empty($h['after_html'])) { $o .= '</div><div class="ld-wrap sx__in">' . $h['after_html']; }
        return $o . '</div></section>';
    }

    /**
     * Feature group: heading and lead on top, items in an even three- or four-column grid below.
     * $g: id, title, lead, items => array(array(icon, title, text)). $bg: 'white'|'alt'.
     */
    public static function group(array $g, string $bg = 'white'): string {
        // With a photo: heading + photo on the left, items on the right. Without one: heading on top, items full width, so no column is left empty.
        $wide = true; // every group uses the same layout: heading on top, even grid below (photos inside groups made pages look chaotic)
        $cols = (count($g['items']) % 4 === 0 && count($g['items']) <= 4) ? 4 : 3;
        $o = '<section class="sx sx--group' . ($bg === 'alt' ? ' sx--alt' : '') . '" id="' . self::e($g['id']) . '"><div class="ld-wrap sx__in sx-fg' . ($wide ? ' sx-fg--wide sx-fg--c' . $cols : '') . '">';
        $o .= '<div class="sx-fg__side"><h2 class="sx-h2">' . self::e($g['title']) . '</h2><p class="sx-lead">' . self::e($g['lead']) . '</p>';
        $o .= '</div><ul class="sx-fg__items">';
        foreach ($g['items'] as $it) {
            $o .= '<li class="sx-fg__item"><span class="sx-fg__ic">' . self::icon($it[0], 20) . '</span><div><h3 class="sx-fg__t">' . self::e($it[1]) . '</h3><p class="sx-fg__x">' . self::e($it[2]) . '</p></div></li>';
        }
        return $o . '</ul></div></section>';
    }

    /** Index list: big display-type names in full-width rows with the description beside them. Each item: icon, title, text. */
    public static function index_list(array $items): string {
        $o = '<ul class="sx-ix">';
        foreach ($items as $it) {
            $o .= '<li class="sx-ix__row"><h3 class="sx-ix__t">' . self::e($it['title']) . '</h3><p class="sx-ix__x">' . self::e($it['text']) . '</p><span class="sx-ix__ic">' . self::icon($it['icon'], 28) . '</span></li>';
        }
        return $o . '</ul>';
    }

    /* ---------------------------------------------------------------------------------------------
     * Tab hero: the home-page pattern used on every page. Big words on the left are tabs; each one
     * switches a live panel on the right (landing.js rotates them until the visitor clicks).
     * $h: id, tabs => array(array('word' => 'Sell.', 'pane' => html)), start (index), title (optional
     * real H1; without it the words are the H1), tag, lead, buttons. Pane HTML comes from the pane_*
     * helpers below (trusted, built from our own views).
     * ------------------------------------------------------------------------------------------- */
    public static function tab_hero(array $h): string {
        $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($h['id'] ?? 'hx')));
        $start = (int) ($h['start'] ?? 0);
        $words = ''; $panes = '';
        foreach ($h['tabs'] as $i => $t) {
            $k = $id . '_' . $i; $on = ($i === $start);
            $words .= '<button type="button" class="hx__w' . ($on ? ' is-on' : '') . '" role="tab" id="' . $k . '_t" aria-selected="' . ($on ? 'true' : 'false') . '" aria-controls="' . $k . '_p"' . ($on ? '' : ' tabindex="-1"') . ' data-hx="' . $i . '">' . self::e($t['word']) . '<span class="hx__bar" aria-hidden="true"><i></i></span></button>';
            $panes .= '<div class="hx__pane" role="tabpanel" id="' . $k . '_p" aria-labelledby="' . $k . '_t" data-hx="' . $i . '"' . ($on ? '' : ' hidden') . '>' . $t['pane'] . '</div>';
        }
        $title = trim((string) ($h['title'] ?? ''));
        $o = '<section class="sx hx"><div class="hx__in"><div class="hx__left">';
        $o .= $title === '' ? '<h1 class="hx__words" role="tablist">' . $words . '</h1>' : '<div class="hx__words" role="tablist" aria-label="' . self::e($title) . '">' . $words . '</div>';
        $o .= '<div class="hx__intro">';
        if ($title !== '') { $o .= '<h1 class="hx__title">' . self::e($title) . '</h1>'; }
        if (!empty($h['tag'])) { $o .= '<span class="hx__tag">' . self::e($h['tag']) . '</span>'; }
        if (!empty($h['lead'])) { $o .= '<p class="hx__lead">' . self::e($h['lead']) . '</p>'; }
        $o .= self::buttons((array) ($h['buttons'] ?? array()));
        return $o . '</div></div><div class="hx__mod">' . $panes . '</div></div></section>';
    }

    /**
     * Panel hero: the home-page layout without tabs. Headline, lead and buttons on the left; one live panel on the right.
     * $h: title, below (trusted HTML right under the title, e.g. a search form), lead, buttons, panel (optional trusted HTML built with the pane_* helpers), bg_image (url shown blurred behind), after_html (trusted HTML under the hero, e.g. jump links).
     */
    public static function panel_hero(array $h): string {
        $panel = (string) ($h['panel'] ?? '');
        $bg = trim((string) ($h['bg_image'] ?? ''));
        $o = '<section class="sx hx hx--panel' . ($panel === '' ? ' hx--solo' : '') . ($bg !== '' ? ' sx--bgimg' : '') . '"' . ($bg !== '' ? ' style="--hero-bg:url(\'' . self::e($bg) . '\')"' : '') . '><div class="hx__in"><div class="hx__left">';
        $o .= '<h1 class="hx__big">' . self::e($h['title']) . '</h1>';
        if (!empty($h['below'])) { $o .= '<div class="hx__below">' . $h['below'] . '</div>'; }
        if (!empty($h['lead'])) { $o .= '<p class="hx__lead">' . self::e($h['lead']) . '</p>'; }
        $o .= self::buttons((array) ($h['buttons'] ?? array()));
        $o .= '</div>' . ($panel !== '' ? '<div class="hx__mod">' . $panel . '</div>' : '') . '</div>';
        if (!empty($h['after_html'])) { $o .= '<div class="ld-wrap sx__in hx__after">' . $h['after_html'] . '</div>'; }
        return $o . '</section>';
    }

    /** Panel heading + optional text + body. */
    public static function pane(string $title, string $text, string $body): string {
        return '<h2 class="hx__h' . ($text === '' ? ' hx__h--solo' : '') . '">' . self::e($title) . '</h2>' . ($text !== '' ? '<p class="hx__p">' . self::e($text) . '</p>' : '') . $body;
    }

    /** Selectable options (one at a time). $items: array(title, text). */
    public static function pane_options(array $items, int $sel = 0, string $label = 'Options'): string {
        $o = '<div class="hx__acc" role="radiogroup" aria-label="' . self::e($label) . '">';
        foreach ($items as $i => $it) { $on = ($i === $sel); $o .= '<button type="button" class="hx__opt' . ($on ? ' is-on' : '') . '" role="radio" aria-checked="' . ($on ? 'true' : 'false') . '"><i aria-hidden="true"></i><span><b>' . self::e($it[0]) . '</b><small>' . self::e($it[1]) . '</small></span></button>'; }
        return $o . '</div>';
    }

    /** A sequence of steps; click a step to move the marker. $items: array(title, text). */
    public static function pane_timeline(array $items, int $stage = 1): string {
        $o = '<div class="hx__box hx__tl" data-stage="' . $stage . '"><span class="hx__fill" aria-hidden="true"></span>';
        foreach ($items as $it) { $o .= '<button type="button" class="hx__nd"><b>' . self::e($it[0]) . '</b><small>' . self::e($it[1]) . '</small></button>'; }
        return $o . '</div>';
    }

    /** Tracked links with click counters. $items: array(label, clicks). */
    public static function pane_links(string $handle, array $items): string {
        $total = 0; foreach ($items as $it) { $total += (int) $it[1]; }
        $o = '<div class="hx__box"><div class="hx__handle"><i aria-hidden="true"></i>' . self::e($handle) . '</div>';
        foreach ($items as $it) { $o .= '<button type="button" class="hx__lk" data-n="' . (int) $it[1] . '">' . self::e($it[0]) . '<b>' . number_format((int) $it[1]) . '</b></button>'; }
        return $o . '<div class="hx__tot"><span>Clicks this month</span><b data-hx-total="' . $total . '">+' . number_format($total) . ' clicks</b></div></div>';
    }

    /** Toggle chips with a live count. $items: labels; $on: indexes switched on. */
    public static function pane_chips(array $items, array $on, string $noun = 'networks'): string {
        $o = '<div class="hx__box"><div class="hx__chips">';
        foreach ($items as $i => $label) { $p = in_array($i, $on, true); $o .= '<button type="button" class="hx__chip' . ($p ? ' is-on' : '') . '" aria-pressed="' . ($p ? 'true' : 'false') . '">' . self::e($label) . '</button>'; }
        return $o . '</div><div class="hx__tot"><span>Publishing to</span><b data-hx-count data-noun="' . self::e($noun) . '">' . count($on) . ' ' . self::e($noun) . '</b></div></div>';
    }

    /** On/off switches. $items: array(title, text, on). */
    public static function pane_switches(array $items): string {
        $o = '<div class="hx__acc">';
        foreach ($items as $it) { $on = !empty($it[2]); $o .= '<button type="button" class="hx__sw' . ($on ? ' is-on' : '') . '" role="switch" aria-checked="' . ($on ? 'true' : 'false') . '"><span><b>' . self::e($it[0]) . '</b><small>' . self::e($it[1]) . '</small></span><i aria-hidden="true"></i></button>'; }
        return $o . '</div>';
    }

    /** Label/value rows. $rows: array(label, value, highlight?). $foot: trusted HTML under the rows. */
    public static function pane_rows(array $rows, string $foot = ''): string {
        $o = '<div class="hx__box hx__kv">';
        foreach ($rows as $r) { $o .= '<div class="hx__kvr' . (!empty($r[2]) ? ' is-hi' : '') . '"><span>' . self::e($r[0]) . '</span><b>' . self::e($r[1]) . '</b></div>'; }
        return $o . '</div>' . $foot;
    }

    /** Link rows. $items: array(label, href, meta). */
    public static function pane_list(array $items, string $empty = ''): string {
        if (empty($items)) { return '<p class="hx__p">' . self::e($empty) . '</p>'; }
        $o = '<div class="hx__box hx__list">';
        foreach ($items as $it) { $o .= '<a class="hx__li" href="' . self::e($it[1]) . '"><b>' . self::e($it[0]) . '</b>' . (!empty($it[2]) ? '<small>' . self::e($it[2]) . '</small>' : '') . '</a>'; }
        return $o . '</div>';
    }

    /** Us versus them on one question. Values are plain text; $src is a source URL for theirs. */
    public static function pane_versus(string $us_name, string $us, string $them_name, string $them, string $src = ''): string {
        return '<div class="hx__box hx__vs"><div class="hx__vsr is-us"><span>' . self::e($us_name) . '</span><b>' . self::e($us) . '</b></div>'
            . '<div class="hx__vsr"><span>' . self::e($them_name) . '</span><b>' . self::e($them) . '</b>' . ($src !== '' ? '<a class="sx-src" href="' . self::e($src) . '" rel="nofollow noopener" target="_blank">Source</a>' : '') . '</div></div>';
    }

    /** Open a full-width section. $bg: 'white'|'alt'. Optional heading block. */
    public static function open(string $bg = 'white', string $title = '', string $lead = '', string $id = ''): string {
        $o = '<section class="sx' . ($bg === 'alt' ? ' sx--alt' : '') . '"' . ($id !== '' ? ' id="' . self::e($id) . '"' : '') . '><div class="ld-wrap sx__in">';
        if ($title !== '') {
            $o .= '<div class="sx-head"><h2 class="sx-h2">' . self::e($title) . '</h2>' . ($lead !== '' ? '<p class="sx-lead">' . self::e($lead) . '</p>' : '') . '</div>';
        }
        return $o;
    }

    public static function close(): string { return '</div></section>'; }

    public static function checks(array $items, int $cols = 1, bool $html = false): string {
        $o = '<ul class="sx-checks' . ($cols > 1 ? ' sx-checks--' . $cols : '') . '">';
        foreach ($items as $i) { $o .= '<li>' . self::icon('check', 16) . '<span>' . ($html ? $i : self::e($i)) . '</span></li>'; }
        return $o . '</ul>';
    }

    /** Alternating image/text rows. Each row: title, text, points[] (optional), image (url, optional), link (array(label, href), optional). */
    public static function rows(array $rows): string {
        $o = '<div class="sx-rows">'; $n = 0;
        foreach ($rows as $r) {
            $img = trim((string) ($r['image'] ?? '')); $flip = ($n++ % 2 === 1);
            $o .= '<article class="sx-row' . ($flip ? ' sx-row--flip' : '') . ($img === '' ? ' sx-row--noimg' : '') . '">';
            if ($img !== '') { $o .= '<div class="sx-row__media"><img src="' . self::e($img) . '" alt="" loading="lazy" width="1024" height="768"></div>'; }
            $o .= '<div class="sx-row__text"><h3 class="sx-h3">' . self::e($r['title']) . '</h3><p class="sx-p">' . self::e($r['text']) . '</p>';
            if (!empty($r['points'])) { $o .= self::checks((array) $r['points']); }
            if (!empty($r['link'])) { $o .= '<a class="sx-more" href="' . self::e($r['link'][1]) . '">' . self::e($r['link'][0]) . '</a>'; }
            $o .= '</div></article>';
        }
        return $o . '</div>';
    }

    /** Card grid. Each card: icon (name, optional), title, text, points[] (optional), note (optional, small line at the bottom). */
    public static function cards(array $cards, int $cols = 3): string {
        $o = '<div class="sx-cards sx-cards--' . $cols . '">';
        foreach ($cards as $c) {
            $o .= '<article class="sx-card">';
            if (!empty($c['icon'])) { $o .= '<span class="sx-card__icon">' . self::icon($c['icon']) . '</span>'; }
            $o .= '<h3 class="sx-card__title">' . self::e($c['title']) . '</h3>';
            if (!empty($c['text'])) { $o .= '<p class="sx-card__text">' . self::e($c['text']) . '</p>'; }
            if (!empty($c['points'])) { $o .= self::checks((array) $c['points']); }
            if (!empty($c['note'])) { $o .= '<p class="sx-card__note">' . self::e($c['note']) . '</p>'; }
            if (!empty($c['link'])) { $o .= '<a class="sx-more" href="' . self::e($c['link'][1]) . '">' . self::e($c['link'][0]) . '</a>'; }
            $o .= '</article>';
        }
        return $o . '</div>';
    }

    /**
     * Table. $head: column labels (text). $rows: arrays of cells; cells are HTML (callers escape their own text,
     * which lets compare tables carry source links). $highlight: 0-based column index to emphasise, or -1.
     */
    public static function table(array $head, array $rows, int $highlight = -1): string {
        $o = '<div class="sx-table-wrap"><table class="sx-table"><thead><tr>';
        foreach ($head as $i => $h) { $o .= '<th' . ($i === $highlight ? ' class="is-hi"' : '') . ' scope="col">' . self::e($h) . '</th>'; }
        $o .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $o .= '<tr>';
            foreach (array_values($r) as $i => $cell) { $o .= ($i === 0 ? '<th scope="row">' : '<td' . ($i === $highlight ? ' class="is-hi"' : '') . '>') . $cell . ($i === 0 ? '</th>' : '</td>'); }
            $o .= '</tr>';
        }
        return $o . '</tbody></table></div>';
    }

    /** FAQ accordion (questions and answers are plain text). */
    public static function faq(array $faq, string $bg = 'alt'): string {
        $o = self::open($bg) . '<div class="sx-faq"><h2 class="sx-h2" id="sx_faq">Frequently asked questions</h2><div class="sx-faq__list">';
        foreach ($faq as $qa) {
            $o .= '<details class="sx-faq__item"><summary>' . self::e($qa['q']) . '<svg class="sx-faq__chev" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary><div class="sx-faq__a">' . self::e($qa['a']) . '</div></details>';
        }
        return $o . '</div></div>' . self::close();
    }

    /** Closing call to action: full-width black band. */
    public static function cta(string $title, string $text = '', bool $pricing_link = true): string {
        return '<section class="sx sx--cta"><div class="ld-wrap sx__in sx-cta"><div><h2 class="sx-cta__title">' . self::e($title) . '</h2>'
            . ($text !== '' ? '<p class="sx-cta__text">' . self::e($text) . '</p>' : '') . '</div>'
            . '<div class="sx-acts"><a class="sx-btn sx-btn--light" href="/?auth=register" data-auth="register">Get Started</a>'
            . ($pricing_link ? '<a class="sx-btn sx-btn--ghost" href="/pricing">See Pricing</a>' : '') . '</div></div></section>';
    }
}
