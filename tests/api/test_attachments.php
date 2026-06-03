<?php
// Integration tests for /api/v1/attachments endpoints.
// Uses ids 8000+ to isolate from earlier fixtures.

/** @return array{0:\PDO,1:string,2:string,3:string} pdo, adminTok, empTok, otherEmpTok */
function attachments_setup(): array {
    api_request('GET', '/api/v1/ping');
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'active', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (2, 'E', 'e@x', 'x', 'employee', 'active', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (3, 'E3', 'e3@x', 'x', 'employee', 'active', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $adminTok = $repo->create(1, 'att-admin-' . bin2hex(random_bytes(3)))['token'];
    $empTok   = $repo->create(2, 'att-emp-'   . bin2hex(random_bytes(3)))['token'];
    $otherTok = $repo->create(3, 'att-emp3-'  . bin2hex(random_bytes(3)))['token'];
    return [$pdo, $adminTok, $empTok, $otherTok];
}

function attachments_seed_project(\PDO $pdo, int $id, int $createdBy = 1): void {
    $pdo->prepare("INSERT OR IGNORE INTO projects (id, name, slug, color, status, created_by, created_at, updated_at) VALUES (?, ?, ?, '#fff', 'active', ?, '2026-01-01', '2026-01-01')")
        ->execute([$id, 'P-' . $id, 'p-att-' . $id, $createdBy]);
}

function attachments_seed_column(\PDO $pdo, int $id, int $projectId): void {
    $pdo->prepare("INSERT OR IGNORE INTO task_columns (id, project_id, name, color, position, is_done, is_backlog) VALUES (?, ?, 'To Do', '#fff', 0, 0, 0)")
        ->execute([$id, $projectId]);
}

function attachments_seed_task(\PDO $pdo, int $id, int $projectId, int $columnId, int $createdBy = 1): void {
    $pdo->prepare("INSERT OR IGNORE INTO tasks (id, project_id, column_id, title, description, position, assignee_id, due_date, created_by, created_at, updated_at) VALUES (?, ?, ?, 'T', NULL, 1024, NULL, NULL, ?, '2026-01-01', '2026-01-01')")
        ->execute([$id, $projectId, $columnId, $createdBy]);
}

// Build fixture files once per run (idempotent).
function attachments_fixture_ok(): string {
    $p = '/tmp/otack-test-upload.txt';
    if (!is_file($p)) file_put_contents($p, "hello attachments\n");
    return $p;
}

function attachments_fixture_bad(): string {
    // Real PHP source — finfo will detect as text/x-php, not in allow-list.
    $p = '/tmp/otack-test-upload.php';
    if (!is_file($p)) file_put_contents($p, "<?php echo 'nope'; ?>\n");
    return $p;
}

api_it('POST /attachments uploads a file to a task', function () {
    [$pdo, $adminTok,,] = attachments_setup();
    attachments_seed_project($pdo, 8001);
    attachments_seed_column($pdo, 80101, 8001);
    attachments_seed_task($pdo, 8100, 8001, 80101, 1);

    $r = api_upload(
        '/api/v1/attachments',
        $adminTok,
        ['entity' => 'task', 'entity_id' => 8100],
        attachments_fixture_ok()
    );
    assert_eq(201, $r['status'], 'body=' . $r['body']);
    assert_true(isset($r['json']['id']),         'id missing');
    assert_eq('task',  $r['json']['entity_type']);
    assert_eq(8100,    (int)$r['json']['entity_id']);
    assert_eq('text/plain', $r['json']['mime']);
    assert_true(isset($r['json']['filename']),   'filename missing');
    assert_true(isset($r['json']['path']),       'path missing');
});

api_it('POST /attachments without file field returns 422', function () {
    [$pdo, $adminTok,,] = attachments_setup();
    attachments_seed_project($pdo, 8002);
    attachments_seed_column($pdo, 80201, 8002);
    attachments_seed_task($pdo, 8101, 8002, 80201, 1);

    $url = 'http://localhost:8765/api/v1/attachments';
    // No file param — send a multipart body with only the two text fields.
    $cmd = implode(' ', array_map('escapeshellarg', [
        'curl', '-s', '-o', '/tmp/otack-api-upload-resp.txt', '-w', '%{http_code}',
        '-X', 'POST',
        '-H', 'Authorization: Bearer ' . $adminTok,
        '-H', 'Accept: application/json',
        '-F', 'entity=task',
        '-F', 'entity_id=8101',
        $url,
    ]));
    $status = (int)trim((string)shell_exec($cmd));
    $body   = (string)@file_get_contents('/tmp/otack-api-upload-resp.txt');
    $json   = json_decode($body, true);
    assert_eq(422, $status, 'body=' . $body);
    assert_eq('validation_failed', $json['error'] ?? null);
});

api_it('POST /attachments with disallowed MIME returns 422', function () {
    [$pdo, $adminTok,,] = attachments_setup();
    attachments_seed_project($pdo, 8003);
    attachments_seed_column($pdo, 80301, 8003);
    attachments_seed_task($pdo, 8102, 8003, 80301, 1);

    $r = api_upload(
        '/api/v1/attachments',
        $adminTok,
        ['entity' => 'task', 'entity_id' => 8102],
        attachments_fixture_bad()
    );
    assert_eq(422, $r['status'], 'body=' . $r['body']);
    assert_eq('validation_failed', $r['json']['error'] ?? null);
});

api_it('POST /attachments as employee not in project returns 403', function () {
    [$pdo,, $empTok,] = attachments_setup();
    // Project created by admin, employee #2 is NOT a member.
    attachments_seed_project($pdo, 8004, 1);
    attachments_seed_column($pdo, 80401, 8004);
    attachments_seed_task($pdo, 8103, 8004, 80401, 1);

    $r = api_upload(
        '/api/v1/attachments',
        $empTok,
        ['entity' => 'task', 'entity_id' => 8103],
        attachments_fixture_ok()
    );
    // Non-member can't see the project at all → 404 (consistent with canSeeProject gate).
    assert_eq(404, $r['status'], 'body=' . $r['body']);
});

api_it('GET /tasks/{id}/attachments lists attachments', function () {
    [$pdo, $adminTok,,] = attachments_setup();
    attachments_seed_project($pdo, 8005);
    attachments_seed_column($pdo, 80501, 8005);
    attachments_seed_task($pdo, 8104, 8005, 80501, 1);

    // Upload one first.
    $u = api_upload(
        '/api/v1/attachments',
        $adminTok,
        ['entity' => 'task', 'entity_id' => 8104],
        attachments_fixture_ok()
    );
    assert_eq(201, $u['status'], 'upload failed: ' . $u['body']);

    $r = api_request('GET', '/api/v1/tasks/8104/attachments', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_true(count($r['json']['items']) >= 1, 'expected at least one attachment');
    assert_eq('task',  $r['json']['items'][0]['entity_type']);
    assert_eq(8104,    (int)$r['json']['items'][0]['entity_id']);
});

api_it('GET /projects/{id}/attachments lists project-scoped attachments', function () {
    [$pdo, $adminTok,,] = attachments_setup();
    attachments_seed_project($pdo, 8006);

    $u = api_upload(
        '/api/v1/attachments',
        $adminTok,
        ['entity' => 'project', 'entity_id' => 8006],
        attachments_fixture_ok()
    );
    assert_eq(201, $u['status'], 'upload failed: ' . $u['body']);

    $r = api_request('GET', '/api/v1/projects/8006/attachments', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(1, count($r['json']['items']));
    assert_eq('project', $r['json']['items'][0]['entity_type']);
    assert_eq(8006, (int)$r['json']['items'][0]['entity_id']);
});

api_it('DELETE /attachments/{id} as uploader returns 204 and unlinks file', function () {
    [$pdo, $adminTok,,] = attachments_setup();
    attachments_seed_project($pdo, 8007);
    attachments_seed_column($pdo, 80701, 8007);
    attachments_seed_task($pdo, 8105, 8007, 80701, 1);

    $u = api_upload(
        '/api/v1/attachments',
        $adminTok,
        ['entity' => 'task', 'entity_id' => 8105],
        attachments_fixture_ok()
    );
    assert_eq(201, $u['status'], 'upload failed: ' . $u['body']);
    $id   = (int)$u['json']['id'];
    $rel  = (string)$u['json']['filename'];
    $abs  = dirname(__DIR__, 2) . '/public/' . $rel;
    assert_true(is_file($abs), 'file should exist on disk before delete');

    $r = api_request('DELETE', '/api/v1/attachments/' . $id, [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(204, $r['status']);
    clearstatcache(true, $abs);
    assert_true(!is_file($abs), 'file should be removed from disk after delete: ' . $abs);
});

api_it('DELETE /attachments/{id} as non-uploader, non-admin returns 403', function () {
    [$pdo, $adminTok, $empTok, $otherTok] = attachments_setup();
    attachments_seed_project($pdo, 8008, 1);
    attachments_seed_column($pdo, 80801, 8008);
    attachments_seed_task($pdo, 8106, 8008, 80801, 1);
    // Make both employees members so they can see the project (admin uploads).
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (8008, 2, 'member')")->execute();
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (8008, 3, 'member')")->execute();

    $u = api_upload(
        '/api/v1/attachments',
        $adminTok,
        ['entity' => 'task', 'entity_id' => 8106],
        attachments_fixture_ok()
    );
    assert_eq(201, $u['status'], 'upload failed: ' . $u['body']);
    $id = (int)$u['json']['id'];

    // Employee #3 is a project member but did NOT upload — must be forbidden.
    $r = api_request('DELETE', '/api/v1/attachments/' . $id, [
        'headers' => ['Authorization: Bearer ' . $otherTok],
    ]);
    assert_eq(403, $r['status']);
});
