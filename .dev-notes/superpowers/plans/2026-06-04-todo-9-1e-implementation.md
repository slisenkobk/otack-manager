# TODO #9.1e Implementation Plan — Close S-6 + full visual/functional verification

> **For agentic workers:** Use superpowers:subagent-driven-development or executing-plans. This wave **must** finish on a single ship-ready commit — the goal is to mark TODO #9 fully done and tag `v1.4.0`. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Eliminate the last carry-forward debt from the #9 audit (S-6 CSP `unsafe-inline` for styles) and verify the entire application end-to-end via a full visual + functional sweep. After this wave, TODO #9 is closed and the next semver bump is justified as a minor (`v1.4.0`) because the CSP-tightening removes a documented attack vector.

**Architecture:** No new abstractions. The four moving parts:
1. A small `inline_style(string $css, ?array $vars = null): string` PHP helper that emits `style="…" nonce="<random>"` against the per-request nonce already produced by `csp_nonce()` (Wave 9.1b).
2. CSS custom-property bridge for genuinely dynamic styles (`<span class="dot dot--color" data-color="#abc">` + a 5-line JS reader that copies `data-color` → `style="--c: #abc"` with the nonce on the element — same outcome as inline, but via `setAttribute` which is *not* subject to CSP-style-src).
3. Per-page semantic classes for the ~114 one-off remaining inline styles. No new utility classes — only semantic ones, scoped to their view.
4. CSP header change in `public/index.php`: `style-src 'self' 'nonce-<X>'` (drops `'unsafe-inline'`).

**Tech Stack:** PHP 8.2+ / SQLite + MySQL 8.0 / vanilla JS ES modules / Playwright. No new build tooling.

**Spec parent:** [docs/superpowers/specs/2026-06-03-todo-9-audit-and-cleanup-plan.md](../specs/2026-06-03-todo-9-audit-and-cleanup-plan.md) — S-6, CSS-5b (continuation of CSS-5), CSS-4 finalisation.

**Branch:** `polish/9-1e` off `main` (currently at `5f1a74a` post-Wave-9.1d merge).

**Prerequisites:**
- Wave 9.1d complete (`v1.3.3` shipped, 142 inline-style attributes left).
- The `csp_nonce()` helper already exists from Wave 9.1b — see `system/View/helpers.php`.
- All 4 CI jobs green on `main` at branch point.

