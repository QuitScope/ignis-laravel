// Rechenwerte zweier Seiten (Alt/Neu) vergleichen. CHROME=<chrome.exe> node styles.mjs <label> <outdir> <loginUrl> <sessionUrl> <dashUrl> '{"login":[css-selektoren],"dash":[...]}'
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [label, out, loginUrl, sessionUrl, dashUrl] = process.argv.slice(2);
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

const PROBE = `(() => { const q = (sel) => { const e = document.querySelector(sel); if (!e) return null; const c = getComputedStyle(e); return { sel, text: (e.innerText||'').trim().slice(0,30), font: c.fontFamily.slice(0,40), size: c.fontSize, weight: c.fontWeight, color: c.color, bg: c.backgroundColor, radius: c.borderRadius, border: c.borderTopColor, w: Math.round(e.getBoundingClientRect().width), h: Math.round(e.getBoundingClientRect().height) }; };
 return JSON.parse(process_sel).map(q).filter(Boolean); })()`;
const sels = JSON.parse(process.argv[7] || '[]');
await s('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'dark' }, { name: 'prefers-reduced-motion', value: 'reduce' }] });
await s('Emulation.setDeviceMetricsOverride', { width: 1920, height: 1080, deviceScaleFactor: 1, mobile: false });
const run = async (url, list) => { await go(url); const { result } = await s('Runtime.evaluate', { expression: PROBE.replace('process_sel', JSON.stringify(JSON.stringify(list))), returnByValue: true }); return result.value; };
const res = { login: await run(loginUrl, sels.login) };
await go(sessionUrl);
res.dash = await run(dashUrl, sels.dash);
console.log(JSON.stringify(res, null, 1));
ws.close(); proc.kill();
