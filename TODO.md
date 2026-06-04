# TODO

Open and completed product items. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
for the codebase map and [.dev-notes/superpowers/specs/](.dev-notes/superpowers/specs/)
for the audit-driven cleanup plan referenced in `#9` below.

## Done

- **#1 — MySQL support + configurable DB.** Driver abstraction (`DriverInterface`
  + `SqliteDriver`/`MysqlDriver`), Schema DSL for migrations, all 36 migrations
  ported to the DSL, repos and snapshot layer portable, CI matrix sqlite +
  mysql 8.0, convention test guarding regressions. See
  [docs/DATABASE.md](docs/DATABASE.md).
- **#2 — Public API for third-party systems.** Mirrors the workspace surface
  for Manager/Employee accounts, per-user API tokens that can be revoked or
  rotated; designed so future MCP bridges (e.g. Claude) can plug in. 47
  endpoints, 84 integration tests; MCP bridge is phase 2. See
  [docs/API.md](docs/API.md), [docs/openapi.yaml](docs/openapi.yaml),
  `/profile/tokens`. Post-review fixes 2026-06-03: atomic rate-limiter,
  401-not-403 leaks, `setPin` auth, unbiased token gen, voters cursor
  contract, method-aware drift check.
- **#3 — App versioning + one-click update from a GitHub main branch tag.**
  Updates every file except user data (storage / DB / uploads). Settings tab
  with check-on-dashboard banner, public GitHub source URL configurable via
  env. See [docs/UPDATES.md](docs/UPDATES.md): steps 1–7 in `main`, topbar
  badge, Settings → Updates with history, backups and Restore, automatic
  snapshot + rollback on error, retention via `UPDATE_BACKUP_KEEP`.
- **#4 — Mobile audit of the entire workspace.** All breakpoints, spacing,
  affordances and element order verified via Playwright.
- **#5 — "Remember me" checkbox on login.** Checked: keep the session for 30
  days. Unchecked: 24 hours.
- **#6 — Polls module built on the Forms engine.** Same builder mechanic; the
  public page collects a contact field before letting the user vote.
  A poll attaches to a project (it cannot spawn a new one) and only once
  closed can it be converted into a project task with the result summary.
  Single sidebar section (no separate "responses"/"voters" entries); voters
  list lives inside an open poll's admin view. Once Active, a poll is
  read-only — admin sees response stats; while Draft, the builder shows
  instead of stats. A "Voters" tab beside it shows emails/phone (contact
  field is configurable). Functionally a Forms builder with pre-seeded
  Email + Radio (answer options) fields.
- **#7 — Sidebar "Integrations" submenu.** Houses Forms, Polls and Links as
  one collapsible group.
- **#8 — Short Links.** A proxy-style link service like classic Google links:
  redirects to a target URL and records click stats (unique visits + total
  clicks).
- **#11 — SQLite → MySQL migrator.** For projects that outgrow SQLite, an
  admin (Compass → Migrate to MySQL) can move the dataset over: connection
  test, plan with row counts, synchronous copy in batches of 500, AUTO_INCREMENT
  reset, sanity check, verify endpoint after the `.env` edit. The SQLite
  file is never touched — rollback = revert `.env`.
