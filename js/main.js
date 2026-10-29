/* Stop other sites from showing this page inside a frame (clickjacking); same-site frames are fine */
(function () {
  if (window.top === window.self) return;
  try { if (window.top.location.origin === window.location.origin) return; } catch (e) { /* cross-site parent */ }
  document.documentElement.style.display = 'none';
  window.top.location = window.location.href;
})();

/* Jovia Network — landing page interactions */
(function () {
  'use strict';

  // ── Mobile menu ──
  const toggle = document.querySelector('.nav-toggle');
  const menu = document.getElementById('mobile-menu');
  if (toggle && menu) {
    const setOpen = (open) => {
      menu.classList.toggle('open', open);
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
      toggle.querySelector('use').setAttribute('href', open ? '#i-x' : '#i-menu');
    };
    toggle.addEventListener('click', () => setOpen(!menu.classList.contains('open')));
    menu.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => setOpen(false)));
  }

  // ── Section links (#earnings, #faq …) ──
  // Sections below the first screen skip layout until needed (content-visibility in styles.css),
  // so lay out the whole page first; otherwise the jump lands in the wrong place.
  const jumpTo = (hash) => {
    const target = hash && hash.length > 1 && document.getElementById(decodeURIComponent(hash.slice(1)));
    if (!target) return false;
    document.documentElement.classList.add('cv-off');
    requestAnimationFrame(() => target.scrollIntoView());
    return true;
  };
  if (location.hash) {
    jumpTo(location.hash);
    window.addEventListener('load', () => jumpTo(location.hash), { once: true });
  }
  window.addEventListener('hashchange', () => jumpTo(location.hash));
  document.addEventListener('click', (e) => {
    const a = e.target.closest('a[href*="#"]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    const url = new URL(a.href, location.href);
    if (url.origin !== location.origin || url.pathname !== location.pathname || !url.hash) return;
    if (jumpTo(url.hash)) {
      e.preventDefault();
      history.pushState(null, '', url.hash);
    }
  });

  // ── FAQ accordion (one open at a time) ──
  const items = document.querySelectorAll('.faq-item');
  items.forEach((item) => {
    item.querySelector('.faq-q').addEventListener('click', () => {
      const willOpen = !item.classList.contains('open');
      items.forEach((other) => {
        other.classList.remove('open');
        other.querySelector('.faq-q').setAttribute('aria-expanded', 'false');
      });
      if (willOpen) {
        item.classList.add('open');
        item.querySelector('.faq-q').setAttribute('aria-expanded', 'true');
      }
    });
  });

  // ── Demo session timer (counts up from 00:24:16 against a 40-minute session) ──
  const timerEl = document.getElementById('demo-timer');
  const progressEl = document.getElementById('demo-progress');
  if (timerEl) {
    const SESSION = 40 * 60;
    let elapsed = 24 * 60 + 16;
    const pad = (n) => String(n).padStart(2, '0');
    const render = () => {
      const h = Math.floor(elapsed / 3600);
      const m = Math.floor((elapsed % 3600) / 60);
      const s = elapsed % 60;
      timerEl.textContent = `${pad(h)}:${pad(m)}:${pad(s)}`;
      if (progressEl) progressEl.style.width = `${(elapsed / SESSION) * 100}%`;
    };
    render();
    setInterval(() => {
      elapsed = elapsed >= SESSION ? 0 : elapsed + 1;
      render();
    }, 1000);
  }

  // ── Reveal on scroll ──
  const reveals = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          io.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -60px 0px', threshold: 0.08 });
    reveals.forEach((el) => io.observe(el));
  } else {
    reveals.forEach((el) => el.classList.add('in'));
  }

  // ── Footer year ──
  const year = document.getElementById('year');
  if (year) year.textContent = new Date().getFullYear();
})();
