// Theme toggle. Cookie 'theme' ∈ {light, dark, auto}. Applies data-theme on
// <html> immediately so the page recolors without a reload. The cookie is
// read server-side on the next request to render the correct initial state.
//
// Note (CSS-6): data-theme is now ALWAYS set on <html> (theme-init.js seeds
// it in <head> before CSS, and the toggle below keeps it in sync). The
// historical `@media (prefers-color-scheme: dark)` fallback was removed
// from tokens.css — when the user picks "Auto" we resolve the system pref
// here and stamp the attribute accordingly.

const COOKIE = 'theme';
const MAX_AGE = 60 * 60 * 24 * 365;

function applyTheme(value) {
  const html = document.documentElement;
  if (value === 'auto') {
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    html.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
  } else {
    html.setAttribute('data-theme', value);
  }
}

function setCookie(value) {
  document.cookie = `${COOKIE}=${value}; path=/; max-age=${MAX_AGE}; samesite=lax`;
}

function setActive(group, value) {
  group.querySelectorAll('[data-theme-set]').forEach(b => {
    b.classList.toggle('is-active', b.dataset.themeSet === value);
  });
}

function init() {
  const groups = document.querySelectorAll('[data-theme-toggle]');
  groups.forEach(group => {
    group.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-theme-set]');
      if (!btn) return;
      const value = btn.dataset.themeSet;
      applyTheme(value);
      setCookie(value);
      // Sync every visible toggle (defensive — usually just one)
      document.querySelectorAll('[data-theme-toggle]').forEach(g => setActive(g, value));
    });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
