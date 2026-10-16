<?php
// The CLI is a script, not a class, so these tests drive it as a subprocess
// and point HOME at a throwaway config directory. No server is started —
// none of these tests need one: every scenario here (usage, an unknown
// command, an out-of-range phase, missing config, a project absent from
// projects.json) is rejected by argument parsing or the local config check
// before the CLI would make its first HTTP call. Coverage of the commands
// that DO talk to the API (whoami, next, task, move, ...) is the live check
// in Step 5, run once against a real 1.7.1+ instance — this repository has
// no fake HTTP server to drive those paths in the unit suite.
//
// See test_agent_config.php for why the file starts with an existence
// guard: tests/run.php's bare top-level `require` turns a missing-file
// require_once into a fatal that aborts the whole suite. This file has no
// require_once of a library, but agent/bin/otack is exactly as absent on
// the red step, so the guard here checks for the script itself and skips
// straight to one clean failing assertion instead of running five tests
// against a binary that doesn't exist yet.
$__bin = dirname(__DIR__, 2) . '/agent/bin/otack';
if (!is_file($__bin)) {
    it('agent/bin/otack exists', fn() => assert_true(false, 'not implemented yet'));
    return;
}

function _cliHome(): string {
    $home = sys_get_temp_dir() . '/otack-cli-test-' . bin2hex(random_bytes(4));
    mkdir($home . '/.claude/otack-agent', 0700, true);
    file_put_contents($home . '/.claude/otack-agent/.env',
        "OTACK_API_URL=http://localhost:8771/api/v1\nOTACK_API_TOKEN=otk_test\n");
    chmod($home . '/.claude/otack-agent/.env', 0600);
    // Config::load() now realpath()s and requires every project's "path"
    // to exist and be a directory (M4) — a fixture pointing at a
    // non-existent path like the old literal "/tmp/p42" would make
    // Config::load() itself throw, before any of the command-specific
    // behaviour under test ever runs. Use a real throwaway directory.
    $projectDir = $home . '/p42';
    mkdir($projectDir, 0700, true);
    file_put_contents($home . '/.claude/otack-agent/projects.json',
        json_encode(['projects' => ['42' => ['path' => $projectDir, 'write' => true]]]));
    return $home;
}

function _cli(string $args, string $home, string $stdin = '', ?string $scope = 'agent'): array {
    $bin = dirname(__DIR__, 2) . '/agent/bin/otack';
    $env = 'HOME=' . escapeshellarg($home) . ($scope !== null ? ' OTACK_SCOPE=' . escapeshellarg($scope) : '');
    // -u: a scope-less run must stay scope-less even if the test runner's
    // own environment happens to carry OTACK_SCOPE.
    $cmd = 'env ' . ($scope === null ? '-u OTACK_SCOPE ' : '') . $env . ' ' . PHP_BINARY . ' ' . escapeshellarg($bin) . ' ' . $args;
    $p = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
}
require_once __DIR__ . '/agent_fixture_server.php';

/** _cliHome(), but OTACK_API_URL points at the fixture server on $port. */
function _cliHomeForServer(int $port, ?array $projects = null): string {
    $home = sys_get_temp_dir() . '/otack-cli-test-' . bin2hex(random_bytes(4));
    mkdir($home . '/.claude/otack-agent', 0700, true);
    file_put_contents($home . '/.claude/otack-agent/.env',
        "OTACK_API_URL=http://127.0.0.1:$port/api/v1\nOTACK_API_TOKEN=otk_test\n");
    chmod($home . '/.claude/otack-agent/.env', 0600);
    if ($projects === null) {
        $projectDir = $home . '/p42';
        mkdir($projectDir, 0700, true);
        $projects = ['42' => ['path' => $projectDir, 'write' => true]];
    }
    file_put_contents($home . '/.claude/otack-agent/projects.json',
        json_encode(['projects' => $projects]));
    return $home;
}

/** _cliHomeForServer() plus a manager .env pointing at the same fixture server. */
function _cliManagerHome(int $port, ?array $projects = null): string {
    $home = _cliHomeForServer($port, $projects);
    mkdir($home . '/.claude/otack-manager', 0700, true);
    file_put_contents($home . '/.claude/otack-manager/.env',
        "OTACK_API_URL=http://127.0.0.1:$port/api/v1\nOTACK_API_TOKEN=otk_owner\n");
    chmod($home . '/.claude/otack-manager/.env', 0600);
    return $home;
}


it('otack with no arguments prints usage and exits non-zero', function () {
    $r = _cli('', _cliHome());
    assert_true($r['code'] !== 0, 'expected a non-zero exit');
    assert_true(str_contains($r['err'], 'whoami'), 'usage should list the commands');
});

it('otack rejects an unknown command', function () {
    $r = _cli('frobnicate', _cliHome());
    assert_true($r['code'] !== 0, 'expected a non-zero exit');
    assert_true(str_contains($r['err'], 'frobnicate'), 'error should name the bad command');
});

it('otack state rejects a phase outside the closed set', function () {
    $r = _cli('state 7 pondering', _cliHome());
    assert_true($r['code'] !== 0, 'expected a non-zero exit');
    assert_true(str_contains($r['err'], 'researching'), 'error should list the valid phases');
});

it('otack reports a missing config as a clear error, not a stack trace', function () {
    $empty = sys_get_temp_dir() . '/otack-cli-empty-' . bin2hex(random_bytes(4));
    mkdir($empty, 0700, true);
    $r = _cli('whoami', $empty);
    assert_true($r['code'] !== 0, 'expected a non-zero exit');
    assert_true(str_contains($r['err'], 'otack-agent'), 'error should name the config directory');
    assert_true(!str_contains($r['err'], 'Stack trace'), 'should not leak a stack trace');
});

it('otack project refuses a project absent from projects.json', function () {
    $r = _cli('project 99', _cliHome());
    assert_true($r['code'] !== 0, 'expected a non-zero exit');
    assert_true(str_contains($r['err'], 'projects.json'), 'error should point at the local allow-list');
});

// M3 (adversarial review of 8144dfe): tick-note now fetches the task first
// and gates on requireLocalProject(), same shape as state/move/comment/
// new-comments above — so every scenario below that gets past phase
// validation needs a fixture server answering GET /tasks/{id}, not the
// server-less _cliHome() the pre-M3 version of these tests used.

it('otack tick-note rewrites the lock file with task_id and phase, keeping the other keys', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/7' => [['status' => 200, 'body' => json_encode(['id' => 7, 'project_id' => 42])]],
    ], function (int $port) {
        $home = _cliHomeForServer($port);
        $lockPath = $home . '/.claude/otack-agent/agent.lock';
        file_put_contents($lockPath, json_encode([
            'pid' => 4242, 'host' => 'test-host', 'started_at' => 1700000000, 'started_iso' => '2023-11-14T22:13:20Z',
        ]));
        $r = _cli('tick-note 7 implementing', $home);
        assert_eq(0, $r['code'], $r['err']);
        $info = json_decode((string)file_get_contents($lockPath), true);
        assert_eq(7, $info['task_id']);
        assert_eq('implementing', $info['phase']);
        assert_eq(4242, $info['pid'], 'tick-note must preserve the keys otack-tick already wrote, not replace the file');
    });
});

it('otack tick-note rejects a phase outside the closed set', function () {
    // No fixture server needed: phase validation runs before the M3
    // allow-list gate, so this must fail before any network call.
    $home = _cliHome();
    file_put_contents($home . '/.claude/otack-agent/agent.lock', json_encode(['pid' => 1]));
    $r = _cli('tick-note 7 pondering', $home);
    assert_true($r['code'] !== 0, 'expected a non-zero exit');
    assert_true(str_contains($r['err'], 'researching'), 'error should list the valid phases');
    assert_true(!str_contains($r['err'], 'HTTP request failed'), 'must reject before ever making a network call');
});

it('otack tick-note refuses a project absent from projects.json before touching the lock file', function () {
    // M3: the allow-list gate itself, mirroring the state/move/comment/
    // new-comments tests above. Deliberately no agent.lock on disk — if
    // the gate did not run first, this would instead fail with "no
    // agent.lock", which would be the pre-M3 bug (a task in an unlisted
    // project reaching the lock-file write at all) reading as a pass.
    _cliFixtureServer([
        'GET /api/v1/tasks/40' => [['status' => 200, 'body' => json_encode(['id' => 40, 'project_id' => 999])]],
    ], function (int $port) {
        $home = _cliHomeForServer($port);
        $r = _cli('tick-note 40 implementing', $home);
        assert_true($r['code'] !== 0, 'expected a non-zero exit');
        assert_true(str_contains($r['err'], 'projects.json'), 'error should point at the local allow-list');
        assert_true(!str_contains($r['err'], 'agent.lock'), 'must refuse via the allow-list gate, not the lock-file check');
    });
});

it('otack tick-note fails clearly when there is no lock file to write into', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/7' => [['status' => 200, 'body' => json_encode(['id' => 7, 'project_id' => 42])]],
    ], function (int $port) {
        $home = _cliHomeForServer($port);
        // No agent.lock written for this home — the gate above passes
        // (project 42 is listed), so this exercises the lock-file-specific
        // failure, not the allow-list one.
        $r = _cli('tick-note 7 implementing', $home);
        assert_true($r['code'] !== 0, 'expected a non-zero exit');
        assert_true(str_contains($r['err'], 'agent.lock'), 'error should name the lock file it expected to update');
    });
});

