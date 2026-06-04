// Side-effect bootstrap for the workspace shell: user menu, flash relay,
// mobile-nav drawer, auto-submit listener, custom-select widget. Imported
// for its top-level effects from ui.js (the façade) — no public exports
// other than `buildCustomSelect`, which is also exposed via `window` for
// legacy callers.
//
// Layout pairs this with ui-modal.js by loading ui.js exactly once per
// page (the façade re-exports). The block here runs on DOMContentLoaded
// (or immediately, if the DOM is already parsed) so every initialiser
// gets to query a fully-built tree.

import { UI } from './ui-modal.js';

function initUserMenu() {
  const root = document.querySelector('[data-user-menu]');
  if (!root) return;
  const toggle = root.querySelector('[data-user-menu-toggle]');
  const pop = root.querySelector('.user-menu__pop');
  if (!toggle || !pop) return;

  function open() {
    pop.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    document.addEventListener('click', onDocClick, true);
    document.addEventListener('keydown', onKey);
  }
  function close() {
    pop.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
    document.removeEventListener('click', onDocClick, true);
    document.removeEventListener('keydown', onKey);
  }
  function onDocClick(e) { if (!root.contains(e.target)) close(); }
  function onKey(e) { if (e.key === 'Escape') { close(); toggle.focus(); } }

  toggle.addEventListener('click', e => {
    e.stopPropagation();
    pop.hidden ? open() : close();
  });

  const logoutForm = root.querySelector('.user-menu__item--form');
  if (logoutForm) {
    logoutForm.addEventListener('submit', () => {
      const csrfInput = logoutForm.querySelector('input[name=_csrf]');
      if (csrfInput && !csrfInput.value) {
        csrfInput.value = document.querySelector('meta[name=csrf-token]')?.content || '';
      }
    });
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initUserMenu);
} else {
  initUserMenu();
}

// Page-load flash: if the layout rendered <meta name="flash-message">,
// pipe it through UI.toast on first paint. Keeps every "saved" / "error"
// confirmation visually consistent with the JS-fired toasts.
function initFlash() {
  const msg = document.querySelector('meta[name="flash-message"]')?.content;
  if (!msg) return;
  const type = document.querySelector('meta[name="flash-type"]')?.content || 'info';
  UI.toast(msg, type);
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initFlash);
} else {
  initFlash();
}

