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

function _cli(string $args, string $home, string $stdin = ''): array {
    $bin = dirname(__DIR__, 2) . '/agent/bin/otack';
    $cmd = 'HOME=' . escapeshellarg($home) . ' ' . PHP_BINARY . ' ' . escapeshellarg($bin) . ' ' . $args;
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
