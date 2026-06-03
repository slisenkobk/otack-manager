<?php
use App\Auth\LoginThrottle;

function _ltPdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260604_000_login_attempts.php');
    return $pdo;
}

it('isThrottled returns false on fresh state', function () {
    $t = new LoginThrottle(_ltPdo(), max: 5, windowSeconds: 900);
    assert_true(!$t->isThrottled('a@b.com'));
});

it('throttles at the configured max', function () {
    $t = new LoginThrottle(_ltPdo(), max: 3, windowSeconds: 900);
    for ($i = 0; $i < 3; $i++) {
        assert_true(!$t->isThrottled('a@b.com'));
        $t->recordFail('a@b.com');
    }
    assert_true($t->isThrottled('a@b.com'));
});

it('resetFails clears the counter', function () {
    $t = new LoginThrottle(_ltPdo(), max: 3, windowSeconds: 900);
    $t->recordFail('a@b.com'); $t->recordFail('a@b.com'); $t->recordFail('a@b.com');
    assert_true($t->isThrottled('a@b.com'));
    $t->resetFails('a@b.com');
    assert_true(!$t->isThrottled('a@b.com'));
});

it('window expires reset the counter', function () {
    $pdo = _ltPdo();
    $t = new LoginThrottle($pdo, max: 3, windowSeconds: 60);
    for ($i = 0; $i < 3; $i++) $t->recordFail('a@b.com');
    assert_true($t->isThrottled('a@b.com'));
    $pdo->prepare('UPDATE login_attempts SET window_start = ? WHERE key_hash = ?')
        ->execute([time() - 61, hash('sha256', strtolower('a@b.com'))]);
    assert_true(!$t->isThrottled('a@b.com'));
});

it('isolates different emails (key collisions)', function () {
    $t = new LoginThrottle(_ltPdo(), max: 2, windowSeconds: 900);
    $t->recordFail('a@b.com'); $t->recordFail('a@b.com');
    assert_true($t->isThrottled('a@b.com'));
    assert_true(!$t->isThrottled('c@d.com'));
});

it('email is lowercased + trimmed before hashing', function () {
    $pdo = _ltPdo();
    $t = new LoginThrottle($pdo, max: 100, windowSeconds: 900);
    $t->recordFail('  A@B.com  ');
    $row = $pdo->query('SELECT key_hash FROM login_attempts')->fetch();
    assert_eq(hash('sha256', 'a@b.com'), $row['key_hash']);
});
