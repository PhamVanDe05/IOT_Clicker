'use strict';
const $ = id => document.getElementById(id);
const stateNames = {CREATED:'Đã tạo', LOGIN_OPEN:'Đang mở đăng nhập', READY:'Chờ câu hỏi', QUESTION_ACTIVE:'Đang nhận đáp án', FINISHED:'Đã kết thúc'};
let csrf = '', sessionId = '', current = null, refreshing = false, privacy = true, analysisQuestionId = '', sortWrong = false, pendingDelete = null, students = [];
const revealed = new Set(), shownColumns = new Set();
// Live tab: results shown on the projected question; switched off whenever another question is shown.
let resultsOn = false, resultsFor = '', revealFresh = false, revealUntil = 0;
// Wi-Fi / BLE belongs to the teacher's room; with no running room the page keeps the last choice and sends it with
// create_session, so the next room starts in it.
let mode = (() => { try { return localStorage.getItem('clicker-mode') === 'ble' ? 'ble' : 'wifi'; } catch { return 'wifi'; } })(), onlineUids = [];
const keepMode = m => { try { localStorage.setItem('clicker-mode', m); } catch {} };
// busy: a command is in flight, so every control stays locked. actionSeq drops dashboard replies requested before it.
let busy = 0, actionSeq = 0, refreshQueue = Promise.resolve();
const MODES = {
  wifi: {label:'WI-FI', footer:'Chế độ Wi-Fi', detail:'Thiết bị đang kết nối', core:'Wi-Fi', kind:'Điện thoại / laptop',
    joinTitle:'Link cho sinh viên', joinText:''},
  ble: {label:'BLE', footer:'Chế độ BLE', detail:'Thiết bị đang kết nối', core:'BLE', kind:'Student Tag nRF52840',
    joinTitle:'Sinh viên nhập MSSV trên Student Tag', joinText:''}
};
$('phone-url').textContent = new URL('phone.html', location.href).href;

// ---- Gateway USB (gateway.js): the Student Tags reach the server through an nRF52840 on this computer's USB.
// Its own request helper keeps the HTTP status and error code, which the gateway turns into the tag's E1–E8.
async function gatewayRequest(action, data) {
  const response = await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body:JSON.stringify({action, ...data})});
  const result = await response.json().catch(() => ({}));
  if (!response.ok || !result.ok) throw Object.assign(new Error(result.error || 'Máy chủ không phản hồi.'), {status:response.status, code:result.code});
  return result;
}
let gwInfo = {phase:'off', uid:'', message:'Chưa kết nối.', tags:0, relayed:0}, wakeLock = null;
const gateway = new GatewayClient(gatewayRequest, info => {
  gwInfo = info; renderGateway(); renderConnection(); keepAwake();
  if (info.phase === 'on') addEventListener('beforeunload', leaveGuard); else removeEventListener('beforeunload', leaveGuard);
});
function renderGateway() {
  const phase = gwInfo.phase, viewing = document.body.classList.contains('viewing');
  $('gw-card').dataset.phase = phase;
  $('gw-badge').textContent = {off:'Chưa kết nối', connecting:'Đang kết nối…', on:'Đã kết nối', error:'Cần xử lý'}[phase];
  $('gw-uid').textContent = gwInfo.uid ? gwInfo.uid.slice(7).match(/../g).join(':') : '—';
  $('gw-tags').textContent = gwInfo.tags; $('gw-relayed').textContent = gwInfo.relayed;
  $('gw-detail').textContent = viewing ? 'Đang xem dữ liệu giáo viên khác (chỉ đọc): không dùng được Gateway.' : gwInfo.message;
  $('gw-connect').hidden = phase === 'on'; $('gw-disconnect').hidden = phase !== 'on';
  $('gw-connect').disabled = phase === 'connecting' || !GatewayClient.supported() || viewing;
  const why = !('serial' in navigator) ? 'browser' : !isSecureContext ? 'http' : '';
  $('gw-unsupported').hidden = !why;
  if (why === 'http') $('gw-unsupported').textContent = 'Gateway USB chỉ chạy trên trang https:// (link công khai của Clicker) hoặc http://localhost ngay trên máy chủ.';
}
// The laptop must not sleep in the middle of a BLE class (the screen lock only holds while this tab is visible).
function keepAwake() {
  const want = gwInfo.phase === 'on' && document.visibilityState === 'visible';
  if (want && !wakeLock) navigator.wakeLock?.request('screen').then(lock => { wakeLock = lock; lock.addEventListener('release', () => { wakeLock = null; }); }).catch(() => {});
  else if (!want && wakeLock && gwInfo.phase !== 'on') wakeLock.release().catch(() => {});
}
document.addEventListener('visibilitychange', keepAwake);
function gatewayAuto() { if (mode === 'ble' && !$('workspace').hidden && !document.body.classList.contains('viewing')) gateway.autoConnect().catch(() => {}); }
navigator.serial?.addEventListener('connect', gatewayAuto);   // plugged back in: reconnect without a prompt
// Closing the tab mid-class stops every tag: ask first, but only while a gateway is connected (a page with an
// unload guard is never kept in the back/forward cache, so it is not left on all the time).
function leaveGuard(event) { if (mode === 'ble') event.preventDefault(); }

// One menu item can hold several steps: "Chuẩn bị" = #classes → #prepare → #room, with the step bar on top.
// #setup opens the step used last (or the first one still empty).
// A page kept from before a deploy (Apache sends no cache headers): reload it once, with a fresh URL, when the server runs another version.
const WEB_VERSION = '20261013-13';
function freshPage(serverVersion) {
  if (!serverVersion || serverVersion === WEB_VERSION) return false;
  try { if (sessionStorage.getItem('clicker-reloaded') === serverVersion) return false; sessionStorage.setItem('clicker-reloaded', serverVersion); } catch { return false; }
  const url = new URL(location.href); url.searchParams.set('r', serverVersion); location.replace(url); return true;
}
function showView() {
  let requested = location.hash.slice(1);
  if (requested === 'setup') {
    let last = ''; try { last = sessionStorage.getItem('clicker-step') || ''; } catch {}
    requested = last || (!$('manage-classes').querySelector('.manage-item') ? 'classes' : !$('manage-exams').querySelector('.manage-item') ? 'prepare' : 'room');
    history.replaceState(null, '', '#' + requested);
  }
  const owner = document.querySelector(`.side-nav a[href="#${CSS.escape(requested)}"]:not([hidden])`)
    || [...document.querySelectorAll('.side-nav a[data-steps]:not([hidden])')].find(a => a.dataset.steps.split(' ').includes(requested));
  const view = owner ? requested : 'overview';
  for (const element of document.querySelectorAll('#workspace [data-view]')) element.hidden = !element.dataset.view.split(' ').includes(view);
  for (const link of document.querySelectorAll('.side-nav a')) {
    const active = link === owner || (!owner && link.hash === '#overview');
    link.classList.toggle('active', active);
    if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
  }
  for (const step of document.querySelectorAll('.stepper .step')) step.classList.toggle('on', step.hash === '#' + view);
  if (owner?.dataset.steps) try { sessionStorage.setItem('clicker-step', view); } catch {}
  document.querySelector('.topbar h1').textContent = (owner || document.querySelector('.side-nav a[href="#overview"]')).querySelector('span').textContent.trim();
  window.scrollTo(0, 0);
}
window.addEventListener('hashchange', showView);
showView();
// #config was the ESP32 Wi-Fi page (ESP32 boards are no longer used): it opens the Thiết bị tab.
if (location.hash === '#config') location.replace('#connection');

if (matchMedia('(hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)').matches) {
  document.addEventListener('pointermove', event => {
    document.body.style.setProperty('--glow-x', `${event.clientX}px`);
    document.body.style.setProperty('--glow-y', `${event.clientY}px`);
  }, {passive:true});
}

