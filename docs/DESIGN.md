# Otack Manager — Design System

> One source of truth for colors, typography, components, layout. If something here conflicts with code: **the code is wrong**, fix the code.

**Inspirations:** editorial mockup `dashboard-editorial-mockup.html` (palette, paper texture, corner-tags), `otack-assystant` (kanban patterns, modal layout, form primitives), `education.otack.com.ua` (token structure, focus ring, type scale).

**Constraints:**

- Sans-serif everywhere, **no italic** anywhere.
- Warm paper background, no dot-pattern (keep grain SVG noise).
- No SPA. Server-rendered PHP + vanilla JS modules. No bundler.

---

## 1. Token graph

Three layers — **never** use raw hex outside the palette layer.

### Layer A — Palette (reference tokens)

```css
:root {
  /* PAPER (warm canvas, 4 steps) */
  --paper:     #F5F2EC;  /* main page bg */
  --paper-2:   #EDE7DC;  /* cards, brief boxes */
  --paper-3:   #E2D9C5;  /* deeper surfaces, badges */
  --paper-4:   #D4C8AC;  /* hover for paper-3 */

  /* INK (warm dark text, 4 steps) */
  --ink:       #1A1612;  /* darkest text, headings */
  --ink-2:     #5A4E3F;  /* body text */
  --ink-3:     #8B7C68;  /* muted, captions */
  --ink-4:     #A89A85;  /* placeholders, disabled */

  /* RULE (subtle dividers, 3 steps) */
  --rule:      #D7CFBF;  /* default border */
  --rule-2:    #C5B99F;  /* stronger border */
  --rule-3:    #B0A487;  /* focused / strongest */

  /* BRAND (warm orange, 5 steps) */
  --brand:     #C2410C;  /* primary action */
  --brand-2:   #9A2F06;  /* hover, deeper */
  --brand-3:   #FCE4D6;  /* tint background */
  --brand-4:   #F8D1B9;  /* deeper tint */
  --brand-pop: #EA580C;  /* bright accent — pop CTAs, link hover */

  /* SEMANTIC TONES (each has solid + tint, designed for paper bg) */
  --green:     #4D6840;  --green-tint:   #E5EFDC;
  --red:       #B23A2B;  --red-tint:     #F5DAD5;
  --blue:      #2E5A88;  --blue-tint:    #DCE7F3;
  --yellow:    #B5871E;  --yellow-tint:  #FAEFCD;
  --teal:      #2E7878;  --teal-tint:    #D8ECEC;
  --purple:    #6B4B82;  --purple-tint:  #E8DEEF;
  --magenta:   #993E62;  --magenta-tint: #F2D7DF;
  --brown:     #7C5034;  --brown-tint:   #ECDED1;
  --olive:     #6B7A3A;  --olive-tint:   #E8ECD3;
  --indigo:    #4B3F88;  --indigo-tint:  #DDD9EF;
}
```

**Why 10 semantic hues:** tag chips, status dots, calendar categories, charts. Picked at OKLCH L≈45% chroma≈12% so they sit on paper without screaming. Tints at L≈92% for backgrounds.

### Layer B — Semantic roles (use these in components)

```css
:root {
  /* Surfaces */
  --bg:                var(--paper);
  --bg-elevated:       var(--paper-2);
  --bg-sunken:         var(--paper-3);

  /* Text */
  --text:              var(--ink);
  --text-2:            var(--ink-2);
  --text-muted:        var(--ink-3);
  --text-placeholder:  var(--ink-4);
  --text-on-brand:     var(--paper);
  --text-on-dark:      var(--paper);
  --text-link:         var(--brand);
  --text-link-hover:   var(--brand-pop);

  /* Borders */
  --border:            var(--rule);
  --border-strong:     var(--rule-2);
  --border-focus:      var(--brand-pop);

  /* Brand */
  --accent:            var(--brand);
  --accent-hover:      var(--brand-2);
  --accent-pop:        var(--brand-pop);
  --accent-tint:       var(--brand-3);

  /* Status */
  --success:           var(--green);
  --success-bg:        var(--green-tint);
  --danger:            var(--red);
  --danger-bg:         var(--red-tint);
  --warning:           var(--yellow);
  --warning-bg:        var(--yellow-tint);
  --info:              var(--blue);
  --info-bg:           var(--blue-tint);

  /* Focus ring */
  --focus-ring:        var(--brand-pop);
  --focus-ring-alpha:  rgba(234, 88, 12, 0.25);
}
```

### Layer C — Component-specific (only inside component CSS)

Never define new color tokens at this layer. Use Layer B vars exclusively.

---

## 2. Tokens — radius, shadow, motion, spacing

