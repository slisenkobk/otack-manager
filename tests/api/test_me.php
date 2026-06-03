<?php
api_it('GET /api/v1/me returns user identity', function () {
    // Warm-up request ensures the test server has booted index.php and applied
    // migrations before we touch the DB directly (test files run alphabetically,
    // so test_me may execute before test_ping).
    api_request('GET', '/api/v1/ping');
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'active', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $row = $pdo->query("SELECT id FROM users WHERE email='u@x'")->fetch();
    $t = $repo->create((int)$row['id'], 'me-test');
    $r = api_request('GET', '/api/v1/me', ['headers' => ['Authorization: Bearer ' . $t['token']]]);
    assert_eq(200, $r['status']);
    assert_eq((int)$row['id'], $r['json']['id']);
    assert_eq('u@x', $r['json']['email']);
    assert_true(in_array($r['json']['role'], ['admin','manager','employee'], true));
});
