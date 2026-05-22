<?php
use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Repository\UserRepository;

function _new_users_db(): array {
    $tmpDb = sys_get_temp_dir() . '/otack-users-' . uniqid() . '.sqlite';
    $tmpMark = sys_get_temp_dir() . '/otack-mark-' . uniqid();
    mkdir($tmpMark, 0755, true);
    $pdo = Connection::open($tmpDb);
    Migrations::run(new SchemaBootstrap($pdo, $tmpMark));
    return [$pdo, $tmpDb, $tmpMark];
}
function _cleanup(string $db, string $mark): void {
    @unlink($db);
    foreach (glob($mark . '/*') as $f) @unlink($f);
    @rmdir($mark);
}

it('findByEmail returns null when missing', function () {
    [$pdo, $db, $mark] = _new_users_db();
    $repo = new UserRepository($pdo);
    assert_eq(null, $repo->findByEmail('nope@example.com'));
    _cleanup($db, $mark);
});

it('first-ever user is admin/approved, subsequent are member/pending', function () {
    [$pdo, $db, $mark] = _new_users_db();
    $repo = new UserRepository($pdo);
    $id1 = $repo->create('a@x', 'h1', 'A');
    $id2 = $repo->create('b@x', 'h2', 'B');
    $u1 = $repo->findById($id1);
    $u2 = $repo->findById($id2);
    assert_eq('admin', $u1['role']);
    assert_eq('approved', $u1['status']);
    assert_eq('member', $u2['role']);
    assert_eq('pending', $u2['status']);
    _cleanup($db, $mark);
});

it('approve/block/setRole work', function () {
    [$pdo, $db, $mark] = _new_users_db();
    $repo = new UserRepository($pdo);
    $repo->create('admin@x', 'h', 'Admin'); // first is admin
    $id = $repo->create('u@x', 'h', 'U');
    $repo->approve($id);
    assert_eq('approved', $repo->findById($id)['status']);
    $repo->block($id);
    assert_eq('blocked', $repo->findById($id)['status']);
    $repo->setRole($id, 'admin');
    assert_eq('admin', $repo->findById($id)['role']);
    _cleanup($db, $mark);
});

it('listAll ordered by created_at DESC', function () {
    [$pdo, $db, $mark] = _new_users_db();
    $repo = new UserRepository($pdo);
    $repo->create('a@x', 'h', 'A');
    usleep(10000);
    $repo->create('b@x', 'h', 'B');
    $list = $repo->listAll();
    assert_eq('b@x', $list[0]['email']);
    assert_eq('a@x', $list[1]['email']);
    _cleanup($db, $mark);
});
