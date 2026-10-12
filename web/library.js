/* Bộ đề → "Soạn tay": type one question, its 4 options and the right one, save, type the next. The first save creates
   the set (import_questions with a title); after that the form keeps adding to it, and the editor below shows the set.
   Uses api(), notify(), bootstrap() from app.js and ExamEditor.open(). */
'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const T = s => (typeof I18N !== 'undefined' ? I18N.t(s) : s);
  let added = 0;

  // "Lưu vào" lists the teacher's sets, kept in step with the room's set picker that app.js fills.
  function targets(keep = $('hand-target').value) {
    const sel = $('hand-target'), opts = [...$('exam-select').options].filter(o => o.value);
    sel.replaceChildren(new Option(T('＋ Bộ đề mới'), ''), ...opts.map(o => new Option(o.text, o.value)));
    sel.value = opts.some(o => o.value === String(keep)) ? String(keep) : '';
    $('hand-title-row').hidden = !!sel.value;
  }
  function clear() {
    $('hand-q').value = ''; for (const k of 'ABCD') $('hand-' + k).value = '';
    document.querySelector('input[name=hand-key][value=A]').checked = true;
    $('hand-q').focus();
  }
  async function save(e) {
    e.preventDefault();
    const row = {question_text:$('hand-q').value.trim(), correct_answer:document.querySelector('input[name=hand-key]:checked')?.value || 'A'};
    for (const k of 'ABCD') row['answer_' + k] = $('hand-' + k).value.trim();
    if (!row.question_text) { notify(T('Nhập câu hỏi.'), true); return $('hand-q').focus(); }
    const empty = [...'ABCD'].find(k => !row['answer_' + k]);
    if (empty) { notify(T(`Nhập lựa chọn ${empty}.`), true); return $('hand-' + empty).focus(); }
    const dest = $('hand-target').value ? {exam_id:Number($('hand-target').value)} : {title:$('hand-title').value.trim()};
    if (!dest.exam_id && !dest.title) { notify(T('Nhập tên bộ đề.'), true); return $('hand-title').focus(); }
    const button = $('hand-save'); button.disabled = true;
    try {
      const r = await api('import_questions', {...dest, rows:[row], commit:true});
      if (r.errors?.length) throw new Error(r.errors[0].error);
      added++; $('hand-count').textContent = T(`Đã thêm ${added} câu`);
      await bootstrap(); targets(r.exam_id); clear();
      if (r.exam_id) ExamEditor.open(r.exam_id);
    } catch (error) { notify(error.message, true); }
    finally { button.disabled = false; }
  }
  document.addEventListener('DOMContentLoaded', () => {
    $('hand-form').addEventListener('submit', save);
    $('hand-target').addEventListener('change', () => { $('hand-title-row').hidden = !!$('hand-target').value; added = 0; $('hand-count').textContent = ''; });
    // Ctrl+Enter anywhere in the form saves, so a teacher can type question after question without the mouse.
    $('hand-form').addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) save(e); });
    new MutationObserver(() => targets()).observe($('exam-select'), {childList:true});
    targets();
  });
})();
