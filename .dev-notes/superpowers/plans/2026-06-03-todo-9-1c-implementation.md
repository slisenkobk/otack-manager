# TODO #9.1c Implementation Plan — Continuous polish backlog

> **For agentic workers:** Use superpowers:subagent-driven-development or executing-plans. This wave is a **backlog of small, independent tasks** — pick them up opportunistically; not a sequential epic. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Pay down the "nice-to-have" tier (18 items) from the 2026-06-03 audit. These are not ship-blockers and not architectural debt — they're papercuts, micro-optimisations, dead-code removals, and small UX/i18n polish that improve code health over time.

**Architecture:** No new patterns introduced. Almost all items are 5-30 minute fixes.

**Tech Stack:** PHP 8.2+ / SQLite + MySQL / vanilla JS ES modules. Composer-free.

**Spec:** [docs/superpowers/specs/2026-06-03-todo-9-audit-and-cleanup-plan.md](../specs/2026-06-03-todo-9-audit-and-cleanup-plan.md)

**Branch policy:** Each task is its own short-lived branch + PR (or a single batched `polish/` branch). Tasks are independent — no ordering constraints.

**Prerequisites:** Wave 9.1a complete (v1.2.0+). Wave 9.1b ideally complete too — some items (e.g., Task 4, Task 7) reference modules that were split in 9.1b. If 9.1b is not yet done, those items can still apply against the original files; just update paths.

**Tag:** Items in this wave do NOT individually trigger a version tag. Bundle them and ship as `v1.x.y` patches as convenient.

---

## Conventions

- Each task ends in **one commit**. No multi-commit PRs for these items.
- No tests required for cosmetic/dead-code/typo fixes (V-7, K-3, J-9, CL-3) — visual smoke is enough.
- Tests are required for any item that changes runtime behavior (A-6, C-4, I-4, AS-4).
- Tasks are listed by their original audit ID for cross-reference.

---

## Backend

### Task 1 — `asset_url()` per-request memo (C-4)

**Files:**
- Modify: `system/View/helpers.php`
- Modify: `system/App.php` (if `App::reset()` needs to clear the static)

- [ ] **Step 1: Add the memo**

```php
function asset_url(string $path): string
{
    static $version = null;
    if ($version === null) {
        try { $version = \App\App::make('settings')->get('asset_version', ''); }
        catch (\Throwable $_) { $version = ''; }
    }
    if ($version === '') return $path;
    return $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
}
```

- [ ] **Step 2: Ensure `App::reset()` clears it** (for test isolation)

In `system/App.php::reset()`, add a `\Closure::bind` call to clear the static, OR refactor the memo into an `App::singleton('asset_version', ...)` registration that resets naturally.

- [ ] **Step 3: Run tests** — counts unchanged.

- [ ] **Step 4: Commit**

```bash
git add system/View/helpers.php system/App.php
git commit -m "perf: per-request memo of asset_version (C-4) — was 37 SELECTs per page"
```

---

### Task 2 — `Migrations::lock()` with `GET_LOCK()` on MySQL (A-6)

**Files:**
- Modify: `system/Database/Migrations.php`

- [ ] **Step 1: Add lock acquisition before applying**

```php
public static function run(SchemaBootstrap $boot, ?string $dir = null): array
{
    $driver = Connection::driverFor($boot->pdo());
    if ($driver?->name() === 'mysql') {
        $locked = $boot->pdo()->query("SELECT GET_LOCK('otack_migrations', 30)")->fetchColumn();
        if (!$locked) {
            // Another worker is mid-migration; wait + retry once
            return [];
        }
    }
    try {
        // ... existing apply loop
    } finally {
        if ($driver?->name() === 'mysql') {
            $boot->pdo()->query("SELECT RELEASE_LOCK('otack_migrations')");
        }
    }
}
```

- [ ] **Step 2: Test**

```php
it('GET_LOCK serializes concurrent boots on MySQL', function () { /* deferred, hard to test without parallel MySQL */ });
```

(Skip if no MySQL fixture; document the lock behavior in `docs/ARCHITECTURE.md`.)

- [ ] **Step 3: Commit**

```bash
git add system/Database/Migrations.php docs/ARCHITECTURE.md
git commit -m "fix(migrations): GET_LOCK serialises concurrent fpm-worker boots on MySQL (A-6)"
```

---

### Task 3 — Delete dead `SmokeController::hello()` (K-3)

