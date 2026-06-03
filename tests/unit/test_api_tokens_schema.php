<?php
function _atSchemaPdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    // Default to FK OFF so tests that exercise schema in isolation (without the
    // users table) can insert rows freely; the cascade test explicitly re-enables.
    $pdo->exec('PRAGMA foreign_keys = OFF');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260603_040_api_tokens.php');
    return $pdo;
}

it('api_tokens table has expected columns', function () {
    $pdo = _atSchemaPdo();
    $cols = $pdo->query("PRAGMA table_info('api_tokens')")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_column($cols, 'name');
    foreach (['id','user_id','name','token_hash','prefix','last_used_at','last_used_ip','created_at','expires_at','revoked_at'] as $expected) {
        assert_true(in_array($expected, $names, true), "missing column $expected (got: " . implode(',', $names) . ')');
    }
});

it('api_tokens.token_hash is unique', function () {
    $pdo = _atSchemaPdo();
    $now = time();
    $stmt = $pdo->prepare('INSERT INTO api_tokens (user_id, name, token_hash, prefix, created_at) VALUES (?,?,?,?,?)');
    $stmt->execute([1, 'a', str_repeat('a', 64), 'otk_aaaaaaaa', $now]);
    $threw = false;
    try { $stmt->execute([1, 'b', str_repeat('a', 64), 'otk_aaaaaaaa', $now]); }
    catch (\PDOException $_) { $threw = true; }
    assert_true($threw, 'duplicate token_hash should throw');
});

it('api_tokens cascades on user delete', function () {
    $pdo = _atSchemaPdo();
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_000_users.php');
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("INSERT INTO users (name, email, password_hash, role, status, created_at) VALUES ('U','u@x','x','admin','active','2026-01-01')");
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO api_tokens (user_id, name, token_hash, prefix, created_at) VALUES (?,?,?,?,?)')
        ->execute([$uid, 'a', str_repeat('a', 64), 'otk_aaaaaaaa', time()]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    $remaining = (int)$pdo->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn();
    assert_eq(0, $remaining, 'tokens should be deleted with user');
});

it('api_rate_limits table has expected columns', function () {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260603_050_api_rate_limits.php');
    $cols = array_column($pdo->query("PRAGMA table_info('api_rate_limits')")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['token_id','window_start','count'] as $c) {
        assert_true(in_array($c, $cols, true), "missing $c");
    }
});
