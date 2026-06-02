# In-app updater (TODO #3)

Detailed design for the GitHub-driven self-update mechanism: how the
running instance discovers a newer release, applies it without losing
user data, records what it did, and lets the admin roll back if needed.

This doc is the spec. The feature is **shipped** as of 2026-06-02
(steps 1-7 in §15 all landed across commits on `main`). When new
behaviour ships, keep this file in sync — anything in the code that
diverges from here is either a bug or a deliberate scope change that
should be reflected back here.

**What's live**

| Step | Subject                                                    | Status   |
|------|------------------------------------------------------------|----------|
| 1    | `app_versions` / `app_backups` tables, `APP_VERSION` const | shipped  |
| 2    | `GET /api/updates/check`, topbar badge, Updates tab skel   | shipped  |
| 3    | History + Backups tables (read-only at first)              | shipped  |
| 4    | Update pipeline (download/swap/migrate/rollback) + `bin/self-update.php` | shipped  |
| 5    | Restore pipeline + Restore button                          | shipped  |
| 6    | Retention sweep + drift reconciliation                     | shipped  |
| 7    | Docs sync (this section)                                   | in commit|

---

## 1. Goals

- One-click safe upgrade from any released `vX.Y.Z` tag to the latest
  newer tag on the same public GitHub repo.
- Zero downtime for user data: `data/`, `public/uploads/`, and `.env`
  never get touched by the updater.
- Visible: a tag/badge appears next to the "Dashboard" title in the
  topbar as soon as a newer version is detected; clicking it drops the
  admin straight into the Updates tab in settings.
- Recoverable: every update produces a backup the admin can restore
  from — both code and DB.
- Auditable: a history of installed versions is persisted, never
  silently overwritten.

## 2. Non-goals (v1)

- Branch-based updates (only released semver tags).
- Plugins / partial updates of individual files.
- Background workers / queued updates. The update is a single
  synchronous HTTP request from the admin; the page reloads when done.
- Automatic / unattended updates. The admin always clicks "Update now".
- Update channels (stable / beta). One repo, one tag list.

## 3. Versioning

- Releases are git tags of the form `vMAJOR.MINOR.PATCH` (strict semver,
  no pre-release suffixes in v1).
- The locally installed version lives in a single source-of-truth file
  `system/version.php`, exposing `const APP_VERSION = '1.0.0';`.
- Bumping the version is part of the release commit. The same commit
  gets the matching tag pushed to `origin`.
- Comparison is semver-aware (`version_compare($a, $b, '<')`). A tag
  whose semver is **strictly greater** than `APP_VERSION` is offered as
  an update; equal or lower never is.

The v1.0.0 tag is already on origin (`a12243b`); subsequent releases
follow the same convention.

## 4. Update strategy: full file replacement, not diff

The updater replaces **every shipped file**, not just the ones that
changed between the current version and the target. The pipeline (§10)
downloads the entire tag tarball and `rename()`s every staged file over
its counterpart in `APP_ROOT`. The release manifest is consulted only
to detect **removals** — files that existed in the old release but no
longer appear in the new one — which are moved to `{workdir}/removed/`.

### Why full, not diff

| Property                        | Full replacement (chosen)        | Diff-based                                       |
|---------------------------------|-----------------------------------|--------------------------------------------------|
| Code complexity                 | low                               | medium-high                                      |
| Outcome determinism             | exact byte-for-byte match         | depends on hash + apply order                    |
| I/O at our scale (~few MB code) | milliseconds                      | marginally faster                                |
| Catches local hand-edits        | yes — overwrites them             | no — would detect and skip (extra logic)         |
| Manifest needs                  | yes, for deletions only           | yes, for every file                              |

At this project's scale the cost difference is invisible (rename of a
few hundred small files completes in milliseconds on any modern disk),
so the simplicity payoff dominates. Eliminating ad-hoc local edits
during update is also the right default for a self-hosted appliance —
modifications to `system/` or `views/` aren't a supported workflow, and
a deterministic post-update state is easier to debug than "depends on
what you touched".

### When this decision should be revisited

- The codebase grows past ~50 MB and updates feel slow over typical
  SMB/home connections (currently ~3-5 MB; nowhere close).
- A plugin mechanism is introduced — plugin directories go into the
  ignore-list, the core stays under full-replacement.
- Operators are sanctioned to customise system files between releases.
  This is a product-direction shift away from "appliance" and would
  warrant a redesign, not just a strategy tweak.

### What is NOT replaced

