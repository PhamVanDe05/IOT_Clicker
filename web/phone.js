'use strict';
// Student page for any browser (phone, laptop). Wi-Fi mode: room code -> MSSV (+ name if not on the list) ->
// waiting screen -> A/B/C/D. A BLE room: the page becomes a simulated Student Tag that really logs in and answers.
// Several rooms can run at once: the room code is sent with every request, so the server knows which room this is.
// Server side: phone-api.php (poll / login / register / answer) and room.php (room code check).
const $ = id => document.getElementById(id);
const S = {uid:'', csrf:'', online:false, offlineSince:0, data:null, mode:'wifi', pending:null, busy:false,
  roomOk:false, roomSession:null, room:'', selected:'', name:'', lastQuestion:null, selfDone:null, deadline:0, pollAt:0};
// A page kept from before a deploy (Apache sends no cache headers): reload it once, with a fresh URL, when the server runs another version.
const WEB_VERSION = '20261013-13';
function freshPage(serverVersion) {
  if (!serverVersion || serverVersion === WEB_VERSION) return false;
  try { if (sessionStorage.getItem('clicker-reloaded') === serverVersion) return false; sessionStorage.setItem('clicker-reloaded', serverVersion); } catch { return false; }
  const url = new URL(location.href); url.searchParams.set('r', serverVersion); location.replace(url); return true;
}
const SCREENS = ['s-code', 's-info', 's-wait', 's-question', 's-done', 's-ble'];

function show(id) {
  for (const s of SCREENS) $(s).hidden = s !== id;
  document.body.classList.toggle('waiting', id === 's-wait');
}
function toast(text, ok = false) {
  const t = $('toast'); t.textContent = text; t.className = 'toast' + (ok ? ' ok' : ''); t.hidden = !text;
  clearTimeout(toast.timer); if (text) toast.timer = setTimeout(() => { t.hidden = true; }, ok ? 2200 : 4500);
}
function wait(title, sub) { $('wait-title').textContent = title; $('wait-sub').textContent = sub || ''; show('s-wait'); }

/* ---------- server ---------- */
const storageKey = () => 'clicker-pending-' + S.uid;
function savePending() { try { if (S.pending) localStorage.setItem(storageKey(), JSON.stringify(S.pending)); else localStorage.removeItem(storageKey()); } catch {} }
async function request(payload) {
  const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 5000);
  try {
    const response = await fetch('phone-api.php', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':S.csrf}, body:JSON.stringify(S.room ? {...payload, room:S.room} : payload), signal:controller.signal});
    const result = await response.json(); S.online = true;
    if (!response.ok || !result.ok) {
      const error = new Error(result.error || 'Server chưa xử lý được.'); error.code = result.code;
      error.permanent = response.status >= 400 && response.status < 500 && ![408, 429].includes(response.status); throw error;
    }
    return result;
  } catch (error) { if (!error.permanent) S.online = false; throw error; }
  finally { clearTimeout(timeout); }
}

/* ---------- room code ---------- */
const params = new URLSearchParams(location.search);
async function checkRoom(code) {
  const d = await (await fetch('room.php?code=' + code, {credentials:'same-origin'})).json();
  if (!d.ok) throw new Error(d.code === 'RATE_LIMIT' ? 'Bạn nhập sai nhiều lần, chờ một phút rồi thử lại.' : d.error || 'Mã phòng không đúng hoặc phòng chưa mở.');
  S.roomOk = true; S.roomSession = null; S.room = code;   // sent with every request: the server finds this room by its code
  try { sessionStorage.setItem('clicker-room', code); } catch {}
  history.replaceState(null, '', 'phone.html?room=' + code);
}
$('code-input').addEventListener('input', e => { const v = e.target.value.replace(/\D/g, '').slice(0, 6); e.target.value = v.length > 3 ? v.slice(0, 3) + ' ' + v.slice(3) : v; });
$('code-form').addEventListener('submit', async e => {
  e.preventDefault();
  const code = $('code-input').value.replace(/\D/g, '');
  if (code.length !== 6) return toast('Mã phòng gồm 6 chữ số.');
  try { await checkRoom(code); toast(''); await tick(); } catch (error) { toast(error.message); }
});

