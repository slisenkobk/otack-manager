<?php
// The tick wrapper's contract is about process lifetime, so these tests
// drive real subprocesses. `--selftest=hold:<n>` runs the lock logic and
// then sleeps instead of launching a session, so the test can observe a
// held lock. `--selftest-task-id=<id>` and `--selftest-started-at=<epoch>`
// (test-only, undocumented outside this file) let a test fabricate "a tick
// that has been stuck on task <id> since <epoch>" without literally waiting
// three hours for STALE_AFTER_SECONDS to elapse. `--selftest=print-cmd`
// (M6) runs every real step — lock, config, allow/disallow-list
// construction, chdir, CLAUDE_CONFIG_DIR setup — and prints the result as
// JSON instead of calling passthru(), so tests can assert on the
// constructed command instead of trusting the source.
//
// All --selftest=* flags require OTACK_TICK_ALLOW_SELFTEST=1 in the
// environment (MINOR) — every helper below that builds a command sets it.
//
// See test_agent_config.php for why the file starts with an existence
// guard: tests/run.php's bare top-level `require` turns a missing-file
// require_once into a fatal that aborts the whole suite. This file has no
// require_once of a library, but agent/bin/otack-tick is exactly as absent
// on the red step, so the guard checks for the script itself.
$__bin = dirname(__DIR__, 2) . '/agent/bin/otack-tick';
if (!is_file($__bin)) {
    it('agent/bin/otack-tick exists', fn() => assert_true(false, 'not implemented yet'));
    return;
}

// otack-tick's own exit-code constants, duplicated here so the tests read
// as assertions against a documented contract rather than magic numbers.
// Kept in sync with the const declarations in agent/bin/otack-tick.
const TICK_EXIT_OK = 0;
const TICK_EXIT_CONFIG_ERROR = 1;
const TICK_EXIT_USAGE_ERROR = 2;
const TICK_EXIT_BLOCKED = 75;

function _tickHome(): string {
    $home = sys_get_temp_dir() . '/otack-tick-test-' . bin2hex(random_bytes(4));
    mkdir($home . '/.claude/otack-agent', 0700, true);
    file_put_contents($home . '/.claude/otack-agent/.env',
        "OTACK_API_URL=http://localhost:8771/api/v1\nOTACK_API_TOKEN=otk_test\n");
    chmod($home . '/.claude/otack-agent/.env', 0600);
    return $home;
}

/** Recursively removes a temp directory tree (repo convention, see test_agent_config.php). */
function _tickRrmdir(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        _tickRrmdir($path . '/' . $item);
    }
    @rmdir($path);
}

function _tickTempProjectDir(): string {
    $p = sys_get_temp_dir() . '/otack-tick-proj-' . bin2hex(random_bytes(4));
    mkdir($p, 0700, true);
    register_shutdown_function(fn() => _tickRrmdir($p));
    return $p;
}

/** Writes projects.json into an existing tick-home directory. */
function _tickWriteProjects(string $home, array $projects): void {
    file_put_contents(
        $home . '/.claude/otack-agent/projects.json',
        json_encode(['projects' => $projects], JSON_PRETTY_PRINT)
    );
}

function _tick(string $home, string $args, array $extraEnv = []): array {
    $bin = dirname(__DIR__, 2) . '/agent/bin/otack-tick';
    $env = array_merge(['HOME' => $home, 'OTACK_TICK_ALLOW_SELFTEST' => '1'], $extraEnv);
    $envStr = '';
    foreach ($env as $k => $v) { $envStr .= $k . '=' . escapeshellarg((string)$v) . ' '; }
    $cmd = $envStr . PHP_BINARY . ' ' . escapeshellarg($bin) . ' ' . $args;
    return [$cmd];
}

/** A fresh, independent flock() attempt on $path — proves kernel-level exclusion, not otack-tick's own self-reporting. */
function _tickFlockIsFree(string $path): bool {
    $fh = @fopen($path, 'c');
    if ($fh === false) { return true; } // nothing to lock against yet
    $got = flock($fh, LOCK_EX | LOCK_NB);
    if ($got) { flock($fh, LOCK_UN); }
    fclose($fh);
    return $got;
}

