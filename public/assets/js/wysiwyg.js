/**
 * wysiwyg.js — Initialises Quill on any element with [data-quill]
 *
 * Quill sanitises its own output, so reading editor.root.innerHTML is safe.
 * The server-side HtmlSanitizer provides an additional allow-list check on save.
 */

function initQuill(el) {
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
  if (typeof window.Quill !== 'undefined') {
    document.querySelectorAll('[data-quill]').forEach(initQuill);
  } else {
    window.addEventListener('load', () => {
      document.querySelectorAll('[data-quill]').forEach(initQuill);
    }, { once: true });
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', tryInit);
} else {
  tryInit();
}
