# Database layer: SQLite + MySQL (TODO #1, #11)

Design for the dual-driver database support. Otack Manager started as
a SQLite-only appliance; this layer adds first-class MySQL support
without sacrificing the "just open a file and go" SQLite experience,
and lays the groundwork for an in-app SQLite → MySQL migrator
(TODO #11).

This doc is the **spec**; it predates the code. Anything in the code
that diverges from here is either a bug or a deliberate scope change
that should be reflected back into this file.

---

## 1. Goals

- Same codebase runs unchanged against SQLite **or** MySQL 8.0+.
- SQLite stays the zero-config default (single file at `data/app.sqlite`).
- MySQL configuration is `.env`-only — admin never needs to touch SQL.
- All 36 existing migrations + every future migration is **portable by
  construction** — no per-driver branches in migration code.
- Repositories use a single SQL surface that runs on both drivers.
- Backups (Updater snapshot/restore, manual snapshots) work on both.
- An in-app migrator (TODO #11) can copy a live SQLite DB into a fresh
  MySQL DB and switch the running instance to it.

## 2. Non-goals (v1)

- Postgres / SQL Server / other RDBMSes. (The driver layer should not
  preclude them, but they're explicitly not shipped.)
- ORM. We keep raw SQL via PDO; the DSL is for schema only.
- Sharding, read replicas, multi-tenant routing.
- Live MySQL → SQLite migration (only the SQLite → MySQL direction —
  the typical "we outgrew SQLite" path).
- Auto-failover or replica promotion.
- Editing the `.env` from the admin UI (security boundary — admin reads
  but does not write env vars). TODO #11's "switch to MySQL" prints
  the lines the operator must add and verifies them on next boot.

## 3. Strategy: Schema DSL + Driver abstraction

Two layers added; one removed.

### 3.1 Driver interface

```
system/Database/
├── Connection.php                 # entry point; parses DSN, picks driver
├── Driver/
│   ├── DriverInterface.php        # the small contract
│   ├── SqliteDriver.php
│   └── MysqlDriver.php
├── Schema/
│   ├── Blueprint.php              # fluent table definition
│   ├── Column.php                 # column type + modifiers
│   ├── Index.php
│   └── ForeignKey.php
├── Migrations.php                 # unchanged signature, driver-aware
└── SchemaBootstrap.php            # unchanged signature, driver-aware
```

`DriverInterface` is intentionally tiny — anything not on it must be
expressible in plain ANSI SQL:

```php
interface DriverInterface
{
    public function name(): string;          // 'sqlite' | 'mysql'
    public function dsn(): string;
    public function pdoOptions(): array;     // PDO::ATTR_* attributes
    public function postConnect(\PDO $pdo): void; // PRAGMAs / SET NAMES / etc.

    // Schema compilation
    public function compileBlueprint(Blueprint $bp): array; // returns string[] DDL statements

    // Quirks the repo layer pokes at by name
    public function upsertSyntax(string $table, array $cols, array $key): string;
    public function paginationAllOffset(int $offset): string; // "LIMIT -1 OFFSET ?" vs "LIMIT 18446744073709551615 OFFSET ?"
    public function currentTimestampSql(): string;            // "CURRENT_TIMESTAMP" works on both, but useful as a single source
    public function jsonExtractSql(string $col, string $path): string; // SQLite json_extract vs MySQL ->>
}
```

Keep the interface **small**. Anything specific to one driver and not
on this interface is a bug.

### 3.2 Schema Builder DSL

Migrations stop emitting raw `CREATE TABLE` SQL. They describe the
table in a Blueprint, the active driver compiles it.

```php
// Before (system/Database/migrations/20260522_000_users.php)
$pdo->query("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    ...
)");

// After
return function (Schema $schema) {
    $schema->createTable('users', function (Blueprint $t) {
        $t->id();                                    // PK auto-increment
        $t->string('email', 320)->unique();          // VARCHAR(320) or TEXT
        $t->string('password_hash', 255);
        $t->string('name', 200);
        $t->string('role', 20)->default('member');
        $t->string('status', 20)->default('pending');
        $t->string('telegram_chat_id', 64)->nullable();
        $t->timestamp('created_at');
        $t->timestamp('last_login_at')->nullable();
    });
};
```

`Schema` is a tiny driver-aware façade; `Blueprint` carries the column
definitions. Compilation is `$ddl = $driver->compileBlueprint($bp);` →
returns an array of statements (table + each index + each FK) that
SchemaBootstrap executes.

### 3.3 Why DSL, not portable raw SQL

We considered keeping raw SQL but disciplining it to be portable. Three
reasons we picked DSL:

1. **Cognitive load.** "Is `TEXT UNIQUE` portable? does MySQL need a
   length on a unique TEXT?" — this question repeats per migration.
   The DSL answers it once, in `MysqlDriver::compileColumn()`.
2. **Type drift.** SQLite is permissive about types; MySQL is not.
   Raw SQL hides type mismatches that DSL catches at write time.
3. **Migrator (TODO #11).** The migrator needs to know each column's
   logical type to copy values safely (booleans, dates, JSON). The
   Blueprint is the natural place for that knowledge.

The cost — rewriting 36 migrations — is bounded and a one-time chore.
The win compounds across every future migration and the migrator.

## 4. Type mapping

The DSL ships a small set of column types. Each maps to a per-driver
DDL fragment and a per-driver coercion rule for the migrator.

| DSL type            | SQLite                                                | MySQL                          | Notes |
|---------------------|-------------------------------------------------------|--------------------------------|-------|
| `id()`              | `INTEGER PRIMARY KEY AUTOINCREMENT`                   | `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` | one per table |
| `bigInteger($name)` | `INTEGER`                                             | `BIGINT`                       | FK targets |
| `integer($name)`    | `INTEGER`                                             | `INT`                          |  |
| `string($name, N)`  | `TEXT`                                                | `VARCHAR(N)`                   | N defaults 255 |
| `text($name)`       | `TEXT`                                                | `MEDIUMTEXT`                   |  |
| `boolean($name)`    | `INTEGER`                                             | `TINYINT(1)`                   | values 0/1 |
| `real($name)`       | `REAL`                                                | `DOUBLE`                       | positions |
| `decimal($name, P,S)` | `TEXT`                                              | `DECIMAL(P,S)`                 | rare in this app |
| `json($name)`       | `TEXT`                                                | `JSON`                         | both store same Unicode |
| `timestamp($name)`  | `TEXT`                                                | `DATETIME(3)`                  | ISO8601 string on SQLite; millis precision on MySQL |
| `date($name)`       | `TEXT`                                                | `DATE`                         |  |

**Timestamp rules.** All `timestamp` columns hold UTC. PHP writes
ISO8601 strings (`Y-m-d\TH:i:s.u\Z`) — readable on both drivers; MySQL
casts to DATETIME(3) on insert, SQLite stores as-is. This matches what
[AppVersionRepository::log()](../system/Repository/AppVersionRepository.php)
already does, so no repo changes for timestamps.

**Booleans.** Repos already use `0/1`; the only change is MySQL stores
TINYINT(1). No `BOOLEAN` reads back as PHP false-y from SQLite for
either column type.

## 5. Repository portability rules

Most repositories are already portable (prepared SQL, named bindings).
Audit and fix points:

1. **`AppBackupRepository::idsBeyondRetention`** uses
   `LIMIT -1 OFFSET ?` — SQLite-only. Replace with
   `$driver->paginationAllOffset()` which emits the right form for each.
2. **`INSERT OR IGNORE` / `INSERT OR REPLACE`** — used in `SchemaBootstrap::runFile`
   and (TBC during audit) some repos. MySQL spelling is
   `INSERT IGNORE` / `REPLACE INTO`. Hide behind
   `$driver->upsertSyntax($table, $cols, $key)` or
   `$driver->insertIgnoreSyntax(...)`.
3. **Bound `LIMIT ?` placeholders.** With
   `PDO::ATTR_EMULATE_PREPARES => false`, MySQL refuses string-bound
   integers in LIMIT. The repos that do `$stmt->bindValue(N, $int, PDO::PARAM_INT)`
   are correct; those that pass via positional `?` need an integer
   bind. Audit all `LIMIT ?` callers as part of step 4.
4. **Date math.** No `julianday()`, no `datetime('now', ...)` calls
   exist today (date math happens in PHP). Keep it that way; the rule
   is "compute dates in PHP, store ISO8601, compare lexicographically".
5. **`PRAGMA` / `SET NAMES`** — owned by `Driver::postConnect()`.

Convention sins are caught by a new test (in step 5) that scans
`system/Repository/*.php` for known-bad tokens (`LIMIT -1`,
`INSERT OR `, `julianday`, `PRAGMA`).

## 6. Snapshots: per-driver backup / restore

The Updater currently does `copy($appPath, $snapshot)` for SQLite. For
MySQL this becomes a `mysqldump --single-transaction --quick --triggers`
piped to a snapshot file (e.g. `app.sql.gz`).

```php
interface SnapshotInterface
{
    public function backupTo(string $destPath): void;   // writes a file
    public function restoreFrom(string $srcPath): void; // restores in place
    public function liveSize(): int;                    // for app_backups.size_bytes
}
```

Both `SqliteSnapshot` and `MysqlSnapshot` implement it. The Updater
asks the driver for the snapshotter; the on-disk shape inside
`data/backups/{stamp}/` becomes:

```
data/backups/20260615_140020_v1.0.0_to_v1.0.1/
├── code/                   # unchanged
└── db.snapshot             # either app.sqlite (sqlite) or app.sql.gz (mysql)
```

`app_backups.db_snapshot` keeps the relative path; the file extension
tells the restore code which driver to delegate to (and we cross-check
against the **current** driver — restoring a MySQL snapshot on a
SQLite-configured host is an error, not a silent format swap).

`mysqldump` is required on the host PATH for MySQL backups. The
Updater detects this at startup and surfaces a warning in the Updates
tab if it's missing (no `mysqldump` → no MySQL backups → updater
refuses to run on a MySQL instance, with a clear error).

## 7. Configuration

`.env` keys (all optional except where the default is unsuitable):

| Key            | Default                                | Purpose                                              |
|----------------|----------------------------------------|------------------------------------------------------|
| `DB_DSN`       | derived from `DB_PATH` (SQLite)        | Full PDO DSN; presence overrides `DB_PATH`           |
| `DB_PATH`      | `data/app.sqlite`                      | SQLite-only; ignored when `DB_DSN` is set            |
| `DB_USER`      | _empty_                                | MySQL user                                           |
| `DB_PASSWORD`  | _empty_                                | MySQL password                                       |
| `DB_CHARSET`   | `utf8mb4`                              | MySQL only                                           |
| `DB_COLLATION` | `utf8mb4_0900_ai_ci`                   | MySQL only                                           |

DSN examples:

```
DB_DSN=mysql:host=127.0.0.1;port=3306;dbname=otack;charset=utf8mb4
DB_DSN=sqlite:/var/lib/otack/app.sqlite          # equivalent to DB_PATH
```

`Connection::open()` parses the scheme out of the DSN and instantiates
the matching driver. If `DB_DSN` is absent it falls back to
`sqlite:{DB_PATH}` — the existing zero-config path keeps working.

## 8. Schema bootstrap + Migration runner changes

`Migrations::run()` stays driver-agnostic — it iterates files and calls
`SchemaBootstrap::runFile`. Two changes inside:

1. **Closure signature**: migration files now `return function (Schema $schema)` instead of `function (\PDO $pdo)`. The runner constructs the `Schema` wrapper around the PDO + driver.
2. **Transaction wrap**: SQLite uses `BEGIN IMMEDIATE`. MySQL uses
   `START TRANSACTION` but DDL (CREATE TABLE, ALTER TABLE, …) is
   implicitly committed on MySQL, so the rollback semantics differ.
   The runner wraps each **file** in its own transaction and gives up
   the "all-or-nothing batch" property on MySQL (documented loudly in
   docs/MIGRATIONS.md). Practical impact is minimal because migrations
   are tiny and the bootstrap runs to completion in milliseconds.

For backwards-compat with the existing `\PDO`-signature migrations
during the rewrite, the runner sniffs the parameter type and calls
both shapes. Once the rewrite lands the sniff is removed.

## 9. Testing strategy

- Existing unit tests run against SQLite by default. **Same suite**
  is parameterised with `DB_DSN` to run against MySQL via the CI matrix.
- Local dev: `make unit-mysql` spins up a docker `mysql:8` for the
  duration of the test run (no global MySQL install required).
- New tests:
  - `tests/unit/test_schema_dsl.php` — Blueprint → DDL snapshot tests,
    asserting the SQLite and MySQL outputs for each type.
  - `tests/unit/test_repo_portability.php` — convention test that
    greps `system/Repository/*.php` for forbidden tokens.
  - `tests/integration/test_migrator.php` — for TODO #11, end-to-end
    copy of a seeded SQLite DB into a docker MySQL.
- The Playwright suite stays SQLite-only — the UI doesn't care which
  driver is underneath, and parametrising the e2e harness on MySQL is
  more pain than it's worth (use the unit matrix for portability).

## 10. Implementation sequencing

Suggested commits, one per layer, with self-verify before commit:

1. **Driver layer + Connection branch.**
   - `DriverInterface`, `SqliteDriver`, `MysqlDriver`.
   - `Connection::open()` parses DSN, picks driver, runs `postConnect()`.
   - No migration changes yet.
   - Existing tests still pass (SQLite path is unchanged in behaviour).

2. **Schema Builder DSL.**
   - `Blueprint`, `Column`, `Index`, `ForeignKey`, `Schema`.
   - `compileBlueprint()` on both drivers.
   - Unit tests for DDL output.
   - Migration runner accepts both `function(PDO)` and `function(Schema)`
     to keep the existing migrations working.

3. **Rewrite migrations to DSL.**
   - All 36 files translated.
   - Snapshot DDL test against both drivers locks the output.
   - Drop the legacy-PDO sniff once everything is converted.

4. **Repo audit + snapshot abstraction.**
   - Fix `LIMIT -1 OFFSET ?` and any other portability sins.
   - `SnapshotInterface` + per-driver implementations.
   - Updater wires through `App::make('driver')->snapshot()`.

5. **MySQL CI matrix.**
   - `.github/workflows/ci.yml` (or equivalent) gets a second job.
   - `make unit-mysql` for local.
   - Convention test for repo portability.

6. **TODO #11 — Migrator.** (Compass module — see §11 below.)

7. **Docs sync.**
   - README mentions MySQL support.
   - docs/MIGRATIONS.md updated to reflect DSL usage and the
     transactional-batch caveat on MySQL.

Each step is independently shippable. Step 1 alone delivers nothing
user-visible; steps 2-3 deliver "running on MySQL works" once the user
sets `DB_DSN`. Step 6 delivers "switch from SQLite to MySQL without
losing data".

## 11. TODO #11 — Migrator design

A self-service wizard under **Compass → Migrate to MySQL** for the
common "we outgrew SQLite, please move us" case. Driver layer must be
in place (step 1-5 above) before this can be built — the migrator
copies data through whatever DSL/types the codebase already speaks.

### 11.1 Flow

1. **Pre-flight** — Compass page collects the target MySQL host, port,
   database name, user, password. "Test connection" button opens a
   short-lived PDO, runs `SELECT VERSION()`, surfaces success or the
   driver-level error. No write yet.
2. **Plan** — show the admin a summary: row counts per table, estimated
   transfer size, an explicit warning that the operation **locks
   writes** (maintenance mode) for the duration. List any pre-conditions
   that fail (mysqldump missing, target DB not empty, MySQL < 8.0).
3. **Execute** — the admin clicks "Start migration". The migrator:
   1. Enters **maintenance mode** (a `MAINTENANCE` flag in `settings`
      that the request dispatcher checks; non-admin requests get a
      503 with a friendly page).
   2. Opens the source SQLite (read-only) and the target MySQL.
   3. Runs the full migration set on MySQL (`Migrations::run()` against
      the new connection). This builds the empty schema.
   4. **Copies tables in dependency order** (parents before children
      to satisfy FKs). For each table, stream rows with a server-side
      cursor and `INSERT` batches of 500 rows in a single transaction.
      Apply per-column coercion from the Blueprint (booleans, JSON).
   5. **Reset auto-increment counters** on each table to
      `MAX(id) + 1`.
   6. **Sanity check** — re-count each table on both ends, assert equal.
       Any mismatch aborts before the switch.
   7. **Verify** — run a few canary queries (login by known email,
      list dashboard) against the new connection.
4. **Switch** — the migrator does NOT edit `.env` (security boundary).
   Instead it shows the exact lines the operator must paste:
   ```
   DB_DSN=mysql:host=…;port=…;dbname=…;charset=utf8mb4
   DB_USER=…
   DB_PASSWORD=…
   ```
   …with a "Verify config" button that, on next request, attempts to
   open the configured connection and reports back. Maintenance mode
   stays on until verification succeeds; then it lifts automatically.
5. **Rollback** — the source SQLite file is **never touched** during
   the migration; rollback is "remove the new DB_DSN lines from .env,
   reload". The migrator keeps the SQLite file on disk for at least 7
   days post-switch (logged in `app_backups` with a new `kind='sqlite_pre_migration'`)
   before retention may prune it.

### 11.2 What we explicitly don't do

- Online migration. The instance is in maintenance mode for the
  duration. For a typical Otack install (a few hundred MB of data),
  this is minutes. Anyone large enough to need online migration is
  past the "self-service wizard" persona.
- **MySQL → SQLite** direction. The product opinion is one-way: SQLite
  for small / hobby, MySQL for serious. Going back is an `mysqldump`
  + manual import; not a wizard.
- Cross-version MySQL migrations (5.7 → 8.0, etc.). The wizard targets
  MySQL 8.0+.

### 11.3 Endpoints (TODO #11)

| Method + path                              | Who      | Purpose                                                    |
|--------------------------------------------|----------|------------------------------------------------------------|
| `GET  /admin/compass/db-migrate`           | admin    | Renders the wizard                                          |
| `POST /admin/compass/db-migrate/test`      | admin    | Opens a transient PDO + returns JSON `{ok,message,version}` |
| `POST /admin/compass/db-migrate/start`     | admin    | Kicks off the synchronous transfer, streams progress via SSE |
| `GET  /admin/compass/db-migrate/verify`    | admin    | Used post-`.env`-swap to confirm the new connection works   |

The transfer is a single long-running HTTP request with SSE progress
events to the wizard page. Behind a reverse proxy this needs the proxy
to honour streaming responses (documented in the wizard's intro).

## 12. Migration discipline (refer to docs/MIGRATIONS.md)

The data-preservation rule from
[docs/MIGRATIONS.md](MIGRATIONS.md#-data-preservation-rule-non-negotiable)
applies identically to DSL migrations. The DSL has no opinion about
this — `Blueprint::dropColumn()` exists, but using it on a populated
production column is a release-blocking bug. Refer to the rule on every
review.

## 13. Open questions

- **MySQL minimum version.** Locking to 8.0+ buys us `JSON` columns,
  invisible columns, CTEs we may want later. 5.7 is EOL since 2023-10.
  Default: **MySQL 8.0+**, reject older with a friendly error.
- **Charset/collation.** `utf8mb4_0900_ai_ci` (MySQL 8 default) handles
  emoji + ICU-style equivalence. Default: this; allow override via env.
- **Connection pooling.** PHP's per-request connection model means we
  open one PDO per request anyway. Persistent connections (PDO::ATTR_PERSISTENT)
  could win some throughput, but they bite on shared hosting. Default:
  **no persistent**, revisit if a deployment shows it matters.
- **mysqldump path.** `which mysqldump` at boot is enough for most
  installs. For Docker-only hosts, document an `MYSQLDUMP_PATH` env
  override.