/** Decodes print-cmd's JSON stdout, failing the test with the raw output on parse failure. */
function _tickDecodePrintCmd(string $raw): array {
    $decoded = json_decode(trim($raw), true);
    assert_true(is_array($decoded), 'print-cmd output was not valid JSON: ' . $raw);
    return $decoded;
}

it('otack-tick acquires the lock and reports it holds it', function () {
    $home = _tickHome();
    [$cmd] = _tick($home, '--selftest=hold:2');
    $p = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    usleep(400_000); // let it take the lock

    $lockPath = $home . '/.claude/otack-agent/agent.lock';
    assert_true(is_file($lockPath), 'lock file should exist while held');
    $info = json_decode((string)file_get_contents($lockPath), true);
    assert_true(isset($info['pid']), 'lock file should record the holder pid');
    assert_true(isset($info['started_at']), 'lock file should record the start time');
    // task_id/phase are present (as null) from the first write, not added
    // later — spec §5.1 lists them alongside pid/host/started_at as what
    // the lock records; `otack tick-note` fills them in once a task is
    // picked (Task 4), but the keys exist before that so a tick that dies
    // between "took the lock" and "picked a task" still has a well-formed
    // lock file for the next tick to read.
    assert_true(array_key_exists('task_id', $info), 'lock file should carry a task_id key, even if null');
    assert_true(array_key_exists('phase', $info), 'lock file should carry a phase key, even if null');

    // A second tick must refuse immediately rather than queue, and must be
    // told apart from a real success/failure via a distinct exit code
    // (MINOR) — "blocked" and "exit 0" used to be the same thing from
    // cron's point of view.
    [$cmd2] = _tick($home, '--selftest=hold:1');
    $t0 = microtime(true);
    exec($cmd2 . ' 2>&1', $out2, $code2);
    $elapsed = microtime(true) - $t0;
    assert_eq(TICK_EXIT_BLOCKED, $code2, 'a blocked tick must exit with a distinct, non-zero, non-error code');
    assert_true($elapsed < 1.0, 'a blocked tick must return immediately, took ' . round($elapsed, 2) . 's');
    assert_true(str_contains(implode("\n", $out2), 'already running'), 'should say why it did nothing');
    assert_true(str_contains(implode("\n", $out2), 'Do not delete agent.lock'), 'the message must actively warn against deleting the lock (M1)');

    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
});

it('the lock is held by the kernel for exactly the holder\'s process lifetime, not otack-tick\'s own bookkeeping', function () {
    // Rewrite of the old "released when the holding process dies" test,
    // which exec()'d the holder to completion *before* ever starting the
    // second run — two back-to-back synchronous calls never overlap, so a
    // no-op flock() (or no locking at all) would have passed it just as
    // well. This version proves the two real properties spec §5.1 depends
    // on, each with a *fresh, independent* flock() attempt rather than
    // trusting otack-tick's own "already running" message: (1) the lock is
    // genuinely held — a fresh flock() attempt fails — while the holder
    // process is alive, and (2) it is genuinely free — a fresh flock()
    // attempt succeeds — the moment that process exits, with no separate
    // release step.
    $home = _tickHome();
    $lockPath = $home . '/.claude/otack-agent/agent.lock';
    [$cmd] = _tick($home, '--selftest=hold:1');
    $p = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);

    usleep(300_000); // let it take the lock
    assert_true(!_tickFlockIsFree($lockPath), 'a fresh flock() attempt must fail while the holder is alive');

    fclose($pipes[1]); fclose($pipes[2]);
    $exitCode = proc_close($p); // blocks until the holder process actually exits
    assert_eq(0, $exitCode, 'the holder itself should exit cleanly');
    assert_true(_tickFlockIsFree($lockPath), 'a fresh flock() attempt must succeed the instant the holder exits — the kernel releases it, not application code');
});