function deviceKind(uid) { return !uid ? '' : uid.startsWith('NRF-SIM-') ? 'Tag mô phỏng' : uid.startsWith('NRF-') ? 'nRF' : uid.startsWith('PHONE-') ? 'Điện thoại' : 'ESP32'; }
function showSignedIn(on) {
  // Signed out or session expired: the only login page is the public one.
  if (!on) { location.replace('landing/login.html'); return; }
  $('workspace').hidden = false; $('logout').hidden = false; $('mode-switch').hidden = false;
}
function applyMode(next, animate) {
  const changed = next !== mode; mode = next; keepMode(mode); const text = MODES[mode];
  document.body.dataset.mode = mode;
  for (const button of document.querySelectorAll('#mode-switch button')) button.setAttribute('aria-checked', String(button.dataset.mode === mode));
  $('footer-mode').textContent = text.footer; $('footer-detail').textContent = text.detail; $('device-kind').textContent = text.kind;
  $('conn-mode-label').textContent = text.label; $('radio-core').textContent = text.core;
  $('join-title').textContent = text.joinTitle; $('join-text').textContent = text.joinText; $('join-text').hidden = !text.joinText;
  if (animate && changed && !matchMedia('(prefers-reduced-motion: reduce)').matches) { document.body.classList.remove('mode-flash'); void document.body.offsetWidth; document.body.classList.add('mode-flash'); setTimeout(() => document.body.classList.remove('mode-flash'), 900); }
  renderConnection(); renderGateway();
  if (mode === 'ble') gatewayAuto();
}
function renderConnection() {
  const up = gwInfo.phase === 'on';
  $('bridge-state').textContent = up ? 'Đang chạy' : 'Chưa kết nối';
  $('bridge-state').className = up ? 'good' : mode === 'ble' ? 'bad' : '';
  $('tag-count').textContent = onlineUids.filter(uid => uid.startsWith('NRF-')).length;
  $('wifi-count').textContent = onlineUids.filter(uid => !uid.startsWith('NRF-')).length;
  document.querySelector('.radio-visual').classList.toggle('idle', mode === 'ble' && !up);
}
async function pollStatus() {
  if ($('workspace').hidden) return;
  const data = await api('status', null, 'GET');
  onlineUids = data.online;
  if (data.mode && data.mode !== mode) applyMode(data.mode, true); else renderConnection();
}
// Toast under the top bar: shows the message for a few seconds (errors longer), then fades out.
let noticeTimer = 0;
function notify(message, error = false) {
  const el = $('notice'); el.textContent = message; el.className = 'notice show' + (error ? ' error' : '');
  clearTimeout(noticeTimer); noticeTimer = setTimeout(() => el.classList.remove('show'), error ? 8000 : 3500);
}
async function api(action, data, method = 'POST') {
  const response = await fetch(method === 'GET' ? `api.php?action=${action}${data ? '&' + new URLSearchParams(data) : ''}` : 'api.php',
    method === 'GET' ? {} : {method, headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action,...data})});
  const result = await response.json();
  if (!response.ok || !result.ok) {
    if (response.status === 401) showSignedIn(false);
    throw new Error(result.error || 'Không thể kết nối server.');
  }
  return result;
}
function updateClearButtons() {
  for (const button of document.querySelectorAll('[data-clear]')) button.disabled = !$(button.dataset.clear).value || $(button.dataset.clear).disabled;
}
function selectOptions(id, rows, value, label, placeholder) {
  const element = $(id), selected = element.value;
  const signature = JSON.stringify(rows.map(row => [String(row[value]), label(row)]));
  if (element.dataset.signature === signature) return;
  element.dataset.signature = signature;
  element.replaceChildren(new Option(placeholder, ''));
  for (const row of rows) element.add(new Option(label(row), row[value]));
  element.value = rows.some(row => String(row[value]) === selected) ? selected : '';
  updateClearButtons();
}
function confirmRemoval(type, item, name) {
  pendingDelete = {type, item};
  $('delete-title').textContent = `Xóa ${type === 'class' ? 'lớp' : type === 'student' ? 'sinh viên' : type === 'exam' ? 'bộ đề' : 'session'}?`;
  $('delete-name').textContent = `“${name}”`;
  $('delete-detail').textContent = ({class:'Toàn bộ sinh viên, session và kết quả của lớp sẽ bị xóa vĩnh viễn.',student:'Sinh viên và câu trả lời, điểm của sinh viên trong mọi session sẽ bị xóa vĩnh viễn.',exam:'Bộ đề, các session dùng bộ đề và toàn bộ kết quả liên quan sẽ bị xóa vĩnh viễn.',session:'Session và toàn bộ câu trả lời, điểm trong session sẽ bị xóa vĩnh viễn.'})[type] + ' Thao tác này không thể hoàn tác.';
  $('confirm-delete').textContent = 'XÁC NHẬN XÓA';
  $('delete-dialog').showModal();
}
// Lists of classes, sets and rooms: the one picked for the room form is highlighted ("✓ Đang chọn"), and long lists
// get a search box so the right one is easy to find.
function markPicked(selectId) {
  const value = $(selectId).value;
  for (const row of document.querySelectorAll(`.manage-item[data-select="${selectId}"]`)) {
    const on = row.dataset.value === value, button = row.querySelector('.pick');
    row.classList.toggle('picked', on);
    if (button) { button.textContent = on ? '✓ Đang chọn' : 'Chọn'; button.className = 'pick ' + (on ? 'primary' : 'secondary'); button.setAttribute('aria-pressed', String(on)); }
  }
}
function listSearch(area, count) {
  let box = area.previousElementSibling?.classList.contains('list-search') ? area.previousElementSibling : null;
  if (count <= 5) { box?.remove(); return; }
  if (!box) {
    box = document.createElement('input'); box.type = 'search'; box.className = 'list-search'; box.placeholder = 'Tìm…'; box.setAttribute('aria-label', 'Tìm trong danh sách');
    box.addEventListener('input', () => filterList(area, box.value));
    area.before(box);
  }
  filterList(area, box.value);
}
function filterList(area, query) {
  const q = query.trim().toLowerCase();
  for (const row of area.querySelectorAll('.manage-item')) row.hidden = !!q && !row.dataset.search.includes(q);
}
function manageList(id, rows, type, name, selectId) {
  const area = $(id); area.replaceChildren();
  if (type !== 'student') listSearch(area, rows.length);
  if (!rows.length) { area.textContent = 'Chưa có dữ liệu.'; return; }
  for (const item of rows) {
    const row = document.createElement('div'); row.className = 'manage-item';
    if (type !== 'student') { row.dataset.select = selectId; row.dataset.value = String(item[type === 'class' ? 'class_id' : 'id']); row.dataset.search = String(name(item)).toLowerCase(); }
    const info = document.createElement('div');
    const title = document.createElement('strong'); title.textContent = name(item);
    const detail = document.createElement('small'); detail.textContent = type === 'student' ? `MSSV ${item.student_id}` : type === 'class' ? `${item.count} sinh viên` : type === 'exam' ? `${item.count} câu hỏi` : stateNames[item.state];
    info.append(title, detail); row.append(info);
    const controls = document.createElement('div'); controls.className = 'actions';
    if (type !== 'student') {
      const choose = document.createElement('button'); choose.className = 'pick secondary'; choose.textContent = 'Chọn';
      choose.addEventListener('click', () => {
        $(selectId).value = row.dataset.value; $(selectId).dispatchEvent(new Event('change')); markPicked(selectId);
        if (type !== 'session') notify(`Đã chọn ${type === 'class' ? 'lớp' : 'bộ đề'} "${name(item)}" cho phòng mới.`);
      });
      controls.append(choose);
    }
    if (type === 'class') {
      // Current list of this class, including students approved from the phone page.
      const csv = document.createElement('button'); csv.className = 'secondary'; csv.textContent = 'Tải CSV';
      csv.addEventListener('click', () => {
        const cell = value => { const text = String(value); return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text; };
        const rows = students.filter(row => row.class_id === item.class_id).map(row => [row.student_no, row.student_id, row.student_name, row.class_id]);
        const text = '\ufeff' + [['STT', 'MSSV', 'Họ tên', 'Lớp'], ...rows].map(row => row.map(cell).join(',')).join('\n') + '\n';
        const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([text], {type:'text/csv;charset=utf-8'}));
        link.download = `${item.class_id}.csv`; link.click(); setTimeout(() => URL.revokeObjectURL(link.href), 1000);
      });
      controls.append(csv);
    }
    if (type === 'exam') {
      const edit = document.createElement('button'); edit.className = 'secondary'; edit.textContent = 'Sửa câu';
      edit.addEventListener('click', () => ExamEditor.open(item.id));
      controls.append(edit);
    }
    const remove = document.createElement('button'); remove.className = 'danger'; remove.textContent = 'Xóa';
    remove.addEventListener('click', () => confirmRemoval(type, item, name(item)));
    controls.append(remove); row.append(controls);
    area.append(row);
  }
}
// Tổng quan: the latest rooms, one click opens them in Phòng.
function renderRecent(sessions) {
  const list = sessions.slice(0, 5);
  $('recent-sessions').replaceChildren(...(list.length ? list.map(row => {
    const a = document.createElement('button'); a.type = 'button'; a.className = 'recent-row';
    const left = document.createElement('div');
    const title = document.createElement('b'); title.textContent = row.title || `Kiểm tra lớp ${row.class_id}`;
    const meta = document.createElement('small'); meta.textContent = `${/^lớp\s/i.test(row.class_id) ? row.class_id : 'Lớp ' + row.class_id} · ${row.exam_title || ''} · ${new Date(String(row.created_at).replace(' ', 'T')).toLocaleDateString('vi-VN')}`;
    left.append(title, meta);
    const badge = document.createElement('span'); badge.className = 'state-badge ' + (row.state === 'FINISHED' ? 'done' : 'active'); badge.textContent = stateNames[row.state];
    a.append(left, badge);
    a.addEventListener('click', () => { $('session-select').value = row.id; $('session-select').dispatchEvent(new Event('change')); location.hash = row.state === 'FINISHED' ? '#results-section' : '#room'; });
    return a;
  }) : [Object.assign(document.createElement('p'), {className:'hint', textContent:'Chưa có buổi kiểm tra nào. Tạo buổi đầu tiên ở tab Phòng.'})]));
}
function renderStudents() {
  const selected = $('class-select').value;
  $('students-heading').textContent = selected ? `Sinh viên lớp ${selected}` : 'Chọn lớp để xem sinh viên';
  if (selected) manageList('manage-students', students.filter(row => row.class_id === selected), 'student', row => `${row.student_no}. ${row.student_name}`, null);
  else $('manage-students').textContent = 'Chọn một lớp ở danh sách trên.';
}
async function bootstrap() {
  const data = await api('bootstrap', null, 'GET');
  // Through the internet link the Wi-Fi setup tab cannot reach a board (boards use BACK 5 s instead).
  $('phone-link').href = data.phone_url; $('phone-url').textContent = data.phone_url;
  onlineUids = data.devices.filter(row => Number(row.online) && !row.device_uid.startsWith('BRIDGE-')).map(row => row.device_uid);
  applyMode(data.mode || mode, false);
  const classes = data.classes, exams = data.exams, sessions = data.sessions;
  students = data.students;
  // An empty list looks broken when clicked; say where the data comes from instead.
  selectOptions('class-select', classes, 'class_id', row => Number(row.open) ? `${row.class_id} · tự đăng ký · ${row.count} sinh viên` : `${row.class_id} · ${row.count} sinh viên`, classes.length ? 'Chọn lớp' : 'Chưa có lớp');
  selectOptions('exam-select', exams, 'id', row => `${row.title} · ${row.count} câu`, exams.length ? 'Chọn bộ đề' : 'Chưa có bộ đề');
  $('room-empty').hidden = !!(classes.length && exams.length);
  // "Sinh viên tự làm" needs the pacing column on the server (tools/setup_db.sh)
  $('pacing-select').querySelector('[value=self]').disabled = data.pacing_ready === false;
  if (data.pacing_ready === false) $('pacing-select').value = 'teacher';
  selectOptions('session-select', sessions, 'id', row => `#${row.id} · ${row.title || 'Kiểm tra lớp '+row.class_id} · ${stateNames[row.state]}`, 'Chọn session');
  manageList('manage-classes', data.classes, 'class', row => Number(row.open) ? `${row.class_id} · tự đăng ký` : row.class_id, 'class-select');
  renderRecent(sessions);
  manageList('manage-exams', data.exams, 'exam', row => row.title, 'exam-select');
  ExamEditor.sync(exams);
  manageList('manage-sessions', data.sessions, 'session', row => `#${row.id} · ${row.title || row.exam_title}`, 'session-select');
  for (const id of ['class-select', 'exam-select', 'session-select']) markPicked(id);
  renderStudents();
  $('total-classes').textContent = classes.length; $('total-exams').textContent = exams.length;
  $('roster-ready').textContent = classes.length ? `${classes.length} lớp sẵn sàng` : 'Chưa có dữ liệu'; $('roster-ready').classList.toggle('ready', !!classes.length);
  $('exam-ready').textContent = exams.length ? `${exams.length} bộ đề sẵn sàng` : 'Chưa có dữ liệu'; $('exam-ready').classList.toggle('ready', !!exams.length);
  // step bar: how far the teacher got
  const steps = [['classes', classes.length, 'lớp'], ['sets', exams.length, 'bộ đề'], ['room', sessions.length, 'phòng']];
  for (const [id, n, unit] of steps) { $('step-' + id).textContent = n ? `${n} ${unit}` : 'Chưa có'; $('step-' + id).closest('.step').classList.toggle('done', !!n); }
  $('device-status').textContent = `Thiết bị online: ${onlineUids.length}`;
  $('total-devices').textContent = onlineUids.length;
  sessionId = $('session-select').value;
  if (sessionId) await refresh(true); else renderEmpty();
}
// Projector: shrink the question and answers until nothing overflows (short questions stay huge, long ones fit).
function fitProjector() {
  const area = $('question-display');
  area.style.removeProperty('--fit');
  if (!document.body.classList.contains('projecting')) return;
  const over = () => area.scrollHeight > area.clientHeight + 2 || [...area.querySelectorAll('.option')].some(o => o.scrollHeight > o.clientHeight + 2);
  for (let fit = 1; fit > 0.45 && over(); fit -= 0.07) area.style.setProperty('--fit', fit.toFixed(2));
}
function drawQuestion(area, question, showAnswer) {
  area.replaceChildren();
  if (!question) { area.textContent = 'Chọn câu hỏi để xem nội dung.'; return; }
  const title = document.createElement('h3'); title.textContent = `Q${question.question_no}. ${question.question_text}`; area.append(title);
  const options = document.createElement('div'); options.className = 'options';
  for (const letter of 'ABCD') {
    const option = document.createElement('div'); option.className = 'option' + (showAnswer && question.correct_answer === letter ? ' correct' : '');
    const badge = document.createElement('span'); badge.className = 'option-letter'; badge.textContent = letter;
    const value = document.createElement('span'); value.textContent = question['answer_'+letter];
    option.append(badge, value); options.append(option);
  }
  area.append(options);
  if (showAnswer && question.correct_answer) {
    const answer = document.createElement('p'); answer.className = 'correct-note'; answer.textContent = `Đáp án đúng: ${question.correct_answer}`; area.append(answer);
  }
}
// Bars behind each choice (share of answers), the key marked, and a summary line: the class result for the projector.
// animate = the moment Hiện kết quả was pressed: bars grow one after another, the numbers count up, then the wrong answers
// dim and the right one pops (CSS .reveal-in). Redraws every second show the final state without replaying it.
function drawResults(area, question, stats, rosterSize, animate = false) {
  const answered = stats?.answered || 0, dist = stats?.distribution || {};
  area.classList.toggle('reveal-in', animate);
  [...area.querySelectorAll('.option')].forEach((option, i) => {
    const letter = option.querySelector('.option-letter').textContent, count = Number(dist[letter] || 0);
    const pct = answered ? Math.round(count / answered * 100) : 0;
    option.classList.add('with-result'); option.classList.toggle('correct', question.correct_answer === letter);
    option.style.setProperty('--pct', pct + '%'); option.style.setProperty('--i', i);
    const result = document.createElement('span'); result.className = 'opt-result';
    result.textContent = animate ? '0 · 0%' : `${count} · ${pct}%`;
    option.append(result);
    if (animate) countUp(result, count, pct, 260 + i * 120);
  });
  const accuracy = answered ? Math.round((stats?.correct || 0) / answered * 100) : 0;
  const summary = document.createElement('p'); summary.className = 'result-summary';
  summary.textContent = `${accuracy}% đúng · ${answered}/${rosterSize} đã trả lời`;
  area.querySelector('h3').after(summary);
}
function countUp(el, count, pct, delay) {
  const t0 = performance.now() + delay, ms = 900;
  const step = now => {
    if (now < t0) { requestAnimationFrame(step); return; }
    const k = Math.min(1, (now - t0) / ms), e = 1 - Math.pow(1 - k, 3);
    el.textContent = `${Math.round(count * e)} · ${Math.round(pct * e)}%`; if (k < 1) requestAnimationFrame(step);
  };
  requestAnimationFrame(step);
}
// "Sinh viên tự làm" rooms: no single open question, so the Live card shows each student's progress instead,
// and the side panel shows the class totals and the share of right answers per question.
const selfClock = {end:0, total:0};
function tickSelfClock() {
  const el = $('self-clock'); el.hidden = !selfClock.end;
  if (!selfClock.end) return;
  const left = Math.max(0, Math.round((selfClock.end - Date.now()) / 1000));
  el.textContent = `⏱ ${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
  el.classList.toggle('warn', left <= 30);
}
setInterval(tickSelfClock, 250);
function drawSelf(data, lock, active, finished) {
  for (const id of ['next-question', 'previous-question', 'auto-mode', 'show-results', 'edit-question']) $(id).disabled = true;
  $('start-question').disabled = lock || finished || active;
  const progress = data.self_progress || {}, joined = data.roster.filter(s => s.device_uid);
  const done = joined.filter(s => progress[s.student_id]?.done).length, total = data.report.questions.length;
  const answered = data.report.rows.reduce((n, r) => n + Object.values(r.answers).filter(Boolean).length, 0);
  const correct = data.report.rows.reduce((n, r) => n + r.correct, 0);
  $('projector-count').textContent = `Đã xong: ${done} / ${joined.length}`;
  $('projector-waiting').textContent = `Đang làm: ${joined.length - done}`;
  $('question-status').textContent = finished ? 'Đã kết thúc' : active ? 'Đang làm bài' : 'Chưa bắt đầu';
  // Whole-quiz countdown (questions x seconds from Bắt đầu); the room ends by itself at 0:00.
  selfClock.end = active && data.self_left != null ? Date.now() + data.self_left * 1000 : 0;
  selfClock.total = data.self_total || 0; tickSelfClock();
  const area = $('question-display'); area.replaceChildren();
  const title = document.createElement('h3');
  title.textContent = finished ? 'Bài làm đã kết thúc' : active ? 'Sinh viên đang tự làm bài' : `Sinh viên tự làm · ${data.session.auto_seconds} giây/câu`;
  area.append(title);
  if (!active && !finished) {
    const p = document.createElement('p'); p.className = 'self-hint';
    p.textContent = `Bấm Bắt đầu: mỗi sinh viên thấy câu hỏi trên máy mình, trả lời xong là sang câu kế; quá ${data.session.auto_seconds} giây không chọn thì tự sang câu mới.`;
    area.append(p); return;
  }
  const bar = document.createElement('div'); bar.className = 'self-total';
  bar.innerHTML = '<span></span>'; bar.firstChild.style.width = (joined.length ? done / joined.length * 100 : 0) + '%';
  const list = document.createElement('div'); list.className = 'self-list';
  const projecting = document.body.classList.contains('projecting');
  for (const s of joined) {
    const pr = progress[s.student_id] || {index:1, answered:0, total, done:false};
    const item = document.createElement('div'); item.className = 'self-item' + (pr.done ? ' done' : '');
    const name = document.createElement('b'); name.textContent = projecting || privacy ? `#${s.student_no}` : `${s.student_no}. ${s.student_name}`;
    const track = document.createElement('i'); const fill = document.createElement('span'); fill.style.width = (pr.total ? (pr.done ? 1 : (pr.index - 1) / pr.total) * 100 : 0) + '%'; track.append(fill);
    const label = document.createElement('small'); label.textContent = pr.done ? 'Xong ✓' : `Câu ${pr.index}/${pr.total}`;
    item.append(name, track, label); list.append(item);
  }
  area.append(bar, list);
  // side panel: class totals and right answers per question
  $('stats').replaceChildren(statBox('Đăng nhập', `${data.logged_in}/${data.roster.length}`), statBox('Đã xong', `${done}/${joined.length}`),
    statBox('Đã trả lời', answered), statBox('Đúng', correct), statBox('Tỷ lệ đúng', `${answered ? Math.round(correct / answered * 100) : 0}%`));
  const pct = joined.length && total ? Math.round(answered / (joined.length * total) * 100) : 0;
  $('response-progress').style.width = pct + '%'; $('response-caption').textContent = `${pct}% số câu của cả lớp đã trả lời`;
  $('dist-title').textContent = 'Tỷ lệ đúng từng câu';
  const keys = new Map(data.report.questions.map(q => [`Q${q.question_no}`, q.correct_answer]));
  $('distribution').replaceChildren(columnChart('done' + data.session.id, [...keys.keys()].map(q => {
    const given = data.report.rows.map(r => r.answers[q]).filter(Boolean), right = given.filter(a => a === keys.get(q)).length;
    const pctRight = given.length ? Math.round(right / given.length * 100) : 0;
    return {label: q, value: pctRight, text: given.length ? pctRight + '%' : '—', bad: given.length > 0 && pctRight < 50};
  })));
}
function statBox(name, value) {
  const box = document.createElement('div'); box.className = 'stat';
  const label = document.createElement('small'); label.textContent = name + ': ';
  const number = document.createElement('strong'); number.textContent = value;
  box.append(label, number); return box;
}
// Column chart like the one on the landing page: green columns growing from the baseline, red for the weak or wrong ones,
// labels underneath. items: [{label, value (0-100 height %), text (shown above), bad}]. The first drawing of a chart
// grows the columns; redraws of the same chart (the page refreshes every second) only move them.
const drawnCharts = new Set();
function columnChart(id, items, height = 150) {
  const sig = id + '|' + items.map(item => item.label).join(',');
  const animate = !drawnCharts.has(sig); drawnCharts.add(sig);
  const chart = document.createElement('div'); chart.className = 'col-chart'; chart.style.setProperty('--cc-h', height + 'px');
  const bars = document.createElement('div'); bars.className = 'cc-bars';
  const labels = document.createElement('div'); labels.className = 'cc-lab';
  const grow = [];
  for (const item of items) {
    const col = document.createElement('div'); col.className = 'cc-col';
    const text = document.createElement('b'); text.textContent = item.text;
    const bar = document.createElement('i'); bar.className = item.bad ? 'lo' : '';
    const pct = Math.max(0, Math.min(100, item.value));
    const target = `calc((100% - 22px) * ${pct / 100})`;
    bar.style.height = animate ? '0' : target; if (animate) grow.push(() => { bar.style.height = target; });
    col.append(text, bar); bars.append(col);
    const name = document.createElement('span'); name.textContent = item.label; labels.append(name);
  }
  chart.append(bars, labels);
  if (grow.length) requestAnimationFrame(() => requestAnimationFrame(() => grow.forEach(run => run())));
  return chart;
}
// Answer choices A–D of one question: the tallest column is the most chosen answer, count and share on top. With `key` (the answer is
// shown) the right one is green and the others red; without it every column is green so the key is not given away.
function distributionChart(id, distribution, answered, key = '') {
  const most = Math.max(0, ...Object.values(distribution).map(Number));   // the tallest column fills the chart
  return columnChart(id, Object.entries(distribution).map(([letter, count]) => ({label: letter, value: most ? count / most * 100 : 0,
    text: answered ? `${count} · ${Math.round(count / answered * 100)}%` : String(count), bad: !!key && letter !== key})), 150);
}
function tableRow(values, tag = 'td') {
  const row = document.createElement('tr');
  for (const value of values) { const cell = document.createElement(tag); cell.textContent = value; row.append(cell); }
  return row;
}
function renderEmpty() {
  current = null; analysisQuestionId = ''; revealed.clear();
  $('room-title').textContent = 'Chưa chọn phòng'; $('room-badge').textContent = 'Chờ thiết lập'; $('room-badge').className = 'state-badge';
  $('session-status').textContent = 'Chưa chọn buổi kiểm tra.'; $('question-status').textContent = 'Chưa mở câu';
  showRoomCode(null); renderJoins([]); $('show-results').disabled = true; $('edit-question').disabled = true; resultsOn = false;
  $('question-select').replaceChildren(new Option('Chọn câu hỏi', '')); delete $('question-select').dataset.signature; drawQuestion($('question-display'), null, false);
  $('stats').replaceChildren(); $('distribution').replaceChildren(); $('roster-preview').textContent = 'Chưa chọn phòng.';
  $('participants').textContent = 'Chưa chọn phòng.'; $('participant-count').textContent = '0/0'; $('auto-countdown').hidden = true;
  $('analysis-list').textContent = 'Chưa có câu hỏi đã làm.'; $('analysis-detail').textContent = 'Chưa có câu hỏi đã làm.';
  $('analysis-title').textContent = 'Chọn một câu đã làm'; $('projector-count').textContent = 'Answered: 0 / 0'; $('projector-waiting').textContent = 'Waiting: 0';
  $('response-progress').style.width = '0%'; $('response-caption').textContent = 'Chờ mở câu hỏi.';
  $('results').tHead.replaceChildren(); $('results').tBodies[0].replaceChildren();
  for (const id of ['open-login','start-question','close-question','next-question','previous-question','export-csv','export-xlsx','auto-mode','analysis-answer-toggle']) $(id).disabled = true;
  updateClearButtons(); renderConnection();
}
function renderAnalysis(data) {
  const items = [...data.analysis];
  if (!items.some(item => String(item.question_id) === analysisQuestionId)) analysisQuestionId = String(data.question?.id && items.some(item => String(item.question_id) === String(data.question.id)) ? data.question.id : items[0]?.question_id || '');
  if (sortWrong) items.sort((a,b) => b.wrong_rate - a.wrong_rate || b.wrong - a.wrong || a.question_no - b.question_no);
  const maxWrong = Math.max(0, ...items.filter(item => item.answered).map(item => item.wrong_rate));
  $('sort-wrong').textContent = sortWrong ? 'Sắp xếp: sai nhiều' : 'Sắp xếp: STT'; $('sort-wrong').setAttribute('aria-pressed', String(sortWrong));
  $('analysis-list').replaceChildren();
  if (!items.length) $('analysis-list').textContent = 'Chưa có câu hỏi đã làm.';
  for (const item of items) {
    const button = document.createElement('button'); button.className = 'analysis-item' + (String(item.question_id) === analysisQuestionId ? ' selected' : '') + (item.answered && maxWrong > 0 && item.wrong_rate === maxWrong ? ' high-wrong' : '');
    const number = document.createElement('strong'); number.textContent = `Q${item.question_no}`;
    const rate = document.createElement('span'); rate.textContent = `${item.accuracy}% đúng · ${item.wrong_rate}% sai`;
    button.append(number, rate);
    if (button.classList.contains('high-wrong')) { const flag = document.createElement('small'); flag.textContent = 'Sai nhiều nhất'; button.append(flag); }
    button.addEventListener('click', () => { analysisQuestionId = String(item.question_id); renderAnalysis(current); });
    $('analysis-list').append(button);
  }
  const item = data.analysis.find(row => String(row.question_id) === analysisQuestionId);
  const question = data.questions.find(row => String(row.id) === analysisQuestionId);
  $('analysis-answer-toggle').disabled = !item;
  $('analysis-answer-toggle').textContent = question && revealed.has(String(question.id)) ? 'ẨN ĐÁP ÁN' : 'XEM ĐÁP ÁN';
  $('analysis-answer-toggle').setAttribute('aria-pressed', String(!!question && revealed.has(String(question.id))));
  $('analysis-title').textContent = question ? `Q${question.question_no} · Phân tích` : 'Chọn một câu đã làm';
  if (!item || !question) { $('analysis-detail').textContent = 'Chưa có câu hỏi đã làm.'; return; }
  const area = $('analysis-detail'); drawQuestion(area, question, revealed.has(String(question.id)));
  const summary = document.createElement('div'); summary.className = 'analysis-summary';
  summary.append(statBox('Answered', `${item.answered}/${data.roster.length}`), statBox('Waiting', item.waiting), statBox('Correct', item.correct), statBox('Wrong', item.wrong), statBox('Accuracy', `${item.accuracy}%`));
  area.append(summary);
  const heading = document.createElement('h4'); heading.textContent = 'Lựa chọn A/B/C/D'; area.append(heading);
  area.append(distributionChart('analysis' + question.id, item.distribution, item.answered, revealed.has(String(question.id)) ? question.correct_answer : ''));
}
function renderParticipants(roster) {
  const joined = roster.filter(student => student.device_uid).length;
  $('participant-count').textContent = `${joined}/${roster.length} đã vào`;
  $('participant-count').className = 'state-badge' + (roster.length && joined === roster.length ? ' ready' : '');
  if (!roster.length) { $('participants').textContent = 'Phòng chưa có sinh viên.'; return; }
  $('participants').replaceChildren(...roster.map(student => {
    const row = document.createElement('div'); row.className = 'participant ' + (student.device_uid ? 'joined' : 'absent');
    const no = document.createElement('span'); no.className = 'participant-no'; no.textContent = student.student_no;
    const who = document.createElement('div');
    const name = document.createElement('strong'); name.textContent = student.student_name;
    const id = document.createElement('small'); id.textContent = `MSSV ${student.student_id}`;
    who.append(name, id);
    const device = document.createElement('span'); device.className = 'participant-device';
    if (student.device_uid) {
      const kind = document.createElement('small'); kind.className = 'device-kind'; kind.textContent = deviceKind(student.device_uid);
      device.append(kind);
      if (!Number(student.online)) { const lost = document.createElement('em'); lost.textContent = 'mất kết nối'; device.append(lost); }
    } else device.textContent = 'Chưa vào';
    row.append(no, who, device); return row;
  }));
}
function renderResults(data) {
  // Privacy (on by default) hides identities and answers; each MSSV / Họ tên / Q header toggles its own column.
  const shown = key => !privacy || shownColumns.has(String(key));
  const hidden = (key, value) => shown(key) ? value : '********';
  // One column per question that has been opened: closed ones from the report, the open one live.
  const active = data.session.state === 'QUESTION_ACTIVE' ? data.question : null;
  const columns = data.report.questions.map(row => ({no: row.question_no, label: 'Q' + row.question_no}));
  if (active && !columns.some(column => String(column.no) === String(active.question_no))) columns.push({no: active.question_no, label: `Q${active.question_no} · đang mở`, live: true});
  const answerCell = (no, answer) => !answer ? '—' : shown(no) ? answer : '*';
  const header = (label, key) => {
    const th = document.createElement('th');
    if (!privacy || key === undefined) { th.textContent = label; return th; }
    const on = shownColumns.has(String(key));
    const button = document.createElement('button'); button.type = 'button'; button.className = 'column-toggle' + (on ? ' shown' : '');
    button.dataset.column = key; button.setAttribute('aria-pressed', String(on));
    button.textContent = `${label} ${on ? '◉' : '○'}`; button.title = on ? 'Ẩn cột này' : 'Hiện cột này';
    th.append(button); return th;
  };
  const head = document.createElement('tr');
  head.append(header('STT'), header('MSSV', 'id'), header('Họ tên', 'name'), header('Trạng thái'), ...columns.map(column => header(column.label, column.no)));
  for (const label of ['Đúng/Tổng','Tỷ lệ đúng','Điểm /10']) head.append(header(label));
  $('results').tHead.replaceChildren(head); $('results').tBodies[0].replaceChildren();
  const reportRows = new Map(data.report.rows.map(row => [row.student_id, row]));
  const keys = new Map(data.report.questions.map(q => [String(q.question_no), q.correct_answer]));
  for (const student of data.roster) {
    const row = reportRows.get(student.student_id);
    const values = [student.student_no, hidden('id', student.student_id), hidden('name', student.student_name),
      student.device_uid ? Number(student.online) ? 'Đã kết nối' : 'Mất kết nối' : 'Chưa tham gia',
      ...columns.map(column => answerCell(column.no, column.live ? data.live_responses[student.student_id] : row?.answers['Q' + column.no])),
      row ? `${row.correct}/${row.total}` : `0/${data.report.questions.length}`, `${row?.percent ?? 0}%`, String(row?.score10 ?? 0)];
    const tr = tableRow(values); if (!student.device_uid) tr.className = 'absent';
    // Closed questions: green when the answer matches the key, red when it does not (only where the answer is shown).
    columns.forEach((column, i) => {
      const key = keys.get(String(column.no)), answer = row?.answers['Q' + column.no];
      if (column.live || !key || !answer || !shown(column.no)) return;
      tr.cells[4 + i].className = answer === key ? 'ans-ok' : 'ans-bad';
    });
    $('results').tBodies[0].append(tr);
  }
}
// Six-digit code students type on the public page; the server sends it only while this room holds the devices.
function showRoomCode(code) {
  const text = code ? code.replace(/^(\d{3})(\d{3})$/, '$1 $2') : '';
  $('room-code-box').hidden = !code; $('projector-code').hidden = !code;
  if ($('room-code').textContent !== text) $('room-code').textContent = text;
  const label = code ? `Mã phòng: ${text}` : '';
  if ($('projector-code').textContent !== label) $('projector-code').textContent = label;
  if (!code) closeCodeStage(); else drawCodeStage(code);
}
// 🔍 on the code box: the code fills the screen for the TV (full screen, Clicker green, moving light). Click anywhere, the
// Thu nhỏ button or Esc brings it back; it follows the room's code while open. Only the code: nothing else on the screen.
function drawCodeStage(code) {
  const stage = $('code-stage'), box = $('cs-code');
  if (box.dataset.code !== code) {
    box.dataset.code = code; box.replaceChildren();
    [code.slice(0, 3), code.slice(3)].forEach((part, g) => {
      const group = document.createElement('span'); group.className = 'cs-grp';
      [...part].forEach((digit, i) => { const d = document.createElement('span'); d.className = 'cs-d'; d.textContent = digit; d.style.setProperty('--i', g * 3 + i); group.append(d); });
      box.append(group);
    });
  }
}
// Lobby view of the same screen: QR to the join page (offline, drawn here), how many are in, and each student's name popping
// in as they sign in. Privacy on = "#number" instead of the name, like the rest of the page. The QR shows in every room (a phone scanning it joins a BLE room as a simulated tag).
let stageView = 'lobby'; try { stageView = localStorage.getItem('clicker-stage-view') === 'code' ? 'code' : 'lobby'; } catch { /* default */ }
const lobby = {chips: new Map(), shown: 0};
function setStageView(view) {
  stageView = view; try { localStorage.setItem('clicker-stage-view', view); } catch { /* remembered only when allowed */ }
  $('code-stage').classList.toggle('is-lobby', view === 'lobby');
  $('cs-view').textContent = I18N_T(view === 'lobby' ? 'Chỉ hiện mã' : 'Phòng chờ');
}
function I18N_T(text) { return typeof I18N !== 'undefined' ? I18N.t(text) : text; }
function drawQr(text) {
  const svg = $('cs-qr');
  if (typeof qrcode !== 'function') { $('cs-qr-tile').hidden = true; return; }
  if (svg.dataset.text === text) return; svg.dataset.text = text;
  const qr = qrcode(0, 'M'); qr.addData(text); qr.make();
  const n = qr.getModuleCount(); let d = '';
  for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (qr.isDark(r, c)) d += `M${c} ${r}h1v1h-1z`;
  svg.setAttribute('viewBox', `-2 -2 ${n + 4} ${n + 4}`);
  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path'); path.setAttribute('d', d); path.setAttribute('fill', '#0F2E3A');
  svg.replaceChildren(path);
}
function tweenNumber(el, to, ms = 600) {
  const from = Number(el.textContent) || 0; if (from === to) { el.textContent = to; return; }
  const t0 = performance.now();
  const step = now => { const k = Math.min(1, (now - t0) / ms); el.textContent = Math.round(from + (to - from) * (1 - Math.pow(1 - k, 3))); if (k < 1) requestAnimationFrame(step); };
  requestAnimationFrame(step);
}
function updateLobby(data) {
  if ($('code-stage').hidden || !data) return;
  const roster = data.roster || [], joined = roster.filter(s => s.device_uid);
  $('cs-qr-tile').hidden = typeof qrcode !== 'function'; $('cs-lobby').classList.toggle('no-qr', $('cs-qr-tile').hidden);
  if (!$('cs-qr-tile').hidden && data.room_code) drawQr(`${$('phone-url').textContent}?room=${data.room_code}`);
  tweenNumber($('cs-n'), joined.length); $('cs-of').textContent = '/' + roster.length;
  const MAX = 48, label = s => privacy ? `#${s.student_no}` : String(s.student_name).split(/\s+/).slice(-2).join(' ');
  for (const s of joined) {
    let chip = lobby.chips.get(s.student_id);
    if (!chip) {
      if (lobby.chips.size >= MAX) continue;
      chip = document.createElement('span'); chip.className = 'cs-chip'; chip.style.setProperty('--i', Math.min(lobby.shown++, 24));
      lobby.chips.set(s.student_id, chip); $('cs-chips').append(chip);
    }
    const text = label(s); if (chip.textContent !== text) chip.textContent = text;
  }
  const more = joined.length - lobby.chips.size, tail = $('cs-chips').querySelector('.more');
  if (more > 0) { const el = tail || Object.assign(document.createElement('span'), {className: 'cs-chip more'}); el.textContent = `+${more}`; $('cs-chips').append(el); } else tail?.remove();
}
function openCodeStage() {
  const code = ($('room-code').textContent || '').replace(/\D/g, ''); if (code.length !== 6) return;
  const stage = $('code-stage');
  $('cs-code').dataset.code = ''; drawCodeStage(code);   // rebuild: the digits rise in again every time
  lobby.chips.clear(); lobby.shown = 0; $('cs-chips').replaceChildren(); $('cs-n').textContent = '0'; setStageView(stageView);
  stage.hidden = false; document.body.style.overflow = 'hidden'; updateLobby(current);
  stage.requestFullscreen?.().catch(() => { /* the overlay already covers the page */ });
}
function closeCodeStage() {
  const stage = $('code-stage'); if (stage.hidden) return;
  stage.hidden = true; document.body.style.overflow = '';
  if (document.fullscreenElement === stage) document.exitFullscreen().catch(() => {});
}
$('code-zoom').addEventListener('click', openCodeStage);
$('cs-view').addEventListener('click', e => { e.stopPropagation(); setStageView(stageView === 'lobby' ? 'code' : 'lobby'); });
$('code-stage').addEventListener('click', closeCodeStage);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCodeStage(); });
document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) closeCodeStage(); });
// Phone students asking to join (MSSV not on the list): ✓ adds them to the class and the room, ✗ drops the request.
function renderJoins(rows) {
  rows = rows || [];
  $('join-card').hidden = !rows.length; $('join-badge').hidden = !rows.length;
  $('join-count').textContent = rows.length; $('join-badge').textContent = rows.length;
  const signature = JSON.stringify(rows.map(r => [r.student_id, r.student_name]));
  if ($('join-list').dataset.signature === signature) return;
  $('join-list').dataset.signature = signature;
  $('join-list').replaceChildren(...rows.map(r => {
    const row = document.createElement('div'); row.className = 'join-row';
    const who = document.createElement('div');
    const name = document.createElement('strong'); name.textContent = r.student_name;
    const id = document.createElement('small'); id.textContent = `MSSV ${r.student_id}`;
    who.append(name, id);
    const yes = document.createElement('button'); yes.type = 'button'; yes.className = 'join-yes'; yes.textContent = '✓'; yes.title = 'Duyệt';
    const no = document.createElement('button'); no.type = 'button'; no.className = 'join-no'; no.textContent = '✗'; no.title = 'Từ chối';
    yes.addEventListener('click', () => decideJoin('approve_join', [r.student_id], yes));
    no.addEventListener('click', () => decideJoin('reject_join', [r.student_id], no));
    row.append(who, yes, no); return row;
  }));
}
async function decideJoin(action, ids, button) {
  if (!sessionId) return;
  if (button) button.disabled = true;
  try {
    const result = await api(action, {session_id:sessionId, student_ids:ids});
    const skipped = result.skipped?.length ? ` Bỏ qua: ${result.skipped.join(', ')} (MSSV đã có).` : '';
    notify((action === 'approve_join' ? `Đã duyệt ${result.done} sinh viên.` : `Đã từ chối ${result.done} yêu cầu.`) + skipped);
    await bootstrap();
  } catch (error) { notify(error.message, true); }
  finally { if (button) button.disabled = false; }
}
function render(data) {
  const previous = current; current = data;
  if (previous && previous.session.id !== data.session.id) { revealed.clear(); shownColumns.clear(); analysisQuestionId = ''; }
  const state = data.session.state, active = state === 'QUESTION_ACTIVE', finished = state === 'FINISHED';
  $('room-title').textContent = data.session.title || `Kiểm tra lớp ${data.session.class_id}`;
  showRoomCode(data.room_code); renderJoins(data.join_requests); updateLobby(data);
  $('room-badge').textContent = stateNames[state]; $('room-badge').className = 'state-badge ' + (state === 'LOGIN_OPEN' ? 'ready' : active ? 'active' : finished ? 'done' : '');
  $('session-status').textContent = `Session #${data.session.id} · ${data.session.class_id} · ${stateNames[state]} · AUTO ${data.session.auto_seconds} giây/câu`;
  selectOptions('question-select', data.questions, 'id', row => `Q${row.question_no}${row.closed_at ? ' · đã đóng' : ''}`, 'Chọn câu hỏi');
  const newRoom = !previous || previous.session.id !== data.session.id;
  if (data.question && state === 'QUESTION_ACTIVE') $('question-select').value = data.question.id;
  else if (newRoom) $('question-select').value = data.question?.id ?? data.questions[0]?.id ?? '';
  const chosen = data.questions.find(row => String(row.id) === $('question-select').value);
  const chosenStats = data.analysis.find(row => String(row.question_id) === String(chosen?.id));
  // While the reveal animation plays (about 2 s) the question is not redrawn, so the page's 1-second refresh cannot cut it short.
  if (!(resultsOn && !revealFresh && Date.now() < revealUntil && String(chosen?.id ?? '') === resultsFor)) {
    drawQuestion($('question-display'), chosen, false);
    if (String(chosen?.id ?? '') !== resultsFor) { resultsOn = false; resultsFor = String(chosen?.id ?? ''); }
    if (resultsOn && chosen) { drawResults($('question-display'), chosen, chosenStats, data.roster.length, revealFresh); if (revealFresh) revealUntil = Date.now() + 2300; }
    revealFresh = false;
  }
  fitProjector();
  $('show-results').textContent = resultsOn ? 'Ẩn kết quả' : 'Hiện kết quả'; $('show-results').setAttribute('aria-pressed', String(resultsOn));
  $('show-results').disabled = !chosen; $('edit-question').disabled = !chosen;
  $('question-status').textContent = active && String(chosen?.id) === String(data.question?.id) ? 'Đang mở' : chosen?.closed_at ? 'Đã đóng' : 'Chưa mở';
  $('question-status').className = 'state-badge ' + (active && String(chosen?.id) === String(data.question?.id) ? 'active' : chosen?.closed_at ? 'done' : '');
  const answered = chosenStats?.answered || 0, waiting = chosenStats?.waiting ?? data.roster.length;
  $('projector-count').textContent = `Answered: ${answered} / ${data.roster.length}`; $('projector-waiting').textContent = `Waiting: ${waiting}`;
  $('stats').replaceChildren(statBox('Đăng nhập', `${data.logged_in}/${data.roster.length}`), statBox('Đã trả lời', `${answered}/${data.roster.length}`), statBox('Chưa trả lời', waiting), statBox('Đúng', chosenStats?.correct || 0), statBox('Sai', chosenStats?.wrong || 0), statBox('Tỷ lệ đúng', `${chosenStats?.accuracy || 0}%`));
  const percent = data.roster.length ? Math.round(answered / data.roster.length * 100) : 0;
  $('response-progress').style.width = percent + '%'; $('response-caption').textContent = `${percent}% sinh viên đã trả lời câu đang xem`;
  $('distribution').replaceChildren(distributionChart('live' + (chosen?.id ?? ''), chosenStats?.distribution || {A:0,B:0,C:0,D:0}, answered, chosen && revealed.has(String(chosen.id)) ? chosen.correct_answer : '')); $('dist-title').textContent = 'Phân bố đáp án';
  $('roster-preview').replaceChildren(...data.roster.map(student => {
    const row = document.createElement('div'); row.className = 'roster-person' + (!student.device_uid ? ' absent' : '');
    const name = document.createElement('strong'); name.textContent = `${student.student_no}. ${student.student_name}`;
    const status = document.createElement('span'); status.textContent = student.device_uid ? Number(student.online) ? '● Đã kết nối' : '○ Mất kết nối' : '○ Chưa tham gia';
    if (student.device_uid) { const kind = document.createElement('small'); kind.className = 'device-kind'; kind.textContent = deviceKind(student.device_uid); status.append(kind); }
    row.append(name, status); return row;
  }));
  renderParticipants(data.roster);
  $('device-status').textContent = `Thiết bị online: ${data.online_devices}`; $('total-devices').textContent = data.online_devices;
  const hasUnused = data.questions.some(row => row.opened_at === null);
  const prior = data.questions.filter(row => row.opened_at !== null && Number(row.question_no) < Number(chosen?.question_no));
  // AUTO drives the room on the server: no manual moves until it is switched off. busy locks everything during a command.
  const auto = Number(data.session.auto_mode) === 1, lock = busy > 0;
  // A finished room can be tested again: Mở đăng nhập then starts a new round with the same class and exam.
  $('open-login').disabled = lock || auto || active || state === 'LOGIN_OPEN';
  $('open-login').textContent = finished ? 'Kiểm tra lại · mở đăng nhập' : 'Mở đăng nhập';
  $('start-question').disabled = lock || auto || finished || active || !hasUnused;
  $('next-question').disabled = lock || auto || !active || !hasUnused;
  $('close-question').disabled = lock || finished;
  $('previous-question').disabled = lock || active || !prior.length;
  $('question-select').disabled = active; updateClearButtons();
  $('auto-mode').disabled = lock || (!auto && (finished || (!active && !hasUnused)));
  $('auto-mode').textContent = auto ? 'AUTO: ĐANG CHẠY · bấm để dừng' : 'AUTO'; $('auto-mode').setAttribute('aria-pressed', String(auto));
  $('auto-mode').classList.toggle('primary', auto); $('auto-mode').classList.toggle('ghost', !auto);
  $('auto-countdown').hidden = !auto;
  if (auto) $('auto-countdown').textContent = `AUTO · Q${data.question?.question_no ?? '?'} · còn ${data.auto_remaining ?? 0} giây`;
  $('export-csv').disabled = false; $('export-xlsx').disabled = false;
  $('privacy-mode').textContent = privacy ? 'Privacy: BẬT' : 'Privacy'; $('privacy-mode').setAttribute('aria-pressed', String(privacy));
  $('projector').textContent = document.body.classList.contains('projecting') ? 'Thoát trình chiếu' : 'Chế độ trình chiếu';
  if (data.pacing === 'self') drawSelf(data, lock, active, finished); else { selfClock.end = 0; tickSelfClock(); }
  document.body.classList.toggle('self-room', data.pacing === 'self');
  renderResults(data);
  renderAnalysis(data); updateClearButtons(); window.AIReview?.students(data.roster, data.report);
  if (data.mode && data.mode !== mode) applyMode(data.mode, true); else renderConnection();
}
async function refresh(force = false) {
  // Timer ticks skip while one is running; commands queue a fresh read so they always render their own result.
  if (!sessionId || $('workspace').hidden || (refreshing && !force)) return;
  const run = async () => {
    refreshing = true; const requested = sessionId, seq = actionSeq;
    try { const result = await api('dashboard', {session_id:requested}, 'GET'); if (requested === sessionId && (force || (seq === actionSeq && !busy))) render(result); }
    finally { refreshing = false; }
  };
  refreshQueue = refreshQueue.then(run, run); return refreshQueue;
}
const CONTROLS = ['open-login','start-question','next-question','close-question','auto-mode'];
function handle(id, event, callback) {
  $(id).addEventListener(event, async e => {
    e.preventDefault(); const button = e.submitter || e.currentTarget; if (busy && CONTROLS.includes(id)) return;
    busy++; actionSeq++; button.disabled = true; if (current) render(current);
    try { await callback(e); } catch (error) { notify(error.message, true); }
    finally { busy--; actionSeq++; button.disabled = false; if (current && !$('workspace').hidden) render(current); }
  });
}
for (const button of document.querySelectorAll('#mode-switch button')) {
  button.addEventListener('click', async () => {
    if (button.dataset.mode === mode) return;
    try {
      const result = await api('set_mode', {mode:button.dataset.mode}); keepMode(result.mode); applyMode(result.mode, true);
      notify(result.mode === 'ble' ? 'Đã chuyển sang BLE.' : 'Đã chuyển sang Wi-Fi.');
      await pollStatus();
    } catch (error) { notify(error.message, true); }
  });
  button.addEventListener('keydown', event => { if (['ArrowLeft','ArrowRight'].includes(event.key)) { event.preventDefault(); const other = document.querySelector(`#mode-switch button:not([data-mode="${button.dataset.mode}"])`); other.focus(); other.click(); } });
}
handle('gw-connect', 'click', async () => {
  try { await gateway.connectPrompt(); notify('Đã kết nối Gateway USB.'); }
  catch (error) { if (error.name !== 'NotFoundError') notify(error.message, true); }   // NotFoundError = closed the port picker
});
handle('gw-disconnect', 'click', async () => { await gateway.disconnect('off', 'Đã ngắt Gateway. Tag sẽ hiện nC sau vài giây.'); });
handle('logout', 'click', async () => { await gateway.disconnect(); await api('logout', {}); location.replace('landing/'); });
handle('student-import', 'submit', async () => {
  const file = $('students-file').files[0]; if (file.size > 1024*1024) throw new Error('Danh sách tối đa 1 MB.');
  if (!window.XLSX) throw new Error('Chưa tải được thư viện Excel.');
  // CSV is read as UTF-8 text: as raw bytes SheetJS takes it for Latin-1 and "Họ tên" no longer matches.
  const workbook = /\.csv$/i.test(file.name) ? XLSX.read(await file.text(), {type:'string', raw:true}) : XLSX.read(await file.arrayBuffer(), {type:'array', raw:true});
  const rows = XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], {header:1,defval:'',raw:false});
  if (!rows.length) throw new Error('File không có dữ liệu.');
  // Headers compared without accents or spaces, so "Họ và tên", "HO TEN", "Lớp học" also work.
  const norm = value => String(value).replace(/^\ufeff/,'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/đ/gi,'d').toLowerCase().replace(/[^a-z0-9]/g,'');
  const headers = rows.shift().map(norm);
  const aliases = [['stt','no','sothutu','student_no'],['mssv','masv','masosinhvien','studentid','student_id'],['hoten','hovaten','ten','name','fullname','student_name'],['lop','lophoc','class','classid','class_id']];
  const indexes = aliases.map(names => headers.findIndex(header => names.includes(header)));
  if (indexes.some(index => index < 0)) throw new Error('Cần cột STT, MSSV, Họ tên, Lớp.');
  const students = rows.filter(row => row.some(value => String(value).trim())).map(row => Object.fromEntries(aliases.map((names,index) => [names.at(-1), String(row[indexes[index]] ?? '').trim()])));
  const result = await api('import_students', {students}); await bootstrap(); notify(`Đã nhập ${result.imported} sinh viên.`);
});
// Segmented buttons switch the panes of their own card only (Lớp học and Bộ đề each have one).
for (const tab of document.querySelectorAll('[data-seg]')) tab.addEventListener('click', () => {
  const card = tab.closest('.card');
  for (const t of card.querySelectorAll('[data-seg]')) t.setAttribute('aria-selected', String(t === tab));
  for (const pane of card.querySelectorAll('[data-segpane]')) pane.hidden = pane.dataset.segpane !== tab.dataset.seg;
});
handle('open-class-form', 'submit', async () => {
  const name = $('open-class-name').value.trim(); if (!name) throw new Error('Nhập tên lớp.');
  await api('class_create', {class_id:name}); $('open-class-name').value = '';
  await bootstrap(); $('class-select').value = name; updateClearButtons();
  notify(`Đã tạo lớp ${name}.`);
});
handle('session-create', 'submit', async () => {
  const result = await api('create_session', {mode, title:$('session-title').value,class_id:$('class-select').value,exam_id:$('exam-select').value,auto_seconds:$('auto-seconds').value,pacing:$('pacing-select').value});
  // Start the new room clean: nothing from the previous room (selected question, reveals) carries over.
  current = null; revealed.clear(); shownColumns.clear(); analysisQuestionId = '';
  await bootstrap(); $('session-select').value = result.session_id; sessionId = String(result.session_id); await refresh(true);
  notify('Đã tạo buổi học (phòng cũ đã kết thúc). Hãy mở đăng nhập.'); document.querySelector('#room .room-card').scrollIntoView({behavior:'smooth',block:'center'}); });
