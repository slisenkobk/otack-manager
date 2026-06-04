// Hashes a tag name to one of 10 design-system tag colors (matches DESIGN.md §1).
const TAG_HUES = [
  { color: 'var(--brand)',   bg: 'var(--brand-3)' },
  { color: 'var(--green)',   bg: 'var(--green-tint)' },
  { color: 'var(--red)',     bg: 'var(--red-tint)' },
  { color: 'var(--blue)',    bg: 'var(--blue-tint)' },
  { color: 'var(--yellow)',  bg: 'var(--yellow-tint)' },
  { color: 'var(--teal)',    bg: 'var(--teal-tint)' },
  { color: 'var(--purple)',  bg: 'var(--purple-tint)' },
  { color: 'var(--magenta)', bg: 'var(--magenta-tint)' },
  { color: 'var(--olive)',   bg: 'var(--olive-tint)' },
  { color: 'var(--indigo)',  bg: 'var(--indigo-tint)' },
];

export function tagColor(name) {
  let h = 0;
  for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0;
  return TAG_HUES[Math.abs(h) % TAG_HUES.length];
}

/**
 * Use in async catch blocks that don't have a user-facing recovery.
 * Logs to console with a `tag` so future regressions are findable,
 * and lets the rejection complete without bubbling to window.onerror.
 *
 * Example:
 *   try { await api(...) } catch (e) { logSilent(e, 'kanban.lazyLoad'); }
 */
export function logSilent(err, tag) {
  // eslint-disable-next-line no-console
  console.warn('[silent]', tag, err);
}

/**
 * Client-side i18n lookup. The js.* slice of the active server-side catalog
 * ships as a non-executed JSON island (`<script type="application/json"
 * id="i18n-js">…</script>`) so the app's strict `script-src 'self'` CSP
 * accepts it. We parse it once on first call and cache on window.__t for
 * later reads. Falls back to the explicit fallback, then the key itself —
 * same contract as the server's t() helper.
 *
 * Example:
 *   UI.toast(t('js.toast.saved'), 'success');
 */
function loadI18nIsland() {
  if (window.__t) return;
  const el = document.getElementById('i18n-js');
  if (!el) { window.__t = {}; return; }
  try { window.__t = JSON.parse(el.textContent); }
  catch (e) { logSilent(e, 'i18n.parseIsland'); window.__t = {}; }
}

export function t(key, fallback) {
  if (!window.__t) loadI18nIsland();
  return (window.__t && window.__t[key]) ?? (fallback ?? key);
}

/**
 * Wrap an async operation so the button shows busy state (`aria-busy`,
 * disabled) for the duration. Replaces ad-hoc
 *   btn.disabled = true;
 *   try { … } finally { btn.disabled = false; }
 * blocks scattered across 45+ call sites — a class of bug where the
 * `finally` branch is omitted or the button stays disabled on rejection.
 * Also sets aria-busy so screen-readers + CSS get a single source of truth.
 *
 * Example:
 *   await withButtonBusy(saveBtn, async () => {
 *     await api('/foo', { method: 'POST' });
 *   });
 */
export async function withButtonBusy(btn, fn) {
  if (!btn) return await fn();
  btn.disabled = true;
  btn.setAttribute('aria-busy', 'true');
  try {
    return await fn();
  } finally {
    btn.disabled = false;
    btn.removeAttribute('aria-busy');
  }
}

// AS-4: lazy-load Sortable from /assets/vendor/sortable.min.js. The pages
// that need it (projects/show.php, forms/builder.php) used to ship the
// 44 KB library as a synchronous <script> tag in <head> which blocked
// first paint. Now those views no longer load it directly — callers
// `await loadSortable()` right before they touch `Sortable.create(...)`.
// Repeated calls share a single in-flight Promise; once resolved we
// re-resolve from `window.Sortable` for free.
let sortablePromise = null;
export function loadSortable() {
  if (window.Sortable) return Promise.resolve(window.Sortable);
  if (sortablePromise) return sortablePromise;
  sortablePromise = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = '/assets/vendor/sortable.min.js';
    s.onload  = () => resolve(window.Sortable);
    s.onerror = () => { sortablePromise = null; reject(new Error('Failed to load Sortable')); };
    document.head.appendChild(s);
  });
  return sortablePromise;
}
