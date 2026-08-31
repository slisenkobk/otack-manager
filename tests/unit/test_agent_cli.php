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

it('otack tick-note rewrites the lock file with task_id and phase, keeping the other keys', function () {
    $home = _cliHome();
    $lockPath = $home . '/.claude/otack-agent/agent.lock';
    file_put_contents($lockPath, json_encode([
        'pid' => 4242, 'host' => 'test-host', 'started_at' => 1700000000, 'started_iso' => '2023-11-14T22:13:20Z',
    ]));
    $r = _cli('tick-note 7 implementing', $home);
    assert_eq(0, $r['code']);
    $info = json_decode((string)file_get_contents($lockPath), true);
    assert_eq(7, $info['task_id']);
    assert_eq('implementing', $info['phase']);
    assert_eq(4242, $info['pid'], 'tick-note must preserve the keys otack-tick already wrote, not replace the file');
});

it('otack tick-note rejects a phase outside the closed set', function () {
    $home = _cliHome();
    file_put_contents($home . '/.claude/otack-agent/agent.lock', json_encode(['pid' => 1]));
    $r = _cli('tick-note 7 pondering', $home);
    assert_true($r['code'] !== 0, 'expected a non-zero exit');
    assert_true(str_contains($r['err'], 'researching'), 'error should list the valid phases');
});

it('otack tick-note fails clearly when there is no lock file to write into', function () {
    $r = _cli('tick-note 7 implementing', _cliHome());
    assert_true($r['code'] !== 0, 'expected a non-zero exit');
    assert_true(str_contains($r['err'], 'agent.lock'), 'error should name the lock file it expected to update');
});
