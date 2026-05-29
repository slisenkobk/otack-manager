# Next-session prompt

Paste this verbatim at the start of the next session to spin Claude up on
the three pending tracks. Don't paraphrase — the wording is calibrated to
re-establish context without re-reading the whole prior session.

---

```
Project: /Users/slisenkobogdan/Work/AINeoLab/internal/otack/otack-tasks
Stack: PHP 8.2+ / SQLite / vanilla JS / server-rendered views. CSP strict.

Read docs/PLAN-next-session.md — that file holds the plan for three tracks
agreed in the previous session: (1) migrations refactor, (2) Compass
admin panel, (3) i18n en/pl. Each track ships independently.

Execute in this order:

──── TRACK 1 — Migrations refactor (~3h) ────

Goal: replace one growing system/Database/Migrations.php with per-file
migrations in system/Database/migrations/ and a DB-backed marker table.

Constraints:
- Keep auto-bootstrap from public/index.php working (no manual step in dev).
- Add `make migrate` CLI entry (bin/migrate.php) so CI / deploy can run
  migrations explicitly.
- Wrap the run in BEGIN IMMEDIATE to serialize concurrent first hits.
- Existing data/.schema/ marker files MUST backfill into the new
  schema_migrations table the first time the new code runs in production
  — otherwise every prior migration re-applies. Read the file names,
  insert into the table, then ignore the directory forever.
- Filenames once shipped are PERMANENT — document this in
  docs/MIGRATIONS.md so contributors don't rename them.

Steps:
1. Create system/Database/migrations/0000_schema_migrations.php (bootstrap
   table).
2. Split Migrations::run() into per-file migrations named
   YYYYMMDD_NNN_<key>.php. Use git log to date each. Keep the closures
   verbatim — body unchanged, only the wrapper moves.
3. Rewrite SchemaBootstrap with a `runFile($path)` method that reads
   schema_migrations.
4. Update Migrations::run() to glob + sort + run sequentially in one tx.
5. Add bin/migrate.php CLI runner (exit code 0/1) + Makefile target.
6. Backfill schema_migrations from existing data/.schema/ marker files on
   first run.
7. Delete data/.schema/ directory after backfill verified.
8. Smoke-test: fresh DB boot + existing DB boot (should be no-op).

──── TRACK 2 — Compass admin panel (~4h) ────

Goal: Voyager/Artisan-style /admin/compass with four tabs:
- Migrations  (list status, "Run pending" button)
- Cache       (clear sessions, clear orphaned uploads, CSS/JS cache-bust)
- DB stats    (row counts per table, file size, last migration timestamp)
- Logs        (tail of data/errors.log, filter by level)

Constraints:
- Admin-only via AuthGuard::requireAdmin in the controller.
- All destructive actions use CRM.confirm modal (NO native confirm()).
- All actions write activity_log + fire 'compass.action' event for
  Telegram. Admins should see "Bohdan cleared 248 stale sessions" in
  their channel.
- Sidebar entry "Compass" added under Settings — admin-only render.

Steps:
1. system/Controller/CompassController.php — admin guard in constructor.
2. Routes block in public/index.php — six new routes under /admin/compass.
3. system/Service/CompassService.php — encapsulates filesystem walks,
   row counters, log tailing.
4. Views in views/admin/compass/{layout,migrations,cache,stats,logs}.php
   using the existing .tabs pattern from project tabs.
5. New Telegram listener for compass.action event.
6. Smoke-test each action end-to-end.

──── TRACK 3 — i18n en + pl (~5h) ────

Goal: English (default) + Polish, user-selectable in Profile.

Approach: plain PHP arrays + t() / tn() helpers. No gettext, no external
deps.

Files:
  system/i18n/en.php   (canonical key set, English defaults)
  system/i18n/pl.php   (Polish translations, same key set)

Helpers (add to system/View/helpers.php):
  t(string $key, array $args = []): string
  tn(string $singular, string $plural, int $count, array $args = []): string
  user_locale(): string         // from users.locale, fallback 'en'
  available_locales(): array    // ['en' => 'English', 'pl' => 'Polski']

Constraints:
- Migration: ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'.
- Locale resolution order: ?locale= query param (debug only) →
  $_SESSION['_locale_override'] → users.locale → Accept-Language → 'en'.
- Polish plural rules are tricky (3 forms: 1 / 2-4 except 12-14 / 0+5+).
  Encode in tn() with explicit form selection.
- HTML lang attribute on <html> reflects user_locale().
- app_name() does NOT go through t() — it's a brand string.

Steps:
1. Migration users.locale.
2. Implement t(), tn(), user_locale(), available_locales().
3. Build en.php by grepping views/ for hardcoded English strings. Group
   keys by category: nav.*, btn.*, field.*, status.*, error.*, empty.*,
   settings.*, dashboard.*, forms.*, activity.*.
4. Translate to pl.php (use DeepL for first pass, hand-revise plurals
   and idiom).
5. Replace literals in views with <?= t('key') ?> calls. Touch every
   catalog index, settings, profile, forms builder, dashboard sections,
   project tabs.
6. Partial public form localization: form title/description stay
   user-supplied; UI chrome ("Submit", "Required", "Thank you...") goes
   through t().
7. Profile page: add Language dropdown alongside name/avatar. POST
   /profile/locale updates users.locale.
8. Settings page: add "Default locale for new users" picker → setting
   `default_locale`. New registrations inherit it.
9. Smoke-test PL across dashboard / projects / kanban / forms / users /
   settings.

Final grep audit for orphan literals:
  grep -rnE '>[A-Z][a-z]+( [A-Z][a-z]+)*<' views/ | grep -v "t("

──── Rules of engagement ────

- Ship each track as its own commit (or PR). Don't bundle.
- Run Playwright smoke after each track.
- No new tokens or design components beyond what's already in app.css.
- No native dialogs (alert/confirm/prompt) anywhere — use UI.toast /
  UI.confirm / UI.prompt.
- Keep PHP files small and lint-clean (php -l) before claiming done.
- Mirror auth-page-bg / topbar-bg pattern when adding new layouts:
  define new tokens in :root + dark-theme override block.
- When adding routes, also update the publicGets array in
  public/index.php for any debug-only / unauthenticated routes.

Login for smoke testing:
- LOGIN_HASH in .env (read it via grep, don't echo).
- Create a temporary admin via PHP CLI (password TestAdmin123, role
  admin, email e2e-themetest@test.local), test, then delete.

Memory: /Users/slisenkobogdan/.claude/projects/-Users-slisenkobogdan-Work-AINeoLab-internal-otack/memory/MEMORY.md
holds Otack-wide project notes. The auto-memory section in CLAUDE.md
loads these automatically.

If anything in PLAN-next-session.md conflicts with what you find in code,
trust the code and update the plan.
```

---

End of prompt.