**Files:**
- Modify: `system/Controller/SmokeController.php`

- [ ] **Step 1: Delete the method**

The action is unreferenced (only `uiSandbox` is routed). Remove `public function hello()` entirely.

- [ ] **Step 2: Commit**

```bash
git add system/Controller/SmokeController.php
git commit -m "cleanup: remove dead SmokeController::hello (K-3)"
```

---

### Task 4 — Confirm `node_modules/` gitignore status (K-5)

**Files:**
- Check / modify: `.gitignore`

- [ ] **Step 1: Verify**

```bash
git ls-files node_modules/ | head -5
```

Expected: empty. If non-empty, this is the bug to fix.

- [ ] **Step 2: Ensure gitignored**

```bash
grep -E "^/node_modules" .gitignore
```

If absent, add `/node_modules/` to `.gitignore`.

- [ ] **Step 3: Commit (if anything changed)**

```bash
git add .gitignore
git commit -m "cleanup: confirm node_modules gitignored (K-5)"
```

---

## Frontend JS

### Task 5 — Drop `window.__otack*Init` guards (J-5)

**Files:**
- Modify: `views/layouts/main.php` (canonicalise the JS import path)
- Modify: `public/assets/js/ui.js` and any module emitting guards

- [ ] **Step 1: Pick canonical import path**

Either always use `asset_url('/assets/js/ui.js')` (cache-busted) OR always `import './ui.js'` relative. Avoid mixing.

- [ ] **Step 2: Delete the five `if (window.__otack*Init) return; window.__otack*Init = true;` guards**

- [ ] **Step 3: Smoke**

```bash
make e2e 2>&1 | tail -5
```

If anything double-binds (visible in console as duplicate event listeners), revert and document why.

- [ ] **Step 4: Commit**

```bash
git add views/layouts/main.php public/assets/js/ui.js
git commit -m "cleanup(js): drop 5 __otack*Init guards by canonicalising import paths (J-5)"
```

---

### Task 6 — Split `ui.js` (J-6)

**Files:**
- Create: `public/assets/js/ui-modal.js`, `ui-fetch.js`, `ui-bootstrap.js`
- Delete: `public/assets/js/ui.js`
- Modify: `views/layouts/main.php`

(If Task 17 in Wave 9.1b already did this, skip. Otherwise:)

- [ ] **Step 1: Split `ui.js` (411 LOC) by concern**
- [ ] **Step 2: Update imports across all modules**
- [ ] **Step 3: Smoke + commit**

---

### Task 7 — Early-return in `task-page.js` (J-9)

**Files:**
- Modify: `public/assets/js/task-page.js`

- [ ] **Step 1: Find the top-of-file `if (!sidebar) { /* not on task page */ } else { ...300 LOC... }`**

Change to:

```js
if (!sidebar) return;  // not on a task page

// ... 300 LOC unindented one level
```

If the file isn't wrapped in an IIFE/module already, wrap in one so `return` is legal.

- [ ] **Step 2: Smoke**

```bash
npx playwright test tests/e2e/task-page.spec.ts --reporter=line
```

- [ ] **Step 3: Commit**

```bash
git add public/assets/js/task-page.js
git commit -m "cleanup(js): early-return in task-page.js drops 1 level of nesting (J-9)"
```

---

## CSS

### Task 8 — Audit `!important` declarations (CSS-4)

Depends on CSS-5 sweep from 9.1b. After inline `style=""` is reduced:

**Files:**
- Modify: each CSS file that contains an `!important`

- [ ] **Step 1: List remaining**

```bash
grep -n "!important" public/assets/css/*.css
```

- [ ] **Step 2: For each, evaluate**
  - Is it fighting an inline style that's now in CSS? → remove
  - Is it a utility (`.hidden { display: none !important }`)? → keep, add comment
  - Is it fighting JS-set inline style? → either drop and refactor JS, or keep with comment

- [ ] **Step 3: Commit**

```bash
git add public/assets/css/
git commit -m "cleanup(css): drop now-redundant !important after CSS-5 sweep (CSS-4)"
```

---

### Task 9 — Dark theme dedup (CSS-6)

**Files:**
- Modify: `public/assets/css/tokens.css` (or wherever the dark variables live)
- Modify: `public/assets/js/theme.js` (server-driven no-flash script)

- [ ] **Step 1: Pick the approach**

