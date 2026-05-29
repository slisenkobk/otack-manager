// Theme toggle. Cookie 'theme' ∈ {light, dark, auto}. Applies data-theme on
// <html> immediately so the page recolors without a reload. The cookie is
// read server-side on the next request to render the correct initial state.

const COOKIE = 'theme';
const MAX_AGE = 60 * 60 * 24 * 365;

function applyTheme(value) {
  const html = document.documentElement;
  if (value === 'auto') html.removeAttribute('data-theme');
  else html.setAttribute('data-theme', value);
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
