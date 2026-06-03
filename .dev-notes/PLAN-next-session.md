# Plan — next session

Three tracks to ship in the next session, in order:

1. **Migrations refactor** — move from one growing `Migrations::run()` to per-file migrations in `system/Database/migrations/`, with a DB-backed marker table.
2. **Compass admin panel** — `/admin/compass` tab in settings: artisan-like surface for migrations / cache / DB stats.
3. **i18n** — English (default) + Polish, language switcher in user settings.

Each track is independent — a deploy after any one is safe.

---

## Track 1 — Migrations refactor

### Current state
- `system/Database/Migrations.php` (~360 lines) holds every `$boot->ensure('key', version, fn)` call.
- `SchemaBootstrap::ensure()` writes a marker file `data/.schema/<key>.<version>` after each run.
- Runs on every HTTP hit from `public/index.php:52`.

### Target state
- Per-file migrations in `system/Database/migrations/`:
  ```
  20260101_000_users.php
  20260101_010_projects.php
  ...
  20260529_010_form_submissions_remote_ip.php
  ```
  Each file returns a single closure `function (\PDO $pdo): void { ... }`.
- New `schema_migrations(name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)` table — replaces filesystem markers.
- `Migrations::run()` discovers files via `glob()`, sorts alphabetically, runs each within a `BEGIN IMMEDIATE` transaction, marks the row, commits.
- Re-runs are no-ops because `schema_migrations` already has the name.
- New `make migrate` Makefile target = `php bin/migrate.php` runner that exits with code 0/1.
- Bootstrap from `public/index.php` keeps the auto-run behaviour, but also gains a `try/catch` that falls back to a "Schema is out of date" error page if a migration throws (instead of half-applying).

### Steps
1. Add `system/Database/migrations/` dir + `0000_schema_migrations.php` (creates the bookkeeping table).
2. Split current `Migrations.php::run()` into one file per `ensure()` block. Naming: `YYYYMMDD_NNN_<key>.php`. Use git history to date each.
3. Rewrite `SchemaBootstrap::ensure()` → `runFile($path)` that reads the table.
4. Update `Migrations::run()` to scan + run sequentially in transaction.
5. Add `bin/migrate.php` CLI + Makefile entry.
6. Backfill the new table with the existing markers' contents on first run (so production doesn't re-apply everything).
7. Delete `data/.schema/` marker directory.
8. Update `README.md` migration section.

### Risk + mitigation
- **Race on first prod hit**: wrap whole run in `BEGIN IMMEDIATE` so concurrent requests serialise.
- **Marker backfill**: read existing files in `data/.schema/` once, insert into `schema_migrations`, then never look at the directory again. Idempotent so safe to run on multiple boxes.
- **Naming-key change** (e.g. renaming `users_role_employee_manager` → `users_roles_v2`): forbidden — would rerun the migration. Document in `docs/MIGRATIONS.md`: once a file ships, its filename is permanent.

### Estimate
~3 hours. Most of it is the split (~25 migrations × ~3 min each).

---

## Track 2 — Compass admin panel

### Concept
Voyager-style "Compass" tab — admin-only `/admin/compass`. Has 4 sub-tabs:

1. **Migrations** — list with status (applied / pending), "Run pending" button, "View source" link.
2. **Cache** — counts and "Clear" actions for:
   - `data/sessions/` (PHP session files older than X)
   - `public/uploads/` orphans (attachments deleted in DB but still on disk)
   - Browser HTTP cache buster (bumps a `?v=` query for CSS/JS by writing a build timestamp setting)
3. **DB stats** — row counts per table, DB file size, last migration timestamp.
4. **Logs** — tail of `data/errors.log` (last 100 lines, line filter by level).

### Steps
1. New `system/Controller/CompassController.php` — admin-only via `AuthGuard::requireAdmin`.
2. Routes:
   ```
   GET  /admin/compass
   GET  /admin/compass/migrations
   POST /admin/compass/migrations/run
   GET  /admin/compass/cache
   POST /admin/compass/cache/sessions/clear
   POST /admin/compass/cache/uploads/orphans/clear
   POST /admin/compass/cache/bust
   GET  /admin/compass/db-stats
   GET  /admin/compass/logs
   ```
3. View `views/admin/compass/{layout,migrations,cache,stats,logs}.php` — uses `.tabs` from existing project tabs pattern.
4. Sidebar: new entry "Compass" under Settings — admin only.
5. New `App\Service\CompassService` — encapsulates the "scan sessions dir", "list pending migrations", "count rows" logic.
6. Destructive actions confirm via `CRM.confirm` style modal — no native `confirm()`.
7. All actions write `activity.log` entries (audit trail).

### Telegram
Compass actions fire `compass.action` event → Telegram notification (admins should see "Bohdan cleared 248 stale sessions").

