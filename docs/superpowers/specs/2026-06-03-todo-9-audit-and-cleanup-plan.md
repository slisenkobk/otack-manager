# TODO #9 — Project Audit & Cleanup Plan

**Status:** Plan ready for execution
**Date:** 2026-06-03
**Baseline:** `main` @ `86b8ec7` after v1.1.0 + post-release fixes
**Supersedes:** TODO.md item #9.1 (2026-06-02 audit checklist)

## Why this exists

TODO #9 asks for "рефакторинг и чистоту кода для упаковки продукта, с документацией". The 2026-06-02 inline audit captured part of it; this spec is the refreshed, fuller picture after v1.1.0 (External REST API, +9000 LOC) shipped and the e2e suite was restored to green.

**73 findings** across three parallel reviews:
- **Backend PHP** (architecture + code quality + security + cleanup): 22 items
- **Frontend** (JS + CSS + views/UX + i18n + assets): 23 items
- **Tests/Docs/Ops** (coverage + docs + packaging + CI): 28 items

This document inventories them, sorts by ship-blocker tier, and groups into 3 execution waves.

## Verified baseline numbers

| Metric | Count |
|---|---|
| PHP source LOC | 17,448 |
| JS LOC (31 modules) | 4,211 |
| CSS LOC (1 file) | 5,175 |
| i18n keys | en 678 / pl 677 / uk 677 |
| Tests | 189 unit / 85 api / 112 e2e (114 specs total, 2 skipped before fix branch) |
| `App::make()` in controller bodies | 222 occurrences across 23 controllers |
| Silent JS `catch {}` blocks | 49 |
| Hardcoded EN toast strings in JS | 77 |
| Hardcoded EN confirm strings in JS | 23 |
| Inline `style=""` attributes in views | 348 |
| Unused i18n keys (no `t()` reference) | 105 |
| FA brand fonts shipped, never used | 324 KB |
| `for=""` on labels vs `<label>` elements | 3 vs 76 |
| `aria-live` regions | 1 |

---

## Tier 1 — MUST FIX before announcing 1.0 (ship-blocker)

These are security holes, accessibility blockers, broken contracts, or material breakage in produced tarballs.

### Security (highest urgency)
- **S-1** — Login throttle is per-session, attacker-bypassable. Move to DB-backed RateLimiter keyed on `sha256(email)`. `system/Auth/AuthManager.php:36-54` → 3h.
- **S-2** — No `session_regenerate_id()` after login. Classic session-fixation gap. `system/Controller/AuthController.php:75-86` + reg flow at :137-143. → 30m.
- **S-4** — `X-Forwarded-For` honoured unconditionally in 3 public controllers (poll rate-limit, short-link unique-visitor count, form submission audit IP). Add `TRUSTED_PROXIES` env + `Request::clientIp()` helper. `PublicPollController.php:272-281`, `PublicLinkController.php:63-72`, `PublicFormController.php:252-264`. → 2h.

### Accessibility (a11y)
- **V-1** — Only 3 `for=""` attributes vs 76 `<label>`s. Screen-reader users hear "edit text" with no context. Mechanical sweep of all form views. → 4-6h.
- **V-2** — Toast root has no `role="status"` / `aria-live="polite"`. Every "Saved" / "Updated" is invisible to AT. Single-line view change + ui.js. → 30m.

### Packaging breakage
- **K-1 / CL-1** — `data.backup-pre-migrate-refactor/` (544 KB) still on dev disk, will ship in `make package`. Delete + exclude. → 5m.
- **K-2** — i18n parity: `forms_data.brand_tag` was deliberate en-only gap, but no CI assertion. Add convention test + fix the gap or whitelist it. → 15m.
- **O-1** — `make package` ships internal `docs/superpowers/`, `docs/PLAN-next-session.md`, `docs/NEXT-SESSION-PROMPT.md`, `package.json`, `playwright.config.ts`, `data/app.api-test.sqlite*`, `data/backups/`. Operators get dev tooling + legacy backups. Add exclusions + `make package-check` smoke. → 30m.
- **O-2** — CI runs only `make unit`. API (85 tests) and e2e (112 specs) never run on push/PR. Wire both into the workflow. → 30m.

