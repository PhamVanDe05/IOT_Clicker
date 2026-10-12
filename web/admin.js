/* Quản trị tab (admins only). Tabs: Tổng quan · Giáo viên · Thiết bị · Báo cáo · Nhật ký · Hệ thống.
   Uses api(), notify() from app.js; the server checks is_admin on every admin_* action. */
'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const T = s => (typeof I18N !== 'undefined' ? I18N.t(s) : s);
  const STATE = {CREATED:'Đã tạo', LOGIN_OPEN:'Đang mở đăng nhập', READY:'Chờ câu hỏi', QUESTION_ACTIVE:'Đang làm bài', FINISHED:'Đã kết thúc'};
  const ACTIONS = {password_set:'Đặt mật khẩu mới', admin_approve_pw:'Cấp phép mật khẩu', admin_reject_pw:'Từ chối mật khẩu', admin_audit_delete:'Xoá nhật ký', login:'Đăng nhập', login_fail:'Đăng nhập sai', logout:'Đăng xuất', register:'Đăng ký', password_request:'Quên mật khẩu',
    import_students:'Nhập lớp', import_exam:'Nhập đề', import_questions:'Nhập câu hỏi', create_session:'Tạo phòng', remove_class:'Xoá lớp',
    remove_student:'Xoá sinh viên', remove_exam:'Xoá đề', remove_session:'Xoá buổi', set_mode:'Đổi chế độ', approve_join:'Duyệt vào lớp',
    admin_backup:'Sao lưu', admin_setting:'Cài đặt', admin_create_teacher:'Tạo tài khoản', admin_view_as:'Xem như', admin_reset_password:'Đặt lại mật khẩu',
    admin_lock:'Khoá', admin_role:'Quyền quản trị', admin_delete:'Xoá tài khoản', admin_end_room:'Kết thúc phòng', admin_device:'Thiết bị'};
  let data = null, extra = null, devices = null, report = null, logRows = [], tab = 'home', timer = 0, devKind = '';
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };
  const btn = (cls, text, on) => { const b = el('button', cls, text); b.type = 'button'; b.addEventListener('click', () => on(b)); return b; };
  const when = v => {
    if (!v) return '—';
    const d = new Date(String(v).replace(' ', 'T')), mins = Math.round((Date.now() - d) / 60000);
    if (mins < 1) return 'Vừa xong'; if (mins < 60) return `${mins} phút trước`;
    if (mins < 1440) return `${Math.round(mins / 60)} giờ trước`; if (mins < 43200) return `${Math.round(mins / 1440)} ngày trước`;
    return d.toLocaleDateString('vi-VN');
  };
  const bytes = n => { n = Number(n) || 0; const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; while (n >= 1024 && i < 4) { n /= 1024; i++; } return `${n.toFixed(n < 10 && i ? 1 : 0)} ${u[i]}`; };
  const kindOf = d => d.device_uid.startsWith('PHONE-') ? 'phone' : d.device_uid.startsWith('NRF-') ? 'nrf' : d.device_uid.startsWith('BRIDGE-') ? 'bridge' : 'esp';
  const KIND = {phone:'Điện thoại / laptop', nrf:'Tag nRF', bridge:'Gateway USB', esp:'ESP32 (cũ)'};

  async function call(action, body, button, okText) {
    if (button) button.disabled = true;
    try { const r = await api(action, body); if (okText) notify(okText); return r; }
    catch (error) { notify(error.message, true); return null; }
    finally { if (button) button.disabled = false; }
  }
  function secret(title, password, note) {
    $('adm-secret-title').textContent = title; $('adm-secret-pass').textContent = password; $('adm-secret-note').textContent = note || '';
    $('adm-secret').showModal();
  }

  /* teacher photo (own upload or the default for their gender); click opens their profile */
  function photo(t, size) {
    const img = el('img', 'adm-photo' + (size ? ' ' + size : '')); img.alt = '';
    const p = t.profile;
    img.src = p?.has_avatar ? `api.php?action=avatar&id=${t.id}&v=${p.v}` : (typeof Account !== 'undefined' ? Account.defaultAvatar(p?.gender) : '');
    const b = el('button', 'adm-photo-btn'); b.type = 'button'; b.title = T('Xem hồ sơ'); b.append(img, el('span', 'adm-photo-tip', T('Hồ sơ')));
    b.addEventListener('click', e => { e.stopPropagation(); showProfile(t); });
    if (Number(t.online)) b.append(el('i', 'adm-online-dot'));
    return b;
  }
  function seenLabel(t) {
    return Number(t.online) ? el('span', 'adm-seen on', T('● Đang online')) : el('span', 'adm-seen', t.last_seen ? T('Hoạt động') + ' ' + when(t.last_seen) : T('Chưa đăng nhập lần nào'));
  }
  function showProfile(t) {
    const p = t.profile || {};
    $('ap-name').textContent = p.full_name || t.username;
    $('ap-user').textContent = `${T('Tài khoản')}: ${t.username}${t.email ? ' · ' + t.email : ''}`;
    $('ap-avatar').src = p.has_avatar ? `api.php?action=avatar&id=${t.id}&v=${p.v}` : Account.defaultAvatar(p.gender);
    const tags = []; if (Number(t.is_admin)) tags.push(['tag-admin', 'Quản trị']); if (t.disabled_at) tags.push(['tag-lock', 'Đã khoá']);
    tags.push(['tag-me', Number(t.google) ? 'Google' : 'Mật khẩu']);
    $('ap-tags').replaceChildren(...tags.map(([c, x]) => el('span', c, T(x))));
    const G = {nam:'Nam', nu:'Nữ', khac:'Khác'};
    const rows = t.profile ? [['Giới tính', G[p.gender] || '—'], ['Trường', p.school], ['Môn dạy', p.subject], ['Khối / lớp dạy', p.grades], ['Điện thoại', p.phone], ['Giới thiệu', p.bio]]
      : [['Hồ sơ', 'Giáo viên này chưa điền hồ sơ.']];
    rows.push(['Lớp / sinh viên', `${t.classes} lớp · ${t.students} sinh viên`], ['Bộ đề / buổi học', `${t.exams} bộ đề · ${t.rooms} buổi`], ['Tạo tài khoản', when(t.created_at)], ['Dùng gần nhất', when(t.last_used)]);
    $('ap-list').replaceChildren(...rows.flatMap(([k, v]) => [el('dt', '', T(k)), el('dd', '', v || '—')]));
    $('adm-profile').showModal();
  }

  /* ---------- loading ---------- */
  async function load(force) {
    if ($('admin-section').hidden || $('nav-admin').hidden) return;
    try {
      [data, extra] = await Promise.all([api('admin_overview', null, 'GET'), api('admin_extra', null, 'GET')]);
      if (tab === 'devices' || force === 'devices') devices = await api('admin_devices', null, 'GET');
      if (tab === 'report' && (!report || force === 'report')) report = await api('admin_report', null, 'GET');
      if (tab === 'log' && force !== 'quiet') await loadLog(true);
      draw();
    } catch (error) { notify(error.message, true); }
  }
  async function loadLog(reset) {
    const q = $('adm-log-search').value.trim(), before = reset ? 0 : (logRows.at(-1)?.id || 0);
    const r = await api('admin_audit', {...(before ? {before} : {}), ...(q ? {q} : {})}, 'GET');
    logRows = reset ? r.rows : logRows.concat(r.rows);
    $('adm-log-more').hidden = r.rows.length < 100;
    drawLog(r.ready);
  }

  function draw() {
    drawHome(); drawTeachers();
    if (devices) drawDevices();
    if (report) drawReport();
    drawSystem(); drawClean();
  }

  /* ---------- Tổng quan: each teacher with their classes, rooms, students, question sets ---------- */
  const open = new Set();
  function drawHome() {
    // Every running room (several teachers can each run one at the same time); a forgotten one can be ended here.
    const rooms = data.active_rooms || [];
    $('adm-room-title').textContent = rooms.length ? `${rooms.length} phòng đang chạy` : 'Không có phòng nào đang chạy';
    $('adm-room-badge').textContent = rooms.length ? `${rooms.reduce((n, r) => n + Number(r.joined), 0)} SV đang vào` : 'Rảnh';
    $('adm-room-badge').className = 'state-badge ' + (rooms.length ? 'active' : '');
    $('adm-rooms').replaceChildren(...rooms.map(r => {
      const row = el('div', 'adm-room'), info = el('div');
      info.append(el('b', '', r.title || `Lớp ${r.class_id}`), el('small', '', `#${r.id} · ${r.username} · lớp ${r.class_id} · ${r.mode === 'ble' ? 'BLE' : 'Wi-Fi'} · ${r.joined} SV · ${STATE[r.state] || r.state} · tạo ${when(r.created_at)}`));
      const end = el('button', 'danger', 'Kết thúc'); end.type = 'button';
      end.addEventListener('click', async () => { if (confirm(T('Kết thúc phòng này? Sinh viên sẽ dừng làm bài.')) && await call('admin_end_room', {session_id:Number(r.id)}, end, 'Đã kết thúc phòng.')) load(); });
      row.append(info, end); return row;
    }));
    $('adm-tree-title').textContent = `${data.teachers.length} giáo viên`;
    $('adm-tree').replaceChildren(...data.teachers.map(t => {
      const id = Number(t.id), card = el('div', 'adm-node' + (open.has(id) ? ' open' : '') + (t.disabled_at ? ' locked' : ''));
      const head = el('button', 'adm-node-head'); head.type = 'button'; head.setAttribute('aria-expanded', String(open.has(id)));
      const who = el('div', 'adm-who'), text = el('div');
      text.append(el('b', '', t.username), el('small', '', t.email || (Number(t.google) ? 'Google' : 'Tài khoản mật khẩu')));
      text.firstChild.textContent = t.profile?.full_name || t.username;
      text.append(seenLabel(t));
      who.append(photo(t), text);
      const nums = el('div', 'adm-nums');
      for (const [n, label] of [[t.classes, 'lớp'], [t.students, 'sinh viên'], [t.rooms, 'phòng'], [t.exams, 'bộ đề']]) { const x = el('span'); x.append(el('strong', '', n), el('small', '', label)); nums.append(x); }
      head.append(who, nums, el('i', 'adm-chev', '›'));
      head.addEventListener('click', () => { open.has(id) ? open.delete(id) : open.add(id); drawHome(); });
      card.append(head);
      if (open.has(id)) {
        const body = el('div', 'adm-node-body');
        const cls = el('div', 'adm-col'); cls.append(el('h4', '', 'Lớp'));
        if (!t.class_list.length) cls.append(el('p', 'hint', 'Chưa nhập lớp nào.'));
        for (const c of t.class_list) {
          const row = el('div', 'adm-leaf'); const left = el('div');
          left.append(el('b', '', c.class_id), el('small', '', c.last_room ? `Phòng gần nhất ${when(c.last_room)}` : 'Chưa tạo phòng'));
          const tags = el('div', 'adm-leaf-tags'); tags.append(el('span', '', `${c.students} SV`), el('span', '', `${c.rooms} phòng`));
          row.append(left, tags); cls.append(row);
        }
        const ex = el('div', 'adm-col'); ex.append(el('h4', '', 'Bộ đề'));
        if (!t.exam_list.length) ex.append(el('p', 'hint', 'Chưa có bộ đề nào.'));
        for (const e of t.exam_list) {
          const row = el('div', 'adm-leaf'); const left = el('div');
          left.append(el('b', '', e.title), el('small', '', `${e.questions} câu`));
          const tags = el('div', 'adm-leaf-tags'); tags.append(el('span', '', `${e.rooms} phòng`)); row.append(left, tags); ex.append(row);
        }
        body.append(cls, ex); card.append(body);
      }
      return card;
    }));
  }

  /* ---------- Giáo viên ---------- */
  function drawTeachers() {
    const q = $('adm-search').value.trim().toLowerCase();
    const waiting = new Set(extra.requests.map(r => Number(r.teacher_id)));
    const list = data.teachers.filter(t => !q || t.username.toLowerCase().includes(q) || (t.email || '').toLowerCase().includes(q));
    $('adm-teacher-count').textContent = `${data.teachers.length} giáo viên`;
    const on = data.teachers.filter(t => Number(t.online)).length;
    $('adm-online-count').textContent = on ? `● ${on} ${T('đang online')}` : '';
    for (const id of [...pickedT]) if (!data.teachers.some(t => Number(t.id) === id)) pickedT.delete(id);
    $('adm-teacher-rows').replaceChildren(...list.map(t => {
      const id = Number(t.id), me = id === data.me;
      const tr = el('tr'); if (t.disabled_at) tr.className = 'locked';
      const who = el('td'), card = el('div', 'adm-who');
      const text = el('div'); text.append(el('b', '', t.username), el('small', '', t.email || 'Chưa có email'));
      const tags = el('div', 'adm-tags');
      if (Number(t.is_admin)) tags.append(el('span', 'tag-admin', 'Quản trị'));
      if (me) tags.append(el('span', 'tag-me', 'Bạn'));
      if (t.disabled_at) tags.append(el('span', 'tag-lock', 'Đã khoá'));
      if (waiting.has(id)) tags.append(el('span', 'tag-req', 'Quên mật khẩu'));
      text.append(tags, seenLabel(t)); if (t.profile?.full_name) text.firstChild.textContent = t.profile.full_name; card.append(photo(t), text); who.append(card);
      const pick = el('td', 'adm-pick');
      if (!me) { const box = el('input'); box.type = 'checkbox'; box.checked = pickedT.has(id); box.addEventListener('change', () => { box.checked ? pickedT.add(id) : pickedT.delete(id); syncT(); }); pick.append(box); }
      const tools = el('td', 'adm-actions');
      if (!me) {
        const menu = el('details', 'adm-menu'); const sum = el('summary', '', 'Thao tác ▾'); const box = el('div', 'adm-menu-box');
        box.append(
          btn('', '🪪 Xem hồ sơ', () => { menu.open = false; showProfile(t); }),
          btn('', '👁 Xem dữ liệu (chỉ đọc)', async b => { if (await call('admin_view_as', {teacher_id:id}, b)) { location.hash = '#overview'; location.reload(); } }),
          btn('', '🔑 Đặt lại mật khẩu', async b => { const x = await call('admin_reset_password', {teacher_id:id}, b); if (x) { secret(`Mật khẩu mới của ${x.username}`, x.password, t.email ? 'Tài khoản này cũng đăng nhập được bằng Google.' : ''); load(); } }),
          btn('', t.disabled_at ? '🔓 Mở khoá' : '🔒 Khoá tài khoản', async b => {
            if (!t.disabled_at && !confirm(T(`Khoá tài khoản "${t.username}"? Người này sẽ bị đăng xuất ngay.`))) return;
            if (await call('admin_set_locked', {teacher_id:id, locked:!t.disabled_at}, b, t.disabled_at ? 'Đã mở khoá.' : 'Đã khoá tài khoản.')) load();
          }),
          btn('', Number(t.is_admin) ? '⭐ Bỏ quyền quản trị' : '⭐ Cho làm quản trị', async b => { if (await call('admin_set_admin', {teacher_id:id, admin:!Number(t.is_admin)}, b, 'Đã cập nhật quyền.')) load(); }),
          btn('danger-item', '🗑 Xoá tài khoản', async b => {
            const typed = prompt(T(`Xoá vĩnh viễn "${t.username}" cùng ${t.rooms} buổi học, ${t.exams} bộ đề, ${t.students} sinh viên?\nGõ đúng tên tài khoản để xác nhận:`));
            if (typed === null) return;
            if (await call('admin_delete_teacher', {teacher_id:id, confirmed:typed.trim()}, b, 'Đã xoá tài khoản.')) load();
          }));
        menu.append(sum, box); tools.append(menu);
      }
      tr.append(pick, who, el('td', '', Number(t.google) ? 'Google' : 'Mật khẩu'), el('td', 'num', t.classes), el('td', 'num', t.students), el('td', 'num', t.exams),
        el('td', 'num', t.rooms), el('td', '', when(t.last_seen || t.last_used || t.created_at)), tools);
      return tr;
    }));
  }

  const pickedT = new Set(), pickedD = new Set();
  function syncT() {
    $('adm-t-del').disabled = !pickedT.size; $('adm-t-del').textContent = pickedT.size ? `${T('Xoá đã chọn')} (${pickedT.size})` : T('Xoá đã chọn');
    const others = data.teachers.filter(t => Number(t.id) !== data.me);
    $('adm-t-all').checked = others.length > 0 && others.every(t => pickedT.has(Number(t.id)));
  }
  function syncD(list) {
    $('adm-d-del').disabled = !pickedD.size; $('adm-d-del').textContent = pickedD.size ? `${T('Xoá đã chọn')} (${pickedD.size})` : T('Xoá đã chọn');
    $('adm-d-all').checked = list.length > 0 && list.every(d => pickedD.has(d.device_uid));
  }
  let shownDevices = [];

  /* ---------- Thiết bị ---------- */
  function drawDevices() {
    const q = $('adm-dev-search').value.trim().toLowerCase();
    const list = devices.devices.filter(d => {
      const k = kindOf(d);
      if (devKind === 'online' ? !Number(d.online) : devKind === 'blocked' ? !d.revoked_at : devKind && k !== devKind) return false;
      return !q || [d.device_uid, d.label, d.last_student].some(v => (v || '').toLowerCase().includes(q));
    });
    $('adm-dev-count').textContent = `${devices.devices.length} thiết bị · ${devices.devices.filter(d => Number(d.online)).length} đang online`;
    shownDevices = list;
    $('adm-dev-rows').replaceChildren(...list.map(d => {
      const tr = el('tr'); if (d.revoked_at) tr.className = 'locked';
      const pick = el('td', 'adm-pick'), box = el('input'); box.type = 'checkbox'; box.checked = pickedD.has(d.device_uid);
      box.addEventListener('change', () => { box.checked ? pickedD.add(d.device_uid) : pickedD.delete(d.device_uid); syncD(list); }); pick.append(box);
      const name = el('td'), card = el('div', 'adm-who');
      const dot = el('span', 'adm-dot' + (Number(d.online) ? ' on' : ''));
      const text = el('div'); text.append(el('b', '', d.label || d.device_uid), el('small', '', d.label ? d.device_uid : 'Chưa đặt tên'));
      if (d.revoked_at) { const tags = el('div', 'adm-tags'); tags.append(el('span', 'tag-lock', 'Đã chặn')); text.append(tags); }
      card.append(dot, text); name.append(card);
      const tools = el('td', 'adm-actions');
      if (devices.labels) tools.append(btn('secondary', 'Đặt tên', async b => {
        const label = prompt(T('Tên thiết bị (ví dụ: Máy 12):'), d.label || ''); if (label === null) return;
        if (await call('admin_device', {device_uid:d.device_uid, label}, b, 'Đã đặt tên.')) load('devices');
      }));
      tools.append(btn(d.revoked_at ? 'secondary' : 'danger', d.revoked_at ? 'Bỏ chặn' : 'Chặn', async b => {
        if (!d.revoked_at && !confirm(T(`Chặn ${d.label || d.device_uid}? Thiết bị này sẽ không vào phòng được nữa.`))) return;
        if (await call('admin_device', {device_uid:d.device_uid, blocked:!d.revoked_at}, b, d.revoked_at ? 'Đã bỏ chặn.' : 'Đã chặn thiết bị.')) load('devices');
      }));
      tr.append(pick, name, el('td', '', KIND[kindOf(d)]), el('td', '', d.last_student || '—'), el('td', '', Number(d.online) ? 'Đang online' : when(d.last_seen)), tools);
      return tr;
    }));
    syncD(list);
    if (!list.length) { const tr = el('tr'); const td = el('td', 'adm-empty', 'Không có thiết bị nào.'); td.colSpan = 6; tr.append(td); $('adm-dev-rows').append(tr); }
  }

  /* ---------- Báo cáo ---------- */
  function bars(id, rows) {
    $(id).replaceChildren(...(rows.length ? rows.slice(0, 12).map(r => {
      const row = el('div', 'adm-bar'), top = el('div', 'adm-bar-top');
      top.append(el('b', '', r.label), el('strong', '', String(r.avg).replace('.', ',')));
      const track = el('i'), fill = el('span'); fill.style.width = (r.avg * 10) + '%'; fill.className = r.avg >= 8 ? 'adm-good' : r.avg >= 5 ? 'adm-mid' : 'adm-low'; track.append(fill);
      row.append(top, track, el('small', '', `${r.students} lượt làm · ${r.rooms} buổi`)); return row;
    }) : [el('p', 'hint', 'Chưa có buổi học nào kết thúc.')]));
  }
  function drawReport() {
    bars('adm-rep-teacher', report.by_teacher); bars('adm-rep-class', report.by_class); bars('adm-rep-exam', report.by_exam);
    $('adm-rep-hard').replaceChildren(...(report.hardest.length ? report.hardest.map((q, i) => {
      const pct = Math.round(q.ok / q.n * 100), row = el('div', 'adm-hard-row');
      const text = el('div'); text.append(el('b', '', `${i + 1}. Q${q.question_no} · ${q.question_text}`), el('small', '', `${q.title} · ${q.username} · đáp án ${q.correct_answer} · ${q.n} lượt trả lời`));
      row.append(text, el('strong', pct < 40 ? 'adm-low' : 'adm-mid', `${pct}% đúng`)); return row;
    }) : [el('p', 'hint', 'Cần ít nhất 3 lượt trả lời cho một câu.')]));
  }
  function reportExcel() {
    if (!report || !window.XLSX) return;
    const book = XLSX.utils.book_new(), sheet = (name, rows) => XLSX.utils.book_append_sheet(book, XLSX.utils.aoa_to_sheet(rows), name);
    const table = rows => [['Tên', 'Điểm TB (/10)', 'Lượt làm', 'Số buổi'], ...rows.map(r => [r.label, r.avg, r.students, r.rooms])];
    sheet('Giao vien', table(report.by_teacher)); sheet('Lop', table(report.by_class)); sheet('Bo de', table(report.by_exam));
    sheet('Cau kho', [['Câu', 'Nội dung', 'Bộ đề', 'Giáo viên', 'Đáp án', 'Lượt trả lời', '% đúng'], ...report.hardest.map(q => [`Q${q.question_no}`, q.question_text, q.title, q.username, q.correct_answer, Number(q.n), Math.round(q.ok / q.n * 100)])]);
    XLSX.writeFile(book, `clicker-bao-cao-${new Date().toISOString().slice(0, 10)}.xlsx`);
  }

  /* ---------- Nhật ký (select rows to delete) ---------- */
  const picked = new Set();
  function syncPicked() {
    $('adm-log-del').disabled = !picked.size; $('adm-log-del').textContent = picked.size ? `Xoá đã chọn (${picked.size})` : 'Xoá đã chọn';
    $('adm-log-all').checked = logRows.length > 0 && logRows.every(r => picked.has(Number(r.id)));
  }
  function drawLog(ready) {
    if (ready === false) { $('adm-log').replaceChildren(el('p', 'hint', 'Nhật ký chưa bật: chạy sudo bash tools/setup_db.sh một lần.')); return; }
    let lastDay = '';
    const out = [];
    for (const r of logRows) {
      const day = String(r.at).slice(0, 10);
      if (day !== lastDay) { out.push(el('div', 'adm-log-day', new Date(day).toLocaleDateString('vi-VN', {weekday:'long', day:'2-digit', month:'2-digit', year:'numeric'}))); lastDay = day; }
      const row = el('label', 'adm-log-row ' + (r.action.startsWith('admin_') ? 'is-admin' : r.action === 'login_fail' ? 'is-bad' : ''));
      const box = el('input'); box.type = 'checkbox'; box.checked = picked.has(Number(r.id));
      box.addEventListener('change', () => { box.checked ? picked.add(Number(r.id)) : picked.delete(Number(r.id)); row.classList.toggle('picked', box.checked); syncPicked(); });
      if (box.checked) row.classList.add('picked');
      row.append(box, el('time', '', String(r.at).slice(11, 16)), el('span', 'adm-log-act', ACTIONS[r.action] || r.action));
      const text = el('div'); text.append(el('b', '', r.actor_name || 'Hệ thống'), el('span', '', r.detail)); row.append(text, el('small', '', r.ip));
      out.push(row);
    }
    $('adm-log').replaceChildren(...(out.length ? out : [el('p', 'hint', 'Chưa có hoạt động nào.')]));
    syncPicked();
  }

  /* ---------- Cài đặt: phone-style switches (green on, red off) ---------- */
  const NOTES = {allow_register:'Giáo viên mới tự tạo tài khoản (mật khẩu hoặc Google). Tắt: chỉ quản trị viên tạo tài khoản.',
    assistant:'Bong bóng trợ lý AI trên mọi trang.', self_paced:'Cho chọn hình thức "Sinh viên tự làm trên máy" khi tạo phòng.',
    self_join:'Sinh viên chưa có trong danh sách được xin vào lớp (giáo viên duyệt).',
    ble_sim:'Lớp ở chế độ BLE: điện thoại / laptop mở trang sinh viên thành một Student Tag mô phỏng gửi đáp án thật (để thử khi chưa đủ tag). Tắt: chỉ tag thật.'};
  function drawSystem() {
    $('adm-switches').replaceChildren(...(extra.server.tools_ready ? extra.settings.map(x => {
      const row = el('label', 'adm-switch' + (x.on ? ' on' : '')); const input = el('input'); input.type = 'checkbox'; input.checked = x.on; input.setAttribute('role', 'switch');
      const text = el('div'); text.append(el('b', '', x.label), el('small', '', NOTES[x.name] || ''));
      const state = el('em', 'adm-switch-state', x.on ? 'Đang bật' : 'Đang tắt');
      input.addEventListener('change', async () => {
        input.disabled = true; row.classList.toggle('on', input.checked); state.textContent = input.checked ? 'Đang bật' : 'Đang tắt';
        const ok = await call('admin_setting', {name:x.name, on:input.checked}, null, `${x.label}: ${input.checked ? 'đã bật' : 'đã tắt'}.`);
        if (!ok) { input.checked = !input.checked; row.classList.toggle('on', input.checked); state.textContent = input.checked ? 'Đang bật' : 'Đang tắt'; }
        x.on = input.checked; input.disabled = false;
      });
      row.append(text, state, input, el('i', 'knob')); return row;
    }) : [el('p', 'hint', 'Chưa bật: chạy sudo bash tools/setup_db.sh một lần.')]));
  }

  function drawClean() {
    $('adm-audit-days').value = String(extra.audit_days || 90);
    $('adm-audit-days').disabled = $('adm-clean-now').disabled = !extra.server.tools_ready;
    const r = extra.table_rows || {};
    $('adm-clean-info').textContent = `Hiện có khoảng ${Number(r.audit_log || 0).toLocaleString('vi-VN')} dòng nhật ký và ${Number(r.request_limit || 0).toLocaleString('vi-VN')} dòng chống spam.`;
  }

  /* ---------- Chuông: password requests, like Facebook notifications ---------- */
  let lastCount = -1;
  async function bell() {
    if ($('nav-admin').hidden) return;
    $('bell').hidden = false;
    let rows;
    try { rows = (await api('admin_notifications', null, 'GET')).requests; } catch { return; }
    const n = rows.length;
    $('bell-count').hidden = !n; $('bell-count').textContent = n > 9 ? '9+' : n;
    if (lastCount >= 0 && n > lastCount) { $('bell-btn').classList.remove('ring'); void $('bell-btn').offsetWidth; $('bell-btn').classList.add('ring'); }
    lastCount = n;
    $('bell-sub').textContent = n ? `${n} yêu cầu đặt lại mật khẩu` : 'Không có thông báo mới';
    $('bell-list').replaceChildren(...(n ? rows.map(r => {
      const item = el('div', 'bell-item'), text = el('div');
      text.append(el('b', '', `${r.username} quên mật khẩu`), el('small', '', `${r.email || 'Tài khoản mật khẩu'} · ${when(r.created_at)} · IP ${r.ip}`));
      const tools = el('div', 'bell-actions');
      tools.append(btn('primary', 'Cấp phép', async b => { if (await call('admin_approve_request', {request_id:Number(r.id)}, b, `Đã cấp phép cho ${r.username}. Người đó đặt mật khẩu mới ngay trên máy của họ.`)) { bell(); if (data) load('quiet'); } }),
        btn('ghost', 'Từ chối', async b => { if (await call('admin_reject_request', {request_id:Number(r.id)}, b, 'Đã từ chối.')) bell(); }));
      item.append(el('span', 'adm-av', (r.username.trim()[0] || '?').toUpperCase()), text, tools); return item;
    }) : [el('p', 'bell-empty', 'Chưa có ai cần cấp phép.')]));
  }

  /* ---------- events ---------- */
  function showTab(name) {
    tab = name;
    for (const b of document.querySelectorAll('[data-adm]')) b.setAttribute('aria-selected', String(b.dataset.adm === name));
    for (const p of document.querySelectorAll('[data-pane]')) p.hidden = p.dataset.pane !== name;
    if (name === 'devices') load('devices'); else if (name === 'report') load('report'); else if (name === 'log') loadLog(true).catch(e => notify(e.message, true));
  }
  document.addEventListener('DOMContentLoaded', () => {
    for (const b of document.querySelectorAll('[data-adm]')) b.addEventListener('click', () => showTab(b.dataset.adm));
    $('adm-refresh').addEventListener('click', () => { report = null; load(tab); });
    $('adm-backup').addEventListener('click', () => { location.href = 'api.php?action=admin_backup'; notify('Đang tạo bản sao lưu… file sẽ tự tải về.'); });
    $('adm-search').addEventListener('input', () => data && drawTeachers());
    $('adm-dev-search').addEventListener('input', () => devices && drawDevices());
    for (const b of document.querySelectorAll('#adm-dev-filter [data-kind]')) b.addEventListener('click', () => {
      devKind = b.dataset.kind; for (const x of document.querySelectorAll('#adm-dev-filter [data-kind]')) x.setAttribute('aria-pressed', String(x === b)); drawDevices();
    });
    let logTimer = 0;
    $('adm-log-search').addEventListener('input', () => { clearTimeout(logTimer); logTimer = setTimeout(() => loadLog(true), 300); });
    $('adm-log-more').addEventListener('click', () => loadLog(false));
    $('adm-log-all').addEventListener('change', e => { for (const r of logRows) e.target.checked ? picked.add(Number(r.id)) : picked.delete(Number(r.id)); drawLog(true); });
    $('adm-log-del').addEventListener('click', async () => {
      if (!picked.size || !confirm(T(`Xoá ${picked.size} dòng nhật ký đã chọn?`))) return;
      if (await call('admin_audit_delete', {ids:[...picked]}, $('adm-log-del'), 'Đã xoá.')) { picked.clear(); loadLog(true); }
    });
    $('adm-log-clear').addEventListener('click', async () => {
      if (!confirm(T('Xoá toàn bộ lịch sử nhật ký? Không khôi phục được.'))) return;
      if (await call('admin_audit_delete', {ids:'all'}, $('adm-log-clear'), 'Đã xoá toàn bộ lịch sử.')) { picked.clear(); loadLog(true); }
    });
    const cleaned = r => `Đã dọn ${r.cleaned.log} dòng nhật ký và ${r.cleaned.limits} dòng chống spam.`;
    $('adm-audit-days').addEventListener('change', async e => { const r = await call('admin_audit_days', {days:Number(e.target.value)}, e.target); if (r) { notify(cleaned(r)); load('quiet'); } });
    $('adm-clean-now').addEventListener('click', async e => { const r = await call('admin_cleanup', {}, e.currentTarget); if (r) { notify(cleaned(r)); load('quiet'); } });
    $('adm-t-all').addEventListener('change', e => { for (const t of data.teachers) if (Number(t.id) !== data.me) e.target.checked ? pickedT.add(Number(t.id)) : pickedT.delete(Number(t.id)); drawTeachers(); syncT(); });
    $('adm-t-del').addEventListener('click', async e => {
      const n = pickedT.size; if (!n) return;
      const names = data.teachers.filter(t => pickedT.has(Number(t.id))).map(t => t.profile?.full_name || t.username).join(', ');
      const typed = prompt(T(`Xoá vĩnh viễn ${n} tài khoản (${names}) cùng toàn bộ lớp, bộ đề, buổi học của họ?\nGõ XOA ${n} để xác nhận:`));
      if (typed === null) return;
      const r = await call('admin_delete_teachers', {ids:[...pickedT], confirmed:typed.trim()}, e.currentTarget);
      if (r) { notify(T(`Đã xoá ${r.deleted} tài khoản.`)); pickedT.clear(); load(); }
    });
    $('adm-d-all').addEventListener('change', e => { for (const d of shownDevices) e.target.checked ? pickedD.add(d.device_uid) : pickedD.delete(d.device_uid); drawDevices(); });
    const delDevices = async (uids, button, ask) => {
      if (!confirm(ask)) return;
      const r = await call('admin_delete_devices', {uids}, button);
      if (r) { notify(T(`Đã xoá ${r.deleted} thiết bị.`) + (r.kept ? ' ' + T(`Giữ lại ${r.kept} máy đã dùng trong bài kiểm tra hoặc đã đăng ký (dùng Chặn nếu cần).`) : '')); pickedD.clear(); load('devices'); }
    };
    $('adm-d-del').addEventListener('click', e => delDevices([...pickedD], e.currentTarget, T(`Xoá ${pickedD.size} thiết bị đã chọn khỏi danh sách?`)));
    $('adm-d-old').addEventListener('click', e => delDevices('inactive', e.currentTarget, T('Xoá mọi thiết bị không kết nối hơn 30 ngày?')));
    $('bell-btn').addEventListener('click', e => { e.stopPropagation(); $('bell-panel').hidden = !$('bell-panel').hidden; if (!$('bell-panel').hidden) bell(); });
    $('bell-panel').addEventListener('click', e => e.stopPropagation());
    document.addEventListener('click', () => { $('bell-panel').hidden = true; });
    let bellTimer = 0;
    new MutationObserver(() => { clearInterval(bellTimer); if (!$('nav-admin').hidden) { bell(); bellTimer = setInterval(bell, 8000); } else $('bell').hidden = true; }).observe($('nav-admin'), {attributes:true, attributeFilter:['hidden']});
    $('adm-report-xlsx').addEventListener('click', reportExcel);
    $('adm-create').addEventListener('submit', async e => {
      e.preventDefault();
      const username = $('adm-new-name').value.trim(), email = $('adm-new-email').value.trim(), password = $('adm-new-pass').value.trim();
      if (!username) return notify('Nhập tên đăng nhập.', true);
      const r = await call('admin_create_teacher', {username, email, password}, e.submitter);
      if (!r) return;
      $('adm-create').reset(); load();
      secret(`Tài khoản "${r.username}"`, r.password, r.email ? `Hoặc đăng nhập bằng Google với ${r.email}.` : 'Đăng nhập ở trang Đăng nhập bằng tên + mật khẩu này.');
    });
    $('adm-secret-copy').addEventListener('click', async () => { try { await navigator.clipboard.writeText($('adm-secret-pass').textContent); notify('Đã sao chép.'); } catch { notify('Không sao chép được, hãy ghi lại.', true); } });
    $('view-stop').addEventListener('click', async () => { if (await call('admin_view_stop', {}, $('view-stop'))) { location.hash = '#admin-section'; location.reload(); } });
    const sync = () => { clearInterval(timer); if (location.hash === '#admin-section') { load(tab); timer = setInterval(() => load('quiet'), 15000); } };
    addEventListener('hashchange', () => setTimeout(sync, 50));
    new MutationObserver(() => setTimeout(sync, 50)).observe($('nav-admin'), {attributes:true, attributeFilter:['hidden']});
  });
})();