// Mobile-nav drawer toggle. The hamburger in the topbar adds
// data-mobile-nav-open on .shell, which the CSS transitions into the
// slide-in sidebar. Clicking the backdrop or any link inside the sidebar
// closes the drawer.
function initMobileNav() {
  const shell  = document.querySelector('[data-shell]');
  const toggle = document.querySelector('[data-mobile-nav-toggle]');
  const back   = document.querySelector('[data-mobile-nav-backdrop]');
  const sidebar = document.querySelector('.sidebar');
  if (!shell || !toggle) return;
  function open()  {
    shell.setAttribute('data-mobile-nav-open', '');
    if (back) back.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
  }
  function close() {
    shell.removeAttribute('data-mobile-nav-open');
    if (back) back.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
  }
  toggle.addEventListener('click', () => {
    shell.hasAttribute('data-mobile-nav-open') ? close() : open();
  });
  back?.addEventListener('click', close);
  document.querySelector('[data-mobile-nav-close]')?.addEventListener('click', close);
  // Closing on any nav-item click keeps navigation feeling instant.
  sidebar?.querySelectorAll('a.nav-item, a.brand').forEach(a => {
    a.addEventListener('click', close);
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initMobileNav);
} else {
  initMobileNav();
}

// Auto-submit any form-control marked [data-auto-submit] on change.
document.addEventListener('change', (e) => {
  const el = e.target.closest('[data-auto-submit]');
  if (!el) return;
  const form = el.closest('form');
  if (form) form.submit();
});

// Custom select — replaces native <select> with styled dropdown.
function initCustomSelect(root) {
  if (root.__csInit) return;
  root.__csInit = true;
  const btn      = root.querySelector('.custom-select__btn');
  const pop      = root.querySelector('.custom-select__pop');
  const hidden   = root.querySelector('input[type=hidden]');
  const label    = root.querySelector('.custom-select__btn .custom-select__label');
  const btnMeta  = root.querySelector('.custom-select__btn .custom-select__meta');
  const btnIco   = root.querySelector('.custom-select__btn .custom-select__icon');
  const search   = root.querySelector('[data-custom-select-search-input]');
  const noRes    = root.querySelector('.custom-select__no-results');
  const updateAttr = root.dataset.updateAttr;
  if (!btn || !pop || !hidden) return;

  const openPop = () => {
    document.querySelectorAll('.custom-select__pop').forEach(p => { if (p !== pop) p.hidden = true; });
    pop.hidden = false;
    if (search) { search.value = ''; runFilter(''); setTimeout(() => search.focus(), 0); }
    // Scroll the selected option into view inside the pop
    const sel = pop.querySelector('.custom-select__opt.is-selected');
    if (sel) sel.scrollIntoView({ block: 'nearest' });
  };

  btn.addEventListener('click', e => {
    e.stopPropagation();
    if (pop.hidden) openPop(); else pop.hidden = true;
  });
  document.addEventListener('click', e => {
    if (!root.contains(e.target)) pop.hidden = true;
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') pop.hidden = true;
  });

  if (search) {
    search.addEventListener('input', () => runFilter(search.value));
    search.addEventListener('keydown', e => { if (e.key === 'Escape') pop.hidden = true; });
  }

  function runFilter(q) {
    const needle = q.trim().toLowerCase();
    let shown = 0;
    pop.querySelectorAll('.custom-select__opt').forEach(opt => {
      const hay = opt.dataset.search || opt.textContent.toLowerCase();
      const match = needle === '' || hay.includes(needle);
      opt.hidden = !match;
      if (match) shown++;
    });
    if (noRes) noRes.hidden = shown > 0;
  }

  pop.querySelectorAll('.custom-select__opt').forEach(opt => {
    opt.addEventListener('click', () => {
      const val = opt.dataset.value;
      pop.hidden = true;
      pop.querySelectorAll('.custom-select__opt').forEach(o => o.classList.toggle('is-selected', o === opt));
      if (hidden.value === val) return;
      hidden.value = val;
      if (label) {
        const lbl = opt.querySelector('.custom-select__opt-label');
        label.textContent = lbl ? lbl.textContent : opt.textContent.trim();
      }
      if (btnMeta) {
        const meta = opt.querySelector('.custom-select__opt-meta');
        btnMeta.textContent = meta ? meta.textContent : '';
      }
      if (btnIco) {
        const srcIco = opt.querySelector('.custom-select__icon');
        if (srcIco) {
          btnIco.className = srcIco.className;
          btnIco.style.cssText = srcIco.style.cssText;
          btnIco.replaceChildren(...Array.from(srcIco.childNodes).map(n => n.cloneNode(true)));
        }
      }
      if (updateAttr) {
        root.setAttribute('data-' + updateAttr, val);
      }
      hidden.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });
}

document.querySelectorAll('[data-custom-select]').forEach(initCustomSelect);
// Re-scan when nodes are added (e.g. modals / kanban quickadd).
new MutationObserver(muts => {
  muts.forEach(m => m.addedNodes.forEach(n => {
    if (!(n instanceof HTMLElement)) return;
    if (n.matches?.('[data-custom-select]')) initCustomSelect(n);
    n.querySelectorAll?.('[data-custom-select]').forEach(initCustomSelect);
  }));
}).observe(document.body, { childList: true, subtree: true });

// Build a custom-select element programmatically.
// items: [{ value, label }], current: initial value
export function buildCustomSelect(items, current) {
  const root = document.createElement('div');
  root.className = 'custom-select';
  root.dataset.customSelect = '';
  const cur = items.find(it => String(it.value) === String(current)) || items[0];
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'custom-select__btn';
  const lbl = document.createElement('span');
  lbl.className = 'custom-select__label';
  lbl.textContent = cur ? cur.label : '';
  btn.appendChild(lbl);
  const chev = document.createElement('i');
  chev.className = 'fa-solid fa-chevron-down custom-select__chevron';
  btn.appendChild(chev);
  root.appendChild(btn);
  const pop = document.createElement('div');
  pop.className = 'custom-select__pop';
  pop.hidden = true;
  items.forEach(it => {
    const opt = document.createElement('div');
    opt.className = 'custom-select__opt' + (String(it.value) === String(current) ? ' is-selected' : '');
    opt.dataset.value = it.value;
    const ol = document.createElement('span');
    ol.className = 'custom-select__opt-label';
    ol.textContent = it.label;
    opt.appendChild(ol);
    pop.appendChild(opt);
  });
  root.appendChild(pop);
  const hidden = document.createElement('input');
  hidden.type = 'hidden';
  hidden.value = cur ? String(cur.value) : '';
  root.appendChild(hidden);
  initCustomSelect(root);
  return { root, hidden };
}
window.buildCustomSelect = buildCustomSelect;
