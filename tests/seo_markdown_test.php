<?php
if (php_sapi_name() !== 'cli') { exit(1); }
$root = dirname(__DIR__);
require_once "$root/libs/Classes/Markdown.php";
$fail = 0;
function check($label, $ok){ global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }
$md = "# Title\n\nIntro with **bold** and *em* and `code`.\n\n## Section\n\n- one\n- two\n\n1. first\n2. second\n\n> quoted\n\n| A | B |\n|---|---|\n| 1 | 2 |\n\nSee [features](/features) and [evil](https://evil.example) and <script>alert(1)</script>.\n\n### Sub\n\nTail.";
$html = Markdown::render($md, array('/features'));
check('h1 demoted to h2',            strpos($html, '<h2>Title</h2>') !== false && strpos($html, '<h1') === false);
check('h2 kept',                     strpos($html, '<h2>Section</h2>') !== false);
check('h3 kept',                     strpos($html, '<h3>Sub</h3>') !== false);
check('bold/em/code inline',         strpos($html, '<strong>bold</strong>') !== false && strpos($html, '<em>em</em>') !== false && strpos($html, '<code>code</code>') !== false);
check('ul rendered',                 strpos($html, '<ul><li>one</li><li>two</li></ul>') !== false);
check('ol rendered',                 strpos($html, '<ol><li>first</li><li>second</li></ol>') !== false);
check('blockquote rendered',         strpos($html, '<blockquote><p>quoted</p></blockquote>') !== false);
check('table rendered',              strpos($html, '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>') !== false);
check('allowed internal link kept',  strpos($html, '<a href="/features">features</a>') !== false);
check('external link stripped',      strpos($html, 'evil.example') === false && strpos($html, '>evil<') === false && strpos($html, 'evil') !== false);
check('raw html escaped',            strpos($html, '<script') === false && strpos($html, '&lt;script&gt;') !== false);
check('links() lists targets',       Markdown::links($md) === array('/features', 'https://evil.example'));
check('word_count ignores markup',   Markdown::word_count("## Hi there\n\n**bold** word [link](/x)") === 5);
check('headings() counts h2',        Markdown::headings($md, 2) === 1);
check('no allowlist = any relative', strpos(Markdown::render("[a](/anything)"), '<a href="/anything">a</a>') !== false);
check('disallowed relative stripped', strpos(Markdown::render("[a](/nope)", array('/features')), '<a') === false);
check('backslash pseudo-protocol link stripped', strpos(Markdown::render("[x](/\\evil.com) [y](/\\/evil.com)"), '<a') === false);
check('code span protects link/bold syntax',   Markdown::render("`[a](/b)` and `not **bold**`") === '<p><code>[a](/b)</code> and <code>not **bold**</code></p>');
check('balanced parens in link target',        strpos(Markdown::render("[wiki](/wiki/Foo_(bar))"), '<a href="/wiki/Foo_(bar)">wiki</a></p>') !== false);
check('short table row padded',                substr_count(Markdown::render("| A | B | C |\n|---|---|---|\n| 1 | 2 |"), '<td>') === 3);
check('unlisted path with colon rejected',     strpos(Markdown::render("[x](/javascript:alert(1))", array('/features')), '<a') === false);
echo $fail === 0 ? "ALL OK\n" : "$fail FAILED\n"; exit($fail === 0 ? 0 : 1);