The ignore-list lives in `Updater.php`. This doc mirrors it for
readability; if the two diverge, code wins and this section is wrong.
See §10 step 3 (snapshot) and step 7 (apply) for canonical use.

- `data/` — SQLite DB, sessions, logs, the updater's own backup directory
- `public/uploads/` — user-uploaded files
- `.env`
- `node_modules/`, `.git/`, `test-results/`
- Anything matched by `.gitignore` (best-effort, parsed once at startup)

## 5. Data model

Two new tables, both small. Migration: `20260603_030_updater.php`.

```sql
CREATE TABLE app_versions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    version      TEXT NOT NULL,
    installed_at TEXT NOT NULL,
    -- 'install' (first boot), 'update' (came from GitHub), 'restore' (rolled back)
    source       TEXT NOT NULL,
    -- The `users.id` that triggered it. NULL for 'install' (no admin yet).
    applied_by   INTEGER NULL,
    -- Free-text release notes pasted from GitHub release body, max 16 KB.
    notes        TEXT NULL
);
CREATE INDEX idx_app_versions_installed_at ON app_versions(installed_at);

CREATE TABLE app_backups (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    -- Both versions are recorded so the UI can label backups clearly:
    -- "Backup made on 2026-06-15 14:22, before upgrading 1.0.2 → 1.0.3".
    version_from    TEXT NOT NULL,
    version_to      TEXT NOT NULL,
    created_at      TEXT NOT NULL,
    -- Filesystem paths relative to APP_ROOT. NULL means the entry refers
    -- to an in-place backup folder rather than a snapshot file.
    code_path       TEXT NOT NULL,    -- e.g. data/backups/2026-06-15_140020/code/
    db_snapshot     TEXT NULL,        -- e.g. data/backups/2026-06-15_140020/app.sqlite
    size_bytes      INTEGER NOT NULL,
    -- 'auto' (created by an update) vs 'manual' (created by a future
    -- "create backup now" button — out of scope for v1 but column reserved).
    kind            TEXT NOT NULL DEFAULT 'auto',
    -- Timestamp at which a retention sweep removed the on-disk artefacts.
    -- NULL while the backup is still usable. Rows are kept after pruning
    -- so the admin still sees the historical record.
    pruned_at       TEXT NULL
);
CREATE INDEX idx_app_backups_created_at ON app_backups(created_at);
```

A few settings keys (in the existing `settings` table) drive the
runtime behaviour:

| Key                            | Purpose                                                |
|--------------------------------|--------------------------------------------------------|
| `available_version`            | Latest tag seen on GitHub, cached                      |
| `available_check_at`           | Unix-ms timestamp of the cached check                  |
| `available_notes`              | Release notes body of `available_version` (if any)    |
| `last_update_duration_seconds` | Most recent update wall-clock duration                 |

These are owned by the updater; nothing else writes to them.

## 6. Configuration (env)

| Variable                | Default                                  | What it does                                                                                                       |
|-------------------------|------------------------------------------|--------------------------------------------------------------------------------------------------------------------|
| `UPDATE_REPO_URL`       | `https://github.com/slisenkobk/otack-manager` | Public GitHub repo to check tags against. Allows forking / mirroring.                                              |
| `UPDATE_CHECK_INTERVAL` | `3600`                                   | Seconds between auto-checks. Set to `0` to disable auto-check (admin can still hit "Check now" in settings).      |
| `UPDATE_BACKUP_KEEP`    | `5`                                      | How many on-disk backups to retain. Older ones get pruned (artefacts removed, `app_backups.pruned_at` set).        |
| `UPDATE_ENABLED`        | `true`                                   | Master switch. `false` hides the badge, the settings tab actions, and disables all endpoints. For locked-down installs that ship updates via OS package manager.|

All four are optional. The defaults match the most common self-hosted
shape.

## 7. UI surfaces

### 7.1 Topbar badge (visible everywhere)

`views/partials/topbar.php` renders a small clickable tag next to the
crumb when `available_version > APP_VERSION`:

```
[Dashboard]  [v1.0.3 available →]
```

- Markup: anchor `<a class="update-badge" href="/admin/settings?tab=updates">…</a>`.
- Style: subtle accent (matches existing topbar tone, not loud red).
- Hidden when `available_version` is empty, equal to current, or older.
- Hidden when the user is not admin.
- Hidden when `UPDATE_ENABLED=false`.

### 7.2 Settings → Updates tab

New tab in `views/admin/settings.php`, added **after Contact Info** in
the tab strip. Sections from top to bottom:

