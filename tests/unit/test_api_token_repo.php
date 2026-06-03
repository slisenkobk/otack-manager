<?php
use App\Repository\ApiTokenRepository;

function _atPdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_000_users.php');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260603_040_api_tokens.php');
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status, created_at) VALUES ('U','u@x','x','admin','active','2026-01-01')");
    return $pdo;
}

it('generate() yields otk_ + base62, >= 40 chars, fresh each call', function () {
    $a = ApiTokenRepository::generate();
    $b = ApiTokenRepository::generate();
    assert_true(str_starts_with($a, 'otk_'));
    assert_true(strlen($a) >= 40);
    assert_true((bool)preg_match('/^otk_[A-Za-z0-9]+$/', $a));
    assert_true($a !== $b);
});

it('hash() is deterministic sha256 hex (64 chars)', function () {
    $h = ApiTokenRepository::hash('otk_test');
    assert_eq(64, strlen($h));
    assert_eq($h, ApiTokenRepository::hash('otk_test'));
});

it('create() stores hashed token, returns id + plaintext only once', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $res = $repo->create(1, 'CI');
    assert_true($res['id'] > 0);
    assert_true(str_starts_with($res['token'], 'otk_'));
    $row = $repo->findById($res['id']);
    assert_eq(ApiTokenRepository::hash($res['token']), $row['token_hash']);
    assert_eq('CI', $row['name']);
});

it('findActiveByToken() returns the row for a valid plaintext token', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $res  = $repo->create(1, 'CI');
    $row  = $repo->findActiveByToken($res['token']);
    assert_true($row !== null);
    assert_eq($res['id'], (int)$row['id']);
});

it('findActiveByToken() returns null for unknown token', function () {
    $repo = new ApiTokenRepository(_atPdo());
    assert_eq(null, $repo->findActiveByToken('otk_nope'));
    assert_eq(null, $repo->findActiveByToken(''));
    assert_eq(null, $repo->findActiveByToken('not-our-prefix'));
});

it('findActiveByToken() returns null when revoked or expired', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $a = $repo->create(1, 'A');
    $b = $repo->create(1, 'B', time() - 10);   // already expired
    $repo->revoke($a['id']);
    assert_eq(null, $repo->findActiveByToken($a['token']));
    assert_eq(null, $repo->findActiveByToken($b['token']));
});

it('listForUser() returns user tokens ordered by created_at DESC', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $repo->create(1, 'old');
    usleep(1100);
    $repo->create(1, 'new');
    $list = $repo->listForUser(1);
    assert_eq(2, count($list));
    assert_eq('new', $list[0]['name']);
});

it('revoke() is idempotent and sets revoked_at', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $t = $repo->create(1, 'x');
    $repo->revoke($t['id']);
    $row = $repo->findById($t['id']);
    assert_true($row['revoked_at'] !== null);
    $first = (int)$row['revoked_at'];
    $repo->revoke($t['id']);   // second call must not overwrite
    assert_eq($first, (int)$repo->findById($t['id'])['revoked_at']);
});

it('revokeAllForUser() revokes only the targeted user', function () {
    $pdo  = _atPdo();
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status, created_at) VALUES ('V','v@x','x','employee','active','2026-01-01')");
    $repo = new ApiTokenRepository($pdo);
    $repo->create(1, 'a');
    $repo->create(2, 'b');
    $repo->revokeAllForUser(1);
    $active = $repo->listActiveForUser(1);
    assert_eq(0, count($active));
    assert_eq(1, count($repo->listActiveForUser(2)));
});

it('touchUsage() updates last_used_at + last_used_ip', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $t = $repo->create(1, 'x');
    $repo->touchUsage($t['id'], '203.0.113.7');
    $row = $repo->findById($t['id']);
    assert_eq('203.0.113.7', $row['last_used_ip']);
    assert_true((int)$row['last_used_at'] > 0);
});

it('prefix is "otk_" + first 8 chars of random suffix', function () {
    $repo = new ApiTokenRepository(_atPdo());
    $t = $repo->create(1, 'x');
    $row = $repo->findById($t['id']);
    assert_eq(substr($t['token'], 0, 12), $row['prefix']);
});
