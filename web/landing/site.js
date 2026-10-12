/* Shared by every marketing page: scroll reveal, header, progress bar, count-up. */
'use strict';
const $ = s => document.querySelector(s), $$ = s => [...document.querySelectorAll(s)];
const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;

/* suffixes such as " giây" are translated; the value is redrawn when the language changes */
const tr = s => (typeof I18N !== 'undefined' && s.trim()) ? s.replace(s.trim(), I18N.t(s.trim())) : s;
function count(el) {
  if (el.dataset.done) return; el.dataset.done = 1;
  el.setAttribute('data-noi18n', '');
  const to = +el.dataset.to, pre = el.dataset.pre || '', suf = tr(el.dataset.suf || ''), t0 = performance.now(), ms = 1300;
  const fmt = v => el.dataset.vn ? v.toLocaleString('vi-VN') : v;
  const step = t => { const k = Math.min(1, (t - t0) / ms);
    el.textContent = pre + fmt(Math.round(to * (1 - Math.pow(1 - k, 3)))) + suf;
    if (k < 1) requestAnimationFrame(step); };
  requestAnimationFrame(step);
}

/* data-rv reveals once in view; data-d is the delay in ms. Pages hook in with a 'reveal' event. */
const io = new IntersectionObserver(es => es.forEach(e => {
  if (!e.isIntersecting) return;
  const el = e.target;
  el.style.transitionDelay = (el.dataset.d || 0) + 'ms';
  el.classList.add('in');
  io.unobserve(el);
  el.querySelectorAll('[data-to]').forEach(count);
  if (el.dataset.to !== undefined) count(el);
  el.dispatchEvent(new CustomEvent('reveal'));
}), { threshold: .15, rootMargin: '0px 0px -6% 0px' });
$$('[data-rv],.hl').forEach(el => io.observe(el));

const hd = $('#hd'), prog = $('#prog'), par = $$('[data-par]');
let ticking = false;
addEventListener('scroll', () => {
  if (ticking) return; ticking = true;
  requestAnimationFrame(() => {
    const y = scrollY, h = document.documentElement.scrollHeight - innerHeight;
    hd?.classList.toggle('stuck', y > 50);
    if (prog) prog.style.transform = `scaleX(${h > 0 ? y / h : 0})`;
    if (!calm) par.forEach(b => b.style.transform = `translateY(${y * b.dataset.par}px)`);
    ticking = false;
  });
}, { passive: true });

$('#bar-x')?.addEventListener('click', () => $('#bar').remove());

/* accordions: .acc > button + .acc-b */
$$('.acc > button').forEach(b => b.addEventListener('click', () => {
  const a = b.parentElement, open = !a.classList.contains('open');
  a.classList.toggle('open', open); b.setAttribute('aria-expanded', open);
}));

/* Shared footer: every page has <footer id="ft"></footer>; links are relative to landing/ */
const FOOT = [
  ['Giáo viên', [['Tổng quan', 'index.html#feat'], ['Soạn đề, nhập Excel', 'index.html#feat'], ['Kết quả trực tiếp', 'index.html#top'],
    ['Báo cáo, xuất Excel', 'index.html#feat'], ['Bảng giá', 'pricing.html']]],
  ['Học sinh', [['Vào lớp học', '../phone.html'], ['Máy bấm trên điện thoại', '../phone.html'], ['Cách nhập MSSV', 'index.html#top']]],
  ['Nhà trường', [['Giải pháp', 'index.html#cases'], ['Thiết bị hỗ trợ', 'pricing.html#cmp'], ['Bảo mật dữ liệu', 'privacy.html#bao-mat'],
    ['Gói Tổ chức', 'pricing.html']]],
  ['Tài nguyên', [['Trợ lý AI', 'index.html#ai'], ['Vì sao chọn Clicker', 'index.html#why'], ['Câu hỏi thường gặp', 'pricing.html#faq']]],
  ['Hỗ trợ', [['Trung tâm trợ giúp', 'pricing.html#faq'], ['Điều khoản sử dụng', 'terms.html'], ['Chính sách quyền riêng tư', 'privacy.html'],
    ['Xoá dữ liệu', 'privacy.html#xoa-du-lieu']]],
];
const ft = $('#ft');
if (ft) {
  ft.className = 'mega';
  ft.innerHTML = `<div class="c"><div class="top">
      <div class="about"><a class="brand" href="index.html"><i>C</i>Clicker</a><p>Hệ thống trắc nghiệm bằng máy bấm cho lớp học.</p></div>
      ${FOOT.map(([h, links]) => `<div><h4>${h}</h4><ul>${links.map(([t, u]) => `<li><a href="${u}">${t}</a></li>`).join('')}</ul></div>`).join('')}
    </div>
    <div class="bottom"><span>© 2026 Clicker. Đồ án môn IoT.</span>
      <div class="links"><a href="terms.html">Điều khoản sử dụng</a><a href="privacy.html">Chính sách quyền riêng tư</a>
        <div class="lang up" data-lang-menu></div></div></div></div>`;
}