/* ---------- student info: MSSV, and the name when the MSSV is not on the list ---------- */
$('info-form').addEventListener('submit', async e => {
  e.preventDefault();
  const id = $('info-id').value.trim(), name = $('info-name').value.trim();
  if (!/^[0-9]{1,20}$/.test(id)) return toast('MSSV chỉ gồm chữ số.');
  S.name = name;
  S.pending = {action:'login', session_id:S.data.session_id, student_id:id}; savePending();
  wait('Đang vào lớp…', 'MSSV ' + id); await tick();
});
async function register(id) {
  if (!S.name) { show('s-info'); $('info-id').value = id; $('info-name').focus(); return toast('Bạn chưa có trong danh sách lớp. Điền họ và tên để xin vào lớp.'); }
  try { await request({action:'register', session_id:S.data.session_id, student_id:id, student_name:S.name}); toast(''); }
  catch (error) { toast(error.message); }
}

/* ---------- vibration: like the board (firmware/ClickerTag VIBRATE_MS): one light buzz when OK / Gửi is pressed and one when a new
   question arrives. Android Chrome only: iPhone browsers have no vibration API, and the page must have been tapped once. ---------- */
function vibrate(n = 1) { try { navigator.vibrate?.(Array(n).fill(60).flatMap(x => [x, 80])); } catch { /* not supported */ } }

/* ---------- answering ---------- */
for (const b of document.querySelectorAll('[data-ans]')) b.addEventListener('click', () => { S.selected = b.dataset.ans; render(); });
$('send').addEventListener('click', async () => {
  const q = S.data?.question; if (!q || !S.selected) return;
  vibrate(1);
  S.pending = {action:'answer', session_id:S.data.session_id, question_id:q.question_id, answer:S.selected}; savePending();
  render(); await tick();
});

/* ---------- main loop ---------- */
async function tick() {
  if (!S.uid || S.busy) return; S.busy = true;
  try {
    if (S.mode === 'ble') { await Tag.sync(); return; }
    if (S.pending) {
      const packet = S.pending;
      try {
        const r = await request(packet);
        const match = r.session_id === packet.session_id && (packet.action === 'login' ? r.type === 'LOGIN_OK' && r.student_id === packet.student_id
          : r.type === 'ANSWER_ACK' && r.question_id === packet.question_id && r.answer === packet.answer);
        if (!match) throw new Error('Xác nhận không khớp. Đang thử lại…');
        S.pending = null; savePending();
        if (packet.action === 'answer') toast('✓ Đã gửi ' + packet.answer, true);
      } catch (error) {
        if (!error.permanent) throw error;
        S.pending = null; savePending();
        if (error.code === 'STUDENT_NOT_FOUND' && packet.action === 'login') await register(packet.student_id);
        else toast(error.message);
      }
    }
    S.data = await request({action:'poll'}); S.mode = S.data.mode === 'ble' ? 'ble' : 'wifi'; S.pollAt = Date.now();   // the room's own mode
    if (S.data.self_done) S.selfDone = S.data.self_done;   // kept to show the score after the room ends
    if (S.roomOk && S.roomSession === null) S.roomSession = S.data.session_id;
  } catch (error) {
    if (error.code === 'MODE_BLE') S.mode = 'ble';
    else if (error.permanent) toast(error.message);
  } finally { S.busy = false; render(); }
}

