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
 * Client-side i18n lookup. `window.__t` is populated server-side from the
 * current locale's catalog (js.* namespace, emitted by views/layouts/*.php).
 * Falls back to the explicit fallback arg, then to the key itself — same
 * contract as the server's t() helper, so missing keys surface in dev.
 *
 * Example:
 *   UI.toast(t('js.toast.saved'), 'success');
 */
export function t(key, fallback) {
  return (window.__t && window.__t[key]) ?? (fallback ?? key);
}