- **#9 — Refactor and code-quality pass for productisation.** Closed
  2026-06-04 with tag `v1.4.0`. Driven by the 2026-06-03 full audit
  (73 findings across backend / frontend / tests-docs-ops parallel
  reviews — see [.dev-notes/superpowers/specs/2026-06-03-todo-9-audit-and-cleanup-plan.md](.dev-notes/superpowers/specs/2026-06-03-todo-9-audit-and-cleanup-plan.md)).
  Shipped across 7 release tags:
  - `v1.2.0` — 9.1a (must-fix: security S-1/S-2/S-4, a11y V-1/V-2,
    packaging O-1/O-2, CI gaps, JS i18n, silent catches, SECURITY/
    DEPLOYMENT docs). See [.dev-notes/superpowers/follow-ups/wave-9-1a.md](.dev-notes/superpowers/follow-ups/wave-9-1a.md).
  - `v1.2.1` — hot-fix for CSP additive nonce that broke inline styles.
  - `v1.3.0` — 9.1b (should-fix: architecture cleanup). Split `index.php`
    → `Bootstrap/Container,Events,Routes`; DI re-entry guard; ApiKernel
    regex routes; 22 controllers to constructor-injection via Factory;
    Validator + Log services; Repository `@return` annotations;
    ActivityLog assoc-array; HtmlSanitizer hard-requires ext-dom;
    `APP_SECRET` split; Updater cleanup logging; CSP nonce prep; kanban.js
    → 3 modules; `ui-fields.js` + `FormBuilder` class; `app.css` → 8
    layered files; `.btn--variant` BEM; modal focus trap;
    `withButtonBusy`; inline field errors; mobile breakpoints; test
    fill-in (+27 unit, +10 e2e); −577 KB assets (FA brands dropped,
    Quill lazy-loaded, fonts preloaded); `.dev-notes/` relocation;
    `.env.example` parity test; `package.json` MIT; `errors.log` size
    cap + `activity_log` prune; `bin/check-env.php`; 64 unused i18n
    keys pruned.
  - `v1.3.1` — hot-fix for the nonce-only CSP attempt (reverted; nonce
    added additively would silently disable `'unsafe-inline'`).
  - `v1.3.2` — 9.1c (nice-to-have: polish). asset_version DI memo (C-4);
    MySQL `GET_LOCK` migration lock (A-6); `SmokeController::hello`
    removed (K-3); 5 `__otack*Init` guards dropped (J-5);
    `task-page.js` IIFE-wrapped (J-9); ui.js split (J-6); dark theme
    dedup via `theme-init.js` (CSS-6); 0-count `Projects` badge hidden
    (V-7); fieldset/legend on admin/settings tabs (V-9); brand_tag
    i18n exemption documented (I-2); admin-configurable
    `project_palette` (I-4); Sortable lazy-loaded (-44 KB, AS-4);
    dark-theme persistence e2e + auto-emulation tests (T-8); `TODO.md`
    English (D-5); `INTEGRATION-CHECKLIST.md` polish (D-6);
    `docs/TESTING.md` created (O-7); `make test-clean` auto-clear
    (CL-2); `reset-test` covers api-test sqlite + WAL/SHM (CL-3);
    CSS-4 `!important` audit deferred behind CSS-5 (see
    [.dev-notes/superpowers/follow-ups/wave-9-1c.md](.dev-notes/superpowers/follow-ups/wave-9-1c.md)).
  - `v1.3.3` — 9.1d blockers + carry-forward debt. CSS-5 first pass
    355 → 140 inline styles; CSS-4 first prune; 6 silent
    disabled-busy sites → withButtonBusy; A-6 lock-fail throws;
    Compass+lock error coverage.
  - `v1.4.0` — 9.1e: **S-6 closed.** `inline_style()` helper +
    request-scoped CSP nonce; ~127 static inline styles → semantic
    classes per page (CSS-5 finalisation, 6 sweep commits across
    admin-compass / admin-updates / tasks-projects /
    dashboard-auth-landing / polls-tags-forms /
    partials-users-profile-links-settings); 21 dynamic inline styles
    → `data-*` + `public/assets/js/dynamic-style.js` JS bridge with
    receiver classes; 3 cache.php ternary inline styles → conditional
    `cache-stat--ok/--alert` classes; CSP split per CSP3 into
    `style-src-elem 'self' 'nonce-X'` (rogue `<style>` injection
    blocked) + `style-src-attr 'unsafe-inline'` (JS DOM-style writes
    allowed — pragmatic close of the high-value half of S-6); CSS-4
    final prune (15 → 13 `!important` declarations); Playwright matrix
    expanded to chromium + webkit + firefox in config + CI; axe-core
    a11y sweep across 10 key routes, 102 → 0 wcag2a/wcag2aa
    violations (3 surgical CSS edits: `--ink-3` darkened in
    `tokens.css`, `.sidebar__foot-version` opacity removed,
    `.task-header__id` shifted to `--accent-hover`). Tests: 309
    unit / 85 api / 127 e2e (chromium) / 128 e2e (firefox).
    Follow-ups in [.dev-notes/superpowers/follow-ups/wave-9-1e.md](.dev-notes/superpowers/follow-ups/wave-9-1e.md).

  Out-of-scope items deferred to future waves with rationale:
  View typed DTOs (E-1), Composer adoption (O-3), Pest/PHPUnit
  migration (O-7 gated by test count), AppContext DTO,
  `JS-disabled + cookie=auto` regression (CSS-6 — acceptable trade-off
  documented in 9.1c follow-up).

## Open

### #10 — Setup wizard for new installs

A small `install` step on first boot that walks an admin through:
- Database choice (SQLite vs MySQL connection params)
- Admin user creation
- `LOGIN_HASH` setup (also storable / toggleable from DB, exposed in
  Platform settings)

Goal: zero-friction first run on a client's server.

---

## Historic checklist (2026-06-02 audit)

Superseded by `#9.2` above — each item's status is captured in the new spec
(fixed / still-applies / expanded). Kept for traceability:

### Architecture / structural debt
- Constructor injection in controllers: drop `App::make()` inside method
  bodies, take deps via `__construct` (~20 controllers,
  see `ProjectController:43-80`, `TaskController:47-61`).