### Estimate
~4 hours.

---

## Track 3 — i18n (en / pl)

### Approach — plain PHP arrays + `t()` helper

No gettext, no JSON, no extension dependency.

### File layout
```
system/i18n/
  en.php   (~150 keys, English defaults)
  pl.php   (Polish translations, same key set)
```

### Helpers (in `system/View/helpers.php`)
```php
function t(string $key, array $args = []): string;
function tn(string $singular, string $plural, int $count, array $args = []): string;
function user_locale(): string;     // reads users.locale, fallback 'en'
function available_locales(): array; // ['en' => 'English', 'pl' => 'Polski']
```

`t()` reads the loaded locale's array; substitutes `:name`-style placeholders via `strtr()`.

For `tn()` — both en and pl have simple plural rules (en: 1 / other; pl: 1 / 2-4 except 12-14 / 0+5+). Encode in arrays:
```php
'task.count' => ['one' => ':n task', 'other' => ':n tasks'],
```

### DB
Migration: `ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'en';`

### UI
- Profile page (`/profile`) gets a "Language" dropdown alongside name/avatar/password.
- Settings page (`/admin/settings`) gets a "Default locale for new users" picker.
- Topbar user-menu — no change (already has Theme; locale belongs on Profile).

### Steps
1. Migration: `users.locale` column.
2. `system/i18n/en.php` — collect all current literal English strings into keys. Run a grep pass through views to find them:
   ```bash
   grep -rn ">\([A-Z][^<]*\)<" views/ | grep -v "<?=" | head -100
   ```
   Group by category: `nav.*`, `btn.*`, `field.*`, `status.*`, `error.*`, `empty.*`, `settings.*`, `dashboard.*`, `forms.*`, etc.
3. `pl.php` — copy keys, translate. Realistic chunks:
   - Nav + sidebar (8 keys) — 5 min
   - Buttons (~25 keys) — 10 min
   - Status / role labels (~15 keys) — 10 min
   - Empty-state texts (~10 keys) — 10 min
   - Settings labels + helpers (~25 keys) — 20 min
   - Form builder labels (~20 keys) — 15 min
   - Error messages (~20 keys) — 15 min
   - Activity verbs (~12 keys) — 10 min
   - **Total: ~95 min for Polish** (use DeepL for first pass, hand-revise).
4. `system/View/helpers.php` — implement `t()`, `tn()`, `user_locale()`. Locale resolution priority: query param `?locale=` (debug only), session `_locale_override`, `user.locale`, `Accept-Language` header, `en`.
5. Bulk-replace literals in views with `<?= t('key') ?>`. This is the bulk of the work — touch every catalog index, settings, forms, dashboard sections, project tabs.
6. Public form pages — partially localise. The form's own title/description are user-supplied (not translated), but UI chrome ("Submit", "Required", "Thank you, your submission was received.") goes through `t()`.
7. Profile page UI: Language dropdown writes to `users.locale` via `POST /profile/locale`.
8. Settings page: "Default locale" picker → setting `default_locale`. New users get this on register.
9. Smoke: switch user to PL, verify dashboard / projects / kanban / forms / users / settings render Polish; switch back to EN.

### Polish-specific notes
- Plural forms for "X tasks / X projektów" — needs the `tn()` helper.
- Dates already use the user's timezone; numbers don't need locale formatting (no commas vs dots issue in our UI yet).
- HTML lang attribute: `<html lang="<?= user_locale() ?>">`.

### Open question
"Otack Manager" name in `app_name()` — should the FALLBACK be localised? Probably no — it's a brand name. The `app_name` setting is whatever the admin types. Skip.

### Risk
- Polish plurals are tricky (3 forms). Test cases needed for `tn()`.
- Forgetting a literal — grep audit pass at the end to catch leftovers:
  ```bash
  grep -rnE '>[A-Z][a-z]+( [A-Z][a-z]+)*<' views/ | grep -v 't('
  ```

### Estimate
~5 hours. Mostly the literal hunt + Polish translation pass.

---

## Cross-cutting

- All three tracks update `docs/DESIGN.md` if any new tokens/components are introduced.
- After each track ships, run a Playwright smoke through the main pages (dashboard, projects, kanban, forms, settings, users, profile).
- Don't bundle the three commits — each ships separately so a problem with one doesn't block the others.

---

## What's NOT in this plan

- White-label logo upload (different SVG/PNG per workspace) — separate task.
- Russian/Ukrainian translation — explicitly de-scoped (en + pl only).
- gettext / Crowdin integration — over-engineering for the current size.
- Multi-tenancy — out of scope, single-workspace by design.
- Rate-limit dashboard in Compass — defer until we see real abuse.

---

Last updated: 2026-05-29
