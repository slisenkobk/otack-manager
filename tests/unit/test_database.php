<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;

it('Connection returns a PDO with foreign keys on', function () {
    $tmp = sys_get_temp_dir() . '/otack-test-' . uniqid() . '.sqlite';
    $pdo = Connection::open($tmp);
    $row = $pdo->query('PRAGMA foreign_keys')->fetch();
    assert_eq(1, (int)$row['foreign_keys']);
    @unlink($tmp);
});

it('SchemaBootstrap.runFile applies a migration once and records it', function () {
    $tmp = sys_get_temp_dir() . '/otack-db-' . uniqid() . '.sqlite';
    $dir = sys_get_temp_dir() . '/otack-migs-' . uniqid();
    mkdir($dir, 0755, true);

    file_put_contents($dir . '/0000_schema_migrations.php', <<<'PHP'
<?php
return function (\PDO $pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        name TEXT PRIMARY KEY,
        applied_at TEXT NOT NULL
    )');
};
PHP);

    $runs = 0;
    file_put_contents($dir . '/20260101_000_foo.php', <<<'PHP'
<?php
return function (\PDO $pdo) {
    $pdo->exec('CREATE TABLE foo (id INTEGER PRIMARY KEY)');
};
PHP);

    $pdo  = Connection::open($tmp);
    $boot = new SchemaBootstrap($pdo);

    $applied1 = Migrations::run($boot, $dir);
    assert_eq(2, count($applied1));

    // Second run must be a no-op.
    $applied2 = Migrations::run($boot, $dir);
    assert_eq(0, count($applied2));

    $count = (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    assert_eq(2, $count);

    @unlink($tmp);
    foreach (glob($dir . '/*') as $f) @unlink($f);
    @rmdir($dir);
});

it('SchemaBootstrap.runFile throws when migration does not return a callable', function () {
    $tmp = sys_get_temp_dir() . '/otack-db-' . uniqid() . '.sqlite';
    $badFile = sys_get_temp_dir() . '/otack-bad-' . uniqid() . '.php';
    file_put_contents($badFile, "<?php\nreturn 'not a closure';\n");

    $pdo  = Connection::open($tmp);
    $boot = new SchemaBootstrap($pdo);
    $boot->beginImmediate();
    $threw = false;
    try {
        $boot->runFile($badFile);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    $boot->rollBack();
    assert_true($threw, 'expected RuntimeException');

    @unlink($tmp);
    @unlink($badFile);
});

it('Migrations.run backfills schema_migrations from legacy marker dir', function () {
    $tmp      = sys_get_temp_dir() . '/otack-bf-' . uniqid() . '.sqlite';
    $migDir   = sys_get_temp_dir() . '/otack-bf-mig-' . uniqid();
    $mrkDir   = sys_get_temp_dir() . '/otack-bf-mrk-' . uniqid();
    mkdir($migDir, 0755, true);
    mkdir($mrkDir, 0755, true);

    // Marker file simulates a previously-applied migration on production.
    file_put_contents($mrkDir . '/foo.1', (string)time());

    // The real bootstrap migration reads from SchemaBootstrap::$legacyMarkerDir.
    copy(
        dirname(__DIR__, 2) . '/system/Database/migrations/0000_schema_migrations.php',
        $migDir . '/0000_schema_migrations.php'
    );

    // Marker key 'foo' must resolve to a real migration file. The closure
    // throws to prove it is NOT executed (backfill claimed it).
    file_put_contents($migDir . '/20260101_000_foo.php', <<<'PHP'
<?php
return function (\PDO $pdo) {
    throw new \RuntimeException('foo migration should have been skipped via backfill');
};
PHP);

    $prev = SchemaBootstrap::$legacyMarkerDir;
    SchemaBootstrap::$legacyMarkerDir = $mrkDir;
    try {
        $pdo  = Connection::open($tmp);
        $boot = new SchemaBootstrap($pdo);
        $applied = Migrations::run($boot, $migDir);

        // Only 0000 ran; foo was backfilled and skipped.
        assert_eq(1, count($applied));
        assert_eq('0000_schema_migrations', $applied[0]);

        $rows = $pdo->query('SELECT name FROM schema_migrations ORDER BY name')
            ->fetchAll(\PDO::FETCH_COLUMN);
        assert_eq(['0000_schema_migrations', '20260101_000_foo'], $rows);
    } finally {
        SchemaBootstrap::$legacyMarkerDir = $prev;
    }

    @unlink($tmp);
    foreach (glob($migDir . '/*') as $f) @unlink($f);
    @rmdir($migDir);
    foreach (glob($mrkDir . '/*') as $f) @unlink($f);
    @rmdir($mrkDir);
});
