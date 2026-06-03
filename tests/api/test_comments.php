<?php
// Integration tests for /api/v1/comments endpoints.
// Uses ids 6000+ to isolate from earlier fixtures.

/** @return array{0:\PDO,1:string,2:string,3:string} pdo, adminTok, empTok, otherEmpTok */
function comments_setup(): array {
    api_request('GET', '/api/v1/ping');
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'active', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (2, 'E', 'e@x', 'x', 'employee', 'active', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (3, 'E3', 'e3@x', 'x', 'employee', 'active', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $adminTok  = $repo->create(1, 'comments-admin-' . bin2hex(random_bytes(3)))['token'];
    $empTok    = $repo->create(2, 'comments-emp-'   . bin2hex(random_bytes(3)))['token'];
    $otherTok  = $repo->create(3, 'comments-emp3-'  . bin2hex(random_bytes(3)))['token'];
    return [$pdo, $adminTok, $empTok, $otherTok];
}

function comments_seed_project(\PDO $pdo, int $id, int $createdBy = 1): void {
    $pdo->prepare("INSERT OR IGNORE INTO projects (id, name, slug, color, status, created_by, created_at, updated_at) VALUES (?, ?, ?, '#fff', 'active', ?, '2026-01-01', '2026-01-01')")
        ->execute([$id, 'P-' . $id, 'p-comment-' . $id, $createdBy]);
}

function comments_seed_column(\PDO $pdo, int $id, int $projectId): void {
    $pdo->prepare("INSERT OR IGNORE INTO task_columns (id, project_id, name, color, position, is_done, is_backlog) VALUES (?, ?, 'To Do', '#fff', 0, 0, 0)")
        ->execute([$id, $projectId]);
}

function comments_seed_task(\PDO $pdo, int $id, int $projectId, int $columnId, int $createdBy = 1): void {
    $pdo->prepare("INSERT OR IGNORE INTO tasks (id, project_id, column_id, title, description, position, assignee_id, due_date, created_by, created_at, updated_at) VALUES (?, ?, ?, 'T', NULL, 1024, NULL, NULL, ?, '2026-01-01', '2026-01-01')")
        ->execute([$id, $projectId, $columnId, $createdBy]);
}

function comments_seed_comment(\PDO $pdo, int $id, string $entityType, int $entityId, int $userId, string $body, string $createdAt, ?int $parentId = null): void {
    $pdo->prepare("INSERT OR IGNORE INTO comments (id, entity_type, entity_id, user_id, body, parent_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$id, $entityType, $entityId, $userId, $body, $parentId, $createdAt]);
}

api_it('GET /tasks/{id}/comments lists comments oldest-first', function () {
    [$pdo, $adminTok,,] = comments_setup();
    comments_seed_project($pdo, 6001);
    comments_seed_column($pdo, 60101, 6001);
    comments_seed_task($pdo, 6100, 6001, 60101, 1);
    comments_seed_comment($pdo, 6200, 'task', 6100, 1, 'first',  '2026-01-01T10:00:00.000Z');
    comments_seed_comment($pdo, 6201, 'task', 6100, 1, 'second', '2026-01-01T10:01:00.000Z');
    comments_seed_comment($pdo, 6202, 'task', 6100, 1, 'third',  '2026-01-01T10:02:00.000Z');

    $r = api_request('GET', '/api/v1/tasks/6100/comments', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(3, count($r['json']['items']));
    $bodies = array_column($r['json']['items'], 'body');
    assert_eq(['first', 'second', 'third'], $bodies, 'expected oldest-first ordering');
});

api_it('GET /projects/{id}/comments lists project-level comments', function () {
    [$pdo, $adminTok,,] = comments_setup();
    comments_seed_project($pdo, 6002);
    comments_seed_comment($pdo, 6210, 'project', 6002, 1, 'p1', '2026-01-01T11:00:00.000Z');
    comments_seed_comment($pdo, 6211, 'project', 6002, 1, 'p2', '2026-01-01T11:01:00.000Z');

    $r = api_request('GET', '/api/v1/projects/6002/comments', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(2, count($r['json']['items']));
});

api_it('POST /comments creates task comment with body (raw Markdown)', function () {
    [$pdo, $adminTok,,] = comments_setup();
    comments_seed_project($pdo, 6003);
    comments_seed_column($pdo, 60301, 6003);
    comments_seed_task($pdo, 6101, 6003, 60301, 1);

    $r = api_request('POST', '/api/v1/comments', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['entity' => 'task', 'entity_id' => 6101, 'body' => '**bold**']),
    ]);
    assert_eq(201, $r['status']);
    assert_eq('**bold**', $r['json']['body'], 'expected raw Markdown body');

    $g = api_request('GET', '/api/v1/tasks/6101/comments', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $g['status']);
    $bodies = array_column($g['json']['items'], 'body');
    assert_true(in_array('**bold**', $bodies, true), 'expected raw markdown body in list');
});

api_it('POST /comments with empty body returns 422', function () {
    [$pdo, $adminTok,,] = comments_setup();
    comments_seed_project($pdo, 6004);
    comments_seed_column($pdo, 60401, 6004);
    comments_seed_task($pdo, 6102, 6004, 60401, 1);

    $r = api_request('POST', '/api/v1/comments', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['entity' => 'task', 'entity_id' => 6102, 'body' => '   ']),
    ]);
    assert_eq(422, $r['status']);
    assert_eq('validation_failed', $r['json']['error']);
});

api_it('POST /comments with bad entity returns 422', function () {
    [$pdo, $adminTok,,] = comments_setup();
    $r = api_request('POST', '/api/v1/comments', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['entity' => 'foo', 'entity_id' => 1, 'body' => 'hi']),
    ]);
    assert_eq(422, $r['status']);
    assert_eq('validation_failed', $r['json']['error']);
});