it('otack tick-note fails cleanly when the lock file cannot be written', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        return; // root bypasses file permission bits — nothing to prove here.
    }
    _cliFixtureServer([
        'GET /api/v1/tasks/7' => [['status' => 200, 'body' => json_encode(['id' => 7, 'project_id' => 42])]],
    ], function (int $port) {
        $home = _cliHomeForServer($port);
        $lockPath = $home . '/.claude/otack-agent/agent.lock';
        file_put_contents($lockPath, json_encode(['pid' => 1]));
        chmod($lockPath, 0400);
        $r = _cli('tick-note 7 implementing', $home);
        chmod($lockPath, 0600); // restore so the temp dir can be cleaned up normally
        assert_true($r['code'] !== 0, 'expected a non-zero exit');
        assert_true(str_contains($r['err'], 'failed to write'), 'error should say the lock file could not be written, not crash');
    });
});

// --- C1: a non-https, non-loopback config typo must not leak the token -----

it('otack refuses a non-https non-loopback OTACK_API_URL without printing the token or a stack trace', function () {
    $home = sys_get_temp_dir() . '/otack-cli-test-' . bin2hex(random_bytes(4));
    mkdir($home . '/.claude/otack-agent', 0700, true);
    file_put_contents($home . '/.claude/otack-agent/.env',
        "OTACK_API_URL=http://example.com/api/v1\nOTACK_API_TOKEN=SECRETTOKEN123ABC\n");
    chmod($home . '/.claude/otack-agent/.env', 0600);

    $r = _cli('whoami', $home);
    assert_true($r['code'] !== 0, 'expected a non-zero exit');
    assert_true(str_contains($r['err'], 'https'), 'error should explain the https requirement');
    assert_true(!str_contains($r['err'], 'SECRETTOKEN123ABC'), 'the bearer token must never appear on stderr');
    assert_true(!str_contains($r['out'], 'SECRETTOKEN123ABC'), 'the bearer token must never appear on stdout');
    assert_true(!str_contains($r['err'], 'Stack trace'), 'should not leak a stack trace');
});

// --- ctype_digit: a numeric id argument with trailing garbage is rejected --

it('otack task rejects a non-numeric id instead of acting on its numeric prefix', function () {
    $r = _cli('task 5abc', _cliHome());
    assert_true($r['code'] !== 0, 'expected a non-zero exit');
    assert_true(str_contains($r['err'], 'usage: otack task'), 'should be a clean usage error');
    assert_true(!str_contains($r['err'], 'HTTP request failed'), 'must reject before ever making a network call');
});

// --- fixture-server-backed tests --------------------------------------------

it('otack next does not pick a card sitting in an is_done column', function () {
    _cliFixtureServer([
        'GET /api/v1/me'           => [['status' => 200, 'body' => json_encode(['id' => 1])]],
        'GET /api/v1/tasks'        => [['status' => 200, 'body' => json_encode([
            'items' => [['id' => 10, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null]],
            'next_cursor' => null,
        ])]],
        'GET /api/v1/projects/42'  => [['status' => 200, 'body' => json_encode([
            'columns' => [['id' => 5, 'is_done' => true, 'is_backlog' => false]],
        ])]],
    ], function (int $port, string $logFile) {
        $home = _cliHomeForServer($port);
        $r = _cli('next', $home);
        assert_eq(0, $r['code']);
        $out = json_decode($r['out'], true);
        assert_eq(null, $out['task']);
        assert_eq('idle', $out['reason']);
        $log = _requestLog($logFile);
        foreach ($log as $entry) {
            assert_true($entry['path'] !== '/api/v1/tasks/10', 'a done-column card must never be fetched by id at all');
        }
    });
});

it('otack next drops a from_form winner at the detail stage and returns the next candidate', function () {
    _cliFixtureServer([
        'GET /api/v1/me'          => [['status' => 200, 'body' => json_encode(['id' => 1])]],
        'GET /api/v1/tasks'       => [['status' => 200, 'body' => json_encode([
            'items' => [
                ['id' => 10, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null],
                ['id' => 11, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null],
            ],
            'next_cursor' => null,
        ])]],
        'GET /api/v1/projects/42' => [['status' => 200, 'body' => json_encode([
            'columns' => [['id' => 5, 'is_done' => false, 'is_backlog' => false]],
        ])]],
        'GET /api/v1/tasks/10'    => [['status' => 200, 'body' => json_encode([
            'id' => 10, 'project_id' => 42, 'from_form' => true, 'agent_state' => null,
        ])]],
        'GET /api/v1/tasks/11'    => [['status' => 200, 'body' => json_encode([
            'id' => 11, 'project_id' => 42, 'from_form' => false, 'agent_state' => null,
        ])]],
    ], function (int $port, string $logFile) {
        $home = _cliHomeForServer($port);
        $r = _cli('next', $home);
        assert_eq(0, $r['code']);
        $out = json_decode($r['out'], true);
        assert_eq(11, $out['task']['id']);
        assert_eq('new', $out['reason']);
        assert_eq(realpath($home . '/p42'), $out['local']['path'], "next's output should carry the project's local path");
        $log = _requestLog($logFile);
        $paths = array_column($log, 'path');
        $i10 = array_search('/api/v1/tasks/10', $paths, true);
        $i11 = array_search('/api/v1/tasks/11', $paths, true);
        assert_true($i10 !== false && $i11 !== false && $i10 < $i11, 'task 10 must be re-verified and dropped before task 11 is tried');
    });
});

it('otack next fails loud on a 500 from GET /projects/{id} instead of reporting idle', function () {
    _cliFixtureServer([
        'GET /api/v1/me'          => [['status' => 200, 'body' => json_encode(['id' => 1])]],
        'GET /api/v1/tasks'       => [['status' => 200, 'body' => json_encode([
            'items' => [['id' => 10, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null]],
            'next_cursor' => null,
        ])]],
        'GET /api/v1/projects/42' => [['status' => 500, 'body' => '{}']],
    ], function (int $port) {
        $home = _cliHomeForServer($port);
        $r = _cli('next', $home);
        assert_true($r['code'] !== 0, 'a broken project must fail loud, not report idle with exit 0');
        assert_true(str_contains($r['err'], '500'), 'error should name the failing status');
    });
});

it('otack next skips a card that only reveals agent_state=blocked at the detail stage', function () {
    _cliFixtureServer([
        'GET /api/v1/me'          => [['status' => 200, 'body' => json_encode(['id' => 1])]],
        'GET /api/v1/tasks'       => [['status' => 200, 'body' => json_encode([
            'items' => [
                ['id' => 10, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null],
                ['id' => 11, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null],
            ],
            'next_cursor' => null,
        ])]],
        'GET /api/v1/projects/42' => [['status' => 200, 'body' => json_encode([
            'columns' => [['id' => 5, 'is_done' => false, 'is_backlog' => false]],
        ])]],
        'GET /api/v1/tasks/10'    => [['status' => 200, 'body' => json_encode([
            'id' => 10, 'project_id' => 42, 'from_form' => false, 'agent_state' => 'blocked',
        ])]],
        'GET /api/v1/tasks/11'    => [['status' => 200, 'body' => json_encode([
            'id' => 11, 'project_id' => 42, 'from_form' => false, 'agent_state' => null,
        ])]],
    ], function (int $port) {
        $home = _cliHomeForServer($port);
        $r = _cli('next', $home);
        assert_eq(0, $r['code']);
        $out = json_decode($r['out'], true);
        assert_eq(11, $out['task']['id']);
    });
});

it('otack next uses a distinct idle reason when the 10-round from_form bound is exhausted', function () {
    $items = [];
    $routes = [
        'GET /api/v1/me'          => [['status' => 200, 'body' => json_encode(['id' => 1])]],
        'GET /api/v1/projects/42' => [['status' => 200, 'body' => json_encode([
            'columns' => [['id' => 5, 'is_done' => false, 'is_backlog' => false]],
        ])]],
    ];
    for ($i = 1; $i <= 10; $i++) {
        $items[] = ['id' => $i, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null];
        $routes["GET /api/v1/tasks/$i"] = [['status' => 200, 'body' => json_encode([
            'id' => $i, 'project_id' => 42, 'from_form' => true, 'agent_state' => null,
        ])]];
    }
    $routes['GET /api/v1/tasks'] = [['status' => 200, 'body' => json_encode(['items' => $items, 'next_cursor' => null])]];

    _cliFixtureServer($routes, function (int $port, string $logFile) {
        $home = _cliHomeForServer($port);
        $r = _cli('next', $home);
        assert_eq(0, $r['code']);
        $out = json_decode($r['out'], true);
        assert_eq(null, $out['task']);
        assert_true($out['reason'] !== 'idle', 'exhausting the from_form bound must not read as the same idle as genuinely no work');
        assert_true(str_contains($out['reason'], 'idle'), 'still an idle-family reason, just a distinct one');

        $log = _requestLog($logFile);
        $detailHits = array_filter($log, fn($e) => preg_match('#^/api/v1/tasks/\d+$#', $e['path']));
        assert_eq(10, count($detailHits), 'expected all 10 rounds of the bound to run, not fewer');
    });
});

it('otack state PATCHes agent_state, not a "state" field', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/10'   => [['status' => 200, 'body' => json_encode(['id' => 10, 'project_id' => 42])]],
        'PATCH /api/v1/tasks/10' => [['status' => 200, 'body' => json_encode(['id' => 10, 'agent_state' => 'blocked'])]],
    ], function (int $port, string $logFile) {
        $home = _cliHomeForServer($port);
        $r = _cli('state 10 blocked', $home);
        assert_eq(0, $r['code']);
        $log = _requestLog($logFile);
        $patch = array_values(array_filter($log, fn($e) => $e['method'] === 'PATCH'))[0] ?? null;
        assert_true($patch !== null, 'expected a PATCH request');
        assert_eq(['agent_state' => 'blocked'], $patch['body']);
    });
});

