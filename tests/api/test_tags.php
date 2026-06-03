<?php
// Integration tests for /api/v1/tags endpoints.
// Uses ids 7000+ to isolate from earlier fixtures.

/** @return array{0:\PDO,1:string,2:string} pdo, adminTok, empTok */
function tags_setup(): array {
    api_request('GET', '/api/v1/ping');
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'approved', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (2, 'E', 'e@x', 'x', 'employee', 'approved', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $adminTok = $repo->create(1, 'tags-admin-' . bin2hex(random_bytes(3)))['token'];
    $empTok   = $repo->create(2, 'tags-emp-'   . bin2hex(random_bytes(3)))['token'];
    return [$pdo, $adminTok, $empTok];
}

function tags_seed_project(\PDO $pdo, int $id, int $createdBy = 1): void {
    $pdo->prepare("INSERT OR IGNORE INTO projects (id, name, slug, color, status, created_by, created_at, updated_at) VALUES (?, ?, ?, '#fff', 'active', ?, '2026-01-01', '2026-01-01')")
        ->execute([$id, 'P-' . $id, 'p-tags-' . $id, $createdBy]);
}

function tags_seed_column(\PDO $pdo, int $id, int $projectId): void {
    $pdo->prepare("INSERT OR IGNORE INTO task_columns (id, project_id, name, color, position, is_done, is_backlog) VALUES (?, ?, 'To Do', '#fff', 0, 0, 0)")
        ->execute([$id, $projectId]);
}

function tags_seed_task(\PDO $pdo, int $id, int $projectId, int $columnId, int $createdBy = 1): void {
    $pdo->prepare("INSERT OR IGNORE INTO tasks (id, project_id, column_id, title, description, position, assignee_id, due_date, created_by, created_at, updated_at) VALUES (?, ?, ?, 'T', NULL, 1024, NULL, NULL, ?, '2026-01-01', '2026-01-01')")
        ->execute([$id, $projectId, $columnId, $createdBy]);
}

function tags_seed_tag(\PDO $pdo, int $id, string $scope = 'task', string $name = 'urgent', string $color = '#f00'): void {
    $pdo->prepare("INSERT OR IGNORE INTO tags (id, scope, name, color) VALUES (?, ?, ?, ?)")
        ->execute([$id, $scope, $name, $color]);
}

api_it('GET /projects/{id}/tags as member returns list', function () {
    [$pdo, $adminTok,] = tags_setup();
    tags_seed_project($pdo, 7001);
    tags_seed_tag($pdo, 7900, 'project', 'alpha', '#0f0');
    $pdo->prepare("INSERT OR IGNORE INTO project_tag_map (project_id, tag_id) VALUES (?, ?)")->execute([7001, 7900]);

    $r = api_request('GET', '/api/v1/projects/7001/tags', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    $names = array_column($r['json']['items'], 'name');
    assert_true(in_array('alpha', $names, true), 'expected attached tag in list');
});

api_it('GET /projects/{id}/tags as non-member returns 404', function () {
    [$pdo,, $empTok] = tags_setup();
    tags_seed_project($pdo, 7002, 1); // owned by admin, no employee membership

    $r = api_request('GET', '/api/v1/projects/7002/tags', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(404, $r['status']);
});

api_it('GET /tags as admin returns list', function () {
    [$pdo, $adminTok,] = tags_setup();
    tags_seed_tag($pdo, 7901, 'task', 'beta', '#abc');

    $r = api_request('GET', '/api/v1/tags', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    $names = array_column($r['json']['items'], 'name');
    assert_true(in_array('beta', $names, true), 'expected seeded tag in global list');
});

api_it('GET /tags as employee returns 403', function () {
    [,, $empTok] = tags_setup();

    $r = api_request('GET', '/api/v1/tags', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(403, $r['status']);
});

api_it('POST /tags as admin creates global tag', function () {
    [, $adminTok,] = tags_setup();

    $r = api_request('POST', '/api/v1/tags', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['name' => 'urgent-' . bin2hex(random_bytes(3)), 'color' => '#f00']),
    ]);
    assert_eq(201, $r['status']);
    assert_eq('#f00', $r['json']['color']);
    assert_true((int)$r['json']['id'] > 0, 'expected tag id');
});

api_it('POST /tags as employee returns 403', function () {
    [,, $empTok] = tags_setup();

    $r = api_request('POST', '/api/v1/tags', [
        'headers' => ['Authorization: Bearer ' . $empTok],
        'body'    => json_encode(['name' => 'nope']),
    ]);
    assert_eq(403, $r['status']);
});

api_it('POST /projects/{id}/tags admin attaches; GET shows it', function () {
    [$pdo, $adminTok,] = tags_setup();
    tags_seed_project($pdo, 7003);
    tags_seed_tag($pdo, 7910, 'project', 'gamma', '#111');

    $r = api_request('POST', '/api/v1/projects/7003/tags', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['tag_id' => 7910]),
    ]);
    assert_eq(201, $r['status']);

    $g = api_request('GET', '/api/v1/projects/7003/tags', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $g['status']);
    $names = array_column($g['json']['items'], 'name');
    assert_true(in_array('gamma', $names, true), 'expected attached tag after POST');
});

api_it('DELETE /projects/{id}/tags/{tag_id} returns 204', function () {
    [$pdo, $adminTok,] = tags_setup();
    tags_seed_project($pdo, 7004);
    tags_seed_tag($pdo, 7920, 'project', 'delta', '#222');
    $pdo->prepare("INSERT OR IGNORE INTO project_tag_map (project_id, tag_id) VALUES (?, ?)")->execute([7004, 7920]);

    $r = api_request('DELETE', '/api/v1/projects/7004/tags/7920', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(204, $r['status']);

    $g = api_request('GET', '/api/v1/projects/7004/tags', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    $names = array_column($g['json']['items'], 'name');
    assert_true(!in_array('delta', $names, true), 'expected tag detached');
});

api_it('POST /tasks/{id}/tags admin attaches; GET /tasks/{id} shows it', function () {
    [$pdo, $adminTok,] = tags_setup();
    tags_seed_project($pdo, 7005);
    tags_seed_column($pdo, 70501, 7005);
    tags_seed_task($pdo, 7100, 7005, 70501, 1);
    tags_seed_tag($pdo, 7930, 'task', 'epsilon', '#333');

    $r = api_request('POST', '/api/v1/tasks/7100/tags', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['tag_id' => 7930]),
    ]);
    assert_eq(201, $r['status']);

    $g = api_request('GET', '/api/v1/tasks/7100', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $g['status']);
    $names = array_column($g['json']['tags'], 'name');
    assert_true(in_array('epsilon', $names, true), 'expected tag in task.tags[]');
});

api_it('DELETE /tasks/{id}/tags/{tag_id} returns 204', function () {
    [$pdo, $adminTok,] = tags_setup();
    tags_seed_project($pdo, 7006);
    tags_seed_column($pdo, 70601, 7006);
    tags_seed_task($pdo, 7101, 7006, 70601, 1);
    tags_seed_tag($pdo, 7940, 'task', 'zeta', '#444');
    $pdo->prepare("INSERT OR IGNORE INTO task_tag_map (task_id, tag_id) VALUES (?, ?)")->execute([7101, 7940]);

    $r = api_request('DELETE', '/api/v1/tasks/7101/tags/7940', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(204, $r['status']);

    $g = api_request('GET', '/api/v1/tasks/7101', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    $names = array_column($g['json']['tags'], 'name');
    assert_true(!in_array('zeta', $names, true), 'expected tag detached from task');
});