```css
:root {
  /* Radius */
  --radius-xs:  3px;
  --radius-sm:  4px;
  --radius:     6px;     /* default */
  --radius-md:  8px;
  --radius-lg:  12px;
  --radius-xl:  16px;
  --radius-2xl: 20px;
  --radius-full: 9999px;

  /* Shadows (warm-tinted, never pure black) */
  --shadow-xs:    0 1px 0 rgba(26, 22, 18, 0.06);
  --shadow-sm:    0 1px 2px rgba(26, 22, 18, 0.08), 0 1px 1px rgba(26, 22, 18, 0.04);
  --shadow:       0 1px 0 rgba(255, 255, 255, 0.4) inset, 0 2px 0 rgba(215, 207, 191, 0.4),
                  0 18px 40px -28px rgba(26, 22, 18, 0.14);
  --shadow-md:    0 4px 12px rgba(26, 22, 18, 0.06), 0 1px 3px rgba(26, 22, 18, 0.05);
  --shadow-lg:    0 12px 30px -8px rgba(26, 22, 18, 0.14), 0 2px 6px rgba(26, 22, 18, 0.06);
  --shadow-pop:   0 18px 40px -16px rgba(26, 22, 18, 0.16), 0 2px 0 rgba(26, 22, 18, 0.06);
  --shadow-modal: 0 30px 60px -12px rgba(26, 22, 18, 0.28), 0 8px 16px rgba(26, 22, 18, 0.08);

  /* Motion */
  --duration-instant: 80ms;
  --duration-fast:    120ms;
  --duration:         150ms;
  --duration-slow:    240ms;
  --ease:             cubic-bezier(0.2, 0.7, 0.3, 1);
  --ease-in:          cubic-bezier(0.4, 0, 1, 1);
  --ease-out:         cubic-bezier(0, 0, 0.2, 1);

  /* Spacing — never hardcode px outside this scale */
  --space-1:  2px;
  --space-2:  4px;
  --space-3:  6px;
  --space-4:  8px;
  --space-5:  10px;
  --space-6:  12px;
  --space-8:  16px;
  --space-10: 20px;
  --space-12: 24px;
  --space-16: 32px;
  --space-20: 40px;
  --space-24: 56px;
  --space-32: 80px;

  /* Z-index scale */
  --z-base:     1;
  --z-sticky:   10;
  --z-dropdown: 40;
  --z-modal:    100;
  --z-toast:    150;
  --z-lightbox: 200;
}
```

---

## 3. Typography

**Families** (vendored woff2 in `/public/assets/fonts/`):

- `--font-sans: 'Manrope', system-ui, -apple-system, sans-serif;` — body, headings, UI labels
- `--font-mono: 'JetBrains Mono', ui-monospace, monospace;` — kickers, IDs, timestamps, counters, code

**Weights:** 400 (body), 500 (UI labels, lede), 600 (emphasis, headings of secondary panels), 700 (display headings, primary CTAs).

**No italic.** Where the editorial mockup used italic for emphasis, we use `color: var(--accent)` + `font-weight: 600`.

**Scale:**

```css
:root {
  --fz-display: clamp(40px, 5.5vw, 64px);  /* h1.display on hero */
  --fz-h1:      clamp(24px, 2vw + 1rem, 32px);
  --fz-h2:      20px;
  --fz-h3:      16px;
  --fz-body:    15px;   /* body, paragraphs */
  --fz-ui:      13px;   /* buttons, form labels */
  --fz-small:   12px;
  --fz-xs:      11px;   /* mono kickers, captions */
  --fz-micro:   10px;   /* corner-tags, badges */

  --lh-tight:   1.15;
  --lh:         1.5;
  --lh-prose:   1.6;

  --ls-tight:   -0.02em;
  --ls-mono:    0.1em;
  --ls-mono-strong: 0.15em;
}
```

**Heading recipes:**

```css
.h-display {
  font-family: var(--font-sans);
  font-weight: 700;
  font-size: var(--fz-display);
  line-height: var(--lh-tight);
  letter-spacing: var(--ls-tight);
  color: var(--text);
}
.h-display em { /* see no-italic rule */
  font-style: normal;
  color: var(--accent);
}

.h-kicker {
  font-family: var(--font-mono);
  font-size: var(--fz-xs);
  font-weight: 500;
  letter-spacing: var(--ls-mono-strong);
  text-transform: uppercase;
  color: var(--accent);
}
```

---

## 4. Layout

### 4.1 Shell

```
+----------------------------------------------------+
| Sidebar 240px |  Topbar (sticky)                   |
|               |--------------------------------- ---|
| Brand         |  body-wrap (max 1180px, auto)      |
| Nav workspace |                                    |
| Nav account   |  …content…                         |
|               |                                    |
+----------------------------------------------------+
```