document.addEventListener('langchange', () => {
  $$('[data-to][data-done]').forEach(el => el.textContent = (el.dataset.pre || '') + el.dataset.to + tr(el.dataset.suf || ''));
});
if (typeof I18N !== 'undefined') document.addEventListener('DOMContentLoaded', I18N.start);

/* Phone menu: built from the desktop menu so both always list the same links. */
const nav = $('#hd .nav'), deskMenu = $('#hd .menu');
if (nav && deskMenu) {
  const auth = nav.querySelector('.auth');
  const burger = document.createElement('button');
  burger.type = 'button'; burger.className = 'burger'; burger.setAttribute('aria-expanded', 'false');
  burger.setAttribute('aria-label', 'Menu'); burger.innerHTML = '<i></i><i></i><i></i>';
  auth.append(burger);
  const panel = document.createElement('div');
  panel.className = 'mnav'; panel.id = 'mnav';
  let items = '';
  deskMenu.querySelectorAll(':scope > li').forEach(li => {
    const a = li.querySelector(':scope > a');
    if (a) { items += `<a class="mi" href="${a.getAttribute('href')}">${a.innerHTML}</a>`; return; }
    const btn = li.querySelector(':scope > button'), drop = li.querySelector('.drop');
    if (!btn || !drop) return;
    const links = [...drop.querySelectorAll('a')].map(x => `<a href="${x.getAttribute('href')}">${x.textContent}</a>`).join('');
    items += `<div class="mgroup"><button type="button" class="mi">${btn.childNodes[0].textContent.trim()}
      <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path d="M9.3 12.95l.7.7L15.66 8l-1.42-1.41L10 10.83 5.76 6.59 4.34 8z"/></svg></button>
      <div class="msub"><div>${links}</div></div></div>`;
  });
  const login = auth.querySelector('.login'), cta = auth.querySelector('.btn-p');
  panel.innerHTML = `<nav>${items}</nav><div class="mcta">
    ${login ? `<a class="btn btn-o" href="${login.getAttribute('href')}">${login.textContent}</a>` : ''}
    ${cta ? `<a class="btn btn-p" href="${cta.getAttribute('href')}">${cta.textContent} <span class="arr">→</span></a>` : ''}</div>`;
  hd.after(panel);
  const setOpen = open => {
    // the header height changes (announcement bar, shrink on scroll), so pin the panel right under it
    if (open) panel.style.setProperty('--mnav-top', Math.round(hd.getBoundingClientRect().bottom) + 'px');
    document.body.classList.toggle('mnav-open', open);
    burger.setAttribute('aria-expanded', String(open));
  };
  burger.addEventListener('click', () => setOpen(!document.body.classList.contains('mnav-open')));
  panel.addEventListener('click', e => {
    const g = e.target.closest('.mgroup > button');
    if (g) { g.parentElement.classList.toggle('open'); return; }
    if (e.target.closest('a')) setOpen(false);   // follow the link and close
  });
  addEventListener('keydown', e => { if (e.key === 'Escape') setOpen(false); });
  addEventListener('resize', () => { if (innerWidth > 1020) setOpen(false); });
}
