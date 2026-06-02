# Migrations

Per-file migrations live in [`system/Database/migrations/`](../system/Database/migrations/) and are applied automatically on the first HTTP hit (via `public/index.php`) or explicitly via `make migrate` / `php bin/migrate.php`. They use the **Schema DSL** ([DATABASE.md §3.2](DATABASE.md)) so the same file runs unchanged on SQLite and MySQL.

Applied migrations are recorded in the `schema_migrations` table. The runner discovers files by glob, sorts alphabetically, and applies anything whose basename (sans `.php`) is not yet in the table.

## File format

Each migration file must `return` a `Closure(Schema): void`:

```php
<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('foo', function (Blueprint $t) {
        $t->id();
        $t->string('name')->unique();
        $t->timestamp('created_at');
    });
};
```

No namespacing, no class. The runner wraps the call in a per-file transaction (`BEGIN IMMEDIATE` on SQLite, `START TRANSACTION` on MySQL — DDL is implicit-committed on MySQL, so rollback semantics differ from SQLite; see [DATABASE.md §8](DATABASE.md)).

For genuinely complex migrations (raw `UPDATE` backfills, data transforms) reach for `$schema->execute($sql)` or `$schema->pdo()` — the documented escape hatches.

The runner also still accepts the legacy `function (\PDO $pdo)` shape — the dispatch in `SchemaBootstrap::runFile` inspects the parameter type. The convention test (`tests/unit/test_repo_portability.php`) fails CI if any migration introduces SQLite-only syntax (`PRAGMA table_info`, `datetime('now')`, `AUTOINCREMENT`).

## Naming convention

`YYYYMMDD_NNN_<key>.php`

- `YYYYMMDD` — UTC date of the migration as it shipped (typically the commit date).
- `NNN` — three-digit sequence number within that date (`000`, `010`, `020`, ...). Leaving gaps of 10 lets you slot in a fix later without renames.
- `<key>` — snake_case, descriptive. Mirrors the legacy ensure() keys.

`0000_schema_migrations.php` is the bootstrap exception — it owns the creation of the `schema_migrations` table itself and runs first on a fresh DB.

## ❗ Filenames are PERMANENT

Once a migration file has shipped to a production environment, **never rename it**. The runner identifies applied migrations by filename; renaming it would re-execute the migration on the next deploy, possibly with destructive results.

## ❗ Data preservation rule (NON-NEGOTIABLE)

**Schema migrations must never drop, rename, or narrow a column that contains user data.** Inserts, additive `ALTER TABLE ADD COLUMN`, new tables, and new indexes are fine. The destructive operations below are forbidden in a schema migration:

- `DROP TABLE` on a table that ever held production rows
- `ALTER TABLE ... DROP COLUMN`
- `ALTER TABLE ... RENAME COLUMN` on a column that ever held production data
- `UPDATE` that overwrites a populated field with a default value
- Type narrowing (`TEXT → INTEGER`, `VARCHAR(255) → VARCHAR(50)`, etc.) where existing values may not fit

The reason: in-app updates (see [UPDATES.md](UPDATES.md)) run migrations against live production data. A column drop irreversibly destroys whatever was in it across every install pulling the update. Even with backup-and-restore on the host side, the data trail (audit logs, third-party links, exports) is broken.

### Workflow for genuinely needing to remove a field

If a column truly needs to go away, split it across **at least two releases**:

1. **Release N — deprecate.** Schema migration stops *writing* to the field at the application layer; the column itself remains in the DB, untouched. Application reads still work for old rows. Mark deprecation in the field's docblock and in this file's "Deprecated columns" section below.
2. **Release N+1 (or later) — data migration.** A dedicated, idempotent script (lives under `bin/data-migrations/`, NOT in `system/Database/migrations/`) optionally migrates the deprecated field's values to wherever they're now stored. Operators run it explicitly via Compass with a confirmation step; it is **not** auto-applied at boot.
3. **Release N+2 (only if everyone is on N+1).** A schema migration may then drop the column. Add the migration with a release-notes line explaining what's gone and why.

The rule is conservative on purpose — losing user data via a sneaky one-liner in a migration file is the single most damaging thing this codebase could do to operators.

### Deprecated columns

Track here. None yet.

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

**Batch rollback caveat:** if migration N fails, the rollback also reverts migrations 0..N-1 that ran earlier in the same batch. Those will all be retried on the next boot. Write each migration to be safe under repeated re-application — use `CREATE TABLE IF NOT EXISTS`, `PRAGMA table_info` guards before `ALTER`, and `INSERT OR IGNORE` / pre-checks for backfill closures.

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
