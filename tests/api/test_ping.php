<?php
api_it('GET /api/v1/ping requires auth', function () {
    $r = api_request('GET', '/api/v1/ping');
    assert_eq(401, $r['status']);
    assert_eq('unauthorized', $r['json']['error']);
});

api_it('GET /api/v1/ping with bad token returns 401', function () {
    $r = api_request('GET', '/api/v1/ping', ['headers' => ['Authorization: Bearer otk_nope']]);
    assert_eq(401, $r['status']);
});

api_it('GET /api/v1/ping with valid token returns 200 + user id', function () {
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'active', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $t = $repo->create(1, 'test');
    $r = api_request('GET', '/api/v1/ping', ['headers' => ['Authorization: Bearer ' . $t['token']]]);
    assert_eq(200, $r['status']);
    assert_eq(true, $r['json']['ok']);
    assert_eq(1, $r['json']['user_id']);
});
