/* Floating chat assistant. Talks only to assistant.php (next to this script); the model and its key live on the server.
   Conversation is kept for this browser tab in sessionStorage. Works with or without i18n.js. */
'use strict';
(() => {
  const T = (s, v) => (typeof I18N !== 'undefined' ? I18N.t(s, v) : s);
  const LANG = () => (typeof I18N !== 'undefined' ? I18N.lang : 'vi');
  const KEY = 'clicker-chat';
  // assistant.php sits next to this script, so the bubble also works on pages outside landing/.
  const API = new URL('assistant.php', document.currentScript?.src || location.href).href;
  const CHIPS = ['Clicker là gì?', 'Cần những thiết bị gì?', 'Bảng giá thế nào?', 'Làm sao tạo bộ đề?'];
  const ERR = {
    AI_NOT_CONFIGURED: 'Trợ lý AI chưa được bật trên máy chủ.',
    RATE_LIMIT: 'Bạn hỏi hơi nhanh, chờ vài phút rồi hỏi tiếp nhé.',
    AI_BUSY: 'Trợ lý đang bận, thử lại sau ít giây nhé.',
  };
  const esc = s => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  /* Minimal, safe markdown: escape first, then **bold**, bullet lists and paragraphs. */
  function md(text) {
    const lines = esc(text).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').split('\n');
    let html = '', list = false;
    for (const raw of lines) {
      const l = raw.trim(), m = l.match(/^(?:[-*•]|\d+[.)])\s+(.*)/);
      if (m) { if (!list) { html += '<ul>'; list = true; } html += `<li>${m[1]}</li>`; continue; }
      if (list) { html += '</ul>'; list = false; }
      if (l) html += `<p>${l}</p>`;
    }
    return html + (list ? '</ul>' : '');
  }

  let msgs = [];
  try { msgs = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch {}
  const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify(msgs.slice(-30))); } catch {} };
  let enabled = null, busy = false;

  const root = document.createElement('div');
  root.setAttribute('data-noi18n', '');
  root.innerHTML = `
    <button class="ai-fab" type="button" aria-haspopup="dialog"><span>C</span><i class="dot"></i></button>
    <section class="ai-panel" role="dialog" aria-modal="false">
      <header class="ai-head"><span class="ai-av">C</span>
        <div><b class="t-name"></b><small class="t-state"></small></div>
        <button class="x" type="button">×</button></header>
      <div class="ai-body" aria-live="polite"></div>
      <div class="ai-foot">
        <form class="ai-form"><textarea rows="1" maxlength="1000"></textarea>
          <button type="submit"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg></button></form>
        <p class="ai-note"></p></div>
    </section>`;
  document.body.append(root);
  const $ = s => root.querySelector(s);
  const fab = $('.ai-fab'), panel = $('.ai-panel'), body = $('.ai-body'), ta = $('textarea'), send = $('.ai-form button');

  function labels() {
    fab.setAttribute('aria-label', T('Mở trợ lý Clicker'));
    $('.t-name').textContent = T('Trợ lý Clicker');
    const st = $('.t-state');
    st.textContent = enabled === false ? T('Chưa bật') : T('Trực tuyến');
    st.classList.toggle('off', enabled === false);
    $('.x').setAttribute('aria-label', T('Đóng'));
    ta.placeholder = T('Nhập câu hỏi…');
    send.setAttribute('aria-label', T('Gửi'));
    $('.ai-note').textContent = T('Trợ lý AI có thể nhầm. Hãy kiểm tra thông tin quan trọng.');
    panel.setAttribute('aria-label', T('Trợ lý Clicker'));
  }

  function bubble(role, html, cls = '') {
    const d = document.createElement('div');
    d.className = `ai-msg ${role === 'user' ? 'me' : ''} ${cls}`;
    d.innerHTML = (role === 'user' ? '' : '<span class="ai-av">C</span>') + `<div class="bb">${html}</div>`;
    body.append(d);
    body.scrollTop = body.scrollHeight;
    return d;
  }
  function render() {
    body.innerHTML = '';
    bubble('assistant', md(T('Xin chào! Mình là **trợ lý Clicker**. Mình có thể giải thích cách dùng, thiết bị, bảng giá, hoặc giúp bạn bắt đầu buổi kiểm tra đầu tiên.')));
    if (!msgs.length) {
      const c = document.createElement('div');
      c.className = 'ai-chips';
      c.innerHTML = CHIPS.map(q => `<button type="button">${esc(T(q))}</button>`).join('');
      c.addEventListener('click', e => { const b = e.target.closest('button'); if (b) ask(b.textContent); });
      body.append(c);
    }
    for (const m of msgs) bubble(m.role, m.role === 'user' ? esc(m.text).replace(/\n/g, '<br>') : md(m.text));
  }

  async function ask(text) {
    text = text.trim();
    if (!text || busy) return;
    busy = true; send.disabled = true;
    body.querySelector('.ai-chips')?.remove();
    msgs.push({ role: 'user', text }); save();
    bubble('user', esc(text).replace(/\n/g, '<br>'));
    ta.value = ''; grow();
    const typing = bubble('assistant', '<i></i><i></i><i></i>', 'ai-typing');
    try {
      const r = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ lang: LANG(), messages: msgs.slice(-12) }) });
      const d = await r.json().catch(() => ({}));
      typing.remove();
      if (!d.ok) throw new Error(ERR[d.code] || 'Chưa kết nối được trợ lý, thử lại sau nhé.');
      msgs.push({ role: 'assistant', text: d.reply }); save();
      bubble('assistant', md(d.reply));
    } catch (e) {
      typing.remove();
      msgs.pop(); save();   // let the question be asked again
      bubble('assistant', esc(T(e.message)), 'err');
    } finally { busy = false; send.disabled = !ta.value.trim(); ta.focus(); }
  }

  function grow() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 120) + 'px'; send.disabled = busy || !ta.value.trim(); }
  function toggle(open) {
    panel.classList.toggle('open', open); fab.classList.toggle('open', open);
    fab.querySelector('span').innerHTML = open ? '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>' : 'C';
    document.querySelector('.ai-hello')?.remove();
    try { sessionStorage.setItem('clicker-chat-seen', '1'); } catch {}
    if (open) { setTimeout(() => ta.focus(), 250); body.scrollTop = body.scrollHeight; }
  }
  fab.addEventListener('click', () => toggle(!panel.classList.contains('open')));
  $('.x').addEventListener('click', () => toggle(false));
  addEventListener('keydown', e => { if (e.key === 'Escape' && panel.classList.contains('open')) toggle(false); });
  ta.addEventListener('input', grow);
  ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(ta.value); } });
  $('.ai-form').addEventListener('submit', e => { e.preventDefault(); ask(ta.value); });

  /* One greeting per browser tab, a few seconds after the page opens. */
  let seen = false;
  try { seen = !!sessionStorage.getItem('clicker-chat-seen'); } catch {}
  if (!seen) setTimeout(() => {
    if (panel.classList.contains('open')) return;
    const h = document.createElement('div');
    h.className = 'ai-hello'; h.setAttribute('data-noi18n', '');
    const fill = () => { h.innerHTML = `${md(T('Xin chào 👋 Mình là **trợ lý Clicker**. Cần giúp gì không?'))}<button type="button" aria-label="${esc(T('Đóng'))}">×</button>`; };
    fill(); document.addEventListener('langchange', fill);
    h.addEventListener('click', e => { if (e.target.closest('button')) { h.remove(); try { sessionStorage.setItem('clicker-chat-seen', '1'); } catch {} } else toggle(true); });
    document.body.append(h);
  }, 3500);

  fetch(API + '?status=1', { credentials: 'same-origin' }).then(r => r.json()).then(d => { enabled = !!d.enabled; labels(); }).catch(() => {});
  labels(); render(); grow();
  if (location.hash === '#chat') toggle(true);   // shareable link that opens the assistant
  /* Any element with data-ask opens the assistant and asks that question (e.g. "Tìm hiểu thêm" on the use cases). */
  document.addEventListener('click', e => {
    const a = e.target.closest('[data-ask]'); if (!a) return;
    e.preventDefault(); toggle(true); ask(T(a.dataset.ask));
  });
  window.ClickerAssistant = { open: () => toggle(true), ask: q => { toggle(true); ask(q); } };
  document.addEventListener('langchange', () => { labels(); render(); });
})();
