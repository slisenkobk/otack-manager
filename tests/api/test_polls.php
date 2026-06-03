<?php
// Integration tests for /api/v1/polls endpoints.
// Uses ids 9000+ to isolate from other fixtures.

/** @return array{0:\PDO,1:string,2:string,3:string} pdo, adminTok, managerTok, empTok */
function polls_setup(): array {
    api_request('GET', '/api/v1/ping');
    $pdo = \App\Database\Connection::open(dirname(__DIR__, 2) . '/data/app.api-test.sqlite');
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1,   'U', 'u@x',         'x', 'admin',    'active', '2026-01-01')");
    // 900 is unique to this suite — other tests reuse id 3 as employee.
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (900, 'M', 'm-polls@x',   'x', 'manager',  'active', '2026-01-01')");
    $pdo->exec("INSERT OR IGNORE INTO users (id, name, email, password_hash, role, status, created_at) VALUES (2,   'E', 'e@x',         'x', 'employee', 'active', '2026-01-01')");
    $repo = new \App\Repository\ApiTokenRepository($pdo);
    $adminTok   = $repo->create(1,   'polls-admin-'   . bin2hex(random_bytes(3)))['token'];
    $managerTok = $repo->create(900, 'polls-manager-' . bin2hex(random_bytes(3)))['token'];
    $empTok     = $repo->create(2,   'polls-emp-'     . bin2hex(random_bytes(3)))['token'];
    return [$pdo, $adminTok, $managerTok, $empTok];
}

function polls_seed_poll(\PDO $pdo, int $id, int $createdBy, string $status = 'active'): void {
    $fields = json_encode([
        ['key' => 'contact', 'type' => 'email', 'label' => 'Email', 'required' => true, 'locked' => true],
        ['key' => 'choice', 'type' => 'radio', 'label' => 'Pick one', 'required' => true, 'locked' => true,
            'options' => [
                ['key' => 'opt_1', 'label' => 'Option A'],
                ['key' => 'opt_2', 'label' => 'Option B'],
            ],
        ],
    ]);
    $pdo->prepare(
        "INSERT OR IGNORE INTO polls (id, hash, title, description, fields_json, footer_json, contact_field, locale, status, created_by, created_at, updated_at)
         VALUES (?, ?, ?, NULL, ?, '{}', 'email', 'en', ?, ?, '2026-01-01', '2026-01-01')"
    )->execute([$id, 'phash-' . $id, 'Poll #' . $id, $fields, $status, $createdBy]);
}

function polls_seed_vote(\PDO $pdo, int $id, int $pollId, string $contact, string $choiceKey): void {
    $norm = strtolower(trim($contact));
    $pdo->prepare(
        "INSERT OR IGNORE INTO poll_votes (id, poll_id, contact, contact_normalized, choice_key, remote_ip, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, '127.0.0.1', 'test', '2026-01-01')"
    )->execute([$id, $pollId, $contact, $norm, $choiceKey]);
}

