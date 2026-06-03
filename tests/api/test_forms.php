<?php
// Integration tests for /api/v1/forms and /api/v1/submissions endpoints.
// Uses ids 8000+ to isolate from other fixtures.

/** @return array{0:\PDO,1:string,2:string,3:string} pdo, adminTok, managerTok, empTok */
function forms_setup(): array {
    api_request('GET', '/api/v1/ping');
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1, 'U', 'u@x', 'x', 'admin',    'active', '2026-01-01')");
    // 800 is unique to this suite — other tests use 1, 2, 3 with varying roles.
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (800, 'M', 'm-forms@x', 'x', 'manager',  'active', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (2,   'E', 'e@x',         'x', 'employee', 'active', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $adminTok   = $repo->create(1,   'forms-admin-'   . bin2hex(random_bytes(3)))['token'];
    $managerTok = $repo->create(800, 'forms-manager-' . bin2hex(random_bytes(3)))['token'];
    $empTok     = $repo->create(2,   'forms-emp-'     . bin2hex(random_bytes(3)))['token'];
    return [$pdo, $adminTok, $managerTok, $empTok];
}

function forms_seed_form(\PDO $pdo, int $id, int $createdBy, string $title = 'Lead form'): void {
    $fields = json_encode([
        ['key' => 'name',  'type' => 'text',  'label' => 'Your name', 'required' => true,  'options' => []],
        ['key' => 'email', 'type' => 'email', 'label' => 'Email',     'required' => true,  'options' => []],
        ['key' => 'msg',   'type' => 'textarea', 'label' => 'Message','required' => false, 'options' => []],
    ]);
    $footer = json_encode(['show_company' => false]);
    $pdo->prepare(
        "INSERT OR IGNORE INTO forms (id, hash, title, description, fields_json, footer_json, status, locale, created_by, created_at, updated_at)
         VALUES (?, ?, ?, NULL, ?, ?, 'published', 'en', ?, '2026-01-01', '2026-01-01')"
    )->execute([$id, 'hash-' . $id, $title, $fields, $footer, $createdBy]);
}

function forms_seed_submission(\PDO $pdo, int $id, int $formId, string $status = 'new'): void {
    $data = json_encode(['name' => 'Alice', 'email' => 'a@x', 'msg' => 'hello']);
    $pdo->prepare(
        "INSERT OR IGNORE INTO form_submissions (id, form_id, data_json, footer_json, status, created_at, updated_at)
         VALUES (?, ?, ?, '{}', ?, '2026-01-01', '2026-01-01')"
    )->execute([$id, $formId, $data, $status]);
}

api_it('GET /forms as manager returns own forms', function () {
    [$pdo, , $managerTok,] = forms_setup();
    forms_seed_form($pdo, 8001, 800, 'Manager-owned form');
    forms_seed_form($pdo, 8002, 1,   'Admin-owned form'); // manager must not see this one

    $r = api_request('GET', '/api/v1/forms', [
        'headers' => ['Authorization: Bearer ' . $managerTok],
    ]);
    assert_eq(200, $r['status']);
    $ids = array_column($r['json']['items'], 'id');
    assert_true(in_array(8001, $ids, true), 'manager should see own form');
    assert_true(!in_array(8002, $ids, true), 'manager should NOT see admin-owned form');
});

api_it('GET /forms as employee returns 403', function () {
    [,,, $empTok] = forms_setup();
    $r = api_request('GET', '/api/v1/forms', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(403, $r['status']);
});

api_it('GET /forms/{id} returns parsed fields[]', function () {
    [$pdo, $adminTok,,] = forms_setup();
    forms_seed_form($pdo, 8010, 1);

    $r = api_request('GET', '/api/v1/forms/8010', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(8010, $r['json']['id']);
    assert_true(is_array($r['json']['fields'] ?? null), 'fields must be array');
    assert_eq(3, count($r['json']['fields']));
    $keys = array_column($r['json']['fields'], 'key');
    assert_true(in_array('name',  $keys, true));
    assert_true(in_array('email', $keys, true));
});

api_it('GET /forms/{id}/submissions returns paginated list', function () {
    [$pdo, $adminTok,,] = forms_setup();
    forms_seed_form($pdo, 8020, 1);
    forms_seed_submission($pdo, 8101, 8020, 'new');
    forms_seed_submission($pdo, 8102, 8020, 'done');

    $r = api_request('GET', '/api/v1/forms/8020/submissions', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_true(is_array($r['json']['items'] ?? null), 'items must be array');
    assert_eq(2, count($r['json']['items']));
    $ids = array_column($r['json']['items'], 'id');
    assert_true(in_array(8101, $ids, true));
    assert_true(in_array(8102, $ids, true));
});

api_it('GET /submissions/{id} returns single submission with answers', function () {
    [$pdo, $adminTok,,] = forms_setup();
    forms_seed_form($pdo, 8030, 1);
    forms_seed_submission($pdo, 8110, 8030, 'new');

    $r = api_request('GET', '/api/v1/submissions/8110', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(8110, $r['json']['id']);
    assert_eq(8030, $r['json']['form_id']);
    assert_true(is_array($r['json']['answers'] ?? null), 'answers must be array');
    assert_eq('Alice', $r['json']['answers']['name']);
    assert_eq('hello', $r['json']['answers']['msg']);
    assert_true(is_array($r['json']['form']['fields'] ?? null));
});
