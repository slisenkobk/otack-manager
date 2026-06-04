# Wave 9.1e — known follow-ups

Items surfaced during Wave 9.1e that did not block `v1.4.0` and are
deferred to a later cleanup pass.

## CSS housekeeping

Surfaced by the consolidated Task 2 review. Pure CSS file-shuffle and
class-collapse work — no view changes, no visual impact, no risk to
the v1.4.0 ship.

### 1. Re-house page-specific classes out of `utilities.css`

During Task 2's sweep ~310 new lines went into
`public/assets/css/utilities.css`, of which ~250 lines are
page-/feature-specific classes that belong in `cards-panels.css` per
the established convention ("utilities are cross-cutting; per-feature
classes belong in the matching stylesheet").

Affected class families (all currently in `utilities.css`):

- `.compass-*` — compass cards/logs/migrations widgets
- `.db-migrate-*` — `/admin/compass/db-migrate` page
- `.updates-*` — `/admin/compass/updates` tab
- `.poll-show__*` — `/polls/{id}` page
- `.tag-group*` — `/admin/tags` page
- `.submission-*`, `.tags-search` — submission editor + tags listing
- `.profile-card__*`, `.user-actions` — `/users/{id}` page
- `.link-card__*`, `.link-danger__*` — `/links/*`
- `.attach-upload*`, `.member-*` — partials
- `.activity-load-more`, `.my-task-card__*`, `.dashboard-*` —
  `/dashboard`
- `.compass-meta-strong*`, `.compass-status-badge*`,
  `.compass-last-migration-*` — compass cells/badges
- `.compass-level-select`, `.compass-logs-empty`,
  `.compass-logs-filter-form`, `.compass-logs-head`,
  `.compass-logs-toolbar`, `.compass-cards-grid`

After the move, `utilities.css` should shrink by ~250 lines and
return to its cross-cutting role. No view file changes — the class
names stay the same, only the source file changes.

### 2. Collapse single-property classes into utilities or `inline_style()`

Six to eight classes added during Task 2 carry exactly one CSS
property and exactly one call site. They could either be replaced by
an existing utility (e.g. `mt-12`, `m-0`, `fz-15`) or `inline_style()`
from Task 1:

- `.updates-current-line { margin:0; font-size:18px; }` →
  `class="m-0 fz-18"`
- `.updates-intro { margin:0 0 8px; font-size:15px; }` →
  `class="mb-8 fz-15"`
- `.poll-show__url { margin-top:12px; }` → `class="mt-12"`
- `.compass-level-select { min-width:160px; }` →
  `inline_style('min-width:160px')`
- `.db-migrate-plan { margin-bottom:18px; }` →
  `inline_style('margin-bottom:18px')`
- `.db-migrate-run-result { margin-top:18px; display:none; }` →
  `inline_style('margin-top:18px;display:none')` (or keep — the
  `display:none` initial state may be preferable as a class)

Optional: audit `.compass-meta-strong` vs
`.compass-meta-strong--accent` (differ only by one color property) —
consider whether the modifier pattern is the right shape vs a single
class + a state-toggle.

### When

Pick up in a polish wave or whenever a maintainer next touches one of
these files. Total effort: ~30-60 min. No tests should change.

## Local WebKit on macOS 14 arm64

Playwright ships a frozen WebKit build for macOS 14 arm64
(`webkit_mac14_arm64_special-2251`). It downloads via `npx playwright
install webkit`, the binary launches (a pid is reported), but the
inspector-pipe handshake never completes, so the test runner times
out at the 180 s launch budget. This is a known compatibility issue
with the frozen build, **not** a code or CSP bug.

Workarounds:
- Run cross-browser specs in CI only — CI runs on Ubuntu where the
  Linux WebKit build works correctly (`unit-tests.yml` e2e job
  installs all three browsers).
- Or skip the webkit project locally with
  `npx playwright test --project=chromium --project=firefox`.
- Or upgrade the host macOS so a non-frozen WebKit build is shipped.

The `playwright.config.ts` projects array stays — local devs on
non-frozen platforms (Linux, future macOS) get the full matrix; macOS
14 arm64 users can skip webkit at the invocation level.