- **Sidebar:** fixed 240px, `--paper-2` bg with right border `--rule`, internal padding 24px 20px.
- **Topbar:** sticky top, `--paper`/95% with `backdrop-filter: blur(6px)`, height ~56px, padding 12px 32px.
- **Body-wrap:** `max-width: 1180px; margin: 0 auto; padding: 32px 32px 80px;` — **not** the 80px hero gap from before; tighter to header.
- **Page background:** `--bg` + grain SVG overlay via `body::before` at opacity 0.3. **No dot pattern.**

### 4.2 Overscroll

```css
html, body { overscroll-behavior-y: none; }
```

No vertical bounce or scroll chaining anywhere.

### 4.3 Section pattern

```html
<section>
  <div class="section-head">
    <span class="section-head__num">02</span>
    <h2 class="section-head__title">Your <span class="accent">projects</span></h2>
    <hr class="section-head__rule">
    <a class="section-head__meta" href="…">All projects <i class="fa-arrow-right"></i></a>
  </div>
  <!-- section body -->
</section>
```

---

## 5. Buttons

**Every button has padding ≥ 8px 14px.** No naked `<button>`s.

```css
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-4);
  padding: var(--space-4) var(--space-8);    /* 8 16 */
  min-height: 36px;
  border-radius: var(--radius-md);
  border: 1px solid transparent;
  background: transparent;
  color: var(--text);
  font: 500 var(--fz-ui)/1.2 var(--font-sans);
  letter-spacing: 0;
  cursor: pointer;
  transition: background var(--duration) var(--ease),
              border-color var(--duration) var(--ease),
              color var(--duration) var(--ease),
              transform var(--duration-fast) var(--ease);
  user-select: none;
}
.btn:active { transform: translateY(1px); }
.btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px var(--focus-ring-alpha);
}
.btn:disabled { opacity: 0.5; pointer-events: none; }

/* Sizes */
.btn--sm { min-height: 30px; padding: var(--space-3) var(--space-6); font-size: var(--fz-small); }
.btn--lg { min-height: 44px; padding: var(--space-6) var(--space-10); font-size: var(--fz-body); }
.btn--icon-only { padding: 0; width: 36px; }

/* Variants */
.btn--primary {  /* the editorial "submit" */
  background: var(--ink);
  color: var(--text-on-dark);
  font-family: var(--font-mono);
  font-size: var(--fz-xs);
  letter-spacing: var(--ls-mono-strong);
  text-transform: uppercase;
  padding: var(--space-5) var(--space-10);  /* slightly bigger */
}
.btn--primary:hover { background: var(--accent); }

.btn--secondary {
  background: var(--paper);
  border-color: var(--rule-2);
  color: var(--text);
}
.btn--secondary:hover { border-color: var(--ink-2); background: var(--paper-2); }

.btn--ghost {
  background: transparent;
  color: var(--text-2);
}
.btn--ghost:hover { background: var(--paper-2); color: var(--text); }

.btn--danger {
  background: var(--danger);
  color: var(--text-on-dark);
}
.btn--danger:hover { background: var(--red-tint); color: var(--danger); border-color: var(--danger); }

.btn--brand {     /* bright pop variant */
  background: var(--accent-pop);
  color: var(--text-on-dark);
}
.btn--brand:hover { background: var(--accent); }
```

**Anti-pattern:** the previous code had `class="submit"` (no `btn` base). All buttons must extend `.btn`.

---

## 6. Forms

### 6.1 Inputs

**All inputs must have a visible border at rest.** This was missing before.

```css
.input,
.textarea,
.select {
  display: block;
  width: 100%;
  padding: var(--space-5) var(--space-6);          /* 10 12 */
  background: var(--paper);
  border: 1px solid var(--rule-2);                  /* visible border */
  border-radius: var(--radius-md);
  color: var(--text);
  font: 400 var(--fz-body)/1.4 var(--font-sans);
  transition: border-color var(--duration) var(--ease),
              box-shadow var(--duration) var(--ease);
}
.input::placeholder, .textarea::placeholder { color: var(--text-placeholder); }
.input:hover, .textarea:hover, .select:hover { border-color: var(--ink-3); }
.input:focus, .textarea:focus, .select:focus {
  outline: none;
  border-color: var(--focus-ring);
  box-shadow: 0 0 0 3px var(--focus-ring-alpha);
}
.input:disabled, .textarea:disabled, .select:disabled {
  background: var(--paper-2);
  color: var(--text-muted);
  cursor: not-allowed;
}
.input--invalid, .textarea--invalid { border-color: var(--danger); }

.textarea { min-height: 96px; resize: vertical; }
```

### 6.2 Rich text — Quill WYSIWYG