it('otack state refuses a project absent from projects.json before writing anything', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/20'   => [['status' => 200, 'body' => json_encode(['id' => 20, 'project_id' => 999])]],
        'PATCH /api/v1/tasks/20' => [['status' => 200, 'body' => json_encode(['id' => 20])]],
    ], function (int $port, string $logFile) {
        $home = _cliHomeForServer($port);
        $r = _cli('state 20 blocked', $home);
        assert_true($r['code'] !== 0, 'expected a non-zero exit');
        assert_true(str_contains($r['err'], 'projects.json'), 'error should point at the local allow-list');
        $log = _requestLog($logFile);
        assert_true(!in_array('PATCH', array_column($log, 'method'), true), 'must refuse before ever writing');
    });
});

it('otack move refuses a project absent from projects.json before writing anything', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/21' => [['status' => 200, 'body' => json_encode(['id' => 21, 'project_id' => 999])]],
    ], function (int $port, string $logFile) {
        $home = _cliHomeForServer($port);
        $r = _cli('move 21 5', $home);
        assert_true($r['code'] !== 0, 'expected a non-zero exit');
        assert_true(str_contains($r['err'], 'projects.json'), 'error should point at the local allow-list');
        $log = _requestLog($logFile);
        $paths = array_column($log, 'path');
        assert_true(!in_array('/api/v1/projects/999/tasks', $paths, true), 'must refuse before fetching column state');
        assert_true(!in_array('/api/v1/tasks/21/move', $paths, true), 'must refuse before posting the move');
    });
});

it('otack comment refuses a project absent from projects.json before touching the network body', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/22' => [['status' => 200, 'body' => json_encode(['id' => 22, 'project_id' => 999])]],
    ], function (int $port, string $logFile) {
        $home = _cliHomeForServer($port);
        $r = _cli('comment 22', $home, "some comment body\n");
        assert_true($r['code'] !== 0, 'expected a non-zero exit');
        assert_true(str_contains($r['err'], 'projects.json'), 'error should point at the local allow-list');
        $log = _requestLog($logFile);
        assert_true(!in_array('/api/v1/comments', array_column($log, 'path'), true), 'must refuse before posting the comment');
    });
});

it('otack new-comments refuses a project absent from projects.json before fetching the thread', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/23' => [['status' => 200, 'body' => json_encode(['id' => 23, 'project_id' => 999])]],
    ], function (int $port, string $logFile) {
        $home = _cliHomeForServer($port);
        $r = _cli('new-comments 23', $home);
        assert_true($r['code'] !== 0, 'expected a non-zero exit');
        assert_true(str_contains($r['err'], 'projects.json'), 'error should point at the local allow-list');
        $log = _requestLog($logFile);
        $paths = array_column($log, 'path');
        assert_true(!in_array('/api/v1/tasks/23/comments', $paths, true), 'must refuse before fetching the thread');
        assert_true(!in_array('/api/v1/me', $paths, true), 'must refuse before even resolving self');
    });
});

it('otack new-comments honours the watermark instead of returning the whole thread', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/5'          => [['status' => 200, 'body' => json_encode(['id' => 5, 'project_id' => 42])]],
        'GET /api/v1/me'               => [['status' => 200, 'body' => json_encode(['id' => 1])]],
        'GET /api/v1/tasks/5/comments' => [['status' => 200, 'body' => json_encode([
            'items' => [
                ['id' => 1, 'user_id' => 1, 'body' => 'a'],
                ['id' => 2, 'user_id' => 2, 'body' => 'b'],
                ['id' => 3, 'user_id' => 1, 'body' => 'c'],
                ['id' => 4, 'user_id' => 2, 'body' => 'd'],
            ],
            'next_cursor' => null,
        ])]],
    ], function (int $port) {
        $home = _cliHomeForServer($port);
        $r = _cli('new-comments 5', $home);
        assert_eq(0, $r['code']);
        $out = json_decode($r['out'], true);
        assert_eq([4], array_column($out['items'], 'id'), 'expected only the comment after the watermark (id 3, the agent\'s own last)');
    });
});

it('otack whoami: successful output lands on stdout and parses as JSON, stderr stays empty', function () {
    _cliFixtureServer([
        'GET /api/v1/me' => [['status' => 200, 'body' => json_encode(['id' => 1, 'name' => 'Bot'])]],
    ], function (int $port) {
        $home = _cliHomeForServer($port);
        $r = _cli('whoami', $home);
        assert_eq(0, $r['code']);
        assert_eq('', $r['err'], 'a successful command must not write anything to stderr');
        $decoded = json_decode($r['out'], true);
        assert_true($decoded !== null, 'stdout must parse as JSON');
        assert_eq(['id' => 1, 'name' => 'Bot'], $decoded);
    });
});

// --- M3: a malformed /me response must fail loud, not degrade silently -----

it('otack whoami fails cleanly when /me is missing "id" instead of leaking a PHP warning', function () {
    _cliFixtureServer([
        'GET /api/v1/me' => [['status' => 200, 'body' => json_encode(['name' => 'Bot'])]],
    ], function (int $port) {
        $home = _cliHomeForServer($port);
        $r = _cli('whoami', $home);
        assert_true($r['code'] !== 0, 'expected a non-zero exit');
        assert_true(str_contains($r['err'], 'unexpected shape'), 'error should name the malformed /me response');
        assert_eq('', $r['out'], 'stdout must stay empty on failure');
        assert_true(!str_contains($r['err'], 'Warning'), 'must not leak a PHP diagnostic instead of a clean error');
    });
});

it('otack whoami fails cleanly when /me returns null instead of crashing with a TypeError', function () {
    _cliFixtureServer([
        'GET /api/v1/me' => [['status' => 200, 'body' => 'null']],
    ], function (int $port) {
        $home = _cliHomeForServer($port);
        $r = _cli('whoami', $home);
        assert_true($r['code'] !== 0, 'expected a non-zero exit');
        assert_true(str_contains($r['err'], 'unexpected shape'), 'error should name the malformed /me response');
        assert_true(!str_contains($r['err'], 'TypeError') || str_contains($r['err'], 'unexpected shape'), 'a clean message, not a raw TypeError trace');
    });
});

// --- M2: an invalid-UTF-8 comment body must fail cleanly --------------------

it('otack comment refuses an invalid-UTF-8 body instead of crashing on json_encode failure', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/30' => [['status' => 200, 'body' => json_encode(['id' => 30, 'project_id' => 42])]],
    ], function (int $port, string $logFile) {
        $home = _cliHomeForServer($port);
        $badBody = "line one\xC3\x28 broken continuation byte";
        $r = _cli('comment 30', $home, $badBody);
        assert_true($r['code'] !== 0, 'expected a non-zero exit');
        assert_true(str_contains($r['err'], 'UTF-8'), 'error should name the encoding problem');
        assert_true(!str_contains($r['err'], 'Stack trace'), 'should not leak a stack trace');
        $log = _requestLog($logFile);
        assert_true(!in_array('/api/v1/comments', array_column($log, 'path'), true), 'must refuse before posting');
    });
});

it('otack without OTACK_SCOPE refuses to run (fail closed)', function () {
    $home = _cliHome();
    $r = _cli('whoami', $home, '', null);
    assert_eq(2, $r['code']);
    assert_true(str_contains($r['err'], 'OTACK_SCOPE'), $r['err']);
    $r = _cli('whoami', $home, '', 'admin');
    assert_eq(2, $r['code']);
});

it('otack --help works without a scope', function () {
    $r = _cli('--help', _cliHome(), '', null);
    assert_eq(2, $r['code']);
    assert_true(str_contains($r['err'], 'todo'), $r['err']);
});

it('manager scope reads a task of an unlisted project with the owner token', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/77'          => [['status' => 200, 'body' => json_encode(['id' => 77, 'project_id' => 900])]],
        'GET /api/v1/projects/900'      => [['status' => 200, 'body' => json_encode(['id' => 900, 'columns' => []])]],
        'GET /api/v1/tasks/77/comments' => [['status' => 200, 'body' => json_encode(['items' => [], 'next_cursor' => null])]],
        'GET /api/v1/me'                => [['status' => 200, 'body' => json_encode(['id' => 3])]],
    ], function (int $port, string $log) {
        $home = _cliManagerHome($port);
        $r = _cli('task 77', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        $j = json_decode($r['out'], true);
        assert_eq(null, $j['local']);
        foreach (_requestLog($log) as $e) { assert_eq('Bearer otk_owner', $e['auth']); }
    });
});

it('agent scope still refuses the same unlisted project before any write', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/77' => [['status' => 200, 'body' => json_encode(['id' => 77, 'project_id' => 900])]],
    ], function (int $port, string $log) {
        $home = _cliManagerHome($port);
        $r = _cli('comment 77', $home, "hi\n", 'agent');
        assert_eq(1, $r['code']);
        assert_true(str_contains($r['err'], 'is not listed'), $r['err']);
        assert_eq([], array_values(array_filter(_requestLog($log), fn($e) => $e['method'] !== 'GET')));
        foreach (_requestLog($log) as $e) { assert_eq('Bearer otk_test', $e['auth']); }
    });
});

it('manager scope comments on an unlisted project', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/77'  => [['status' => 200, 'body' => json_encode(['id' => 77, 'project_id' => 900])]],
        'POST /api/v1/comments' => [['status' => 201, 'body' => json_encode(['id' => 5])]],
    ], function (int $port, string $log) {
        $r = _cli('comment 77', _cliManagerHome($port), "Согласовано\n", 'manager');
        assert_eq(0, $r['code'], $r['err']);
        $post = array_values(array_filter(_requestLog($log), fn($e) => $e['method'] === 'POST'))[0];
        assert_eq(['entity' => 'task', 'entity_id' => 77, 'body' => "Согласовано\n"], $post['body']);
    });
});

