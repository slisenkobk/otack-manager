<?php
// Integration tests for /api/v1/tasks/* endpoints.
// Uses high-numbered ids (5000+) to isolate from earlier fixtures.

/** @return array{0:\PDO,1:string,2:string} pdo, adminTok, empTok */
function tasks_setup(): array {
    api_request('GET', '/api/v1/ping');
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'approved', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (2, 'E', 'e@x', 'x', 'employee', 'approved', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $adminTok = $repo->create(1, 'tasks-admin-' . bin2hex(random_bytes(3)))['token'];
    $empTok   = $repo->create(2, 'tasks-emp-'   . bin2hex(random_bytes(3)))['token'];
    return [$pdo, $adminTok, $empTok];
}

function tasks_seed_project(\PDO $pdo, int $id, int $createdBy = 1): void {
    $pdo->prepare("INSERT OR IGNORE INTO projects (id, name, slug, color, status, created_by, created_at, updated_at) VALUES (?, ?, ?, '#fff', 'active', ?, '2026-01-01', '2026-01-01')")
        ->execute([$id, 'P-' . $id, 'p-task-' . $id, $createdBy]);
}

function tasks_seed_column(\PDO $pdo, int $id, int $projectId, string $name, int $position, int $isDone = 0, int $isBacklog = 0): void {
    $pdo->prepare("INSERT OR IGNORE INTO task_columns (id, project_id, name, color, position, is_done, is_backlog) VALUES (?, ?, ?, '#fff', ?, ?, ?)")
        ->execute([$id, $projectId, $name, $position, $isDone, $isBacklog]);
}

function tasks_seed_task(\PDO $pdo, int $id, int $projectId, int $columnId, int $createdBy = 1, ?int $assigneeId = null, string $title = 'T'): void {
    $pdo->prepare("INSERT OR IGNORE INTO tasks (id, project_id, column_id, title, description, position, assignee_id, due_date, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, 1024, ?, NULL, ?, '2026-01-01', '2026-01-01')")
        ->execute([$id, $projectId, $columnId, $title, $assigneeId, $createdBy]);
}

api_it('GET /tasks/{id} returns task with counts and tags', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5001);
    tasks_seed_column($pdo, 50101, 5001, 'To Do', 0);
    tasks_seed_task($pdo, 5100, 5001, 50101, 1);
    // Attach a tag
    $pdo->exec("INSERT OR IGNORE INTO tags (id, scope, name, color) VALUES (5990, 'task', 'urgent', '#f00')");
    $pdo->prepare("INSERT OR IGNORE INTO task_tag_map (task_id, tag_id) VALUES (?, ?)")->execute([5100, 5990]);

    $r = api_request('GET', '/api/v1/tasks/5100', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(5100, (int)$r['json']['id']);
    assert_true(array_key_exists('comments_count', $r['json']));
    assert_true(array_key_exists('attachments_count', $r['json']));
    assert_true(array_key_exists('tags', $r['json']));
    assert_true(array_key_exists('links', $r['json']));
    $tagNames = array_column($r['json']['tags'], 'name');
    assert_true(in_array('urgent', $tagNames, true), 'expected attached tag in response');
});

