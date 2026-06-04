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

// WCAG relative luminance for a "#RRGGBB" hex string. Used to pick a
// readable foreground (white vs near-black) when the template injects a
// background from a user-chosen palette. Without this, a bright orange
// project colour leaves white initials at ~3.5:1 — fails AA. Picking the
// fg dynamically lets the palette stay vivid and the avatar stay readable.
function luminance(hex) {
  if (!hex || hex[0] !== '#' || (hex.length !== 7 && hex.length !== 4)) return 1;
  let r, g, b;
  if (hex.length === 4) {
    r = parseInt(hex[1] + hex[1], 16);
    g = parseInt(hex[2] + hex[2], 16);
    b = parseInt(hex[3] + hex[3], 16);
  } else {
    r = parseInt(hex.slice(1, 3), 16);
    g = parseInt(hex.slice(3, 5), 16);
    b = parseInt(hex.slice(5, 7), 16);
  }
  const lin = c => {
    const n = c / 255;
    return n <= 0.03928 ? n / 12.92 : Math.pow((n + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

// Lighter than this → use the dark ink for contrast. Tuned so vivid
// brand colours (#EA580C, #CA8A04, #0891B2) land on dark text, while
// the existing dark slate palette entries (#5A4E3F, #2563EB, …) keep
// the established white-on-dark look.
const LUMINANCE_FLIP = 0.20;

function apply(el) {
  const { bg, tagColor, widthPct, heightPct, color } = el.dataset;
  if (bg) {
    el.style.setProperty('--bg', bg);
    // Only the .ini avatar opts in to luminance-aware fg via
    // var(--bg-fg). Other [data-bg] consumers (kanban col-dot, tag
    // chip) don't render text on the colour.
    if (el.classList.contains('ini')) {
      const fg = luminance(bg) > LUMINANCE_FLIP ? '#1A1612' : '#FFFFFF';
      el.style.setProperty('--bg-fg', fg);
    }
  }
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