### Front-end JS contract
- **J-3** — 49 silent `catch {}` blocks across 22 modules. Worst: `kanban.js` (7), `task-page.js` (7). IntersectionObserver swallows errors → broken lazy-load goes unnoticed. Add `logSilent(err, where)` helper + ESLint `no-empty` rule. → 3h.
- **J-7 / I-3** — 77 hardcoded EN toast strings + 23 confirm strings in JS. PL/UK users see English on every async write. Standardise on `data-i18n-*` or `window.__t` injection from layout. → medium (1 day).
- **J-8** — `task-page.js:123` builds toast labels from snake_cased field names ("column id updated"). Awkward English + i18n gap. Explicit i18n key map. → 30m.

### Test surface
- **T-1** — `HtmlSanitizer` has zero tests. The single XSS defence on persisted Quill HTML. ~12 cases. → 45m.
- **T-3** — `RolePolicy` matrix only covers 1/11 methods (`canDeleteComment`). Audit explicitly asked for the matrix. ~12 tests. → 30m.

### Documentation (CRITICAL for packaging)
- **D-1** — `ARCHITECTURE.md`, `TESTING.md`, `SECURITY.md`, `DEPLOYMENT.md`, `FRONTEND.md` — all listed in 2026-06-02 audit, none exist. Without `SECURITY.md` and `DEPLOYMENT.md` we cannot honestly ship a tarball. 200-400 LOC each, scaffolds first. → 4h for skeletons.
- **D-2** — `DESIGN.md` and `QA-CHECKLIST.md` are 12 days stale. QA-CHECKLIST has zero mentions of Forms, Polls, Short Links, Updates, MySQL, External API. 6 sections to add. → 2h.
- **V-6** — `partials/activity-row.php` verbs hardcoded EN despite `activity.*` i18n keys existing in all 3 catalogues. 18 keys × 3 langs = 54 strings dead until this fixes. One `match` → `t()` rewrite. → 30m.

**Tier 1 total: ~24h of work (3 dev-days).**

---

## Tier 2 — SHOULD FIX in the 1.1.x window

### Backend architecture
- **A-1** — `public/index.php` (446 LOC) owns bootstrap + DI + 100+ routes + middleware. Split into `Bootstrap/Container.php`, `Bootstrap/Events.php`, `Bootstrap/Routes.php`. → medium (4-8h).
- **A-2** — 16 Telegram event listeners as inline closures at index.php:90-173 (84 LOC). Extract `system/Events/TelegramListeners.php`. → 2-3h.
- **A-3** — Two route systems (web Router + ApiKernel string map). Either unify or document the split in ARCHITECTURE.md. → 4h.
- **A-4** — DI container has no boot phase. Add re-entry guard in `App::make()` at minimum. → 2h.
- **A-7** — `ApiKernel::normalisePath()` collapses every numeric segment to `{id}`. Latent risk for future routes. Pre-compile per-route regex. → 2h.

### Backend code quality
- **C-1** — 222 `App::make()` calls in controller bodies across 23 controllers. Sweep + convention test. → medium (afternoon).
- **C-2** — JSON body parse boilerplate in 17+ controller actions. Lift to `Request::jsonBody()` reusing `JsonRequest::parse()`. → 3-4h.
- **C-3** — POST validation duplication (trim + 422 return) in 46 places. Introduce `system/Http/Validator.php`. → 4-6h.
- **C-5** — 9 scattered `error_log()` calls with ad-hoc prefixes. Introduce `Log::error()` service. → 2h.
- **C-6** — Repository return types unannotated. Apply `@return list<array<string,mixed>>` standard across 21 repos. → 4h.
- **C-7** — `ActivityLogRepository::log()` positional-arg-heavy (32+ call sites). Convert to DTO or assoc array. → 3h.
- **C-8** — `pathId($req, 3)` is segment-index-hardcoded. Thread route params from ApiKernel into handlers. → 2h.

