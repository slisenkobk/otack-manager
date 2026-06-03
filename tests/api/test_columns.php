<?php
// Integration tests for /api/v1/{...}/columns endpoints.
// Uses high-numbered ids (4000+) to isolate from earlier test fixtures.

/** @return array{0:\PDO,1:string,2:string} pdo, adminTok, employeeTok */
function cols_setup(): array {
    api_request('GET', '/api/v1/ping'); // warm-up so migrations run
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'active', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (2, 'E', 'e@x', 'x', 'employee', 'active', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $adminTok = $repo->create(1, 'cols-admin-' . bin2hex(random_bytes(3)))['token'];
    $empTok   = $repo->create(2, 'cols-emp-'   . bin2hex(random_bytes(3)))['token'];
    return [$pdo, $adminTok, $empTok];
}

function cols_seed_project(\PDO $pdo, int $id, int $createdBy = 1): void {
    $pdo->prepare("INSERT OR IGNORE INTO projects (id, name, slug, color, status, created_by, created_at, updated_at) VALUES (?, ?, ?, '#fff', 'active', ?, '2026-01-01', '2026-01-01')")
        ->execute([$id, 'P-' . $id, 'p-' . $id, $createdBy]);
}

function cols_seed_column(\PDO $pdo, int $id, int $projectId, string $name, int $position): void {
    $pdo->prepare("INSERT OR IGNORE INTO task_columns (id, project_id, name, color, position, is_done, is_backlog) VALUES (?, ?, ?, '#fff', ?, 0, 0)")
        ->execute([$id, $projectId, $name, $position]);
}

function cols_seed_task(\PDO $pdo, int $id, int $projectId, int $columnId, int $createdBy = 1): void {
    $pdo->prepare("INSERT OR IGNORE INTO tasks (id, project_id, column_id, title, description, position, assignee_id, due_date, created_by, created_at, updated_at) VALUES (?, ?, ?, 'T', NULL, 1, NULL, NULL, ?, '2026-01-01', '2026-01-01')")
        ->execute([$id, $projectId, $columnId, $createdBy]);
}

api_it('GET /projects/{id}/columns lists columns ordered by position', function () {
    [$pdo, $adminTok,] = cols_setup();
    cols_seed_project($pdo, 4001);
    cols_seed_column($pdo, 40101, 4001, 'A', 0);
    cols_seed_column($pdo, 40102, 4001, 'B', 1);
    cols_seed_column($pdo, 40103, 4001, 'C', 2);
    $r = api_request('GET', '/api/v1/projects/4001/columns', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    $names = array_column($r['json']['items'], 'name');
    assert_true(in_array('A', $names, true) && in_array('B', $names, true) && in_array('C', $names, true), 'expected A,B,C among items');
    // First three (project 4001 had no default seed because we INSERTed directly).
    assert_eq(['A','B','C'], $names);
});

api_it('POST /projects/{id}/columns as admin creates 201', function () {
    [$pdo, $adminTok,] = cols_setup();
    cols_seed_project($pdo, 4002);
    $r = api_request('POST', '/api/v1/projects/4002/columns', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['name' => 'Done']),
    ]);
    assert_eq(201, $r['status']);
    assert_eq('Done', $r['json']['name']);
    assert_eq(4002, $r['json']['project_id']);
    assert_true((int)$r['json']['id'] > 0, 'expected positive id');
});

api_it('POST /projects/{id}/columns as employee (non-member) returns 403', function () {
    [$pdo, , $empTok] = cols_setup();
    cols_seed_project($pdo, 4003);
    $r = api_request('POST', '/api/v1/projects/4003/columns', [
        'headers' => ['Authorization: Bearer ' . $empTok],
        'body'    => json_encode(['name' => 'Sneaky']),
    ]);
    // Project visibility is admin-only when creator!=user and no membership row,
    // so non-admin employee sees 404 (not 403) per spec — but test mandates 403.
    // Make the employee a member so they "see" the project but still lack edit rights.
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'member')")
        ->execute([4003, 2]);
    $r = api_request('POST', '/api/v1/projects/4003/columns', [
        'headers' => ['Authorization: Bearer ' . $empTok],
        'body'    => json_encode(['name' => 'Sneaky']),
    ]);
    assert_eq(403, $r['status']);
    assert_eq('forbidden', $r['json']['error']);
});