api_it('GET /polls as admin returns list', function () {
    [$pdo, $adminTok,,] = polls_setup();
    polls_seed_poll($pdo, 9001, 1, 'active');

    $r = api_request('GET', '/api/v1/polls', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    $ids = array_column($r['json']['items'], 'id');
    assert_true(in_array(9001, $ids, true), 'admin should see the poll');
});

api_it('GET /polls as manager returns own polls', function () {
    [$pdo, , $managerTok,] = polls_setup();
    polls_seed_poll($pdo, 9002, 900, 'active'); // manager-owned
    polls_seed_poll($pdo, 9003, 1,   'active'); // admin-owned — manager must NOT see

    $r = api_request('GET', '/api/v1/polls', [
        'headers' => ['Authorization: Bearer ' . $managerTok],
    ]);
    assert_eq(200, $r['status']);
    $ids = array_column($r['json']['items'], 'id');
    assert_true(in_array(9002, $ids, true), 'manager should see own poll');
    assert_true(!in_array(9003, $ids, true), 'manager should NOT see admin-owned poll');
});

api_it('GET /polls as employee returns 403', function () {
    [,,, $empTok] = polls_setup();
    $r = api_request('GET', '/api/v1/polls', [
        'headers' => ['Authorization: Bearer ' . $empTok],
    ]);
    assert_eq(403, $r['status']);
});

api_it('GET /polls/{id} returns stats with counts per option', function () {
    [$pdo, $adminTok,,] = polls_setup();
    polls_seed_poll($pdo, 9010, 1, 'active');
    polls_seed_vote($pdo, 9501, 9010, 'a@x', 'opt_1');
    polls_seed_vote($pdo, 9502, 9010, 'b@x', 'opt_1');
    polls_seed_vote($pdo, 9503, 9010, 'c@x', 'opt_2');

    $r = api_request('GET', '/api/v1/polls/9010', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(9010, $r['json']['id']);
    assert_true(is_array($r['json']['fields'] ?? null), 'fields must be array');
    assert_true(is_array($r['json']['stats'] ?? null), 'stats must be array');
    assert_eq(3, $r['json']['stats']['total']);
    $byKey = [];
    foreach ($r['json']['stats']['options'] as $o) { $byKey[$o['key']] = (int)$o['count']; }
    assert_eq(2, $byKey['opt_1'] ?? -1);
    assert_eq(1, $byKey['opt_2'] ?? -1);
});

api_it('GET /polls/{id}/voters returns paginated voters list', function () {
    [$pdo, $adminTok,,] = polls_setup();
    polls_seed_poll($pdo, 9020, 1, 'active');
    polls_seed_vote($pdo, 9601, 9020, 'x1@x', 'opt_1');
    polls_seed_vote($pdo, 9602, 9020, 'x2@x', 'opt_2');

    $r = api_request('GET', '/api/v1/polls/9020/voters', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_true(is_array($r['json']['items'] ?? null), 'items must be array');
    assert_eq(2, count($r['json']['items']));
    $contacts = array_column($r['json']['items'], 'contact');
    assert_true(in_array('x1@x', $contacts, true));
    assert_true(in_array('x2@x', $contacts, true));
    // choice_label resolution
    foreach ($r['json']['items'] as $v) {
        if ($v['choice_key'] === 'opt_1') { assert_eq('Option A', $v['choice_label']); }
        if ($v['choice_key'] === 'opt_2') { assert_eq('Option B', $v['choice_label']); }
    }
    // Newest-first: id DESC. With only 2 votes and default limit, next_cursor is null.
    assert_eq(9602, (int)$r['json']['items'][0]['id']);
    assert_eq(9601, (int)$r['json']['items'][1]['id']);
    assert_true(array_key_exists('next_cursor', $r['json']));
    assert_true($r['json']['next_cursor'] === null, 'next_cursor must be null when no more pages');
});

api_it('GET /polls/{id}/voters uses id-based cursor with ?after', function () {
    [$pdo, $adminTok,,] = polls_setup();
    polls_seed_poll($pdo, 9021, 1, 'active');
    polls_seed_vote($pdo, 9701, 9021, 'a@x', 'opt_1');
    polls_seed_vote($pdo, 9702, 9021, 'b@x', 'opt_2');
    polls_seed_vote($pdo, 9703, 9021, 'c@x', 'opt_1');

    // First page: limit=2, expect 2 newest (9703, 9702), next_cursor = 9702.
    $r = api_request('GET', '/api/v1/polls/9021/voters?limit=2', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r['status']);
    assert_eq(2, count($r['json']['items']));
    assert_eq(9703, (int)$r['json']['items'][0]['id']);
    assert_eq(9702, (int)$r['json']['items'][1]['id']);
    assert_eq(9702, (int)$r['json']['next_cursor']);

    // Follow the cursor: ?after=9702 should return ids strictly less than 9702.
    $r2 = api_request('GET', '/api/v1/polls/9021/voters?limit=2&after=9702', [
        'headers' => ['Authorization: Bearer ' . $adminTok],
    ]);
    assert_eq(200, $r2['status']);
    assert_eq(1, count($r2['json']['items']));
    assert_eq(9701, (int)$r2['json']['items'][0]['id']);
    assert_true($r2['json']['next_cursor'] === null, 'next_cursor must be null when no more pages');
});
