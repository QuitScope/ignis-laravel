// CEF probe: renders the same pages in Chromium 103 (FiveM's CEF) and a current Chromium and
// diffs layout boxes and colours of every element. No dependencies: drives both browsers over
// the DevTools protocol with Node's built-in WebSocket (Node >= 22). See docs/dev/cef.md.
//
//   node tests/e2e/cef/probe.mjs --old <chrome103.exe> --new <current chrome.exe> \
//     --login-old <tablet login url> --login-new <tablet login url> [--paths /,/personnel] [--out dir]
//     [--enotf-vehicle <id>]   (eNOTF: after the tablet login, open a crew on that EMS vehicle via POST /enotf/login)
//     [--post '<path>|<form body>|<expected final path>' ...]   (form POSTs after the login, or after the first page
//                               without --login-*: FireTab crew, clinic device; fails when the redirect ends elsewhere)
// Per page it also lists WebSocket handshakes, pusher events and /broadcasting/auth statuses (Reverb).
//
// Exit code 1 when Chromium 103 throws a JS error, drops an @media rule, or lays an element out differently (box or
// shown/hidden; native <select> widths excepted). Colour differences are listed for review only (see docs/dev/cef.md).
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { parseArgs } from 'node:util';

const { values: o } = parseArgs({
    options: {
        old: { type: 'string' },
        new: { type: 'string' },
        'login-old': { type: 'string' },
        'login-new': { type: 'string' },
        paths: { type: 'string', default: '/login,/,/personnel,/mci/create' },
        out: { type: 'string', default: 'storage/cef-probe' },
        'enotf-vehicle': { type: 'string' },
        post: { type: 'string', multiple: true, default: [] },
    },
});
if (!o.old || !o.new) throw new Error('--old and --new (browser executables) are required');
mkdirSync(o.out, { recursive: true });
if (o['enotf-vehicle']) o.post.unshift(`/enotf/login|vehicle_id=${o['enotf-vehicle']}&pin=1234|/enotf/overview`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function launch(exe) {
    const dir = mkdtempSync(join(tmpdir(), 'cef-probe-'));
    const proc = spawn(exe, ['--headless', '--hide-scrollbars', '--no-first-run', '--remote-debugging-port=0', `--user-data-dir=${dir}`], { stdio: 'ignore' });
    const portFile = join(dir, 'DevToolsActivePort');
    for (let i = 0; i < 100 && !existsSync(portFile); i++) await sleep(100);
    const [port] = readFileSync(portFile, 'utf8').split('\n');
    const { webSocketDebuggerUrl } = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json();
    const ws = new WebSocket(webSocketDebuggerUrl);
    await new Promise((res, rej) => ((ws.onopen = res), (ws.onerror = rej)));
    let id = 0;
    const pending = new Map();
    const listeners = [];
    ws.onmessage = (e) => {
        const m = JSON.parse(e.data);
        if (m.id && pending.has(m.id)) {
            const { res, rej } = pending.get(m.id);
            pending.delete(m.id);
            m.error ? rej(new Error(m.error.message)) : res(m.result);
        } else listeners.forEach((l) => l(m));
    };
    const send = (method, params = {}, sessionId) =>
        new Promise((res, rej) => {
            pending.set(++id, { res, rej });
            ws.send(JSON.stringify({ id, method, params, sessionId }));
        });
    const close = () => {
        ws.close();
        proc.kill();
        setTimeout(() => rmSync(dir, { recursive: true, force: true }), 1000);
    };
    return { send, on: (f) => listeners.push(f), close };
}

// Runs inside the page: one entry per element (document order), plus feature support.
const COLLECT = `(() => {
    const cv = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
    const rgba = (v) => { cv.clearRect(0, 0, 1, 1); cv.fillStyle = 'rgba(0,0,0,0)'; cv.fillStyle = v; cv.fillRect(0, 0, 1, 1); return [...cv.getImageData(0, 0, 1, 1).data]; };
    // A dialog box with DialogContent's classes (centred with translate-x/y-[-50%]); it is measured like any element.
    const dialog = document.body.appendChild(document.createElement('div'));
    dialog.className = 'fixed top-[50%] left-[50%] translate-x-[-50%] translate-y-[-50%] w-full max-w-[calc(100%-2rem)] sm:max-w-lg p-6 cef-probe-dialog';
    // SVG <defs> content is never rendered; 103 still reports boxes for it (eNOTF body map clip-path), current Chromium 0.
    const els = [...document.querySelectorAll('body *')].filter((e) => !['SCRIPT', 'STYLE'].includes(e.tagName) && !e.closest('defs'));
    const rules = (list) => [...list].flatMap((r) => [r, ...(r.cssRules ? rules(r.cssRules) : [])]);
    const all = [...document.styleSheets].flatMap((s) => { try { return rules(s.cssRules); } catch { return []; } });
    const out = {
        ua: navigator.userAgent,
        deadMedia: all.filter((r) => r instanceof CSSMediaRule && r.media.mediaText === 'not all').length,
        supports: {
            'media range syntax (width >= 1px)': matchMedia('(width >= 1px)').media !== 'not all',
            'color-mix()': CSS.supports('color', 'color-mix(in lab, red, red)'),
            'oklch()': CSS.supports('color', 'oklch(0.5 0.1 100)'),
            'translate/scale/rotate properties': CSS.supports('translate', '1px'),
            ':has()': CSS.supports('selector(:has(a))'),
            '@layer': typeof CSSLayerBlockRule !== 'undefined',
            '@property': typeof CSSPropertyRule !== 'undefined',
            'container queries': CSS.supports('container-type', 'inline-size'),
            'CSS nesting': typeof CSSNestedDeclarations !== 'undefined' || CSS.supports('selector(&)'),
            'lh unit': CSS.supports('min-height', '1lh'),
            'dvh unit': CSS.supports('height', '1dvh'),
            'crypto.randomUUID': typeof crypto.randomUUID === 'function',
            'structuredClone': typeof structuredClone === 'function',
            'Array.prototype.at': typeof [].at === 'function',
        },
        els: els.map((e) => {
            const r = e.getBoundingClientRect();
            const s = getComputedStyle(e);
            const cls = typeof e.className === 'string' ? e.className : '';
            return {
                id: e.tagName.toLowerCase() + (cls ? '.' + cls.trim().split(/\\s+/).slice(0, 6).join('.') : ''),
                box: [r.x, r.y, r.width, r.height].map(Math.round),
                display: s.display,
                color: rgba(s.color),
                background: rgba(s.backgroundColor),
                border: rgba(s.borderTopColor),
            };
        }),
    };
    dialog.remove();
    return out;
})()`;

async function probe(label, exe, loginUrl) {
    const b = await launch(exe);
    const { targetId } = await b.send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await b.send('Target.attachToTarget', { targetId, flatten: true });
    const s = (m, p) => b.send(m, p, sessionId);
    const errors = [];
    const net = [];
    b.on((m) => {
        if (m.sessionId !== sessionId) return;
        if (m.method === 'Network.webSocketCreated') net.push(`ws open ${m.params.url.replace(/\?.*/, '')}`);
        if (m.method === 'Network.webSocketHandshakeResponseReceived') net.push(`ws handshake ${m.params.response.status}`);
        if (m.method === 'Network.webSocketFrameReceived') net.push(`ws ${m.params.response.payloadData.match(/"event":"([^"]+)"/)?.[1] ?? "?"}`);
        if (m.method === 'Network.webSocketClosed') net.push('ws closed');
        if (m.method === 'Network.responseReceived' && m.params.response.url.includes('/broadcasting/auth')) net.push(`auth ${m.params.response.status}`);
        if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.exception?.description ?? m.params.exceptionDetails.text);
        if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') errors.push(m.params.args.map((a) => a.value ?? a.description).join(' '));
        if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') errors.push(`${m.params.entry.text} ${m.params.entry.url ?? ''}`);
    });
    await Promise.all([s('Page.enable'), s('Runtime.enable'), s('Log.enable'), s('Network.enable')]);
    const { userAgent } = await b.send('Browser.getVersion');
    // Like FiveM's CEF: the user agent carries CitizenFX, so the `fivem` middleware treats us as the tablet.
    await s('Network.setUserAgentOverride', { userAgent: userAgent.replace('HeadlessChrome', 'Chrome') + ' CitizenFX/1.0' });
    await s('Emulation.setDeviceMetricsOverride', { width: 1280, height: 800, deviceScaleFactor: 1, mobile: false });
    const go = async (url) => {
        await s('Page.navigate', { url });
        for (let i = 0; i < 100; i++) {
            const { result } = await s('Runtime.evaluate', { expression: "document.readyState === 'complete' && !!document.querySelector('#app > *')", returnByValue: true });
            if (result.value) break;
            await sleep(100);
        }
        await sleep(800); // fonts, transitions
    };
    const base = new URL(loginUrl ?? 'http://127.0.0.1:8123').origin;
    const pages = {};
    for (const path of o.paths.split(',')) {
        if (path !== '/login' && (loginUrl || o.post.length) && !pages.__loggedIn) {
            await go(loginUrl ?? base + path);
            pages.__loggedIn = true;
            for (const p of o.post) {
                // Form POST like the page would send it (XSRF cookie); the final redirect target must match.
                const [to, body, expect] = p.split('|');
                const post = `fetch(${JSON.stringify(to)}, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '') }, body: ${JSON.stringify(body)} }).then((r) => r.url)`;
                const { result, exceptionDetails } = await s('Runtime.evaluate', { expression: post, awaitPromise: true, returnByValue: true });
                if (exceptionDetails) throw new Error(`${label}: POST ${to}: ${exceptionDetails.exception?.description ?? exceptionDetails.text}`);
                if (expect && new URL(String(result.value)).pathname !== expect) throw new Error(`${label}: POST ${to} ended at ${result.value}, expected ${expect}`);
            }
        }
        const before = errors.length;
        net.length = 0;
        await go(base + path);
        if (net.some((n) => n.startsWith('ws open'))) await sleep(3000); // Reverb: connect, auth, subscribe
        const { result } = await s('Runtime.evaluate', { expression: COLLECT, returnByValue: true });
        const shot = await s('Page.captureScreenshot', { format: 'png' });
        writeFileSync(join(o.out, `${label}${path.replace(/\W+/g, '_')}.png`), Buffer.from(shot.data, 'base64'));
        pages[path] = { ...result.value, errors: errors.slice(before), net: [...net] };
    }
    delete pages.__loggedIn;
    b.close();
    return pages;
}

