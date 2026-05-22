<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Database\SchemaBootstrap;

it('Connection returns a PDO with foreign keys on', function () {
    $tmp = sys_get_temp_dir() . '/otack-test-' . uniqid() . '.sqlite';
    $pdo = Connection::open($tmp);
    $row = $pdo->query('PRAGMA foreign_keys')->fetch();
    assert_eq(1, (int)$row['foreign_keys']);
    unlink($tmp);
});

it('SchemaBootstrap runs migration once', function () {
    $tmp = sys_get_temp_dir() . '/otack-db-' . uniqid() . '.sqlite';
    $markerDir = sys_get_temp_dir() . '/otack-markers-' . uniqid();
    mkdir($markerDir, 0755, true);
    $pdo = Connection::open($tmp);
    $boot = new SchemaBootstrap($pdo, $markerDir);
    $runs = 0;
    $boot->ensure('foo', 1, function (\PDO $p) use (&$runs) {
        $p->query('CREATE TABLE foo (id INTEGER PRIMARY KEY)');
        $runs++;
    });
    $boot->ensure('foo', 1, function () use (&$runs) { $runs++; });
    assert_eq(1, $runs);
    // cleanup
    @unlink($tmp);
    foreach (glob($markerDir . '/*') as $f) @unlink($f);
    @rmdir($markerDir);
});
