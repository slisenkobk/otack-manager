const ROOT_MODAL = () => document.getElementById('modal-root');
const ROOT_TOAST = () => document.getElementById('toast-root');
const ROOT_LIGHT = () => document.getElementById('lightbox-root');

function el(html) {
  const t = document.createElement('template');
  t.innerHTML = html.trim();
  return t.content.firstElementChild;
}

function escapeText(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

export const UI = {
  modal({ title = '', body = '', actions = [] } = {}) {
    const node = el(`
      <div class="modal-backdrop">
        <div class="modal" role="dialog" aria-modal="true">
          <div class="modal-head"><h2>${escapeText(title)}</h2>
            <button class="modal-close" aria-label="Close">
              <i class="fa-solid fa-xmark"></i>
            </button></div>
          <div class="modal-body"></div>
          <div class="modal-actions"></div>
        </div>
      </div>`);
    const bodyEl = node.querySelector('.modal-body');
    if (body instanceof Node) {
      bodyEl.appendChild(body);
    } else {
      const wrap = document.createElement('div');
      wrap.innerHTML = body;  // intentional — caller controls
      bodyEl.appendChild(wrap);
    }
    const actionsEl = node.querySelector('.modal-actions');
    actions.forEach(a => {
      const b = document.createElement('button');
      b.className = a.variant || 'btn-secondary';
      b.textContent = a.label;
      b.addEventListener('click', () => a.onClick && a.onClick(close));
      actionsEl.appendChild(b);
    });
    const prevFocus = document.activeElement;
    function close() {
      node.remove();
      document.removeEventListener('keydown', onKey);
      if (prevFocus && prevFocus.focus) prevFocus.focus();
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    node.querySelector('.modal-close').addEventListener('click', close);
    node.addEventListener('click', e => { if (e.target === node) close(); });
    document.addEventListener('keydown', onKey);
    ROOT_MODAL().appendChild(node);
    setTimeout(() => node.querySelector('button')?.focus(), 0);
    return { close, node };
  },

  confirm(message, { confirmLabel = 'OK', danger = false } = {}) {
    return new Promise(resolve => {
      this.modal({
        title: 'Confirm',
        body: `<p>${escapeText(message)}</p>`,
        actions: [
          { label: 'Cancel', variant: 'btn-ghost', onClick: (close) => { close(); resolve(false); } },
          { label: confirmLabel, variant: danger ? 'btn-danger' : 'submit',
            onClick: (close) => { close(); resolve(true); } },
        ],
      });
    });
  },

  prompt(message, { default: def = '', placeholder = '' } = {}) {
    return new Promise(resolve => {
      const body = document.createElement('div');
      const p = document.createElement('p');
      p.textContent = message;
      body.appendChild(p);
      const input = document.createElement('input');
      input.className = 'input';
      input.value = def;
      input.placeholder = placeholder;
      body.appendChild(input);
      const m = this.modal({
        title: 'Prompt', body,
        actions: [
          { label: 'Cancel', variant: 'btn-ghost', onClick: (close) => { close(); resolve(null); } },
          { label: 'OK', variant: 'submit', onClick: (close) => { const v = input.value; close(); resolve(v); } },
        ],
      });
      setTimeout(() => input.focus(), 0);
      input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { const v = input.value; m.close(); resolve(v); }
      });
    });
  },

  toast(message, type = 'info') {
    const node = document.createElement('div');
    node.className = 'toast toast--' + type;
    node.textContent = message;
    ROOT_TOAST().appendChild(node);
    const remove = () => node.remove();
    node.addEventListener('click', remove);
    setTimeout(remove, 4000);
  },

  lightbox(images, startIndex = 0) {
    let i = startIndex;
    const node = el(`
      <div class="lightbox-backdrop">
        <button class="lb-close"><i class="fa-solid fa-xmark"></i></button>
        <button class="lb-prev"><i class="fa-solid fa-chevron-left"></i></button>
        <img class="lightbox-img" alt="">
        <button class="lb-next"><i class="fa-solid fa-chevron-right"></i></button>
      </div>`);
    const img = node.querySelector('img');
    function render() { img.src = images[i]; }
    function close() {
      node.remove();
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) {
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowLeft') { i = (i - 1 + images.length) % images.length; render(); }
      if (e.key === 'ArrowRight') { i = (i + 1) % images.length; render(); }
    }
    node.querySelector('.lb-close').addEventListener('click', close);
    node.querySelector('.lb-prev').addEventListener('click', () => { i = (i - 1 + images.length) % images.length; render(); });
    node.querySelector('.lb-next').addEventListener('click', () => { i = (i + 1) % images.length; render(); });
    node.addEventListener('click', e => { if (e.target === node) close(); });
    document.addEventListener('keydown', onKey);
    ROOT_LIGHT().appendChild(node);
    render();
  },
};

export async function api(url, opts = {}) {
  const headers = opts.headers || {};
  headers['X-CSRF-Token'] = document.querySelector('meta[name=csrf-token]')?.content || '';
  if (opts.body && !(opts.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }
  const res = await fetch(url, { ...opts, headers });
  let data = null;
  try { data = await res.json(); } catch {}
  if (!res.ok) {
    UI.toast(data?.error || ('HTTP ' + res.status), 'error');
    throw new Error(data?.error || ('HTTP ' + res.status));
  }
  return data;
}

window.UI = UI;
window.api = api;

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

// Idempotency guard: the layout loads this module via a cache-busted URL
// (?v=…), and other modules (dashboard.js, etc.) import it via a relative
// path. Browsers treat those as distinct module instances and run the
// top-level code twice — without a guard, the user-menu / flash init would
// attach two click listeners, which then pingpong open()/close() on every
// click and the dropdown never actually opens.
if (!window.__otackUiInit) {
  window.__otackUiInit = true;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initUserMenu);
  } else {
    initUserMenu();
  }
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
if (!window.__otackFlashInit) {
  window.__otackFlashInit = true;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFlash);
  } else {
    initFlash();
  }
}

// Auto-submit any form-control marked [data-auto-submit] on change.
if (!window.__otackAutoSubmitInit) {
  window.__otackAutoSubmitInit = true;
  document.addEventListener('change', (e) => {
    const el = e.target.closest('[data-auto-submit]');
    if (!el) return;
    const form = el.closest('form');
    if (form) form.submit();
  });
}

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