1. **Current version** — a single line showing `Otack Manager v1.0.0`
   plus the date of `installed_at` of the most recent `app_versions`
   row (last update or first install).
2. **Check for updates** — last check timestamp, "Check now" button.
   The button hits `GET /api/updates/check` (admin-only) and refreshes
   in place. Shows a spinner while the request is in flight.
3. **Update available** (conditional, only when `available_version >
   APP_VERSION`) — version number, release notes (rendered via the
   existing `Markdown::render` if the GitHub release body is Markdown),
   "Update now" button → opens a confirmation modal:
   > Updating from v1.0.0 to v1.0.3
   >
   > Estimated downtime: 15-45 seconds (last update on this instance
   > took N seconds). Your data, uploads and settings will NOT be
   > touched.
   >
   > A backup will be created automatically so you can roll back if
   > needed. [Cancel] [Confirm update]
   On confirm: POST `/admin/updates/run` (no JSON body); the request
   blocks until the update finishes, then redirects to the same page
   with a success/error flash.
4. **History** — table of `app_versions` rows: version, source,
   when, who. Up to 20 most recent, with "Show all" toggling visibility
   beyond 20. Read-only.
5. **Backups** — table of `app_backups` rows: version_from →
   version_to, when, size, kind (auto / manual), action (`Restore`
   button when `pruned_at IS NULL`, otherwise grey "pruned" label).
   Restore opens a hard-warning modal:
   > Restore from backup will revert the app code AND database to the
   > state right before v1.0.0 → v1.0.3.
   >
   > Anything that happened in the database since (new tasks, comments,
   > votes, form submissions) will be LOST.
   >
   > Estimated downtime: similar to an update.
   > [Cancel] [I understand — restore]

All confirmation modals reuse `UI.confirm` with `danger: true` for the
destructive ones (restore especially).

### 7.3 i18n

Catalog keys land under `updates.*` and `nav.updates_*`. Same lockstep
rule as polls — add to en/pl/uk in the same commit.

## 8. Code structure

```
system/
├── version.php                      # APP_VERSION constant (single source of truth)
├── Service/
│   └── Updater.php                  # Discovery, download, swap, restore orchestration
├── Repository/
│   ├── AppVersionRepository.php     # log() / list() / current()
│   └── AppBackupRepository.php      # create() / list() / get() / markPruned()
└── Controller/
    └── UpdatesController.php        # Settings tab + endpoints

bin/
└── self-update.php                  # Standalone runner used by Updater (see §10)

data/backups/                        # Generated; gitignored; created on first update
```

The Compass module already lives in `system/Service/CompassService.php`
— `Updater.php` mirrors its style (single service, lean public surface,
delegates DB to a repo).

## 9. Endpoints

| Method + path                              | Who      | Purpose                                                       |
|--------------------------------------------|----------|---------------------------------------------------------------|
| `GET  /api/updates/check`                  | admin    | Hit GitHub API, refresh cached `available_version`, return JSON `{current, available, has_update, notes, checked_at}` |
| `POST /admin/updates/run`                  | admin    | Runs the update pipeline (§10). Redirects on completion.      |
| `POST /admin/updates/restore/{backup_id}`  | admin    | Runs the restore pipeline (§11). Redirects on completion.     |
| `GET  /admin/settings?tab=updates`         | admin    | Renders the tab.                                              |

The two POST endpoints are CSRF-protected (existing rule covers any
non-public POST).

## 10. Update pipeline

The pipeline is one HTTP request, executed synchronously. Stages:

1. **Validate** — confirm `available_version > APP_VERSION` (re-check
   live, not from cache; cache may be stale). If equal/lower → 409
   "Already up to date".
2. **Allocate workdir** — `data/backups/{timestamp}/`. Create
   `code/`, leave room for `app.sqlite` snapshot at sibling path.
3. **Snapshot code** — copy every file under `APP_ROOT` to
   `{workdir}/code/`, **excluding** (the ignore-list referred to in §4
   above):
   - `data/`
   - `public/uploads/`
   - `node_modules/`
   - `test-results/`
   - `.git/`
   - Anything in `.gitignore` (best-effort — parse `.gitignore` once at startup)
4. **Snapshot DB** — `cp data/app.sqlite {workdir}/app.sqlite`. Use
   SQLite's `.backup` command if available, else a raw `cp` while
   holding a `BEGIN IMMEDIATE` lock. SQLite read-replicating during the
   copy is well-defined.