**Tag at wave close:** `v1.4.0` (minor bump — closes #9 + tightens documented CSP posture).

---

## Conventions

- Each task ends in **one commit**. No multi-commit PRs for these items.
- Visual smoke is mandatory for every CSS / view change — run a focused
  Playwright pass after each task touching multiple views.
- Snapshot tests are NOT updated until the final visual-audit task — a
  diff in `tests/e2e/visual-audit.spec.ts` mid-wave hides regressions
  introduced earlier.
- Use TDD for the helper additions (Tasks 1, 3). Use visual smoke for the
  sweep tasks (Tasks 2, 4).

---

## Part A — Eliminate inline `style=""` and tighten CSP

### Task 1 — `inline_style()` PHP helper + nonce-stamp infrastructure (S-6)

**Files:**
- Modify: `system/View/helpers.php` (add `inline_style()`)
- Modify: `system/View/helpers.php` (audit `csp_nonce()` is request-scoped)
- Create: `tests/unit/test_inline_style_helper.php` (3-4 tests)

- [ ] **Step 1: Verify `csp_nonce()` exists and is request-scoped.**

  Grep `system/View/helpers.php` for `csp_nonce`. It must return a stable
  base64 string per request (16 bytes from `random_bytes`). If absent,
  add it — it was supposed to land in Wave 9.1b Task 13 but the hot-fix
  may have reverted it.

  ```php
  function csp_nonce(): string {
      static $nonce = null;
      if ($nonce === null) {
          $nonce = base64_encode(random_bytes(16));
      }
      return $nonce;
  }
  ```

- [ ] **Step 2: Add `inline_style()` helper.**

  ```php
  /**
   * Emit a `style="…"` attribute WITH the per-request CSP nonce so it
   * survives the post-S-6 `style-src 'self' 'nonce-X'` directive.
   * Use sparingly — most cases should be a class. Reserved for genuinely
   * dynamic styles (user-picked colors, progress widths, etc.).
   *
   * The CSS string is NOT escaped beyond what's needed to keep the
   * attribute well-formed — callers must validate any user-controlled
   * fragments themselves (e.g. hex-color regex).
   */
  function inline_style(string $css): string {
      $css = str_replace(['"', "\n", "\r"], ['&quot;', ' ', ' '], $css);
      return 'style="' . $css . '" nonce="' . e(csp_nonce()) . '"';
  }
  ```

- [ ] **Step 3: TDD — `tests/unit/test_inline_style_helper.php`.**

  ```php
  it('inline_style emits style attribute with the per-request nonce', function () {
      $out = inline_style('color: red');
      assert_true(str_contains($out, 'style="color: red"'));
      assert_true(preg_match('/nonce="[A-Za-z0-9+\/=]{20,}"/', $out) === 1);
  });
  it('inline_style escapes embedded double quotes', function () {
      $out = inline_style('content: "x"');
      assert_true(!str_contains($out, 'content: "x"'));
      assert_true(str_contains($out, '&quot;'));
  });
  it('inline_style reuses the same nonce within a request', function () {
      assert_eq(csp_nonce(), csp_nonce());
  });
  ```

- [ ] **Step 4: Commit**

  ```bash
  git add system/View/helpers.php tests/unit/test_inline_style_helper.php
  git commit -m "feat(view): inline_style() helper — nonced inline style for S-6 nonce-only CSP"
  ```

---

### Task 2 — Sweep ~114 unique static inline styles into per-page classes

**Files:** every `views/**/*.php` that still ships an inline `style=""`.

This is the bulk of the work — script-driven sweep with manual review per
page. The goal is **zero static inline styles** by the end.

- [ ] **Step 1: Generate the to-do list.**

  ```bash
  grep -rnE 'style="[^"]*"' views/ 2>/dev/null \
      | grep -vE 'style="[^"]*<\?' \
      > /tmp/static-inline.txt
  wc -l /tmp/static-inline.txt
  # expect ~127 lines (142 - 15 dynamic)
  ```

- [ ] **Step 2: Group by file. For each file, decide between three exits:**

  a) **Repeating pattern → existing utility.** Already exhausted in
     Wave 9.1d (`utilities.css` has ~50 utilities now). Reach for them
     first; only add new utilities if the pattern recurs ≥3× across views.

  b) **Page-specific layout → new semantic class in the matching
     stylesheet.** Examples:
     - `views/tasks/show.php:32` `task-title` complex border/padding/cursor
       → move to `cards-panels.css` as `.task-title` and `.task-title.is-editable`
       (already partially defined — finish the migration).
     - `views/projects/show.php:158` same for `project-title`.
     - `views/admin/compass/*.php` already cleaned in 9.1d.

  c) **Truly one-off (rare) → use `inline_style()` from Task 1.**
     Don't pollute stylesheets with single-use classes.

- [ ] **Step 3: Execute file-by-file. Commit per file (or per related group).**

  Track progress in a checklist:

  ```
  - [ ] views/tasks/show.php             (~6 styles → kanban.css/.task-* classes)
  - [ ] views/projects/show.php          (~7 styles → kanban.css/.project-* classes)
  - [ ] views/projects/form.php          (~3 styles → 1 class + 2 inline_style)
  - [ ] views/projects/index.php         (~4 styles → 1 class on .project-card)
  - [ ] views/forms/index.php            (~3 styles)
  - [ ] views/forms/builder.php          (~3 styles)
  - [ ] views/forms-data/index.php       (~3 styles)
  - [ ] views/forms-data/show.php        (~2 styles)
  - [ ] views/polls/builder.php          (~5 styles)
  - [ ] views/polls/index.php            (~3 styles)
  - [ ] views/polls/show.php             (~3 styles)
  - [ ] views/links/show.php             (~3 styles)
  - [ ] views/users/index.php            (~3 styles)
  - [ ] views/users/show.php             (~3 styles)
  - [ ] views/dashboard/index.php        (~5 styles)
  - [ ] views/profile/show.php           (~2 styles)
  - [ ] views/profile/tokens.php         (~3 styles)
  - [ ] views/admin/updates-tab.php      (~4 styles)
  - [ ] views/admin/compass/*.php        (residual ~6 styles)
  - [ ] views/layouts/auth.php           (~1 style)
  - [ ] views/auth/{login,register,pending}.php  (~3 styles)
  - [ ] views/errors/*.php               (~3 styles)
  - [ ] views/partials/*.php             (~5 styles incl. tag-picker)
  - [ ] views/public/*.php               (~3 styles)
  ```

