# Wave 9.1c — known follow-ups

## STATUS: RESOLVED in Wave 9.1e (v1.4.0)

The CSS-5 inline-style sweep (`140 → 0 non-nonced`), CSS-4
`!important` finalisation (`13 left, all legitimate`), and the S-6
CSP `style-src` tightening (split into `style-src-elem 'nonce-X'` +
`style-src-attr 'unsafe-inline'`) all shipped in `v1.4.0`. TODO #9
is closed — see [TODO.md](../../../TODO.md#9-—-refactor-and-code-quality-pass-for-productisation).

Items audited or partially completed during the polish wave that were
intentionally deferred. Tracked here so they don't get lost.

## Wave 9.1d — partial closure of blockers (`v1.3.3`)

The follow-up wave that handled the CSS-5 + CSS-4 + silent-catch debt:

- **CSS-5 inline-style sweep:** 355 → 140 inline `style=""` attributes
  across `views/` (−60.6%). Four sed-driven passes introduced ~50 utility
  classes (`.fz-*`, `.mt-*`, `.field-hint`, `.section-label`,
  `.compass-card`, `.callout-card`, `.table-cell--*`, `.split-head`, etc.)
  in `utilities.css`. The 140 survivors split into:
   - 26 truly dynamic (`<?= … ?>` inside the style value — project / column
     / tag colors)
   - ~114 unique one-offs that each warrant a per-page semantic class
     rather than another utility
- **CSS-4 !important prune:** dropped 4 of 21 (one dead rule targeting
  inline `[style*="margin-top"]` which no longer exists; three redundant
  `text-decoration: none !important` on `.link-card` where specificity
  already wins).
- **17 silent disabled-busy sites → `withButtonBusy`:** 6 sites migrated
  (`updates-tab.js`, `dashboard.js`, `compass.js` × 2, `links-show.js` × 2);
  the remaining ~11 sites are inside `form.submit()` paths where re-enable
  in `finally` would be wrong (page navigates away).
- **S-6 CSP `'unsafe-inline'` removal:** **STILL DEFERRED.** The 140
  remaining inline styles would all need `nonce="…"` (and CSP would need
  `'nonce-X'`). Adding nonce to `style-src` SILENTLY DISABLES
  `'unsafe-inline'` (CSP spec) — confirmed by the v1.3.1 hot-fix incident.
  Path to closure is a dedicated **Wave 9.1e** scoped specifically to
  inline-style elimination:
   1. Migrate the ~114 unique one-off styles to per-page semantic classes
      (touch every view; ~3-4 hours).
   2. For the 26 dynamic styles, convert to CSS custom properties on the
      element via `data-*` + a small `theme-init`-style script, OR add a
      `csp_nonce()` helper to `style` attributes for the genuinely-needed
      ones (e.g. progress-bar `width: N%`).
   3. Drop `'unsafe-inline'` from `style-src` in `public/index.php`.
   4. Confirm via the `B12 no console errors` e2e — no CSP-violation
      reports.

## CSS-4 audit — `!important` declarations (deferred removal)

Inventoried 21 `!important` declarations across four CSS files. The plan
called for dropping the "now-redundant" subset after the **CSS-5 inline-style
sweep** lands. CSS-5 was on the Wave-9.1b shortlist but did not ship — see
`wave-9-1a.md` "S-6 CSP unsafe-inline removal". With ~348 `style="…"`
attributes still in views, most of the 21 hits remain load-bearing.

Concrete examples checked:

| Location | Verdict |
|----------|---------|
| `kanban.css:95,97` `.task-title.is-editable:hover/:focus` | **Keep.** Views still ship `<h1 class="task-title" style="border-bottom:1px dashed transparent;…">`; without `!important` the `:hover`/`:focus` colour swap loses to the inline `border-bottom` shorthand. |
| `kanban.css:307–316` `.kanban-card.is-dragging-*` | **Keep.** Fighting SortableJS's inline `style="transform/opacity/…"`. |
| `kanban.css:388, 768`, `utilities.css:113` `[hidden]` / `.hidden` | **Keep.** Standard hidden-utility pattern. |
| `cards-panels.css:539` `div[style*="margin-top"]` | **Keep.** Selector explicitly targets inline-styled siblings; rule is dead the day CSS-5 ships and can be deleted then. |
| `utilities.css:538–575` media-query overrides | **Keep.** Mobile responsive overrides of desktop-only properties. |
| `cards-panels.css:117` `.profile-avatar__pic { width/height/font-size … !important }` | **Keep.** Fighting baseline `.user-avatar` rule. Could be refactored once `.user-avatar--lg` exists. |

Action when CSS-5 ships: re-run `grep -n '!important' public/assets/css/*.css`
and drop any declaration whose neighbouring inline-style attribute is gone.
Estimated yield after CSS-5: ~6 of 21 declarations become removable.

## CSS-6 known regression — JS-disabled users with cookie=auto

The dark-theme dedup (commit `6c79e45`) dropped the
`@media (prefers-color-scheme: dark)` block from `tokens.css` and made
`public/assets/js/theme-init.js` the single source of truth. Consequence:
**a user with JavaScript disabled AND `theme=auto` (or no cookie) always
gets the light palette**, regardless of OS preference. Users with an
explicit `theme=dark`/`theme=light` cookie still get the right palette
server-side, so the regression is bounded to the small "no-cookie +
no-JS" intersection.

This is acceptable for an admin-facing tool but worth a docs/SECURITY.md
mention if we ever ship a public-facing surface that relies on the dark
auto-detect for an opted-in-but-no-JS reader-mode use case.

## Items also carried forward from earlier waves

See `wave-9-1a.md` for:
- CSS-5 inline-style sweep (blocks CSP `style-src 'unsafe-inline'` removal — S-6).
- 17 silent-catch sites pending `withButtonBusy` migration.

## Stats at end of Wave 9.1c

- Unit: 304 passed (unchanged — wave was zero-test-delta on the unit layer).
- API:  85 passed (unchanged).
- E2E:  +1 spec (`tests/e2e/theme.spec.ts`, 3 tests) — theme persistence + auto
  follows system pref (light/dark emulations).
- Commits on `polish/9-1c`: 18 (one per task that needed a commit; CSS-4 deferred
  to a docs-only commit, K-5 and K-4 closed without a commit per the plan).
- LOC delta:
  - `ui.js` 411 → 21 (façade) + `ui-modal.js` 162 + `ui-fetch.js` 21 + `ui-bootstrap.js` 250 = same surface, smaller files.
  - `task-page.js` re-indented under an IIFE (no LOC delta).
  - Removed `<script src="sortable.min.js">` from two views; loader moved into `utils.js::loadSortable()`.
  - `tokens.css` − ~50 LOC (dropped duplicate `@media (prefers-color-scheme: dark)` block).
- Tag at wave close: `v1.3.2`.

## Item-by-item outcome

| Task | ID  | Outcome |
|------|-----|---------|
| 1    | C-4 | Done — DI singleton `asset_version`. |
| 2    | A-6 | Done — `GET_LOCK('otack_migrations', 30)` on MySQL. |
| 3    | K-3 | Done — `SmokeController::hello` and unused `Csrf` dep removed. |
| 4    | K-5 | Verified — `node_modules/` already gitignored, no commit. |
| 5    | J-5 | Done — canonicalised ui.js/wysiwyg.js load URLs, dropped 5 guards. |
| 6    | J-6 | Done — `ui-modal.js` / `ui-fetch.js` / `ui-bootstrap.js` + façade. |
| 7    | J-9 | Done — IIFE wrap + `if (!sidebar) return;`. |
| 8    | CSS-4 | **Deferred** — blocks on CSS-5 inline-style sweep. See top of this file. |
| 9    | CSS-6 | Done — `theme-init.js` no-flash + single `[data-theme="dark"]` block. |
| 10   | V-7 | Done — `if ($projectsCount > 0)` wrap. |
| 11   | V-9 | Done — `.field-group`/`.field-group__legend` in admin/settings tabs. |
| 12   | I-2 | Done — inline comment on `forms_data.brand_tag`. |
| 13   | I-4 | Done — `project_palette` setting + `<meta name="project-palette">`. |
| 14   | AS-4 | Done — `loadSortable()` in utils.js, dropped 2 `<script src=…>` tags. |
| 15   | T-8 | Done — `tests/e2e/theme.spec.ts` (3 tests). |
| 16   | D-5 | Done — `TODO.md` translated to English. |
| 17   | D-6 | Done — idempotency + schema-poll + 401-alert bullets. |
| 18   | O-7 | Done — `docs/TESTING.md` created with cutover threshold section. |
| 19   | CL-2 | Done — `test-clean` Makefile target, wired into `make e2e`. |
| 20   | CL-3 | Done — `reset-test` now covers `app.api-test.sqlite*` + WAL/SHM. |
| 21   | K-4 | Verified — zero `error_log()` outside `Service/Log.php`, no commit. |
