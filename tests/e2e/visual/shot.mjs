// Screenshots ueber DevTools (Muster: tests/e2e/cef/probe.mjs).
//   CHROME=<chrome.exe> node shot.mjs <label> <outDir> <loginUrl> <sessionUrl> <dashUrl>
//        [--paths /a,/b?x=1] [--widths 1920,1366,390] [--schemes dark,light] [--post '<pfad>|<body>'] ...
// Ohne --paths: Login (Gast) und Dashboard `dashUrl`, je Schema/Breite. Mit --paths: je Pfad (nach Anmeldung ueber
// sessionUrl und optionalen --post-Formularen, z. B. '/enotf/login|vehicle_id=3&pin=1234') eine Datei
// <label>-p-<slug>-<scheme>-<w>.png; Login/Dashboard entfallen. Hoehen: 1920 1080, 1366 768, 1280 800, sonst 844 (<=500 mobil, ganze Seite).
// Setup: setup.php (Wegwerf-SQLite mit "visual" im Namen), router.php (php -S aus public/), gebautes Bundle (npm run build).
//
// Stichprobe je Modul (dunkel+hell, 1366, visual.admin):
//   /personnel /personnel/{id} /vehicles /vehicles/defects /logbook /lexicon /forms /forms/admin /calendar /mail /mci
//   /enotf/admin /firetab/qm /me/documents /inbox /users /users/roles /settings/system/config /settings/pois
//   /federation/protocols /settings
// Tablet-Beweis (Punkt 3; 1280, Besatzung visual.viewer mit setup.php ... token viewer enotf):
//   /enotf/login /enotf/overview /enotf/p/{enr} /firetab/login /firetab/list /firetab/incidents/{id}
//   /enotf/schnittstelle/login /enotf/print/{enr}
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { parseArgs } from 'node:util';

const { values: o, positionals } = parseArgs({
    allowPositionals: true,
    options: { paths: { type: 'string' }, widths: { type: 'string', default: '1920,1366,390' }, schemes: { type: 'string', default: 'dark,light' }, post: { type: 'string', multiple: true, default: [] } },
});
const [label, out, loginUrl, sessionUrl, dashUrl] = positionals;
const paths = o.paths ? o.paths.split(',') : null;
const exe = process.env.CHROME ?? 'chrome';
mkdirSync(out, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const dir = mkdtempSync(join(tmpdir(), 'visual-shot-'));
const proc = spawn(exe, ['--headless', '--hide-scrollbars', '--no-first-run', '--disable-gpu', '--force-color-profile=srgb', '--font-render-hinting=none', '--remote-debugging-port=0', `--user-data-dir=${dir}`], { stdio: 'ignore' });
const portFile = join(dir, 'DevToolsActivePort');
for (let i = 0; i < 100 && !existsSync(portFile); i++) await sleep(100);
const [port] = readFileSync(portFile, 'utf8').split('\n');
const { webSocketDebuggerUrl } = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json();
const ws = new WebSocket(webSocketDebuggerUrl);
await new Promise((res, rej) => ((ws.onopen = res), (ws.onerror = rej)));
let id = 0;
const pending = new Map();
const errors = [];
ws.onmessage = (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) {
        const { res, rej } = pending.get(m.id);
        pending.delete(m.id);
        m.error ? rej(new Error(m.error.message)) : res(m.result);
    } else if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.exception?.description ?? m.params.exceptionDetails.text);
    else if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') errors.push(`${m.params.entry.text} ${m.params.entry.url ?? ''}`);
};
const send = (method, params = {}, sessionId) => new Promise((res, rej) => { pending.set(++id, { res, rej }); ws.send(JSON.stringify({ id, method, params, sessionId })); });
const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
const s = (m, p) => send(m, p, sessionId);
await Promise.all([s('Page.enable'), s('Runtime.enable'), s('Log.enable')]);

const go = async (url) => {
    await s('Page.navigate', { url });
    for (let i = 0; i < 100; i++) {
        const { result } = await s('Runtime.evaluate', { expression: "document.readyState === 'complete'", returnByValue: true });
        if (result.value) break;
        await sleep(100);
    }
    await s('Runtime.evaluate', { expression: 'document.fonts.ready', awaitPromise: true });
    await sleep(1500); // transitions, reveal animations, Inertia mount
};
const shot = async (name, full = false) => {
    const p = { format: 'png', captureBeyondViewport: full };
    if (full) {
        const { result } = await s('Runtime.evaluate', { expression: 'Math.max(document.documentElement.scrollHeight, document.body.scrollHeight)', returnByValue: true });
        const { cssLayoutViewport } = await s('Page.getLayoutMetrics');
        p.clip = { x: 0, y: 0, width: cssLayoutViewport.clientWidth, height: result.value, scale: 1 };
    }
    const { data } = await s('Page.captureScreenshot', p);
    writeFileSync(join(out, `${label}-${name}.png`), Buffer.from(data, 'base64'));
};

const heights = { 1920: 1080, 1366: 768, 1280: 800 };
const sizes = o.widths.split(',').map(Number).map((w) => [w, heights[w] ?? 844, w <= 500]);
const schemes = o.schemes.split(',');
const media = (scheme) => s('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: scheme }, { name: 'prefers-reduced-motion', value: 'reduce' }] });
const view = (w, h, mobile) => s('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile });
if (!paths) {
    for (const scheme of schemes) {
        await media(scheme);
        for (const [w, h, mobile] of sizes) {
            await view(w, h, mobile);
            await go(loginUrl);
            await shot(`login-${scheme}-${w}`);
        }
    }
}
// Anmeldung (Tablet-Token bzw. Konto) und optionale Formulare (Besatzung)
await go(sessionUrl);
if (process.env.DISMISS) await s('Runtime.evaluate', { expression: "localStorage.setItem('intra_setup_dismissed', '1')" });
for (const p of o.post) {
    const [to, body] = p.split('|');
    const expr = `fetch(${JSON.stringify(to)}, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '') }, body: ${JSON.stringify(body)} }).then((r) => r.url)`;
    const { result } = await s('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
    console.log('post', to, '->', result.value);
}
const slug = (p) => p.replace(/^\//, '').replace(/[^a-zA-Z0-9]+/g, '_') || 'root';
for (const scheme of schemes) {
    await media(scheme);
    for (const [w, h, mobile] of sizes) {
        await view(w, h, mobile);
        if (paths) {
            for (const p of paths) {
                await go(new URL(p, dashUrl).href);
                await shot(`p-${slug(p)}-${scheme}-${w}`, mobile);
            }
        } else {
            await go(dashUrl);
            await shot(`dash-${scheme}-${w}`);
            await shot(`dash-${scheme}-${w}-full`, true);
        }
    }
}
writeFileSync(join(out, `${label}-errors.txt`), errors.join('\n'));
ws.close();
proc.kill();
setTimeout(() => rmSync(dir, { recursive: true, force: true }), 1000);
console.log(label, 'done', errors.length, 'console errors');
