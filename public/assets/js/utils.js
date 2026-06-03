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