For **description** fields on projects and tasks (NOT for comments — comments use markdown), wrap a `<div class="wysiwyg-host">` with `<div class="wysiwyg-editor" data-quill="basic"></div>`. JS in `/public/assets/js/wysiwyg.js` initialises Quill with toolbar: bold, italic, underline, link, code, code-block, lists. Output is HTML, sanitised server-side.

**Quill is vendored** at `/public/assets/vendor/quill/{quill.snow.css, quill.min.js}` — no CDN.

Server-side: store rendered HTML for descriptions. Sanitise via DOMDocument allow-list before persist.

### 6.3 Field

```html
<div class="field">
  <label class="field__label" for="x">Name</label>
  <input class="input" id="x" name="x" />
  <p class="field__hint">Optional helper.</p>
  <p class="field__error">Validation message.</p>
</div>
```

```css
.field { display: flex; flex-direction: column; gap: var(--space-3); }
.field__label {
  font-family: var(--font-mono);
  font-size: var(--fz-micro);
  font-weight: 500;
  letter-spacing: var(--ls-mono);
  text-transform: uppercase;
  color: var(--text-muted);
}
.field__hint { font-size: var(--fz-small); color: var(--text-muted); }
.field__error { font-size: var(--fz-small); color: var(--danger); }
```

### 6.4 Toggle

```html
<label class="toggle">
  <input type="checkbox" />
  <span class="toggle__track"></span>
</label>
```

```css
.toggle { position: relative; display: inline-block; width: 36px; height: 20px; cursor: pointer; }
.toggle input { opacity: 0; position: absolute; inset: 0; }
.toggle__track {
  position: absolute; inset: 0;
  background: var(--rule-2);
  border-radius: var(--radius-full);
  transition: background var(--duration) var(--ease);
}
.toggle__track::before {
  content: ""; position: absolute;
  width: 16px; height: 16px; left: 2px; top: 2px;
  background: var(--paper);
  border-radius: var(--radius-full);
  box-shadow: var(--shadow-sm);
  transition: transform var(--duration) var(--ease);
}
.toggle input:checked + .toggle__track { background: var(--accent); }
.toggle input:checked + .toggle__track::before { transform: translateX(16px); }
.toggle input:focus-visible + .toggle__track { box-shadow: 0 0 0 3px var(--focus-ring-alpha); }
```

---

## 7. Cards

### 7.1 Basic card

```css
.card {
  position: relative;
  background: var(--paper-2);
  border: 1px solid var(--rule);
  border-radius: var(--radius);
  padding: var(--space-10) var(--space-10) var(--space-8);
  transition: border-color var(--duration) var(--ease),
              transform var(--duration-fast) var(--ease),
              box-shadow var(--duration) var(--ease);
}
.card:hover { border-color: var(--ink-2); transform: translateY(-2px); box-shadow: var(--shadow-pop); }
.card--selected { border-color: var(--accent); background: var(--brand-3); }

/* corner-tag (editorial signature) */
.card__tag {
  position: absolute; top: -1px; left: -1px;
  background: var(--ink);
  color: var(--text-on-dark);
  font: 500 var(--fz-micro)/1 var(--font-mono);
  letter-spacing: var(--ls-mono-strong);
  padding: var(--space-2) var(--space-5);
  border-radius: var(--radius-sm) 0 var(--radius-sm) 0;
}
.card__meta {
  position: absolute; top: -1px; right: -1px;
  background: var(--paper);
  color: var(--text-muted);
  border-left: 1px solid var(--rule);
  border-bottom: 1px solid var(--rule);
  font: 400 var(--fz-micro)/1 var(--font-mono);
  letter-spacing: var(--ls-mono);
  padding: var(--space-2) var(--space-5);
}
```

### 7.2 Panel (form/info container)

```css
.panel {
  background: var(--paper-2);
  border: 1px solid var(--rule);
  border-radius: var(--radius);
  padding: var(--space-10);
}
```

---

## 8. Sidebar

```html
<aside class="sidebar">
  <a class="brand" href="/">
    <span class="brand__mark"><!-- 36px SVG icon --></span>
    <span class="brand__word">
      <span class="brand__word-a">Otack</span>
      <span class="brand__word-b">Manager</span>
    </span>
  </a>

  <nav class="nav-group">
    <p class="nav-group__label">Workspace</p>
    <a class="nav-item" href="/">…</a>
  </nav>

  <div class="nav-divider"></div>

  <nav class="nav-group"><!-- Account --></nav>
</aside>
```

- **Brand block:** the `<a>` has `text-decoration: none` (no underline on hover either). Mark icon 36×36, brand color background, white SVG inside.
- **Nav items:** monospace marker numbers (01, 02, …) on the left; label sans; right gutter reserved for badges.
- **Active item:** `color: var(--accent); font-weight: 600;` plus a small horizontal rule at `::before` extending left into the sidebar margin.
- **Hover:** background `var(--paper)`.

