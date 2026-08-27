<?php
api_it('GET /projects lists visible projects with cursor', function () {
    // Warm-up so migrations run before we touch the DB.
    api_request('GET', '/api/v1/ping');
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'approved', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $row = $pdo->query("SELECT id FROM users WHERE email='u@x'")->fetch();
    $uid = (int)$row['id'];
    $pdo->exec("INSERT OR IGNORE INTO projects (id, name, slug, color, status, created_by, created_at, updated_at) VALUES (101, 'Alpha', 'alpha', '#fff', 'active', $uid, '2026-01-01', '2026-01-01')");
    $t = $repo->create($uid, 'proj-read');

    $r = api_request('GET', '/api/v1/projects', ['headers' => ['Authorization: Bearer ' . $t['token']]]);
    assert_eq(200, $r['status']);
    assert_true(array_key_exists('items', $r['json']));
    assert_true(array_key_exists('next_cursor', $r['json']));
    $ids = array_column($r['json']['items'], 'id');
    assert_true(in_array(101, $ids, true), 'newly-created project should appear in the list');
});

api_it('GET /projects/{id} returns 404 for non-existent', function () {
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'approved', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $uid = (int)$pdo->query("SELECT id FROM users WHERE email='u@x'")->fetch()['id'];
    $t = $repo->create($uid, 'proj-404');
    $r = api_request('GET', '/api/v1/projects/999999', ['headers' => ['Authorization: Bearer ' . $t['token']]]);
    assert_eq(404, $r['status']);
});

api_it('GET /projects/{id} returns 404 for project the caller cannot see (employee not a member)', function () {
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    // Insert a second user (employee, not admin) — the existing seed user 'u@x' is admin in our test DB
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (2, 'E', 'e@x', 'x', 'employee', 'approved', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO projects (id, name, slug, color, status, created_by, created_at, updated_at) VALUES (202, 'Hidden', 'hidden-202', '#fff', 'active', 1, '2026-01-01', '2026-01-01')");
    $t = $repo->create(2, 'proj-hidden');
    $r = api_request('GET', '/api/v1/projects/202', ['headers' => ['Authorization: Bearer ' . $t['token']]]);
    assert_eq(404, $r['status']);   // hidden = 404, not 403, per spec (don't leak existence)
});

api_it('GET /projects/{id}: exposes description and column flags', function () {
    api_request('GET', '/api/v1/ping');   // warm-up so migrations run
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'approved', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $tok = $repo->create(1, 'proj-agent-' . bin2hex(random_bytes(3)))['token'];

    $pdo->exec("INSERT OR IGNORE INTO projects (id, name, slug, description, color, status, created_by, created_at, updated_at) VALUES (5600, 'D', 'p-desc', 'Project brief text', '#fff', 'active', 1, '2026-01-01', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO task_columns (id, project_id, name, color, position, is_done, is_backlog) VALUES (56001, 5600, 'Done', '#fff', 2, 1, 0)");

    $r = api_request('GET', '/api/v1/projects/5600', [
        'headers' => ['Authorization: Bearer ' . $tok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq('Project brief text', $r['json']['description']);
    assert_eq(true, $r['json']['columns'][0]['is_done']);
    assert_eq(false, $r['json']['columns'][0]['is_backlog']);
});
