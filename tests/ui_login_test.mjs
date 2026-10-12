// Browser test of the sign-in page (headless Chrome, test database): password sign-in reaches the dashboard,
// a wrong password shows the error, "Quên mật khẩu?" shows the waiting screen, a new account must fill its profile.
//   node tests/ui_login_test.mjs
// Catches page-script errors that the PHP tests cannot see (e.g. a crash before the request is sent).
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, symlinkSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const config = join(root, '.local/test-config.php');
const dsn = execFileSync('php', ['-r', `echo (require '${config}')['dsn'];`]).toString();
if (!dsn.includes('smart_clicker_test')) { console.log('Refusing: test config must point at smart_clicker_test'); process.exit(1); }
const tmp = mkdtempSync(join(tmpdir(), 'clicker-ui-'));
symlinkSync(join(root, 'web'), join(tmp, 'clicker'));
const port = 20000 + Math.floor(Math.random() * 20000), cdp = port + 1;
const php = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', tmp], { env: { ...process.env, CLICKER_CONFIG: config }, stdio: 'ignore' });
const chrome = spawn('google-chrome', ['--headless=new', `--remote-debugging-port=${cdp}`, '--no-first-run', `--user-data-dir=${join(tmp, 'prof')}`, 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const BASE = `http://127.0.0.1:${port}/clicker/`;
let failures = 0;
const check = (label, ok, detail = '') => { console.log((ok ? 'PASS ' : 'FAIL ') + label + (ok || !detail ? '' : ' → ' + JSON.stringify(detail))); if (!ok) failures++; };
const sql = q => execFileSync('php', ['-r', `$c=require '${config}'; $db=new PDO($c['dsn'],$c['db_user'],$c['db_password']); $db->exec(${JSON.stringify(q)});`]);

async function post(body) {
  const r = await fetch(BASE + 'api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  return r.json();
}
let ws, id = 0; const pend = new Map(), errors = [];
const send = (method, params = {}) => new Promise(r => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const run = async js => (await send('Runtime.evaluate', { expression: js, awaitPromise: true, returnByValue: true })).result?.result?.value;
const go = async path => { await send('Page.navigate', { url: BASE + path }); await sleep(2200); };

const user = 'UI_' + Math.random().toString(16).slice(2, 8);
try {
  await sleep(600);
  sql("DELETE FROM request_limit");
  check('tạo tài khoản thử', (await post({ action: 'teacher_register', username: user, password: '12345678' })).ok === true);
  for (let i = 0; i < 40 && !ws; i++) { await sleep(250); try { const t = await (await fetch(`http://127.0.0.1:${cdp}/json`)).json(); const p = t.find(x => x.type === 'page'); if (p) ws = new WebSocket(p.webSocketDebuggerUrl); } catch {} }
  await new Promise(r => ws.onopen = r);
  ws.onmessage = e => { const m = JSON.parse(e.data); if (m.id && pend.has(m.id)) { pend.get(m.id)(m); pend.delete(m.id); }
    if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text); };
  await send('Runtime.enable'); await send('Page.enable');

  await go('landing/login.html');
  await run(`document.getElementById('user').value=${JSON.stringify(user)}; document.getElementById('pass').value='11111111'; document.getElementById('t-form').requestSubmit(); new Promise(r=>setTimeout(r,1200))`);
  check('sai mật khẩu → báo lỗi trên trang', (await run(`document.getElementById('t-err').textContent`)).includes('không đúng'));
  sql("DELETE FROM request_limit");
  await run(`document.getElementById('pass').value='12345678'; document.getElementById('t-form').requestSubmit(); true`);
  await sleep(4000);   // the page shows "Đăng nhập thành công" and moves to the dashboard by itself
  check('đúng mật khẩu → tự chuyển vào bảng điều khiển', !String(await run('location.pathname')).includes('login'), await run('location.href'));
  await sleep(1500);
  check('tài khoản mới: hộp "Hoàn thiện hồ sơ" tự mở', await run(`document.getElementById('profile-dialog').open && document.getElementById('profile-x').hidden`) === true);
  await run(`document.getElementById('pf-name').value='Thầy Thử'; document.getElementById('pf-school').value='THPT Thử'; document.getElementById('pf-subject').value='Tin học'; document.getElementById('profile-form').requestSubmit(); new Promise(r=>setTimeout(r,1500))`);
  check('lưu hồ sơ → hộp đóng, tên hiện ở góc phải', await run(`!document.getElementById('profile-dialog').open && document.getElementById('teacher-name').textContent`) === 'Thầy Thử');
  await run(`document.getElementById('logout').click(); new Promise(r=>setTimeout(r,2500))`);
  await go('landing/login.html');
  await run(`document.getElementById('user').value=${JSON.stringify(user)}; document.getElementById('forgot').click(); new Promise(r=>setTimeout(r,1500))`);
  check('quên mật khẩu → màn chờ cấp phép', await run(`!document.getElementById('pw-wait').hidden && document.getElementById('forms').hidden`) === true);
  await run(`document.getElementById('pw-cancel').click(); new Promise(r=>setTimeout(r,800))`);
  check('huỷ yêu cầu → quay lại ô đăng nhập', await run(`!document.getElementById('forms').hidden`) === true);
  check('không có lỗi script trên trang', errors.length === 0, errors);
} catch (e) { check('chạy test', false, String(e)); }
finally {
  try { execFileSync('php', ['-r', `require '${root}/web/lib.php'; $c=require '${config}'; $db=new PDO($c['dsn'],$c['db_user'],$c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $id=(int)$db->query("SELECT id FROM teacher_account WHERE username='${user}'")->fetchColumn(); if($id){$db->beginTransaction(); delete_teacher($db,$id); $db->commit();}`]); } catch {}
  chrome.kill(); php.kill(); await sleep(300); rmSync(tmp, { recursive: true, force: true });
  console.log(failures ? `\n${failures} FAIL` : '\nALL PASS');
  process.exit(failures ? 1 : 0);
}
