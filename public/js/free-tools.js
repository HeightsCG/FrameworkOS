/*
 * Browser-only free tools (/tools/ai-influencer-name-generator, /tools/ai-influencer-bio-generator,
 * /tools/ai-disclosure-caption-checker). Everything runs here: no request leaves the page, nothing is stored,
 * no credits. window.CLSFreeTools.mount(el, slug, opts) builds a tool inside el; the tool pages call it on
 * [data-ft] and public/js/embed.js calls it inside a Shadow DOM on other sites (opts.embed = true).
 */
(function () {
    'use strict';

    var esc = function (s) {
        return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    };

    // Seeded random (mulberry32), so the same inputs give the same list and Shuffle moves to the next one.
    var seeded = function (seed) {
        return function () {
            seed |= 0; seed = seed + 0x6D2B79F5 | 0;
            var t = Math.imul(seed ^ seed >>> 15, 1 | seed);
            t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
            return ((t ^ t >>> 14) >>> 0) / 4294967296;
        };
    };
    var hash = function (s) { var h = 2166136261; for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return h >>> 0; };
    var pick = function (rnd, list) { return list[Math.floor(rnd() * list.length)]; };

    var copy_text = function (text, btn) {
        var done = function () {
            var was = btn.getAttribute('data-label') || btn.textContent;
            btn.setAttribute('data-label', was);
            btn.textContent = 'Copied'; btn.classList.add('is-copied');
            setTimeout(function () { btn.textContent = was; btn.classList.remove('is-copied'); }, 1600);
        };
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function () { fallback(); }); return; }
        fallback();
        function fallback() {
            var ta = document.createElement('textarea'); ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
            document.body.removeChild(ta);
        }
    };

    var options = function (list, chosen) {
        return list.map(function (o) { return '<option value="' + esc(o) + '"' + (o === chosen ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('');
    };

    /* ---------- Name generator ---------- */

    var NICHES = {
        'Fitness':   ['lifts', 'moves', 'fit', 'strong', 'trains'],
        'Fashion':   ['style', 'wears', 'edit', 'looks', 'closet'],
        'Beauty':    ['glow', 'glam', 'skin', 'beauty', 'blush'],
        'Travel':    ['travels', 'roams', 'abroad', 'wanders', 'away'],
        'Food':      ['eats', 'cooks', 'kitchen', 'table', 'bakes'],
        'Gaming':    ['plays', 'games', 'gg', 'arcade', 'quest'],
        'Tech':      ['tech', 'builds', 'labs', 'codes', 'bytes'],
        'Music':     ['sounds', 'beats', 'sings', 'tunes', 'records'],
        'Wellness':  ['calm', 'well', 'breathes', 'daily', 'rituals'],
        'Art':       ['draws', 'paints', 'studio', 'makes', 'sketches'],
        'Lifestyle': ['daily', 'life', 'diary', 'days', 'notes']
    };
    var VIBES = {
        'Luxe':       { first: ['Celeste', 'Valentina', 'Margaux', 'Aurelia', 'Sienna', 'Isabeau', 'Octavia', 'Delphine', 'Laurent', 'Julian', 'Matteo', 'Sebastian', 'Vivienne', 'Camille', 'Raphael', 'Elodie'],
                        last:  ['Laurent', 'Vance', 'Sterling', 'Delacroix', 'Monroe', 'Ashford', 'Beaumont', 'Rossi', 'Marchetti', 'Hale', 'Fontaine', 'Wilder', 'Castell', 'Devereux', 'Lennox', 'Blackwood'] },
        'Playful':    { first: ['Poppy', 'Juno', 'Milo', 'Daisy', 'Pip', 'Ruby', 'Ziggy', 'Lulu', 'Coco', 'Kiki', 'Ollie', 'Bea', 'Remy', 'Nova', 'Teddy', 'Mabel'],
                        last:  ['Sparks', 'Bloom', 'Bell', 'Honey', 'Skye', 'Pepper', 'Jolly', 'Breeze', 'Sunny', 'Dot', 'Berry', 'Fizz', 'Maple', 'Tumble', 'Penny', 'Lark'] },
        'Minimal':    { first: ['Ana', 'Leo', 'Mia', 'Eli', 'Noa', 'Kai', 'Ida', 'Theo', 'Liv', 'Ren', 'Ava', 'Sol', 'Mae', 'Jun', 'Ines', 'Arlo'],
                        last:  ['Lind', 'Moss', 'Grey', 'Stone', 'West', 'North', 'Vale', 'Ash', 'Reed', 'Lowe', 'Hart', 'Snow', 'Wren', 'Fern', 'Brook', 'Dale'] },
        'Edgy':       { first: ['Raven', 'Jett', 'Nyx', 'Blaze', 'Rogue', 'Vex', 'Onyx', 'Sasha', 'Dante', 'Echo', 'Riot', 'Zara', 'Knox', 'Lilith', 'Axel', 'Storm'],
                        last:  ['Black', 'Steele', 'Cross', 'Vega', 'Kane', 'Hex', 'Wolfe', 'Volt', 'Night', 'Raze', 'Voss', 'Graves', 'Rook', 'Slate', 'Ember', 'Thorne'] },
        'Cozy':       { first: ['Hazel', 'Willow', 'Rosie', 'Oliver', 'Clara', 'Finn', 'Ivy', 'Hugo', 'Elsie', 'Arthur', 'Nell', 'Freya', 'Benji', 'Lottie', 'Rowan', 'Edie'],
                        last:  ['Woods', 'Hearth', 'Brown', 'Cotton', 'Meadows', 'Fields', 'Oakley', 'Gable', 'Hollow', 'Bramble', 'Holt', 'Ember', 'Pine', 'Larkin', 'Wells', 'Hayes'] },
        'Futuristic': { first: ['Lyra', 'Zion', 'Astra', 'Orion', 'Vega', 'Neo', 'Kira', 'Ion', 'Zephyr', 'Elara', 'Cyrus', 'Nyla', 'Atlas', 'Rhea', 'Xen', 'Solene'],
                        last:  ['Quinn', 'Cole', 'Nexus', 'Ray', 'Arc', 'Prime', 'Flux', 'Zenith', 'Sato', 'Kael', 'Vire', 'Lumen', 'Strand', 'Halo', 'Tesla', 'Cypher'] }
    };

    var name_tool = function (root) {
        var niches = Object.keys(NICHES), vibes = Object.keys(VIBES), round = 0;
        root.innerHTML =
            '<form class="ft-form" novalidate>' +
                '<div class="ct-row">' +
                    '<div class="ct-field"><label class="ct-label" for="ftn_niche">Niche</label><select class="ct-input" id="ftn_niche">' + options(niches, 'Fitness') + '</select></div>' +
                    '<div class="ct-field"><label class="ct-label" for="ftn_vibe">Vibe</label><select class="ct-input" id="ftn_vibe">' + options(vibes, 'Minimal') + '</select></div>' +
                '</div>' +
                '<div class="ft-acts"><button class="sx-btn sx-btn--primary" type="submit">Generate Names</button><button class="sx-btn sx-btn--ghost ft-shuffle" type="button" hidden>Shuffle</button></div>' +
            '</form>' +
            '<div class="ft-out" aria-live="polite"><p class="ft-empty">Pick a niche and a vibe, then generate 20 names.</p></div>';
        var form = root.querySelector('form'), out = root.querySelector('.ft-out'), shuffle = root.querySelector('.ft-shuffle');
        var run = function () {
            var niche = root.querySelector('#ftn_niche').value, vibe = root.querySelector('#ftn_vibe').value;
            var v = VIBES[vibe], words = NICHES[niche], rnd = seeded(hash(niche + '|' + vibe + '|' + round));
            var seen = {}, names = [], guard = 0;
            while (names.length < 20 && guard++ < 500) {
                var first = pick(rnd, v.first), last = pick(rnd, v.last);
                if (first === last || seen[first + last]) { continue; }
                seen[first + last] = 1;
                var f = first.toLowerCase(), l = last.toLowerCase(), w = pick(rnd, words), style = Math.floor(rnd() * 4);
                var handle = style === 0 ? f + w : style === 1 ? f + '.' + l : style === 2 ? f + l : f + '_' + w;
                names.push({ name: first + ' ' + last, handle: '@' + handle });
            }
            out.innerHTML = '<ul class="ft-list">' + names.map(function (n) {
                return '<li class="ft-item"><span class="ft-item__main"><b>' + esc(n.name) + '</b><small>' + esc(n.handle) + '</small></span>' +
                    '<button class="ft-copy" type="button" data-copy="' + esc(n.name + ' ' + n.handle) + '" aria-label="Copy ' + esc(n.name) + '">Copy</button></li>';
            }).join('') + '</ul>';
            shuffle.hidden = false;
        };
        form.addEventListener('submit', function (e) { e.preventDefault(); round = 0; run(); });
        shuffle.addEventListener('click', function () { round++; run(); });
    };

    /* ---------- Bio generator ---------- */

    // Profile bio limits, in characters (the bio field, not posts): what each platform allows today.
    var BIO_LIMITS = [['Instagram', 150], ['TikTok', 80], ['X', 160], ['Threads', 150], ['Bluesky', 256]];
    var TONES = {
        'Warm': [
            '{name}. {niche}, slow mornings and good company.',
            'Hi, I am {name}. Sharing {niche} that makes ordinary days better.',
            '{name} here. Everyday {niche}, honest notes and the small things that make it fun. Glad you found me.',
            'I am {name}, and this is my corner for {niche}: simple ideas, real routines and the moments in between. Pull up a chair, new posts every week.'
        ],
        'Bold': [
            '{name}. {niche}, no filler.',
            '{name}. {niche} done loud. Follow if you are serious.',
            '{name}. Big on {niche}, short on patience for the boring version. New drops every week.',
            '{name}. {niche} without the small talk: the looks, the wins, the hard days and what I learned from them. Turn notifications on.'
        ],
        'Playful': [
            '{name}! {niche}, snacks and chaos.',
            '{name} here: {niche} with extra sparkle and zero rules.',
            'Hey, I am {name}. {niche}, bad puns and a camera roll that needs help. Come say hi.',
            'Hey, I am {name}. I post {niche}, questionable dance moves and the occasional plot twist. Stick around, it gets better every week.'
        ],
        'Professional': [
            '{name}. {niche} insights.',
            '{name}. Practical {niche}, explained clearly.',
            '{name}. Practical {niche} for people who want results. New guides and breakdowns every week.',
            '{name}. Clear, practical {niche}: what works, what does not and how to start. Weekly breakdowns, tools and answers to your questions.'
        ],
        'Mysterious': [
            '{name}. {niche}, after dark.',
            '{name}. {niche}, and the stories I do not tell.',
            '{name}. {niche} from somewhere between real and imagined. Look closer.',
            '{name}. {niche}, told in fragments. Every post is a clue, and the full story is only for the ones who stay to the end.'
        ]
    };
    var AI_LINES = [' | AI-generated character', ' | AI creator', ' | AI'];

    var bio_tool = function (root) {
        root.innerHTML =
            '<form class="ft-form" novalidate>' +
                '<div class="ct-row">' +
                    '<div class="ct-field"><label class="ct-label" for="ftb_name">Name</label><input class="ct-input" id="ftb_name" type="text" placeholder="Lyra Quinn" autocomplete="off"></div>' +
                    '<div class="ct-field"><label class="ct-label" for="ftb_niche">Niche</label><input class="ct-input" id="ftb_niche" type="text" placeholder="Strength training" autocomplete="off"></div>' +
                '</div>' +
                '<div class="ct-row">' +
                    '<div class="ct-field"><label class="ct-label" for="ftb_tone">Tone</label><select class="ct-input" id="ftb_tone">' + options(Object.keys(TONES), 'Warm') + '</select></div>' +
                    '<label class="ft-check" for="ftb_ai"><input type="checkbox" id="ftb_ai" checked><span>Say It Is AI</span></label>' +
                '</div>' +
                '<p class="ft-error" role="alert" hidden></p>' +
                '<div class="ft-acts"><button class="sx-btn sx-btn--primary" type="submit">Write Bios</button></div>' +
            '</form>' +
            '<div class="ft-out" aria-live="polite"><p class="ft-empty">Enter a name and a niche to get five bios, one per platform.</p></div>';
        var form = root.querySelector('form'), out = root.querySelector('.ft-out'), err = root.querySelector('.ft-error');
        var fill = function (t, name, niche) { return t.replace(/\{name\}/g, name).replace(/\{niche\}/g, niche); };
        var cap = function (s) { return s.charAt(0).toUpperCase() + s.slice(1); };
        var run = function () {
            var name_el = root.querySelector('#ftb_name'), niche_el = root.querySelector('#ftb_niche');
            var name = name_el.value.trim(), niche = niche_el.value.trim(), ai = root.querySelector('#ftb_ai').checked;
            name_el.classList.toggle('is-bad', name === ''); niche_el.classList.toggle('is-bad', niche === '');
            if (name === '' || niche === '') { err.textContent = 'Enter a name and a niche.'; err.hidden = false; (name === '' ? name_el : niche_el).focus(); return; }
            err.hidden = true;
            var list = TONES[root.querySelector('#ftb_tone').value];
            out.innerHTML = '<ul class="ft-bios">' + BIO_LIMITS.map(function (pl) {
                var limit = pl[1], best = '', shortest = '';
                // the longest template (and AI line) that fits this platform's limit; the niche starts a sentence when a template opens with it
                for (var i = 0; i < list.length; i++) {
                    var body = fill(list[i], name, list[i].indexOf('{niche}') === 0 ? cap(niche) : niche);
                    var tails = ai ? AI_LINES : [''];
                    for (var j = 0; j < tails.length; j++) {
                        var b = body + tails[j];
                        if (shortest === '' || b.length < shortest.length) { shortest = b; }
                        if (b.length <= limit && b.length > best.length) { best = b; }
                    }
                }
                var bio = best !== '' ? best : shortest, over = bio.length > limit;
                return '<li class="ft-bio' + (over ? ' is-over' : '') + '"><div class="ft-bio__head"><b>' + esc(pl[0]) + '</b>' +
                    '<span class="ft-count">' + bio.length + ' / ' + limit + '</span></div>' +
                    '<p class="ft-bio__text">' + esc(bio) + '</p>' +
                    (over ? '<p class="ft-bio__warn">Too long for ' + esc(pl[0]) + '. Shorten the name or niche.</p>' : '') +
                    '<button class="ft-copy" type="button" data-copy="' + esc(bio) + '" aria-label="Copy the ' + esc(pl[0]) + ' bio">Copy</button></li>';
            }).join('') + '</ul>';
        };
        form.addEventListener('submit', function (e) { e.preventDefault(); run(); });
    };

    /* ---------- AI disclosure caption checker ---------- */

    var PLATFORMS = ['Instagram', 'TikTok', 'YouTube', 'Facebook', 'Threads', 'X', 'Bluesky', 'LinkedIn', 'Pinterest', 'Fanvue'];
    var HARD_LIMITS = { 'X': 280, 'Bluesky': 300, 'Threads': 500 };   // post length limits
    var OWN_LABEL = { 'TikTok': 'TikTok has its own AI-generated content label. Turn it on when you post.', 'YouTube': 'YouTube has its own altered or synthetic content setting. Turn it on when you upload.' };
    var CLEAR = /\b(ai[\s-]?generated|generated (with|by|using) ai|made (with|using) ai|created (with|using|by) ai|ai[\s-]?assisted|ai[\s-]?edited|synthetic (media|content|image|video)|this is an ai|i am an ai|i'm an ai|virtual (influencer|creator|character))\b|#(aigenerated|madewithai|createdwithai|aiassisted|syntheticmedia|virtualinfluencer)\b/i;
    var VAGUE = /(^|\s)#(ai|aiart|aimodel|aiinfluencer|aigirl|aiboy|digitalart|aiphoto|aiimage)\b/i;
    var PREVIEW = 125;   // our check: most apps show roughly this much before "more"

    var check_tool = function (root) {
        root.innerHTML =
            '<form class="ft-form" novalidate>' +
                '<div class="ct-field"><label class="ct-label" for="ftc_caption">Caption</label><textarea class="ct-input ct-input--area" id="ftc_caption" rows="5" placeholder="Golden hour on the rooftop. #aigenerated"></textarea></div>' +
                '<div class="ct-field"><label class="ct-label" for="ftc_platform">Platform</label><select class="ct-input" id="ftc_platform">' + options(PLATFORMS, 'Instagram') + '</select></div>' +
                '<p class="ft-error" role="alert" hidden></p>' +
                '<div class="ft-acts"><button class="sx-btn sx-btn--primary" type="submit">Check Caption</button></div>' +
            '</form>' +
            '<div class="ft-out" aria-live="polite"><p class="ft-empty">Paste a caption and pick a platform to check its AI disclosure.</p></div>';
        var form = root.querySelector('form'), out = root.querySelector('.ft-out'), err = root.querySelector('.ft-error');
        var run = function () {
            var ta = root.querySelector('#ftc_caption'), caption = ta.value.trim(), platform = root.querySelector('#ftc_platform').value;
            ta.classList.toggle('is-bad', caption === '');
            if (caption === '') { err.textContent = 'Paste a caption to check.'; err.hidden = false; ta.focus(); return; }
            err.hidden = true;
            var good = [], fix = [], m = caption.match(CLEAR), vague = VAGUE.test(caption);
            var first_line = caption.split(/\n/)[0];
            if (m) {
                good.push('It says the content is AI: "' + m[0].trim() + '".');
                if (m.index > PREVIEW && first_line.length < m.index) { fix.push('The disclosure sits after the first ' + PREVIEW + ' characters, so many viewers will not see it before the caption is cut off. Move it to the start.'); }
                else { good.push('The disclosure is near the start, where viewers see it first.'); }
                var tail = caption.slice(m.index);
                if (/^#/.test(m[0].trim()) && /^(#\S+\s*)+$/.test(tail)) { fix.push('The disclosure is only a hashtag in a block of hashtags at the end. Say it in words too.'); }
            } else if (vague) {
                fix.push('Hashtags like #ai or #aiart do not clearly say the image or video was made with AI. Use plain words such as AI-generated.');
            } else {
                fix.push('No AI disclosure found. If the image, video or voice was made or edited with AI, say so in the caption.');
            }
            if (HARD_LIMITS[platform]) {
                var n = Array.from ? Array.from(caption).length : caption.length;
                if (n > HARD_LIMITS[platform]) { fix.push('The caption is ' + n + ' characters, over ' + platform + '\'s ' + HARD_LIMITS[platform] + '. A cut could remove the disclosure, so keep it at the start.'); }
            }
            var pass = fix.length === 0;
            var suggestion = '';
            if (!pass) {
                var body = m ? caption.replace(m[0], '').replace(/\s{2,}/g, ' ').trim() : caption;
                suggestion = 'AI-generated content. ' + body;
            }
            out.innerHTML =
                '<div class="ft-result ' + (pass ? 'is-pass' : 'is-fix') + '">' +
                    '<p class="ft-result__h">' + (pass ? 'Clear Disclosure' : 'Needs Work') + '</p>' +
                    '<ul class="ft-reasons">' + good.map(function (g) { return '<li class="is-good">' + esc(g) + '</li>'; }).join('') + fix.map(function (f) { return '<li class="is-fix">' + esc(f) + '</li>'; }).join('') + '</ul>' +
                    (OWN_LABEL[platform] ? '<p class="ft-note">' + esc(OWN_LABEL[platform]) + '</p>' : '') +
                    (suggestion !== '' ? '<div class="ft-suggest"><p class="ft-suggest__h">Suggested Caption</p><p class="ft-suggest__text">' + esc(suggestion) + '</p><button class="ft-copy" type="button" data-copy="' + esc(suggestion) + '">Copy</button></div>' : '') +
                    '<p class="ft-legal">This is a quick check, not legal advice. Platform rules change, so read each platform\'s current policy.</p>' +
                '</div>';
        };
        form.addEventListener('submit', function (e) { e.preventDefault(); run(); });
    };

    var TOOLS = { 'ai-influencer-name-generator': name_tool, 'ai-influencer-bio-generator': bio_tool, 'ai-disclosure-caption-checker': check_tool };

    window.CLSFreeTools = {
        mount: function (el, slug) {
            if (!el || !TOOLS[slug]) { return false; }
            var root = document.createElement('div'); root.className = 'ft';
            el.appendChild(root);
            TOOLS[slug](root);
            root.addEventListener('click', function (e) {
                var b = e.target.closest ? e.target.closest('.ft-copy') : null;
                if (b) { copy_text(b.getAttribute('data-copy') || '', b); }
            });
            return true;
        },
        copy: copy_text
    };

    // On the tool page: mount every [data-ft], and wire the "Embed this tool" copy button.
    var boot = function () {
        var nodes = document.querySelectorAll('[data-ft]');
        for (var i = 0; i < nodes.length; i++) { var n = nodes[i]; n.innerHTML = ''; window.CLSFreeTools.mount(n, n.getAttribute('data-ft')); }
        var eb = document.querySelectorAll('[data-copy-from]');
        for (var k = 0; k < eb.length; k++) {
            eb[k].addEventListener('click', function () { var src = document.getElementById(this.getAttribute('data-copy-from')); if (src) { copy_text(src.value || src.textContent, this); } });
        }
    };
    if (!window.CLS_FT_EMBED) {
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
    }
})();