api_it('GET /tasks/{id} returns 404 when not visible to caller', function () {
    [$pdo, , $empTok] = tasks_setup();
    tasks_seed_project($pdo, 5002, 1);
    tasks_seed_column($pdo, 50201, 5002, 'To Do', 0);
    tasks_seed_task($pdo, 5101, 5002, 50201, 1);
    // Employee is not a member of project 5002.
    $r = api_request('GET', '/api/v1/tasks/5101', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(404, $r['status']);
});

api_it('GET /projects/{id}/tasks paginates', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5003);
    tasks_seed_column($pdo, 50301, 5003, 'To Do', 0);
    // Batch-insert 60 tasks. Stay clear of the 5100-5101 range above.
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO tasks (id, project_id, column_id, title, description, position, assignee_id, due_date, created_by, created_at, updated_at) VALUES (?, 5003, 50301, ?, NULL, ?, NULL, NULL, 1, '2026-01-01', '2026-01-01')");
    $allIds = [];
    for ($i = 0; $i < 60; $i++) {
        $tid = 5200 + $i;
        $allIds[] = $tid;
        $stmt->execute([$tid, 'PG-' . $i, 1024 + $i * 1024]);
    }
    $r = api_request('GET', '/api/v1/projects/5003/tasks?limit=25', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(25, count($r['json']['items']));
    assert_true($r['json']['next_cursor'] !== null, 'expected next_cursor on first page');
    $seen = array_column($r['json']['items'], 'id');

    $cursor = $r['json']['next_cursor'];
    $guard = 0;
    while ($cursor !== null && $guard < 5) {
        $guard++;
        $r2 = api_request('GET', '/api/v1/projects/5003/tasks?limit=25&after=' . (int)$cursor, [
            'headers' => ['Authorization: Bearer ' . $adminTok],
        ]);
        assert_eq(200, $r2['status']);
        $seen = array_merge($seen, array_column($r2['json']['items'], 'id'));
        $cursor = $r2['json']['next_cursor'];
    }
    $seen = array_values(array_unique(array_map('intval', $seen)));
    sort($seen);
    assert_eq($allIds, $seen, 'expected paginated union to cover all 60 ids');
});

api_it('GET /projects/{id}/tasks?column_id=X filters by column', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5004);
    tasks_seed_column($pdo, 50401, 5004, 'A', 0);
    tasks_seed_column($pdo, 50402, 5004, 'B', 1);
    tasks_seed_task($pdo, 5110, 5004, 50401, 1, null, 'IN-A');
    tasks_seed_task($pdo, 5111, 5004, 50402, 1, null, 'IN-B');
    tasks_seed_task($pdo, 5112, 5004, 50401, 1, null, 'IN-A2');
    $r = api_request('GET', '/api/v1/projects/5004/tasks?column_id=50401', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    $ids = array_column($r['json']['items'], 'id');
    assert_true(in_array(5110, $ids, true) && in_array(5112, $ids, true), 'expected col-A tasks');
    assert_true(!in_array(5111, $ids, true), 'col-B task should be filtered out');
});

api_it('GET /projects/{id}/tasks?assignee_id=X filters by assignee', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5005);
    tasks_seed_column($pdo, 50501, 5005, 'C', 0);
    tasks_seed_task($pdo, 5120, 5005, 50501, 1, 1, 'mine');
    tasks_seed_task($pdo, 5121, 5005, 50501, 1, 2, 'yours');
    $r = api_request('GET', '/api/v1/projects/5005/tasks?assignee_id=2', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    $ids = array_column($r['json']['items'], 'id');
    assert_true(in_array(5121, $ids, true));
    assert_true(!in_array(5120, $ids, true));
});

api_it('POST /projects/{id}/tasks creates with required title only', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5006);
    tasks_seed_column($pdo, 50601, 5006, 'To Do', 0);
    $r = api_request('POST', '/api/v1/projects/5006/tasks', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['title' => 'Brand new task']),
    ]);
    assert_eq(201, $r['status']);
    assert_eq('Brand new task', $r['json']['title']);
    assert_eq(5006, (int)$r['json']['project_id']);
    assert_true((int)$r['json']['id'] > 0);
});

api_it('POST /projects/{id}/tasks with empty title returns 422', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5007);
    tasks_seed_column($pdo, 50701, 5007, 'To Do', 0);
    $r = api_request('POST', '/api/v1/projects/5007/tasks', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['title' => '']),
    ]);
    assert_eq(422, $r['status']);
    assert_eq('validation_failed', $r['json']['error']);
});

