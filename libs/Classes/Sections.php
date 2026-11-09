<?php
/**
 * The marketing-site section system. Every public page (home, features, pricing, guides) is
 * built from these blocks so the site has one rhythm: full-width sections that alternate white and
 * light gray, a 1200px content column, black and white with color only from imagery, 4px corners.
 * Styles: public/css/sx.css. Each method returns HTML; text arguments are escaped here unless the
 * parameter says it takes HTML (only ever passed from our own views, never user input).
 */
class Sections {

    public static function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

    /**
     * Headline Title Case (Daniel's rule): capitalise each word's first letter, keep short joining words lower
     * (except first/last), and never lower anything, so "AI", "TikTok", "YouTube" and "$29/mo" stay as written.
     */
    /** Hyphenated names tc() leaves exactly as written. */
    const TC_KEEP = array();

    public static function tc(string $s): string {
        $small = array('a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'nor', 'of', 'on', 'or', 'per', 'the', 'to', 'vs', 'via', 'with');
        $w = preg_split('/(\s+)/u', trim($s), -1, PREG_SPLIT_DELIM_CAPTURE);
        $last = count($w) - 1;
        foreach ($w as $i => $word) {
            if ($word === '' || preg_match('/^\s+$/u', $word)) { continue; }
            $bare = strtolower(trim($word, '.,:;!?()"\''));
            if ($i !== 0 && $i !== $last && in_array($bare, $small, true)) { continue; }
            $w[$i] = preg_replace_callback('/^([\'"(\[]*)(\p{Ll})/u', function ($m) { return $m[1] . mb_strtoupper($m[2]); }, $word);
            // hyphenated words take a capital after each hyphen too ("Link-In-Bio", "Built-In"); brand spellings are kept
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
            'video' => '<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
            'arrow-right' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
            'wand' => '<path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4M19 14v4M10 2v2M7 8H3M21 16h-4M11 3H9"/>',
            'zap' => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
            'mic' => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/>',
            'face' => '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01M15 9h.01"/>',
        );
        return '<svg class="sx-ic" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? $p['check']) . '</svg>';
    }

    /** Buttons: array(label, href, 'primary'|'secondary', auth?) — auth = 'login'|'register' opens the sign-in dialog. */
    public static function buttons(array $btns, string $class = 'sx-acts'): string {
        if (empty($btns)) { return ''; }
        $h = '<div class="' . $class . '">';
        foreach ($btns as $b) {
            // Site-wide redesign 2026-10-15: the plain sign-up primary is the trial offer (trial_link(): "Start Your 14-Day Free Trial" while
            // founding spots remain, else "Start Free"). Sign-ups with their own parameters (?ref=, ?plan=) keep their label and link.
            if (($b[2] ?? '') === 'primary' && (string) $b[1] === '/?auth=register') { $t = self::trial_link(); $b = array($t[0], $t[1], 'primary', $t[2]); }
            $auth = !empty($b[3]) ? ' data-auth="' . self::e($b[3]) . '"' : '';
            $rel = strpos((string) $b[1], '?') !== false ? ' rel="nofollow"' : '';   // sign-up links carry ?auth= parameters: nofollow keeps them out of the crawl
            $h .= '<a class="sx-btn sx-btn--' . self::e($b[2] ?? 'secondary') . '" href="' . self::e($b[1]) . '"' . $rel . $auth . '>' . self::e($b[0]) . (($b[2] ?? '') === 'primary' ? self::icon('arrow-right', 18) : '') . '</a>';
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
     * $h: id, tabs => array(array('word' => 'Create.', 'pane' => html)), start (index), title (optional
     * real H1 shown first with the words as a compact tab row under it; without it the words are the H1), tag, lead, buttons, after_html (trusted HTML under the grid, titled hero only). Pane HTML comes from the pane_*
     * helpers below (trusted, built from our own views).
     * ------------------------------------------------------------------------------------------- */
    public static function tab_hero(array $h): string {
        $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($h['id'] ?? 'hx')));
        $start = (int) ($h['start'] ?? 0);
        $small = !empty($h['words_eyebrow']);   // the words as one small line above the H1 (home page): decoration that marks which card is showing, not tabs
        $words = ''; $panes = '';
        foreach ($h['tabs'] as $i => $t) {
            $k = $id . '_' . $i; $on = ($i === $start);
            if ($small) {
                $words .= '<span class="hx__w' . ($on ? ' is-on' : '') . '" data-hx="' . $i . '">' . self::e($t['word']) . '<span class="hx__bar"><i></i></span></span>';
                // the card's title is a line of text here, not a heading: the page's outline is the H1 and the sections below
                $panes .= '<div class="hx__pane" data-hx="' . $i . '"' . ($on ? '' : ' hidden') . '>' . preg_replace(array('#<h2 class="hx__h#', '#</h2>#'), array('<p class="hx__h', '</p>'), (string) $t['pane']) . '</div>';
                continue;
            }
            $words .= '<button type="button" class="hx__w' . ($on ? ' is-on' : '') . '" role="tab" id="' . $k . '_t" aria-selected="' . ($on ? 'true' : 'false') . '" aria-controls="' . $k . '_p"' . ($on ? '' : ' tabindex="-1"') . ' data-hx="' . $i . '">' . self::e($t['word']) . '<span class="hx__bar" aria-hidden="true"><i></i></span></button>';
            $panes .= '<div class="hx__pane" role="tabpanel" id="' . $k . '_p" aria-labelledby="' . $k . '_t" data-hx="' . $i . '"' . ($on ? '' : ' hidden') . '>' . $t['pane'] . '</div>';
        }
        $title = trim((string) ($h['title'] ?? ''));
        $o = '<section class="sx hx' . ($small ? ' hx--eyebrow' : '') . '"><div class="hx__in"><div class="hx__left">';
        if (!$small) { $o .= $title === '' ? '<h1 class="hx__words" role="tablist">' . $words . '</h1>' : '<div class="hx__words" role="tablist" aria-label="' . self::e($title) . '">' . $words . '</div>'; }
        $o .= '<div class="hx__intro">';
        if ($small) { $o .= '<p class="hx__words hx__words--eyebrow" aria-hidden="true">' . $words . '</p>'; }
        if (!empty($h['eyebrow'])) { $o .= '<p class="hx__eyebrow">' . self::e($h['eyebrow']) . '</p>'; }   // one line above the H1
        if ($title !== '') { $o .= '<h1 class="hx__title">' . self::e($title) . '</h1>'; }   // the H1 as written, not title-cased: it is a sentence
        if (!empty($h['sub'])) { $o .= '<p class="hx__sub">' . self::e($h['sub']) . '</p>'; }   // one line directly under the H1 (the sub-line on the home page)
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
        if (!empty($h['photo']) || !empty($h['variant'])) { return self::photo_hero($h); }   // 2026-10-15: the photographic heroes
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

    /**
     * One photo from SitePhotos as an <img> with its WebP srcset, intrinsic size and crop focus. $eager for the hero (the LCP image):
     * fetchpriority high, never lazy. Returns '' when the photo is missing, so a page never shows a broken image.
     */
    public static function photo(string $key, string $sizes, string $class = '', bool $eager = false): string {
        if (!SitePhotos::has($key)) { return ''; }
        $p = SitePhotos::PHOTOS[$key];
        return '<img' . ($class !== '' ? ' class="' . self::e($class) . '"' : '') . ' src="' . self::e(SitePhotos::src($key)) . '" srcset="' . self::e(SitePhotos::srcset($key)) . '" sizes="' . self::e($sizes) . '"'
            . ' width="' . (int) $p[3] . '" height="' . (int) $p[4] . '" alt="' . self::e($p[5]) . '" style="object-position:' . self::e($p[6]) . '"'
            . ($eager ? ' fetchpriority="high" decoding="async"' : ' loading="lazy" decoding="async"') . '>';
    }

    /**
     * Premium hero: the approved home hero as a shared component (2026-10-15, Features first). Same proportions, type, fade, benefit
     * list and buttons as libs/Layout/home_body.php (whose own markup stays untouched), with page-specific content.
     * $h: title (first line, white), highlight (second line, orange; optional), lead, points (array of array(icon, text)), buttons
     * (see buttons()), photo (SitePhotos key, a tall 4:5 portrait), after_html (trusted HTML under the hero, e.g. jump links).
     * The H1 reads as one sentence: title then highlight.
     */
    public static function premium_hero(array $h): string {
        $photo = (string) ($h['photo'] ?? '');
        $img = $photo !== '' ? self::photo($photo, '(max-width: 760px) 100vw, 58vw', 'pm-hero__img', true) : '';
        $o = '<section class="pm-hero' . ($img === '' ? ' pm-hero--nophoto' : '') . '" aria-labelledby="pm_title">';
        if ($img !== '') { $o .= '<div class="pm-hero__media">' . $img . '</div>'; }
        $o .= '<div class="ld-wrap pm-hero__in"><div class="pm-hero__copy"><h1 class="pm-hero__title" id="pm_title"><span>' . self::e(self::tc((string) $h['title'])) . '</span>'
            . (!empty($h['highlight']) ? ' <span class="pm-hero__hl">' . self::e(self::tc((string) $h['highlight'])) . '</span>' : '') . '</h1>';
        if (!empty($h['lead'])) { $o .= '<p class="pm-hero__sub">' . self::e((string) $h['lead']) . '</p>'; }
        if (!empty($h['points'])) {
            $o .= '<ul class="pm-hero__pts">';
            foreach ((array) $h['points'] as $pt) { $o .= '<li>' . self::icon((string) $pt[0], 22) . self::e((string) $pt[1]) . '</li>'; }
            $o .= '</ul>';
        }
        $btns = (array) ($h['buttons'] ?? array());
        foreach ($btns as $i => $b) { $btns[$i][2] = $b[2] ?? 'secondary'; }
        $o .= str_replace('class="sx-btn ', 'class="sx-btn sx-btn--lg ', self::buttons($btns, 'pm-hero__acts')) . '</div></div>';
        if (!empty($h['after_html'])) { $o .= '<div class="ld-wrap pm-hero__after">' . $h['after_html'] . '</div>'; }
        return $o . '</section>';
    }

    /**
     * Photographic page hero (site-wide redesign 2026-10-15, from the home hero). Same type, buttons and fade as the home, four layouts so
     * pages do not all look alike. $h: title, lead, below, buttons, after_html, panel (trusted HTML), photo (SitePhotos key), variant:
     *   split     text left, the photo right fading into the black (the home's composition, tall photos)
     *   full      a wide photo across the hero with the subject on the right, text over the dark left side
     *   editorial headline and lead on top, a wide photo band under them
     *   panel     text left and the panel right, the wide photo dimmed behind both
     * On phones every variant stacks: text, photo, panel. Text never sits on a face.
     */
    public static function photo_hero(array $h): string {
        $photo = (string) ($h['photo'] ?? '');
        if ($photo !== '' && !SitePhotos::has($photo)) { $photo = ''; }
        $panel = (string) ($h['panel'] ?? '');
        $v = (string) ($h['variant'] ?? ($panel !== '' ? 'panel' : 'split'));
        if (!in_array($v, array('split', 'full', 'editorial', 'panel'), true)) { $v = 'split'; }
        $sizes = $v === 'split' ? '(max-width: 760px) 100vw, 52vw' : '100vw';
        $img = $photo !== '' ? self::photo($photo, $sizes, 'ph__img', true) : '';
        $o = '<section class="sx ph ph--' . $v . ($img === '' ? ' ph--nophoto' : '') . ($panel !== '' ? ' ph--haspanel' : '') . '"><div class="ld-wrap ph__in"><div class="ph__copy">';
        $o .= '<h1 class="ph__title">' . self::e(self::tc($h['title'])) . '</h1>';
        if (!empty($h['below'])) { $o .= '<div class="hx__below">' . $h['below'] . '</div>'; }
        if (!empty($h['lead'])) { $o .= self::paragraphs((string) $h['lead'], 'ph__lead'); }
        $o .= self::buttons((array) ($h['buttons'] ?? array()), 'sx-acts ph__acts');
        if (!empty($h['note'])) { $o .= '<p class="ph__note">' . self::e((string) $h['note']) . '</p>'; }
        $o .= '</div>';
        if ($v === 'editorial' && $img !== '') { $o .= '<figure class="ph__band">' . $img . '</figure>'; $img = ''; }
        if ($panel !== '') { $o .= '<div class="hx__mod ph__panel">' . $panel . '</div>'; }
        $o .= '</div>';
        if ($img !== '') { $o .= '<div class="ph__media">' . $img . '</div>'; }
        if (!empty($h['after_html'])) { $o .= '<div class="ld-wrap ph__after">' . $h['after_html'] . '</div>'; }
        return $o . '</section>';
    }

    /**
     * Product schematics: small drawings of a screen in HTML with invented values, always labelled, never a screenshot (Daniel's rule).
     * Kinds: automation, analytics, schedule, targets, offers (your page), inbox, linkpage, domain. Every row maps to a real feature.
     */
    public static function ui(string $kind): string {
        $e = function ($s) { return self::e($s); };
        $rows = function (array $r) use ($e) { $o = '<dl class="sx-ui__rows">'; foreach ($r as $x) { $o .= '<div><dt>' . $e($x[0]) . '</dt><dd' . (isset($x[2]) ? ' class="sx-ui__chips"' : '') . '>' . (isset($x[2]) ? $x[1] : $e($x[1])) . '</dd></div>'; } return $o . '</dl>'; };
        $chips = function (array $c) use ($e) { $o = ''; foreach ($c as $x) { $o .= '<span>' . $e($x) . '</span>'; } return $o; };
        $bar = function ($title, $right = '') use ($e) { return '<div class="sx-ui__bar"><b>' . $e($title) . '</b>' . $right . '</div>'; };
        $on = '<span class="sx-ui__on">Active</span>';
        $cap = 'Illustrative example';
        switch ($kind) {
            case 'automation':
                $body = $bar('Morning Post', $on) . $rows(array(array('Runs', 'Every day at 9:00'), array('Source', 'AI influencer'), array('Format', 'Photo'), array('Caption', 'Written by AI in your brand voice'),
                    array('Publish to', $chips(array('Your page', 'Instagram', 'TikTok', 'X')), 1), array('Next run', 'Tomorrow, 9:00')));
                break;
            case 'analytics':
                $bars = ''; foreach (array(38, 52, 45, 61, 57, 72, 66, 80, 74, 90, 84, 96) as $b) { $bars .= '<i style="height:' . (int) $b . '%"></i>'; }
                $body = $bar('Analytics', '<span class="sx-ui__seg"><span>7d</span><span class="is-on">30d</span><span>90d</span></span>')
                    . '<div class="sx-ui__kpis"><div><small>Views</small><b>12,480</b></div><div><small>Engagement</small><b>6.2%</b></div><div><small>Link clicks</small><b>1,936</b></div><div><small>New followers</small><b>418</b></div></div>'
                    . '<div class="sx-ui__chart"><small>Views</small><div class="sx-ui__bars">' . $bars . '</div></div>';
                $cap = 'Illustrative figures';
                break;
            case 'schedule':
                $q = array(array('Mon 9:00', 'Studio tour', 'Instagram, TikTok'), array('Tue 18:30', 'AI influencer photo set', 'Your page'), array('Wed 12:00', 'Behind the scenes', 'X, Threads, Bluesky'), array('Fri 9:00', 'Weekly recap', 'YouTube, Facebook'));
                $li = ''; foreach ($q as $x) { $li .= '<li><time>' . $e($x[0]) . '</time><b>' . $e($x[1]) . '</b><span>' . $e($x[2]) . '</span></li>'; }
                $body = $bar('Scheduled', '<span class="sx-ui__count">4 posts</span>') . '<ul class="sx-ui__queue">' . $li . '</ul>';
                break;
            case 'targets':
                $t = array(array('Instagram', '142 / 2,200'), array('TikTok', '142 / 2,200'), array('X', '142 / 280'), array('Threads', '142 / 500'), array('Bluesky', '142 / 300'), array('LinkedIn', '142 / 3,000'));
                $li = ''; foreach ($t as $x) { $li .= '<li><span class="sx-ui__tick">' . self::icon('check', 14) . '</span><b>' . $e($x[0]) . '</b><small>' . $e($x[1]) . '</small></li>'; }
                $body = $bar('New Post', '<span class="sx-ui__count">Publish now</span>') . '<p class="sx-ui__cap">New studio setup is finally done. Full tour on my page tonight.</p><ul class="sx-ui__targets">' . $li . '</ul>';
                break;
            case 'offers':
                $body = $bar('Your Page') . $rows(array(array('Latest post', 'Studio tour, 12 photos'), array('AI influencer', 'Mia, posting daily'), array('Links', 'YouTube, newsletter, shop'), array('Socials', 'Instagram, TikTok, X'), array('Domain', 'www.yourname.com')));
                break;
            case 'inbox':
                $body = $bar('Inbox', '<span class="sx-ui__count">AI draft</span>') . '<div class="sx-ui__msgs"><p class="is-in">Do you do custom requests?</p><p class="is-out">I do. Send me the details here and I will get back to you this week.</p></div><div class="sx-ui__acts"><span>Edit</span><span class="is-on">Send</span></div>';
                break;
            case 'linkpage':
                $li = ''; foreach (array('Latest video', 'My newsletter', 'Brand partnerships', 'Shop my presets') as $x) { $li .= '<li>' . $e($x) . '</li>'; }
                $body = $bar('@yourhandle') . '<ul class="sx-ui__links">' . $li . '</ul>';
                break;
            case 'domain':
                $body = $bar('Custom Domain', '<span class="sx-ui__on">Connected</span>') . $rows(array(array('Domain', 'www.yourname.com'), array('Points to', 'Your page'), array('Security', 'HTTPS on')));
                break;
            default:
                return '';
        }
        return '<figure class="sx-ui sx-ui--' . $e($kind) . '" aria-label="' . $e($cap . ': ' . $kind) . '">' . $body . '<figcaption>' . $e($cap) . '</figcaption></figure>';
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
        return $o . '<div class="hx__tot"><span>Example: clicks this month</span><b data-hx-total="' . $total . '">+' . number_format($total) . ' clicks</b></div></div>';
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
        $noimg = count(array_filter($rows, function ($r) { return trim((string) ($r['image'] ?? '')) === '' && (string) ($r['media_html'] ?? '') === ''; }));   // text-only rows sit in a grid: three (or six) fill three columns, so no row is left alone under a pair
        $o = '<div class="sx-rows' . ($noimg === count($rows) && count($rows) > 0 && count($rows) % 3 === 0 ? ' sx-rows--3' : '') . '">'; $n = 0;
        foreach ($rows as $r) {
            $media = (string) ($r['media_html'] ?? '');   // trusted HTML in place of the image (a Sections::ui schematic or a Sections::photo)
            $img = trim((string) ($r['image'] ?? '')); if ($media !== '') { $img = 'media'; } $flip = isset($r['flip']) ? (bool) $r['flip'] : ($n % 2 === 1); $n++;   // 'flip' puts the picture on the right; sections with one row each alternate by setting it
            $o .= '<div class="sx-row' . ($flip ? ' sx-row--flip' : '') . ($img === '' ? ' sx-row--noimg' : '') . '">';   // a div, not <article>: extractors would take a card for the page's main content
            if ($media !== '') { $o .= '<div class="sx-row__media sx-row__media--ui">' . $media . '</div>'; }
            elseif ($img !== '') {   // 'image_alt' describes the picture; 'caption' is a visible line under it (a screenshot's "Example dashboard (demo account)")
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

    /** One line pointing at the free tool that belongs with this hub page (ToolsController::for_page), or ''. */
    public static function tool_line(string $path): string {
        $o = '';
        foreach (ToolsController::for_page($path) as $t) {
            $x = self::cap($t[2], 1);
            $o .= '<p class="sx-note">Free tool: <a href="' . self::e($t[0]) . '">' . self::e($t[1]) . '</a>. ' . self::e((string) ($x[0] ?? '')) . '</p>';
        }
        return $o;
    }

    /**
     * "Related Guides": the blog articles that belong under this hub page, as a section of in-body links (SeoDrafter::guides_for).
     * Returns '' when no article is published for the page, so a hub never shows an empty block.
     */
    public static function related_guides(string $path, string $bg = 'white', int $limit = 4, int $offset = 0, string $prefer = ''): string {
        // $prefer: an article whose slug names this page's platform leads the block. $offset turns the rest of the pool (it wraps), so pages that share a topic show different articles.
        $pool = SeoDrafter::guides_for($path, 12); $first = array(); $rest = array();
        foreach ($pool as $r) { if ($prefer !== '' && strpos((string) $r['slug'], $prefer) !== false) { $first[] = $r; } else { $rest[] = $r; } }
        if ($offset > 0 && count($rest) > 1) { $k = $offset % count($rest); $rest = array_merge(array_slice($rest, $k), array_slice($rest, 0, $k)); }
        $rows = array_slice(array_merge($first, $rest), 0, $limit);
        if (empty($rows)) { return ''; }
        $o = self::open($bg, 'Related guides', '', 'guides') . '<dl class="fx-list fx-list--guides">';
        foreach ($rows as $r) {
            $x = self::cap((string) ($r['excerpt'] ?? ''), 2);
            $o .= '<div class="fx-list__row"><dt><a href="/blog/' . self::e($r['slug']) . '">' . self::e($r['title']) . '</a></dt><dd>' . self::e((string) ($x[0] ?? '')) . '</dd></div>';
        }
        return $o . '</dl>' . self::close();
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
            if (!empty($c['points_html'])) { $o .= self::checks((array) $c['points_html'], 1, true); }   // caller-escaped points that carry a source link
            if (!empty($c['note'])) { $o .= self::paragraphs((string) $c['note'], 'sx-card__note'); }
            if (!empty($c['link'])) { $o .= '<a class="sx-more" href="' . self::e($c['link'][1]) . '">' . self::e($c['link'][0]) . '</a>'; }
            $o .= '</div>';
        }
        return $o . '</div>';
    }

    /**
     * Table. $head: column labels (text). $rows: arrays of cells; cells are HTML (callers escape their own text,
     * which lets tables carry source links). $highlight: 0-based column index to emphasise, or -1.
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
     * (nofollow, new tab) and [text](/path) an internal link; everything else is escaped. Used for page-specific copy blocks.
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

    /**
     * The trial button: while founding spots remain it starts the free first Founding::TRIAL_DAYS (the claim link),
     * after that it is the plain sign-up. Returns array(label, href, data-auth). The number always comes from Founding.
     */
    /** One line on what Free can't do and which plan opens the tools; names the trial only while the founding offer is open (as trial_link). */
    public static function plan_note(): string {
        $open = false;
        try { $open = Founding::remaining_cached() > 0; } catch (\Throwable $ex) { error_log('[sections] founding: ' . $ex->getMessage()); }
        $unlock = $open ? 'A ' . (int) Founding::TRIAL_DAYS . '-day trial on ' . Founding::plan_names() . ' opens them all.' : 'The ' . Founding::plan_names() . ' plan opens them all.';
        return 'Free accounts can build drafts, but publishing, scheduling and the AI tools need a plan. ' . $unlock;
    }

    public static function trial_link(): array {
        $open = false;
        try { $open = Founding::remaining_cached() > 0; } catch (\Throwable $ex) { error_log('[sections] founding: ' . $ex->getMessage()); }
        if (!$open) { return array('Start Free', '/?auth=register', 'register'); }
        $c = Founding::claim_link();
        return array('Start Your ' . (int) Founding::TRIAL_DAYS . '-Day Free Trial', $c[0], $c[1]);
    }

    /**
     * Closing band (2026-10-15, from the home's final band): a big headline, one line of text and one orange button that follows the
     * trial offer. $photo (SitePhotos key) puts a darkened photo behind it. Used by trial_cta() and cta().
     */
    public static function band(string $title, string $text, array $btn, string $second = '', string $photo = '', string $note = ''): string {
        $img = $photo !== '' ? self::photo($photo, '100vw', 'sx-band__img') : '';
        return '<section class="sx sx--cta sx-band' . ($img !== '' ? ' sx-band--photo' : '') . '">' . ($img !== '' ? '<div class="sx-band__media">' . $img . '</div>' : '')
            . '<div class="ld-wrap sx__in sx-band__in"><h2 class="sx-cta__title">' . self::e(self::tc($title)) . '</h2>'
            . ($text !== '' ? '<p class="sx-cta__text">' . self::e($text) . '</p>' : '')
            . '<div class="sx-acts"><a class="sx-btn sx-btn--primary sx-btn--lg" href="' . self::e($btn[1]) . '" rel="nofollow"' . ($btn[2] !== '' ? ' data-auth="' . self::e($btn[2]) . '"' : '') . '>' . self::e($btn[0]) . self::icon('arrow-right', 18) . '</a>' . $second . '</div>'
            . ($note !== '' ? '<p class="sx-band__note">' . self::e($note) . '</p>' : '') . '</div></section>';   // $note: a short line under the buttons (Sections::plan_note)
    }

    /** Closing band with the trial button (integrations, free tools, press, /alternatives). */
    public static function trial_cta(string $title, string $text = '', string $photo = ''): string {
        return self::band($title, $text, self::trial_link(), '', $photo);
    }

    /** Closing call to action. The button follows the trial offer unless the page passes its own sign-up link (?ref=, ?plan=). */
    public static function cta(string $title, string $text = '', bool $pricing_link = true, string $signup = '/?auth=register', string $photo = '', string $note = ''): string {
        $btn = $signup === '/?auth=register' ? self::trial_link() : array('Start Free', $signup, 'register');
        return self::band($title, $text, $btn, $pricing_link ? '<a class="sx-btn sx-btn--secondary sx-btn--lg" href="/pricing">See Pricing</a>' : '', $photo, $note);
    }
}
