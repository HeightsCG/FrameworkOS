/*
 * Embed for the browser-only free tools. Other sites paste (from the "Embed this tool" box on each tool page):
 *   <div data-cls-tool="<slug>"><a href="https://www.creatorlinkstudio.com/tools/<slug>">Free <Tool> by Creator Link Studio</a></div>
 *   <script async src="https://www.creatorlinkstudio.com/js/embed.js"></script>
 * Each div gets a Shadow DOM holding the tool (public/js/free-tools.js) and its own styles, so the host page's CSS
 * never reaches in and ours never leaks out. The credit link stays in the light DOM (shown through a slot), so it is
 * there for readers and crawlers even when scripts are off. No framing, no cookies, no request besides free-tools.js.
 */
(function () {
    'use strict';
    var me = document.currentScript || (function () { var s = document.getElementsByTagName('script'); for (var i = s.length - 1; i >= 0; i--) { if (/\/js\/embed\.js/.test(s[i].src)) { return s[i]; } } return null; })();
    var base = me ? me.src.replace(/\/js\/embed\.js.*$/, '') : 'https://www.creatorlinkstudio.com';

    // The tool styles (tools.css + the /contact field styles), on a fixed dark card in the brand colours.
    var CSS = ':host{all:initial;display:block;max-width:640px;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color-scheme:dark;' +
        '--sx-bg:#050505;--sx-grey:#161618;--sx-grey-2:#1f1f22;--sx-line:rgba(255,255,255,.07);--sx-line-2:rgba(255,255,255,.13);--sx-acc:#ff6a13;--sx-acc-h:#e85a06;' +
        '--sx-on-acc:#0a0a0a;--sx-ink:#f5f5f4;--sx-t2:#a8a8ad;--sx-muted:#8e8e94;--sx-r:6px;--sx-ring:0 0 0 2px #161618,0 0 0 4px #ff6a13}' +
        '*,*::before,*::after{box-sizing:border-box}' +
        '.card{background:var(--sx-grey);border:1px solid var(--sx-line-2);border-radius:10px;padding:24px;color:var(--sx-ink);font-size:16px;line-height:1.5}' +
        '.credit{margin-top:12px;font-size:13px;color:var(--sx-muted)}' +
        '::slotted(a){color:inherit;text-decoration:underline;text-underline-offset:2px}' +
        '.ct-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.ct-field{display:flex;flex-direction:column;gap:8px}' +
        '.ct-label{font-size:14px;font-weight:600;color:var(--sx-ink)}' +
        '.ct-input{width:100%;height:44px;padding:0 14px;background:var(--sx-grey-2);border:1px solid var(--sx-line-2);border-radius:var(--sx-r);color:var(--sx-ink);font:inherit;font-size:16px}' +
        '.ct-input--area{height:auto;min-height:140px;padding:12px 14px;resize:vertical}.ct-input::placeholder{color:var(--sx-muted)}' +
        '.ct-input:focus{outline:none;border-color:var(--sx-acc);box-shadow:0 0 0 3px rgba(255,106,19,.2)}.ct-input.is-bad{border-color:#f87171}' +
        'select.ct-input{appearance:none;padding-right:40px;background-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'16\' height=\'16\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%238e8e94\' stroke-width=\'1.5\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Cpath d=\'m6 9 6 6 6-6\'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center}' +
        'select.ct-input option{background:var(--sx-grey-2);color:var(--sx-ink)}' +
        '.sx-btn{display:inline-flex;align-items:center;justify-content:center;height:44px;padding:0 20px;border:1px solid transparent;border-radius:var(--sx-r);font:inherit;font-size:15px;font-weight:600;cursor:pointer}' +
        '.sx-btn--primary{background:var(--sx-acc);color:var(--sx-on-acc)}.sx-btn--primary:hover{background:var(--sx-acc-h)}' +
        '.sx-btn--ghost{background:transparent;color:var(--sx-ink);border-color:var(--sx-line-2)}.sx-btn--ghost:hover{border-color:var(--sx-muted)}' +
        '.sx-btn:focus-visible{outline:none;box-shadow:var(--sx-ring)}[hidden]{display:none!important}';

    // The ft-* rules from public/css/tools.css, copied here (a cross-site fetch of the stylesheet would need CORS headers). Keep in step.
    var FT_CSS = ".ft { display: flex; flex-direction: column; gap: 20px; } .ft-form { display: flex; flex-direction: column; gap: 16px; } .ft-form .ct-row { align-items: end; } .ft-acts { display: flex; flex-wrap: wrap; gap: 12px; } .ft-check { display: flex; align-items: center; gap: 10px; height: 44px; font-size: .9375rem; font-weight: 600; color: var(--sx-ink); cursor: pointer; } .ft-check input { width: 18px; height: 18px; margin: 0; accent-color: var(--sx-acc); cursor: pointer; } .ft-check input:focus-visible { outline: none; box-shadow: var(--sx-ring); border-radius: 3px; } .ft-error { margin: 0; font-size: .875rem; color: #f87171; } .ft-error[hidden] { display: none; } .ft-empty { margin: 0; padding: 16px; border: 1px dashed var(--sx-line-2); border-radius: var(--sx-r); font-size: .9375rem; color: var(--sx-muted); } .ft-out { min-width: 0; } .ft-copy { flex: none; height: 32px; padding: 0 12px; border: 1px solid var(--sx-line-2); border-radius: var(--sx-r); background: transparent; color: var(--sx-t2); font: inherit; font-size: .8125rem; font-weight: 600; cursor: pointer; transition: border-color .2s, color .2s; } .ft-copy:hover { border-color: var(--sx-muted); color: var(--sx-ink); } .ft-copy:focus-visible { outline: none; box-shadow: var(--sx-ring); } .ft-copy.is-copied { border-color: var(--sx-acc); color: var(--sx-acc); } .ft-list { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; } .ft-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 1px solid var(--sx-line); border-radius: var(--sx-r); background: var(--sx-grey-2); } .ft-item__main { display: flex; flex-direction: column; min-width: 0; } .ft-item__main b { font-size: .9375rem; font-weight: 700; color: var(--sx-ink); } .ft-item__main small { font-size: .8125rem; color: var(--sx-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .ft-bios { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; } .ft-bio { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px 12px; padding: 12px; border: 1px solid var(--sx-line); border-radius: var(--sx-r); background: var(--sx-grey-2); } .ft-bio__head { display: flex; align-items: baseline; gap: 10px; } .ft-bio__head b { font-size: .875rem; font-weight: 700; color: var(--sx-ink); } .ft-count { font-size: .8125rem; color: var(--sx-muted); font-variant-numeric: tabular-nums; } .ft-bio__text { grid-column: 1; margin: 0; font-size: .9375rem; line-height: 1.5; color: var(--sx-t2); } .ft-bio .ft-copy { grid-column: 2; grid-row: 1 / span 2; align-self: start; } .ft-bio__warn { grid-column: 1; margin: 0; font-size: .8125rem; color: #f87171; } .ft-bio.is-over { border-color: rgba(248, 113, 113, .5); } .ft-bio.is-over .ft-count { color: #f87171; } .ft-result { display: flex; flex-direction: column; gap: 12px; padding: 16px; border: 1px solid var(--sx-line-2); border-radius: var(--sx-r); background: var(--sx-grey-2); } .ft-result.is-pass { border-color: rgba(74, 222, 128, .45); } .ft-result.is-fix { border-color: rgba(251, 191, 36, .45); } .ft-result__h { margin: 0; font-size: 1rem; font-weight: 700; color: var(--sx-ink); } .ft-reasons { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; } .ft-reasons li { position: relative; padding-left: 18px; font-size: .875rem; line-height: 1.5; color: var(--sx-t2); } .ft-reasons li::before { content: \"\"; position: absolute; left: 0; top: .5em; width: 8px; height: 8px; border-radius: 50%; } .ft-reasons .is-good::before { background: #4ade80; } .ft-reasons .is-fix::before { background: #fbbf24; } .ft-note { margin: 0; font-size: .875rem; color: var(--sx-ink); } .ft-suggest { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; padding-top: 12px; border-top: 1px solid var(--sx-line); } .ft-suggest__h { margin: 0; font-size: .8125rem; font-weight: 700; color: var(--sx-ink); } .ft-suggest__text { margin: 0; font-size: .9375rem; line-height: 1.5; color: var(--sx-t2); white-space: pre-wrap; overflow-wrap: anywhere; } .ft-legal { margin: 0; font-size: .75rem; color: var(--sx-muted); }@media (max-width: 640px){.ct-row{grid-template-columns:minmax(0,1fr)}.ft-list{grid-template-columns:minmax(0,1fr)}.ft-acts .sx-btn{flex:1 1 auto}}";

    var loading = null;
    var load_tools = function (cb) {
        if (window.CLSFreeTools) { cb(); return; }
        if (loading) { loading.push(cb); return; }
        loading = [cb];
        window.CLS_FT_EMBED = true;   // free-tools.js: do not boot the tool-page wiring on the host page
        var s = document.createElement('script'); s.src = base + '/js/free-tools.js'; s.async = true;
        s.onload = s.onerror = function () { var q = loading; loading = null; q.forEach(function (f) { f(); }); };
        document.head.appendChild(s);
    };

    var mount = function (host) {
        if (host.getAttribute('data-cls-mounted') === '1' || !host.attachShadow) { return; }
        var slug = host.getAttribute('data-cls-tool') || '';
        host.setAttribute('data-cls-mounted', '1');
        var shadow = host.attachShadow({ mode: 'open' });
        shadow.innerHTML = '<style>' + CSS + FT_CSS + '</style><div class="card"><div class="tool"></div></div><p class="credit"><slot></slot></p>';
        if (!window.CLSFreeTools || !window.CLSFreeTools.mount(shadow.querySelector('.tool'), slug)) {
            shadow.querySelector('.card').style.display = 'none';   // unknown tool: only the credit link shows
        }
    };

    var run = function () {
        var hosts = document.querySelectorAll('[data-cls-tool]');
        if (!hosts.length) { return; }
        load_tools(function () { for (var i = 0; i < hosts.length; i++) { mount(hosts[i]); } });
    };
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
})();
