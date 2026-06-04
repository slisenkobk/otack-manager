<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Service\DbMigrator;

// Helper — open a Connection-registered in-memory SQLite (so
// DbMigrator's Connection::driverFor() lookup succeeds) and seed it with
// schema_migrations + two business tables, so plan()/migrate() see a
// realistic source connection without touching prod data.
function db_migrator_seed_sqlite(): \PDO
{
    $pdo = Connection::open('sqlite::memory:');
    $pdo->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT)');
    $pdo->exec('CREATE TABLE projects (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE tasks (id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL, title TEXT)');
    $pdo->exec("INSERT INTO projects (name) VALUES ('alpha'), ('beta'), ('gamma')");
    $pdo->exec("INSERT INTO tasks (project_id, title) VALUES (1, 'one'), (1, 'two')");
    return $pdo;
}

it('plan() reports per-table row counts and excludes schema_migrations', function () {
    $pdo = db_migrator_seed_sqlite();
    $m = new DbMigrator();
    $plan = $m->plan($pdo);

    assert_true(isset($plan['tables']) && is_array($plan['tables']), 'tables key missing');
    $names = array_map(fn($t) => $t['name'], $plan['tables']);
    assert_true(!in_array('schema_migrations', $names, true), 'schema_migrations leaked into plan');
    assert_true(in_array('projects', $names, true), 'projects missing from plan');
    assert_true(in_array('tasks', $names, true), 'tasks missing from plan');

    $byName = array_column($plan['tables'], null, 'name');
    assert_eq(3, $byName['projects']['rows']);
    assert_eq(2, $byName['tasks']['rows']);
    assert_eq(5, $plan['total_rows']);
});

it('plan() throws when handed an unregistered (driver-unknown) PDO', function () {
    // A bare PDO not opened through Connection::open() is invisible to
    // Connection::driverFor() (it keys on the PDO instance), so the
    // migrator can't confirm "this is SQLite" and refuses to proceed.
    $bare = new \PDO('sqlite::memory:');
    $m = new DbMigrator();
    $threw = false;
    try { $m->plan($bare); }
    catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'SQLite source');
    }
    assert_true($threw, 'plan() must reject a connection it cannot identify as SQLite');
});

it('migrate() throws early when source is not a SQLite connection', function () {
    $bare = new \PDO('sqlite::memory:');
    $m = new DbMigrator();
    $threw = false;
    try {
        $m->migrate($bare, [
            'host' => '127.0.0.1', 'port' => 3306,
            'db'   => 'x', 'user' => 'x', 'password' => 'x',
        ]);
    } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'SQLite source');
    }
    assert_true($threw, 'migrate() must reject non-SQLite source before opening the target');
});

it('testConnection() returns ok=false on an unreachable MySQL host', function () {
    $m = new DbMigrator();
    // 127.0.0.1:1 — a privileged port nothing legitimate listens on. PDO
    // fails fast (ATTR_TIMEOUT=5 inside testConnection) rather than
    // blocking the test runner.
    $r = $m->testConnection([
        'host'     => '127.0.0.1',
        'port'     => 1,
        'db'       => 'otack_does_not_exist',
        'user'     => 'invalid',
        'password' => 'invalid',
    ]);
    assert_true(isset($r['ok']) && $r['ok'] === false, 'expected ok=false on unreachable host');
    assert_true(!empty($r['error']), 'expected human-readable error string');
});

// Sanity-check the private q() identifier quoter — it underpins every
// CREATE/SELECT/INSERT the migrator emits, so a regression there silently
// corrupts every migration. Reflection lets us hit it without exposing a
// public test API.
it('q() escapes backticks inside identifiers for safe MySQL/SQLite inserts', function () {
    $m  = new DbMigrator();
    $rc = new ReflectionClass(DbMigrator::class);
    $rm = $rc->getMethod('q');
    $rm->setAccessible(true);
    assert_eq('`plain`',         $rm->invoke($m, 'plain'));
    // A literal backtick inside the identifier must be doubled so MySQL
    // parses the wrapping pair correctly.
    assert_eq('`weird``name`',   $rm->invoke($m, 'weird`name'));
});