```css
.brand { display: flex; align-items: center; gap: var(--space-6);
         padding: var(--space-2) var(--space-3) var(--space-8); border-bottom: 1px solid var(--rule);
         text-decoration: none; color: inherit; }
.brand:hover { text-decoration: none; }      /* explicit — no underline */
.brand:hover .brand__word-b { color: var(--accent); transition: color var(--duration) var(--ease); }
.brand__mark { width: 36px; height: 36px; border-radius: var(--radius-sm);
               background: var(--accent); display: grid; place-items: center;
               box-shadow: var(--shadow-xs); flex-shrink: 0; }
.brand__mark svg { width: 22px; height: 22px; color: var(--paper); }
.brand__word { display: flex; flex-direction: column; font-weight: 700; font-size: 18px;
               letter-spacing: var(--ls-tight); line-height: 1.05; }
.brand__word-a { color: var(--accent); }
.brand__word-b { color: var(--text); }
```

---

## 9. Topbar

```html
<header class="topbar">
  <div class="topbar__lhs">
    <span class="topbar__seal">Otack Manager</span>
    <span class="topbar__crumb">· <?= e($crumb) ?></span>
  </div>
  <div class="topbar__rhs">
    <a class="topbar__avatar" href="/profile" title="Profile" aria-label="Open profile">
      <?= e(mb_substr($user['name'], 0, 1)) ?>
    </a>
  </div>
</header>
```

- **Avatar:** circle 32px, `--ink` background, `--paper` text, **no PRO badge**, click navigates to `/profile`.
- **No center crumb** in the old 3-column layout — left side carries seal + crumb inline.
- **No language pill, no inbox pill** — both removed.

```css
.topbar {
  position: sticky; top: 0; z-index: var(--z-sticky);
  background: rgba(245, 242, 236, 0.92); backdrop-filter: blur(6px);
  border-bottom: 1px solid var(--rule);
  display: flex; justify-content: space-between; align-items: center;
  padding: var(--space-6) var(--space-16);
  font-family: var(--font-mono);
  font-size: var(--fz-xs);
  letter-spacing: var(--ls-mono-strong);
  text-transform: uppercase;
  color: var(--text-2);
}
.topbar__avatar {
  width: 32px; height: 32px; border-radius: var(--radius-full);
  background: var(--ink); color: var(--text-on-dark);
  display: grid; place-items: center;
  font-family: var(--font-sans); font-weight: 500; font-size: 14px;
  text-decoration: none;
  transition: background var(--duration) var(--ease), transform var(--duration-fast) var(--ease);
}
.topbar__avatar:hover { background: var(--accent); transform: scale(1.05); }
```

---

## 10. Kanban (full feature parity with otack-assystant)

### 10.1 Layout

```
+-- Filter bar (sticky) ----------------------------------+
| chips: [All] [Tag1] [Tag2] …      |  [search input]    |
+--------------------------------------------------------+
+-- Board (horizontal scroll) ---------------------------+
| .col | .col | .col | .col | [+ Column] |
| head | head | head | head |            |
| list | list | list | list |            |
| qa   | qa   | qa   | qa   |            |
+--------------------------------------------------------+
```

### 10.2 Filter bar (new — was missing)

```html
<div class="kanban-toolbar">
  <div class="kanban-tagbar">
    <button class="chip chip--active" data-tag="">All</button>
    <button class="chip" data-tag="design">design</button>
    <button class="chip" data-tag="backend">backend</button>
  </div>
  <div class="kanban-search">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input class="input input--inline" placeholder="Search tasks…" data-task-search>
  </div>
</div>
```

```css
.kanban-toolbar { position: sticky; top: 0; z-index: var(--z-sticky);
  display: flex; justify-content: space-between; align-items: center; gap: var(--space-8);
  padding: var(--space-6) 0; background: var(--bg); border-bottom: 1px solid var(--rule); }
.kanban-tagbar { display: flex; gap: var(--space-3); flex: 1; overflow-x: auto; padding-bottom: var(--space-2); }
.kanban-search { display: flex; align-items: center; gap: var(--space-3); padding: 0 var(--space-5);
  background: var(--paper); border: 1px solid var(--rule-2); border-radius: var(--radius); }
.kanban-search input { border: 0; padding: var(--space-3) 0; min-width: 220px; }
```

### 10.3 Column