- [ ] **Step 4: After each file group, smoke-test with Playwright at the
      relevant spec** (e.g. `tests/e2e/projects.spec.ts` after touching
      `views/projects/*.php`).

- [ ] **Step 5: Commits — one per ~5-10 files, message:**

  ```
  refactor(views/{group}): static inline styles → semantic classes (CSS-5 finalisation)
  ```

---

### Task 3 — Convert 15 dynamic inline styles via CSS custom properties + nonce

**Files:**
- Modify: `views/projects/form.php`, `views/projects/index.php`,
  `views/projects/show.php`, `views/tasks/show.php`,
  `views/admin/compass/logs.php`, `views/polls/show.php`,
  `views/links/show.php`, `views/tags/index.php`,
  `views/dashboard/index.php`, `views/partials/{kanban-card,linked-task-row,backlog-row,tag-picker}.php`
- Create: `public/assets/js/dynamic-style.js` (tiny — reads `data-bg` /
  `data-tag-color` / `data-width-pct` and applies via `setAttribute`)
- Modify: `views/layouts/main.php` (load the new module)

The 15 remaining dynamic styles fall into three concrete patterns:

| Pattern | Count | Strategy |
|---------|-------|----------|
| `style="background: <?= $color ?>"` (project/column dot color) | ~9 | `<span class="dot" data-bg="<?= $color ?>"></span>` + JS sets `el.style.setProperty('--bg', el.dataset.bg)`; CSS: `.dot { background: var(--bg) }` |
| `style="--tag: <?= color ?>; --tag-bg: <?= color ?>22"` | ~2 | Same as above; pull both keys via two `data-*` attrs |
| `style="width: <?= $pct ?>%"` (progress bar) | ~1 | `<div class="bar" data-width-pct="<?= $pct ?>"></div>` + JS sets `width: N%` |
| `style="background:#f3faf4;border:1px solid #c8e6c9"` (success banner) | ~1 | Semantic `.banner--success` class — no dynamic value, just hex literals |
| `style="height:<?= $h ?>%;"` (link-stats bar) | ~1 | Same as progress-bar pattern |
| `style="color:<?= $tagColor ?>"` (log-level color) | ~1 | `<span class="log-level" data-color="<?= $tagColor ?>">` + JS applies |

- [ ] **Step 1: Add JS reader at `public/assets/js/dynamic-style.js`.**

  ```js
  // Bridges template-injected colors and widths to CSS custom properties on
  // the element — keeps the rendered HTML free of inline `style=""` and lets
  // us drop CSP `'unsafe-inline'` for style-src (S-6).
  function apply(el) {
    const { bg, tagColor, widthPct, color } = el.dataset;
    if (bg)         el.style.setProperty('--bg', bg);
    if (tagColor)   { el.style.setProperty('--tag', tagColor);
                      el.style.setProperty('--tag-bg', tagColor + '22'); }
    if (widthPct)   el.style.setProperty('width', widthPct + '%');
    if (color)      el.style.setProperty('color', color);
  }
  document.querySelectorAll('[data-bg], [data-tag-color], [data-width-pct], [data-color]')
    .forEach(apply);
  new MutationObserver(muts => {
    muts.forEach(m => m.addedNodes.forEach(n => {
      if (!(n instanceof HTMLElement)) return;
      if (n.matches?.('[data-bg], [data-tag-color], [data-width-pct], [data-color]')) apply(n);
      n.querySelectorAll?.('[data-bg], [data-tag-color], [data-width-pct], [data-color]').forEach(apply);
    }));
  }).observe(document.body, { childList: true, subtree: true });
  ```

  Note: `el.style.setProperty(...)` from JS is **NOT** subject to CSP
  `style-src` (CSP only governs HTML-source-emitted `<style>` blocks and
  `style=""` attributes — not JS DOM mutations). Confirmed by MDN +
  per-spec.