### Security (defence-in-depth)
- **S-3** — `HtmlSanitizer` falls back to `strip_tags` when DOMDocument is missing — allows `on*` handlers through. Make DOM a hard prereq. → 1h + docs.
- **S-5** — `SessionManager` `mkdir 0700` doesn't re-chmod existing dirs. Add explicit `chmod()` on boot. → 15m.
- **S-6** — CSP `style-src 'unsafe-inline'` permanent. Migrate `app_brand_style_tag()` to CSP nonce + audit inline `style=`. → medium (depends on V-/CSS-5 progress).
- **S-7** — `LinkController` `json_decode($body) ?: []` silently coerces malformed JSON. Fold into C-2. → covered.
- **S-8** — `LOGIN_HASH` reused as HMAC secret for time-traps + IP hashing. Add `APP_SECRET` env with backward-compat fallback. → 1h.
- **S-9** — Updater `@unlink` / `@rmdir` silently swallow cleanup errors. Replace with try/catch + Log::error. → 2h.

### Front-end JS
- **J-1** — `form-builder.js` (316 LOC) still IIFE with TDZ workaround. Refactor to class. → medium.
- **J-2** — `kanban.js` (652 LOC) has 7 responsibilities. Split into `kanban-board.js` / `kanban-toolbar.js` / `kanban-columns.js` + extract `ui-fields.js`. → medium.
- **J-4** — `buildField` duplicated across 3 modules. Lift to `ui-fields.js`. → small.

### CSS
- **CSS-1** — `app.css` is 5,175 LOC single file. Split into 8 layered files. → medium.
- **CSS-2** — Two parallel button class systems (`.btn--primary` BEM vs `.btn-primary` legacy). 65 vs 38 instances. Pick BEM, migrate. → small but touches many files.
- **CSS-3** — Dead `.top-pill` section (26 LOC) marked "(legacy)", zero references. Delete. → 5m.
- **CSS-5** — 348 inline `style=""` attributes; `tasks/show.php` has 28. Blocks CSP tightening + dark theme. Phased extraction. → medium.

### Views / UX
- **V-3** — Modal focus trap is one-shot, not a real trap. Tab escapes back to underlying page. ~20 LOC fix in `ui.js`. → small.
- **V-4** — 45 ad-hoc `btn.disabled = true; finally { btn.disabled = false; }` blocks. No `aria-busy`, no spinner. Add `withButtonBusy(btn, asyncFn)` helper. → medium.
- **V-5** — Zero inline field-error UI pattern; all errors are flash/toast. Long forms can't tell user which field is invalid. Add `.field--invalid` + per-field error rendering. → medium.
- **V-8** — Mobile breakpoints only at 12 places; `admin/compass/*`, `projects/show`, `tasks/show` have no narrow-viewport rules. → medium.

### i18n
- **I-1** — 105 unused i18n keys (after V-6 fix, drops by 18). Diff-then-prune script + convention test. → small (script + delete pass).

### Assets
- **AS-1** — FontAwesome brand fonts (324 KB) shipped, zero usage. Delete + strip CSS `@font-face`. → small.
- **AS-2** — No `<link rel="preload">` for body font. Visible FOUT on first paint. → small.
- **AS-3** — Quill (236 KB JS + CSS) loaded on every page, used by 6 views. Lazy-load via dynamic import. → medium.