it('a stale tick gets exactly one warning attempt per lock generation, not one every tick', function () {
    $home = _tickHome();
    $staleStart = time() - (3 * 3600 + 600); // just over STALE_AFTER_SECONDS
    [$cmdHold] = _tick(
        $home,
        '--selftest=hold:2 --selftest-task-id=55 --selftest-started-at=' . $staleStart
    );
    $p = proc_open($cmdHold, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    usleep(400_000); // let the holder take the lock and write the fabricated started_at/task_id

    [$cmdBlocked] = _tick($home, '');

    // First blocked tick: sees the stale lock, tries to post the warning
    // comment. There is no real server behind OTACK_API_URL in this test,
    // so the attempt fails — that failure message is exactly the signal
    // that an attempt was made at all.
    exec($cmdBlocked . ' 2>&1', $out1, $code1);
    assert_eq(TICK_EXIT_BLOCKED, $code1);
    assert_true(str_contains(implode("\n", $out1), 'WARNING'), 'should warn about the stale run');
    assert_true(
        str_contains(implode("\n", $out1), 'could not post the stale-tick warning comment'),
        'first sighting of this stale generation should attempt to post'
    );

    $warnedPath = $home . '/.claude/otack-agent/stale-warned';
    assert_true(is_file($warnedPath), 'stale-warned marker should exist after the first warning');
    assert_eq((string)$staleStart, trim((string)file_get_contents($warnedPath)));

    // Second blocked tick against the SAME stale lock (same started_at):
    // still warns a human on stderr, but must not attempt the post again.
    exec($cmdBlocked . ' 2>&1', $out2, $code2);
    assert_eq(TICK_EXIT_BLOCKED, $code2);
    assert_true(str_contains(implode("\n", $out2), 'WARNING'), 'should still warn a human every tick');
    assert_true(
        !str_contains(implode("\n", $out2), 'could not post the stale-tick warning comment'),
        'must not re-attempt the post for a lock generation already marked warned'
    );

    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
});

it('M2: a tick that wedges before a task is picked (task_id still null) does not burn the warned marker', function () {
    // Reproduces the exact M2 scenario: the lock starts with task_id: null
    // (before `otack tick-note` ever runs), so a stale generation with no
    // task picked yet must warn a human every tick but never write
    // stale-warned — otherwise, the moment a later tick fills task_id in,
    // every generation after it would find the marker already "warned" and
    // stay silent forever.
    $home = _tickHome();
    $staleStart = time() - (3 * 3600 + 600);
    [$cmdHold] = _tick($home, '--selftest=hold:2 --selftest-started-at=' . $staleStart); // no --selftest-task-id
    $p = proc_open($cmdHold, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    usleep(400_000);

    [$cmdBlocked] = _tick($home, '');
    exec($cmdBlocked . ' 2>&1', $out, $code);
    assert_eq(TICK_EXIT_BLOCKED, $code);
    assert_true(str_contains(implode("\n", $out), 'WARNING'), 'should still warn a human on stderr');
    assert_true(
        !str_contains(implode("\n", $out), 'could not post the stale-tick warning comment'),
        'must not attempt a post at all while task_id is null'
    );
    $warnedPath = $home . '/.claude/otack-agent/stale-warned';
    assert_true(!is_file($warnedPath), 'stale-warned must NOT be written when no attempt was made (task_id null)');

    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
});

it('M1: deleting agent.lock while it is held does not let a second tick start a concurrent session', function () {
    $home = _tickHome();
    $lockPath = $home . '/.claude/otack-agent/agent.lock';

    // Bootstrap: a first, uncontended tick lays the "this machine has run
    // before" marker the fix depends on.
    [$cmdBootstrap] = _tick($home, '--selftest=hold:0');
    exec($cmdBootstrap . ' 2>&1', $outBoot, $codeBoot);
    assert_eq(TICK_EXIT_OK, $codeBoot);
    assert_true(is_file($home . '/.claude/otack-agent/.agent-lock-initialized'), 'first-ever tick must lay the bootstrap marker');

    // Holder takes the lock for real and keeps it while we delete the file
    // out from under it — the literal repro from the review.
    [$cmdHold] = _tick($home, '--selftest=hold:2');
    $p = proc_open($cmdHold, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    usleep(400_000);
    assert_true(is_file($lockPath), 'holder should have (re)created the lock file');
    unlink($lockPath);
    assert_true(!file_exists($lockPath), 'lock file should now be gone from the path (holder keeps its fd regardless)');

    // A second tick, launched *while the first is still alive*, must
    // refuse outright instead of creating a fresh inode and launching.
    [$cmdSecond] = _tick($home, '--selftest=hold:0');
    exec($cmdSecond . ' 2>&1', $outSecond, $codeSecond);
    assert_eq(TICK_EXIT_CONFIG_ERROR, $codeSecond, 'a second tick must refuse (not silently recreate the lock and launch)');
    assert_true(
        str_contains(implode("\n", $outSecond), 'Refusing to create a replacement'),
        'should explain why it refused: ' . implode("\n", $outSecond)
    );
    assert_true(
        !str_contains(implode("\n", $outSecond), 'already running'),
        'this is NOT the ordinary "someone else is mid-tick" path — the lock file itself is gone'
    );

    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
});

it('an unrecognised argument exits non-zero instead of being silently ignored', function () {
    $home = _tickHome();
    [$cmd] = _tick($home, '--this-is-not-a-real-flag');
    exec($cmd . ' 2>&1', $out, $code);
    assert_eq(TICK_EXIT_USAGE_ERROR, $code);
    assert_true(str_contains(implode("\n", $out), 'unrecognised argument'), implode("\n", $out));
});

it('--selftest=* is refused without OTACK_TICK_ALLOW_SELFTEST=1, even with a well-formed value', function () {
    $home = _tickHome();
    $bin = dirname(__DIR__, 2) . '/agent/bin/otack-tick';
    // Deliberately not using _tick()'s helper, which always sets the gate —
    // build the command by hand so the gate is genuinely absent.
    $cmd = 'HOME=' . escapeshellarg($home) . ' ' . PHP_BINARY . ' ' . escapeshellarg($bin) . ' --selftest=hold:1';
    $t0 = microtime(true);
    exec($cmd . ' 2>&1', $out, $code);
    $elapsed = microtime(true) - $t0;
    assert_eq(TICK_EXIT_USAGE_ERROR, $code);
    assert_true($elapsed < 1.0, 'must be refused immediately, not actually held for 1s');
    assert_true(str_contains(implode("\n", $out), 'OTACK_TICK_ALLOW_SELFTEST'), implode("\n", $out));
});

it('--selftest=hold:<junk> is rejected rather than silently coerced to 0', function () {
    $home = _tickHome();
    [$cmd] = _tick($home, '--selftest=hold:not-a-number');
    exec($cmd . ' 2>&1', $out, $code);
    assert_eq(TICK_EXIT_USAGE_ERROR, $code);
});

it('--selftest=hold:<n> above the cap is rejected rather than holding the mutex for a long time', function () {
    $home = _tickHome();
    [$cmd] = _tick($home, '--selftest=hold:999999999');
    $t0 = microtime(true);
    exec($cmd . ' 2>&1', $out, $code);
    $elapsed = microtime(true) - $t0;
    assert_eq(TICK_EXIT_USAGE_ERROR, $code);
    assert_true($elapsed < 1.0, 'a rejected value must not actually sleep');
});

it('print-cmd: refuses to build a command with zero write-enabled projects', function () {
    $home = _tickHome();
    // No projects.json at all — spec §7.3: the agent may touch nothing.
    [$cmd] = _tick($home, '--selftest=print-cmd');
    exec($cmd . ' 2>&1', $out, $code);
    assert_eq(TICK_EXIT_CONFIG_ERROR, $code);
    assert_true(str_contains(implode("\n", $out), 'no write-enabled projects'), implode("\n", $out));

    // Same outcome with a projects.json present but every entry write:false.
    $home2 = _tickHome();
    $proj = _tickTempProjectDir();
    _tickWriteProjects($home2, ['1' => ['path' => $proj, 'write' => false]]);
    [$cmd2] = _tick($home2, '--selftest=print-cmd');
    exec($cmd2 . ' 2>&1', $out2, $code2);
    assert_eq(TICK_EXIT_CONFIG_ERROR, $code2);
    assert_true(str_contains(implode("\n", $out2), 'no write-enabled projects'), implode("\n", $out2));
});

it('print-cmd: a test_cmd containing ) is rejected instead of being interpolated', function () {
    $home = _tickHome();
    $proj = _tickTempProjectDir();
    _tickWriteProjects($home, [
        '1' => ['path' => $proj, 'write' => true, 'test_cmd' => 'make test) Bash(rm -rf /tmp/x'],
    ]);
    [$cmd] = _tick($home, '--selftest=print-cmd');
    exec($cmd . ' 2>&1', $out, $code);
    assert_eq(TICK_EXIT_CONFIG_ERROR, $code, implode("\n", $out));
    assert_true(str_contains(implode("\n", $out), 'test_cmd'), implode("\n", $out));
});

it('print-cmd: a plain multi-word test_cmd like "make test" is accepted', function () {
    $home = _tickHome();
    $proj = _tickTempProjectDir();
    _tickWriteProjects($home, ['1' => ['path' => $proj, 'write' => true, 'test_cmd' => 'make test']]);
    [$cmd] = _tick($home, '--selftest=print-cmd');
    exec($cmd . ' 2>&1', $out, $code);
    assert_eq(TICK_EXIT_OK, $code, implode("\n", $out));
    $decoded = _tickDecodePrintCmd(implode("\n", $out));
    assert_true(in_array('Bash(make test:*)', $decoded['allowed_tools'], true), implode("\n", $out));
});

it('print-cmd: constructs a command bounded exactly the way spec §9 and C1/C2/M5 require', function () {
    $home = _tickHome();
    $projWrite1 = _tickTempProjectDir();
    $projWrite2 = _tickTempProjectDir();
    $projReadOnly = _tickTempProjectDir();
    _tickWriteProjects($home, [
        '1' => ['path' => $projWrite1, 'write' => true, 'test_cmd' => 'make unit'],
        '2' => ['path' => $projWrite2, 'write' => true],
        '3' => ['path' => $projReadOnly, 'write' => false, 'test_cmd' => 'make dangerous-test'],
    ]);
    [$cmd] = _tick($home, '--selftest=print-cmd');
    exec($cmd . ' 2>&1', $out, $code);
    assert_eq(TICK_EXIT_OK, $code, implode("\n", $out));
    $decoded = _tickDecodePrintCmd(implode("\n", $out));
    $full = $decoded['cmd'];

    // M6 mutant: --permission-mode switched to bypassPermissions.
    assert_true(str_contains($full, '--permission-mode'), $full);
    assert_true(str_contains($full, 'dontAsk'), $full);
    assert_true(!str_contains($full, 'bypassPermissions'), $full);

    // Never --dangerously-skip-permissions, anywhere in the command or the
    // structured fields — this is the one flag this whole file exists to
    // keep out (spec §9's boundaries only mean anything if checks are
    // actually enforced, not disabled).
    assert_true(!str_contains(strtolower(json_encode($decoded)), 'dangerously'), json_encode($decoded));

    // M6 mutant: --allowedTools dropped entirely.
    assert_true(str_contains($full, '--allowedTools'), $full);
    assert_true(count($decoded['allowed_tools']) > 0, $full);
    // M6 mutant: the allow-list collapsing to ['*'].
    assert_true(!in_array('*', $decoded['allowed_tools'], true), implode(',', $decoded['allowed_tools']));
    foreach ($decoded['allowed_tools'] as $rule) {
        assert_true($rule !== '*' && trim($rule) !== '*', "wildcard rule leaked through: $rule");
    }

    // M6 mutant: --add-dir dropped entirely / built from write:false projects too.
    assert_true(str_contains($full, '--add-dir'), $full);
    assert_eq(2, substr_count($full, '--add-dir'), 'exactly one --add-dir per write-enabled project: ' . $full);
    // Config::load() realpath()s every project path (e.g. macOS resolves
    // /tmp/... to /private/tmp/...), so compare against the same canonical
    // form rather than the raw sys_get_temp_dir() path.
    assert_eq([realpath($projWrite1), realpath($projWrite2)], $decoded['add_dirs']);
    assert_true(!str_contains($full, $projReadOnly), 'a write:false project path must never appear in the command: ' . $full);

    // M6 mutant: the allow-list built from write:false projects' test_cmd too.
    assert_true(!in_array('Bash(make dangerous-test:*)', $decoded['allowed_tools'], true), implode(',', $decoded['allowed_tools']));
    assert_true(in_array('Bash(make unit:*)', $decoded['allowed_tools'], true), implode(',', $decoded['allowed_tools']));

    // M5: the git prefixes must not grant exactly what §9 forbids.
    assert_true(!in_array('Bash(git branch:*)', $decoded['allowed_tools'], true), 'git branch must be dropped entirely, not narrowed');
    assert_true(in_array('Bash(git push --force:*)', $decoded['disallowed_tools'], true));
    assert_true(in_array('Bash(git checkout main:*)', $decoded['disallowed_tools'], true));
    assert_true(in_array('Bash(git branch -D:*)', $decoded['disallowed_tools'], true));
    assert_true(in_array('Bash(git tag:*)', $decoded['disallowed_tools'], true));

    // C1 belt-and-braces.
    assert_true(in_array('WebFetch', $decoded['disallowed_tools'], true));
    assert_true(in_array('Bash(php -r:*)', $decoded['disallowed_tools'], true));

    // C1: the child must run under an isolated CLAUDE_CONFIG_DIR, never the
    // operator's own ~/.claude.
    assert_true($decoded['claude_config_dir'] !== null && $decoded['claude_config_dir'] !== '', $full);
    assert_true(str_contains($decoded['claude_config_dir'], '/claude-home'), $decoded['claude_config_dir']);
    assert_true(
        is_file($decoded['claude_config_dir'] . '/settings.json'),
        'otack-tick must have written a minimal settings.json into the isolated config dir'
    );
    $isolated = json_decode((string)file_get_contents($decoded['claude_config_dir'] . '/settings.json'), true);
    assert_eq([], $isolated['permissions']['allow'] ?? ['not-empty']);

    // C2: cwd must be a deliberate, vetted directory — never $HOME (token,
    // settings) and never left at whatever the caller's shell happened to
    // have (the otack-tasks checkout, per the documented crontab line).
    assert_true($decoded['cwd'] !== $home, 'cwd must not be $HOME');
    assert_true(str_contains($decoded['cwd'], '/session-cwd'), $decoded['cwd']);
});

it('a real session: the lock stays held for the child\'s entire lifetime, and its exit code propagates', function () {
    // M6: kills two mutants at once — "releasing the lock immediately
    // before passthru()" (the one failure this file exists to prevent) and
    // "a session failure exiting 0 so cron never sees it". Stubs `claude`
    // via PATH with a script that sleeps briefly then exits a distinctive
    // non-zero code, so this test exercises the real (non-selftest)
    // passthru() path end to end.
    $home = _tickHome();
    $proj = _tickTempProjectDir();
    _tickWriteProjects($home, ['1' => ['path' => $proj, 'write' => true]]);

    $binDir = sys_get_temp_dir() . '/otack-tick-stub-bin-' . bin2hex(random_bytes(4));
    mkdir($binDir, 0700, true);
    register_shutdown_function(fn() => _tickRrmdir($binDir));
    $stubExitCode = 47;
    file_put_contents($binDir . '/claude', "#!/bin/sh\nsleep 1\nexit $stubExitCode\n");
    chmod($binDir . '/claude', 0755);

    $bin = dirname(__DIR__, 2) . '/agent/bin/otack-tick';
    $path = $binDir . ':' . getenv('PATH');
    $cmd = 'HOME=' . escapeshellarg($home) . ' PATH=' . escapeshellarg($path) . ' '
         . PHP_BINARY . ' ' . escapeshellarg($bin);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert_true(is_resource($p), 'failed to start otack-tick');

    usleep(400_000); // let it acquire the lock and exec into the stub via passthru()
    $lockPath = $home . '/.claude/otack-agent/agent.lock';
    assert_true(is_file($lockPath), 'lock file should exist while the child runs');
    assert_true(
        !_tickFlockIsFree($lockPath),
        'the lock must still be held while the stubbed child process is running — ' .
        'releasing it before passthru() would let a concurrent tick start alongside a real session'
    );

    fclose($pipes[1]); fclose($pipes[2]);
    $exitCode = proc_close($p);
    assert_eq($stubExitCode, $exitCode, 'the child\'s exit code must propagate, not collapse to 0');
    assert_true(_tickFlockIsFree($lockPath), 'the lock must be free once the whole session (wrapper + child) has exited');
});
