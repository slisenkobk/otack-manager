<?php
// Helpers shared across the write-tests. Each test creates fresh project rows
// with high ids (3000+) so they don't collide with test_projects_read.php
// fixtures (101, 202).

/** @return array{0:\PDO,1:\App\Repository\ApiTokenRepository,2:string,3:string} */
function pw_setup(): array {
    api_request('GET', '/api/v1/ping'); // warm-up so migrations run
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin', 'approved', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (2, 'E', 'e@x', 'x', 'employee', 'approved', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $adminTok    = $repo->create(1, 'pw-admin-' . bin2hex(random_bytes(3)))['token'];
    $employeeTok = $repo->create(2, 'pw-emp-'   . bin2hex(random_bytes(3)))['token'];
    return [$pdo, $repo, $adminTok, $employeeTok];
}

function pw_seed_project(\PDO $pdo, int $id, string $name, int $createdBy = 1): void {
    $slug = 'pw-' . $id;
    $pdo->prepare("INSERT OR IGNORE INTO projects (id, name, slug, color, status, created_by, created_at, updated_at) VALUES (?, ?, ?, '#fff', 'active', ?, '2026-01-01', '2026-01-01')")
        ->execute([$id, $name, $slug, $createdBy]);
}

api_it('POST /projects as admin creates and returns 201 with new id', function () {
    [, , $adminTok,] = pw_setup();
    $r = api_request('POST', '/api/v1/projects', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['name' => 'Smoke']),
    ]);
    assert_eq(201, $r['status']);
    assert_true(is_array($r['json']) && (int)$r['json']['id'] > 0, 'expected positive id');
    assert_eq('Smoke', $r['json']['name']);
});

api_it('POST /projects with empty name returns 422 validation_failed', function () {
    [, , $adminTok,] = pw_setup();
    $r = api_request('POST', '/api/v1/projects', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['name' => '']),
    ]);
    assert_eq(422, $r['status']);
    assert_eq('validation_failed', $r['json']['error']);
    assert_true(isset($r['json']['fields']['name']), 'expected fields.name in body');
});

api_it('POST /projects as employee returns 403 forbidden', function () {
    [, , , $empTok] = pw_setup();
    $r = api_request('POST', '/api/v1/projects', [
        'headers' => ['Authorization: Bearer ' . $empTok],
        'body'    => json_encode(['name' => 'X']),
    ]);
    assert_eq(403, $r['status']);
    assert_eq('forbidden', $r['json']['error']);
});

api_it('PATCH /projects/{id} renames as admin', function () {
    [$pdo, , $adminTok,] = pw_setup();
    pw_seed_project($pdo, 3001, 'Before');
    $r = api_request('PATCH', '/api/v1/projects/3001', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['name' => 'Renamed']),
    ]);
    assert_eq(200, $r['status']);
    $g = api_request('GET', '/api/v1/projects/3001', ['headers' => ['Authorization: Bearer ' . $adminTok]]);
    assert_eq(200, $g['status']);
    assert_eq('Renamed', $g['json']['name']);
});

api_it('PATCH /projects/{id} non-existent returns 404', function () {
    [, , $adminTok,] = pw_setup();
    $r = api_request('PATCH', '/api/v1/projects/9999999', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['name' => 'X']),
    ]);
    assert_eq(404, $r['status']);
});