```html
<div class="kanban-col" data-column-id="3">
  <div class="kanban-col__head">
    <span class="kanban-col__dot" style="--col: #C2410C"></span>
    <span class="kanban-col__name">In progress</span>
    <span class="kanban-col__count">7</span>
    <button class="btn-icon kanban-col__settings" type="button" aria-label="Column settings">
      <i class="fa-solid fa-ellipsis"></i>
    </button>
  </div>
  <div class="kanban-col__list" data-column-id="3"><!-- cards --></div>
  <div class="kanban-col__footer">
    <button class="kanban-col__add" data-quickadd-trigger>
      <i class="fa-solid fa-plus"></i> Add task
    </button>
    <form class="kanban-col__form" hidden>
      <input class="input input--sm" name="title" placeholder="Task title" maxlength="200">
      <span class="kanban-col__hint">Enter to save, Esc to cancel</span>
    </form>
  </div>
</div>
```

Quick-add **collapses by default** as a button; clicking expands form. Esc collapses. (Was always-visible textfield before — change.)

### 10.4 Card (richer than current)

```html
<div class="kanban-card" data-task-id="42" data-task-url="/tasks/42"
     data-position="1024" data-tags="design,urgent">
  <div class="kanban-card__row">
    <span class="kanban-card__id">#42</span>
    <span class="kanban-card__tag" style="--tag: var(--olive); --tag-bg: var(--olive-tint)">design</span>
  </div>
  <div class="kanban-card__title">Wire up the new card layout</div>
  <div class="kanban-card__assignee">
    <span class="avatar avatar--xs" title="Bohdan">Бо</span>
    <span class="kanban-card__assignee-name">Bohdan</span>
  </div>
  <div class="kanban-card__meta">
    <span class="kanban-card__due"><i class="fa-regular fa-calendar"></i> 24.05</span>
    <span class="kanban-card__counts">
      <i class="fa-regular fa-comment"></i> 3
      <i class="fa-solid fa-paperclip"></i> 1
    </span>
  </div>
</div>
```

```css
.kanban-card { display: flex; flex-direction: column; gap: var(--space-3);
  background: var(--paper); border: 1px solid var(--rule); border-radius: var(--radius);
  padding: var(--space-5) var(--space-6); cursor: pointer;
  transition: border-color var(--duration) var(--ease), box-shadow var(--duration) var(--ease),
              transform var(--duration-fast) var(--ease); }
.kanban-card:hover { border-color: var(--ink-3); box-shadow: var(--shadow-sm); transform: translateY(-1px); }
.kanban-card__row { display: flex; align-items: center; gap: var(--space-3); justify-content: space-between; }
.kanban-card__id { font: 500 var(--fz-micro)/1 var(--font-mono); color: var(--text-muted);
  letter-spacing: var(--ls-mono); }
.kanban-card__tag { font: 500 var(--fz-micro)/1 var(--font-mono); letter-spacing: var(--ls-mono);
  text-transform: uppercase; padding: 2px var(--space-3); border-radius: var(--radius-xs);
  background: var(--tag-bg, var(--paper-2)); color: var(--tag, var(--text-2)); }
.kanban-card__title { font-size: var(--fz-body); font-weight: 600; line-height: 1.3; color: var(--text);
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.kanban-card__assignee { display: flex; align-items: center; gap: var(--space-3);
  font-size: var(--fz-small); color: var(--text-2); }
.kanban-card__meta { display: flex; justify-content: space-between; font-size: var(--fz-xs);
  color: var(--text-muted); font-family: var(--font-mono); }
.kanban-card__counts { display: flex; gap: var(--space-5); }
.kanban-card__counts i { margin-right: 3px; }

/* Drag states */
.kanban-ghost {
  opacity: 0.35; background: var(--brand-3) !important;
  border-style: dashed; border-color: var(--accent) !important;
}
.kanban-dragging { opacity: 0.5; }

/* Highlight (pulse on focus, e.g. after returning from task page) */
.kanban-card.is-highlight { animation: card-pulse 1.6s ease-out; }
@keyframes card-pulse {
  0%   { box-shadow: 0 0 0 0 var(--brand-3); }
  50%  { box-shadow: 0 0 0 8px var(--brand-3); }
  100% { box-shadow: 0 0 0 0 transparent; }
}
```

### 10.5 Avatar

```css
.avatar {
  display: inline-grid; place-items: center;
  background: var(--ink); color: var(--text-on-dark);
  border-radius: var(--radius-full);
  font: 500 13px/1 var(--font-sans);
  width: 28px; height: 28px;
  flex-shrink: 0;
}
.avatar--xs { width: 20px; height: 20px; font-size: 10px; }
.avatar--sm { width: 24px; height: 24px; font-size: 11px; }
.avatar--lg { width: 36px; height: 36px; font-size: 14px; }
.avatar--brand { background: var(--accent); }
```

---

## 11. Modals + Toasts + Lightbox

### 11.1 Modal

