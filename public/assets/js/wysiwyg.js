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

/**
 * Quill 2's table module groups cells into rows by a `data-row` attribute on
 * each cell, not by the <tr> they sit in. The server sanitiser strips
 * `data-row` (it is not on the allow-list), so a table that was stored through
 * the API or saved earlier arrives here with plain <tr><td>…</td></tr>, and
 * Quill puts every cell of every row into ONE row on load — saving then
 * persists the collapsed table. Re-derive the row id from each cell's <tr>
 * before handing the HTML to Quill. `th` is turned into `td` because Quill has
 * no header-cell blot and would otherwise drop the header row's cells.
 * DOMParser output is inert (no script execution, no resource loads).
 */
function normalizeTablesForQuill(html) {
  if (!/<table[\s>]/i.test(html)) return html;
  const doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
  doc.querySelectorAll('table').forEach(table => {
    table.querySelectorAll('tr').forEach((tr, i) => {
      Array.from(tr.children).forEach(cell => {
        if (cell.tagName !== 'TD' && cell.tagName !== 'TH') return;
        let td = cell;
        if (cell.tagName === 'TH') {
          td = doc.createElement('td');
          while (cell.firstChild) td.appendChild(cell.firstChild);
          tr.replaceChild(td, cell);
          // Keep header emphasis visible after the th -> td swap.
          if (td.querySelector('strong, b') === null) {
            const strong = doc.createElement('strong');
            while (td.firstChild) strong.appendChild(td.firstChild);
            td.appendChild(strong);
          }
        }
        td.setAttribute('data-row', 'row-' + (i + 1));
      });
    });
  });
  return doc.body.innerHTML;
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
    editor.root.innerHTML = normalizeTablesForQuill(initial);  // Safe: server-sanitised HTML, re-parsed inertly; Quill parses and re-renders
  }

  // Tables with header cells: Quill's own paste conversion folds <th> cells of
  // the first row into a single cell. Route those pastes through the same
  // normaliser used for loading so every header cell survives as its own cell.
  editor.root.addEventListener('paste', (e) => {
    const html = e.clipboardData?.getData('text/html') || '';
    if (!/<table[\s>]/i.test(html) || !/<th[\s>]/i.test(html)) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const range = editor.getSelection(true);
    editor.clipboard.dangerouslyPasteHTML(range.index, normalizeTablesForQuill(html), 'user');
  }, true);

  // Sync to hidden input on change
  editor.on('text-change', () => {
    if (hidden) hidden.value = editor.root.innerHTML;
  });

  return editor;
}

function tryInit() {
  document.querySelectorAll('[data-quill]').forEach(initQuill);
}

// wysiwyg.js is loaded ONCE per page from layouts/main.php with a
// query-less URL; no other modules `import './wysiwyg.js'`, so ES-module
// dedup keeps this top-level block running exactly once.
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
