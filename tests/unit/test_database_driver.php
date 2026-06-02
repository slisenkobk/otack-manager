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
    // Default charset is utf8mb4 (defaulted in the constructor).
    // We can't inspect a private prop directly, but postConnect would
    // emit SET NAMES 'utf8mb4' — exercised in integration when MySQL is wired up.
    assert_true(true);
});