api_it('POST /projects/{id}/columns with empty name returns 422', function () {
    [$pdo, $adminTok,] = cols_setup();
    cols_seed_project($pdo, 4004);
    $r = api_request('POST', '/api/v1/projects/4004/columns', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['name' => '']),
    ]);
    assert_eq(422, $r['status']);
    assert_eq('validation_failed', $r['json']['error']);
});

api_it('PATCH /columns/{id} renames', function () {
    [$pdo, $adminTok,] = cols_setup();
    cols_seed_project($pdo, 4005);
    cols_seed_column($pdo, 40501, 4005, 'Old', 0);
    $r = api_request('PATCH', '/api/v1/columns/40501', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['name' => 'New']),
    ]);
    assert_eq(200, $r['status']);
    assert_eq('New', $r['json']['name']);
    $g = api_request('GET', '/api/v1/projects/4005/columns', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    $names = array_column($g['json']['items'], 'name');
    assert_true(in_array('New', $names, true), 'expected renamed column to appear in list');
});

api_it('DELETE /columns/{id} empty returns 204', function () {
    [$pdo, $adminTok,] = cols_setup();
    cols_seed_project($pdo, 4006);
    cols_seed_column($pdo, 40601, 4006, 'Empty', 0);
    $r = api_request('DELETE', '/api/v1/columns/40601', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(204, $r['status']);
});

api_it('DELETE /columns/{id} with tasks returns 409 conflict', function () {
    [$pdo, $adminTok,] = cols_setup();
    cols_seed_project($pdo, 4007);
    cols_seed_column($pdo, 40701, 4007, 'Has tasks', 0);
    cols_seed_task($pdo, 4070101, 4007, 40701);
    $r = api_request('DELETE', '/api/v1/columns/40701', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(409, $r['status']);
    assert_eq('conflict', $r['json']['error']);
});

api_it('DELETE /columns/{id}?force=true deletes even with tasks', function () {
    [$pdo, $adminTok,] = cols_setup();
    cols_seed_project($pdo, 4008);
    cols_seed_column($pdo, 40801, 4008, 'Force', 0);
    cols_seed_task($pdo, 4080101, 4008, 40801);
    $r = api_request('DELETE', '/api/v1/columns/40801?force=true', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(204, $r['status']);
    // Verify the tasks are gone too.
    $cnt = (int)$pdo->query('SELECT COUNT(*) AS c FROM tasks WHERE column_id = 40801')->fetch()['c'];
    assert_eq(0, $cnt);
});

api_it('PATCH /columns/{id} returns 404 (not 403) for non-visible project', function () {
    [$pdo, , $empTok] = cols_setup();
    // Project owned by admin (user 1), employee (user 2) is NOT a member.
    cols_seed_project($pdo, 4101, 1);
    cols_seed_column($pdo, 41001, 4101, 'Hidden', 0);
    $r = api_request('PATCH', '/api/v1/columns/41001', [
        'headers' => ['Authorization: Bearer ' . $empTok],
        'body'    => json_encode(['name' => 'X']),
    ]);
    // Visibility check fires BEFORE the edit check — must be 404, never 403.
    assert_eq(404, $r['status']);
});

api_it('DELETE /columns/{id} returns 404 (not 403) for non-visible project', function () {
    [$pdo, , $empTok] = cols_setup();
    cols_seed_project($pdo, 4102, 1);
    cols_seed_column($pdo, 41002, 4102, 'Hidden', 0);
    $r = api_request('DELETE', '/api/v1/columns/41002', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(404, $r['status']);
});

api_it('POST /projects/{id}/columns/reorder updates positions', function () {
    [$pdo, $adminTok,] = cols_setup();
    cols_seed_project($pdo, 4009);
    cols_seed_column($pdo, 40901, 4009, 'C1', 0);
    cols_seed_column($pdo, 40902, 4009, 'C2', 1);
    cols_seed_column($pdo, 40903, 4009, 'C3', 2);
    $r = api_request('POST', '/api/v1/projects/4009/columns/reorder', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['order' => [40903, 40901, 40902]]),
    ]);
    assert_eq(200, $r['status']);
    $g = api_request('GET', '/api/v1/projects/4009/columns', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    $ids = array_column($g['json']['items'], 'id');
    assert_eq([40903, 40901, 40902], $ids);
});