5. **Download tarball** —
   `https://github.com/{owner}/{repo}/archive/refs/tags/v{ver}.tar.gz`.
   Save to `{workdir}/incoming.tar.gz`. Verify GitHub's reported
   `Content-Length`. (No GPG signing in v1; rely on HTTPS + GitHub-as-trust.)
6. **Extract** — `tar -xzf incoming.tar.gz -C {workdir}/staging/`. The
   tar contains a single directory `otack-manager-{ver}/`; that
   directory's contents become the new code root.
7. **Apply** — atomic-ish swap. For each path in the staging
   directory (except the ignore-list from step 3 above), `rename()`
   from `{staging}/{path}` to `{APP_ROOT}/{path}`. `rename()` is atomic
   per-file on the same filesystem.
   - Files removed in the new version: tracked via a manifest. Each
     release includes a `MANIFEST` file at the repo root listing every
     file that should exist post-extract. Anything in `APP_ROOT` not in
     the manifest (and not in the ignore-list) is moved to
     `{workdir}/removed/`.
8. **Persist version** — write `system/version.php` from the new
   release (it's part of the tarball, so this is implicit after step 7
   — but verify the constant matches the requested tag, fail loud if
   not).
9. **Migrate** — run `php bin/migrate.php` in-process. Failures here
   roll back to the snapshot (§10.1) and surface the migration error to
   the admin.
10. **Record** — insert `app_versions` (source='update', applied_by) +
    `app_backups` rows. Set `settings.last_update_duration_seconds`.
11. **Prune** — if `app_backups WHERE pruned_at IS NULL` count >
    `UPDATE_BACKUP_KEEP`, remove on-disk artefacts of the oldest excess
    backups and mark `pruned_at`.
12. **Done** — redirect with success flash; OPcache invalidates itself
    on the next request (most PHP setups), so the new code is live
    immediately.

### 10.1 Rollback on failure

Any step from 5 onward that fails triggers an automatic rollback:

- Step 5 / 6 / 7 failure → restore from `{workdir}/code/` and
  `{workdir}/app.sqlite` (§11 logic).
- Step 9 failure (migrations) → same as above.
- A backup row is still inserted with a status note so the admin sees
  the attempt and can investigate.

### 10.2 Self-update gotcha

The PHP process running the update is replacing its own source files.
Two mitigations:

1. **Critical paths cached upfront.** The `Updater` service `require`s
   every file it might need *before* step 7. After that point only
   standard-library calls and already-loaded application code run.
2. **Atomic per-file `rename()`.** A partially-applied state on the
   filesystem cannot happen for a single file. The window where the
   project is inconsistent is the time between renames of related
   files — typically milliseconds — during which the in-memory PHP
   process keeps using its cached copies.

A separate `bin/self-update.php` runner exists as belt-and-braces — if
later experience shows the in-process update is too fragile, the
controller can `exec()` the standalone runner and exit immediately, and
the next request runs against the new code. The runner uses only
PHP-standard-library imports.

## 11. Restore pipeline

Mirror of update, played backwards.

1. **Validate** — `backup_id` exists, `pruned_at IS NULL`, code/DB
   paths still on disk.
2. **Snapshot current** — same as §10 steps 3-4, into a new
   `app_backups` row with source 'restore'. Reason: a botched restore
   should also be recoverable.
3. **Swap code** — atomic rename from backup snapshot dir back into
   `APP_ROOT`, removing files that exist now but didn't in the backup.
4. **Swap DB** — `cp` (or SQLite `.backup`) the backup snapshot over
   `data/app.sqlite`. Open connections in other processes get
   invalidated when SQLite sees the file change; the next request
   reconnects.
5. **Record** — insert `app_versions(source='restore')`.
6. **Done** — redirect, flash success.

A restore does NOT run migrations: the schema is whatever the snapshot
had, and the code being restored matches it.

## 12. Backup retention

- Driver: `UPDATE_BACKUP_KEEP` env (default 5).
- Sweep timing: at the tail of every successful update (§10 step 11),
  and as a fallback inside `GET /api/updates/check` if the count drifts
  (e.g. someone manually deleted files).
- Sweep policy: keep the N newest by `created_at`, prune the rest.
  Pruning = `rm -rf` the artefact directory + `UPDATE app_backups SET
  pruned_at = now() WHERE id = ?`. The `app_backups` row stays so the
  history is preserved.
- A backup created by a restore is treated identically to one created
  by an update for retention purposes — both are "pre-change" snapshots.

## 13. Migration discipline (refer to MIGRATIONS.md)