it('next and tick-note run only in agent scope', function () {
    $home = _cliHome();
    mkdir($home . '/.claude/otack-manager', 0700, true);
    file_put_contents($home . '/.claude/otack-manager/.env', "OTACK_API_URL=http://localhost:8771/api/v1\nOTACK_API_TOKEN=otk_owner\n");
    chmod($home . '/.claude/otack-manager/.env', 0600);
    foreach (['next', 'tick-note 1 researching'] as $cmd) {
        $r = _cli($cmd, $home, '', 'manager');
        assert_eq(2, $r['code'], $cmd);
        assert_true(str_contains($r['err'], "bot's scope"), $r['err']);
    }
});

it('projects lists visible projects with their local entry or null', function () {
    _cliFixtureServer([
        'GET /api/v1/projects' => [['status' => 200, 'body' => json_encode(['items' => [
            ['id' => 42, 'name' => 'Listed', 'slug' => 'listed', 'repo_url' => null],
            ['id' => 900, 'name' => 'New one', 'slug' => 'new-one', 'repo_url' => 'git@github.com:o/new-one.git'],
        ], 'next_cursor' => null])]],
    ], function (int $port) {
        $r = _cli('projects', _cliManagerHome($port), '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        $items = json_decode($r['out'], true)['items'];
        assert_true(is_array($items[0]['local']));
        assert_eq(null, $items[1]['local']);
        assert_eq('git@github.com:o/new-one.git', $items[1]['repo_url']);
    });
});

it('todo --project ranks the project\'s open tasks across assignees', function () {
    _cliFixtureServer([
        'GET /api/v1/me'                => [['status' => 200, 'body' => json_encode(['id' => 3])]],
        'GET /api/v1/projects/900'      => [['status' => 200, 'body' => json_encode(['id' => 900, 'name' => 'New one', 'columns' => [
            ['id' => 1, 'is_backlog' => true], ['id' => 2], ['id' => 3, 'is_done' => true]]])]],
        'GET /api/v1/projects/900/tasks' => [['status' => 200, 'body' => json_encode(['items' => [
            ['id' => 10, 'column_id' => 2, 'priority' => 'low', 'assignee_id' => null],
            ['id' => 11, 'column_id' => 2, 'priority' => 'urgent', 'assignee_id' => 8],
            ['id' => 12, 'column_id' => 3, 'priority' => 'urgent'],
            ['id' => 13, 'column_id' => 1, 'priority' => 'urgent'],
        ], 'next_cursor' => null])]],
        'GET /api/v1/projects/900/tags' => [['status' => 200, 'body' => json_encode(['items' => []])]],
    ], function (int $port) {
        $r = _cli('todo --project 900', _cliManagerHome($port), '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        $j = json_decode($r['out'], true);
        assert_eq([11, 10], array_map(fn($i) => $i['task']['id'], $j['items']));
        assert_eq(['id' => 900, 'name' => 'New one'], $j['items'][0]['project']);
        assert_eq(['priority:urgent'], $j['items'][0]['rank']['reasons']);
        assert_eq(false, $j['items'][0]['local']);
    });
});

it('todo without --project lists only my tasks; --assignee any is refused there', function () {
    _cliFixtureServer([
        'GET /api/v1/me'               => [['status' => 200, 'body' => json_encode(['id' => 3])]],
        'GET /api/v1/tasks'            => [['status' => 200, 'body' => json_encode(['items' => [
            ['id' => 20, 'project_id' => 42, 'column_id' => 2, 'priority' => 'high', 'assignee_id' => 3],
        ], 'next_cursor' => null])]],
        'GET /api/v1/projects/42'      => [['status' => 200, 'body' => json_encode(['id' => 42, 'name' => 'Listed', 'columns' => [['id' => 2]]])]],
        'GET /api/v1/projects/42/tags' => [['status' => 200, 'body' => json_encode(['items' => []])]],
    ], function (int $port, string $log) {
        $home = _cliManagerHome($port);
        $r = _cli('todo', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        assert_eq([20], array_map(fn($i) => $i['task']['id'], json_decode($r['out'], true)['items']));
        $q = array_values(array_filter(_requestLog($log), fn($e) => $e['path'] === '/api/v1/tasks'))[0]['query'];
        assert_eq('3', (string)$q['assignee_id']);
        assert_eq(2, _cli('todo --assignee any', $home, '', 'manager')['code']);
    });
});

it('assign me PATCHes assignee_id with my id; assign none sends null', function () {
    _cliFixtureServer([
        'GET /api/v1/me'        => [['status' => 200, 'body' => json_encode(['id' => 3])]],
        'GET /api/v1/tasks/77'  => [['status' => 200, 'body' => json_encode(['id' => 77, 'project_id' => 900])]],
        'PATCH /api/v1/tasks/77' => [['status' => 200, 'body' => json_encode(['id' => 77])]],
    ], function (int $port, string $log) {
        $home = _cliManagerHome($port);
        assert_eq(0, _cli('assign 77 me', $home, '', 'manager')['code']);
        assert_eq(0, _cli('assign 77 none', $home, '', 'manager')['code']);
        $patches = array_values(array_filter(_requestLog($log), fn($e) => $e['method'] === 'PATCH'));
        assert_eq(['assignee_id' => 3], $patches[0]['body']);
        assert_eq(['assignee_id' => null], $patches[1]['body']);
        $before = count(_requestLog($log));
        assert_eq(2, _cli('assign 77 me', $home, '', 'agent')['code'], 'assign is manager-only');
        assert_eq($before, count(_requestLog($log)), 'agent-scope assign must refuse before any request');
        assert_eq(2, _cli('assign 77 bob', $home, '', 'manager')['code']);
    });
});

it('unknown options are refused', function () {
    assert_eq(2, _cli('todo --everything', _cliHome(), '', 'agent')['code']);
});

it('projects in agent scope lists only projects present in projects.json', function () {
    _cliFixtureServer([
        'GET /api/v1/projects' => [['status' => 200, 'body' => json_encode(['items' => [
            ['id' => 42, 'name' => 'Listed', 'slug' => 'listed'],
            ['id' => 900, 'name' => 'New one', 'slug' => 'new-one'],
        ], 'next_cursor' => null])]],
    ], function (int $port) {
        $r = _cli('projects', _cliManagerHome($port), '', 'agent');
        assert_eq(0, $r['code'], $r['err']);
        assert_eq([42], array_map(fn($i) => $i['id'], json_decode($r['out'], true)['items']));
    });
});

it('todo --project in agent scope refuses an unlisted project before fetching it', function () {
    _cliFixtureServer([
        'GET /api/v1/me' => [['status' => 200, 'body' => json_encode(['id' => 3])]],
    ], function (int $port, string $log) {
        $r = _cli('todo --project 900', _cliManagerHome($port), '', 'agent');
        assert_eq(1, $r['code']);
        assert_true(str_contains($r['err'], 'is not listed'), $r['err']);
        assert_eq([], array_values(array_filter(_requestLog($log), fn($e) => str_starts_with($e['path'], '/api/v1/projects/900'))));
    });
});

it('todo without --project in agent scope drops tasks of unlisted projects', function () {
    _cliFixtureServer([
        'GET /api/v1/me'               => [['status' => 200, 'body' => json_encode(['id' => 3])]],
        'GET /api/v1/tasks'            => [['status' => 200, 'body' => json_encode(['items' => [
            ['id' => 20, 'project_id' => 42, 'column_id' => 2, 'priority' => 'high', 'assignee_id' => 3],
            ['id' => 21, 'project_id' => 900, 'column_id' => 5, 'priority' => 'urgent', 'assignee_id' => 3],
        ], 'next_cursor' => null])]],
        'GET /api/v1/projects/42'      => [['status' => 200, 'body' => json_encode(['id' => 42, 'name' => 'Listed', 'columns' => [['id' => 2]]])]],
        'GET /api/v1/projects/42/tags' => [['status' => 200, 'body' => json_encode(['items' => []])]],
    ], function (int $port, string $log) {
        $r = _cli('todo', _cliManagerHome($port), '', 'agent');
        assert_eq(0, $r['code'], $r['err']);
        assert_eq([20], array_map(fn($i) => $i['task']['id'], json_decode($r['out'], true)['items']));
        assert_eq([], array_values(array_filter(_requestLog($log), fn($e) => str_starts_with($e['path'], '/api/v1/projects/900'))));
    });
});

it('todo --project with a non-numeric id is a usage error (exit 2)', function () {
    foreach (['abc', '0', '-1', '5x'] as $bad) {
        assert_eq(2, _cli('todo --project ' . escapeshellarg($bad), _cliHome(), '', 'agent')['code'], $bad);
    }
});

function _setupFixtureRoutes(?string $repoUrl = null): array {
    return [
        'GET /api/v1/projects/900' => [['status' => 200, 'body' => json_encode([
            'id' => 900, 'name' => 'Тілі-Тісто', 'slug' => 'tili-tisto', 'repo_url' => $repoUrl, 'default_branch' => 'main',
            'columns' => [['id' => 2, 'name' => 'To Do'], ['id' => 4, 'name' => 'В работе'], ['id' => 5, 'name' => 'Review']],
        ])]],
    ];
}

/** Manager home with roots {otack: <tmp>/roots/otack}. */
function _setupHome(int $port): array {
    $home = _cliManagerHome($port);
    $root = $home . '/roots/otack';
    mkdir($root, 0755, true);
    $file = $home . '/.claude/otack-agent/projects.json';
    $raw = json_decode(file_get_contents($file), true);
    $raw['roots'] = ['otack' => $root];
    file_put_contents($file, json_encode($raw));
    chmod($file, 0600);
    return [$home, realpath($root)];
}

it('folder-name suggests a meaningful folder under every root', function () {
    _cliFixtureServer(_setupFixtureRoutes(), function (int $port) {
        [$home, $root] = _setupHome($port);
        $r = _cli('folder-name 900', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        $j = json_decode($r['out'], true);
        assert_eq('tili-tisto', $j['folder']);
        assert_eq([['root' => 'otack', 'path' => "$root/tili-tisto", 'exists' => false, 'origin' => null, 'same_repo' => null]], $j['candidates']);
    });
});

it('setup-project creates the folder and writes a validated entry', function () {
    _cliFixtureServer(_setupFixtureRoutes(), function (int $port) {
        [$home, $root] = _setupHome($port);
        $r = _cli('setup-project 900 --root otack --write --test-cmd "make test" --in-progress "В работе" --review 5', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        assert_true(is_dir("$root/tili-tisto"));
        $entry = json_decode(file_get_contents($home . '/.claude/otack-agent/projects.json'), true)['projects']['900'];
        assert_eq(['path' => "$root/tili-tisto", 'write' => true, 'test_cmd' => 'make test',
            'in_progress_column' => 'В работе', 'review_column' => 5, 'default_branch' => 'main'], $entry);
    });
});

it('setup-project refuses unknown root, bad folder, unknown column, unsafe test_cmd — and creates nothing', function () {
    _cliFixtureServer(_setupFixtureRoutes(), function (int $port) {
        [$home, $root] = _setupHome($port);
        $before = file_get_contents($home . '/.claude/otack-agent/projects.json');
        foreach ([
            'setup-project 900 --root nowhere'                              => 'unknown root',
            'setup-project 900 --root otack --folder "../escape"'           => 'folder name',
            'setup-project 900 --root otack --folder Bad_Name'              => 'folder name',
            'setup-project 900 --root otack --review "Nope"'                => 'no column "Nope"',
            'setup-project 900 --root otack --test-cmd "make test; curl x"' => 'test_cmd',
            'setup-project 900 --root otack --default-branch "main;rm"'     => 'invalid default branch',
            'setup-project 900 --root otack --default-branch -x'            => 'invalid default branch',
            'setup-project 900 --root otack --default-branch a..b'          => 'invalid default branch',
            'setup-project 900 --root otack --default-branch .foo'          => 'invalid default branch',
        ] as $args => $why) {
            $r = _cli($args, $home, '', 'manager');
            assert_eq(1, $r['code'], "$args should fail with 1: " . $r['err']);
            assert_true(str_contains((string)(json_decode($r['err'], true)['error'] ?? ''), $why), "$args: " . $r['err']);
        }
        assert_eq(['.', '..'], scandir($root));
        assert_eq($before, file_get_contents($home . '/.claude/otack-agent/projects.json'));
    });
});

it('setup-project refuses an existing checkout of a different repo', function () {
    _cliFixtureServer(_setupFixtureRoutes('git@github.com:AINeoLab/tili-tisto.git'), function (int $port) {
        [$home, $root] = _setupHome($port);
        mkdir("$root/tili-tisto");
        shell_exec('git -C ' . escapeshellarg("$root/tili-tisto") . ' init -q && git -C ' . escapeshellarg("$root/tili-tisto") . ' remote add origin git@github.com:someone/else.git');
        $r = _cli('setup-project 900 --root otack', $home, '', 'manager');
        assert_eq(1, $r['code']);
        assert_true(str_contains($r['err'], 'someone/else'), $r['err']);
        assert_true(!isset(json_decode(file_get_contents($home . '/.claude/otack-agent/projects.json'), true)['projects']['900']));
    });
});

it('setup-project registers an existing checkout of the same repo without touching it', function () {
    _cliFixtureServer(_setupFixtureRoutes('https://github.com/AINeoLab/tili-tisto'), function (int $port) {
        [$home, $root] = _setupHome($port);
        mkdir("$root/tili-tisto");
        shell_exec('git -C ' . escapeshellarg("$root/tili-tisto") . ' init -q && git -C ' . escapeshellarg("$root/tili-tisto") . ' remote add origin git@github.com:AINeoLab/tili-tisto.git');
        $r = _cli('setup-project 900 --root otack', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        assert_eq(false, json_decode($r['out'], true)['created_folder']);
    });
});

it('setup-project refuses to clone into a non-empty non-git folder, and refuses a symlink', function () {
    _cliFixtureServer(_setupFixtureRoutes('/nonexistent/repo'), function (int $port) {
        [$home, $root] = _setupHome($port);
        mkdir("$root/tili-tisto");
        file_put_contents("$root/tili-tisto/notes.txt", 'x');
        $before = file_get_contents($home . '/.claude/otack-agent/projects.json');
        $r = _cli('setup-project 900 --root otack --folder tili-tisto --clone', $home, '', 'manager');
        assert_eq(1, $r['code'], $r['err']);
        assert_true(str_contains($r['err'], 'is not empty and is not a git checkout'), $r['err']);
        assert_eq(['.', '..', 'notes.txt'], scandir("$root/tili-tisto"));
        assert_eq('x', file_get_contents("$root/tili-tisto/notes.txt"));
        symlink('/tmp', "$root/linked");
        $r = _cli('setup-project 900 --root otack --folder linked', $home, '', 'manager');
        assert_eq(1, $r['code'], $r['err']);
        assert_true(str_contains($r['err'], 'symlink'), $r['err']);
        assert_eq('/tmp', readlink("$root/linked"));
        assert_eq($before, file_get_contents($home . '/.claude/otack-agent/projects.json'));
    });
});

it('setup-project will not overwrite an existing entry without --replace', function () {
    _cliFixtureServer(_setupFixtureRoutes(), function (int $port) {
        [$home] = _setupHome($port);
        assert_eq(0, _cli('setup-project 900 --root otack', $home, '', 'manager')['code']);
        $r = _cli('setup-project 900 --root otack --write', $home, '', 'manager');
        assert_eq(1, $r['code']);
        assert_true(str_contains($r['err'], '--replace'), $r['err']);
        assert_eq(0, _cli('setup-project 900 --root otack --write --replace', $home, '', 'manager')['code']);
    });
});

it('folder-name and setup-project are refused in agent scope', function () {
    $home = _cliHome();
    assert_eq(2, _cli('folder-name 900', $home, '', 'agent')['code']);
    assert_eq(2, _cli('setup-project 900 --root otack', $home, '', 'agent')['code']);
});

it('setup-project --clone clones the project repo (local fixture repo, no network)', function () {
    $src = sys_get_temp_dir() . '/otack-cli-src-' . bin2hex(random_bytes(4));
    mkdir($src);
    shell_exec('git -C ' . escapeshellarg($src) . ' init -q -b main && git -C ' . escapeshellarg($src)
        . ' -c user.name=t -c user.email=t@t commit -q --allow-empty -m init');
    _cliFixtureServer(_setupFixtureRoutes($src), function (int $port) use ($src) {
        [$home, $root] = _setupHome($port);
        $r = _cli('setup-project 900 --root otack --clone', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        $j = json_decode($r['out'], true);
        assert_eq(true, $j['cloned']);
        assert_eq(false, $j['created_folder']);
        $folder = basename($src);
        assert_eq("$root/$folder", $j['entry']['path']);
        assert_true(is_dir("$root/$folder/.git"));
    });
    shell_exec('rm -rf ' . escapeshellarg($src));
});

it('folder-name and setup-project never echo credentials embedded in repo URLs', function () {
    _cliFixtureServer(_setupFixtureRoutes('https://bot:otk_secret1@github.com/AINeoLab/tili-tisto.git'), function (int $port) {
        [$home, $root] = _setupHome($port);
        mkdir("$root/tili-tisto");
        shell_exec('git -C ' . escapeshellarg("$root/tili-tisto") . ' init -q && git -C ' . escapeshellarg("$root/tili-tisto")
            . ' remote add origin https://x:otk_secret2@github.com/someone/else.git');
        $r = _cli('folder-name 900', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        $j = json_decode($r['out'], true);
        assert_eq('https://github.com/AINeoLab/tili-tisto.git', $j['project']['repo_url']);
        assert_eq('https://github.com/someone/else.git', $j['candidates'][0]['origin']);
        assert_eq(false, $j['candidates'][0]['same_repo']);
        $r = _cli('setup-project 900 --root otack', $home, '', 'manager');
        assert_eq(1, $r['code']);
        assert_true(str_contains($r['err'], 'someone/else'), $r['err']);
        foreach (['otk_secret1', 'otk_secret2'] as $secret) {
            assert_true(!str_contains($r['err'], $secret), $r['err']);
        }
    });
});

it('setup-project refuses a server default_branch the tick would reject, and --test-cmd "" means none', function () {
    $routes = _setupFixtureRoutes();
    $p = json_decode($routes['GET /api/v1/projects/900'][0]['body'], true);
    $p['default_branch'] = '-x';
    $routes['GET /api/v1/projects/900'][0]['body'] = json_encode($p);
    _cliFixtureServer($routes, function (int $port) {
        [$home, $root] = _setupHome($port);
        $r = _cli('setup-project 900 --root otack', $home, '', 'manager');
        assert_eq(1, $r['code'], $r['err']);
        assert_true(str_contains($r['err'], 'pass --default-branch'), $r['err']);
        assert_eq(['.', '..'], scandir($root));
        $r = _cli('setup-project 900 --root otack --default-branch release/1.2 --test-cmd ""', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        $entry = json_decode(file_get_contents($home . '/.claude/otack-agent/projects.json'), true)['projects']['900'];
        assert_eq('release/1.2', $entry['default_branch']);
        assert_true(!array_key_exists('test_cmd', $entry), json_encode($entry));
    });
});

it('setup-project --clone into a git checkout without origin says so', function () {
    _cliFixtureServer(_setupFixtureRoutes('/nonexistent/repo'), function (int $port) {
        [$home, $root] = _setupHome($port);
        mkdir("$root/tili-tisto");
        shell_exec('git -C ' . escapeshellarg("$root/tili-tisto") . ' init -q');
        $r = _cli('setup-project 900 --root otack --folder tili-tisto --clone', $home, '', 'manager');
        assert_eq(1, $r['code'], $r['err']);
        assert_true(str_contains(json_decode($r['err'], true)['error'], 'git checkout without an "origin" remote'), $r['err']);
        assert_true(is_dir("$root/tili-tisto/.git"));
    });
});

/** A local git repo with one commit, for --clone tests (no network). */
function _setupSrcRepo(): string {
    $src = sys_get_temp_dir() . '/otack-cli-src-' . bin2hex(random_bytes(4));
    mkdir($src);
    shell_exec('git -C ' . escapeshellarg($src) . ' init -q -b main && git -C ' . escapeshellarg($src)
        . ' -c user.name=t -c user.email=t@t commit -q --allow-empty -m init');
    return $src;
}

it('setup-project rolls back only what it made when projects.json cannot be written', function () {
    $src = _setupSrcRepo();
    _cliFixtureServer(_setupFixtureRoutes($src), function (int $port) use ($src) {
        [$home, $root] = _setupHome($port);
        $cfgDir = $home . '/.claude/otack-agent';
        $before = file_get_contents("$cfgDir/projects.json");
        $folder = basename($src);
        mkdir("$root/kept");
        chmod($cfgDir, 0500);   // load() still reads; writeProjectEntry cannot create its temp file
        try {
            // 1. Freshly created folder -> removed.
            $r = _cli('setup-project 900 --root otack', $home, '', 'manager');
            assert_eq(1, $r['code'], $r['err']);
            assert_true(!file_exists("$root/$folder"), 'created folder must be rolled back');
            // 2. Fresh clone -> removed.
            $r = _cli('setup-project 900 --root otack --clone', $home, '', 'manager');
            assert_eq(1, $r['code'], $r['err']);
            assert_true(!file_exists("$root/$folder"), 'fresh clone must be rolled back');
            // 3. Clone into a pre-existing empty folder -> the folder stays, empty.
            $r = _cli('setup-project 900 --root otack --folder kept --clone', $home, '', 'manager');
            assert_eq(1, $r['code'], $r['err']);
            assert_eq(['.', '..'], scandir("$root/kept"));
        } finally {
            chmod($cfgDir, 0700);
        }
        assert_eq(['.', '..', 'kept'], scandir($root));
        assert_eq($before, file_get_contents("$cfgDir/projects.json"));
    });
    shell_exec('rm -rf ' . escapeshellarg($src));
});

it('folder-name redacts a password that itself contains "@"', function () {
    _cliFixtureServer(_setupFixtureRoutes('https://u:p@ss@github.com/AINeoLab/tili-tisto'), function (int $port) {
        [$home] = _setupHome($port);
        $r = _cli('folder-name 900', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        assert_eq('https://github.com/AINeoLab/tili-tisto', json_decode($r['out'], true)['project']['repo_url']);
        assert_true(!str_contains($r['out'], 'ss@'), $r['out']);
    });
});

// ---------------------------------------------------------------------
// otack-manager skills, Task 10: `next` in Telegram selection mode.
// ---------------------------------------------------------------------

/** Agent home in approval.mode=telegram; $selection is written raw to selection.json (null = no file). */
function _cliTgHome(int $port, ?string $selection, bool $gateClosed = false): string {
    $home = _cliHomeForServer($port);
    file_put_contents($home . '/.claude/otack-agent/.env',
        "OTACK_API_URL=http://127.0.0.1:$port/api/v1\nOTACK_API_TOKEN=otk_test\n"
        . "OTACK_TG_BOT_TOKEN=123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ\nOTACK_TG_CHAT_ID=42\n");
    chmod($home . '/.claude/otack-agent/.env', 0600);
    file_put_contents($home . '/.claude/otack-agent/projects.json', json_encode([
        'approval' => ['mode' => 'telegram'],
        'projects' => ['42' => ['path' => $home . '/p42', 'write' => true]],
    ]));
    if ($selection !== null) { file_put_contents($home . '/.claude/otack-agent/selection.json', $selection); }
    if ($gateClosed) { file_put_contents($home . '/.claude/otack-agent/approval-closed', "1\n"); }
    return $home;
}

function _cliTgRoutes(array $items): array {
    $routes = [
        'GET /api/v1/me'          => [['status' => 200, 'body' => json_encode(['id' => 1])]],
        'GET /api/v1/tasks'       => [['status' => 200, 'body' => json_encode(['items' => $items, 'next_cursor' => null])]],
        'GET /api/v1/projects/42' => [['status' => 200, 'body' => json_encode(['columns' => [['id' => 5]]])]],
    ];
    foreach ($items as $t) {
        $routes['GET /api/v1/tasks/' . $t['id']] = [['status' => 200, 'body' => json_encode($t + ['from_form' => false])]];
    }
    return $routes;
}

it('telegram mode: next does not start a new task the owner has not chosen', function () {
    $items = [['id' => 10, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null]];
    _cliFixtureServer(_cliTgRoutes($items), function (int $port, string $log) {
        $home = _cliTgHome($port, json_encode(['offset' => 0, 'digest' => null, 'approved' => []]));
        $r = _cli('next', $home);
        assert_eq(0, $r['code'], $r['err']);
        assert_eq(['task' => null, 'reason' => 'awaiting_owner_selection'], json_decode($r['out'], true));
        assert_true(!in_array('/api/v1/tasks/10', array_column(_requestLog($log), 'path'), true));
    });
});

it('telegram mode: next starts a task the owner approved', function () {
    $items = [['id' => 10, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null],
              ['id' => 11, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null]];
    _cliFixtureServer(_cliTgRoutes($items), function (int $port) {
        $home = _cliTgHome($port, json_encode(['offset' => 0, 'digest' => null, 'approved' => [11]]));
        $out = json_decode(_cli('next', $home)['out'], true);
        assert_eq(11, $out['task']['id']);
        assert_eq('new', $out['reason']);
    });
});

it('telegram mode: with no selection.json yet, nothing new is started', function () {
    $items = [['id' => 10, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null]];
    _cliFixtureServer(_cliTgRoutes($items), function (int $port) {
        $home = _cliTgHome($port, null);
        assert_eq('awaiting_owner_selection', json_decode(_cli('next', $home)['out'], true)['reason']);
    });
});

it('telegram mode: a broken selection.json fails closed for new tasks but still resumes work', function () {
    $items = [['id' => 10, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null]];
    _cliFixtureServer(_cliTgRoutes($items), function (int $port) {
        $home = _cliTgHome($port, '{"approved": [10], broken');
        $r = _cli('next', $home);
        assert_eq(0, $r['code'], $r['err']);
        assert_eq('awaiting_owner_selection', json_decode($r['out'], true)['reason']);
        assert_true(str_contains($r['err'], 'selection.json'), $r['err']);
    });
    $items = [['id' => 10, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null],
              ['id' => 12, 'project_id' => 42, 'column_id' => 5, 'agent_state' => 'implementing']];
    _cliFixtureServer(_cliTgRoutes($items), function (int $port) {
        $home = _cliTgHome($port, 'not json');
        $out = json_decode(_cli('next', $home)['out'], true);
        assert_eq(12, $out['task']['id']);
        assert_eq('resume', $out['reason']);
    });
});

it('telegram mode: after a failed approval pass (approval-closed marker) nothing new starts, even if approved', function () {
    $items = [['id' => 10, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null]];
    _cliFixtureServer(_cliTgRoutes($items), function (int $port) {
        $home = _cliTgHome($port, json_encode(['offset' => 0, 'digest' => null, 'approved' => [10]]), true);
        $r = _cli('next', $home);
        assert_eq('awaiting_owner_selection', json_decode($r['out'], true)['reason']);
        assert_true(str_contains($r['err'], 'approval'), $r['err']);
    });
});

it('mode none: next ignores selection.json and the marker entirely', function () {
    $items = [['id' => 10, 'project_id' => 42, 'column_id' => 5, 'agent_state' => null]];
    _cliFixtureServer(_cliTgRoutes($items), function (int $port) {
        $home = _cliHomeForServer($port);
        file_put_contents($home . '/.claude/otack-agent/selection.json', 'not json');
        file_put_contents($home . '/.claude/otack-agent/approval-closed', "1\n");
        $out = json_decode(_cli('next', $home)['out'], true);
        assert_eq(10, $out['task']['id']);
    });
});

// ---------------------------------------------------------------------
// Final fix round.
// ---------------------------------------------------------------------

it('manager scope survives a broken projects.json entry (local.error) and --replace repairs it; agent scope fails closed', function () {
    $routes = _setupFixtureRoutes();
    $routes['GET /api/v1/projects'] = [['status' => 200, 'body' => json_encode(['items' => [['id' => 900, 'name' => 'Тілі-Тісто']], 'next_cursor' => null])]];
    _cliFixtureServer($routes, function (int $port, string $log) {
        [$home, $root] = _setupHome($port);
        $file = $home . '/.claude/otack-agent/projects.json';
        $raw = json_decode(file_get_contents($file), true);
        $raw['projects']['900'] = ['path' => $home . '/gone', 'write' => true];
        file_put_contents($file, json_encode($raw));
        $r = _cli('projects', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        $local = json_decode($r['out'], true)['items'][0]['local'];
        assert_true(str_contains($local['error'] ?? '', 'does not exist'), $r['out']);
        $r = _cli('project 900', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        assert_true(isset(json_decode($r['out'], true)['local']['error']), $r['out']);
        $r = _cli('setup-project 900 --root otack', $home, '', 'manager');
        assert_eq(1, $r['code']);
        assert_true(str_contains($r['err'], '--replace'), $r['err']);
        $r = _cli('setup-project 900 --root otack --replace', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        assert_eq("$root/tili-tisto", json_decode(file_get_contents($file), true)['projects']['900']['path']);

        $raw = json_decode(file_get_contents($file), true);
        $raw['projects']['900']['path'] = $home . '/gone';
        file_put_contents($file, json_encode($raw));
        $before = count(_requestLog($log));
        $r = _cli('projects', $home, '', 'agent');
        assert_eq(1, $r['code']);
        assert_true(str_contains($r['err'], 'does not exist'), $r['err']);
        assert_eq($before, count(_requestLog($log)), 'agent scope makes no request');
    });
});

it('comment refuses a body carrying a token or an .env line (exit 2, no request, body not echoed) in both scopes', function () {
    _cliFixtureServer([
        'GET /api/v1/tasks/30'  => [['status' => 200, 'body' => json_encode(['id' => 30, 'project_id' => 42])]],
        'POST /api/v1/comments' => [['status' => 201, 'body' => json_encode(['id' => 1])]],
    ], function (int $port, string $log) {
        $home = _cliManagerHome($port);
        $tg = '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ';   // short enough to dodge the pattern: caught as a configured secret
        file_put_contents($home . '/.claude/otack-agent/.env',
            "OTACK_API_URL=http://127.0.0.1:$port/api/v1\nOTACK_API_TOKEN=botPlainSecret77\nOTACK_TG_BOT_TOKEN=$tg\n");
        file_put_contents($home . '/.claude/otack-manager/.env',
            "OTACK_API_URL=http://127.0.0.1:$port/api/v1\nOTACK_API_TOKEN=ownerPlainSecret88\n");
        $bodies = [
            'otk pattern'      => "see otk_ABCDEFGH12 above\n",
            'telegram pattern' => "bot 123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw1 ok\n",
            'env token line'   => "x\n  OTACK_API_TOKEN = anything\n",
            'env tg line'      => "OTACK_TG_CHAT_ID=42\n",
            'own token'        => "token is ownerPlainSecret88\n",
            'bot token'        => "botPlainSecret77\n",
            'configured tg'    => "use $tg\n",
            'manager .env'     => file_get_contents($home . '/.claude/otack-manager/.env'),
            'agent .env'       => file_get_contents($home . '/.claude/otack-agent/.env'),
        ];
        foreach (['manager', 'agent'] as $scope) {
            foreach ($bodies as $name => $body) {
                $r = _cli('comment 30', $home, $body, $scope);
                assert_eq(2, $r['code'], "$scope/$name: " . $r['err']);
                assert_true(str_contains($r['err'], 'refusing to send it'), "$scope/$name: " . $r['err']);
                foreach (['otk_ABCDEFGH12', 'AAHdqTcv', 'anything', 'ownerPlainSecret88', 'botPlainSecret77', $tg] as $leak) {
                    assert_true(!str_contains($r['err'] . $r['out'], $leak), "$scope/$name echoed the body");
                }
            }
        }
        assert_eq([], _requestLog($log), 'no request for a refused body');
        $ok = "Готово — перевірила, працює. Сделано 3 из 5 пунктов, созвон в 12:30.\n";
        foreach (['manager', 'agent'] as $scope) {
            $r = _cli('comment 30', $home, $ok, $scope);
            assert_eq(0, $r['code'], "$scope: " . $r['err']);
        }
        $posts = array_values(array_filter(_requestLog($log), fn($e) => $e['method'] === 'POST'));
        assert_eq(2, count($posts));
        assert_eq($ok, $posts[0]['body']['body']);
    });
});

it('projects, project and task never echo credentials embedded in URL fields', function () {
    $secretUrl = 'https://bot:otk_secretXYZ1@github.com/o/r.git';
    $project = ['id' => 42, 'name' => 'P', 'repo_url' => $secretUrl, 'columns' => [],
        'links' => [['site_url' => 'https://u:pw123@example.com/x']]];
    _cliFixtureServer([
        'GET /api/v1/projects'          => [['status' => 200, 'body' => json_encode(['items' => [$project], 'next_cursor' => null])]],
        'GET /api/v1/projects/42'       => [['status' => 200, 'body' => json_encode($project)]],
        'GET /api/v1/tasks/7'           => [['status' => 200, 'body' => json_encode(['id' => 7, 'project_id' => 42, 'preview_url' => 'https://a:b@h.example/'])]],
        'GET /api/v1/tasks/7/comments'  => [['status' => 200, 'body' => json_encode(['items' => [], 'next_cursor' => null])]],
        'GET /api/v1/me'                => [['status' => 200, 'body' => json_encode(['id' => 3])]],
    ], function (int $port) {
        $home = _cliManagerHome($port);
        foreach (['projects', 'project 42', 'task 7'] as $cmd) {
            foreach (['manager', 'agent'] as $scope) {
                $r = _cli($cmd, $home, '', $scope);
                assert_eq(0, $r['code'], "$scope $cmd: " . $r['err']);
                foreach (['otk_secretXYZ1', 'pw123', 'a:b@'] as $leak) {
                    assert_true(!str_contains($r['out'], $leak), "$scope $cmd leaked $leak: " . $r['out']);
                }
                assert_true(str_contains($r['out'], 'https://github.com/o/r.git'), "$scope $cmd: " . $r['out']);
            }
        }
    });
});

/** _setupFixtureRoutes() with default_branch null on the server. */
function _setupRoutesNoBranch(?string $repoUrl): array {
    $routes = _setupFixtureRoutes($repoUrl);
    $p = json_decode($routes['GET /api/v1/projects/900'][0]['body'], true);
    $p['default_branch'] = null;
    $routes['GET /api/v1/projects/900'][0]['body'] = json_encode($p);
    return $routes;
}

it('setup-project --write with no default branch: origin/HEAD of the checkout, else a refusal asking for --default-branch', function () {
    $src = _setupSrcRepo();
    $folder = basename($src);
    _cliFixtureServer(_setupRoutesNoBranch($src), function (int $port) use ($src, $folder) {
        [$home, $root] = _setupHome($port);
        $file = $home . '/.claude/otack-agent/projects.json';
        $entry = fn() => json_decode(file_get_contents($file), true)['projects']['900'] ?? null;

        // Not a checkout and nothing to clone -> refuse, create nothing.
        $r = _cli('setup-project 900 --root otack --write', $home, '', 'manager');
        assert_eq(1, $r['code'], $r['err']);
        assert_true(str_contains($r['err'], 'pass --default-branch'), $r['err']);
        assert_eq(['.', '..'], scandir($root));

        // Read-only entry needs no branch: unchanged behaviour.
        $r = _cli('setup-project 900 --root otack --folder ro', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        assert_true(!array_key_exists('default_branch', $entry()));

        // Fresh clone -> origin/HEAD.
        $r = _cli('setup-project 900 --root otack --write --clone --replace', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        assert_eq('main', $entry()['default_branch']);
        assert_eq("$root/$folder", $entry()['path']);

        // Existing checkout of the same repo -> origin/HEAD, folder untouched.
        $r = _cli("setup-project 900 --root otack --folder $folder --write --replace", $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        assert_eq('main', $entry()['default_branch']);

        // A checkout without origin/HEAD -> refusal naming set-head; --default-branch wins.
        mkdir("$root/bare");
        shell_exec('git -C ' . escapeshellarg("$root/bare") . ' init -q && git -C ' . escapeshellarg("$root/bare")
            . ' remote add origin ' . escapeshellarg($src));
        $r = _cli('setup-project 900 --root otack --folder bare --write --replace', $home, '', 'manager');
        assert_eq(1, $r['code'], $r['err']);
        assert_true(str_contains($r['err'], 'set-head') && str_contains($r['err'], '--default-branch'), $r['err']);
        assert_true(is_dir("$root/bare/.git"), 'an existing folder is never removed');
        $r = _cli('setup-project 900 --root otack --folder bare --write --replace --default-branch develop', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        assert_eq('develop', $entry()['default_branch']);
    });
    shell_exec('rm -rf ' . escapeshellarg($src));
});

it('setup-project --write --clone rolls the clone back when origin/HEAD names a branch the tick would reject', function () {
    $src = sys_get_temp_dir() . '/otack-cli-src-' . bin2hex(random_bytes(4));
    mkdir($src);
    shell_exec('git -C ' . escapeshellarg($src) . ' init -q -b rel+1 && git -C ' . escapeshellarg($src)
        . ' -c user.name=t -c user.email=t@t commit -q --allow-empty -m init');
    _cliFixtureServer(_setupRoutesNoBranch($src), function (int $port) use ($src) {
        [$home, $root] = _setupHome($port);
        $before = file_get_contents($home . '/.claude/otack-agent/projects.json');
        $r = _cli('setup-project 900 --root otack --write --clone', $home, '', 'manager');
        assert_eq(1, $r['code'], $r['err']);
        assert_true(str_contains($r['err'], 'rel+1') && str_contains($r['err'], '--default-branch'), $r['err']);
        assert_eq(['.', '..'], scandir($root), 'the fresh clone is rolled back');
        assert_eq($before, file_get_contents($home . '/.claude/otack-agent/projects.json'));
    });
    shell_exec('rm -rf ' . escapeshellarg($src));
});

// --- attachments / download ------------------------------------------------

/** Fixture routes for task 77 in project $pid with the given attachment rows and /uploads bodies. */
function _attRoutes(int $pid, array $items, array $uploads = []): array {
    $routes = [
        'GET /api/v1/tasks/77'             => [['status' => 200, 'body' => json_encode(['id' => 77, 'project_id' => $pid])]],
        'GET /api/v1/tasks/77/attachments' => [['status' => 200, 'body' => json_encode(['items' => $items])]],
    ];
    foreach ($uploads as $path => $resp) { $routes["GET $path"] = [$resp]; }
    return $routes;
}

function _attRow(int $id, string $name, string $path, int $size, string $mime = 'application/pdf'): array {
    return ['id' => $id, 'entity_type' => 'task', 'entity_id' => 77, 'filename' => ltrim($path, '/'), 'path' => $path,
        'original_name' => $name, 'mime' => $mime, 'size' => $size, 'is_image' => false, 'uploaded_by' => 1, 'created_at' => null];
}

it('attachments lists a task\'s files in both scopes, and --project lists a project\'s', function () {
    $row = _attRow(5, 'spec.pdf', '/uploads/2026/10/a.pdf', 3);
    $routes = _attRoutes(42, [$row]) + [
        'GET /api/v1/projects/42/attachments' => [['status' => 200, 'body' => json_encode(['items' => [$row]])]],
    ];
    _cliFixtureServer($routes, function (int $port) {
        $home = _cliManagerHome($port);
        foreach (['agent', 'manager'] as $scope) {
            $r = _cli('attachments 77', $home, '', $scope);
            assert_eq(0, $r['code'], $r['err']);
            assert_eq('spec.pdf', json_decode($r['out'], true)['items'][0]['original_name']);
            $r = _cli('attachments --project 42', $home, '', $scope);
            assert_eq(0, $r['code'], $r['err']);
            assert_eq(5, json_decode($r['out'], true)['items'][0]['id']);
        }
    });
});

it('agent scope refuses attachments/download of an unlisted project\'s task without fetching the list', function () {
    _cliFixtureServer(_attRoutes(900, [_attRow(5, 'a.pdf', '/uploads/2026/10/a.pdf', 3)]), function (int $port, string $log) {
        $home = _cliManagerHome($port);
        foreach (['attachments 77', 'download 77'] as $cmd) {
            $r = _cli($cmd, $home, '', 'agent');
            assert_eq(1, $r['code'], $r['err']);
            assert_true(str_contains($r['err'], 'is not listed'), $r['err']);
        }
        $r = _cli('attachments --project 900', $home, '', 'agent');
        assert_eq(1, $r['code'], $r['err']);
        $paths = array_map(fn($e) => $e['path'], _requestLog($log));
        assert_eq(['/api/v1/tasks/77', '/api/v1/tasks/77'], $paths, 'only the task itself was read');
        assert_true(!is_dir($home . '/.cache'), 'no download directory was created');
    });
});

it('download writes safe 0600 names into the per-scope 0700 directory, without the token on /uploads', function () {
    $items = [
        _attRow(5, 'ТЗ проекта.pdf', '/uploads/2026/10/a.pdf', 3),
        _attRow(6, '../../.bashrc', '/uploads/2026/10/b.txt', 4, 'text/plain'),
        _attRow(7, '..\\..\\evil/../.ssh/id', '/uploads/2026/10/c.bin', 2, 'application/octet-stream'),
    ];
    $uploads = [
        '/uploads/2026/10/a.pdf' => ['status' => 200, 'body' => 'PDF'],
        '/uploads/2026/10/b.txt' => ['status' => 200, 'body' => 'evil'],
        '/uploads/2026/10/c.bin' => ['status' => 200, 'body' => 'xx'],
    ];
    _cliFixtureServer(_attRoutes(42, $items, $uploads), function (int $port, string $log) {
        $home = _cliManagerHome($port);
        $dirs = [
            'agent'   => $home . '/.cache/otack-agent/session-cwd/attachments/task-77',
            'manager' => $home . '/.cache/otack-manager/attachments/task-77',
        ];
        foreach ($dirs as $scope => $dir) {
            $r = _cli('download 77', $home, '', $scope);
            assert_eq(0, $r['code'], $r['err']);
            $j = json_decode($r['out'], true);
            assert_eq($dir, $j['dir']);
            assert_eq(0700, fileperms($dir) & 0777, "$scope dir mode");
            assert_eq([], $j['skipped']);
            $names = array_map(fn($f) => basename($f['path']), $j['files']);
            assert_eq(['5-ТЗ_проекта.pdf', '6-bashrc', '7-id'], $names);
            foreach ($j['files'] as $f) {
                assert_eq($dir, dirname($f['path']), 'every file stays inside the task directory');
                assert_eq(0600, fileperms($f['path']) & 0777, 'file mode');
            }
            assert_eq('PDF', file_get_contents($dir . '/5-ТЗ_проекта.pdf'));
            assert_eq(['.', '..', '5-ТЗ_проекта.pdf', '6-bashrc', '7-id'], scandir($dir), 'no temp files left behind');
        }
        assert_true(!file_exists($home . '/.bashrc') && !is_dir($home . '/.ssh'), 'nothing escaped the directory');
        $uploadHits = 0;
        foreach (_requestLog($log) as $e) {
            if (str_starts_with($e['path'], '/uploads/')) {
                $uploadHits++;
                assert_eq(null, $e['auth'], 'no token on ' . $e['path']);
            } else {
                assert_true(in_array($e['auth'], ['Bearer otk_test', 'Bearer otk_owner'], true), 'token on ' . $e['path']);
            }
        }
        assert_eq(6, $uploadHits, 'three files per scope were fetched from the instance');

        // Re-download of one id replaces the file in place.
        file_put_contents($dirs['manager'] . '/5-ТЗ_проекта.pdf', 'stale');
        $r = _cli('download 77 5 99', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        $j = json_decode($r['out'], true);
        assert_eq([5], array_map(fn($f) => $f['id'], $j['files']));
        assert_eq([['id' => 99, 'reason' => 'not attached to this task']], $j['skipped']);
        assert_eq('PDF', file_get_contents($dirs['manager'] . '/5-ТЗ_проекта.pdf'));
    });
});

it('download skips oversize files, foreign paths and redirects without leaving the origin', function () {
    $items = [
        _attRow(1, 'big-listed.pdf', '/uploads/2026/10/big1.pdf', 50),
        _attRow(2, 'big-served.pdf', '/uploads/2026/10/big2.pdf', 3),
        _attRow(3, 'foreign.pdf', 'https://evil.example/uploads/x.pdf', 3),
        _attRow(4, 'scheme-relative.pdf', '//evil.example/uploads/x.pdf', 3),
        _attRow(5, 'traversal.pdf', '/uploads/../.env', 3),
        _attRow(6, 'moved.pdf', '/uploads/2026/10/moved.pdf', 3),
        _attRow(7, 'ok.pdf', '/uploads/2026/10/ok.pdf', 3),
    ];
    $uploads = [
        '/uploads/2026/10/big1.pdf'  => ['status' => 200, 'body' => str_repeat('a', 50)],
        '/uploads/2026/10/big2.pdf'  => ['status' => 200, 'body' => str_repeat('b', 40)],
        '/uploads/2026/10/moved.pdf' => ['status' => 302, 'body' => '', 'headers' => ['Location: https://evil.example/x.pdf']],
        '/uploads/2026/10/ok.pdf'    => ['status' => 200, 'body' => 'yes'],
    ];
    _cliFixtureServer(_attRoutes(42, $items, $uploads), function (int $port, string $log) {
        $home = _cliManagerHome($port);
        putenv('OTACK_ATTACHMENT_MAX_BYTES=20');
        try {
            $r = _cli('download 77', $home, '', 'agent');
        } finally {
            putenv('OTACK_ATTACHMENT_MAX_BYTES');
        }
        assert_eq(0, $r['code'], $r['err']);
        $j = json_decode($r['out'], true);
        assert_eq([7], array_map(fn($f) => $f['id'], $j['files']));
        $reasons = array_column($j['skipped'], 'reason', 'id');
        assert_eq([1, 2, 3, 4, 5, 6], array_keys($reasons));
        assert_true(str_starts_with($reasons[1], 'oversize'), $reasons[1]);
        assert_true(str_starts_with($reasons[2], 'oversize'), $reasons[2]);
        assert_true(str_contains($reasons[6], 'redirect not followed'), $reasons[6]);
        $paths = array_map(fn($e) => $e['path'], _requestLog($log));
        assert_true(!in_array('/uploads/2026/10/big1.pdf', $paths, true), 'listed oversize is skipped without a request');
        assert_true(!array_filter($paths, fn($p) => str_contains($p, 'evil') || str_contains($p, '.env')), implode(',', $paths));
        assert_eq(['.', '..', '7-ok.pdf'], scandir(json_decode($r['out'], true)['dir']));
    });
});

it('download converts an .rtf to a .txt next to it on macOS', function () {
    if (PHP_OS_FAMILY !== 'Darwin' || !is_executable('/usr/bin/textutil')) { return; }
    $rtf = "{\\rtf1\\ansi Hello spec\\par}";
    _cliFixtureServer(_attRoutes(42, [_attRow(8, 'spec.rtf', '/uploads/2026/10/s.rtf', strlen($rtf), 'application/rtf')],
        ['/uploads/2026/10/s.rtf' => ['status' => 200, 'body' => $rtf]]), function (int $port) {
        $home = _cliManagerHome($port);
        $r = _cli('download 77', $home, '', 'manager');
        assert_eq(0, $r['code'], $r['err']);
        $f = json_decode($r['out'], true)['files'][0];
        assert_eq($f['path'] . '.txt', $f['text_path']);
        assert_true(str_contains((string)file_get_contents($f['text_path']), 'Hello spec'));
        assert_eq(0600, fileperms($f['text_path']) & 0777);
    });
});
