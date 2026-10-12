/* Bộ đề tab → question sets (Đợt 3): import from Excel / CSV / TXT / paste with a check-before-save preview, and the
   set editor (edit text and choices, one-click answer key, add, delete unused). Uses api(), notify() and bootstrap()
   from app.js; the server re-checks everything (api.php import_questions, save_question, remove_question). */
'use strict';
const ExamEditor = (() => {
  const $ = id => document.getElementById(id);
  const KEYS = ['A', 'B', 'C', 'D'];
  let exams = [], rows = null, openId = 0, openQuestions = [];

  async function run(button, work) {
    if (button) button.disabled = true;
    try { await work(); } catch (error) { notify(error.message, true); say(error.message, true); }
    finally { if (button) button.disabled = false; }
  }
  // Messages near the form too: the top notice is out of sight on a long page.
  function say(text, bad = false) {
    const badge = $('preview-badge');
    if (!$('exam-preview').hidden) { badge.textContent = text; badge.className = 'state-badge ' + (bad ? 'bad' : 'ready'); }
  }

  /* ---------- reading Excel / CSV / pasted cells into question rows ---------- */
  const norm = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd').toLowerCase().replace(/[^a-z0-9]/g, '');
  const HEAD = {
    question_text: ['cauhoi', 'noidung', 'noidungcauhoi', 'question', 'de', 'debai'],
    answer_A: ['a', 'dapana', 'luachona', 'phuongana', 'optiona'], answer_B: ['b', 'dapanb', 'luachonb', 'phuonganb', 'optionb'],
    answer_C: ['c', 'dapanc', 'luachonc', 'phuonganc', 'optionc'], answer_D: ['d', 'dapand', 'luachond', 'phuongand', 'optiond'],
    correct_answer: ['dapan', 'dapandung', 'dapanchinhxac', 'answer', 'correct', 'key', 'ketqua'],
  };
  function toRows(table) {
    table = table.filter(r => r.some(c => String(c).trim() !== ''));
    if (!table.length) throw new Error('Không có dòng nào để nhập.');
    const head = table[0].map(norm);
    const col = {};
    for (const [key, names] of Object.entries(HEAD)) col[key] = head.findIndex(h => names.includes(h));
    let body;
    if (col.question_text >= 0 && col.correct_answer >= 0 && KEYS.every(k => col['answer_' + k] >= 0)) body = table.slice(1);
    else {
      // No header: Câu hỏi, A, B, C, D, Đáp án — with an optional STT column first.
      const first = table[0];
      const skip = first.length >= 7 && /^\d+$/.test(String(first[0]).trim()) ? 1 : 0;
      ['question_text', 'answer_A', 'answer_B', 'answer_C', 'answer_D', 'correct_answer'].forEach((k, i) => col[k] = i + skip);
      body = table;
    }
    return body.map(r => Object.fromEntries(Object.entries(col).map(([k, i]) => [k, String(r[i] ?? '').trim()])));
  }
  function sheetTable(workbook) {
    return XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], { header: 1, defval: '', raw: false });
  }
  async function readSource() {
    const file = $('exam-file').files[0], pasted = $('exam-paste').value;
    if (file) {
      if (file.size > 2 * 1024 * 1024) throw new Error('File đề tối đa 2 MB.');
      if (/\.txt$/i.test(file.name)) return { txt: await file.text() };
      if (!window.XLSX) throw new Error('Chưa tải được thư viện Excel.');
      // CSV as UTF-8 text (as bytes SheetJS would read Vietnamese as Latin-1).
      const book = /\.csv$/i.test(file.name) ? XLSX.read(await file.text(), { type: 'string', raw: true }) : XLSX.read(await file.arrayBuffer(), { type: 'array', raw: true });
      return { rows: toRows(sheetTable(book)) };
    }
    if (pasted.trim()) {
      if (!window.XLSX) throw new Error('Chưa tải được thư viện Excel.');
      // Excel copies cells as tab-separated text (quoted when a cell has line breaks); SheetJS reads that format.
      return { rows: toRows(sheetTable(XLSX.read(pasted, { type: 'string', raw: true, FS: '\t' }))) };
    }
    throw new Error('Chọn file đề hoặc dán dữ liệu từ Excel.');
  }
  function target() {
    const id = $('exam-target').value;
    if (id) return { exam_id: Number(id) };
    const title = $('exam-title').value.trim();
    if (!title) { $('exam-title').focus(); throw new Error('Nhập tên bộ đề mới.'); }
    return { title };
  }

  /* ---------- preview ---------- */
  function cell(text, cls) { const td = document.createElement('td'); td.textContent = text; if (cls) td.className = cls; return td; }
  function showPreview(list, errors) {
    const box = $('exam-preview'), body = $('preview-table').tBodies[0];
    box.hidden = false; body.replaceChildren();
    const bad = new Map(errors.map(e => [e.row, e.error]));
    list.forEach((r, i) => {
      const tr = document.createElement('tr'); if (bad.has(i + 1)) tr.className = 'bad-row';
      tr.append(cell(String(i + 1)), cell(r.question_text, 'q-cell'), ...KEYS.map(k => cell(r['answer_' + k])), cell(String(r.correct_answer).toUpperCase(), 'key-cell'));
      body.append(tr);
    });
    const errBox = $('preview-errors'); errBox.replaceChildren();
    for (const e of errors) { const p = document.createElement('p'); p.textContent = `Dòng ${e.row}: ${e.error}`; errBox.append(p); }
    $('preview-title').textContent = errors.length ? `${list.length} câu · ${errors.length} dòng lỗi` : `${list.length} câu hợp lệ`;
    say(errors.length ? 'Sửa dòng lỗi rồi kiểm tra lại' : 'Sẵn sàng lưu', !!errors.length);
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function resetCheck() { rows = null; $('exam-save').disabled = true; }

  async function check() {
    resetCheck();
    const src = await readSource(), dest = target();
    if (src.txt !== undefined) {
      if (dest.exam_id) throw new Error('File TXT chỉ tạo bộ đề mới. Muốn thêm câu vào bộ đề có sẵn thì dùng Excel hoặc dán từ Excel.');
      rows = { txt: src.txt }; $('exam-save').disabled = false;
      $('exam-preview').hidden = true;
      notify('File TXT được kiểm tra khi lưu. Bấm “Lưu bộ đề”.');
      return;
    }
    const result = await api('import_questions', { ...dest, rows: src.rows, commit: false });
    showPreview(src.rows, result.errors);
    if (!result.errors.length) { rows = { list: src.rows, dest }; $('exam-save').disabled = false; notify(`${src.rows.length} câu hợp lệ. Bấm “Lưu bộ đề”.`); }
    else notify(`Có ${result.errors.length} dòng lỗi. Sửa trong Excel rồi kiểm tra lại.`, true);
  }
  async function save() {
    if (!rows) return;
    let examId, count;
    if (rows.txt !== undefined) {
      const r = await api('import_exam', { title: target().title, text: rows.txt });
      examId = r.exam_id; count = r.imported;
    } else {
      const r = await api('import_questions', { ...rows.dest, rows: rows.list, commit: true });
      examId = r.exam_id; count = r.imported;
    }
    resetCheck();
    $('exam-file').value = ''; $('exam-paste').value = ''; $('exam-title').value = '';
    $('exam-preview').hidden = true;
    await bootstrap();
    $('exam-select').value = String(examId);
    notify(`Đã nhập ${count} câu hỏi.`);
    await open(examId);
  }

  function template(e) {
    e.preventDefault();
    if (!window.XLSX) return notify('Chưa tải được thư viện Excel.', true);
    const sheet = XLSX.utils.aoa_to_sheet([
      ['STT', 'Câu hỏi', 'A', 'B', 'C', 'D', 'Đáp án'],
      [1, 'Điện áp có đơn vị là gì?', 'Volt', 'Ampere', 'Ohm', 'Watt', 'A'],
      [2, 'Điện trở có đơn vị là gì?', 'Volt', 'Ampere', 'Ohm', 'Watt', 'C'],
      [3, 'Công suất có đơn vị là gì?', 'Volt', 'Ampere', 'Ohm', 'Watt', 'D'],
    ]);
    sheet['!cols'] = [{ wch: 5 }, { wch: 46 }, { wch: 16 }, { wch: 16 }, { wch: 16 }, { wch: 16 }, { wch: 9 }];
    const book = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(book, sheet, 'Cau hoi');
    XLSX.writeFile(book, 'mau-de-clicker.xlsx');
  }

  /* ---------- editor ---------- */
  function button(text, cls, onClick) {
    const b = document.createElement('button'); b.type = 'button'; b.className = cls; b.textContent = text;
    b.addEventListener('click', () => run(b, onClick)); return b;
  }
  function payload(q, changes = {}) {
    const p = { exam_id: q.exam_id ?? openId, question_text: q.question_text, correct_answer: q.correct_answer, ...changes };
    for (const k of KEYS) p['answer_' + k] = changes['answer_' + k] ?? q['answer_' + k];
    if (q.id) p.question_id = Number(q.id);
    return p;
  }
  function row(q) {
    const el = document.createElement('div'); el.className = 'qrow'; el.dataset.id = q.id;
    const head = document.createElement('div'); head.className = 'qrow-head';
    const no = document.createElement('span'); no.className = 'qno'; no.textContent = 'Q' + q.question_no;
    const text = document.createElement('p'); text.className = 'qtext'; text.textContent = q.question_text;
    const keys = document.createElement('div'); keys.className = 'keypick'; keys.setAttribute('role', 'radiogroup'); keys.setAttribute('aria-label', 'Đáp án đúng');
    for (const k of KEYS) {
      const b = document.createElement('button'); b.type = 'button'; b.textContent = k; b.setAttribute('role', 'radio');
      b.setAttribute('aria-checked', String(q.correct_answer === k)); b.title = q['answer_' + k];
      b.addEventListener('click', () => run(null, async () => {
        if (q.correct_answer === k) return;
        await api('save_question', payload(q, { correct_answer: k }));
        notify(Number(q.used) ? `Đã đổi đáp án Q${q.question_no} thành ${k}. Điểm các buổi đã dùng câu này được chấm lại.` : `Đã đổi đáp án Q${q.question_no} thành ${k}.`);
        await load();
      }));
      keys.append(b);
    }
    const tools = document.createElement('div'); tools.className = 'actions';
    tools.append(button('Sửa', 'secondary', async () => el.replaceWith(form(q))));
    if (!Number(q.used)) tools.append(button('Xóa', 'danger', async () => {
      if (!confirm(I18N.t(`Xóa Q${q.question_no}?`))) return;
      await api('remove_question', { question_id: Number(q.id), confirmed: true });
      notify(`Đã xóa Q${q.question_no}.`); await load(); await bootstrap();
    }));
    head.append(no, text, keys, tools);
    const opts = document.createElement('ol'); opts.className = 'qopts';
    for (const k of KEYS) { const li = document.createElement('li'); li.textContent = q['answer_' + k]; if (q.correct_answer === k) li.className = 'ok'; opts.append(li); }
    el.append(head, opts);
    if (Number(q.used)) { const tag = document.createElement('span'); tag.className = 'used-tag'; tag.textContent = `Đã dùng trong ${q.used} buổi`; el.append(tag); }
    return el;
  }
  // done() runs after save or cancel: reload the set editor, or close the quick-edit dialog on the Live tab.
  function form(q, done = load) {
    const f = document.createElement('form'); f.className = 'qform';
    const title = document.createElement('label'); title.textContent = q.id ? `Sửa Q${q.question_no}` : 'Câu hỏi mới';
    const text = document.createElement('textarea'); text.rows = 3; text.required = true; text.maxLength = 8000; text.value = q.question_text || ''; text.placeholder = 'Nội dung câu hỏi';
    title.append(text); f.append(title);
    const grid = document.createElement('div'); grid.className = 'qform-grid';
    const inputs = {};
    for (const k of KEYS) {
      const line = document.createElement('label'); line.className = 'qopt';
      const radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'key'; radio.value = k; radio.checked = (q.correct_answer || 'A') === k;
      radio.setAttribute('aria-label', `${k} là đáp án đúng`);
      const letter = document.createElement('b'); letter.textContent = k;
      const input = document.createElement('input'); input.required = true; input.maxLength = 8000; input.value = q['answer_' + k] || ''; input.placeholder = `Lựa chọn ${k}`;
      inputs[k] = input; line.append(radio, letter, input); grid.append(line);
    }
    const hint = document.createElement('p'); hint.className = 'hint'; hint.textContent = 'Chấm tròn bên trái = đáp án đúng.';
    const tools = document.createElement('div'); tools.className = 'actions';
    const ok = document.createElement('button'); ok.className = 'primary'; ok.textContent = 'Lưu câu hỏi';
    tools.append(ok, button('Hủy', 'secondary', async () => done(false)));
    f.append(grid, hint, tools);
    f.addEventListener('submit', e => { e.preventDefault(); run(ok, async () => {
      const changes = { question_text: text.value, correct_answer: f.querySelector('input[name=key]:checked').value };
      for (const k of KEYS) changes['answer_' + k] = inputs[k].value;
      const r = await api('save_question', payload(q, changes));
      notify(q.id ? (r.used ? `Đã lưu Q${q.question_no}. Điểm các buổi đã dùng câu này được chấm lại.` : `Đã lưu Q${q.question_no}.`) : 'Đã thêm câu hỏi.');
      await done(true); if (!q.id) await bootstrap();
    }); });
    requestAnimationFrame(() => text.focus());
    return f;
  }
  async function load() {
    const data = await api('exam_questions', { exam_id: openId }, 'GET');
    openQuestions = data.questions;
    $('editor-title').textContent = `${data.exam.title} · ${data.questions.length} câu`;
    if (document.activeElement !== $('editor-name')) $('editor-name').value = data.exam.title;
    $('editor-live').hidden = !data.live_session;
    $('editor-live').textContent = data.live_session ? `Bộ đề đang dùng trong phòng #${data.live_session}: câu thêm mới có ngay ở lượt NEXT; đổi đáp án thì điểm tự chấm lại.` : '';
    $('editor-list').replaceChildren(...data.questions.map(row));
  }
  async function open(id) {
    openId = Number(id);
    $('exam-editor').hidden = false;
    await load();
    $('exam-editor').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  // Live tab: edit the question on screen without leaving the room.
  async function quickEdit(examId, questionId) {
    const data = await api('exam_questions', { exam_id: examId }, 'GET');
    const q = data.questions.find(row => Number(row.id) === questionId);
    if (!q) return notify('Không tìm thấy câu hỏi.', true);
    q.exam_id = examId;
    const dialog = $('quick-edit');
    const done = async saved => {
      dialog.close();
      if (saved) { await refresh(true); if (openId === examId) await load(); }
    };
    const note = document.createElement('p'); note.className = 'hint';
    note.textContent = 'Lưu xong, màn giáo viên và màn chiếu đổi ngay. Đổi đáp án đúng thì điểm tự chấm lại.';
    $('quick-edit-body').replaceChildren(form(q, done), note);
    dialog.showModal();
  }
  function sync(list) {
    exams = list;
    const sel = $('exam-target'), keep = sel.value;
    sel.replaceChildren(new Option('＋ Bộ đề mới', ''), ...exams.map(e => new Option(`Thêm vào: ${e.title} · ${e.count} câu`, e.id)));
    sel.value = exams.some(e => String(e.id) === keep) ? keep : '';
    $('exam-title-row').hidden = !!sel.value;
    if (openId && !exams.some(e => Number(e.id) === openId)) { openId = 0; $('exam-editor').hidden = true; }
  }

  document.addEventListener('DOMContentLoaded', () => {
    $('exam-import').addEventListener('submit', e => { e.preventDefault(); run($('exam-check'), check); });
    $('exam-save').addEventListener('click', () => run($('exam-save'), save));
    $('exam-template').addEventListener('click', template);
    $('exam-target').addEventListener('change', () => { $('exam-title-row').hidden = !!$('exam-target').value; resetCheck(); });
    $('exam-file').addEventListener('change', () => { if ($('exam-file').files[0]) $('exam-paste').value = ''; resetCheck(); });
    $('exam-paste').addEventListener('input', () => { if ($('exam-paste').value.trim()) $('exam-file').value = ''; resetCheck(); });
    $('exam-title').addEventListener('input', resetCheck);
    $('editor-close').addEventListener('click', () => { openId = 0; $('exam-editor').hidden = true; });
    $('editor-add').addEventListener('click', () => {
      if ($('editor-list').querySelector('.qform.new')) return;
      const f = form({ question_no: openQuestions.length + 1 }); f.classList.add('new');
      $('editor-list').append(f); f.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    $('editor-rename').addEventListener('submit', e => { e.preventDefault(); run(e.submitter, async () => {
      await api('rename_exam', { exam_id: openId, title: $('editor-name').value.trim() });
      notify('Đã đổi tên bộ đề.'); await load(); await bootstrap();
    }); });
  });
  return { open, sync, quickEdit };
})();
