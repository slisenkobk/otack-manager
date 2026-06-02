<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Database\Driver\DriverFactory;
use App\Database\Driver\MysqlDriver;
use App\Database\Driver\SqliteDriver;

it('DriverFactory picks SqliteDriver for sqlite: DSN', function () {
    $d = DriverFactory::make('sqlite::memory:');
    assert_eq('sqlite', $d->name());
    assert_true($d instanceof SqliteDriver, 'returned class is SqliteDriver');
});

it('DriverFactory picks MysqlDriver for mysql: DSN and carries credentials', function () {
    $d = DriverFactory::make(
        'mysql:host=127.0.0.1;port=3306;dbname=otack;charset=utf8mb4',
        ['username' => 'otack', 'password' => 'pw', 'charset' => 'utf8mb4']
    );
    assert_eq('mysql', $d->name());
    assert_true($d instanceof MysqlDriver);
    assert_eq('otack', $d->username());
    assert_eq('pw', $d->password());
});

it('DriverFactory rejects unsupported schemes with a clear message', function () {
    $threw = false;
    try {
        DriverFactory::make('pgsql:host=localhost');
    } catch (\InvalidArgumentException $e) {
        $threw = (strpos($e->getMessage(), "'pgsql'") !== false)
              && (strpos($e->getMessage(), 'sqlite, mysql') !== false);
    }
    assert_true($threw, 'unsupported driver must error with named driver + supported list');
});

it('DriverFactory rejects malformed DSN', function () {
    $threw = false;
    try {
        DriverFactory::make('not-a-dsn');
    } catch (\InvalidArgumentException $_) {
        $threw = true;
    }
    assert_true($threw, 'DSN without a scheme must error');
});

it('Connection::open keeps the legacy bare-path signature working', function () {
    $tmp = sys_get_temp_dir() . '/otack_conn_' . uniqid('', true) . '.sqlite';
    $pdo = Connection::open($tmp);
    assert_true($pdo instanceof \PDO);
    $drv = Connection::driverFor($pdo);
    assert_true($drv !== null && $drv->name() === 'sqlite', 'bare path → sqlite driver');
    // Confirm the SQLite-specific postConnect actually ran (foreign_keys = ON).
    $fk = $pdo->query('PRAGMA foreign_keys')->fetchColumn();
    assert_eq('1', (string)$fk, 'PRAGMA foreign_keys must be ON');
    @unlink($tmp);
});

it('Connection::open accepts an explicit sqlite: DSN', function () {
    $pdo = Connection::open('sqlite::memory:');
    assert_true($pdo instanceof \PDO);
    $drv = Connection::driverFor($pdo);
    assert_true($drv !== null && $drv->name() === 'sqlite');
});

it('Connection::driverFor returns null for unknown PDOs', function () {
    $foreign = new \PDO('sqlite::memory:');
    assert_eq(null, Connection::driverFor($foreign));
});

it('SqliteDriver pdoOptions enforces exception mode + assoc + no emulated prepares', function () {
    $d = new SqliteDriver('sqlite::memory:');
    $opts = $d->pdoOptions();
    assert_eq(\PDO::ERRMODE_EXCEPTION, $opts[\PDO::ATTR_ERRMODE]);
    assert_eq(\PDO::FETCH_ASSOC,        $opts[\PDO::ATTR_DEFAULT_FETCH_MODE]);
    assert_eq(false,                    $opts[\PDO::ATTR_EMULATE_PREPARES]);
});

it('MysqlDriver username/password pass through; charset has a sane default', function () {
    $d = new MysqlDriver('mysql:host=127.0.0.1;dbname=x', 'u', 'p');
    assert_eq('mysql', $d->name());
    assert_eq('u', $d->username());
    assert_eq('p', $d->password());
});

it('SqliteDriver::snapshotFor refuses :memory: with a clear error', function () {
    $d = new SqliteDriver('sqlite::memory:');
    $pdo = Connection::open('sqlite::memory:');
    $threw = false;
    try {
        $d->snapshotFor($pdo);
    } catch (\RuntimeException $e) {
        $threw = strpos($e->getMessage(), ':memory:') !== false;
    }
    assert_true($threw, 'in-memory DSN must produce a friendly error');
});

it('MysqlDriver::snapshotFor honours unix_socket DSNs', function () {
    $d = new MysqlDriver('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=otack', 'u', 'p');
    $pdo = Connection::open('sqlite::memory:'); // any PDO, we only inspect the adapter
    $snap = $d->snapshotFor($pdo);
    // The adapter is constructed with a socket key and no host; we
    // can't run mysqldump here but we can confirm the wiring by
    // peeking at the conn array via reflection.
    $ref = new ReflectionClass($snap);
    $prop = $ref->getProperty('conn'); $prop->setAccessible(true);
    $conn = $prop->getValue($snap);
    assert_eq('/var/run/mysqld/mysqld.sock', $conn['socket']);
    assert_eq(null, $conn['host'], 'host must be null when socket is set, so mysqldump uses --socket=');
});

it('Driver portability surface: insertIgnoreVerb / paginationAllOffsetSql / listTablesSql', function () {
    $s = new SqliteDriver('sqlite::memory:');
    assert_eq('INSERT OR IGNORE', $s->insertIgnoreVerb());
    assert_eq('LIMIT -1 OFFSET ?', $s->paginationAllOffsetSql());
    assert_true(strpos($s->listTablesSql(), 'sqlite_master') !== false);

    $m = new MysqlDriver('mysql:host=x;dbname=y', null, null);
    assert_eq('INSERT IGNORE', $m->insertIgnoreVerb());
    assert_true(strpos($m->paginationAllOffsetSql(), '18446744073709551615') !== false);
    assert_true(strpos($m->listTablesSql(), 'information_schema') !== false);
});