- Extract event listeners from `public/index.php:92-165` (74 lines of inline
  closures) into a dedicated `system/Events/Listeners.php` or factory.
- View contracts: move away from `extract($data, EXTR_SKIP)` toward typed
  DTOs (or at least documented template contracts) — typos in a key
  silently disappear today.
- Centralise POST validation: a shared validator with error collection
  instead of scattered `trim()` + `if ('' === $x) return 422` (repeated in
  every POST action).
- Cache `asset_version` in memory at bootstrap (currently one SELECT per
  `asset_url()` call in `helpers.php:49-55`, ~12 DB hits per render).
- Structured logging: a single `ErrorLogger` service replacing scattered
  `error_log()` (EventBus, PublicFormController, PublicLinkController…).
- Repositories: add return types (`array|null`) on `findById` etc — needed
  for static analysis.
- DI container: an explicit boot phase with dependency-graph validation
  instead of the implicit registration order in `index.php:45-81`.

### Security (from the audit)
- HtmlSanitizer: require `DOMDocument` as a hard prerequisite OR sanitize
  on save (not on render) — closes the silent fall-back to `strip_tags` on
  task description (`views/tasks/show.php:44`).
- X-Forwarded-For: document the trusted-reverse-proxy requirement + env flag
  to disable XFF parsing (`PublicFormController:148-149`,
  `ShortLinkVisitRepository:65-71`); otherwise rate-limit and
  unique-visitor counters spoof.
- `SessionManager:16` — add an explicit `chmod($dir, 0700)` after `mkdir`
  (today the mode depends on umask).
- Split `FORM_ANTI_BOT_SECRET` away from `LOGIN_HASH` (with a fallback for
  backward compatibility).
- CSP `style-src 'unsafe-inline'` (`public/index.php:16`): move dynamic
  inline styles into CSS variables via data-attributes, then drop
  `unsafe-inline`.
- `LinkController:100` — `json_decode` silently returns `[]`; return `400`
  with a clear client-side error instead.
- Optional: a toggle that hides referrer columns in short-link stats.

### Frontend code quality
- `form-builder.js`: rewrite as a class (the TDZ comment on line 30 is a
  scar from a real bug).
- Split large modules by concern: `kanban.js` (652 LOC), `ui.js` (411),
  `form-builder.js` (316).
- Silent `catch {}` blocks: surface API errors via toast/banner (kanban
  lazy-load, form save, …).
- ARIA / focus management: a single pattern for modals and live regions
  driving async updates.
- `app.css` (4894 LOC): split into layers (palette → components → pages)
  the moment the single-file discipline starts to slip.

### Tests
- TelegramNotifier: unit test for the event → notification → HTTP POST
  chain.
- FileUploader: edge cases (size, MIME filter, directory-traversal
  guards).
- RolePolicy: complete permission matrix (`canCreateProject`,
  `canEditTask`, `canManageForms/Polls/Links`).
- API layer: a dedicated `test_api.php` for
  `/api/projects/{id}/columns/{id}/tasks` and friends.
- Markdown / HtmlSanitizer: complex nested structures, link and code edge
  cases.
- Dark-theme persistence: e2e test for toggle + reload.
- Mobile breakpoints: visual assertions at narrow viewports (beyond
  screenshots).
- Decide the fate of root `playwright.config.ts` (port 8000, stale) —
  delete it or merge into `tests/e2e/playwright.config.ts` (port 8001).
- Consider migrating off the hand-rolled test runner to Pest/PHPUnit if
  the codebase grows past 7–8 KLOC.

### Documentation (per `#9` "an md per entity")
- `docs/ARCHITECTURE.md`: request flow, DI/container, repositories,
  service layer, event bus.
- `docs/API.md`: public endpoint contracts (`/api/*`), error format.
- `docs/FRONTEND.md`: JS module map, API-interaction pattern, escaping,
  dark theme.
- `docs/TESTING.md`: hand-rolled runner internals, Playwright config, how
  to add a test.
- `docs/SECURITY.md`: threat model, CSP, CSRF, IP-hash dedup, anti-bot,
  role policy.
- `docs/DEPLOYMENT.md`: `make package`, `data/` permissions, required PHP
  extensions (DOMDocument, finfo, PDO sqlite).
- `TODO.md`: pick a single language (or carve strategic epics into a
  separate roadmap).

### Repo cleanup
- Remove `data.backup-pre-migrate-refactor/` (592 KB legacy backup in the
  repo).
- i18n parity: add `forms_data.brand_tag` to `system/i18n/pl.php` and
  `system/i18n/uk.php` (EN 478 keys, PL/UK 477).
- Commit the finished short-links feature (currently in working tree —
  95% complete, tested).
