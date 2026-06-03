<?php
use App\Api\V1\TokenAuthenticator;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;

function _authPdo(): array {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_000_users.php');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260603_040_api_tokens.php');
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status, created_at) VALUES ('U','u@x','x','employee','active','2026-01-01')");
    return [$pdo, new ApiTokenRepository($pdo), new UserRepository($pdo)];
}

it('returns null for missing header', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate(null));
});

it('returns null for non-Bearer header', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate('Basic abc'));
});

it('returns null for unknown token', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate('Bearer otk_nope'));
});

it('returns user row + token row for a valid token', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $t = $tokens->create(1, 'x');
    $auth = new TokenAuthenticator($tokens, $users);
    $ctx = $auth->authenticate('Bearer ' . $t['token']);
    assert_true($ctx !== null);
    assert_eq(1, (int)$ctx['user']['id']);
    assert_eq($t['id'], (int)$ctx['token']['id']);
});

it('returns null for revoked token', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $t = $tokens->create(1, 'x');
    $tokens->revoke($t['id']);
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate('Bearer ' . $t['token']));
});

it('returns null when user has been blocked', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $t = $tokens->create(1, 'x');
    $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['blocked', 1]);
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate('Bearer ' . $t['token']));
});

it('accepts user with production status = approved', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $t = $tokens->create(1, 'x');
    // Production UserRepository uses 'approved', not 'active'. API auth must match.
    $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['approved', 1]);
    $auth = new TokenAuthenticator($tokens, $users);
    $ctx = $auth->authenticate('Bearer ' . $t['token']);
    assert_true($ctx !== null);
    assert_eq(1, (int)$ctx['user']['id']);
});

it('rejects user with status = pending', function () {
    [$pdo, $tokens, $users] = _authPdo();
    $t = $tokens->create(1, 'x');
    $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['pending', 1]);
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate('Bearer ' . $t['token']));
});

it('rejects user with unknown status (defensive)', function () {
    // Anything outside the two-element allowlist ('approved'|'active') must
    // be rejected — protects against future status values that haven't
    // been threaded through the auth path.
    [$pdo, $tokens, $users] = _authPdo();
    $t = $tokens->create(1, 'x');
    $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['disabled', 1]);
    $auth = new TokenAuthenticator($tokens, $users);
    assert_eq(null, $auth->authenticate('Bearer ' . $t['token']));
});