api_it('POST /projects/{id}/tasks as employee not in project returns 403', function () {
    [$pdo, , $empTok] = tasks_setup();
    // The employee is the creator (so visibility passes via created_by) but
    // does NOT have a project_members row — that's the visible-but-not-member
    // path that surfaces the 403 from the canEdit check.
    tasks_seed_project($pdo, 5008, 2);
    tasks_seed_column($pdo, 50801, 5008, 'To Do', 0);
    $r = api_request('POST', '/api/v1/projects/5008/tasks', [
        'headers' => ['Authorization: Bearer ' . $empTok],
        'body'    => json_encode(['title' => 'sneaky']),
    ]);
    assert_eq(403, $r['status']);
});

api_it('PATCH /tasks/{id} updates description', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5009);
    tasks_seed_column($pdo, 50901, 5009, 'To Do', 0);
    tasks_seed_task($pdo, 5130, 5009, 50901, 1);
    $r = api_request('PATCH', '/api/v1/tasks/5130', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['description' => 'patched body']),
    ]);
    assert_eq(200, $r['status']);
    $g = api_request('GET', '/api/v1/tasks/5130', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq('patched body', $g['json']['description']);
});

api_it('POST /tasks/{id}/move changes column + position', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5010);
    tasks_seed_column($pdo, 51001, 5010, 'A', 0);
    tasks_seed_column($pdo, 51002, 5010, 'B', 1);
    tasks_seed_task($pdo, 5140, 5010, 51001, 1);
    $r = api_request('POST', '/api/v1/tasks/5140/move', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['column_id' => 51002, 'position' => 4096]),
    ]);
    assert_eq(200, $r['status']);
    $g = api_request('GET', '/api/v1/tasks/5140', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(51002, (int)$g['json']['column_id']);
    assert_eq(4096.0, (float)$g['json']['position']);
});

api_it('DELETE /tasks/{id} returns 204 and subsequent GET returns 404', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5011);
    tasks_seed_column($pdo, 51101, 5011, 'X', 0);
    tasks_seed_task($pdo, 5150, 5011, 51101, 1);
    $r = api_request('DELETE', '/api/v1/tasks/5150', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(204, $r['status']);
    $g = api_request('GET', '/api/v1/tasks/5150', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(404, $g['status']);
});

api_it('POST /tasks/{id}/promote-to-project returns new project_id', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5012);
    tasks_seed_column($pdo, 51201, 5012, 'X', 0);
    tasks_seed_task($pdo, 5160, 5012, 51201, 1, null, 'Promote me');
    $r = api_request('POST', '/api/v1/tasks/5160/promote-to-project', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => '{}',
    ]);
    assert_eq(200, $r['status']);
    $newId = (int)$r['json']['project_id'];
    assert_true($newId > 0, 'expected positive new project id');
    // Confirm new project visible.
    $g = api_request('GET', '/api/v1/projects/' . $newId, [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $g['status']);
});

api_it('POST /tasks/{id}/links creates a bidirectional link', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5013);
    tasks_seed_column($pdo, 51301, 5013, 'X', 0);
    tasks_seed_task($pdo, 5170, 5013, 51301, 1);
    tasks_seed_task($pdo, 5171, 5013, 51301, 1);
    $r = api_request('POST', '/api/v1/tasks/5170/links', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['other_id' => 5171]),
    ]);
    assert_eq(201, $r['status']);
    $g = api_request('GET', '/api/v1/tasks/5170', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_true(in_array(5171, array_map('intval', $g['json']['links']), true), 'forward link missing');
    $g2 = api_request('GET', '/api/v1/tasks/5171', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_true(in_array(5170, array_map('intval', $g2['json']['links']), true), 'reverse link missing');
});

api_it('DELETE /tasks/{id}/links/{other_id} removes the link', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5014);
    tasks_seed_column($pdo, 51401, 5014, 'X', 0);
    tasks_seed_task($pdo, 5180, 5014, 51401, 1);
    tasks_seed_task($pdo, 5181, 5014, 51401, 1);
    api_request('POST', '/api/v1/tasks/5180/links', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['other_id' => 5181]),
    ]);
    $r = api_request('DELETE', '/api/v1/tasks/5180/links/5181', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(204, $r['status']);
    $g = api_request('GET', '/api/v1/tasks/5180', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_true(!in_array(5181, array_map('intval', $g['json']['links']), true), 'link still present');
});

