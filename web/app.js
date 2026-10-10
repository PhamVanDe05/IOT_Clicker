'use strict';
const $ = id => document.getElementById(id);
const stateNames = {CREATED:'Đã tạo', LOGIN_OPEN:'Đang mở đăng nhập', READY:'Chờ câu hỏi', QUESTION_ACTIVE:'Đang nhận đáp án', FINISHED:'Đã kết thúc'};
let csrf = '', sessionId = '', current = null, refreshing = false, privacy = true, analysisQuestionId = '', sortWrong = false, pendingDelete = null, students = [];
let registerMode = false;
const revealed = new Set(), shownColumns = new Set();
let mode = 'wifi', bridge = null, onlineUids = [];
let remote = false;
const gatewayClient = new GatewayClient((action, data) => api(action, data), message => {
  $('gateway-state').textContent = gatewayClient.uid ? 'Đã kết nối' : 'Chưa kết nối';
  $('gateway-detail').textContent = message;
  $('connect-gateway').textContent = gatewayClient.uid ? 'Kết nối lại Gateway' : 'Kết nối Gateway';
  renderConnection();
});
// busy: a command is in flight, so every control stays locked. actionSeq drops dashboard replies requested before it.
let busy = 0, actionSeq = 0, refreshQueue = Promise.resolve();
const MODES = {
  wifi: {label:'WI-FI', footer:'Chế độ Wi-Fi', detail:'Thiết bị đang kết nối', core:'Wi-Fi', kind:'ESP32 & điện thoại',
    joinTitle:'Mở đăng nhập, sau đó nhập MSSV trên clicker.', joinText:'Điện thoại mở liên kết bên dưới; ESP32 tự hiển thị màn hình nhập khi phòng mở đăng nhập.'},
  ble: {label:'BLE', footer:'Chế độ BLE', detail:'Thiết bị đang kết nối', core:'BLE', kind:'Student Tag nRF52840',
    joinTitle:'Mở đăng nhập, sinh viên nhập MSSV trên Student Tag.', joinText:'Tag hiện Lo khi phòng mở đăng nhập. Nhập MSSV bằng phím số, bấm OK; tag hiện Hi và rung khi thành công.'}
};
$('phone-url').textContent = new URL('phone.html', location.href).href;

function showView() {
  const requested = location.hash.slice(1);
  const view = document.querySelector(`.side-nav a[href="#${CSS.escape(requested)}"]:not([hidden])`) ? requested : 'overview';
  for (const element of document.querySelectorAll('#workspace [data-view]')) element.hidden = element.dataset.view !== view;
  for (const link of document.querySelectorAll('.side-nav a')) {
    const active = link.hash === '#' + view;
    link.classList.toggle('active', active);
    if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
  }
  document.querySelector('.topbar h1').textContent = document.querySelector(`.side-nav a[href="#${CSS.escape(view)}"]`).lastChild.textContent.trim();
  window.scrollTo(0, 0);
}
window.addEventListener('hashchange', showView);
showView();

if (matchMedia('(hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)').matches) {
  document.addEventListener('pointermove', event => {
    document.body.style.setProperty('--glow-x', `${event.clientX}px`);
    document.body.style.setProperty('--glow-y', `${event.clientY}px`);
  }, {passive:true});
}

