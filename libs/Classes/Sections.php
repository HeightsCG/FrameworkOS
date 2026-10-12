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

    /**
     * Headline Title Case (Daniel's rule): capitalise each word's first letter, keep short joining words lower
     * (except first/last), and never lower anything, so "AI", "OnlyFans", "Ko-fi" and "$29/mo" stay as written.
     */
    /** Hyphenated names tc() leaves exactly as written. */
    const TC_KEEP = array('Ko-fi');

    public static function tc(string $s): string {
        $small = array('a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'nor', 'of', 'on', 'or', 'per', 'the', 'to', 'vs', 'via', 'with');
        $w = preg_split('/(\s+)/u', trim($s), -1, PREG_SPLIT_DELIM_CAPTURE);
        $last = count($w) - 1;
        foreach ($w as $i => $word) {
            if ($word === '' || preg_match('/^\s+$/u', $word)) { continue; }
            $bare = strtolower(trim($word, '.,:;!?()"\''));
            if ($i !== 0 && $i !== $last && in_array($bare, $small, true)) { continue; }
            $w[$i] = preg_replace_callback('/^([\'"(\[]*)(\p{Ll})/u', function ($m) { return $m[1] . mb_strtoupper($m[2]); }, $word);
            // hyphenated words take a capital after each hyphen too ("Pay-Per-View", "Built-In"); brand spellings are kept
            if (strpos($word, '-') !== false && !in_array(trim($word, '.,:;!?()"\''), self::TC_KEEP, true)) {
                $w[$i] = preg_replace_callback('/-(\p{Ll})/u', function ($m) { return '-' . mb_strtoupper($m[1]); }, $w[$i]);
            }
        }
        return implode('', $w);
    }

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
        $o .= '<h1 class="sx-hero__title">' . self::e(self::tc($h['title'])) . '</h1>';
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
        $o .= '<div class="sx-fg__side"><h2 class="sx-h2">' . self::e(self::tc($g['title'])) . '</h2><p class="sx-lead">' . self::e($g['lead']) . '</p>';
        $o .= '</div><ul class="sx-fg__items">';
        foreach ($g['items'] as $it) {
            $o .= '<li class="sx-fg__item"><span class="sx-fg__ic">' . self::icon($it[0], 20) . '</span><div><h3 class="sx-fg__t">' . self::e(self::tc($it[1])) . '</h3><p class="sx-fg__x">' . self::e($it[2]) . '</p></div></li>';
        }
        return $o . '</ul></div></section>';
    }

    /** Index list: big display-type names in full-width rows with the description beside them. Each item: icon, title, text. */
    public static function index_list(array $items): string {
        $o = '<ul class="sx-ix">';
        foreach ($items as $it) {
            $o .= '<li class="sx-ix__row"><h3 class="sx-ix__t">' . self::e(self::tc($it['title'])) . '</h3><p class="sx-ix__x">' . self::e($it['text']) . '</p><span class="sx-ix__ic">' . self::icon($it['icon'], 28) . '</span></li>';
        }
        return $o . '</ul>';
    }

    /* ---------------------------------------------------------------------------------------------
     * Tab hero: the home-page pattern used on every page. Big words on the left are tabs; each one
     * switches a live panel on the right (landing.js rotates them until the visitor clicks).
     * $h: id, tabs => array(array('word' => 'Sell.', 'pane' => html)), start (index), title (optional
     * real H1 shown first with the words as a compact tab row under it; without it the words are the H1), tag, lead, buttons, after_html (trusted HTML under the grid, titled hero only). Pane HTML comes from the pane_*
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
        if (!empty($h['eyebrow'])) { $o .= '<p class="hx__eyebrow">' . self::e($h['eyebrow']) . '</p>'; }   // the one line above the H1 (the fee wedge on the home page)
        if ($title !== '') { $o .= '<h1 class="hx__title">' . self::e($title) . '</h1>'; }   // the H1 as written, not title-cased: it is a sentence
        if (!empty($h['tag'])) { $o .= '<span class="hx__tag">' . self::e($h['tag']) . '</span>'; }
        if (!empty($h['lead'])) { $o .= self::paragraphs((string) $h['lead'], 'hx__lead'); }
        $o .= self::buttons((array) ($h['buttons'] ?? array()));
        if (!empty($h['under'])) { $o .= '<p class="hx__under-wrap">' . $h['under'] . '</p>'; }   // trusted HTML: one short line under the buttons
        return $o . '</div></div><div class="hx__mod">' . $panes . '</div></div></section>';
    }
    /** Plan strip under a hero: one link per plan. Each item: name, price (text), role, href. */
    public static function plan_strip(array $items, string $label = 'Plans'): string {
        $o = '<nav class="ld-wrap hx-plans" aria-label="' . self::e($label) . '">';
        foreach ($items as $it) {
            $o .= '<a class="hx-plans__i" href="' . self::e($it['href']) . '"><b>' . self::e($it['name']) . '</b><span>' . self::e($it['price']) . '</span><small>' . self::e($it['role']) . '</small></a>';
        }
        return $o . '</nav>';
    }

    /**
     * Panel hero: the home-page layout without tabs. Headline, lead and buttons on the left; one live panel on the right.
     * $h: title, below (trusted HTML right under the title, e.g. a search form), lead, buttons, panel (optional trusted HTML built with the pane_* helpers), bg_image (url shown blurred behind), after_html (trusted HTML under the hero, e.g. jump links).
     */
    public static function panel_hero(array $h): string {
        $panel = (string) ($h['panel'] ?? '');
        $bg = trim((string) ($h['bg_image'] ?? ''));
        $o = '<section class="sx hx hx--panel' . ($panel === '' ? ' hx--solo' : '') . ($bg !== '' ? ' sx--bgimg' : '') . '"' . ($bg !== '' ? ' style="--hero-bg:url(\'' . self::e($bg) . '\')"' : '') . '><div class="hx__in"><div class="hx__left">';
        $o .= '<h1 class="hx__big">' . self::e(self::tc($h['title'])) . '</h1>';
        if (!empty($h['below'])) { $o .= '<div class="hx__below">' . $h['below'] . '</div>'; }
        if (!empty($h['lead'])) { $o .= self::paragraphs((string) $h['lead'], 'hx__lead'); }
        $o .= self::buttons((array) ($h['buttons'] ?? array()));
        $o .= '</div>' . ($panel !== '' ? '<div class="hx__mod">' . $panel . '</div>' : '') . '</div>';
        if (!empty($h['after_html'])) { $o .= '<div class="ld-wrap sx__in hx__after">' . $h['after_html'] . '</div>'; }
        return $o . '</section>';
    }

    /** Panel heading + optional text + body. */
    public static function pane(string $title, string $text, string $body): string {
        return '<h2 class="hx__h' . ($text === '' ? ' hx__h--solo' : '') . '">' . self::e(self::tc($title)) . '</h2>' . ($text !== '' ? '<p class="hx__p">' . self::e($text) . '</p>' : '') . $body;
    }

    /** Selectable options (one at a time). $items: array(title, text). */
    public static function pane_options(array $items, int $sel = 0, string $label = 'Options'): string {
        $o = '<div class="hx__acc" role="radiogroup" aria-label="' . self::e($label) . '">';
        foreach ($items as $i => $it) { $on = ($i === $sel); $o .= '<button type="button" class="hx__opt' . ($on ? ' is-on' : '') . '" role="radio" aria-checked="' . ($on ? 'true' : 'false') . '"><i aria-hidden="true"></i><span><b>' . self::e($it[0]) . '</b><small>' . self::e($it[1]) . '</small></span></button>'; }
        return $o . '</div>';
    }

    /** A sequence of steps; click a step to move the marker. $items: array(title, text). */
    public static function pane_timeline(array $items, int $stage = 1): string {
        $o = '<ul class="hx__box hx__tl" data-stage="' . $stage . '"><span class="hx__fill" aria-hidden="true"></span>';   // a list: each step is its own block to an extractor (2026-10-12)
        foreach ($items as $it) { $o .= '<li class="hx__nd-item"><button type="button" class="hx__nd"><b>' . self::e($it[0]) . '</b><small>' . self::e($it[1]) . '</small></button></li>'; }
        return $o . '</ul>';
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
            . '<div class="hx__vsr"><span>' . self::e($them_name) . '</span><b>' . self::e($them) . '</b>' . ($src !== '' ? '<a class="sx-src" href="' . self::e($src) . '" rel="noopener" target="_blank">Source</a>' : '') . '</div></div>';
    }

    /** Chart and diagram files, generated on dev and shipped as static files (served as /images/charts/<name>-<hash>.svg). */
    const CHART_DIR = '/public/images/charts/';
    const CHART_URL = '/images/charts/';

    /**
     * The URL of a chart's SVG file. The name carries a hash of the markup, so a changed chart is a new file. Files are written
     * only on dev (APPLICATION_ENV=development), by rendering the pages once (cron/build_charts.php), and committed with the deploy;
     * production never writes. tests/seo_charts_test.php fails when a page references a file that is not in the repo.
     */
    public static function svg_asset(string $svg, string $name): string {
        $file = preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)) . '-' . substr(sha1($svg), 0, 10) . '.svg';
        if (getenv('APPLICATION_ENV') === 'development') {
            $dir = Main::app_path() . self::CHART_DIR; $path = $dir . $file;
            if (!is_file($path)) {
                if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                if (@file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $svg) === false) { error_log('[charts] cannot write ' . $path); }
            }
        }
        return self::CHART_URL . $file;
    }

    /** A chart figure: the SVG file as an <img> with alt text, in a <figure> with its caption. */
    private static function chart_figure(string $svg, string $name, string $alt, int $w, int $h, string $caption, string $legend = '', bool $caption_is_html = false): string {
        $img = '<img class="sx-chart-img" src="' . self::e(self::svg_asset($svg, $name)) . '" alt="' . self::e($alt) . '" width="' . $w . '" height="' . $h . '" loading="lazy" decoding="async">';
        return '<figure class="sx-figure"><div class="sx-chart-wrap">' . $img . '</div>' . $legend . '<figcaption class="sx-figcap">' . ($caption_is_html ? $caption : self::paragraphs($caption)) . '</figcaption></figure>';
    }

    /**
     * Inline SVG bar chart of what each option leaves you on each sales figure (g_multimodal): built from the same
     * $options rows as the fee table (label, percent, monthly, us). The figure carries the numbers in its caption and
     * in the SVG's accessible name, so the chart reads the same without pixels.
     */
    public static function fee_chart(array $options, array $sales, string $title = 'What you keep'): string {
        if (count($options) < 2 || empty($sales)) { return ''; }
        $money = function ($n) { return '$' . number_format((float) $n, 0); };
        $kept = array(); $max = 0;
        foreach ($sales as $s) { foreach ($options as $o) { $k = max(0, $s - $s * ((float) $o['percent']) / 100); $kept[$s][] = $k; $max = max($max, $k); } }   // the platform percentage alone, never a plan price (Daniel 2026-10-12)
        if ($max <= 0) { return ''; }
        // drawn at the page's scale on the site palette: 960 wide, the groups spread across the width, bars sized to the room
        $n = count($options); $groups = count($sales);
        $w = 960; $h = 400; $left = 88; $right = 24; $top = 32; $base = 332;
        $group_w = ($w - $left - $right) / $groups; $gap = 10; $bar_w = max(14, min(72, (int) floor(($group_w * 0.72 - ($n - 1) * $gap) / $n)));
        $font = 'font-family="Inter, Helvetica, Arial, sans-serif"';
        $ink = '#f4f4f5'; $muted = '#8e8e94'; $grid = '#2a2a2e'; $axis = '#3a3a40';
        $palette = array('#CD4C00', '#FF6A13', '#5c5c66', '#8e8e94', '#b8b8c0', '#d8d8dc');
        $colour = function ($oi, $o) use ($palette) { return !empty($o['us']) ? $palette[min((int) $oi, 1)] : $palette[2 + min((int) $oi, 3)]; };
        $alt = array();
        $svg = '<svg class="sx-chart" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-labelledby="sxc_t sxc_d" xmlns="http://www.w3.org/2000/svg" ' . $font . '>';
        $svg .= '<title id="sxc_t">' . self::e($title) . '</title><rect x="0" y="0" width="' . $w . '" height="' . $h . '" rx="12" fill="#161618"/>';
        foreach (array(0.25, 0.5, 0.75, 1) as $f) { $y = $base - ($base - $top) * $f; $svg .= '<line x1="' . $left . '" y1="' . round($y, 1) . '" x2="' . ($w - $right) . '" y2="' . round($y, 1) . '" stroke="' . $grid . '" stroke-width="1"/><text x="' . ($left - 12) . '" y="' . round($y + 4, 1) . '" text-anchor="end" font-size="12" fill="' . $muted . '">' . self::e($money(round($max * $f / 50) * 50)) . '</text>'; }
        $svg .= '<line x1="' . $left . '" y1="' . $base . '" x2="' . ($w - $right) . '" y2="' . $base . '" stroke="' . $axis . '" stroke-width="1"/>';
        foreach ($sales as $gi => $s) {
            $gx = $left + $gi * $group_w + ($group_w - ($n * $bar_w + ($n - 1) * $gap)) / 2; $parts = array();
            foreach ($options as $oi => $o) {
                $k = $kept[$s][$oi]; $bh = ($base - $top) * $k / $max; $x = $gx + $oi * ($bar_w + $gap); $y = $base - $bh;
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . $bar_w . '" height="' . round($bh, 1) . '" fill="' . $colour($oi, $o) . '" rx="4"><title>' . self::e($o['label'] . ': you keep ' . $money($k) . ' of ' . $money($s)) . '</title></rect>';
                $svg .= '<text x="' . round($x + $bar_w / 2, 1) . '" y="' . round($y - 8, 1) . '" text-anchor="middle" font-size="12" font-weight="600" fill="' . $ink . '">' . self::e($money($k)) . '</text>';
                $parts[] = $o['label'] . ' ' . $money($k);
            }
            $svg .= '<text x="' . round($left + $gi * $group_w + $group_w / 2, 1) . '" y="' . ($base + 26) . '" text-anchor="middle" font-size="13" fill="' . $ink . '">' . self::e('on ' . $money($s) . ' of sales') . '</text>';
            $alt[] = 'On ' . $money($s) . ' of sales you keep: ' . implode('; ', $parts);
        }
        $desc = implode('. ', $alt) . '.';
        $svg .= '<desc id="sxc_d">' . self::e($desc) . '</desc></svg>';
        $items = ''; foreach ($options as $oi => $o) { $cells = array(); foreach ($sales as $gi => $s) { $cells[] = $money($kept[$s][$oi]) . ' on ' . $money($s); } $items .= '<li>' . self::e($o['label'] . ': you keep ' . implode(', ', $cells) . '.') . '</li>'; }
        $caption_html = '<p>' . self::e($title . '.') . '</p><ul class="sx-figcap__list">' . $items . '</ul>';
        $legend = array(); foreach ($options as $oi => $o) { $legend[] = '<li class="sx-chart__key"><i style="background:' . $colour($oi, $o) . '"></i>' . self::e($o['label']) . '</li>'; }
        $alt_text = 'Bar chart, ' . lcfirst($title) . '. ' . $desc;
        $out = self::chart_figure($svg, 'fee-' . implode('-', array_map('intval', $sales)) . '-' . count($options), $alt_text, $w, $h, $caption_html, '<ul class="sx-chart__legend">' . implode('', $legend) . '</ul>', true);
        return str_replace('<figure class="sx-figure">', '<figure class="sx-figure sx-figure--chart">', $out);
    }

    /**
     * Schematic of a product screen (never a screenshot, so a UI change cannot date it; Daniel 2026-10-12): a window with the
     * app's left rail and the screen's own structure, with INVENTED round figures and made-up names, never anything from a real
     * account. $kind: 'plans' (Settings > Membership Plans), 'posts' (Content Studio posts list), 'analytics' (the Analytics dashboard).
     */
    public static function ui_sketch_svg(string $kind): string {
        $w = 960; $h = 600; $bg = '#161618'; $line = '#2a2a2e'; $bar = '#3a3a40'; $soft = '#2f2f34'; $acc = '#FF6A13'; $ink = '#8e8e94'; $white = '#f4f4f5';
        $font = 'font-family="Inter, Helvetica, Arial, sans-serif"';
        $txt = function ($x, $y, $t, $size = 13, $fill = '#f4f4f5', $weight = 500, $anchor = 'start') use ($font) { return '<text x="' . $x . '" y="' . $y . '" font-size="' . $size . '" font-weight="' . $weight . '" fill="' . $fill . '" text-anchor="' . $anchor . '" ' . $font . '>' . self::e($t) . '</text>'; };
        $o = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="sk_t"><title id="sk_t">' . self::e(self::ui_sketch_name($kind)) . '</title>';
        $o .= '<rect x="0" y="0" width="' . $w . '" height="' . $h . '" rx="12" fill="' . $bg . '"/>';
        $o .= '<rect x="0" y="0" width="' . $w . '" height="56" fill="#121214"/><rect x="208" y="16" width="420" height="24" rx="6" fill="' . $soft . '"/>' . $txt(222, 32, 'Search creators and content', 12, $ink, 400) . '<circle cx="912" cy="28" r="14" fill="' . $acc . '"/>' . $txt(912, 32, 'A', 12, '#fff', 700, 'middle');
        $o .= '<rect x="0" y="56" width="176" height="' . ($h - 56) . '" fill="#121214"/><circle cx="36" cy="92" r="12" fill="' . $acc . '"/>' . $txt(60, 96, 'Creator Link Studio', 12, $white, 600);
        $rail = array('Home', 'Inbox', 'Analytics', 'Content Studio', 'Ideas', 'Influencers', 'Audience', 'Events', 'Services', 'Promote');
        $rail_on = array('plans' => 10, 'posts' => 3, 'analytics' => 2, 'inbox' => 1)[$kind] ?? 2;
        foreach ($rail as $i => $label) { $y = 136 + $i * 34; $on = ($i === $rail_on); $o .= ($on ? '<rect x="12" y="' . ($y - 12) . '" width="152" height="30" rx="6" fill="#1f1f23"/>' : '') . '<rect x="28" y="' . ($y - 7) . '" width="12" height="12" rx="3" fill="' . ($on ? $acc : $bar) . '"/>' . $txt(50, $y + 4, $label, 12, $on ? $acc : $ink, $on ? 600 : 400); }
        $x0 = 208; $cw = $w - $x0 - 32;
        if ($kind === 'plans') {
            $o .= $txt($x0, 98, 'Membership Plans', 18, $white, 700) . $txt($x0, 122, 'Create tiers your fans can subscribe to. Toggle to show or hide a tier on your profile.', 12, $ink, 400);
            $o .= '<rect x="' . ($x0 + $cw - 120) . '" y="80" width="120" height="32" rx="6" fill="none" stroke="' . $ink . '"/>' . $txt($x0 + $cw - 60, 101, '+ Add Plan', 12, $white, 600, 'middle');
            foreach (array(array('Supporter', '$5', true), array('Insider', '$12', true), array('VIP', '$25', false)) as $i => $row) {
                $y = 148 + $i * 80; $on = $row[2];
                $o .= '<rect x="' . $x0 . '" y="' . $y . '" width="' . $cw . '" height="64" rx="8" fill="#1c1c20" stroke="' . $line . '"/>';
                $o .= '<rect x="' . ($x0 + 20) . '" y="' . ($y + 24) . '" width="8" height="16" rx="2" fill="' . $bar . '"/>' . $txt($x0 + 44, $y + 37, $row[0], 14, $white, 600) . $txt($x0 + 170, $y + 37, $row[1], 14, $acc, 700) . $txt($x0 + 170 + strlen($row[1]) * 9, $y + 37, '/mo', 11, $ink, 400);
                $o .= '<rect x="' . ($x0 + $cw - 150) . '" y="' . ($y + 20) . '" width="44" height="24" rx="12" fill="' . ($on ? $acc : $bar) . '"/><circle cx="' . ($x0 + $cw - 150 + ($on ? 32 : 12)) . '" cy="' . ($y + 32) . '" r="9" fill="#fff"/>';
                $o .= '<rect x="' . ($x0 + $cw - 84) . '" y="' . ($y + 22) . '" width="20" height="20" rx="4" fill="' . $bar . '"/><rect x="' . ($x0 + $cw - 52) . '" y="' . ($y + 22) . '" width="20" height="20" rx="4" fill="' . $bar . '"/>';
            }
            $o .= $txt($x0, 428, 'Discount Codes', 16, $white, 700) . '<rect x="' . ($x0 + $cw - 120) . '" y="408" width="120" height="32" rx="6" fill="none" stroke="' . $ink . '"/>' . $txt($x0 + $cw - 60, 429, '+ Add Code', 12, $white, 600, 'middle');
            $o .= '<rect x="' . $x0 . '" y="452" width="' . $cw . '" height="56" rx="8" fill="#1c1c20" stroke="' . $line . '"/>' . $txt($x0 + 20, 485, 'WELCOME20', 13, $white, 600) . $txt($x0 + 150, 485, '20% off, first month, 40 uses left', 12, $ink, 400);
            $o .= $txt($x0, 548, 'Content Bundles', 16, $white, 700) . '<rect x="' . ($x0 + $cw - 130) . '" y="528" width="130" height="32" rx="6" fill="none" stroke="' . $ink . '"/>' . $txt($x0 + $cw - 65, 549, '+ Add Bundle', 12, $white, 600, 'middle');
        } elseif ($kind === 'posts') {
            $o .= $txt($x0, 98, 'Content Studio', 18, $white, 700) . $txt($x0, 122, 'Upload once, use everywhere.', 12, $ink, 400);
            foreach (array('Posts', 'Library', 'Calendar', 'Collections', 'Scheduler') as $t => $tab) { $o .= $txt($x0 + $t * 96, 156, $tab, 12, $t === 0 ? $acc : $ink, $t === 0 ? 600 : 400); }
            $o .= '<rect x="' . $x0 . '" y="166" width="40" height="2" fill="' . $acc . '"/><rect x="' . $x0 . '" y="184" width="' . ($cw - 150) . '" height="30" rx="6" fill="' . $soft . '"/>' . $txt($x0 + 14, 203, 'Search posts', 12, $ink, 400) . '<rect x="' . ($x0 + $cw - 130) . '" y="184" width="130" height="30" rx="6" fill="none" stroke="' . $line . '"/>' . $txt($x0 + $cw - 65, 203, 'All statuses', 12, $white, 500, 'middle');
            $rows = array(array('Rooftop set, part two', 'Scheduled', 'Subscribers', '0', '0'), array('Morning run, three photos', 'Scheduled', 'Everyone', '0', '0'), array('Studio day, new lighting', 'Published', 'Everyone', '1,240', '18'), array('Market walk, Saturday', 'Published', 'Subscribers', '860', '9'), array('Coffee stop, two photos', 'Published', 'Everyone', '2,105', '31'), array('Beach day, full set', 'Published', 'Pay-per-view', '640', '12'));
            foreach ($rows as $i => $r) {
                $y = 232 + $i * 58; $sched = ($r[1] === 'Scheduled');
                $o .= '<rect x="' . $x0 . '" y="' . $y . '" width="' . $cw . '" height="50" rx="8" fill="#1c1c20" stroke="' . $line . '"/><rect x="' . ($x0 + 12) . '" y="' . ($y + 8) . '" width="34" height="34" rx="6" fill="' . $bar . '"/>';
                $o .= $txt($x0 + 58, $y + 21, $r[0], 13, $white, 600) . '<rect x="' . ($x0 + 58) . '" y="' . ($y + 28) . '" width="' . ($sched ? 66 : 64) . '" height="14" rx="7" fill="' . ($sched ? $acc : $soft) . '"/>' . $txt($x0 + 58 + ($sched ? 33 : 32), $y + 38, $r[1], 10, $sched ? '#fff' : $ink, 600, 'middle') . $txt($x0 + 136, $y + 39, $r[2], 11, $ink, 400);
                $o .= $txt($x0 + $cw - 190, $y + 30, $r[3] . ' views', 11, $ink, 400) . $txt($x0 + $cw - 110, $y + 30, $r[4], 11, $ink, 400) . '<rect x="' . ($x0 + $cw - 70) . '" y="' . ($y + 11) . '" width="58" height="28" rx="6" fill="none" stroke="' . $ink . '"/>' . $txt($x0 + $cw - 41, $y + 29, 'Edit', 12, $white, 500, 'middle');
            }
        } elseif ($kind === 'inbox') {
            // left: the conversation list (one row highlighted); right: the thread with a fan message, a priced media bubble, and the agent's draft waiting for approval
            $lw = 250; $o .= $txt($x0, 98, 'Inbox', 18, $white, 700) . $txt($x0, 122, '3 waiting for your approval', 12, $acc, 600);
            $convs = array(array('Jade', 'Loved the rooftop set, is there a part three?', true), array('Noah', 'Can I book a call next week?', false), array('Priya', 'Thanks for the welcome message!', false), array('Sam', 'Does the VIP tier include the archive?', false), array('Lena', 'Just joined, hi!', false));
            foreach ($convs as $i => $c) { $y = 144 + $i * 62; $on = $c[2]; $o .= '<rect x="' . $x0 . '" y="' . $y . '" width="' . $lw . '" height="54" rx="8" fill="' . ($on ? '#1f1f23' : '#1c1c20') . '" stroke="' . ($on ? $acc : $line) . '"/><circle cx="' . ($x0 + 24) . '" cy="' . ($y + 27) . '" r="14" fill="' . $bar . '"/>' . $txt($x0 + 48, $y + 22, $c[0], 13, $white, 600) . $txt($x0 + 48, $y + 40, mb_substr($c[1], 0, 28) . (mb_strlen($c[1]) > 28 ? '…' : ''), 11, $ink, 400); }
            $tx = $x0 + $lw + 20; $tw = $cw - $lw - 20;
            $o .= '<rect x="' . $tx . '" y="84" width="' . $tw . '" height="476" rx="8" fill="#1c1c20" stroke="' . $line . '"/>' . $txt($tx + 20, 112, 'Jade', 14, $white, 700) . $txt($tx + 70, 112, 'Insider member since March', 11, $ink, 400);
            $o .= '<rect x="' . ($tx + 20) . '" y="140" width="300" height="44" rx="10" fill="' . $soft . '"/>' . $txt($tx + 34, 167, 'Loved the rooftop set, is there a part three?', 12, $white, 400);
            $o .= '<rect x="' . ($tx + $tw - 320) . '" y="200" width="300" height="44" rx="10" fill="#2a1a10" stroke="' . $acc . '"/>' . $txt($tx + $tw - 306, 219, 'Part three, 12 photos', 12, $white, 600) . $txt($tx + $tw - 306, 236, 'Unlock for 40 credits', 11, $acc, 600) . '<rect x="' . ($tx + $tw - 74) . '" y="210" width="40" height="24" rx="4" fill="' . $bar . '"/>';
            $o .= $txt($tx + 20, 286, 'Draft reply, in your voice', 11, $ink, 400) . '<rect x="' . ($tx + 20) . '" y="298" width="' . ($tw - 40) . '" height="110" rx="10" fill="' . $soft . '" stroke="' . $line . '"/>' . $txt($tx + 36, 326, 'Part three went up this morning, Jade. It is 12 photos from the', 12, $white, 400) . $txt($tx + 36, 346, 'same rooftop, and members get it at 40 credits. Thank you for', 12, $white, 400) . $txt($tx + 36, 366, 'sticking around since March.', 12, $white, 400);
            $o .= '<rect x="' . ($tx + 20) . '" y="428" width="110" height="34" rx="6" fill="' . $acc . '"/>' . $txt($tx + 75, 450, 'Approve', 12, '#fff', 700, 'middle') . '<rect x="' . ($tx + 140) . '" y="428" width="90" height="34" rx="6" fill="none" stroke="' . $ink . '"/>' . $txt($tx + 185, 450, 'Edit', 12, $white, 500, 'middle') . '<rect x="' . ($tx + 240) . '" y="428" width="90" height="34" rx="6" fill="none" stroke="' . $ink . '"/>' . $txt($tx + 285, 450, 'Skip', 12, $white, 500, 'middle');
            $o .= '<rect x="' . ($tx + 20) . '" y="488" width="' . ($tw - 40) . '" height="40" rx="8" fill="' . $bg . '" stroke="' . $line . '"/>' . $txt($tx + 36, 513, 'Write a message', 12, $ink, 400);
        } else {
            $o .= $txt($x0, 98, 'Analytics', 18, $white, 700) . $txt($x0, 122, 'Last 30 days vs. previous 30 days', 12, $ink, 400);
            $o .= '<rect x="' . ($x0 + $cw - 210) . '" y="80" width="210" height="30" rx="6" fill="' . $soft . '"/>' . $txt($x0 + $cw - 175, 100, '7 days', 11, $ink, 400, 'middle') . '<rect x="' . ($x0 + $cw - 140) . '" y="84" width="70" height="22" rx="4" fill="#f4f4f5"/>' . $txt($x0 + $cw - 105, 100, '30 days', 11, '#161618', 600, 'middle') . $txt($x0 + $cw - 35, 100, '90 days', 11, $ink, 400, 'middle');
            $tiles = array(array('Revenue', '2,400 credits', '+12%'), array('Subscribers', '128', '+6%'), array('Followers', '3,410', '+9%'), array('Views', '12,800', '+21%'), array('Posts', '24', '+4'), array('Engagement', '4.1%', '+0.3'));
            $tw = (int) floor(($cw - 5 * 12) / 6);
            foreach ($tiles as $i => $tile) { $x = $x0 + $i * ($tw + 12); $o .= '<rect x="' . $x . '" y="132" width="' . $tw . '" height="92" rx="8" fill="#1c1c20" stroke="' . $line . '"/>' . $txt($x + 12, 152, $tile[0], 11, $ink, 400) . '<circle cx="' . ($x + $tw - 18) . '" cy="148" r="8" fill="' . ($i === 0 ? $acc : $soft) . '"/>' . $txt($x + 12, 180, $tile[1], 16, $white, 700) . '<rect x="' . ($x + 12) . '" y="194" width="38" height="16" rx="4" fill="' . $soft . '"/>' . $txt($x + 31, 206, $tile[2], 10, $acc, 600, 'middle'); }
            $o .= '<rect x="' . $x0 . '" y="244" width="' . $cw . '" height="316" rx="8" fill="#1c1c20" stroke="' . $line . '"/>' . $txt($x0 + 20, 272, 'Revenue', 14, $white, 700) . $txt($x0 + 20, 292, 'Last 30 days', 11, $ink, 400) . $txt($x0 + $cw - 20, 272, '2,400 credits earned', 13, $white, 600, 'end');
            $n = 30; $bw = (int) floor(($cw - 40 - ($n - 1) * 6) / $n);
            for ($i = 0; $i < $n; $i++) { $hh = 30 + (($i * 53) % 160); $x = $x0 + 20 + $i * ($bw + 6); $o .= '<rect x="' . $x . '" y="' . (528 - $hh) . '" width="' . $bw . '" height="' . $hh . '" rx="3" fill="' . ($i === 23 ? $acc : $soft) . '"/>'; }
            $o .= '<rect x="' . ($x0 + 20) . '" y="532" width="' . ($cw - 40) . '" height="1" fill="' . $line . '"/>' . $txt($x0 + 20, 550, 'Sep 12', 10, $ink, 400) . $txt($x0 + $cw - 20, 550, 'Oct 12', 10, $ink, 400, 'end');
        }
        return $o . '</svg>';
    }

    public static function ui_sketch_name(string $kind): string {
        return array('plans' => 'Schematic of the Membership Plans screen', 'posts' => 'Schematic of the Content Studio posts list', 'analytics' => 'Schematic of the Analytics dashboard', 'inbox' => 'Schematic of the Inbox with a drafted reply waiting for approval')[$kind] ?? 'Schematic of a product screen';
    }

    /** The sketch as a static SVG file (written on dev like the charts, shipped with the deploy), returned as its URL. */
    public static function ui_sketch_url(string $kind): string { return self::svg_asset(self::ui_sketch_svg($kind), 'sketch-' . $kind); }

    /** The sketch as a figure: <img> with a descriptive alt and a visible caption. */
    public static function ui_sketch(string $kind, string $alt, string $caption = ''): string {
        return '<figure class="sx-figure sx-figure--sketch"><img class="sx-sketch" src="' . self::e(self::ui_sketch_url($kind)) . '" alt="' . self::e($alt) . '" width="960" height="600" loading="lazy" decoding="async">'
            . ($caption !== '' ? '<figcaption class="sx-figcap">' . self::e($caption) . '</figcaption>' : '') . '</figure>';
    }

    /** Inline SVG process diagram: boxes joined by arrows, each step a short label (g_multimodal). The steps are the accessible name. */
    public static function flow_svg(array $steps, string $title): string {
        $n = count($steps); if ($n < 2) { return ''; }
        $bw = 150; $bh = 54; $gap = 36; $w = $n * $bw + ($n - 1) * $gap + 16; $h = $bh + 16;
        $svg = '<svg class="sx-flow" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-labelledby="sxf_t sxf_d" xmlns="http://www.w3.org/2000/svg" style="font-family:Inter,Helvetica,Arial,sans-serif">';
        $svg .= '<title id="sxf_t">' . self::e($title) . '</title><desc id="sxf_d">' . self::e(implode(', then ', $steps)) . '.</desc>';
        $svg .= '<defs><marker id="sxf_arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 z" fill="#8e8e94"/></marker></defs>';
        foreach ($steps as $i => $label) {
            $x = 8 + $i * ($bw + $gap); $last = ($i === $n - 1);
            $svg .= '<rect x="' . $x . '" y="8" width="' . $bw . '" height="' . $bh . '" rx="6" fill="' . ($last ? '#CD4C00' : '#1c1c20') . '" stroke="' . ($last ? '#CD4C00' : '#2f2f34') . '"/>';
            $lines = self::wrap_words((string) $label, 20);
            foreach ($lines as $li => $line) { $svg .= '<text x="' . ($x + $bw / 2) . '" y="' . (8 + $bh / 2 + ($li - (count($lines) - 1) / 2) * 15 + 4) . '" text-anchor="middle" font-size="12" fill="#f4f4f5">' . self::e($line) . '</text>'; }
            if (!$last) { $svg .= '<line x1="' . ($x + $bw + 4) . '" y1="' . (8 + $bh / 2) . '" x2="' . ($x + $bw + $gap - 4) . '" y2="' . (8 + $bh / 2) . '" stroke="#8e8e94" stroke-width="1.5" marker-end="url(#sxf_arrow)"/>'; }
        }
        $svg .= '</svg>';
        $caption = $title . ': ' . implode(', then ', $steps) . '.';
        $out = self::chart_figure($svg, 'flow-' . substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), 0, 40), 'Process diagram, ' . $caption, $w, $h, $title . '.');
        return str_replace('<figure class="sx-figure">', '<figure class="sx-figure sx-figure--flow">', $out);
    }

    private static function wrap_words(string $s, int $width): array {
        $lines = array(); $cur = '';
        foreach (preg_split('/\s+/', trim($s)) as $wd) { if ($cur !== '' && strlen($cur . ' ' . $wd) > $width) { $lines[] = $cur; $cur = $wd; } else { $cur = ($cur === '' ? $wd : $cur . ' ' . $wd); } }
        if ($cur !== '') { $lines[] = $cur; }
        return $lines;
    }

    /** Open a full-width section. $bg: 'white'|'alt'. Optional heading block. */
    public static function open(string $bg = 'white', string $title = '', string $lead = '', string $id = ''): string {
        $o = '<section class="sx' . ($bg === 'alt' ? ' sx--alt' : '') . '"' . ($id !== '' ? ' id="' . self::e($id) . '"' : '') . '><div class="ld-wrap sx__in">';
        if ($title !== '') {
            $o .= '<div class="sx-head"><h2 class="sx-h2">' . self::e(self::tc($title)) . '</h2>' . ($lead !== '' ? self::paragraphs($lead, 'sx-lead') : '') . '</div>';
        }
        return $o;
    }

    public static function close(): string { return '</div></section>'; }

    public static function checks(array $items, int $cols = 1, bool $html = false): string {
        $o = '<ul class="sx-checks' . ($cols > 1 ? ' sx-checks--' . $cols : '') . '">';
        foreach ($items as $i) { $o .= '<li>' . self::icon('check', 16) . '<span>' . ($html ? $i : (self::sentence_count((string) $i) > self::SENT_MAX ? self::paragraphs((string) $i) : self::e($i))) . '</span></li>'; }
        return $o . '</ul>';
    }

    /** Alternating image/text rows. Each row: title, text, points[] (optional), image (url, optional), link (array(label, href), optional). */
    public static function rows(array $rows): string {
        $noimg = count(array_filter($rows, function ($r) { return trim((string) ($r['image'] ?? '')) === ''; }));   // text-only rows sit in a grid: three (or six) fill three columns, so no row is left alone under a pair
        $o = '<div class="sx-rows' . ($noimg === count($rows) && count($rows) > 0 && count($rows) % 3 === 0 ? ' sx-rows--3' : '') . '">'; $n = 0;
        foreach ($rows as $r) {
            $img = trim((string) ($r['image'] ?? '')); $flip = isset($r['flip']) ? (bool) $r['flip'] : ($n % 2 === 1); $n++;   // 'flip' puts the picture on the right; sections with one row each alternate by setting it
            $o .= '<div class="sx-row' . ($flip ? ' sx-row--flip' : '') . ($img === '' ? ' sx-row--noimg' : '') . '">';   // a div, not <article>: extractors would take a card for the page's main content
            if ($img !== '') {   // 'image_alt' describes the picture; 'caption' is a visible line under it (a screenshot's "Example dashboard (demo account)")
                $o .= '<figure class="sx-row__media"><img src="' . self::e($img) . '" alt="' . self::e((string) ($r['image_alt'] ?? '')) . '" loading="lazy" width="' . (int) ($r['image_w'] ?? 1024) . '" height="' . (int) ($r['image_h'] ?? 768) . '">'
                    . (!empty($r['caption']) ? '<figcaption class="sx-figcap">' . self::e($r['caption']) . '</figcaption>' : '') . '</figure>';
            }
            $o .= '<div class="sx-row__text"><h3 class="sx-h3">' . self::e(self::tc($r['title'])) . '</h3>' . self::paragraphs((string) $r['text'], 'sx-p') . '';
            if (!empty($r['points'])) { $o .= self::checks((array) $r['points']); }
            if (!empty($r['link'])) { $o .= '<a class="sx-more" href="' . self::e($r['link'][1]) . '">' . self::e($r['link'][0]) . '</a>'; }
            $o .= '</div></div>';
        }
        return $o . '</div>';
    }

    /** Card grid. Each card: icon (name, optional), title, text, points[] (optional), note (optional, small line at the bottom). */
    public static function cards(array $cards, int $cols = 3): string {
        $o = '<div class="sx-cards sx-cards--' . $cols . '">';
        foreach ($cards as $c) {
            $o .= '<div class="sx-card">';   // a div, not <article> (see rows)
            if (!empty($c['icon'])) { $o .= '<span class="sx-card__icon">' . self::icon($c['icon']) . '</span>'; }
            $o .= '<h3 class="sx-card__title">' . self::e(self::tc($c['title'])) . '</h3>';
            if (!empty($c['text'])) { $o .= self::paragraphs((string) $c['text'], 'sx-card__text'); }
            if (!empty($c['points'])) { $o .= self::checks((array) $c['points']); }
            if (!empty($c['note'])) { $o .= self::paragraphs((string) $c['note'], 'sx-card__note'); }
            if (!empty($c['link'])) { $o .= '<a class="sx-more" href="' . self::e($c['link'][1]) . '">' . self::e($c['link'][0]) . '</a>'; }
            $o .= '</div>';
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

    /**
     * Prose section: a heading and paragraphs of plain text. Inside a paragraph, [text](https://url) becomes a source link
     * (nofollow, new tab) and [text](/path) an internal link; everything else is escaped. Used by the compare, alternatives,
     * best-of and monetize pages for their page-specific copy (CompareProse).
     */
    public static function prose(string $bg, string $title, array $paragraphs, string $lead = ''): string {
        $o = self::open($bg, $title, $lead);
        foreach ($paragraphs as $p) {
            $h = self::e($p);
            $h = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)/', function ($m) {
                $ext = strpos($m[2], '/') !== 0;
                return '<a href="' . $m[2] . '"' . ($ext ? ' rel="noopener" target="_blank"' : '') . '>' . $m[1] . '</a>';   // $m[*] are already escaped text
            }, $h);
            $o .= self::paragraphs_html($h, 'sx-p');   // capped at SENT_MAX conservative sentences, links kept
        }
        return $o . self::close();
    }

    /** prose() with an id on the section, for jump links. */
    public static function prose_id(string $bg, string $title, string $id, array $paragraphs, string $lead = ''): string {
        return self::open($bg, $title, $lead, $id) . self::prose_inline($paragraphs) . self::close();
    }

    /** Paragraphs inside an already-open section (same markup as prose(), without opening or closing one). */
    public static function prose_inline(array $paragraphs): string {
        $o = '';
        foreach ($paragraphs as $p) {
            $h = self::e($p);
            $h = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)/', function ($m) { $ext = strpos($m[2], '/') !== 0; return '<a href="' . $m[2] . '"' . ($ext ? ' rel="noopener" target="_blank"' : '') . '>' . $m[1] . '</a>'; }, $h);
            $o .= self::paragraphs_html($h, 'sx-p');
        }
        return $o;
    }

    /** FAQ accordion (questions and answers are plain text). */
    public static function faq(array $faq, string $bg = 'alt'): string {
        $o = self::open($bg) . '<div class="sx-faq"><h2 class="sx-h2" id="sx_faq">Frequently Asked Questions</h2><div class="sx-faq__list">';
        foreach ($faq as $qa) {
            $o .= '<details class="sx-faq__item"><summary>' . self::e($qa['q']) . '<svg class="sx-faq__chev" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary><div class="sx-faq__a">' . self::paragraphs((string) $qa['a']) . '</div></details>';
        }
        return $o . '</div></div>' . self::close();
    }

    /**
     * The sentence cap on every text container of the public pages (c_paragraph_length, Daniel 2026-10-12): at most
     * SENT_MAX sentences, counted the conservative way the audit counts them, where every period, question mark,
     * exclamation mark or closing parenthesis followed by a space is a break.
     */
    const SENT_MAX = 4;
    public static function sentence_count(string $t): int {
        $t = trim(preg_replace('/\s+/', ' ', $t)); if ($t === '') { return 0; }
        return count(array_filter(preg_split('/(?<=[.?!)])\s+/', $t), function ($x) { return trim($x) !== ''; }));
    }
    /** Text split into chunks of at most SENT_MAX conservative sentences, cut only at real sentence ends (never inside a figure or a link). */
    public static function cap(string $text, int $max = self::SENT_MAX): array {
        $text = trim(preg_replace('/\s+/', ' ', $text)); if ($text === '') { return array(); }
        if (self::sentence_count($text) <= $max) { return array($text); }
        $real = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9$"(\[])/', $text);
        $out = array(); $cur = '';
        foreach ($real as $sen) {
            $try = $cur === '' ? $sen : $cur . ' ' . $sen;
            if ($cur !== '' && self::sentence_count($try) > $max) { $out[] = $cur; $cur = $sen; } else { $cur = $try; }
        }
        if ($cur !== '') { $out[] = $cur; }
        return $out;
    }
    /** Plain text as <p> elements under the cap: one paragraph per blank-line block, each block split as cap() decides. */
    public static function paragraphs(string $text, string $class = ''): string {
        $out = ''; $cls = $class !== '' ? ' class="' . $class . '"' : '';
        foreach (preg_split('/\n\s*\n/', trim($text)) as $block) { foreach (self::cap($block) as $piece) { $out .= '<p' . $cls . '>' . self::e($piece) . '</p>'; } }
        return $out;
    }
    /** Like paragraphs(), for text that already holds safe inline HTML (links): the cap counts and cuts on the text, pieces keep their markup. */
    public static function paragraphs_html(string $html, string $class = ''): string {
        $cls = $class !== '' ? ' class="' . $class . '"' : '';
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        if (self::sentence_count(html_entity_decode($text, ENT_QUOTES, 'UTF-8')) <= self::SENT_MAX) { return '<p' . $cls . '>' . $html . '</p>'; }
        // cut the HTML at the same real sentence ends cap() would use, tags carried along
        $parts = preg_split('/(?<=[.!?])\s+(?=(?:<[^>]+>)*[A-Z0-9$"(\[])/', trim($html));
        $out = ''; $cur = '';
        foreach ($parts as $sen) {
            $try = $cur === '' ? $sen : $cur . ' ' . $sen;
            if ($cur !== '' && self::sentence_count(html_entity_decode(strip_tags($try), ENT_QUOTES, 'UTF-8')) > self::SENT_MAX) { $out .= '<p' . $cls . '>' . $cur . '</p>'; $cur = $sen; } else { $cur = $try; }
        }
        if ($cur !== '') { $out .= '<p' . $cls . '>' . $cur . '</p>'; }
        return $out;
    }

    /** Closing call to action: full-width black band. */
    public static function cta(string $title, string $text = '', bool $pricing_link = true, string $signup = '/?auth=register'): string {
        return '<section class="sx sx--cta"><div class="ld-wrap sx__in sx-cta"><div><h2 class="sx-cta__title">' . self::e(self::tc($title)) . '</h2>'
            . ($text !== '' ? '<p class="sx-cta__text">' . self::e($text) . '</p>' : '') . '</div>'
            . '<div class="sx-acts"><a class="sx-btn sx-btn--light" href="' . self::e($signup) . '" data-auth="register">Start Free</a>'
            . ($pricing_link ? '<a class="sx-btn sx-btn--ghost" href="/pricing">See Pricing</a>' : '') . '</div></div></section>';
    }
}