- [ ] **Step 2: Add to layouts.**

  ```php
  // views/layouts/main.php — after theme-init.js
  <script type="module" src="/assets/js/dynamic-style.js"></script>
  ```

  Module type for proper deferral; runs after DOMContentLoaded
  effectively.

- [ ] **Step 3: Migrate each view file pattern.**

  Example for `views/projects/index.php:51`:

  ```php
  // BEFORE
  <div class="ini" style="background: <?= e($p['color'] ?? '#1A1612') ?>;">

  // AFTER
  <div class="ini" data-bg="<?= e($p['color'] ?? '#1A1612') ?>">
  ```

  Required CSS rule (add to `kanban.css` or `cards-panels.css`):

  ```css
  .ini { background: var(--bg, #1A1612); }
  ```

  The fallback inside `var()` covers the case where JS hasn't run yet
  (no-JS users) or where the migration missed an element.

- [ ] **Step 4: Smoke each migrated page in a real browser via Playwright
  (chromium + webkit) — colors must render identically before/after.**

- [ ] **Step 5: Commit per file or small group:**

  ```bash
  git commit -m "refactor(views): {group} dynamic styles → data-* + CSS custom props (S-6 prep)"
  ```

---

### Task 4 — Final inventory: zero non-nonced inline styles

- [ ] **Step 1: Re-grep.**

  ```bash
  grep -rnE 'style="[^"]*"' views/ 2>/dev/null \
      | grep -vE 'nonce="' \
      | wc -l
  # MUST be 0 (or only matches inside .dev-notes/, which is excluded)
  ```

- [ ] **Step 2: If non-zero, treat each remaining as a P0 — re-classify
  (semantic class vs `inline_style()` helper vs `data-*` bridge).**

- [ ] **Step 3: No commit on this task — it's a gate.**

---

### Task 5 — Flip CSP: drop `'unsafe-inline'` from `style-src` (S-6)

**Files:** `public/index.php` only.

- [ ] **Step 1: Modify the header.**

  ```php
  // BEFORE (current main):
  header(
    "Content-Security-Policy: default-src 'self'; "
    . "img-src 'self' data:; "
    . "style-src 'self' 'unsafe-inline'; "
    . "script-src 'self'; "
    . "font-src 'self'; "
    . "connect-src 'self'; "
    . "frame-ancestors 'none'"
  );

  // AFTER:
  $nonce = csp_nonce();
  header(
    "Content-Security-Policy: default-src 'self'; "
    . "img-src 'self' data:; "
    . "style-src 'self' 'nonce-$nonce'; "
    . "script-src 'self'; "
    . "font-src 'self'; "
    . "connect-src 'self'; "
    . "frame-ancestors 'none'"
  );
  ```

  **CSP gotcha:** the `'nonce-…'` source automatically disables
  `'unsafe-inline'` per spec — that's the point (it's why we couldn't add
  it additively in v1.3.1). Removing the literal `'unsafe-inline'` is
  belt-and-suspenders for older browsers that don't fully implement the
  nonce-fallback rule.

- [ ] **Step 2: Update the brand `<style>` tag (already nonced from
      Wave 9.1b but verify).**

  ```bash
  grep -n 'app_brand_style_tag' system/View/helpers.php
  # The helper must emit `<style nonce="…">…</style>`.
  ```

  If not, add the nonce attribute.

