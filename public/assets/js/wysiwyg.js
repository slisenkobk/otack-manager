/**
 * wysiwyg.js — Initialises Quill on any element with [data-quill]
 *
 * Quill is lazy-loaded on first sight of a [data-quill] node (AS-3): the layout
 * no longer ships quill.min.js / quill.snow.css on every authenticated page.
 *
 * Quill sanitises its own output, so reading editor.root.innerHTML is safe.
 * The server-side HtmlSanitizer provides an additional allow-list check on save.
 */

let _quillLoadPromise = null;

function ensureQuillLoaded() {
  if (window.Quill) return Promise.resolve();
  if (_quillLoadPromise) return _quillLoadPromise;
  _quillLoadPromise = new Promise((resolve, reject) => {
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = '/assets/vendor/quill/quill.snow.css';
    document.head.appendChild(css);

    const s = document.createElement('script');
    s.src = '/assets/vendor/quill/quill.min.js';
    s.onload = () => resolve();
    s.onerror = (e) => {
      _quillLoadPromise = null; // allow retry on next [data-quill] match
      reject(e);
    };
    document.head.appendChild(s);
  });
  return _quillLoadPromise;
}

async function initQuill(el) {
  // Guard against double-init: the layout loads this module via a cache-busted
  // URL and individual pages may import it again (or the MutationObserver in
  // ui.js re-runs init when modal nodes are inserted). Without the guard,
  // Quill builds a second toolbar above the editor.
  if (el.dataset.quillReady === '1') return;
  el.dataset.quillReady = '1';

  try {
    await ensureQuillLoaded();
  } catch (_) {
    el.dataset.quillReady = ''; // allow retry
    return;
  }
  if (typeof window.Quill === 'undefined') return;

  const targetSelector = el.dataset.quillTarget;
  const hidden = targetSelector ? document.querySelector(targetSelector) : null;
  const initial = hidden ? hidden.value : '';

  const editor = new window.Quill(el, {
    theme: 'snow',
    placeholder: el.dataset.placeholder || 'Description…',
    modules: {
      toolbar: [
        ['bold', 'italic', 'underline'],
        ['link', 'code', 'code-block'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['clean'],
      ],
    },
  });

  // Seed existing content
  if (initial) {
    // eslint-disable-next-line no-unsanitized/property
    editor.root.innerHTML = initial;  // Safe: Quill parses and re-renders
  }

  // Sync to hidden input on change
  editor.on('text-change', () => {
    if (hidden) hidden.value = editor.root.innerHTML;
  });

  return editor;
}

function tryInit() {
  document.querySelectorAll('[data-quill]').forEach(initQuill);
}

if (!window.__otackQuillInit) {
  window.__otackQuillInit = true;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryInit);
  } else {
    tryInit();
  }
  // Catch [data-quill] elements that get inserted dynamically (modals,
  // kanban quick-edit, etc.) — mirrors the custom-select observer in ui.js.
  new MutationObserver((muts) => {
    muts.forEach((m) => m.addedNodes.forEach((n) => {
      if (!(n instanceof HTMLElement)) return;
      if (n.matches?.('[data-quill]')) initQuill(n);
      n.querySelectorAll?.('[data-quill]').forEach(initQuill);
    }));
  }).observe(document.body, { childList: true, subtree: true });
}
