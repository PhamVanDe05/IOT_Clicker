/* Dashboard motion, same feel as landing/site.js: cards rise in, sliding tab underline, scroll progress,
   count-up numbers, badges pop when their state changes. Only looks at the page; app.js does not depend on it. */
'use strict';
(() => {
  const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const nav = document.querySelector('.tabs-nav');

  /* sliding underline under the active tab */
  const ink = document.createElement('span');
  ink.className = 'tab-ink'; ink.setAttribute('aria-hidden', 'true');
  nav.append(ink);
  function moveInk() {
    const a = nav.querySelector('a.active');
    if (!a) return;
    if (getComputedStyle(nav).flexDirection === 'column') {   // left menu: a bar beside the active item
      ink.style.width = ''; ink.style.height = (a.offsetHeight - 16) + 'px';
      ink.style.transform = `translateY(${a.offsetTop + 8}px)`;
    } else {
      ink.style.height = ''; ink.style.width = (a.offsetWidth - 20) + 'px';
      ink.style.transform = `translateX(${a.offsetLeft + 10}px)`;
    }
    // keep the active tab visible when the tab row scrolls sideways (scrollIntoView would also scroll the page)
    if (nav.scrollWidth > nav.clientWidth) {
      const left = a.offsetLeft - 16, right = a.offsetLeft + a.offsetWidth + 16;
      if (left < nav.scrollLeft) nav.scrollTo({ left, behavior: calm ? 'auto' : 'smooth' });
      else if (right > nav.scrollLeft + nav.clientWidth) nav.scrollTo({ left: right - nav.clientWidth, behavior: calm ? 'auto' : 'smooth' });
    }
  }

  /* scroll progress bar */
  const prog = document.createElement('div');
  prog.className = 'scroll-prog'; prog.setAttribute('aria-hidden', 'true');
  document.body.prepend(prog);
  let ticking = false;
  addEventListener('scroll', () => {
    if (ticking) return; ticking = true;
    requestAnimationFrame(() => {
      const h = document.documentElement.scrollHeight - innerHeight;
      prog.style.transform = `scaleX(${h > 0 ? scrollY / h : 0})`;
      ticking = false;
    });
  }, { passive: true });

  /* cards rise in one after another when a tab opens, and when they scroll into view */
  const PARTS = '.hero,.metric,.quick,.section-head,.card,.join-strip';
  let batch = 0, batchTimer = 0;
  const io = new IntersectionObserver(entries => {
    for (const e of entries) {
      if (!e.isIntersecting) continue;
      e.target.style.transitionDelay = Math.min(batch++, 8) * 70 + 'ms';
      e.target.classList.add('in');
      io.unobserve(e.target);
    }
    clearTimeout(batchTimer); batchTimer = setTimeout(() => { batch = 0; }, 120);
  }, { threshold: 0, rootMargin: '0px 0px -4% 0px' });
  function reveal() {
    if (calm) return;
    const view = document.querySelectorAll('#workspace [data-view]:not([hidden])');
    for (const root of view) {
      const parts = root.matches(PARTS) ? [root] : [];
      parts.push(...root.querySelectorAll(PARTS));
      for (const el of parts) {
        if (el.closest('.projecting')) continue;
        el.classList.remove('in'); el.classList.add('fx'); el.style.transitionDelay = '0ms';
        io.observe(el);
      }
    }
    batch = 0;
  }
  // Hidden panes (e.g. the Wi-Fi subtab) are revealed at once when they appear.
  for (const tab of document.querySelectorAll('.subtabs [data-dev]')) tab.addEventListener('click', () => requestAnimationFrame(reveal));

  /* numbers count up when app.js changes them */
  const writing = new WeakSet();
  function countUp(el, from, to) {
    if (calm || from === to) return;
    const t0 = performance.now(), ms = 900;
    writing.add(el);
    const step = t => {
      const k = Math.min(1, (t - t0) / ms);
      el.textContent = String(Math.round(from + (to - from) * (1 - Math.pow(1 - k, 3))));
      if (k < 1 && writing.has(el)) requestAnimationFrame(step); else writing.delete(el);
    };
    requestAnimationFrame(step);
  }
  for (const id of ['total-classes', 'total-exams', 'total-devices', 'tag-count', 'wifi-count']) {
    const el = document.getElementById(id);
    if (!el) continue;
    let last = Number(el.textContent) || 0;
    new MutationObserver(() => {
      if (writing.has(el)) return;               // our own frames
      const now = Number(el.textContent);
      if (!Number.isFinite(now)) return;
      const from = last; last = now;
      countUp(el, from, now);
    }).observe(el, { childList: true, characterData: true, subtree: true });
  }

  /* status badges pop when their text really changes (app.js rewrites them every second) */
  for (const id of ['question-status', 'room-badge', 'participant-count', 'session-status', 'wifi-config-state', 'projector-count']) {
    const el = document.getElementById(id);
    if (!el) continue;
    let last = el.textContent;
    new MutationObserver(() => {
      if (el.textContent === last) return;
      last = el.textContent;
      if (calm) return;
      el.classList.remove('pop'); void el.offsetWidth; el.classList.add('pop');
    }).observe(el, { childList: true, characterData: true, subtree: true });
  }

  function onView() { moveInk(); reveal(); }
  addEventListener('hashchange', () => requestAnimationFrame(onView));
  addEventListener('resize', moveInk);
  document.addEventListener('langchange', () => requestAnimationFrame(moveInk));
  document.fonts?.ready.then(moveInk);
  // The workspace is hidden until the session check finishes; reveal once it shows.
  const ws = document.getElementById('workspace');
  new MutationObserver(() => { if (!ws.hidden) requestAnimationFrame(onView); }).observe(ws, { attributes: true, attributeFilter: ['hidden'] });
  requestAnimationFrame(onView);
})();