The updater runs whatever migrations a new release brings, on live
production data. The "Data preservation rule" in
[MIGRATIONS.md](MIGRATIONS.md#-data-preservation-rule-non-negotiable)
governs what those migrations may and may not do. The two-doc cross-
reference is intentional — the rule is a property of the migration
system, but the updater is the engine that makes the rule
load-bearing.

In one sentence: **a release that ships a migration which drops or
overwrites a user-data column is a release that should never be cut.**

## 14. Failure modes worth designing for

| Failure                                | Detect                                                       | Recover                                                              |
|----------------------------------------|--------------------------------------------------------------|----------------------------------------------------------------------|
| GitHub API rate-limited                | HTTP 403 with `X-RateLimit-Remaining: 0`                     | Show "rate-limited, try again in N min" in the badge tooltip         |
| GitHub returns 404 for repo            | HTTP 404                                                     | Show "configured repo not found" in settings; disable update button  |
| Tag exists but tarball doesn't yet     | HTTP 404 on tarball download                                 | Surface clearly: "release tag found but archive not yet published"   |
| Disk full mid-extract                  | `tar` non-zero exit, fwrite returns false                    | Abort, rollback (§10.1), keep workdir for admin inspection           |
| `rename()` cross-device                | EXDEV from PHP                                               | Fall back to copy+unlink; log a warning so this can be investigated  |
| Migration fails partway                | Migration throws                                             | Rollback (§10.1) — DB snapshot guarantees consistency                |
| Backup directory missing on restore    | `is_dir()` false                                             | 410 Gone, mark `pruned_at` if not already, tell admin                |
| Self-update breaks current request     | uncatchable (process dies)                                   | Next request runs new code; if THAT fails, admin uses `bin/self-update.php --restore <id>` from shell |

## 15. Implementation sequencing

Suggested commits, one per layer, with self-verify before commit:

1. **Migration + repos + version constant + unit tests.**
   - Tables `app_versions`, `app_backups`.
   - `AppVersionRepository`, `AppBackupRepository`.
   - `system/version.php` with `APP_VERSION='1.0.0'`.
   - Unit tests: log/list/retention math.

2. **Check-for-updates + topbar badge.**
   - `UpdatesController::check`.
   - GitHub API call with timeout + caching in `settings`.
   - Topbar badge partial.
   - Settings → Updates tab skeleton (Current + Check-for-updates sections only).
   - i18n keys 1/3.

3. **Settings tab — full UI.**
   - History table, Backups table (read-only at this stage).
   - i18n keys 2/3.

4. **Update pipeline (steps 1-11 from §10).**
   - `Updater::update($targetVersion, $actorUserId)`.
   - `bin/self-update.php` belt-and-braces runner.
   - Manifest tracking.
   - End-to-end happy-path e2e test that creates a fake remote tag,
     points `UPDATE_REPO_URL` at a local fixture, and runs the full
     pipeline.

5. **Restore pipeline.**
   - `Updater::restore($backupId, $actorUserId)`.
   - Restore action in Backups table.
   - e2e test: update v1.0.0 → v1.0.1 → restore back to v1.0.0,
     assert DB and code match the original.

6. **Retention + edge-case polish.**
   - Backup pruning sweep.
   - All §14 failure modes with at least toast/log surfacing.
   - i18n keys 3/3.

7. **Docs sync.**
   - Update README + CLAUDE.md to mention the updater.
   - Update this file with whatever drifted during implementation.

Each step is independently shippable. Step 1-3 alone deliver value (the
admin can see when there's a new version) even before steps 4-5 are
written.

## 16. Open questions

- **Release notes source.** GitHub releases (`/releases/latest`) carry
  a body separate from the tag. v1 plan above uses release body if a
  release exists for the tag; falls back to tag message otherwise.
  Decide which.
- **Should the topbar badge be dismissible per-user?** Adds a row to
  `settings` per user or a session flag. Default: no, the badge is
  small enough to live with permanently until updated.
- **Should `check` happen automatically on dashboard load or only on
  user action?** Plan above: every dashboard hit calls
  `UpdatesController::check` if cache is older than
  `UPDATE_CHECK_INTERVAL`. Decide whether this is the right cadence.
- **GitHub API auth.** Anonymous is 60 req/h shared across the host's
  outbound IP. For self-hosted single-instance deployments that's
  ample. If multiple instances share an IP (e.g. behind a NAT) and we
  see rate-limit hits, allow an optional `GITHUB_TOKEN` env var.
