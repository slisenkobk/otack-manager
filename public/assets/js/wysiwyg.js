/**
 * wysiwyg.js — Initialises Quill on any element with [data-quill]
 *
 * Quill sanitises its own output, so reading editor.root.innerHTML is safe.
 * The server-side HtmlSanitizer provides an additional allow-list check on save.
 */

function initQuill(el) {
  if (typeof window.Quill === 'undefined') return;
  // Guard against double-init: the layout loads this module via a cache-busted
  // URL and individual pages may import it again (or the MutationObserver in
  // ui.js re-runs init when modal nodes are inserted). Without the guard,
  // Quill builds a second toolbar above the editor.
  if (el.dataset.quillReady === '1') return;
  el.dataset.quillReady = '1';

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
  if (typeof window.Quill !== 'undefined') {
    document.querySelectorAll('[data-quill]').forEach(initQuill);
  } else {
    window.addEventListener('load', () => {
      document.querySelectorAll('[data-quill]').forEach(initQuill);
    }, { once: true });
  }
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