api_it('PATCH /tasks/{id}: assignee employee may update a manager-created task', function () {
    [$pdo, , $empTok] = tasks_setup();
    tasks_seed_project($pdo, 5310, 1);
    tasks_seed_column($pdo, 53101, 5310, 'To Do', 0);
    // user 2 is an employee, a member of the project, assigned to the task,
    // but NOT its author — the exact shape of a bot service account.
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'member')")
        ->execute([5310, 2]);
    tasks_seed_task($pdo, 5320, 5310, 53101, 1, 2);

    $r = api_request('PATCH', '/api/v1/tasks/5320', [
        'headers' => ['Authorization: Bearer ' . $empTok],
        'body'    => json_encode(['priority' => 'high']),
    ]);
    assert_eq(200, $r['status']);
    assert_eq('high', $r['json']['priority']);
});

api_it('PATCH /tasks/{id}: non-assignee employee still gets 403', function () {
    [$pdo, , $empTok] = tasks_setup();
    tasks_seed_project($pdo, 5330, 1);
    tasks_seed_column($pdo, 53301, 5330, 'To Do', 0);
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'member')")
        ->execute([5330, 2]);
    // Assigned to user 1, not to the caller.
    tasks_seed_task($pdo, 5340, 5330, 53301, 1, 1);

    $r = api_request('PATCH', '/api/v1/tasks/5340', [
        'headers' => ['Authorization: Bearer ' . $empTok],
        'body'    => json_encode(['priority' => 'high']),
    ]);
    assert_eq(403, $r['status']);
});

api_it('PATCH /tasks/{id}: sets agent_state and stamps agent_state_at', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5400, 1);
    tasks_seed_column($pdo, 54001, 5400, 'To Do', 0);
    tasks_seed_task($pdo, 5410, 5400, 54001, 1);

    $r = api_request('PATCH', '/api/v1/tasks/5410', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['agent_state' => 'researching']),
    ]);
    assert_eq(200, $r['status']);
    assert_eq('researching', $r['json']['agent_state']);

    $row = $pdo->query('SELECT agent_state_at FROM tasks WHERE id = 5410')->fetch(\PDO::FETCH_ASSOC);
    assert_true(!empty($row['agent_state_at']), 'agent_state_at must be stamped automatically');
});

api_it('PATCH /tasks/{id}: rejects an unknown agent_state', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5420, 1);
    tasks_seed_column($pdo, 54201, 5420, 'To Do', 0);
    tasks_seed_task($pdo, 5430, 5420, 54201, 1);

    $r = api_request('PATCH', '/api/v1/tasks/5430', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['agent_state' => 'nonsense']),
    ]);
    assert_eq(422, $r['status']);
    assert_eq('validation_failed', $r['json']['error']);
});

api_it('PATCH /tasks/{id}: agent_state null clears the phase', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5440, 1);
    tasks_seed_column($pdo, 54401, 5440, 'To Do', 0);
    tasks_seed_task($pdo, 5450, 5440, 54401, 1);
    // Seed both agent_state and agent_state_at so we can verify both are cleared
    $pdo->exec("UPDATE tasks SET agent_state = 'review', agent_state_at = '2026-01-01T00:00:00.000000Z' WHERE id = 5450");

    $r = api_request('PATCH', '/api/v1/tasks/5450', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['agent_state' => null]),
    ]);
    assert_eq(200, $r['status']);
    assert_eq(null, $r['json']['agent_state']);

    // Verify both columns are cleared via direct DB query (since agent_state_at is not exposed in response)
    $row = $pdo->query('SELECT agent_state, agent_state_at FROM tasks WHERE id = 5450')->fetch(\PDO::FETCH_ASSOC);
    assert_true($row['agent_state'] === null, 'agent_state must be NULL');
    assert_true($row['agent_state_at'] === null, 'agent_state_at must be NULL');
});

