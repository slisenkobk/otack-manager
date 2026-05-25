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

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initUserMenu);
} else {
  initUserMenu();
}

// Auto-submit any form-control marked [data-auto-submit] on change.
document.addEventListener('change', (e) => {
  const el = e.target.closest('[data-auto-submit]');
  if (!el) return;
  const form = el.closest('form');
  if (form) form.submit();
});
