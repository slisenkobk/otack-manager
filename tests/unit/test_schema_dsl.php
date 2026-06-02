<?php
declare(strict_types=1);

use App\Database\Driver\MysqlDriver;
use App\Database\Driver\SqliteDriver;
use App\Database\Schema\Blueprint;

// DDL snapshot tests. They lock the SQL shape per driver so we catch
// any accidental drift when we extend the DSL in later steps. The tests
// don't open a real DB — Blueprint → driver → string[] is pure.

it('Sqlite CREATE TABLE: id + string(unique) + nullable timestamp', function () {
    $bp = new Blueprint('users');
    $bp->id();
    $bp->string('email')->unique();
    $bp->timestamp('created_at');
    $bp->timestamp('last_login_at')->nullable();

    $stmts = (new SqliteDriver('sqlite::memory:'))->compileCreateTable($bp);
    assert_eq(1, count($stmts), 'no extra index — single statement');
    $sql = $stmts[0];

    assert_true(strpos($sql, 'CREATE TABLE users') === 0);
    assert_true(strpos($sql, 'id INTEGER PRIMARY KEY AUTOINCREMENT') !== false);
    assert_true(strpos($sql, 'email TEXT NOT NULL UNIQUE') !== false);
    assert_true(strpos($sql, 'created_at TEXT NOT NULL') !== false);
    assert_true(strpos($sql, 'last_login_at TEXT') !== false);
    assert_true(strpos($sql, 'last_login_at TEXT NOT NULL') === false, 'nullable: no NOT NULL');
});

it('MySQL CREATE TABLE: id maps to BIGINT UNSIGNED + InnoDB/utf8mb4', function () {
    $bp = new Blueprint('users');
    $bp->id();
    $bp->string('email', 320)->unique();
    $bp->timestamp('created_at');

    $stmts = (new MysqlDriver('mysql:host=x', 'u', 'p'))->compileCreateTable($bp);
    $sql = $stmts[0];

    assert_true(strpos($sql, 'CREATE TABLE users') === 0);
    assert_true(strpos($sql, 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY') !== false);
    assert_true(strpos($sql, 'email VARCHAR(320) NOT NULL UNIQUE') !== false);
    assert_true(strpos($sql, 'created_at DATETIME(3) NOT NULL') !== false);
    assert_true(strpos($sql, 'ENGINE=InnoDB') !== false);
    assert_true(strpos($sql, 'utf8mb4') !== false);
});

it('Sqlite: foreign key emitted as inline FOREIGN KEY clause', function () {
    $bp = new Blueprint('tasks');
    $bp->id();
    $bp->bigInteger('project_id');
    $bp->foreign('project_id')->references('id')->on('projects')->onDelete('CASCADE');

    $sql = (new SqliteDriver('sqlite::memory:'))->compileCreateTable($bp)[0];
    assert_true(strpos($sql, 'FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE') !== false);
});

it('MySQL: foreign key emitted as CONSTRAINT … FOREIGN KEY clause', function () {
    $bp = new Blueprint('tasks');
    $bp->id();
    $bp->bigInteger('project_id');
    $bp->foreign('project_id')->references('id')->on('projects')->onDelete('CASCADE');

    $sql = (new MysqlDriver('mysql:host=x', 'u', 'p'))->compileCreateTable($bp)[0];
    assert_true(strpos($sql, 'CONSTRAINT fk_tasks_project_id FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE') !== false);
});

it('Indexes: separate CREATE INDEX statement for single-column index, inline for composite UNIQUE', function () {
    $bp = new Blueprint('tags');
    $bp->id();
    $bp->string('scope', 20);
    $bp->string('name');
    $bp->unique(['scope', 'name']);     // composite unique → inline
    $bp->index('name');                 // single-col regular → separate stmt

    $stmts = (new SqliteDriver('sqlite::memory:'))->compileCreateTable($bp);
    assert_eq(2, count($stmts), 'inline UNIQUE + one separate CREATE INDEX');
    assert_true(strpos($stmts[0], 'UNIQUE(scope, name)') !== false, 'composite UNIQUE inline');
    assert_true(strpos($stmts[1], 'CREATE INDEX idx_tags_name ON tags(name)') !== false);
});

it('ALTER TABLE ADD COLUMN: emits per-column statement on both drivers', function () {
    $bp = new Blueprint('tasks');
    $bp->string('priority', 16)->default('none');
    $bp->string('sub_status')->nullable();

    $sqlite = (new SqliteDriver('sqlite::memory:'))->compileAlterTable($bp);
    assert_eq(2, count($sqlite));
    assert_true(strpos($sqlite[0], "ALTER TABLE tasks ADD COLUMN priority TEXT NOT NULL DEFAULT 'none'") !== false);
    assert_true(strpos($sqlite[1], 'ALTER TABLE tasks ADD COLUMN sub_status TEXT') !== false);
    assert_true(strpos($sqlite[1], 'NOT NULL') === false, 'sub_status is nullable');

    $mysql = (new MysqlDriver('mysql:host=x', 'u', 'p'))->compileAlterTable($bp);
    assert_eq(2, count($mysql));
    assert_true(strpos($mysql[0], "ALTER TABLE tasks ADD COLUMN priority VARCHAR(16) NOT NULL DEFAULT 'none'") !== false);
});

it('Defaults: scalar types pass through; booleans render as 0/1', function () {
    $bp = new Blueprint('flags');
    $bp->boolean('enabled')->default(true);
    $bp->integer('count')->default(0);
    $bp->string('label')->default("It's fine"); // single-quote escaping

    $sql = (new SqliteDriver('sqlite::memory:'))->compileCreateTable($bp)[0];
    assert_true(strpos($sql, 'enabled INTEGER NOT NULL DEFAULT 1') !== false);
    assert_true(strpos($sql, 'count INTEGER NOT NULL DEFAULT 0') !== false);
    assert_true(strpos($sql, "label TEXT NOT NULL DEFAULT 'It''s fine'") !== false, 'single quotes escaped');
});

it('createTable wires Blueprint → driver → PDO end-to-end on SQLite', function () {
    $tmp = sys_get_temp_dir() . '/otack_dsl_' . uniqid('', true) . '.sqlite';
    $pdo = App\Database\Connection::open($tmp);
    $driver = App\Database\Connection::driverFor($pdo);
    $schema = new App\Database\Schema\Schema($pdo, $driver);

    $schema->createTable('demo', function (Blueprint $t) {
        $t->id();
        $t->string('name')->unique();
        $t->integer('count')->default(0);
        $t->timestamp('created_at');
    });

    // Insert + read back to confirm the table actually exists with the
    // shape the DSL claimed.
    $pdo->prepare('INSERT INTO demo (name, count, created_at) VALUES (?, ?, ?)')
        ->execute(['a', 5, '2026-06-02T12:00:00.000Z']);
    $row = $pdo->query('SELECT name, count FROM demo')->fetch();
    assert_eq('a', $row['name']);
    assert_eq(5, (int)$row['count']);

    // UNIQUE should reject the duplicate insert.
    $threw = false;
    try {
        $pdo->prepare('INSERT INTO demo (name, count, created_at) VALUES (?, ?, ?)')
            ->execute(['a', 1, '2026-06-02T12:00:01.000Z']);
    } catch (\PDOException $_) { $threw = true; }
    assert_true($threw, 'UNIQUE must reject the duplicate row');

    @unlink($tmp);
});
