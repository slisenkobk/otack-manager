<?php
function _laSchemaPdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260604_000_login_attempts.php');
    return $pdo;
}

it('login_attempts table has expected columns', function () {
    $pdo = _laSchemaPdo();
    $cols = array_column($pdo->query("PRAGMA table_info('login_attempts')")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['key_hash', 'window_start', 'count'] as $c) {
        assert_true(in_array($c, $cols, true), "missing column $c");
    }
});

it('login_attempts.key_hash is the primary key', function () {
    $pdo = _laSchemaPdo();
    $stmt = $pdo->prepare('INSERT INTO login_attempts (key_hash, window_start, count) VALUES (?, ?, ?)');
    $stmt->execute([str_repeat('a', 64), time(), 1]);
    $threw = false;
    try { $stmt->execute([str_repeat('a', 64), time(), 2]); }
    catch (\PDOException $_) { $threw = true; }
    assert_true($threw, 'duplicate key_hash should throw');
});