function render() {
  // network screen after 2 s without the server (and before the first answer)
  if (S.online) S.offlineSince = 0; else if (!S.offlineSince) S.offlineSince = Date.now();
  $('net-wait-text').textContent = S.uid ? 'Mạng chậm, đang kết nối lại…' : 'Đang kết nối…';
  $('net-wait').classList.toggle('on', !S.uid || (!S.online && Date.now() - S.offlineSince > 2000));
  if (S.mode === 'ble') { $('who').hidden = true; show('s-ble'); Tag.start(); return; }
  Tag.stop();
  const d = S.data; if (!d) return;
  $('who').hidden = !d.student_id; $('who').textContent = d.student_id ? (d.student_name || 'MSSV ' + d.student_id) : '';
  if (d.student_id) S.joined = d.session_id || S.joined;
  if (!d.student_id && S.joined && (!d.session_id || d.session_id === S.joined)) return done();
  if (d.student_id) {   // in the room: no room code needed again on this device
    if (d.state === 'FINISHED' || !d.session_id) return done();
    const q = d.question;
    if (!q && d.pacing === 'self' && d.state === 'QUESTION_ACTIVE') return done();
    if (S.pending?.action === 'answer') return wait('Đang gửi đáp án ' + S.pending.answer + '…', 'Giữ trang này mở.');
    if (q && !q.accepted_answer) {
      if (S.lastQuestion !== q.question_id) { S.lastQuestion = q.question_id; S.selected = ''; S.maxLeft = 0; vibrate(1); }
      $('q-no').textContent = q.total ? `Câu ${q.index}/${q.total}` : 'Câu ' + q.question_no;
      $('q-text').textContent = q.text || '';
      for (const b of document.querySelectorAll('[data-ans]')) {
        b.setAttribute('aria-checked', String(b.dataset.ans === S.selected));
        b.querySelector('span').textContent = q.choices ? q.choices[b.dataset.ans] : '';
      }
      // self-paced: seconds left for this question (server time, counted down locally between polls)
      const timed = q.remaining !== undefined;
      $('q-timer').hidden = $('q-left').hidden = !timed; if (!timed) $('q-quiz').hidden = true;
      if (timed) {
        const left = Math.max(0, q.remaining - (Date.now() - S.pollAt) / 1000), total = Number(S.data.seconds) || S.maxLeft || left;
        S.maxLeft = Math.max(S.maxLeft || 0, q.remaining);
        $('q-left').textContent = Math.ceil(left) + ' giây';
        $('q-left').classList.toggle('warn', left <= 5); $('q-timer').classList.toggle('warn', left <= 5);
        const quiz = q.quiz_left !== undefined ? Math.max(0, q.quiz_left - (Date.now() - S.pollAt) / 1000) : null;
        $('q-quiz').hidden = quiz === null;
        if (quiz !== null) $('q-quiz').textContent = `Còn ${Math.floor(quiz / 60)}:${String(Math.floor(quiz % 60)).padStart(2, '0')} cho cả bài`;
        $('q-timer').firstElementChild.style.transform = `scaleX(${total ? Math.min(1, left / total) : 0})`;
      }
      document.querySelector('.answers').classList.toggle('picked', !!S.selected);
      $('send').disabled = !S.selected; $('send').textContent = S.selected ? 'Gửi đáp án ' + S.selected : 'Gửi đáp án';
      return show('s-question');
    }
    if (q) return wait('Đã gửi đáp án ' + q.accepted_answer + ' ✓', 'Chờ câu hỏi tiếp theo…');   // teacher-paced only
    return wait('Đã vào lớp', 'Chờ giảng viên bắt đầu câu hỏi…');
  }
  // not in the room yet
  if (!S.roomOk) { show('s-code'); return; }
  if (S.roomSession !== null && d.session_id !== S.roomSession) { S.roomOk = false; toast('Phòng đã đổi. Hãy nhập mã phòng mới.'); return show('s-code'); }
  if (d.state === 'FINISHED') return done();
  if (!d.session_id) return wait('Chờ giảng viên mở phòng', 'Phòng chưa sẵn sàng.');
  if (S.pending?.action === 'login') return wait('Đang vào lớp…', 'MSSV ' + S.pending.student_id);
  if (d.join_pending) return wait('Đợi giảng viên duyệt', `${d.join_pending.student_name} · MSSV ${d.join_pending.student_id} · lớp ${d.class_id}`);
  if (d.state !== 'LOGIN_OPEN') return wait('Chờ giảng viên mở đăng nhập', d.state === 'CREATED' ? 'Phòng đã tạo, sắp mở đăng nhập.' : 'Buổi học đang diễn ra, đăng nhập đã đóng. Hãy báo giảng viên.');
  $('info-class').textContent = 'Lớp ' + d.class_id; $('info-class-input').value = d.class_id;
  show('s-info');
}

function done() {
  const r = S.selfDone;
  $('done-title').textContent = r ? (S.data?.state === 'FINISHED' || !S.data?.session_id ? 'Bài kiểm tra đã kết thúc' : 'Bạn đã làm xong') : 'Buổi học đã kết thúc';
  $('done-score').hidden = !r;
  if (r) $('done-score').textContent = `${String(r.score10).replace('.', ',')}/10 điểm`;
  $('done-sub').textContent = r ? `Đúng ${r.correct}/${r.total} câu · đã trả lời ${r.answered}/${r.total}. Câu chưa trả lời tính 0 điểm.` : 'Cảm ơn bạn đã tham gia. Giảng viên sẽ thông báo kết quả.';
  show('s-done');
}

