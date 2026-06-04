// Dynamic style bridge — Wave 9.1e Task 3 / S-6 prep.
//
// CSP `style-src` governs HTML-source-emitted `<style>` blocks and
// `style=""` attributes, but NOT JS-driven `el.style.setProperty(...)`.
// Templates that need to inject a per-row colour or width therefore set
// a `data-*` attribute, and this module copies the value onto either a
// CSS custom property (so a class rule can `var(--bg)` it) or directly
// onto the element's style (for width/height/color).
//
// Recognised attributes:
//   data-bg          → sets --bg                (background colour)
//   data-tag-color   → sets --tag and --tag-bg  (tag chip: opaque + 22-alpha)
//   data-width-pct   → sets width: N%
//   data-height-pct  → sets height: N%
//   data-color       → sets color: …
//
// A MutationObserver applies the same logic to nodes injected later
// (kanban-card hydration, comment posts, etc.) so newly-rendered HTML
// stays in sync without each script having to opt in.

function apply(el) {
  const { bg, tagColor, widthPct, heightPct, color } = el.dataset;
  if (bg)        el.style.setProperty('--bg', bg);
  if (tagColor)  {
    el.style.setProperty('--tag', tagColor);
    el.style.setProperty('--tag-bg', tagColor + '22');
  }
  if (widthPct)  el.style.setProperty('width', widthPct + '%');
  if (heightPct) el.style.setProperty('height', heightPct + '%');
  if (color)     el.style.setProperty('color', color);
}

const SELECTOR = '[data-bg], [data-tag-color], [data-width-pct], [data-height-pct], [data-color]';

document.querySelectorAll(SELECTOR).forEach(apply);

new MutationObserver(muts => {
  muts.forEach(m => m.addedNodes.forEach(n => {
    if (!(n instanceof HTMLElement)) return;
    if (n.matches?.(SELECTOR)) apply(n);
    n.querySelectorAll?.(SELECTOR).forEach(apply);
  }));
}).observe(document.body, { childList: true, subtree: true });