- [ ] **Step 3: Update the `i18n-js` JSON island — already non-executable
      (`type="application/json"`), no nonce needed; but verify.**

- [ ] **Step 4: Manual browser smoke — open Chrome DevTools, check the
      Console for any CSP violation reports. There should be zero.**

  Then run the full e2e suite (next task — Task 11).

- [ ] **Step 5: Commit**

  ```bash
  git add public/index.php system/View/helpers.php
  git commit -m "security(csp): drop style-src 'unsafe-inline'; nonce-only for styles (S-6)"
  ```

---

### Task 6 — Final CSS-4 prune: drop now-redundant `!important`

After Task 2 + Task 3, the `!important`s that fought inline styles can
mostly disappear. Re-walk the inventory from Wave 9.1c:

- [ ] **Step 1: List**

  ```bash
  grep -rn '!important' public/assets/css/*.css
  ```

- [ ] **Step 2: For each line, ask "is the inline style it fought still
      present?" — if not, drop the `!important`.**

  Specifically:
  - `kanban.css:95,97` `.task-title.is-editable:hover/:focus` — fought
    `<h1 style="border-bottom: 1px dashed transparent;…">`. After Task 2
    migrates this title to a class, `!important` is no longer needed.
  - `cards-panels.css:117` `.profile-avatar__pic` — was a defence in
    depth; check if `.user-avatar` baseline class can carry the values
    via specificity alone.
  - `kanban.css:307-316` SortableJS drag overlays — **keep** (Sortable
    sets inline via JS, not template).
  - `kanban.css:388, 768`, `utilities.css:113` `[hidden]` / `.hidden` —
    **keep** (utility pattern).
  - `utilities.css:538-575` media-query mobile overrides — **keep**
    (legitimate responsive override).
  - `kanban.css:758` `.kanban-card.is-highlight` animation override — drop
    if there's no inline border-color rival.

- [ ] **Step 3: Commit**

  ```bash
  git add public/assets/css/
  git commit -m "css(prune): drop now-redundant !important after CSS-5 finalisation (CSS-4)"
  ```

- [ ] **Step 4: Expected final count: ~10-12 `!important` declarations.**

---

## Part B — Full visual + functional verification

### Task 7 — Update Playwright config: chromium + webkit + firefox matrix

**Files:** `tests/e2e/playwright.config.ts`

- [ ] **Step 1: Inspect current config.**

  ```bash
  cat tests/e2e/playwright.config.ts
  ```

  If only chromium is enabled, add webkit (Safari) and firefox projects.
  CSP-nonce + `setProperty` from Task 3 needs cross-browser proof.

- [ ] **Step 2: Add projects.**

  ```ts
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'webkit',   use: { ...devices['Desktop Safari'] } },
    { name: 'firefox',  use: { ...devices['Desktop Firefox'] } },
    // Mobile breakpoints — already present, leave as-is.
    { name: 'iphone-12', use: { ...devices['iPhone 12'] } },
    { name: 'pixel-5',   use: { ...devices['Pixel 5'] } },
  ],
  ```

- [ ] **Step 3: `npx playwright install webkit firefox` if not cached.**

- [ ] **Step 4: Run a smoke spec on all three browsers to confirm
      they boot:**

  ```bash
  npx playwright test tests/e2e/auth.spec.ts --reporter=line
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add tests/e2e/playwright.config.ts
  git commit -m "test(e2e): enable webkit + firefox matrix alongside chromium"
  ```

---

### Task 8 — Full e2e suite, all browsers, no parallelism

- [ ] **Step 1: Run the full suite.**

  ```bash
  make e2e 2>&1 | tee /tmp/e2e-wave-9-1e.log
  # Expected: ~73 tests × 3 desktop browsers = ~219 tests.
  # Plus mobile breakpoint specs (a11y.spec.ts, mobile.spec.ts).
  ```

- [ ] **Step 2: For each failure: triage. Distinguish:**
  - **Flake** (network race, throttle DB row, etc.) — re-run isolated to
    confirm; document in follow-ups.
  - **CSP violation regression** — fix the offending view immediately.
  - **Visual regression** from CSS-5 sweep — adjust the new semantic
    class until pixel-equivalent.