api_it('PATCH /tasks/{id}: rejects non-scalar agent_state', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5480, 1);
    tasks_seed_column($pdo, 54801, 5480, 'To Do', 0);
    tasks_seed_task($pdo, 5490, 5480, 54801, 1);

    $r = api_request('PATCH', '/api/v1/tasks/5490', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['agent_state' => ['x']]),
    ]);
    assert_eq(422, $r['status']);
    assert_eq('validation_failed', $r['json']['error']);
});

api_it('GET /projects/{id}/tasks?agent_state= filters by phase', function () {
    [$pdo, $adminTok,] = tasks_setup();
    tasks_seed_project($pdo, 5460, 1);
    tasks_seed_column($pdo, 54601, 5460, 'To Do', 0);
    tasks_seed_task($pdo, 5470, 5460, 54601, 1);
    tasks_seed_task($pdo, 5471, 5460, 54601, 1);
    $pdo->exec("UPDATE tasks SET agent_state = 'implementing' WHERE id = 5470");

    $r = api_request('GET', '/api/v1/projects/5460/tasks?agent_state=implementing', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(1, count($r['json']['items']));
    assert_eq(5470, (int)$r['json']['items'][0]['id']);
});

api_it('GET /tasks: returns only tasks assigned to the caller, across projects', function () {
    [$pdo, , $empTok] = tasks_setup();
    tasks_seed_project($pdo, 5500, 1);
    tasks_seed_project($pdo, 5501, 1);
    tasks_seed_column($pdo, 55001, 5500, 'To Do', 0);
    tasks_seed_column($pdo, 55011, 5501, 'To Do', 0);
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, 2, 'member')")->execute([5500]);
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, 2, 'member')")->execute([5501]);
    tasks_seed_task($pdo, 5510, 5500, 55001, 1, 2);   // assigned to caller
    tasks_seed_task($pdo, 5511, 5501, 55011, 1, 2);   // assigned to caller, other project
    tasks_seed_task($pdo, 5512, 5500, 55001, 1, 1);   // assigned to someone else

    $r = api_request('GET', '/api/v1/tasks', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(200, $r['status']);
    $ids = array_map('intval', array_column($r['json']['items'], 'id'));
    assert_true(in_array(5510, $ids, true), '5510 must be present');
    assert_true(in_array(5511, $ids, true), '5511 must be present');
    assert_true(!in_array(5512, $ids, true), '5512 belongs to another assignee');
});

api_it('GET /tasks: never leaks tasks from projects the caller is not in', function () {
    [$pdo, , $empTok] = tasks_setup();
    tasks_seed_project($pdo, 5520, 1);
    tasks_seed_column($pdo, 55201, 5520, 'To Do', 0);
    // Assigned to the caller, but the caller is NOT a member of the project.
    tasks_seed_task($pdo, 5530, 5520, 55201, 1, 2);

    $r = api_request('GET', '/api/v1/tasks', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(200, $r['status']);
    $ids = array_map('intval', array_column($r['json']['items'], 'id'));
    assert_true(!in_array(5530, $ids, true), 'membership must gate visibility');
});

api_it('GET /tasks?agent_state= filters across projects', function () {
    [$pdo, , $empTok] = tasks_setup();
    tasks_seed_project($pdo, 5540, 1);
    tasks_seed_column($pdo, 55401, 5540, 'To Do', 0);
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, 2, 'member')")->execute([5540]);
    tasks_seed_task($pdo, 5550, 5540, 55401, 1, 2);
    tasks_seed_task($pdo, 5551, 5540, 55401, 1, 2);
    $pdo->exec("UPDATE tasks SET agent_state = 'awaiting_approval' WHERE id = 5550");

    $r = api_request('GET', '/api/v1/tasks?agent_state=awaiting_approval', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(1, count($r['json']['items']));
    assert_eq(5550, (int)$r['json']['items'][0]['id']);
});

api_it('GET /tasks requires a token', function () {
    $r = api_request('GET', '/api/v1/tasks');
    assert_eq(401, $r['status']);
});
