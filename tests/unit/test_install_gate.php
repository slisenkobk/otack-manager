<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Service\InstallGate;
use App\Repository\UserRepository;
use App\Repository\SettingsRepository;

function install_gate_fresh_pdo(): \PDO {
    $pdo = Connection::open('sqlite::memory:');
    $pdo->exec("CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL,
        status TEXT NOT NULL,
        locale TEXT,
        avatar TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    return $pdo;
}

it('isInstallRequired true on empty DB', function () {
    $pdo = install_gate_fresh_pdo();
    assert_true(InstallGate::isInstallRequired($pdo));
});

it('isInstallRequired false once an approved admin exists', function () {
    $pdo = install_gate_fresh_pdo();
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status) "
             . "VALUES ('Admin', 'a@b.com', 'hash', 'admin', 'approved')");
    assert_true(!InstallGate::isInstallRequired($pdo));
});

it('isInstallRequired false once installed_at is set, even with no admin', function () {
    $pdo = install_gate_fresh_pdo();
    $pdo->exec("INSERT INTO settings (key, value) VALUES ('installed_at', '2026-06-04T12:00:00Z')");
    assert_true(!InstallGate::isInstallRequired($pdo));
});

it('isInstallRequired true with pending non-admin only', function () {
    $pdo = install_gate_fresh_pdo();
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status) "
             . "VALUES ('P', 'p@x', 'h', 'employee', 'pending')");
    assert_true(InstallGate::isInstallRequired($pdo));
});

it('UserRepository::countApprovedAdmins counts only approved admins', function () {
    $pdo = install_gate_fresh_pdo();
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status) VALUES
        ('A', 'a1@x', 'h', 'admin', 'approved'),
        ('B', 'a2@x', 'h', 'admin', 'pending'),
        ('C', 'm@x',  'h', 'manager', 'approved'),
        ('D', 'a3@x', 'h', 'admin', 'approved')");
    $repo = new UserRepository($pdo);
    assert_eq(2, $repo->countApprovedAdmins());
});