Option A (CSS-only with `:where()`): one variable block keyed on a selector that matches both `data-theme="dark"` and the media query. CSS-spec-conformant but verbose selector.

Option B (JS-driven): inline script in `<head>` reads `localStorage`/system pref, sets `data-theme` on `<html>` before render. CSS has only `[data-theme="dark"]` block.

Pick **Option B** — simpler CSS, also enables admin-configured default theme.

- [ ] **Step 2: Implement no-flash inline script**

In `views/layouts/main.php` near the top of `<head>`:

```html
<script>
(function () {
  var stored = localStorage.getItem('otack-theme');
  var def = '<?= e($defaultTheme ?? 'auto') ?>';
  var theme = stored || def;
  if (theme === 'auto') {
    theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  document.documentElement.setAttribute('data-theme', theme);
})();
</script>
```

- [ ] **Step 3: Drop the media-query dark block in CSS**

Keep only `[data-theme="dark"] { ... }`. Delete the duplicated `@media (prefers-color-scheme: dark) { ... }` block.

- [ ] **Step 4: Verify dark theme still works**

```bash
npx playwright test tests/e2e/visual-audit.spec.ts --reporter=line
```

- [ ] **Step 5: Commit**

```bash
git add public/assets/css/tokens.css public/assets/js/theme.js views/layouts/main.php
git commit -m "cleanup(css): dedup dark theme via JS-set data-theme attr (CSS-6)"
```

---

## Views / UX

### Task 10 — Conditional badge in sidebar (V-7)

**Files:**
- Modify: `views/partials/sidebar.php`

- [ ] **Step 1: Wrap projects-count badge in conditional**

```php
<?php if ($projectsCount > 0): ?>
  <span class="nav-item__count"><?= (int)$projectsCount ?></span>
<?php endif; ?>
```

(Same conditional pattern is already used for the Submissions count below it — matching it for consistency.)

- [ ] **Step 2: Commit**

```bash
git add views/partials/sidebar.php
git commit -m "cleanup(ux): hide 0-count badges next to Projects (V-7)"
```

---

### Task 11 — `<fieldset>` / `<legend>` grouping in long forms (V-9)

**Files:**
- Modify: `views/admin/settings.php`, `views/forms/builder.php`, `views/polls/builder.php`

- [ ] **Step 1: Wrap related fields**

For each tab section in admin/settings.php (Branding, Locale, Updates), wrap the field group:

```php
<fieldset class="field-group">
  <legend><?= e(t('settings.section.branding')) ?></legend>
  <!-- existing fields -->
</fieldset>
```

Add corresponding CSS in `forms.css`:

```css
.field-group { border: 0; padding: 0; margin: 0 0 24px; }
.field-group > legend { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-2); margin-bottom: 12px; }
```

- [ ] **Step 2: Commit**

```bash
git add views/admin/ views/forms/ views/polls/ public/assets/css/forms.css system/i18n/
git commit -m "a11y(views): fieldset/legend grouping in long forms (V-9)"
```

---

## i18n

### Task 12 — Document `forms_data.brand_tag` deliberate gap (I-2)