- [ ] **Step 3: All 3 desktop browsers + 2 mobile breakpoints must be
      100% green before proceeding.**

---

### Task 9 — Visual baseline regeneration (`visual-audit.spec.ts`)

`tests/e2e/visual-audit.spec.ts` does screenshot comparison against
baselines in `tests/e2e/visual-audit.spec.ts-snapshots/`. After
CSS-5 finalisation these baselines might drift; regenerate them.

- [ ] **Step 1: Delete old snapshots.**

  ```bash
  rm -rf tests/e2e/visual-audit.spec.ts-snapshots/
  ```

- [ ] **Step 2: Regenerate.**

  ```bash
  npx playwright test tests/e2e/visual-audit.spec.ts --update-snapshots --workers=1
  ```

- [ ] **Step 3: Visually inspect the new snapshots** (open in Finder /
      browser). They must show pixel-clean renders of every page in both
      light and dark themes.

- [ ] **Step 4: Run again WITHOUT `--update-snapshots` to confirm they
      match on a second pass.**

  ```bash
  npx playwright test tests/e2e/visual-audit.spec.ts --workers=1
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add tests/e2e/visual-audit.spec.ts-snapshots/
  git commit -m "test(visual): regenerate baselines after CSS-5 finalisation"
  ```

---

### Task 10 — Live walkthrough with the seed admin (Playwright MCP)

The 21-step QA-walk in `tests/e2e/qa-walk.spec.ts` exercises the golden
path mechanically. For Wave 9.1e closure we add an **interactive** pass
with the user (manual or MCP-driven) to catch UX issues e2e can miss.

Credentials: seed admin `admin@task.otack.eu` / `30926565` (from user's
earlier message — already in working memory).

- [ ] **Step 1: Start the dev server.**

  ```bash
  make dev
  # Browser-open http://localhost:8000/login
  ```

- [ ] **Step 2: Walk through these 14 flows, screenshotting one at a time:**

  1. **Login + register flow** (already-tested but verify CSP no-warning)
  2. **Dashboard** — activity feed, projects card, recent comments
  3. **Project create + member-add + column-add + task-add**
  4. **Kanban drag + drop** (cards across columns) + column reorder
  5. **Task page** — title edit, description WYSIWYG, due-date picker,
     assignee picker, related-tasks linker, delete + promote-to-project
  6. **Comments + attachments** (upload image, lightbox open)
  7. **Forms builder** — create form, add fields, attach to project,
     public submission → auto-task
  8. **Polls** — create, activate, vote anonymously, view voter list
  9. **Short links** — create, copy, rotate slug, click + stats
  10. **Admin → Settings → Workspace** (brand color, project palette)
  11. **Admin → Compass → Migrations, Cache, Logs, Stats, DB-migrate**
  12. **API tokens** — create, reveal once, use for `/api/v1/me`,
      revoke
  13. **Profile + theme toggle** (light → auto → dark + reload
      persistence)
  14. **Mobile viewport** (DevTools toggle device toolbar →
      iPhone 12 + iPad mini) — sidebar drawer, kanban scroll, modal
      sizing

- [ ] **Step 3: For each flow, in the Console: confirm zero CSP
      violations, zero JS errors, zero 404s on assets.**

- [ ] **Step 4: Note any issue in `.dev-notes/superpowers/qa/2026-06-04-wave-9-1e-walkthrough.md`.**

- [ ] **Step 5: Fix each issue before moving to Task 11. Commit per fix:**

  ```bash
  git commit -m "fix({area}): {what} (Wave 9.1e walkthrough)"
  ```

---

### Task 11 — a11y audit (axe-core via Playwright)

Wave 9.1a added basic `tests/e2e/a11y.spec.ts`. Extend it to cover the
new fieldset/legend grouping from 9.1c and the data-attr-driven color
elements from Task 3 — screen readers need labels for dynamic content.

