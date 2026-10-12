/* Bộ đề → "Tạo bằng AI": course text → api.php ai_generate_questions → review list (difficulty, topic, why the
   answer is right; untick what you don't want) → save with import_questions into a new or existing set.
   Uses api(), notify(), bootstrap() from app.js and ExamEditor.open() to show the saved set. */
'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const T = s => (typeof I18N !== 'undefined' ? I18N.t(s) : s);
  const LEVEL = {de:'Dễ', tb:'Trung bình', kho:'Khó', nangcao:'Nâng cao'};
  let rows = [], picked = new Set();
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };

  function count() { const n = $('ai-content').value.trim().length; $('ai-len').textContent = n ? `${n.toLocaleString('vi-VN')} ${T('ký tự')}` : ''; }
  async function readFile() {
    const f = $('ai-file').files[0]; if (!f) return;
    if (f.size > 400000) { notify(T('File quá lớn (tối đa khoảng 400 KB chữ).'), true); return; }
    $('ai-content').value = await f.text(); count();
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
      const opts = el('ol', 'ai-opts-list');
      for (const k of 'ABCD') { const li = el('li', q.correct_answer === k ? 'ok' : '', q['answer_' + k]); opts.append(li); }
      body.append(head, tags, opts);
      if (q.explanation) body.append(el('p', 'ai-why', '💡 ' + q.explanation));
      card.append(box, body); return card;
    }));
  }
  async function generate(e) {
    e?.preventDefault();
    const content = $('ai-content').value.trim();
    if (content.length < 80) { notify(T('Dán nội dung môn học (ít nhất vài câu) để AI soạn câu hỏi.'), true); return; }
    const b = $('ai-go'); b.disabled = true; const label = b.textContent; b.textContent = T('AI đang soạn câu hỏi… (10–40 giây)');
    $('ai-card').classList.add('busy');
    try {
      const r = await api('ai_generate_questions', {content, count:Number($('ai-count').value) || 10, level:$('ai-level').value, lang:$('ai-lang').value, notes:$('ai-notes').value.trim()});
      rows = r.rows; picked = new Set(rows.map((_, i) => i)); targets(); draw();
      if (!$('ai-target').value && !$('ai-title').value) $('ai-title').value = (content.split('\n').find(l => l.trim()) || 'Bộ đề AI').trim().slice(0, 80) + ' (AI)';
      notify(T(`AI đã tạo ${rows.length} câu hỏi. Xem lại rồi bấm Lưu.`));
      $('ai-result').scrollIntoView({behavior:'smooth', block:'start'});
    } catch (error) { notify(error.message, true); }
    finally { b.disabled = false; b.textContent = label; $('ai-card').classList.remove('busy'); }
  }
  async function save() {
    const list = rows.filter((_, i) => picked.has(i)).map(q => ({question_text:q.question_text, answer_A:q.answer_A, answer_B:q.answer_B, answer_C:q.answer_C, answer_D:q.answer_D, correct_answer:q.correct_answer}));
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
    $('ai-content').addEventListener('input', count);
    $('ai-target').addEventListener('change', () => { $('ai-title-row').hidden = !!$('ai-target').value; });
    $('ai-all').addEventListener('change', e => { picked = e.target.checked ? new Set(rows.map((_, i) => i)) : new Set(); draw(); });
    addEventListener('hashchange', () => { if (location.hash === '#prepare') targets(); });
    // Which model answers (the server decides: your own model, the OpenAI-compatible one, or Gemini)
    setTimeout(() => api('ai_status', null, 'GET').then(r => {
      $('ai-model').textContent = r.model ? `${T('Mô hình')}: ${r.model}` : T('Chưa cấu hình AI');
      $('ai-model').classList.toggle('ready', !!r.model); $('ai-go').disabled = !r.model;
    }).catch(() => {}), 1500);
  });
})();