api_it('POST /comments with parent_id creates a reply', function () {
    [$pdo, $adminTok,,] = comments_setup();
    comments_seed_project($pdo, 6005);
    comments_seed_column($pdo, 60501, 6005);
    comments_seed_task($pdo, 6103, 6005, 60501, 1);
    comments_seed_comment($pdo, 6220, 'task', 6103, 1, 'root', '2026-01-01T12:00:00.000Z');

    $r = api_request('POST', '/api/v1/comments', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['entity' => 'task', 'entity_id' => 6103, 'body' => 'reply', 'parent_id' => 6220]),
    ]);
    assert_eq(201, $r['status']);
    assert_eq(6220, (int)$r['json']['parent_id']);
});

api_it('DELETE /comments/{id} as author returns 204', function () {
    [$pdo,, $empTok,] = comments_setup();
    comments_seed_project($pdo, 6006, 2);
    comments_seed_column($pdo, 60601, 6006);
    comments_seed_task($pdo, 6104, 6006, 60601, 2);
    // Employee #2 must be member of the project to see it.
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (6006, 2, 'member')")->execute();
    comments_seed_comment($pdo, 6230, 'task', 6104, 2, 'mine', '2026-01-01T13:00:00.000Z');

    $r = api_request('DELETE', '/api/v1/comments/6230', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(204, $r['status']);
});

api_it('DELETE /comments/{id} as admin (non-author) returns 204', function () {
    [$pdo, $adminTok,,] = comments_setup();
    comments_seed_project($pdo, 6007, 1);
    comments_seed_column($pdo, 60701, 6007);
    comments_seed_task($pdo, 6105, 6007, 60701, 1);
    // Comment authored by employee #2, deleted by admin #1.
    comments_seed_comment($pdo, 6240, 'task', 6105, 2, 'not mine', '2026-01-01T14:00:00.000Z');

    $r = api_request('DELETE', '/api/v1/comments/6240', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(204, $r['status']);
});

api_it('DELETE /comments/{id} as other employee returns 403', function () {
    [$pdo,, $empTok, $otherTok] = comments_setup();
    comments_seed_project($pdo, 6008, 1);
    comments_seed_column($pdo, 60801, 6008);
    comments_seed_task($pdo, 6106, 6008, 60801, 1);
    // Both employees are members; one authored the comment.
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (6008, 2, 'member')")->execute();
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (6008, 3, 'member')")->execute();
    comments_seed_comment($pdo, 6250, 'task', 6106, 2, 'authored by #2', '2026-01-01T15:00:00.000Z');

    // Employee #3 (otherTok) tries to delete employee #2's comment.
    $r = api_request('DELETE', '/api/v1/comments/6250', [
        'headers' => ['Authorization: Bearer ' . $otherTok],
    ]);
    assert_eq(403, $r['status']);
});
