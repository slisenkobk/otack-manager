<?php
use App\Api\V1\RateLimiter;

function _rlPdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260603_050_api_rate_limits.php');
    return $pdo;
}

it('first hit returns allowed with retryAfter=0', function () {
    $rl = new RateLimiter(_rlPdo(), max: 3, windowSeconds: 60);
    $r = $rl->check(42);
    assert_true($r['allowed']);
    assert_eq(0, $r['retry_after']);
    assert_eq(1, $r['count']);
});

it('counts increment within the same window', function () {
    $rl = new RateLimiter(_rlPdo(), max: 3, windowSeconds: 60);
    $rl->check(7); $rl->check(7);
    $r = $rl->check(7);
    assert_true($r['allowed']);
    assert_eq(3, $r['count']);
});

it('returns retry_after with allowed=false on overflow', function () {
    $rl = new RateLimiter(_rlPdo(), max: 2, windowSeconds: 60);
    $rl->check(1); $rl->check(1);
    $r = $rl->check(1);
    assert_true(!$r['allowed']);
    assert_true($r['retry_after'] > 0 && $r['retry_after'] <= 60);
});

it('resets after the window rolls', function () {
    $pdo = _rlPdo();
    $rl = new RateLimiter($pdo, max: 1, windowSeconds: 60);
    $rl->check(5);
    // Force the window to look stale
    $pdo->prepare('UPDATE api_rate_limits SET window_start = ? WHERE token_id = ?')
        ->execute([time() - 61, 5]);
    $r = $rl->check(5);
    assert_true($r['allowed']);
    assert_eq(1, $r['count']);
});

it('isolates tokens', function () {
    $rl = new RateLimiter(_rlPdo(), max: 1, windowSeconds: 60);
    $rl->check(1);
    $r = $rl->check(2);
    assert_true($r['allowed']);
});

it('enforces exact count-equals-max boundary in a tight loop', function () {
    // Single-threaded: 65 calls against max=60, windowSeconds=60.
    // Expect exactly 60 allowed=true, 5 allowed=false. This catches the
    // off-by-one boundary AND ensures the atomic UPSERT counts each
    // call exactly once.
    $rl = new RateLimiter(_rlPdo(), max: 60, windowSeconds: 60);
    $allowed = 0;
    $blocked = 0;
    for ($i = 0; $i < 65; $i++) {
        $r = $rl->check(99);
        if ($r['allowed']) $allowed++;
        else                $blocked++;
    }
    assert_eq(60, $allowed);
    assert_eq(5,  $blocked);
});