function deviceKind(uid) { return !uid ? '' : uid.startsWith('NRF-') ? 'nRF' : uid.startsWith('PHONE-') ? 'Điện thoại' : 'ESP32'; }
function showSignedIn(on) { $('workspace').hidden = !on; $('login-panel').hidden = on; $('logout').hidden = !on; $('mode-switch').hidden = !on; }
function updateConfig() {
  const ble = mode === 'ble', visible = ble || !remote;
  $('config').hidden = !visible;
  const nav = document.querySelector('.side-nav a[href="#config"]');
  nav.hidden = !visible;
  nav.lastChild.textContent = ble ? ' Cấu hình Gateway' : ' Cấu hình Wi-Fi';
  $('config-title').textContent = ble ? 'Cấu hình Gateway' : 'Cấu hình Wi-Fi ESP32';
  $('config-description').textContent = ble ? 'Cắm Gateway nRF52840 vào USB của laptop giáo viên.' : 'Một lần gửi cho mọi ESP32 đang chờ cấu hình.';
  $('wifi-config-card').hidden = ble;
  $('gateway-config-card').hidden = !ble;
  if (!visible && location.hash === '#config') location.hash = '#overview';
  if (ble && !$('workspace').hidden) gatewayClient.autoConnect().catch(error => $('gateway-detail').textContent = error.message);
}
function applyMode(next, animate) {
  const changed = next !== mode; mode = next; const text = MODES[mode];
  document.body.dataset.mode = mode;
  for (const button of document.querySelectorAll('#mode-switch button')) button.setAttribute('aria-checked', String(button.dataset.mode === mode));
  $('footer-mode').textContent = text.footer; $('footer-detail').textContent = text.detail; $('device-kind').textContent = text.kind;
  $('conn-mode-label').textContent = text.label; $('radio-core').textContent = text.core;
  $('bridge-label').textContent = mode === 'ble' ? 'Gateway USB' : 'BLE bridge';
  $('join-title').textContent = text.joinTitle; $('join-text').textContent = text.joinText;
  if (animate && changed && !matchMedia('(prefers-reduced-motion: reduce)').matches) { document.body.classList.remove('mode-flash'); void document.body.offsetWidth; document.body.classList.add('mode-flash'); setTimeout(() => document.body.classList.remove('mode-flash'), 900); }
  updateConfig();
  renderConnection();
}
function renderConnection() {
  const up = mode === 'ble' ? !!gatewayClient.uid : !!bridge && Number(bridge.online) === 1;
  $('bridge-state').textContent = up ? 'Đang chạy' : mode === 'ble' ? 'Chưa kết nối' : bridge ? 'Không phản hồi' : 'Chưa chạy';
  $('bridge-state').className = up ? 'good' : mode === 'ble' ? 'bad' : '';
  $('tag-count').textContent = onlineUids.filter(uid => uid.startsWith('NRF-')).length;
  $('wifi-count').textContent = onlineUids.filter(uid => !uid.startsWith('NRF-')).length;
  document.querySelector('.radio-visual').classList.toggle('idle', mode === 'ble' && !up);
}
async function pollStatus() {
  if ($('workspace').hidden) return;
  const data = await api('status', null, 'GET');
  bridge = data.bridge; onlineUids = data.online;
  if (data.mode !== mode) applyMode(data.mode, true); else renderConnection();
}
function notify(message, error = false) { $('notice').textContent = message; $('notice').className = error ? 'notice error' : 'notice'; }
async function api(action, data, method = 'POST') {
  const response = await fetch(method === 'GET' ? `api.php?action=${action}${data ? '&' + new URLSearchParams(data) : ''}` : 'api.php',
    method === 'GET' ? {} : {method, headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action,...data})});
  const result = await response.json();
  if (!response.ok || !result.ok) {
    if (response.status === 401) showSignedIn(false);
    const error = new Error(result.error || 'Không thể kết nối server.');
    error.status = response.status; error.code = result.code;
    throw error;
  }
  return result;
}
async function loadWifiInfo() {
  const info = await api('wifi_info', null, 'GET');
  // Server-side values track the laptop's real Wi-Fi, whatever address this page was opened with.
  if (!$('wifi-ssid').value) $('wifi-ssid').value = info.ssid || '';
  if (!$('wifi-bssid').value) $('wifi-bssid').value = info.bssid || '';
  $('wifi-server-ip').value = info.server_ip || '';
  const missing = !info.ssid || !info.bssid || !info.server_ip;
  $('wifi-detected').textContent = missing
    ? 'Chưa đọc đủ thông tin Wi-Fi của laptop. Mở mục Nâng cao để điền phần còn thiếu.'
    : `Laptop đang ở mạng “${info.ssid}”, IP ${info.server_ip}. Chỉ cần nhập mật khẩu.`;
  if (missing) $('wifi-bssid').closest('details').open = true;
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
function manageList(id, rows, type, name, selectId) {
  const area = $(id); area.replaceChildren();
  if (!rows.length) { area.textContent = 'Chưa có dữ liệu.'; return; }
  for (const item of rows) {
    const row = document.createElement('div'); row.className = 'manage-item';
    const info = document.createElement('div');
    const title = document.createElement('strong'); title.textContent = name(item);
    const detail = document.createElement('small'); detail.textContent = type === 'student' ? `MSSV ${item.student_id}` : type === 'class' ? `${item.count} sinh viên` : type === 'exam' ? `${item.count} câu hỏi` : stateNames[item.state];
    info.append(title, detail); row.append(info);
    const controls = document.createElement('div'); controls.className = 'actions';
    if (type !== 'student') {
      const choose = document.createElement('button'); choose.className = 'secondary'; choose.textContent = 'Chọn';
      choose.addEventListener('click', () => { $(selectId).value = String(item[type === 'class' ? 'class_id' : 'id']); $(selectId).dispatchEvent(new Event('change')); $(selectId).focus(); });
      controls.append(choose);
    }
    const remove = document.createElement('button'); remove.className = 'danger'; remove.textContent = 'Xóa';
    remove.addEventListener('click', () => confirmRemoval(type, item, name(item)));
    controls.append(remove); row.append(controls);
    area.append(row);
  }
}
function renderStudents() {
  const selected = $('class-select').value;
  $('students-heading').textContent = selected ? `Sinh viên lớp ${selected}` : 'Chọn lớp để xem sinh viên';
  if (selected) manageList('manage-students', students.filter(row => row.class_id === selected), 'student', row => `${row.student_no}. ${row.student_name}`, null);
  else $('manage-students').textContent = 'Chọn một lớp ở danh sách trên.';
}
async function bootstrap() {
  const data = await api('bootstrap', null, 'GET');
  remote = !!data.remote;
  $('phone-link').href = data.phone_url; $('phone-url').textContent = data.phone_url;
  bridge = data.bridge; onlineUids = data.devices.filter(row => Number(row.online) && !row.device_uid.startsWith('BRIDGE-')).map(row => row.device_uid);
  applyMode(data.mode, false);
  const classes = data.classes, exams = data.exams, sessions = data.sessions;
  students = data.students;
  selectOptions('class-select', classes, 'class_id', row => `${row.class_id} · ${row.count} sinh viên`, 'Chọn lớp');
  selectOptions('exam-select', exams, 'id', row => `${row.title} · ${row.count} câu`, 'Chọn bộ đề');
  selectOptions('session-select', sessions, 'id', row => `#${row.id} · ${row.title || 'Kiểm tra lớp '+row.class_id} · ${stateNames[row.state]}`, 'Chọn session');
  manageList('manage-classes', data.classes, 'class', row => `Lớp ${row.class_id} · ${row.count} sinh viên`, 'class-select');
  manageList('manage-exams', data.exams, 'exam', row => `${row.title} · ${row.count} câu`, 'exam-select');
  manageList('manage-sessions', data.sessions, 'session', row => `#${row.id} · ${row.title || row.exam_title}`, 'session-select');
  renderStudents();
  $('total-classes').textContent = classes.length; $('total-exams').textContent = exams.length;
  $('roster-ready').textContent = classes.length ? `${classes.length} lớp sẵn sàng` : 'Chưa có dữ liệu'; $('roster-ready').classList.toggle('ready', !!classes.length);
  $('exam-ready').textContent = exams.length ? `${exams.length} bộ đề sẵn sàng` : 'Chưa có dữ liệu'; $('exam-ready').classList.toggle('ready', !!exams.length);
  $('device-status').textContent = `Thiết bị online: ${onlineUids.length}`;
  $('total-devices').textContent = onlineUids.length;
  sessionId = $('session-select').value;
  if (sessionId) await refresh(true); else renderEmpty();
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
function statBox(name, value) {
  const box = document.createElement('div'); box.className = 'stat';
  const label = document.createElement('small'); label.textContent = name + ': ';
  const number = document.createElement('strong'); number.textContent = value;
  box.append(label, number); return box;
}
function distributionRows(distribution, answered) {
  return Object.entries(distribution).map(([letter, count]) => {
    const row = document.createElement('div'); row.className = 'answer-bar';
    const name = document.createElement('span'); name.textContent = letter;
    const track = document.createElement('div'); const fill = document.createElement('i'); fill.style.width = (answered ? count / answered * 100 : 0) + '%'; track.append(fill);
    const total = document.createElement('strong'); total.textContent = count;
    row.append(name, track, total); return row;
  });
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
  const distribution = document.createElement('div'); distribution.className = 'distribution'; distribution.append(...distributionRows(item.distribution, item.answered)); area.append(distribution);
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
  for (const label of ['Đúng/Tổng','Tỷ lệ đúng']) head.append(header(label));
  $('results').tHead.replaceChildren(head); $('results').tBodies[0].replaceChildren();
  const reportRows = new Map(data.report.rows.map(row => [row.student_id, row]));
  for (const student of data.roster) {
    const row = reportRows.get(student.student_id);
    const values = [student.student_no, hidden('id', student.student_id), hidden('name', student.student_name),
      student.device_uid ? Number(student.online) ? 'Đã kết nối' : 'Mất kết nối' : 'Chưa tham gia',
      ...columns.map(column => answerCell(column.no, column.live ? data.live_responses[student.student_id] : row?.answers['Q' + column.no])),
      row ? `${row.correct}/${row.total}` : `0/${data.report.questions.length}`, `${row?.percent ?? 0}%`];
    const tr = tableRow(values); if (!student.device_uid) tr.className = 'absent'; $('results').tBodies[0].append(tr);
  }
}
function render(data) {
  const previous = current; current = data;
  if (previous && previous.session.id !== data.session.id) { revealed.clear(); shownColumns.clear(); analysisQuestionId = ''; }
  const state = data.session.state, active = state === 'QUESTION_ACTIVE', finished = state === 'FINISHED';
  $('room-title').textContent = data.session.title || `Kiểm tra lớp ${data.session.class_id}`;
  $('room-badge').textContent = stateNames[state]; $('room-badge').className = 'state-badge ' + (state === 'LOGIN_OPEN' ? 'ready' : active ? 'active' : finished ? 'done' : '');
  $('session-status').textContent = `Session #${data.session.id} · ${data.session.class_id} · ${stateNames[state]} · AUTO ${data.session.auto_seconds} giây/câu`;
  selectOptions('question-select', data.questions, 'id', row => `Q${row.question_no}${row.closed_at ? ' · đã đóng' : ''}`, 'Chọn câu hỏi');
  const newRoom = !previous || previous.session.id !== data.session.id;
  if (data.question && state === 'QUESTION_ACTIVE') $('question-select').value = data.question.id;
  else if (newRoom) $('question-select').value = data.question?.id ?? data.questions[0]?.id ?? '';
  const chosen = data.questions.find(row => String(row.id) === $('question-select').value);
  const chosenStats = data.analysis.find(row => String(row.question_id) === String(chosen?.id));
  drawQuestion($('question-display'), chosen, false);
  $('question-status').textContent = active && String(chosen?.id) === String(data.question?.id) ? 'Đang mở' : chosen?.closed_at ? 'Đã đóng' : 'Chưa mở';
  $('question-status').className = 'state-badge ' + (active && String(chosen?.id) === String(data.question?.id) ? 'active' : chosen?.closed_at ? 'done' : '');
  const answered = chosenStats?.answered || 0, waiting = chosenStats?.waiting ?? data.roster.length;
  $('projector-count').textContent = `Answered: ${answered} / ${data.roster.length}`; $('projector-waiting').textContent = `Waiting: ${waiting}`;
  $('stats').replaceChildren(statBox('Đăng nhập', `${data.logged_in}/${data.roster.length}`), statBox('Đã trả lời', `${answered}/${data.roster.length}`), statBox('Chưa trả lời', waiting), statBox('Đúng', chosenStats?.correct || 0), statBox('Sai', chosenStats?.wrong || 0), statBox('Tỷ lệ đúng', `${chosenStats?.accuracy || 0}%`));
  const percent = data.roster.length ? Math.round(answered / data.roster.length * 100) : 0;
  $('response-progress').style.width = percent + '%'; $('response-caption').textContent = `${percent}% sinh viên đã trả lời câu đang xem`;
  $('distribution').replaceChildren(...distributionRows(chosenStats?.distribution || {A:0,B:0,C:0,D:0}, answered));
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
  renderResults(data);
  renderAnalysis(data); updateClearButtons();
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
$('toggle-register').addEventListener('click', () => {
  registerMode = !registerMode;
  $('auth-title').textContent = registerMode ? 'Tạo tài khoản giáo viên' : 'Chào mừng trở lại';
  $('auth-submit').textContent = registerMode ? 'Đăng ký' : 'Đăng nhập';
  $('toggle-register').textContent = registerMode ? 'Đã có tài khoản? Đăng nhập' : 'Chưa có tài khoản? Đăng ký';
  $('password').autocomplete = registerMode ? 'new-password' : 'current-password';
});
handle('teacher-login', 'submit', async () => {
  const data = await api(registerMode ? 'teacher_register' : 'teacher_login',
    {username:$('username').value,password:$('password').value});
  csrf = data.csrf; $('password').value = ''; $('teacher-name').textContent = data.username;
  showSignedIn(true); await bootstrap();
  if (mode === 'wifi' && !remote) await loadWifiInfo();
  notify(registerMode ? 'Đã tạo tài khoản.' : 'Đã đăng nhập.');
});
handle('wifi-config-form', 'submit', async () => {
  $('wifi-config-state').textContent = 'Đang phát';
  $('wifi-config-result').textContent = 'Đang gửi cấu hình Wi-Fi cho các ESP32 trong 30 giây…';
  try {
    const result = await api('wifi_provision', {ssid:$('wifi-ssid').value, password:$('wifi-password').value,
      bssid:$('wifi-bssid').value, server_ip:$('wifi-server-ip').value});
    $('wifi-password').value = '';
    $('wifi-config-state').textContent = result.count ? 'Đã nhận' : 'Chưa xác nhận';
    $('wifi-config-result').textContent = result.count ? `${result.count} ESP32 đã xác nhận: ${Object.entries(result.devices).map(([mac,ip]) => `${mac} (${ip})`).join(', ')}` : 'Chưa có ESP32 xác nhận. Kiểm tra OLED, BSSID, Wi-Fi 2,4 GHz và gửi lại.';
    notify($('wifi-config-result').textContent, !result.count);
  } catch (error) {
    $('wifi-config-state').textContent = 'Lỗi';
    $('wifi-config-result').textContent = error.message;
    throw error;
  }
});
handle('connect-gateway', 'click', async () => {
  if (gatewayClient.uid) {
    const port = gatewayClient.port;
    await gatewayClient.disconnect('Đang kết nối lại Gateway…');
    await gatewayClient.connect(port);
  } else await gatewayClient.connectPrompt();
});
if ('serial' in navigator) navigator.serial.addEventListener('connect', () => {
  if (mode === 'ble' && !$('workspace').hidden) gatewayClient.autoConnect().catch(() => {});
});
for (const button of document.querySelectorAll('#mode-switch button')) {
  button.addEventListener('click', async () => {
    if (button.dataset.mode === mode) return;
    try {
      const result = await api('set_mode', {mode:button.dataset.mode}); applyMode(result.mode, true);
      notify(result.mode === 'ble' ? 'Đã chuyển sang BLE.' : 'Đã chuyển sang Wi-Fi.');
      await pollStatus();
    } catch (error) { notify(error.message, true); }
  });
  button.addEventListener('keydown', event => { if (['ArrowLeft','ArrowRight'].includes(event.key)) { event.preventDefault(); const other = document.querySelector(`#mode-switch button:not([data-mode="${button.dataset.mode}"])`); other.focus(); other.click(); } });
}
handle('logout', 'click', async () => { await gatewayClient.disconnect(); await api('logout', {}); location.reload(); });
handle('student-import', 'submit', async () => {
  const file = $('students-file').files[0]; if (file.size > 1024*1024) throw new Error('Danh sách tối đa 1 MB.');
  if (!window.XLSX) throw new Error('Chưa tải được thư viện Excel.');
  const workbook = XLSX.read(await file.arrayBuffer(), {type:'array', raw:true});
  const rows = XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], {header:1,defval:'',raw:false});
  const headers = rows.shift().map(value => String(value).trim().toLowerCase().replace(/^\ufeff/,''));
  const aliases = [['stt','student_no'],['mssv','student_id'],['họ tên','student_name'],['lớp','class_id']];
  const indexes = aliases.map(names => headers.findIndex(header => names.includes(header)));
  if (indexes.some(index => index < 0)) throw new Error('Cần cột STT, MSSV, Họ tên, Lớp.');
  const students = rows.filter(row => row.some(value => String(value).trim())).map(row => Object.fromEntries(aliases.map((names,index) => [names[1], String(row[indexes[index]] ?? '').trim()])));
  const result = await api('import_students', {students}); await bootstrap(); notify(`Đã nhập ${result.imported} sinh viên.`);
});
handle('exam-import', 'submit', async () => { const file = $('exam-file').files[0]; if (file.size > 500000) throw new Error('Đề tối đa 500 KB.'); const result = await api('import_exam', {title:$('exam-title').value,text:await file.text()}); await bootstrap(); $('exam-select').value = result.exam_id; updateClearButtons(); notify(`Đã nhập ${result.imported} câu hỏi.`); });
handle('session-create', 'submit', async () => {
  const result = await api('create_session', {title:$('session-title').value,class_id:$('class-select').value,exam_id:$('exam-select').value,auto_seconds:$('auto-seconds').value});
  // Start the new room clean: nothing from the previous room (selected question, reveals) carries over.
  current = null; revealed.clear(); shownColumns.clear(); analysisQuestionId = '';
  await bootstrap(); $('session-select').value = result.session_id; sessionId = String(result.session_id); await refresh(true);
  notify('Đã tạo buổi học (phòng cũ đã kết thúc). Hãy mở đăng nhập.'); document.querySelector('#room .room-card').scrollIntoView({behavior:'smooth',block:'center'}); });
$('session-select').addEventListener('change', () => { sessionId = $('session-select').value; current = null; revealed.clear(); analysisQuestionId = ''; updateClearButtons(); if (sessionId) refresh().catch(error => notify(error.message, true)); else renderEmpty(); });
$('question-select').addEventListener('change', () => { updateClearButtons(); if (current) render(current); });
$('class-select').addEventListener('change', () => { updateClearButtons(); renderStudents(); });
$('exam-select').addEventListener('change', updateClearButtons);
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
  const rows = [['STT','MSSV','Họ tên',...current.report.questions.map(q => 'Q'+q.question_no),'Đúng/Tổng','Tỷ lệ đúng (%)'],...current.report.rows.map(row => [row.student_no,String(row.student_id),row.student_name,...Object.values(row.answers),`${row.correct}/${row.total}`,row.percent])];
  const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(rows), 'Scores'); XLSX.writeFile(wb, `clicker-session-${sessionId}.xlsx`);
});
$('projector').addEventListener('click', async () => {
  const on = !document.body.classList.contains('projecting');
  document.body.classList.toggle('projecting', on);
  if (on) { revealed.clear(); try { await $('projector-card').requestFullscreen(); } catch { /* CSS presentation still works. */ } }
  else if (document.fullscreenElement) await document.exitFullscreen();
  if (current) render(current);
});
document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) { document.body.classList.remove('projecting'); if (current) render(current); } });
$('delete-dialog').addEventListener('close', async () => {
  if ($('delete-dialog').returnValue !== 'confirm' || !pendingDelete) { pendingDelete = null; return; }
  const {type,item} = pendingDelete; pendingDelete = null;
  const key = type === 'class' ? 'class_id' : type === 'student' ? 'student_id' : type === 'exam' ? 'exam_id' : 'session_id';
  try { await api(`remove_${type}`, {[key]:type === 'class' ? item.class_id : type === 'student' ? item.student_id : item.id,confirmed:true}); await bootstrap(); notify('Đã xóa vĩnh viễn khỏi database.'); }
  catch (error) { notify(error.message, true); }
});
$('copy-phone-link').addEventListener('click', async () => { const link = $('phone-link').href; try { if (navigator.clipboard) await navigator.clipboard.writeText(link); else { const input = document.createElement('textarea'); input.value = link; document.body.append(input); input.select(); if (!document.execCommand('copy')) throw new Error('Không sao chép được.'); input.remove(); } notify('Đã sao chép liên kết điện thoại.'); } catch { notify('Hãy sao chép liên kết hiển thị trên trang.', true); } });
api('auth', null, 'GET').then(async data => { csrf = data.csrf || ''; showSignedIn(data.authenticated); if (data.authenticated) { $('teacher-name').textContent = data.username; await bootstrap(); if (mode === 'wifi' && !remote) await loadWifiInfo(); } notify(data.authenticated ? 'Sẵn sàng.' : 'Nhập tên đăng nhập và mật khẩu 8 số để bắt đầu.'); }).catch(error => notify(error.message, true));
// Limit: poll once per second for one classroom; use SSE when room count grows.
setInterval(() => refresh().catch(error => notify('Mất kết nối: '+error.message, true)), 1000);
setInterval(() => pollStatus().catch(() => {}), 2000);
