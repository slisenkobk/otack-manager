<?php
use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Repository\UserRepository;
use App\Auth\PasswordHasher;
use App\Auth\AuthManager;

function _new_auth_setup(): array {
    $tmpDb = sys_get_temp_dir() . '/otack-auth-' . uniqid() . '.sqlite';
    $pdo = Connection::open($tmpDb);
    Migrations::run(new SchemaBootstrap($pdo));
    $session = [];
    $repo = new UserRepository($pdo);
    $hasher = new PasswordHasher();
    $throttle = new \App\Auth\LoginThrottle($pdo);
    $auth = new AuthManager($repo, $hasher, $session, $throttle);
    return [$auth, $repo, $hasher, &$session, $tmpDb, $pdo];
}

it('login with wrong password returns null and increments fails', function () {
    $setup = _new_auth_setup();
    [$auth, $repo, $hasher] = $setup;
    $pdo = $setup[5];
    $repo->create('a@x', $hasher->hash('correct'), 'A');
    assert_eq(null, $auth->login('a@x', 'wrong'));
    $count = (int)$pdo->query('SELECT count FROM login_attempts')->fetchColumn();
    assert_true($count >= 1, "expected at least 1 fail recorded, got $count");
    @unlink($setup[4]);
});

it('5 fails within 15min returns throttled', function () {
    $setup = _new_auth_setup();
    [$auth, $repo, $hasher] = $setup;
    $repo->create('a@x', $hasher->hash('correct'), 'A');
    for ($i = 0; $i < 5; $i++) $auth->login('a@x', 'wrong');
    assert_eq('throttled', $auth->login('a@x', 'wrong'));
    @unlink($setup[4]);
});

it('login on pending user returns pending', function () {
    $setup = _new_auth_setup();
    [$auth, $repo, $hasher] = $setup;
    $repo->create('admin@x', $hasher->hash('x'), 'A'); // first = admin/approved
    $repo->create('u@x', $hasher->hash('correct'), 'U'); // second = pending
    assert_eq('pending', $auth->login('u@x', 'correct'));
    @unlink($setup[4]);
});

it('valid + approved login returns user array and touches last_login', function () {
    $setup = _new_auth_setup();
    [$auth, $repo, $hasher] = $setup;
    $repo->create('admin@x', $hasher->hash('p'), 'Admin'); // first = admin/approved
    $result = $auth->login('admin@x', 'p');
    assert_true(is_array($result));
    assert_eq('admin@x', $result['email']);
    $fresh = $repo->findById((int)$result['id']);
    assert_true($fresh['last_login_at'] !== null);
    @unlink($setup[4]);
});