$('session-select').addEventListener('change', () => { sessionId = $('session-select').value; current = null; revealed.clear(); analysisQuestionId = ''; updateClearButtons(); if (sessionId) refresh().catch(error => notify(error.message, true)); else renderEmpty(); });
$('question-select').addEventListener('change', () => { updateClearButtons(); if (current) render(current); });
$('class-select').addEventListener('change', () => { updateClearButtons(); renderStudents(); });
$('exam-select').addEventListener('change', updateClearButtons);
// whichever way a class, set or room gets picked (list button, room form, × clear), its row in the list follows
for (const id of ['class-select', 'exam-select', 'session-select']) $(id).addEventListener('change', () => markPicked(id));
for (const button of document.querySelectorAll('[data-clear]')) button.addEventListener('click', () => { const select = $(button.dataset.clear); select.value = ''; select.dispatchEvent(new Event('change')); });
const MESSAGES = {open_login:'Đã mở đăng nhập.', start_question:'Đã đóng đăng nhập và mở câu đầu tiên.', next_question:'Đã chuyển sang câu tiếp theo.', close_question:'Đã kết thúc buổi kiểm tra.'};
for (const [id,action] of [['start-question','start_question'],['next-question','next_question'],['close-question','close_question']]) {
  handle(id, 'click', async () => {
    if (!sessionId) throw new Error('Hãy chọn buổi học.');
    await api(action, {session_id:sessionId});
    // Ending the room changes its label in the session list; other commands only need the room itself.
    if (action === 'close_question') await bootstrap(); else await refresh(true);
    notify(MESSAGES[action]);
  });
}
handle('previous-question', 'click', async () => { const chosen = current.questions.find(row => String(row.id) === $('question-select').value); const prior = current.questions.filter(row => row.opened_at !== null && Number(row.question_no) < Number(chosen.question_no)); $('question-select').value = prior.at(-1).id; render(current); });
function toggleAnswer(questionId) { const key = String(questionId); if (revealed.has(key)) revealed.delete(key); else revealed.add(key); if (current) render(current); }
$('analysis-answer-toggle').addEventListener('click', () => { if (analysisQuestionId) toggleAnswer(analysisQuestionId); });
$('privacy-mode').addEventListener('click', () => { privacy = !privacy; shownColumns.clear(); if (current) render(current); });
$('results').tHead.addEventListener('click', event => {
  const button = event.target.closest('[data-column]'); if (!button) return;
  const key = button.dataset.column; if (shownColumns.has(key)) shownColumns.delete(key); else shownColumns.add(key);
  if (current) render(current);
});
handle('open-login', 'click', async () => {
  if (!sessionId) throw new Error('Hãy chọn buổi học.');
  if (current?.session.state !== 'FINISHED') { await api('open_login', {session_id:sessionId}); await refresh(true); notify(MESSAGES.open_login); return; }
  // Finished room: a new round (same class and exam) opens for login; the old round keeps its results.
  const result = await api('retest_session', {session_id:sessionId});
  current = null; revealed.clear(); shownColumns.clear(); analysisQuestionId = '';
  await bootstrap(); $('session-select').value = result.session_id; sessionId = String(result.session_id); await refresh(true);
  notify('Đã tạo lượt kiểm tra mới và mở đăng nhập. Kết quả lượt trước vẫn được giữ.');
});
handle('auto-mode', 'click', async () => {
  if (!sessionId) throw new Error('Hãy chọn buổi học.');
  const on = Number(current?.session.auto_mode) === 1;
  await api(on ? 'auto_stop' : 'auto_start', {session_id:sessionId}); await refresh(true);
  notify(on ? 'Đã dừng AUTO. Câu đang mở vẫn mở, điều khiển tay.' : `Đã bật AUTO: mỗi câu ${current.session.auto_seconds} giây, hết câu cuối tự kết thúc.`);
});
// Hide live statistics while projecting; remembered per browser only.
function showInsights(on) {
  $('insights-body').hidden = !on; $('insights-hidden').hidden = on;
  $('toggle-insights').setAttribute('aria-pressed', String(on)); $('toggle-insights').title = on ? 'Ẩn thống kê' : 'Hiện thống kê';
  $('toggle-insights').classList.toggle('off', !on);
  try { localStorage.setItem('clicker-insights', on ? 'show' : 'hide'); } catch { /* storage unavailable */ }
}
$('toggle-insights').addEventListener('click', () => showInsights($('insights-body').hidden));
try { showInsights(localStorage.getItem('clicker-insights') !== 'hide'); } catch { showInsights(true); }
$('sort-wrong').addEventListener('click', () => { sortWrong = !sortWrong; if (current) renderAnalysis(current); });
handle('export-csv', 'click', async () => { if (!sessionId) throw new Error('Hãy chọn buổi học.'); location.href = `api.php?action=export&session_id=${sessionId}`; });
handle('export-xlsx', 'click', async () => {
  if (!sessionId) throw new Error('Hãy chọn buổi học.'); await refresh();
  const rows = [['STT','MSSV','Họ tên',...current.report.questions.map(q => 'Q'+q.question_no),'Đúng/Tổng','Tỷ lệ đúng (%)','Điểm (thang 10)'],...current.report.rows.map(row => [row.student_no,String(row.student_id),row.student_name,...Object.values(row.answers),`${row.correct}/${row.total}`,row.percent,row.score10])];
  const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(rows), 'Scores'); XLSX.writeFile(wb, `clicker-session-${sessionId}.xlsx`);
});
$('join-approve-all').addEventListener('click', () => decideJoin('approve_join', 'all', $('join-approve-all')));
$('join-reject-all').addEventListener('click', () => { if (confirm(I18N.t('Từ chối tất cả yêu cầu đang chờ?'))) decideJoin('reject_join', 'all', $('join-reject-all')); });
$('show-results').addEventListener('click', () => { resultsOn = !resultsOn; revealFresh = resultsOn; revealUntil = 0; if (current) render(current); });
$('edit-question').addEventListener('click', () => {
  const id = $('question-select').value;
  if (current && id) ExamEditor.quickEdit(Number(current.session.exam_id), Number(id));
});
$('projector').addEventListener('click', async () => {
  const on = !document.body.classList.contains('projecting');
  document.body.classList.toggle('projecting', on);
  if (on) { revealed.clear(); try { await $('projector-card').requestFullscreen(); } catch { /* CSS presentation still works. */ } }
  else if (document.fullscreenElement) await document.exitFullscreen();
  if (current) render(current);
});
window.addEventListener('resize', fitProjector);
document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) { document.body.classList.remove('projecting'); if (current) render(current); } });
$('delete-dialog').addEventListener('close', async () => {
  if ($('delete-dialog').returnValue !== 'confirm' || !pendingDelete) { pendingDelete = null; return; }
  const {type,item} = pendingDelete; pendingDelete = null;
  const key = type === 'class' ? 'class_id' : type === 'student' ? 'student_id' : type === 'exam' ? 'exam_id' : 'session_id';
  try { await api(`remove_${type}`, {[key]:type === 'class' ? item.class_id : type === 'student' ? item.student_id : item.id,confirmed:true}); await bootstrap(); notify('Đã xóa vĩnh viễn khỏi database.'); }
  catch (error) { notify(error.message, true); }
});
$('copy-phone-link').addEventListener('click', async () => { const link = $('phone-link').href; try { if (navigator.clipboard) await navigator.clipboard.writeText(link); else { const input = document.createElement('textarea'); input.value = link; document.body.append(input); input.select(); if (!document.execCommand('copy')) throw new Error('Không sao chép được.'); input.remove(); } notify('Đã sao chép liên kết điện thoại.'); } catch { notify('Hãy sao chép liên kết hiển thị trên trang.', true); } });
// Waiting screen (logo C spinning on the brand green): shown while the page loads and when the network drops for
// a couple of polls in a row; follows the projector into full screen so the class sees it too.
let netFails = 0;
function netWait(on, text) {
  const box = $('net-wait');
  if (text) $('net-wait-text').textContent = text;
  const host = document.fullscreenElement || document.body;
  if (on && box.parentElement !== host) host.append(box);
  box.classList.toggle('on', on);
}
function netOk() { netFails = 0; netWait(false); }
function netFail() { if (++netFails >= 2) netWait(true, 'Mạng chậm, đang kết nối lại…'); }
api('auth', null, 'GET').then(async data => { if (freshPage(data.web)) return; csrf = data.csrf || ''; showSignedIn(data.authenticated); if (data.authenticated) { $('teacher-name').textContent = data.username; $('nav-admin').hidden = !data.is_admin; Account.init(data); $('view-banner').hidden = !data.view_as; $('view-name').textContent = data.view_as || ''; document.body.classList.toggle('viewing', !!data.view_as); if (data.is_admin && location.hash === '#admin-section') showView(); await bootstrap(); } if (data.authenticated) $('notice').classList.remove('show'); netOk(); }).catch(error => { notify(error.message, true); netWait(true, 'Chưa kết nối được máy chủ, đang thử lại…'); setTimeout(() => location.reload(), 4000); });
// Limit: poll once per second for one classroom; use SSE when room count grows.
setInterval(() => refresh().catch(error => { notify('Mất kết nối: '+error.message, true); netFail(); }), 1000);
setInterval(() => pollStatus().then(() => { if (netFails) netOk(); }).catch(() => netFail()), 2000);