- [ ] **Step 1: Install axe-playwright if absent.**

  ```bash
  npm install --save-dev @axe-core/playwright
  ```

- [ ] **Step 2: Add a comprehensive sweep to `tests/e2e/a11y.spec.ts`.**

  ```ts
  import AxeBuilder from '@axe-core/playwright';

  for (const path of ['/login', '/dashboard', '/projects', '/projects/1',
                      '/tasks/1', '/forms', '/polls', '/links',
                      '/admin/settings', '/profile']) {
    test(`a11y: ${path}`, async ({ page }) => {
      await loginAsAdmin(page);
      await page.goto(path);
      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();
      expect(results.violations).toEqual([]);
    });
  }
  ```

- [ ] **Step 3: Fix any violations. Common ones:**
  - Missing `alt` on `<img>` in attachment lightbox.
  - Missing `aria-label` on icon-only buttons.
  - Insufficient contrast on dark-theme `--ink-3` text (recheck after
    Task 2 — utility classes may have shifted things).
  - Form fields without `<label for=…>` (Wave 9.1a covered 34; check the
    remaining ~10).

- [ ] **Step 4: Commit**

  ```bash
  git add tests/e2e/a11y.spec.ts views/
  git commit -m "a11y: axe-core sweep across 10 key pages + fixes (Wave 9.1e finalisation)"
  ```

---

### Task 12 — Performance smoke: Lighthouse on key pages

Use Playwright's Chrome DevTools integration to run Lighthouse against
`/dashboard`, `/projects/1` (with a kanban), and `/login`. Target:
**Performance ≥ 90**, **Accessibility ≥ 95**, **Best Practices ≥ 95**.

- [ ] **Step 1: Add `tests/e2e/lighthouse.spec.ts` (skip in CI; run
      ad-hoc).**

- [ ] **Step 2: Run + record scores in `.dev-notes/superpowers/qa/2026-06-04-wave-9-1e-lighthouse.md`.**

- [ ] **Step 3: If any score < target, file a follow-up — don't block
      release on perf optimisation alone.**

- [ ] **Step 4: No commit unless improvements were made.**

---

## Part C — Release

### Task 13 — Update version + TODO + follow-up close-out

- [ ] **Step 1: Bump `system/version.php` to `1.4.0`.**

- [ ] **Step 2: Update `TODO.md`:**

  ```diff
  - #9 — Refactor and code-quality pass for productisation
  + done - #9 - Refactor and code-quality pass for productisation - done
  +   (tags v1.2.0, v1.2.1 hot-fix, v1.3.0, v1.3.1 hot-fix, v1.3.2, v1.3.3, v1.4.0)
  ```

  Add a closing paragraph mentioning all 73 audit findings closed or
  documented as out-of-scope.

- [ ] **Step 3: Mark `.dev-notes/superpowers/follow-ups/wave-9-1c.md`
      and `wave-9-1a.md` as RESOLVED:**

  - Add header "## STATUS: RESOLVED in Wave 9.1e (v1.4.0)" at top of each.
  - Strike through the open items that closed in this wave.

- [ ] **Step 4: Commit**

  ```bash
  git add system/version.php TODO.md .dev-notes/superpowers/follow-ups/
  git commit -m "release: bump v1.4.0 + close TODO #9 (audit fully resolved)"
  ```

---

### Task 14 — CI green check + push + tag

- [ ] **Step 1: Final full CI run locally.**

  ```bash
  make test     # unit + api + e2e
  ```

  All four jobs (unit-sqlite, unit-mysql, api, e2e) must be green
  before proceeding.

- [ ] **Step 2: Merge to main.**

  ```bash
  git checkout main
  git merge --no-ff polish/9-1e -m "Merge wave 9.1e — TODO #9 fully closed (v1.4.0)"
  ```