api_it('DELETE /projects/{id} as admin returns 204', function () {
    [$pdo, , $adminTok,] = pw_setup();
    pw_seed_project($pdo, 3002, 'ToDelete');
    $r = api_request('DELETE', '/api/v1/projects/3002', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(204, $r['status']);
    $g = api_request('GET', '/api/v1/projects/3002', ['headers' => ['Authorization: Bearer ' . $adminTok]]);
    assert_eq(404, $g['status']);
});

api_it('DELETE /projects/{id} as employee returns 403', function () {
    [$pdo, , , $empTok] = pw_setup();
    pw_seed_project($pdo, 3003, 'NoTouch');
    // make sure employee is a member so existence is "seen" (not 404)
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'member')")
        ->execute([3003, 2]);
    $r = api_request('DELETE', '/api/v1/projects/3003', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(403, $r['status']);
});

api_it('POST /projects/{id}/pin sets pinned true idempotently', function () {
    [$pdo, , $adminTok,] = pw_setup();
    pw_seed_project($pdo, 3004, 'Pinning');
    for ($i = 0; $i < 2; $i++) {
        $r = api_request('POST', '/api/v1/projects/3004/pin', [
            'headers' => ['Authorization: Bearer ' . $adminTok],
            'body'    => json_encode(['pinned' => true]),
        ]);
        assert_eq(200, $r['status']);
        assert_eq(true, $r['json']['pinned']);
    }
    $g = api_request('GET', '/api/v1/projects/3004', ['headers' => ['Authorization: Bearer ' . $adminTok]]);
    assert_eq(200, $g['status']);
    assert_eq(true, $g['json']['pinned']);
});

api_it('POST /projects/{id}/pin sets pinned false idempotently', function () {
    [$pdo, , $adminTok,] = pw_setup();
    pw_seed_project($pdo, 3005, 'Unpinning');
    // First pin it.
    api_request('POST', '/api/v1/projects/3005/pin', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['pinned' => true]),
    ]);
    for ($i = 0; $i < 2; $i++) {
        $r = api_request('POST', '/api/v1/projects/3005/pin', [
            'headers' => ['Authorization: Bearer ' . $adminTok],
            'body'    => json_encode(['pinned' => false]),
        ]);
        assert_eq(200, $r['status']);
        assert_eq(false, $r['json']['pinned']);
    }
    $g = api_request('GET', '/api/v1/projects/3005', ['headers' => ['Authorization: Bearer ' . $adminTok]]);
    assert_eq(200, $g['status']);
    assert_eq(false, $g['json']['pinned']);
});

api_it('POST /projects/{id}/pin as employee member returns 403', function () {
    [$pdo, , , $empTok] = pw_setup();
    // Project owned by admin; employee added as plain member (visibility OK).
    pw_seed_project($pdo, 3104, 'EmpPinTry');
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'member')")
        ->execute([3104, 2]);
    // Employees can see the project but pinning is project-wide — it must
    // be gated to managers/admins/owners. Expect 403, not 200.
    $r = api_request('POST', '/api/v1/projects/3104/pin', [
        'headers' => ['Authorization: Bearer ' . $empTok],
        'body'    => json_encode(['pinned' => true]),
    ]);
    assert_eq(403, $r['status']);
    assert_eq('forbidden', $r['json']['error']);
});

api_it('POST /projects/{id}/members adds member', function () {
    [$pdo, , $adminTok,] = pw_setup();
    pw_seed_project($pdo, 3006, 'MemberAdd');
    $r = api_request('POST', '/api/v1/projects/3006/members', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
        'body'    => json_encode(['user_id' => 2]),
    ]);
    assert_eq(201, $r['status']);
    $g = api_request('GET', '/api/v1/projects/3006', ['headers' => ['Authorization: Bearer ' . $adminTok]]);
    assert_eq(200, $g['status']);
    $memberIds = array_column($g['json']['members'], 'user_id');
    assert_true(in_array(2, $memberIds, true), 'expected user 2 in members list');
});

api_it('DELETE /projects/{id}/members/{user_id} removes member', function () {
    [$pdo, , $adminTok,] = pw_setup();
    pw_seed_project($pdo, 3007, 'MemberRemove');
    $pdo->prepare("INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, 'member')")
        ->execute([3007, 2]);
    $r = api_request('DELETE', '/api/v1/projects/3007/members/2', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(204, $r['status']);
    $g = api_request('GET', '/api/v1/projects/3007', ['headers' => ['Authorization: Bearer ' . $adminTok]]);
    assert_eq(200, $g['status']);
    $memberIds = array_column($g['json']['members'], 'user_id');
    assert_true(!in_array(2, $memberIds, true), 'user 2 should no longer be a member');
});