### Tests
- **T-2** — `TelegramNotifier` untested. 6 pure-path tests on `escape()` + `buildMessage()`. → 30m.
- **T-4** — Markdown coverage misses scheme/nested cases. 6-8 cases. → 20m.
- **T-5** — `FileUploader::store()` entirely untested. Directory traversal guard, real-mime sniffing path. 4 tests. → 25m.
- **T-6** — Comment delete + lightbox have no standalone e2e specs. Add to `comments.spec.ts` + `attachments.spec.ts`. → 45m.
- **T-7** — Admin surface (`/admin/settings`, `/admin/compass`, `/admin/updates`) has zero e2e coverage. One `admin.spec.ts` with 5 smoke tests. → 1h.
- **T-9** — No mobile viewport tests. Add `mobile.spec.ts` or a mobile Playwright project. → 1h.
- **T-10** — `EventBus`, `NotificationLogger`, `DbMigrator` untested. EventBus + NotificationLogger first. → 45m.
- **T-11** — `tests/api/run.php` orphans `php -S` on SIGINT. Add `pcntl_signal` handlers. → 15m.
- **T-12** — README claims 140 unit / 17 e2e; actuals are 189 / 112. → 5m.

### Docs
- **D-3 / D-4** — `docs/PLAN-next-session.md`, `docs/NEXT-SESSION-PROMPT.md`, `docs/superpowers/` ship in tarball. Move to `.dev-notes/` or exclude. → 10m.

### Ops
- **O-3** — `.env.example` missing 7 documented-in-code variables (`UPLOAD_DIR`, `UPDATE_*`, `MYSQLDUMP_PATH`, `MYSQL_PATH`). → 10m.
- **O-4** — `package.json` has `"license": "ISC"` contradicting MIT LICENSE + `"main": "index.js"` boilerplate. Fix. → 5m.
- **O-5** — `data/errors.log` + `activity_log` table have no rotation/prune. Add bootstrap-size check + Compass prune button. → 1h.
- **O-6** — PHP extension requirements documented only in README + CI. Add `bin/check-env.php`. → 30m.

**Tier 2 total: ~5 dev-days.**

---

## Tier 3 — NICE TO HAVE (continuous improvement backlog)

### Backend
- **A-5** — `extract($data, EXTR_SKIP)` everywhere in views; typed view-data DTOs would catch silent typos. → large; defer.
- **A-6** — Migrations run on every web request via `BEGIN IMMEDIATE`. On MySQL the locking isn't strong enough for racing fpm workers. Add `GET_LOCK()` or gate behind env. → 2h.
- **C-4** — `asset_url()` re-queries settings table per call (37 hits/page). Per-request memo. → 30m.
- **K-3** — Dead `SmokeController::hello()` method (placeholder from Task 26). Delete. → 5m.
- **K-5** — `node_modules/` may be committed. Verify + gitignore. → 5m.

### Front-end
- **J-5** — Five `window.__otack*Init` guards papering over module-ordering issues. Canonicalize import paths. → small/medium.
- **J-6** — `ui.js` (411 LOC) is a kitchen sink. Split into `ui-modal.js`/`ui-fetch.js`/`ui-bootstrap.js`. → medium.
- **J-9** — `task-page.js:5-7` early-bails by indenting 300 LOC inside else. Flip to early return. → 5m.
- **CSS-4** — 21 `!important` declarations. Audit after CSS-5 sweep. → small per fix.
- **CSS-6** — Dark theme rules duplicated 147 LOC × 2 (media query + data-theme). Collapse with `:where(...)` or JS-toggle pattern. → small.
- **V-7** — Projects sidebar badge always shows count, including "0". Conditional render. → 5m.
- **V-9** — Several admin forms missing `<fieldset>` / `<legend>` grouping. → small.
- **I-2** — `forms_data.brand_tag` deliberate en-only gap — confirmed correct, document as such (not a defect). Whitelist in T-3 convention test. → 5m.
- **I-4** — `projects.js` hardcoded 10-color palette. Move to settings-driven config. → small.
- **AS-4** — `sortable.min.js` (44 KB) loaded globally; only kanban needs it. Lazy import. → small.

### Tests
- **T-8** — Dark theme persistence untested. One e2e. → 15m.

### Docs
- **D-5** — `TODO.md` is bilingual ru/en. Translate or split into ROADMAP.md. → 1h.
- **D-6** — `INTEGRATION-CHECKLIST.md` missing idempotency / retry note. 3-4 checkboxes. → 10m.