```css
.modal-backdrop {
  position: fixed; inset: 0; z-index: var(--z-modal);
  background: rgba(26, 22, 18, 0.45); backdrop-filter: blur(4px);
  display: grid; place-items: center; padding: var(--space-12);
  animation: fade-in var(--duration) var(--ease);
}
.modal {
  background: var(--paper-2);
  border: 1px solid var(--rule-2); border-radius: var(--radius-lg);
  box-shadow: var(--shadow-modal);
  max-width: 600px; width: 100%;
  display: flex; flex-direction: column;
  animation: pop-in var(--duration-slow) var(--ease);
}
.modal--lg { max-width: 920px; max-height: 90vh; }
.modal__head { display: flex; justify-content: space-between; align-items: center;
  padding: var(--space-8) var(--space-10); border-bottom: 1px solid var(--rule); }
.modal__body { padding: var(--space-10); overflow-y: auto; }
.modal__actions { display: flex; justify-content: flex-end; gap: var(--space-4);
  padding: var(--space-8) var(--space-10); border-top: 1px solid var(--rule); }

@keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
@keyframes pop-in { from { opacity: 0; transform: translateY(8px) scale(0.98); }
                    to   { opacity: 1; transform: translateY(0) scale(1); } }
```

### 11.2 Toast

Variants: `toast--info`, `toast--success`, `toast--warning`, `toast--error`. Uses semantic colors.

```css
.toast {
  background: var(--paper-2); border: 1px solid var(--border);
  border-left: 3px solid var(--accent);   /* color per variant */
  padding: var(--space-5) var(--space-8); border-radius: var(--radius);
  box-shadow: var(--shadow-lg);
  font-size: var(--fz-small); color: var(--text);
  display: flex; align-items: center; gap: var(--space-5);
  animation: slide-in var(--duration-slow) var(--ease);
}
.toast--success { border-left-color: var(--success); }
.toast--warning { border-left-color: var(--warning); }
.toast--error   { border-left-color: var(--danger); }
.toast--info    { border-left-color: var(--info); }
```

### 11.3 Lightbox

Centered image with prev/next arrows, Esc close, arrow keys navigate. Dark backdrop.

---

## 12. Tags & chips

```css
.chip {
  display: inline-flex; align-items: center; gap: var(--space-3);
  padding: var(--space-2) var(--space-5);
  background: var(--paper-2); color: var(--text-2);
  border: 1px solid var(--rule);
  border-radius: var(--radius-full);
  font: 500 var(--fz-small)/1.2 var(--font-sans);
  cursor: pointer;
  transition: background var(--duration) var(--ease), color var(--duration) var(--ease),
              border-color var(--duration) var(--ease);
}
.chip:hover { background: var(--paper); border-color: var(--ink-3); }
.chip--active { background: var(--ink); color: var(--text-on-dark); border-color: var(--ink); }
.chip--accent { background: var(--accent-tint); color: var(--accent); border-color: var(--accent); }

/* Tag (smaller, mono uppercase) */
.tag {
  display: inline-flex; align-items: center; gap: var(--space-2);
  padding: 2px var(--space-3);
  background: var(--tag-bg, var(--paper-2)); color: var(--tag, var(--text-2));
  border-radius: var(--radius-xs);
  font: 500 var(--fz-micro)/1.2 var(--font-mono);
  letter-spacing: var(--ls-mono);
  text-transform: uppercase;
}
```

**Tag colour assignment:** when creating a tag, hash the name and pick one of 10 semantic hues (`green, blue, yellow, teal, purple, magenta, brown, olive, indigo, brand`). Same name → same color across the app. Provided JS helper: `tagColor(name)` in `/public/assets/js/utils.js`.

---

## 13. Icons & logo

### 13.1 Icons

**FontAwesome 6 Free** (`fa-solid`, `fa-regular`). Vendored at `/public/assets/vendor/fontawesome/`. Sizes:

- Inside buttons: 14px (default FA font-size at our 13px button text feels right).
- Topbar / corner-tag: 12px.
- Card meta: 11px.

**Don't** mix emoji into the UI. Ever.

### 13.2 Logo

New SVG: **kanban-style icon** — three vertical bars with a check mark in the third. Works on dark and as favicon. Source in `/public/assets/img/logo.svg` (also imported inline into sidebar via PHP `file_get_contents()`).

```xml
<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
  <rect x="2"  y="4" width="5" height="16" rx="1.5" fill="currentColor" opacity="0.32"/>
  <rect x="9"  y="4" width="5" height="11" rx="1.5" fill="currentColor" opacity="0.55"/>
  <rect x="16" y="4" width="5" height="7"  rx="1.5" fill="currentColor"/>
  <path d="M16.5 16.5 L18 18 L21 14.5" stroke="currentColor" stroke-width="1.8"
        stroke-linecap="round" stroke-linejoin="round" fill="none"/>
</svg>
```