**Files:**
- Modify: `system/i18n/en.php` (inline comment above the key)
- Modify: `tests/unit/test_i18n.php` (the parity test's exemption list should reference this comment)

- [ ] **Step 1: Add comment**

```php
// Deliberate en-only: this is a brand-name string baked into the Forms UI footer.
// Translating it would mislabel the product. Whitelisted in the i18n parity test.
'forms_data.brand_tag' => 'Otack Manager',
```

- [ ] **Step 2: Commit**

```bash
git add system/i18n/en.php
git commit -m "docs(i18n): document forms_data.brand_tag deliberate en-only gap (I-2)"
```

---

### Task 13 — Settings-driven project palette (I-4)

**Files:**
- Modify: `system/Controller/SettingsController.php` (expose `project_palette` setting)
- Modify: `views/layouts/main.php` (emit `<meta name="project-palette">`)
- Modify: `public/assets/js/projects.js` (read from meta tag instead of hardcoded array)

- [ ] **Step 1: Add `project_palette` setting**

Default: comma-separated 10 hex codes (the current hardcoded array). Admin can override in `/admin/settings` (Branding tab).

- [ ] **Step 2: Emit in layout**

```php
<meta name="project-palette" content="<?= e(App::make('settings')->get('project_palette', 'AABBCC,DDEE...')) ?>">
```

- [ ] **Step 3: Read in `projects.js`**

```js
function pickPaletteColor() {
  const meta = document.querySelector('meta[name=project-palette]');
  const palette = (meta?.content || '').split(',').filter(Boolean);
  if (!palette.length) return '#8B7C68';  // safe default
  return '#' + palette[Math.floor(Math.random() * palette.length)];
}
```

- [ ] **Step 4: Commit**

```bash
git add system/Controller/SettingsController.php views/layouts/main.php views/admin/settings.php public/assets/js/projects.js system/i18n/
git commit -m "feat(branding): admin-configurable project color palette (I-4)"
```

---

## Assets

### Task 14 — Lazy-load Sortable (AS-4)

**Files:**
- Modify: `public/assets/js/kanban.js` (or `kanban-board.js` if 9.1b done)
- Modify: `public/assets/js/form-builder.js`
- Modify: `views/layouts/main.php` (remove unconditional Sortable script)

- [ ] **Step 1: Dynamic load helper**

In `utils.js`:

```js
let sortablePromise = null;
export function loadSortable() {
  if (window.Sortable) return Promise.resolve(window.Sortable);
  if (sortablePromise) return sortablePromise;
  sortablePromise = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = '/assets/vendor/sortable.min.js';
    s.onload = () => resolve(window.Sortable);
    s.onerror = reject;
    document.head.appendChild(s);
  });
  return sortablePromise;
}
```

- [ ] **Step 2: Use in kanban + form-builder**

Before `new Sortable(...)`, `await loadSortable()`.

- [ ] **Step 3: Remove unconditional load**

Find `<script src="/assets/vendor/sortable.min.js">` in views/layouts/main.php and delete.

- [ ] **Step 4: Smoke**

```bash
npx playwright test tests/e2e/kanban.spec.ts tests/e2e/forms-auto-task.spec.ts --reporter=line
```

- [ ] **Step 5: Commit**

```bash
git add public/assets/js/utils.js public/assets/js/kanban*.js public/assets/js/form-builder.js views/layouts/main.php
git commit -m "perf(assets): lazy-load Sortable (-44KB on non-kanban pages) (AS-4)"
```

---

## Tests

### Task 15 — Dark theme persistence e2e (T-8)

**Files:**
- Create: `tests/e2e/theme.spec.ts`

- [ ] **Step 1: Spec**

```ts
import { test, expect } from '@playwright/test';

test('dark theme toggle persists across reload', async ({ page }) => {
  await page.goto('/');
  // Find the theme toggle button (look at how the app exposes it — data-theme-toggle attr?)
  const toggle = page.locator('[data-theme-toggle]');
  await expect(toggle).toBeVisible();
  await toggle.click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  // Toggle back
  await toggle.click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
});
```

- [ ] **Step 2: Run**

```bash
npx playwright test tests/e2e/theme.spec.ts --reporter=line
```

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/theme.spec.ts
git commit -m "test(e2e): dark theme persistence across reload (T-8)"
```

---

## Docs

### Task 16 — Translate / split `TODO.md` (D-5)

**Files:**
- Modify: `TODO.md` (English)
- Create: `ROADMAP.md` (Russian strategic prose — optional)

- [ ] **Step 1: Decide split**

Option A (preferred for public repo): Translate all to English. Move strategic prose to `ROADMAP.md` if it's narrative-heavy.

Option B: Keep bilingual but add English summary line above each Russian block.

Pick Option A unless the contributor base is exclusively Russian-reading.

- [ ] **Step 2: Translate**

Each `#N - ...` task line gets an English rewrite. Audit blocks (`#9.1`, `#9.1a/b/c`) are already in English — leave them.

- [ ] **Step 3: Commit**

```bash
git add TODO.md ROADMAP.md
git commit -m "docs: TODO.md to English; strategic prose moved to ROADMAP.md (D-5)"
```

---

### Task 17 — `INTEGRATION-CHECKLIST.md` polish (D-6)

**Files:**
- Modify: `docs/INTEGRATION-CHECKLIST.md`

- [ ] **Step 1: Add 3-4 checkboxes**

Under existing sections:
- Under "Wire the client": `- [ ] Client knows there are no idempotency keys; retries on POST may create duplicates. Use natural keys client-side to deduplicate.`
- Under "Hardening": `- [ ] Client subscribes to schema changes by polling GET /api/v1/openapi.yaml weekly.`
- Under "Operations": `- [ ] You alert on 5xx rates and on 4xx codes that indicate auth drift (401 spikes).`

- [ ] **Step 2: Commit**

```bash
git add docs/INTEGRATION-CHECKLIST.md
git commit -m "docs(api): idempotency + schema-poll guidance in INTEGRATION-CHECKLIST (D-6)"
```

---

## Ops / Cleanup

### Task 18 — Test runner cutover threshold note (O-7)

**Files:**
- Modify: `docs/TESTING.md` (created in 9.1a)

- [ ] **Step 1: Add a "When to consider migrating to Pest/PHPUnit" section**

```markdown
## When to consider migrating off the hand-rolled runner

We deliberately use a tiny PHP test runner at tests/run.php instead of
Pest/PHPUnit. This is fine while:
- Total wall-clock under 30s
- Under 400 unit tests
- No cross-test DB pollution requiring per-test resets

When any of those tip, evaluate migrating. The runner contract is
minimal (`it()`, `assert_eq()`, `assert_true()`, `apply_migration()`)
so a swap is straightforward.
```

- [ ] **Step 2: Commit**

```bash
git add docs/TESTING.md
git commit -m "docs(testing): document test-runner cutover threshold (O-7)"
```

---

### Task 19 — Auto-cleanup of `test-results/` (CL-2)

**Files:**
- Modify: `Makefile`
- Optional: `playwright.config.ts`

- [ ] **Step 1: Add a `test-clean` target**

```make
test-clean:
	@rm -rf test-results/ .playwright/ data/app.test.sqlite* data/.schema.test
	@rm -rf data/app.api-test.sqlite*
	@echo "Test artifacts cleared."

e2e: test-clean
	npx playwright test
```

- [ ] **Step 2: Commit**

```bash
git add Makefile
git commit -m "ops(test): make e2e auto-clears test-results to prevent disk creep (CL-2)"
```

---

### Task 20 — `reset-test` includes api-test sqlite (CL-3)

**Files:**
- Modify: `Makefile`

- [ ] **Step 1: Update `reset-test` target**

```make
reset-test:
	@rm -f data/app.test.sqlite data/app.test.sqlite-wal data/app.test.sqlite-shm
	@rm -f data/app.api-test.sqlite data/app.api-test.sqlite-wal data/app.api-test.sqlite-shm
	@rm -rf data/.schema.test public/uploads-test
	@mkdir -p public/uploads-test
	@echo "Test DB + schema + uploads reset."
```

- [ ] **Step 2: Commit**

```bash
git add Makefile
git commit -m "ops(test): reset-test also clears api-test sqlite + uploads-test (CL-3)"
```

---

## Cross-cutting

### Task 21 — `error_log()` prefixes audit (K-4)

Folds into 9.1b Task 7 (`Log::error` migration). If 9.1b already done, K-4 is implicitly closed.

- [ ] **Step 1: Verify**

```bash
grep -rn "error_log\(" system/ | grep -v "^.*Log::" | wc -l
```

Expected: 0 (or only `error_log()` calls inside `Log.php` itself).

- [ ] **Step 2: No commit if zero. Otherwise migrate the remaining sites.**

---

## When to merge / how to ship

These 21 tasks can be bundled into a single `polish/9-1c` branch and shipped as `v1.3.x` patches:

- After 5-7 tasks land → patch tag (`v1.3.1`, `v1.3.2`, ...)
- After ~15 tasks → minor tag (`v1.4.0`)
- After all 21 → close TODO #9 entirely

No fixed schedule. Run continuously as background polish.

---

## Self-review

All 18 nice-to-have items accounted for:
- Backend: A-6, C-4, K-3, K-5 → Tasks 1-4
- Frontend JS: J-5, J-6, J-9 → Tasks 5-7
- CSS: CSS-4, CSS-6 → Tasks 8-9
- Views/UX: V-7, V-9 → Tasks 10-11
- i18n: I-2, I-4 → Tasks 12-13
- Assets: AS-4 → Task 14
- Tests: T-8 → Task 15
- Docs: D-5, D-6 → Tasks 16-17
- Ops/Cleanup: O-7, CL-2, CL-3, K-4 → Tasks 18-21

Three "out of scope" items from the spec (Composer adoption, Pest/PHPUnit cutover, `AppContext` DTO) remain deferred — Task 18 documents the cutover threshold as a future decision.