const off = (a, b, tol) => a.some((v, i) => Math.abs(v - b[i]) > tol);
const [oldRun, newRun] = [await probe('old', o.old, o['login-old']), await probe('new', o.new, o['login-new'])];
let failed = false;
const report = {};
for (const path of Object.keys(oldRun)) {
    const a = oldRun[path];
    const b = newRun[path];
    const diffs = [];
    let layout = 0;
    if (a.els.length !== b.els.length) diffs.push(`element count ${a.els.length} vs ${b.els.length}`);
    for (let i = 0; i < Math.min(a.els.length, b.els.length); i++) {
        const [x, y] = [a.els[i], b.els[i]];
        const what = [];
        if (off(x.box, y.box, 2)) what.push(`box ${x.box} vs ${y.box}`);
        if (x.display !== y.display) what.push(`display ${x.display} vs ${y.display}`);
        // Native <select> arrows differ by a few px between versions, and date inputs default to inline-flex in 103.
        const shown = (x.display === 'none') !== (y.display === 'none');
        if (shown || (off(x.box, y.box, 2) && !x.id.startsWith('select'))) layout++;
        for (const k of ['color', 'background', 'border']) if (off(x[k], y[k], 3)) what.push(`${k} ${x[k]} vs ${y[k]}`);
        if (what.length) diffs.push(`${x.id}: ${what.join('; ')}`);
    }
    report[path] = { ua: a.ua, deadMedia: a.deadMedia, supports: a.supports, errors: a.errors, net: a.net, elements: a.els.length, diffs };
    failed ||= a.errors.length > 0 || a.deadMedia > 0 || layout > 0 || a.els.length !== b.els.length;
    console.log(`\n== ${path}  (${a.ua.match(/Chrome\/[\d.]+/)?.[0]} vs ${b.ua.match(/Chrome\/[\d.]+/)?.[0]})`);
    console.log(`elements ${a.els.length}, differing ${diffs.length} (layout ${layout}), dead @media in 103: ${a.deadMedia}, JS errors in 103: ${a.errors.length}`);
    a.errors.forEach((e) => console.log('  error:', e.split('\n')[0]));
    if (a.net.length) console.log(`  network in 103: ${a.net.join(', ')}`);
    diffs.slice(0, 12).forEach((d) => console.log('  ' + d));
}
console.log('\nChromium 103 support:', JSON.stringify(Object.values(oldRun)[0]?.supports, null, 1));
writeFileSync(join(o.out, 'report.json'), JSON.stringify(report, null, 2));
process.exit(failed ? 1 : 0);
