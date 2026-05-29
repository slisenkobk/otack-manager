# Migrations

Per-file SQLite migrations live in [`system/Database/migrations/`](../system/Database/migrations/) and are applied automatically on the first HTTP hit (via `public/index.php`) or explicitly via `make migrate` / `php bin/migrate.php`.

Applied migrations are recorded in the `schema_migrations(name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)` table inside the application DB. The runner discovers files by glob, sorts alphabetically, and applies anything whose basename (sans `.php`) is not yet in the table.

## File format

Each migration file must `return` a `Closure(PDO): void`:

```php
<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $pdo->exec("CREATE TABLE foo ( ... )");
};
```

No namespacing, no class. The closure runs inside a single `BEGIN IMMEDIATE` transaction shared by the whole batch — concurrent first-hits on production serialise rather than racing.

## Naming convention

`YYYYMMDD_NNN_<key>.php`

- `YYYYMMDD` — UTC date of the migration as it shipped (typically the commit date).
- `NNN` — three-digit sequence number within that date (`000`, `010`, `020`, ...). Leaving gaps of 10 lets you slot in a fix later without renames.
- `<key>` — snake_case, descriptive. Mirrors the legacy ensure() keys.

`0000_schema_migrations.php` is the bootstrap exception — it owns the creation of the `schema_migrations` table itself and runs first on a fresh DB.

## ❗ Filenames are PERMANENT

Once a migration file has shipped to a production environment, **never rename it**. The runner identifies applied migrations by filename; renaming it would re-execute the migration on the next deploy, possibly with destructive results.

If you need to change something:
- **Wrong SQL?** Add a new migration that fixes it.
- **Wrong filename / key?** Live with it — the wart is permanent.
- **Want to roll back?** Add a new migration that undoes the change.

There is no `down()` / rollback step. Forward-only.

## Running

```bash
make migrate                   # apply pending migrations explicitly
php bin/migrate.php            # same; useful in CI / deploy scripts
```

Exit code 0 on success (including no-op), 1 on any failure. The runner wraps the whole batch in `BEGIN IMMEDIATE` / `COMMIT`, so a mid-batch failure rolls back cleanly — the half-applied state never persists.

Web boot (`public/index.php`) calls `Migrations::run()` on every request, but the runner short-circuits when there's nothing pending, so the overhead is one `SELECT name FROM schema_migrations`.

## Legacy backfill (one-time)

Installations that pre-date this layout used filesystem markers under `data/.schema/<key>.<version>`. The bootstrap migration (`0000_schema_migrations.php`) detects that directory on first run and inserts a row into `schema_migrations` for each marker whose key matches a sibling migration filename. The applied_at timestamp is taken from the marker file's mtime.

After backfill, `data/.schema/` is no longer consulted. Operators can remove it with `make reset` or `rm -rf data/.schema` once they've confirmed the new schema_migrations table looks correct.

The backfill scan is opt-in: `SchemaBootstrap::$legacyMarkerDir` must be set to the marker directory's absolute path. Both `public/index.php` and `bin/migrate.php` set it to `APP_ROOT . '/data/.schema'`; tests deliberately leave it `null` so they don't see ghost markers from the live environment.

## Writing a new migration

1. Create `system/Database/migrations/YYYYMMDD_NNN_<key>.php` (today's date, next free sequence).
2. Implement the closure. Wrap schema changes in idempotency guards (`CREATE TABLE IF NOT EXISTS`, `PRAGMA table_info` checks before `ALTER`) so the migration is safe to re-apply against a partially-migrated DB.
3. Run `php bin/migrate.php` locally; verify with `sqlite3 data/app.sqlite ".schema" / "SELECT * FROM schema_migrations;"`.
4. Add a unit test in `tests/unit/test_database.php` if the migration has tricky logic.
5. Commit and deploy.

## Compass (admin UI)

The Compass admin panel (`/admin/compass/migrations`) shows applied/pending state for each file in the migrations directory and exposes a "Run pending" button. Admin-only.
