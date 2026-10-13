/* Bộ đề → "Tạo bằng AI": course text or file (PDF/DOCX/PPTX read by api.php ai_extract; questions.json opened as is)
   → ai_generate_questions with the chosen model (cloud or modelv3 on this computer) → review list (difficulty, topic,
   page the answer comes from; questions whose evidence is not in the material start unticked) → save with import_questions.
   Kết quả → "Nhận xét bài làm": ai_review for the class or one student.
   Uses api(), notify(), bootstrap(), csrf, sessionId from app.js and ExamEditor.open() to show the saved set. */
'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const T = s => (typeof I18N !== 'undefined' ? I18N.t(s) : s);
  const LEVEL = {de:'Dễ', tb:'Trung bình', kho:'Khó', nangcao:'Nâng cao'};
  let rows = [], picked = new Set(), fileTitle = '';
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };

  function count() { const n = $('ai-content').value.trim().length; $('ai-len').textContent = n ? `${n.toLocaleString('vi-VN')} ${T('ký tự')}` : ''; }
  async function readFile() {
    const f = $('ai-file').files[0]; if (!f) return;
    const ext = f.name.split('.').pop().toLowerCase();
    if (f.size > 5242880) { notify(T('File tối đa 5 MB.'), true); return; }
    if (ext === 'json') return openJson(f);
    if (['txt', 'md', 'csv'].includes(ext) && f.size < 400000) { $('ai-content').value = await f.text(); count(); return; }
    $('ai-card').classList.add('busy');
    try {
      const data = await new Promise((ok, bad) => { const r = new FileReader(); r.onload = () => ok(String(r.result).split(',')[1]); r.onerror = bad; r.readAsDataURL(f); });
      const res = await fetch('api.php?action=ai_extract', {method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-Token':csrf}, body:JSON.stringify({action:'ai_extract', name:f.name, data})});
      const r = await res.json(); if (!res.ok || !r.ok) throw new Error(r.error || T('Không đọc được file.'));
      $('ai-content').value = r.text; count(); fileTitle = f.name.replace(/\.[^.]+$/, '');
      notify(r.cut ? T('Đã đọc file (chỉ lấy 40.000 ký tự đầu, hãy chia nhỏ theo chương).') : T('Đã đọc file. Bấm Tạo câu hỏi.'));
    } catch (error) { notify(error.message, true); }
    finally { $('ai-card').classList.remove('busy'); $('ai-file').value = ''; }
  }
  // questions.json (MCQ Studio and similar): {questions:[{question, choices:{A..D}, correct_answer, difficulty, concept,
  // explanation, evidence, source:{type,index}}]} straight into the review list; same checks as the server's.
  async function openJson(f) {
    let list;
    try { const j = JSON.parse(await f.text()); list = Array.isArray(j) ? j : j.questions; } catch { list = null; }
    if (!Array.isArray(list)) { notify(T('File JSON không có danh sách questions.'), true); return; }
    const lv = {easy:'de', medium:'tb', hard:'kho', de:'de', tb:'tb', kho:'kho', nangcao:'nangcao'};
    const txt = v => String(v ?? '').replace(/^\s*[A-Da-d][.):]\s+/, '').trim();
    const seen = new Set();
    rows = list.map(q => {
      const c = q.choices || q;
      const row = {question_text:txt(q.question ?? q.question_text), answer_A:txt(c.A ?? c.answer_A), answer_B:txt(c.B ?? c.answer_B), answer_C:txt(c.C ?? c.answer_C), answer_D:txt(c.D ?? c.answer_D),
        correct_answer:String(q.correct_answer ?? q.answer ?? '').trim().toUpperCase(), difficulty:lv[q.difficulty] || 'tb', topic:String(q.concept ?? q.topic ?? '').slice(0, 80),
        explanation:String(q.explanation ?? '').slice(0, 400), evidence:String(q.evidence ?? '')};
      const src = q.source?.index ?? q.source_index;
      row.source = src ? `${q.source?.type === 'slide' ? 'Slide' : 'Trang'} ${src}` : '';
      const opts = 'ABCD'.split('').map(k => row['answer_' + k].toLowerCase());
      const key = row.question_text.toLowerCase();
      row.problem = !row.question_text || opts.some(o => !o) || !'ABCD'.includes(row.correct_answer) || row.correct_answer.length !== 1 ? T('Thiếu dữ liệu')
        : new Set(opts).size < 4 ? T('Có lựa chọn trùng nhau') : seen.has(key) ? T('Câu trùng') : '';
      seen.add(key); row.verified = !row.problem; return row;
    });
    picked = new Set(rows.map((r, i) => r.verified ? i : -1).filter(i => i >= 0)); targets(); draw();
    if (!$('ai-target').value && !$('ai-title').value) $('ai-title').value = f.name.replace(/\.json$/i, '');
    const bad = rows.length - picked.size;
    notify(T(`Đã mở ${rows.length} câu từ file`) + (bad ? T(`, ${bad} câu có lỗi đã bỏ chọn.`) : '.'), bad > 0);
    $('ai-result').scrollIntoView({behavior:'smooth', block:'start'}); $('ai-file').value = '';
  }
  function targets() {
    const sel = $('ai-target'), keep = sel.value;
    const opts = [...$('exam-select').options].filter(o => o.value);
    sel.replaceChildren(new Option(T('＋ Bộ đề mới'), ''), ...opts.map(o => new Option(T('Thêm vào: ') + o.text, o.value)));
    sel.value = opts.some(o => o.value === keep) ? keep : '';
    $('ai-title-row').hidden = !!sel.value;
  }
  function draw() {
    $('ai-result').hidden = !rows.length;
    $('ai-result-title').textContent = `${rows.length} ${T('câu hỏi')} · ${T('đã chọn')} ${picked.size}`;
    $('ai-all').checked = picked.size === rows.length;
    $('ai-save').disabled = !picked.size;
    $('ai-list').replaceChildren(...rows.map((q, i) => {
      const card = el('label', 'ai-q' + (picked.has(i) ? ' on' : ''));
      const box = el('input'); box.type = 'checkbox'; box.checked = picked.has(i);
      box.addEventListener('change', () => { box.checked ? picked.add(i) : picked.delete(i); draw(); });
      const body = el('div');
      const head = el('div', 'ai-q-head'); head.append(el('b', '', `${i + 1}. ${q.question_text}`));
      const tags = el('div', 'ai-tags'); tags.append(el('span', 'lv-' + q.difficulty, T(LEVEL[q.difficulty] || q.difficulty)));
      if (q.topic) tags.append(el('span', 'topic', q.topic));
      if (q.problem) tags.append(el('span', 'nosrc', '⚠ ' + q.problem));
      else if (q.verified === false) tags.append(el('span', 'nosrc', T('⚠ Không thấy trong tài liệu')));
      else if (q.verified) tags.append(el('span', 'src', '✓ ' + (q.source || T('Có trong tài liệu'))));
      const opts = el('ol', 'ai-opts-list');
      for (const k of 'ABCD') { const li = el('li', q.correct_answer === k ? 'ok' : '', q['answer_' + k]); opts.append(li); }
      body.append(head, tags, opts);
      if (q.explanation) body.append(el('p', 'ai-why', '💡 ' + q.explanation));
      if (q.evidence) { const ev = el('p', 'ai-why', '📄 “' + q.evidence + '”'); ev.title = T('Câu trong tài liệu làm căn cứ'); body.append(ev); }
      card.append(box, body); return card;
    }));
  }
  async function generate(e) {
    e?.preventDefault();
    const content = $('ai-content').value.trim();
    if (content.length < 80) { notify(T('Dán nội dung môn học (ít nhất vài câu) để AI soạn câu hỏi.'), true); return; }
    const local = $('ai-provider').value === 'local';
    const b = $('ai-go'); b.disabled = true; const label = b.textContent; b.textContent = T(local ? 'Model trong máy đang soạn… (30 giây – 2 phút)' : 'AI đang soạn câu hỏi… (10–40 giây)');
    $('ai-card').classList.add('busy');
    try {
      const r = await api('ai_generate_questions', {content, count:Number($('ai-count').value) || 10, level:$('ai-level').value, lang:$('ai-lang').value, notes:$('ai-notes').value.trim(), provider:$('ai-provider').value});
      rows = r.rows; picked = new Set(rows.map((q, i) => q.verified ? i : -1).filter(i => i >= 0)); if (!picked.size) picked = new Set(rows.map((_, i) => i)); targets(); draw();
      if (!$('ai-target').value && !$('ai-title').value) $('ai-title').value = (fileTitle || content.split('\n').find(l => l.trim() && !/^\[(Trang|Slide) \d+\]$/.test(l.trim())) || 'Bộ đề AI').trim().slice(0, 80) + ' (AI)';
      const asked = r.asked || rows.length;
      notify(rows.length < asked ? T(`AI chỉ tạo được ${rows.length}/${asked} câu: tài liệu có thể chưa đủ nội dung. Thêm tài liệu hoặc bấm Tạo lại.`) : T(`AI đã tạo ${rows.length} câu hỏi. Xem lại rồi bấm Lưu.`), rows.length < asked);
      $('ai-result').scrollIntoView({behavior:'smooth', block:'start'});
    } catch (error) { notify(error.message, true); }
    finally { b.disabled = false; b.textContent = label; $('ai-card').classList.remove('busy'); }
  }
  async function save() {
    const list = rows.filter((_, i) => picked.has(i)).map(q => ({question_text:q.question_text, answer_A:q.answer_A, answer_B:q.answer_B, answer_C:q.answer_C, answer_D:q.answer_D, correct_answer:q.correct_answer, topic:q.topic || '', explanation:q.explanation || ''}));
    if (!list.length) return;
    const dest = $('ai-target').value ? {exam_id:Number($('ai-target').value)} : {title:$('ai-title').value.trim()};
    if (!dest.exam_id && !dest.title) { notify(T('Nhập tên bộ đề mới.'), true); $('ai-title').focus(); return; }
    $('ai-save').disabled = true;
    try {
      const r = await api('import_questions', {...dest, rows:list, commit:true});
      if (r.errors?.length) throw new Error(T('Có câu không hợp lệ, hãy tạo lại.'));
      notify(T(`Đã lưu ${r.imported} câu hỏi.`)); rows = []; picked.clear(); draw();
      await bootstrap(); if (r.exam_id) ExamEditor.open(r.exam_id);
    } catch (error) { notify(error.message, true); }
    finally { $('ai-save').disabled = false; }
  }
  document.addEventListener('DOMContentLoaded', () => {
    $('ai-form').addEventListener('submit', generate);
    $('ai-again').addEventListener('click', generate);
    $('ai-save').addEventListener('click', save);
    $('ai-file').addEventListener('change', readFile);
    $('ai-content').addEventListener('input', () => { count(); fileTitle = ''; });
    $('ai-target').addEventListener('change', () => { $('ai-title-row').hidden = !!$('ai-target').value; });
    $('ai-all').addEventListener('change', e => { picked = e.target.checked ? new Set(rows.map((_, i) => i)) : new Set(); draw(); });
    addEventListener('hashchange', () => { if (location.hash === '#prepare') targets(); });
    // Which models answer now: the cloud one (Gemini or your own) and/or modelv3 on this computer. The choice is remembered.
    const pick = e => { try { localStorage.setItem('clicker-ai-provider', e.target.value); } catch {} for (const id of ['ai-provider', 'air-provider']) $(id).value = e.target.value; };
    $('ai-provider').addEventListener('change', pick); $('air-provider').addEventListener('change', pick);
    setTimeout(() => api('ai_status', null, 'GET').then(r => {
      const list = r.providers || [];
      let saved = ''; try { saved = localStorage.getItem('clicker-ai-provider') || ''; } catch {}
      for (const id of ['ai-provider', 'air-provider']) {
        $(id).replaceChildren(...list.map(p => new Option(p.id === 'local' ? T('Model trong máy (modelv3)') : p.name, p.id)));
        if (list.some(p => p.id === saved)) $(id).value = saved;
        $(id).hidden = list.length < 2;
      }
      $('ai-model').textContent = list.length ? (list.length < 2 ? `${T('Mô hình')}: ${list[0].id === 'local' ? 'modelv3' : list[0].name}` : '') : T('Chưa cấu hình AI');
      $('ai-model').hidden = list.length > 1;
      $('ai-model').classList.toggle('ready', list.length > 0); $('ai-go').disabled = !list.length; $('air-go').disabled = !list.length;
    }).catch(() => {}), 1500);
    $('air-go').addEventListener('click', review);
    $('air-each').addEventListener('click', reviewEach);
    $('air-each-csv').addEventListener('click', eachCsv);
  });

  // ---- Kết quả → Nhận xét bài làm
  // ---- Gợi ý ôn tập cho từng bạn: students who answered, sent in small groups; each card fills in when its group returns.
  let each = {room:'', list:[], got:{}, failed:new Set(), running:false};
  const label = s => (typeof privacy !== 'undefined' && privacy) ? `${T('Sinh viên')} #${s.student_no}` : `${s.student_id} · ${s.student_name}`;
  function drawEach() {
    $('air-each-list').replaceChildren(...each.list.map(s => {
      const v = each.got[s.student_id], card = el('div', 'air-st' + (v ? '' : each.failed.has(s.student_id) ? ' fail' : ' wait'));
      const head = el('div', 'air-st-head'); head.append(el('b', '', label(s)));
      const sc = Number(s.score10); head.append(el('span', 'air-score ' + (sc >= 8 ? 'hi' : sc >= 5 ? 'mid' : 'lo'), `${sc}/10`));
      card.append(head);
      if (!v) { card.append(el('p', '', each.failed.has(s.student_id) ? T('Chưa có nhận xét, bấm lại để thử lại.') : T('Đang chờ AI…'))); return card; }
      if (v.summary) card.append(el('p', '', v.summary));
      if (v.topics.length) card.append(el('p', 'air-topics', T('Cần ôn') + ': ' + v.topics.join(' · ')));
      if (v.advice.length) { const ul = el('ul'); for (const t of v.advice) ul.append(el('li', '', t)); card.append(ul); }
      return card;
    }));
  }
  async function reviewEach() {
    if (!sessionId || each.running) return;
    const list = each.pool || []; if (!list.length) { notify(T('Chưa có sinh viên nào làm bài.'), true); return; }
    each = {...each, room:sessionId, list, got:{}, failed:new Set(), running:true};
    const provider = $('air-provider').value, local = provider === 'local', size = local ? 5 : 10, lanes = local ? 2 : 3;
    const lang = (typeof I18N !== 'undefined' && I18N.lang) || 'vi';
    const queue = []; for (let i = 0; i < list.length; i += size) queue.push(list.slice(i, i + size).map(s => s.student_id));
    const state = $('air-each-state'), b = $('air-each'); b.disabled = true; state.hidden = false; $('air-each-csv').hidden = true;
    const done = () => Object.keys(each.got).length;
    const progress = () => { state.textContent = `${done()}/${list.length}`; };
    progress(); drawEach();
    const work = async () => {
      while (queue.length) {
        const ids = queue.shift();
        for (let attempt = 0; attempt < 2 && ids.length; attempt++) {   // the model sometimes skips someone: ask once more for the missing
          try {
            const r = await api('ai_review_students', {session_id:each.room, student_ids:ids, provider, lang});
            for (const id of Object.keys(r.reviews)) { each.got[id] = r.reviews[id]; each.failed.delete(id); ids.splice(ids.indexOf(id), 1); }
          } catch (error) { if (attempt) notify(error.message, true); }
          progress(); drawEach();
        }
        for (const id of ids) each.failed.add(id);
        drawEach();
      }
    };
    try { await Promise.all(Array.from({length:Math.min(lanes, queue.length)}, work)); }
    finally { each.running = false; b.disabled = false; state.hidden = !each.failed.size; if (each.failed.size) state.textContent = `${each.failed.size} ${T('chưa có')}`; $('air-each-csv').hidden = !done(); b.textContent = T('✨ Làm lại'); drawEach(); }
  }
  // MSSV, name, score, summary, topics, tips → a CSV the teacher can send on (Excel-safe: BOM, quoted, no formulas).
  function eachCsv() {
    const q = v => { v = String(v ?? '').replace(/\r?\n/g, ' '); if (/^[=+\-@]/.test(v)) v = "'" + v; return '"' + v.replace(/"/g, '""') + '"'; };
    const rowsOut = [['MSSV', 'Họ tên', 'Điểm /10', 'Nhận xét', 'Chủ đề cần ôn', 'Gợi ý ôn tập']].concat(each.list.filter(s => each.got[s.student_id]).map(s => {
      const v = each.got[s.student_id]; return [s.student_id, s.student_name, s.score10, v.summary, v.topics.join('; '), v.advice.join(' | ')]; }));
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + rowsOut.map(r => r.map(q).join(',')).join('\r\n')], {type:'text/csv;charset=utf-8'}));
    a.download = 'goi-y-on-tap.csv'; a.click(); URL.revokeObjectURL(a.href);
  }
  window.AIReview = {students(roster, report) {
    // Only students who answered something can be reviewed one by one.
    const done = new Set(report.rows.filter(r => Object.values(r.answers).some(Boolean)).map(r => r.student_id));
    const list = roster.filter(s => done.has(s.student_id));
    const score = new Map(report.rows.map(r => [r.student_id, r.score10]));
    each.pool = list.map(s => ({...s, score10:score.get(s.student_id) ?? 0}));
    if (each.room && each.room !== sessionId) { each = {room:'', list:[], got:{}, failed:new Set(), running:false, pool:each.pool}; drawEach(); $('air-each-csv').hidden = true; $('air-each').textContent = T('✨ Gợi ý cho từng bạn'); }
    else if (each.list.length) { each.list = each.pool.filter(s => each.list.some(o => o.student_id === s.student_id)); drawEach(); }
    // Privacy on (projector): only the row number, like the results table.
    const hide = typeof privacy !== 'undefined' && privacy;
    const sig = hide + list.map(s => s.student_id).join(',');
    if ($('air-who').dataset.sig === sig) return;
    const keep = $('air-who').value; $('air-who').dataset.sig = sig;
    $('air-who').replaceChildren(new Option(T('Cả lớp'), ''), ...list.map(s => new Option(hide ? `${T('Sinh viên')} #${s.student_no}` : `${s.student_id} · ${s.student_name}`, s.student_id)));
    $('air-who').value = list.some(s => s.student_id === keep) ? keep : '';
  }};
  async function review() {
    if (!sessionId) { notify(T('Chọn một phòng trước.'), true); return; }
    const b = $('air-go'), label = b.textContent; b.disabled = true; b.textContent = T('AI đang đọc kết quả…'); $('ai-review').classList.add('busy');
    try {
      const lang = (typeof I18N !== 'undefined' && I18N.lang) || 'vi';
      const r = await api('ai_review', {session_id:sessionId, student_id:$('air-who').value, provider:$('air-provider').value, lang});
      const out = $('air-out'), v = r.review, f = r.facts;
      const box = (cls, title, items) => { if (!items.length) return null; const d = el('div', 'air-box ' + cls); d.append(el('h4', '', T(title))); const ul = el('ul'); for (const t of items) ul.append(el('li', '', t)); d.append(ul); return d; };
      const facts = f.student ? `${T('Điểm')}: ${f.student.score10}/10 · ${T('đúng')} ${f.student.right}/${f.questions_done}`
        : `${f.class.students_answered} ${T('sinh viên đã làm')} · ${T('điểm trung bình')} ${f.class.average_score10}/10 · ${T('cao nhất')} ${f.class.highest} · ${T('thấp nhất')} ${f.class.lowest}`;
      out.replaceChildren(...[el('p', 'air-sum', v.summary || T('AI không đưa ra tóm tắt.')), box('good', 'ĐIỂM MẠNH', v.strengths), box('bad', 'CẦN CẢI THIỆN', v.weaknesses),
        box('', 'CHỦ ĐỀ CẦN ÔN', v.topics), box('', 'GỢI Ý ÔN TẬP', v.advice), el('p', 'air-facts', facts)].filter(Boolean));
      out.hidden = false;
    } catch (error) { notify(error.message, true); }
    finally { b.disabled = false; b.textContent = label; $('ai-review').classList.remove('busy'); }
  }
})();
