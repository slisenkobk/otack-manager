<?php
declare(strict_types=1);

/**
 * Unit tests for the install-wizard additions to UserRepository:
 *   - createAdmin(name, email, hash)
 *   - firstApprovedAdmin()
 *
 * The wizard calls these AFTER the schema is up but possibly BEFORE
 * the normal `create()` (which auto-promotes the first user) has run.
 */

use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Repository\UserRepository;

function _install_users_db(): array {
    $tmpDb = sys_get_temp_dir() . '/otack-users-install-' . uniqid() . '.sqlite';
    $pdo = Connection::open($tmpDb);
    Migrations::run(new SchemaBootstrap($pdo));
    return [$pdo, $tmpDb];
}

function _install_cleanup(string $db): void {
    @unlink($db);
    @unlink($db . '-wal');
    @unlink($db . '-shm');
}

it('createAdmin inserts an admin/approved user regardless of order', function () {
    [$pdo, $db] = _install_users_db();
    $repo = new UserRepository($pdo);
    // Pre-seed two unrelated users so the wizard insert can't rely on
    // "first ever = admin".
    $repo->create('a@x', 'h', 'A');
    $repo->create('b@x', 'h', 'B');
    $id = $repo->createAdmin('Wizard Admin', 'wiz@x.com', 'hashhash');
    assert_true($id > 0);
    $row = $repo->findById($id);
    assert_eq('admin',    $row['role']);
    assert_eq('approved', $row['status']);
    assert_eq('Wizard Admin', $row['name']);
    assert_eq('wiz@x.com',    $row['email']);
    assert_eq('hashhash',     $row['password_hash']);
    _install_cleanup($db);
});

it('createAdmin throws on duplicate email', function () {
    [$pdo, $db] = _install_users_db();
    $repo = new UserRepository($pdo);
    $repo->createAdmin('A', 'dupe@x.com', 'h1');
    $threw = false;
    try {
        $repo->createAdmin('B', 'dupe@x.com', 'h2');
    } catch (\Throwable $e) {
        $threw = true;
    }
    assert_true($threw, 'expected unique-email violation');
    _install_cleanup($db);
});

it('firstApprovedAdmin returns null on empty users table', function () {
    [$pdo, $db] = _install_users_db();
    $repo = new UserRepository($pdo);
    assert_eq(null, $repo->firstApprovedAdmin());
    _install_cleanup($db);
});

it('firstApprovedAdmin returns the earliest approved admin', function () {
    [$pdo, $db] = _install_users_db();
    $repo = new UserRepository($pdo);
    // createAdmin is order-independent; insert two and expect the older one.
    $id1 = $repo->createAdmin('First Admin',  'a1@x', 'h');
    usleep(10000);
    $id2 = $repo->createAdmin('Second Admin', 'a2@x', 'h');
    $row = $repo->firstApprovedAdmin();
    assert_true($row !== null);
    assert_eq('a1@x', $row['email']);
    assert_eq($id1, (int)$row['id']);
    // Sanity: id2 wasn't picked.
    assert_true($id1 !== $id2);
    _install_cleanup($db);
});

it('firstApprovedAdmin ignores pending admins', function () {
    [$pdo, $db] = _install_users_db();
    $repo = new UserRepository($pdo);
    // A pending admin (role=admin, status=pending) — with an OLD created_at
    // so it would win the ORDER BY if status filter were missing.
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status, created_at) "
             . "VALUES ('Pending Admin', 'pend@x', 'h', 'admin', 'pending', '2020-01-01T00:00:00Z')");
    $approvedId = $repo->createAdmin('Real Admin', 'real@x', 'h');
    $row = $repo->firstApprovedAdmin();
    assert_true($row !== null);
    assert_eq('real@x', $row['email']);
    assert_eq($approvedId, (int)$row['id']);
    _install_cleanup($db);
});