### Ops
- **O-7** — Hand-rolled test runner cutover threshold. Decision only. → 5m.
- **CL-2** — `test-results/` accumulates 37 MB. Auto-cleanup or `make test-clean`. → 5m.
- **CL-3** — `reset-test` Makefile target doesn't include `data/app.api-test.sqlite*`. → 3m.

### Cross-cutting
- **K-4** — `error_log()` prefixes ad-hoc. Folds into C-5.
- **K-6 / A-7** — Already covered above.

**Tier 3 total: ~2 dev-days, run as background polish.**

---

## Execution waves

### Wave 9.1a — "Make it safe to ship 1.x" (~24h, parallelisable)

All Tier 1 items. Suggested sub-grouping:

**Day 1 (security):**
- S-1 login throttle → S-2 session_regenerate_id → S-4 trusted proxies + clientIp
- K-1 delete data.backup-pre-migrate-refactor/
- T-1 HtmlSanitizer tests, T-3 RolePolicy matrix
- D-1 SECURITY.md scaffold

**Day 2 (a11y + i18n + UI gaps):**
- V-1 label `for` sweep
- V-2 aria-live toast root
- V-6 activity-row.php i18n unblock
- J-3 silent catch sweep + lint rule
- J-7 JS i18n channel + J-8 task-page field labels

**Day 3 (packaging + docs):**
- O-1 `make package` exclusions + package-check smoke
- O-2 CI runs API + e2e
- K-2 i18n parity convention test
- D-1 DEPLOYMENT.md + ARCHITECTURE.md scaffolds
- D-2 QA-CHECKLIST.md refresh (6 new sections)

**End-of-wave deliverable:** v1.2.0 tagged + pushed. Public README updated.

### Wave 9.1b — "Tidy the architecture" (~5 dev-days)

All Tier 2 items. Suggested sub-grouping:

- **Backend refactor** (A-1, A-2, A-3, A-4, A-7, C-1, C-2, C-3, C-5, C-6, C-7, C-8): 2-3 days.
- **Security defence-in-depth** (S-3, S-5, S-6, S-8, S-9): 1 day.
- **Frontend module reorg** (J-1, J-2, J-4, CSS-1, CSS-2, CSS-3, CSS-5): 1-2 days.
- **UX polish** (V-3, V-4, V-5, V-8): 1 day.
- **Test fill-in** (T-2, T-4, T-5, T-6, T-7, T-9, T-10, T-11, T-12): 0.5 day.
- **Asset budget** (AS-1, AS-2, AS-3): 0.5 day.
- **Docs/ops finish-out** (D-3, D-4, O-3, O-4, O-5, O-6, I-1): 0.5 day.

**End-of-wave deliverable:** v1.3.0. Codebase ready to onboard external contributors.

### Wave 9.1c — "Polish backlog" (~2 dev-days)

All Tier 3 items. Run as background tickets, no fixed schedule.

---

## Constraints / conventions

- **No new Composer / no new framework.** The project principle is intentional. Any item that smells like "let's add Symfony X" is out of scope for #9.
- **No new code without tests.** Every Tier 1/2 fix that adds logic adds at least one unit or integration test.
- **One PR per Tier 1 group above.** Smaller PRs preferred for Tier 2 items (one architectural concern per PR).
- **Update `TODO.md`** as each wave completes: mark the wave done, link to the relevant tag.
- **Update this spec** if findings change during execution (cross out, append "DONE in commit X").

## Out of scope (deferred discussion)

- Adopting Composer (currently a project principle).
- Replacing hand-rolled test runner with PHPUnit/Pest (under threshold).
- Migrating to a proper framework (out of scope).
- Materialising `app_name`/`app_color`/`asset_url` into a single `AppContext` DTO (bigger than C-4 alone).
- API token usage write throttling (every request UPDATEs `last_used_at` — could sample at 10s intervals).

---

**Total work:** ~11 dev-days (3 + 5 + 3 across the three waves). Ready to start with Wave 9.1a on demand.