Favicon: same SVG, also exported as 32×32 + 180×180 PNG fallback in `/public/`.

---

## 14. Behaviour & UX rules

1. **No native `alert/confirm/prompt`** anywhere — only `UI.confirm/prompt/toast`.
2. **All forms have CSRF hidden field.** All AJAX uses `X-CSRF-Token` header.
3. **Buttons that mutate state ALWAYS confirm via modal** (delete, block, demote, etc.). Read-only nav doesn't.
4. **Empty states** must offer the next action ("No projects yet. <a>Create one</a>.").
5. **Loading states**: any AJAX > 300ms shows a toast or spinner. Otherwise rely on optimistic UI.
6. **Focus management**: modal opens → focus first input/button. Modal closes → focus returns to trigger.
7. **Keyboard**: Esc closes modals/lightboxes/menus. Enter submits forms. Arrow keys navigate lightbox.
8. **Toast** auto-dismiss 4s, click dismisses sooner.
9. **Click vs drag** on kanban cards: pointer movement < 5px = click → open task in new tab; ≥ 5px = drag.
10. **No vertical bounce**: `overscroll-behavior-y: none` on html+body globally.

---

## 15. PHP integration rules

1. **Views may NEVER call `App::make()`.** Controllers pre-compute all values, pass via render data.
2. **Escape all output** via `e()`. The only exception: pre-rendered markdown HTML from `Service\Markdown`.
3. **Static assets** under `/public/assets/{css,js,vendor,fonts,img}/`. Never inline a `<style>` block in templates.
4. **JS modules** go in `/public/assets/js/`. Use ES modules. Always `import { api, UI } from './ui.js'`.
5. **No inline event handlers** (`onclick=…`). All wired via `addEventListener` in the page's module script.
6. **CSP-safe**: no inline scripts with logic. Module scripts via `src=` only.

---

## 16. File-naming conventions

- BEM-ish: `.kanban-col`, `.kanban-col__head`, `.kanban-col--collapsed`.
- Utility classes prefixed: `.u-mt-8`, `.u-mono`, `.u-muted`.
- JS modules kebab-case: `kanban.js`, `comment-thread.js`.
- Tests: `tests/unit/test_*.php`, `tests/e2e/*.spec.ts`.
- Views: `views/{section}/{action}.php`.

---

## 17. Accessibility minimums

- All interactive elements focusable. Visible focus ring (`box-shadow: 0 0 0 3px var(--focus-ring-alpha)`).
- Color is **never** the sole signal — pair with icon or text.
- Images carry `alt`. Decorative SVGs `aria-hidden="true"`.
- Forms: every input has `<label>`. `aria-describedby` for errors.
- Modal: `role="dialog" aria-modal="true"`, label via `aria-labelledby`.

WCAG target: AA on body text (4.5:1), large headings 3:1. Audit periodically via APCA.

---

## 18. Component implementation checklist

When adding a new component:

- [ ] Uses semantic tokens (Layer B), never raw hex.
- [ ] Has visible border or shadow at rest if it's interactive.
- [ ] Has hover state distinct from rest.
- [ ] Has `:focus-visible` ring.
- [ ] Has disabled state (opacity 0.5 + pointer-events none).
- [ ] Keyboard-accessible (Tab into, Enter activates, Esc cancels if applicable).
- [ ] Mobile breakpoint considered (`@media (max-width: 960px)`).
- [ ] No naked text/numbers — every meaningful number paired with a unit/label.

---

## 19. What NOT to do

- ❌ `font-style: italic` — anywhere.
- ❌ Dot-pattern background.
- ❌ Multiple H1s on a page.
- ❌ Inline `<style>` blocks.
- ❌ Inline `<script>` with logic (CSP blocks anyway).
- ❌ Native `alert/confirm/prompt`.
- ❌ Emoji in the UI.
- ❌ Hardcoded hex outside the palette layer in tokens.
- ❌ Pixel-perfect copies of Fraunces editorial italic — Manrope replaces with weight + color.
- ❌ The "PRO" badge over the avatar — feature flag never existed.
- ❌ Tag colors hardcoded inline (`style="background: #x"`). Use semantic tag hues via CSS variables.

---

## 20. Living document

This file evolves with the product. PRs that add components should:

1. Add the component section here.
2. Reference Layer B tokens — define new Layer A tokens only when you've exhausted the palette.
3. Add a Playwright spec in `tests/e2e/components/{name}.spec.ts`.
4. Add a screenshot to `docs/design-screenshots/{name}.png` (manual capture).

Last reviewed: 2026-05-22.