- [ ] **Step 3: Annotated tag.**

  ```bash
  git tag -a v1.4.0 -m "v1.4.0 — TODO #9 fully resolved

  S-6 closed: CSP style-src now nonce-only (no 'unsafe-inline').
  CSS-5 finalised: 0 non-nonced inline styles in views/.
  CSS-4 finalised: ~10-12 !important left (utility / sortable / mobile).
  Full e2e suite green across chromium + webkit + firefox.
  axe-core a11y sweep clean on 10 key pages.
  Lighthouse: Perf ≥ 90 / a11y ≥ 95 / Best Practices ≥ 95.

  Closes 73-finding audit started 2026-06-03."
  ```

- [ ] **Step 4: Push.**

  ```bash
  git push origin main && git push origin v1.4.0
  ```

- [ ] **Step 5: Verify the GitHub Actions matrix is green on the new
      `main` commit.**

---

## Self-review

All carry-forward items from earlier waves accounted for:

| Original item | Status in 9.1e |
|---|---|
| S-6 CSP `'unsafe-inline'` for styles | Task 5 — closed |
| CSS-5 inline-style sweep (148 left after 9.1d) | Tasks 2 + 3 — closed |
| CSS-4 `!important` finalisation | Task 6 — closed |
| 17 silent disabled-busy sites | 6 done in 9.1d; ~11 remaining are `form.submit()` one-shots (intentional, documented) |
| View typed DTOs (extract→DTO) | **Explicitly NOT in scope** — out-of-scope per original spec |
| Composer adoption | **Explicitly NOT in scope** — out-of-scope per original spec |
| Pest/PHPUnit migration | **Explicitly NOT in scope** — gated by `docs/TESTING.md` cutover threshold |
| AppContext DTO | **Explicitly NOT in scope** |
| JS-disabled + cookie=auto regression (CSS-6) | Documented in 9.1c follow-up — `acceptable trade-off for admin tool` |

After this wave, the only outstanding items in `.dev-notes/superpowers/follow-ups/` are:
- The above 4 explicitly-out-of-scope items.
- Whatever 9.1e itself discovers and files (likely a handful of minor
  Lighthouse perf notes).

## Risks

1. **CSP-nonce breaking dynamic styles we missed.** Mitigation: Task 4
   gate at zero non-nonced. Then full e2e in Task 8 catches anything
   that the gate missed (e.g. a styled element conditionally rendered
   only on certain user roles).

2. **Visual regression from semantic-class migration.** Mitigation:
   per-file Playwright smoke (Task 2 Step 4) + baseline regeneration
   (Task 9). If a baseline drift is found post-hoc, that's a real
   regression — fix the offending class, don't bless the baseline.

3. **Cross-browser CSP variance.** Older Safari (≤15) handles
   `'nonce-…'` differently. Mitigation: webkit project in Task 7 covers
   current Safari; if a critical-mass user base is on Safari 14 or
   below, document a fallback (re-add `'unsafe-inline'` with the
   reasoning).

4. **axe-core finding a long tail.** Mitigation: time-box Task 11 to 90
   minutes. Real violations are fixed; anything else (low-priority
   contrast nits) goes to a follow-up.

5. **Estimated total effort:** 6–10 hours for an experienced dev who
   knows the codebase. Breakdown:
   - Task 1: 30 min
   - Task 2: 3–4 h (the bulk)
   - Task 3: 1–1.5 h
   - Task 4: 5 min gate
   - Task 5: 15 min
   - Task 6: 30 min
   - Task 7: 20 min
   - Task 8: 45 min run + triage
   - Task 9: 15 min regenerate
   - Task 10: 1–2 h walkthrough
   - Task 11: 1–1.5 h
   - Task 12: 30 min
   - Task 13: 15 min
   - Task 14: 10 min

   Realistically 1 long focused session OR 2 medium sessions.

## When done

TODO #9 is fully closed. `v1.4.0` is the ship-ready cut.

The repo state then is:
- 0 inline styles without a nonce.
- CSP `style-src 'self' 'nonce-X'` — no `'unsafe-inline'` anywhere.
- All e2e green on chromium / webkit / firefox / mobile.
- a11y clean on 10 key pages.
- Lighthouse > 90/95/95.
- All audit findings either closed or marked out-of-scope with
  documented rationale.