/* ---------- BLE: a Student Tag of this browser's own (server id NRF-SIM-…). Screen, timings and buzzes follow
   firmware/ClickerTag/ClickerTag.ino (struct Tag): power-up chase → -- → Lo → MSSV → OK → Hi → rd → question number →
   1–4 → OK → A= → at the end the score (5 s), En (3 s), then the display sleeps (30 s idle sleeps it too; a key wakes it). ---------- */
const Tag = (() => {
  const SEG = {'0':0x3F,'O':0x3F,'1':0x06,'2':0x5B,'3':0x4F,'4':0x66,'5':0x6D,'S':0x6D,'6':0x7D,'7':0x07,'8':0x7F,'9':0x6F,'A':0x77,'b':0x7C,'B':0x7C,
    'C':0x39,'d':0x5E,'D':0x5E,'E':0x79,'F':0x71,'H':0x76,'i':0x04,'L':0x38,'n':0x54,'o':0x5C,'r':0x50,'-':0x40,'=':0x48};
  const ERR = {LOGIN_CLOSED:1, STUDENT_NOT_FOUND:2, STUDENT_IN_USE:3, DEVICE_IN_USE:4, LOGIN_REQUIRED:5, QUESTION_CLOSED:6, ANSWER_LOCKED:7};
  // segment polygons a..g for one digit in a 50x80 box
  const P = [[8,4,42,4,38,10,12,10],[43,6,46,10,45,36,40,38,39,12],[45,42,40,44,39,68,43,72,46,70],[8,76,42,76,38,70,12,70],
    [5,42,10,44,11,68,7,72,4,70],[7,6,11,12,10,36,5,38,4,10],[9,40,13,37,37,37,41,40,37,43,13,43]];
  const led = $('led');
  led.innerHTML = [0, 1].map(d => P.map((p, i) => `<polygon class="seg" data-d="${d}" data-s="${i}" points="${p.map((v, j) => j % 2 ? v : v + 8 + d * 58).join(' ')}"/>`).join('')).join('');
  const segs = [...led.querySelectorAll('.seg')];
  // Same numbers as the firmware (ms)
  const NOTICE_MS = 1500, GATEWAY_TIMEOUT_MS = 5000, DISPLAY_SLEEP_MS = 30000, SCORE_SHOW_MS = 5000, END_SHOW_MS = 3000, SCORE_WAIT_MS = 10000,
    STARTUP_STEP_MS = 200, STARTUP_ROUNDS = 3;
  const t = {data:null, input:'', selected:'', pending:null, notice:'', noticeAt:0, question:0, off:false,
    awake:true, activeAt:Date.now(), heard:false, heardAt:0, state:'', hasScore:false, score:0, scoreAt:0, finishedAt:0, startAt:0};
  let timer = null;
  const wake = (now = Date.now()) => { t.awake = true; t.activeAt = now; };
  // Power-up effect: one segment per step round the two digits (a2 a1 b1 c1 d1 d2 e2 f2), three rounds, a buzz each round.
  const CHASE = [[1, 0], [0, 1], [0, 2], [0, 4], [0, 8], [8, 0], [16, 0], [32, 0]];   // [left digit, right digit] segment bits
  const startupStep = now => t.startAt ? Math.floor((now - t.startAt) / STARTUP_STEP_MS) : 99;
  // Score out of 10 in tenths, cut not rounded: 9.75 → 97 → "97"; a whole score shows as a number (10 → "10", 5.0 → " 5")
  const tensOf = n => (n %= 100) < 10 ? ' ' : String(Math.floor(n / 10)), unitsOf = n => String(n % 10);
  const scoreChars = tenths => tenths % 10 ? [String(Math.floor(tenths / 10) % 10), String(tenths % 10)] : [tensOf(tenths / 10), unitsOf(tenths / 10)];
  const fmtScore = tenths => String(tenths / 10).replace('.', ',');

  // End of the test, as the firmware does it: score for 5 s, "En" for 3 s, then the display sleeps; a tag still waiting for its
  // score shows "En" for up to 10 s; a key pressed after it slept shows "En" again.
  function finished(now, logged) {
    const start = t.hasScore ? t.scoreAt : t.finishedAt;
    const scoreEnd = start + (t.hasScore ? SCORE_SHOW_MS : 0);
    const end = scoreEnd + (t.hasScore || !logged ? END_SHOW_MS : SCORE_WAIT_MS);
    if (t.activeAt <= end) {
      if (now < scoreEnd) return [scoreChars(t.score).join(''), false, `Điểm: ${fmtScore(t.score)}/10`];
      if (now >= end) { t.awake = false; return ['  ', false, 'Màn hình đã tắt, bấm phím để bật']; }
    }
    return ['En', false, t.hasScore ? `Kết thúc · điểm ${fmtScore(t.score)}/10` : 'Kết thúc'];
  }

  function view(now = Date.now()) {   // [two characters, blink, status line]; same order of rules as Tag::screen()
    const d = t.data, q = d?.question, logged = !!d?.student_id || (d?.state === 'FINISHED' && !!d?.self_done);   // after the end the server only sends self_done
    if (!t.pending && now - t.activeAt > DISPLAY_SLEEP_MS) t.awake = false;
    if (!t.awake) return ['  ', false, 'Màn hình đã tắt, bấm phím để bật'];
    if (t.notice && now - t.noticeAt < NOTICE_MS) return [t.notice, false, t.notice === 'Hi' ? 'Đã vào lớp' : 'Lỗi ' + t.notice];
    if (t.pending?.action === 'login') return [t.input.slice(-2).padStart(2, ' '), true, 'Đang gửi MSSV…'];
    if (t.pending) return [' ' + t.pending.answer, true, 'Đang gửi đáp án…'];
    if (!t.heard) return ['--', false, 'Đang kết nối…'];
    if (now - t.heardAt > GATEWAY_TIMEOUT_MS) return ['nC', false, 'Mất kết nối (nC)'];
    if (t.off) return ['OF', false, 'Quản trị viên đã tắt tag mô phỏng.'];
    if (d.state === 'FINISHED') return finished(now, logged);
    if (!d.session_id || d.state === 'WAIT_SESSION' || d.state === 'CREATED') return ['--', false, 'Chờ giảng viên mở phòng'];
    if (!logged) return d.state !== 'LOGIN_OPEN' ? ['LC', false, 'Đăng nhập đã đóng']
      : [t.input ? t.input.slice(-2).padStart(2, ' ') : 'Lo', false, 'Nhập MSSV bằng phím số rồi bấm OK'];
    if (d.state !== 'QUESTION_ACTIVE' || !q) return ['rd', false, 'Sẵn sàng, chờ câu hỏi'];
    if (q.accepted_answer) return [q.accepted_answer + '=', false, 'Đã gửi đáp án'];
    if (t.selected) return [' ' + t.selected, false, 'Bấm OK để gửi'];
    return [tensOf(q.question_no) + unitsOf(q.question_no), false, `Câu ${q.question_no}: chọn 1–4 (= A–D)`];
  }
  function draw() {
    const now = Date.now(), step = startupStep(now), chase = step < 8 * STARTUP_ROUNDS;
    const [text, blink, status] = chase ? ['  ', false, 'Đang khởi động…'] : view(now);
    const mask = d => chase ? CHASE[step % 8][d] : SEG[text[d]] || 0;
    for (const sg of segs) sg.classList.toggle('on', !!(mask(+sg.dataset.d) >> +sg.dataset.s & 1));
    if (chase && step % 8 === 0 && t.lastBuzzStep !== step) { t.lastBuzzStep = step; buzz(1); }
    led.parentElement.classList.toggle('blink', blink);
    $('tag-status').textContent = status;
    const d = t.data;
    $('tag-who').hidden = !d?.student_id; $('tag-who').textContent = d?.student_id ? `${d.student_name || ''} · MSSV ${d.student_id}` : '';
  }
  const buzz = n => { const el = $('tag-dev'); el.classList.remove('buzz'); void el.offsetWidth; el.style.animationIterationCount = n * 4; el.classList.add('buzz'); vibrate(n); };
  const notice = text => { t.notice = text; t.noticeAt = Date.now(); wake(); };

  // One round with the server, called by tick(): the waiting request first (kept until answered, like the board), then the state.
  async function sync() {
    if (t.pending) {
      const packet = t.pending;
      try {
        await request(packet);
        t.pending = null;
        if (packet.action === 'login') { t.input = ''; notice('Hi'); wake(); }
        else wake();
      } catch (error) {
        if (!error.permanent) throw error;
        t.pending = null; t.selected = '';
        if (error.code === 'MODE_WIFI') { S.mode = 'wifi'; return; }
        if (error.code === 'SIM_OFF') t.off = true; else notice('E' + (ERR[error.code] || 8));
        wake();
      }
    }
    try { t.data = await request({action:'poll', as_tag:true}); t.off = false; t.heard = true; t.heardAt = Date.now(); if (t.data.mode === 'wifi') { S.mode = 'wifi'; return; } }
    catch (error) {
      if (error.code === 'MODE_WIFI') { S.mode = 'wifi'; return; }
      if (error.code === 'SIM_OFF') { t.off = true; return; }
      throw error;
    }
    heard(t.data, Date.now());
  }
  // What Tag::onDownlink does with each STATE frame: wake on a change, one buzz for a new question, keep the score.
  function heard(d, now) {
    const fin = d.state === 'FINISHED', q = d.question?.question_id || 0;
    if (fin && t.state !== 'FINISHED') t.finishedAt = now;
    if (t.state === 'FINISHED' && !fin) { t.hasScore = false; t.selected = ''; t.input = ''; wake(now); }
    if (q !== t.question || d.state !== t.state) {
      if (q !== t.question) t.selected = '';
      if (d.state === 'QUESTION_ACTIVE' && q && q !== t.question && d.student_id) buzz(1);   // new question: one buzz
      wake(now);
    }
    t.question = q; t.state = d.state;
    const sc = d.self_done?.score10;
    if (fin && sc !== undefined && sc !== null) {
      const tenths = Math.min(100, Math.floor(Number(sc) * 10 + 1e-6));
      if (!t.hasScore || t.score !== tenths) { t.score = tenths; t.hasScore = true; t.scoreAt = now; wake(now); }
    }
  }
  function press(k) {
    const d = t.data, q = d?.question, wasAwake = t.awake;
    wake();
    if (!wasAwake) { draw(); return; }   // a key on a sleeping display only wakes it
    if (t.pending || t.off || !d) { draw(); return; }
    const login = !d.student_id && d.state === 'LOGIN_OPEN', answer = !!d.student_id && d.state === 'QUESTION_ACTIVE' && q && !q.accepted_answer;
    if (k === 'B') { if (login) t.input = t.input.slice(0, -1); else if (answer) t.selected = ''; }
    else if (k === 'C') {
      if (login && t.input) t.pending = {action:'login', session_id:d.session_id, student_id:t.input, as_tag:true};
      else if (answer && t.selected) t.pending = {action:'answer', session_id:d.session_id, question_id:q.question_id, answer:t.selected, as_tag:true};
      if (t.pending) { buzz(1); tick(); }
    }
    else if (login && t.input.length < 20) t.input += k;
    else if (answer && '1234'.includes(k)) t.selected = 'ABCD'[+k - 1];
    draw();
  }
  for (const b of document.querySelectorAll('[data-k]')) b.addEventListener('click', () => press(b.dataset.k));
  document.addEventListener('keydown', e => {
    if ($('s-ble').hidden || e.repeat) return;
    const k = /^[0-9]$/.test(e.key) ? e.key : e.key === 'Enter' ? 'C' : e.key === 'Backspace' ? 'B' : null;
    if (!k) return; e.preventDefault();
    const b = document.querySelector(`[data-k="${k}"]`); b.classList.add('press'); setTimeout(() => b.classList.remove('press'), 120); press(k);
  });
  return {
    sync,
    start() { if (!timer) { if (!t.startAt) t.startAt = Date.now(); draw(); timer = setInterval(draw, 100); } },
    stop() { clearInterval(timer); timer = null; },
  };
})();

/* ---------- start ---------- */
async function initialize() {
  try {
    const response = await fetch('phone-api.php', {cache:'no-store'}); const identity = await response.json();
    if (freshPage(identity.web)) return;
    if (!response.ok || !identity.ok) throw new Error('Không cấp được mã thiết bị.');
    S.uid = identity.device_uid; S.csrf = identity.csrf;
    try { S.pending = JSON.parse(localStorage.getItem(storageKey()) || 'null'); } catch { S.pending = null; }
    const code = (params.get('room') || (() => { try { return sessionStorage.getItem('clicker-room'); } catch { return ''; } })() || '').replace(/\D/g, '');
    if (code.length === 6) { try { await checkRoom(code); } catch (error) { toast(error.message); } }
    await tick();
  } catch (error) { render(); setTimeout(initialize, 2000); }
}
window.addEventListener('online', tick);
document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
render(); initialize(); setInterval(tick, 1000);
setInterval(() => { if (!$('s-question').hidden && !$('q-timer').hidden) render(); }, 250);
