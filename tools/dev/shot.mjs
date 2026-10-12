// Headless Chrome screenshots of the local test server (php -S 127.0.0.1:8099 on the test database).
//   node tools/dev/shot.mjs <teacher cookie> <out dir> '[["name","path or null",width,height (0 = whole page),"js or null"], ...]'
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';
const [cookie, out] = process.argv.slice(2);
mkdirSync(out, { recursive: true });
const chrome = spawn('google-chrome', ['--headless=new', '--remote-debugging-port=9333', '--no-first-run', '--user-data-dir=' + out + '/prof', 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ws;
for (let i = 0; i < 40 && !ws; i++) { await sleep(250); try { const t = await (await fetch('http://127.0.0.1:9333/json')).json(); const p = t.find(x => x.type === 'page'); if (p) ws = new WebSocket(p.webSocketDebuggerUrl); } catch {} }
await new Promise(r => ws.onopen = r);
let id = 0; const pend = new Map(); const logs = [];
ws.onmessage = e => { const m = JSON.parse(e.data); if (m.id && pend.has(m.id)) { pend.get(m.id)(m); pend.delete(m.id); }
  if (m.method === 'Runtime.exceptionThrown') logs.push('EXC ' + JSON.stringify(m.params.exceptionDetails).slice(0, 400));
  if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') logs.push('ERR ' + JSON.stringify(m.params.args).slice(0, 1500)); };
const send = (method, params = {}) => new Promise(r => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');
if (cookie && cookie !== '-') await send('Network.setCookie', { name: 'clicker_teacher', value: cookie, domain: '127.0.0.1', path: '/clicker/' });
const BASE = process.env.BASE || 'http://127.0.0.1:8099/clicker/';
for (const [name, url, w, hh, js] of JSON.parse(process.argv[4] || '[]')) {
  const h = hh || 900;   // height 0 = the whole page
  await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 600 });
  if (url) { await send('Page.navigate', { url: BASE + url }); await sleep(2500); }
  if (js) { const r = await send('Runtime.evaluate', { expression: js, awaitPromise: true }); if (r.result?.exceptionDetails) logs.push(name + ' JS ' + JSON.stringify(r.result.exceptionDetails).slice(0, 300)); await sleep(700); }
  const full = hh ? null : (await send('Page.getLayoutMetrics')).result.cssContentSize;
  const r = await send('Page.captureScreenshot', full ? { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: w, height: Math.min(full.height, 6000), scale: 1 } } : { format: 'png' });
  writeFileSync(`${out}/${name}.png`, Buffer.from(r.result.data, 'base64'));
}
console.log(logs.join('\n') || 'no console errors');
chrome.kill(); process.exit(0);
