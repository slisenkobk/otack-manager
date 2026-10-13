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
const TICK_EXIT_CHILD_BASE = 100;
const TICK_EXIT_GIT_REFUSED = 90;

function _tickHome(): string {
    $home = sys_get_temp_dir() . '/otack-tick-test-' . bin2hex(random_bytes(4));
    mkdir($home . '/.claude/otack-agent', 0700, true);
    file_put_contents($home . '/.claude/otack-agent/.env',
        "OTACK_API_URL=http://localhost:8771/api/v1\nOTACK_API_TOKEN=otk_test\n");
    chmod($home . '/.claude/otack-agent/.env', 0600);
    // Task 1 (otack-manager skills): the tick refuses to start without the
    // installed agent wrapper and the bot skill inside claude-home.
    mkdir($home . '/.claude/otack-agent/bin', 0700, true);
    file_put_contents($home . '/.claude/otack-agent/bin/otack', "#!/bin/sh\nexit 0\n");
    chmod($home . '/.claude/otack-agent/bin/otack', 0755);
    mkdir($home . '/.claude/otack-agent/claude-home/skills/otack-manager-agent', 0700, true);
    file_put_contents($home . '/.claude/otack-agent/claude-home/skills/otack-manager-agent/SKILL.md', "---\nname: otack-manager-agent\n---\n");
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

/**
 * A directory holding a stub `claude`, first on PATH. Third pass:
 * otack-tick now resolves `claude` to an absolute path and refuses to
 * launch when it cannot find one (cron's PATH is /usr/bin:/bin, which does
 * not contain ~/.local/bin — a bare `claude` in the command used to be a
 * scheduled run failing with "command not found" for a reason no message
 * explained). Every helper below therefore puts a stub on PATH, which also
 * means the resolution path itself is exercised by every test.
 */
function _tickStubBin(int $exitCode = 0, string $extraBody = ''): string {
    $dir = sys_get_temp_dir() . '/otack-tick-stub-bin-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    register_shutdown_function(fn() => _tickRrmdir($dir));
    file_put_contents($dir . '/claude', "#!/bin/sh\n$extraBody\nexit $exitCode\n");
    chmod($dir . '/claude', 0755);
    return $dir;
}

/** One shared no-op stub, created lazily, for every helper that doesn't need its own. */
function _tickDefaultStubBin(): string {
    static $dir = null;
    if ($dir === null) { $dir = _tickStubBin(); }
    return $dir;
}

function _tick(string $home, string $args, array $extraEnv = []): array {
    $bin = dirname(__DIR__, 2) . '/agent/bin/otack-tick';
    $env = array_merge(
        ['HOME' => $home, 'OTACK_TICK_ALLOW_SELFTEST' => '1', 'PATH' => _tickDefaultStubBin() . ':' . getenv('PATH')],
        $extraEnv
    );
    $envStr = '';
    foreach ($env as $k => $v) { $envStr .= $k . '=' . escapeshellarg((string)$v) . ' '; }
    $cmd = $envStr . PHP_BINARY . ' ' . escapeshellarg($bin) . ' ' . $args;
    return [$cmd];
}

/** Runs `otack-tick --selftest=<mode>` and returns its exit code and both streams. */
function _tickRunSelftest(string $home, string $mode, array $extraEnv = []): array {
    [$cmd] = _tick($home, '--selftest=' . $mode, $extraEnv);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
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
    // stdout only: the wrapper also writes advisory notes to stderr (a
    // project that is not a git working tree, for one), and print-cmd's
    // stdout must stay parseable JSON on its own.
    exec($cmd . ' 2>/dev/null', $out, $code);
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
    exec($cmd . ' 2>/dev/null', $out, $code); // stdout only — see the note above

    assert_eq(TICK_EXIT_OK, $code, implode("\n", $out));
    $decoded = _tickDecodePrintCmd(implode("\n", $out));
    $full = $decoded['cmd'];

    // M6 mutant: --permission-mode switched to bypassPermissions.
    // F1: acceptEdits, not dontAsk — see the dedicated test below for why
    // the mode and the absence of Edit/Write are one fix, not two.
    assert_true(str_contains($full, '--permission-mode'), $full);
    assert_true(str_contains($full, 'acceptEdits'), $full);
    assert_true(!str_contains($full, 'bypassPermissions'), $full);
    assert_true(!str_contains($full, 'dontAsk'), $full);

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

    $stubExitCode = 47;
    $binDir = _tickStubBin($stubExitCode, 'sleep 1');

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
    // Third pass: `exit($code)` propagated the child's status verbatim, so
    // a session exiting 1, 2 or 75 was indistinguishable from the
    // wrapper's own config error / usage error / blocked. The child's code
    // is now offset by EXIT_CHILD_BASE, which keeps it visible AND
    // unambiguous.
    assert_eq(TICK_EXIT_CHILD_BASE + $stubExitCode, $exitCode, 'the child\'s exit code must be reported, offset out of the wrapper\'s own code space');
    assert_true($exitCode !== TICK_EXIT_CONFIG_ERROR && $exitCode !== TICK_EXIT_USAGE_ERROR && $exitCode !== TICK_EXIT_BLOCKED,
        'a child failure must never be reported using one of the wrapper\'s own codes');
    assert_true(_tickFlockIsFree($lockPath), 'the lock must be free once the whole session (wrapper + child) has exited');
});

// ---------------------------------------------------------------------
// Third hardening pass. Each block below corresponds to a finding whose
// failure was reproduced first against the previous commit, and to one of
// the mutants that survived the previous test suite.
// ---------------------------------------------------------------------

/**
 * A throwaway git repository with a real (bare) remote, on branch
 * `otack/task-1`, default branch `main`. Returns
 * ['repo' => …, 'remote' => …].
 */
function _tickGitRepo(bool $withOriginHead = true): array {
    $base = sys_get_temp_dir() . '/otack-tick-git-' . bin2hex(random_bytes(4));
    mkdir($base, 0700, true);
    register_shutdown_function(fn() => _tickRrmdir($base));
    $remote = $base . '/remote.git';
    $repo   = $base . '/repo';
    $q = ' >/dev/null 2>&1';
    exec('git init -q --bare ' . escapeshellarg($remote) . $q);
    // The bare repo's HEAD follows git's init.defaultBranch (master on git
    // 2.28 and on CI runners) — point it at main so `remote set-head -a` works.
    exec('git --git-dir=' . escapeshellarg($remote) . ' symbolic-ref HEAD refs/heads/main' . $q);
    exec('git clone -q ' . escapeshellarg($remote) . ' ' . escapeshellarg($repo) . $q);
    $g = 'cd ' . escapeshellarg($repo) . ' && git ';
    exec($g . 'config user.email agent@example.test' . $q);
    exec($g . 'config user.name Agent' . $q);
    exec($g . 'symbolic-ref HEAD refs/heads/main' . $q);
    file_put_contents($repo . '/a.txt', "one\n");
    exec($g . 'add a.txt' . $q);
    exec($g . 'commit -qm init' . $q);
    exec($g . 'push -q origin main' . $q);
    if ($withOriginHead) { exec($g . 'remote set-head origin -a' . $q); }
    exec($g . 'checkout -q -b otack/task-1' . $q);
    // One commit that only exists on the task branch, so a push to the
    // default branch has something to carry — otherwise git short-circuits
    // with "Everything up-to-date" and never reaches the pre-push hook.
    file_put_contents($repo . '/b.txt', "two\n");
    exec($g . 'add b.txt' . $q);
    exec($g . 'commit -qm work' . $q);
    // 'remote' is the URL as git recorded it, not its realpath: that is
    // what `git remote get-url origin` returns and therefore what the
    // policy compares against.
    return ['repo' => realpath($repo), 'remote' => $remote];
}

/** A tick home wired to one write-enabled git project. */
function _tickHomeWithRepo(array $repo, array $extra = []): string {
    $home = _tickHome();
    _tickWriteProjects($home, ['1' => array_merge(['path' => $repo['repo'], 'write' => true], $extra)]);
    return $home;
}

/** Runs `otack-tick --selftest=print-cmd` and returns the decoded JSON. */
function _tickPrintCmd(string $home): array {
    [$cmd] = _tick($home, '--selftest=print-cmd');
    exec($cmd . ' 2>/dev/null', $out, $code);
    assert_eq(TICK_EXIT_OK, $code, implode("\n", $out));
    return _tickDecodePrintCmd(implode("\n", $out));
}

/** Invokes the git gate the way the generated PATH shim does. */
function _tickGitGate(string $policyPath, string $cwd, string $args): array {
    $bin = dirname(__DIR__, 2) . '/agent/bin/otack-tick';
    $cmd = 'cd ' . escapeshellarg($cwd) . ' && OTACK_GIT_POLICY=' . escapeshellarg($policyPath)
         . ' ' . PHP_BINARY . ' ' . escapeshellarg($bin) . ' --git-gate ' . $args . ' 2>&1';
    $out = []; $code = 0;
    exec($cmd, $out, $code);
    return ['out' => implode("\n", $out), 'code' => $code];
}

it('F1: Write and Edit are NOT in the allow-list, and the mode is acceptEdits — together that is what bounds file writes', function () {
    // The second pass concluded --add-dir could not bound Write. It could:
    // the cause was that `Write` was listed in --allowedTools, and a bare
    // tool name is an unconditional grant that never consults the
    // directory check. Measured against a real `claude -p`, ground truth
    // taken from outside the child (does the file exist?), identical
    // prompt each time:
    //   dontAsk     + Write allowed  + target outside every --add-dir -> file CREATED
    //   acceptEdits + Write withheld + target inside  an  --add-dir   -> file created
    //   acceptEdits + Write withheld + target outside every --add-dir -> REFUSED
    // If either half of this test is ever "fixed" by putting Write back in
    // the list, the boundary silently disappears again.
    $home = _tickHomeWithRepo(_tickGitRepo());
    $decoded = _tickPrintCmd($home);

    assert_true(!in_array('Write', $decoded['allowed_tools'], true),
        'Write must NOT be in --allowedTools — a bare grant bypasses the --add-dir check entirely');
    assert_true(!in_array('Edit', $decoded['allowed_tools'], true),
        'Edit must NOT be in --allowedTools, same reason');
    assert_eq('acceptEdits', $decoded['permission_mode']);
    // Read stays: the session must be able to read the project it works on.
    assert_true(in_array('Read', $decoded['allowed_tools'], true), implode(',', $decoded['allowed_tools']));
    // And the boundary only exists if there is a boundary to speak of.
    assert_true(count($decoded['add_dirs']) > 0, 'acceptEdits without --add-dir would bound nothing');
});

it('F6: --disallowedTools actually reaches the command line, with every §9 absolute in it', function () {
    // The previous suite asserted on $decoded['disallowed_tools'] only,
    // which is the array — dropping the flag while still building the
    // array survived all 14 tests, silently re-opening force-push, tags and
    // branch deletion. Assert against the constructed command string.
    $home = _tickHomeWithRepo(_tickGitRepo());
    $decoded = _tickPrintCmd($home);
    $full = $decoded['cmd'];

    assert_true(str_contains($full, '--disallowedTools'), 'the flag itself must be on the command line: ' . $full);
    foreach ($decoded['disallowed_tools'] as $rule) {
        assert_true(str_contains($full, $rule), "deny rule missing from the actual command: $rule");
    }

    // Mutant: dropping individual entries. The old test asserted only
    // WebFetch and `php -r`, so removing curl/wget/ssh/`npx tsx -e` from
    // the deny list went unnoticed.
    foreach ([
        'Bash(curl:*)', 'Bash(wget:*)', 'Bash(ssh:*)', 'Bash(npx tsx -e:*)',
        'Bash(php -r:*)', 'WebFetch',
        'Bash(git push --force:*)', 'Bash(git push --tags:*)', 'Bash(git tag:*)',
        'Bash(git branch -D:*)', 'Bash(git branch --delete:*)',
    ] as $rule) {
        assert_true(in_array($rule, $decoded['disallowed_tools'], true), "deny rule dropped: $rule");
    }

    // Same for --allowedTools: the flag, not just the array.
    assert_true(str_contains($full, '--allowedTools'), $full);
});

it('--add-dir values are shell-escaped, so a project path with a space cannot split into two arguments', function () {
    // Mutant: dropping escapeshellarg() on --add-dir values. Every existing
    // fixture used space-free temp paths, so it survived.
    $repo = _tickGitRepo();
    $spaced = dirname($repo['repo']) . '/dir with spaces';
    rename($repo['repo'], $spaced);
    $home = _tickHome();
    _tickWriteProjects($home, ['1' => ['path' => $spaced, 'write' => true, 'default_branch' => 'main']]);
    $decoded = _tickPrintCmd($home);

    assert_eq([realpath($spaced)], $decoded['add_dirs']);
    assert_true(
        str_contains($decoded['cmd'], escapeshellarg(realpath($spaced))),
        'the path must appear quoted in the command, not raw: ' . $decoded['cmd']
    );
    assert_true(
        !str_contains($decoded['cmd'], '--add-dir ' . realpath($spaced)),
        'an unquoted path would be parsed as several arguments: ' . $decoded['cmd']
    );
});

it('the lock payload is truncated before rewriting, so a longer previous payload cannot corrupt it', function () {
    // Mutant: dropping ftruncate($lock, 0). fopen(…, 'c') does not
    // truncate, so the leftover tail of a longer previous write stays
    // behind and the next tick reads unparseable JSON.
    $home = _tickHome();
    $lockPath = $home . '/.claude/otack-agent/agent.lock';
    file_put_contents($lockPath, str_repeat('X', 4096));

    [$cmd] = _tick($home, '--selftest=hold:0');
    exec($cmd . ' 2>&1', $out, $code);
    assert_eq(TICK_EXIT_OK, $code, implode("\n", $out));

    $raw = (string)file_get_contents($lockPath);
    assert_true(!str_contains($raw, 'XXXX'), 'stale bytes survived the rewrite: ' . substr($raw, 0, 200));
    $info = json_decode($raw, true);
    assert_true(is_array($info) && isset($info['pid']), 'the lock file must still parse as JSON: ' . substr($raw, 0, 200));
});

it('the inode re-check fires when the lock file is replaced inside the fopen/flock window', function () {
    // Mutant: removing the fstat/fresh-stat comparison. The window is
    // microseconds wide in production, so the test widens it with a
    // test-only flag (same gate as every other --selftest surface) and
    // replaces the file inside it — otherwise this defence is untestable
    // and, as the reviewer found, removable without a single failure.
    $home = _tickHome();
    $lockPath = $home . '/.claude/otack-agent/agent.lock';

    [$bootstrap] = _tick($home, '--selftest=hold:0');
    exec($bootstrap . ' 2>&1', $b, $bc);
    assert_eq(TICK_EXIT_OK, $bc, implode("\n", $b));

    [$cmd] = _tick($home, '--selftest=hold:0 --selftest-race-sleep-ms=1500');
    $p = proc_open($cmd, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    usleep(500_000); // inside the widened fopen()->flock() window
    unlink($lockPath);
    file_put_contents($lockPath, '{}'); // a different inode now sits at the path
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);

    assert_eq(TICK_EXIT_CONFIG_ERROR, $code, 'a replaced lock file must abort the tick');
    assert_true(str_contains($err, 'was replaced while being acquired'), $err);
});

/** A pid that is certainly not running, so "the recorded holder is dead" can be staged deterministically. */
function _tickDeadPid(): int {
    for ($pid = 99990; $pid > 60000; $pid--) {
        if (!function_exists('posix_kill')) { return $pid; }
        if (!@posix_kill($pid, 0) && posix_get_last_error() !== 1) { return $pid; }
    }
    return 99999;
}

it('F2: a lock held by a leaked descriptor, with its recorded holder dead, is named as such and posts nothing', function () {
    // Reproduced against the previous commit: the lock fd is inherited by
    // `claude` and by everything it spawns, so a background process the
    // session left behind (`make test` starting a dev server) keeps holding
    // it after the wrapper exits. Every later tick then printed "tick
    // already running (pid <dead>)" with no otack-tick in `ps`, and after
    // three hours posted the stale-tick comment into a task on every single
    // tick. Staged here without an orphan process: the test itself holds
    // the flock while the payload names a pid that is definitely dead.
    $home = _tickHome();
    $lockPath = $home . '/.claude/otack-agent/agent.lock';
    $deadPid = _tickDeadPid();
    file_put_contents($lockPath, json_encode([
        'pid'        => $deadPid,
        'host'       => gethostname(),
        'task_id'    => 55,
        'phase'      => 'implementing',
        'started_at' => time() - (4 * 3600),
    ]));
    $fh = fopen($lockPath, 'c');
    assert_true(flock($fh, LOCK_EX | LOCK_NB), 'the test must be able to hold the lock itself');

    [$cmd] = _tick($home, '');
    exec($cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);

    assert_eq(TICK_EXIT_BLOCKED, $code, $text);
    assert_true(str_contains($text, 'is not running on this machine'), $text);
    assert_true(str_contains($text, 'inherited file descriptor'), 'must explain WHY the lock outlived the session: ' . $text);
    assert_true(str_contains($text, 'lsof'), 'must tell the operator how to find the real holder: ' . $text);
    // The stale-tick comment must be suppressed: nothing is running to be slow.
    assert_true(!str_contains($text, 'could not post the stale-tick warning comment'),
        'a dead holder must not trigger the "работаю подозрительно долго" post: ' . $text);
    assert_true(!is_file($home . '/.claude/otack-agent/stale-warned'),
        'no stale-warned marker should be burned for a lock with no live holder');

    flock($fh, LOCK_UN); fclose($fh);
});

it('F4: deleting agent.lock while nothing is running bootstraps a fresh one instead of bricking the bot', function () {
    // Reproduced against the previous commit with nothing running at all:
    // bootstrap tick lays the marker, operator deletes agent.lock while
    // idle, and every later tick exits 1 forever — recoverable only by
    // deleting a dotfile nobody has heard of. The refusal is now gated on
    // the recorded holder actually being alive (the M1 test above still
    // covers the case where it is).
    $home = _tickHome();
    $lockPath = $home . '/.claude/otack-agent/agent.lock';

    [$bootstrap] = _tick($home, '--selftest=hold:0');
    exec($bootstrap . ' 2>&1', $b, $bc);
    assert_eq(TICK_EXIT_OK, $bc, implode("\n", $b));
    assert_true(is_file($home . '/.claude/otack-agent/.agent-lock-initialized'));

    unlink($lockPath); // nothing is running

    [$next] = _tick($home, '--selftest=hold:0');
    exec($next . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    assert_eq(TICK_EXIT_OK, $code, 'an idle deletion must not brick the bot: ' . $text);
    assert_true(str_contains($text, 'bootstrapping a fresh lock'), 'and it must say that it did: ' . $text);
    assert_true(is_file($lockPath), 'the lock file should have been recreated');

    // Still healthy on the tick after that, with nothing left to say.
    [$third] = _tick($home, '--selftest=hold:0');
    exec($third . ' 2>&1', $out3, $code3);
    assert_eq(TICK_EXIT_OK, $code3, implode("\n", $out3));
    assert_true(!str_contains(implode("\n", $out3), 'bootstrapping'), 'the second recovery should be silent');
});

it('F5: the session cwd has no sensitive neighbours — never $HOME, never beside .env/projects.json', function () {
    $home = _tickHomeWithRepo(_tickGitRepo());
    $decoded = _tickPrintCmd($home);
    $cwd = $decoded['cwd'];
    assert_true($cwd !== $home, 'cwd must not be $HOME');
    assert_true(
        !str_starts_with($cwd, $home . '/.claude/otack-agent'),
        'cwd must not be a child of the directory holding .env (the bearer token) and projects.json ' .
        '(the allow-list deciding what the next tick may touch): ' . $cwd
    );
    assert_true(is_dir($cwd), $cwd);
    // Nothing sensitive may share the directory the session starts in.
    $siblings = array_values(array_diff(scandir(dirname($cwd)) ?: [], ['.', '..']));
    assert_eq(['session-cwd'], $siblings, 'the session cwd should be alone in its parent: ' . implode(',', $siblings));
});

it('F3: the branch policy is a real gate — the shim, the hooks and the policy file all reach the child', function () {
    $repo = _tickGitRepo();
    $home = _tickHomeWithRepo($repo);
    $decoded = _tickPrintCmd($home);

    $gate = $decoded['git_gate'];
    assert_true(is_file($gate['shim']), 'the PATH shim must exist: ' . $gate['shim']);
    assert_true(is_executable($gate['shim']), 'and be executable');
    assert_true(str_contains((string)file_get_contents($gate['shim']), '--git-gate'), 'the shim must re-enter otack-tick');
    assert_eq('git', basename($gate['shim']), 'the shim must be named git so PATH resolution finds it');
    foreach (['pre-commit', 'pre-push'] as $hook) {
        $p = $gate['hooks_dir'] . '/' . $hook;
        assert_true(is_file($p) && is_executable($p), "missing hook: $p");
    }

    // The policy is derived from projects.json, never hardcoded.
    $entry = $gate['policy']['repos'][$repo['repo']] ?? null;
    assert_true(is_array($entry), 'the write-enabled repository must be in the policy: ' . json_encode($gate['policy']));
    assert_eq('main', $entry['default_branch']);
    assert_eq($repo['remote'], $entry['origin']);

    // And the child's environment is what activates both mechanisms.
    $env = $decoded['child_env'];
    assert_true(str_starts_with($env['PATH'], dirname($gate['shim']) . ':'),
        'the shim directory must come FIRST on the child PATH: ' . $env['PATH']);
    assert_eq('core.hooksPath', $env['GIT_CONFIG_KEY_0']);
    assert_eq($gate['hooks_dir'], $env['GIT_CONFIG_VALUE_0']);
    assert_eq('2', $env['GIT_CONFIG_COUNT']);
    assert_eq($gate['policy_path'], $env['OTACK_GIT_POLICY']);
});

it('F3: the gate refuses the exact two commands that defeated the prefix rules', function () {
    // `git checkout -B main` does not match a `Bash(git checkout main:*)`
    // deny prefix, and `git push origin HEAD:main` does not match
    // `Bash(git push origin main:*)` — the reviewer advanced a real
    // remote's main with exactly this pair.
    $repo = _tickGitRepo();
    $home = _tickHomeWithRepo($repo);
    $policy = _tickPrintCmd($home)['git_gate']['policy_path'];

    $before = trim((string)shell_exec('git --git-dir=' . escapeshellarg($repo['remote']) . ' rev-parse main'));

    $r1 = _tickGitGate($policy, $repo['repo'], 'checkout -B main');
    assert_eq(TICK_EXIT_GIT_REFUSED, $r1['code'], $r1['out']);
    assert_true(str_contains($r1['out'], 'default branch'), $r1['out']);

    $r2 = _tickGitGate($policy, $repo['repo'], 'push origin HEAD:main');
    assert_eq(TICK_EXIT_GIT_REFUSED, $r2['code'], $r2['out']);
    assert_true(str_contains($r2['out'], 'default branch'), $r2['out']);

    $after = trim((string)shell_exec('git --git-dir=' . escapeshellarg($repo['remote']) . ' rev-parse main'));
    assert_eq($before, $after, 'the remote default branch must not have moved');
    assert_eq('otack/task-1', trim((string)shell_exec(
        'git -C ' . escapeshellarg($repo['repo']) . ' symbolic-ref --short HEAD')),
        'and the working tree must still be on its task branch');
});

it('F3: the gate refuses shipping the repository to any destination but its own origin', function () {
    $repo = _tickGitRepo();
    $elsewhere = dirname($repo['repo']) . '/elsewhere.git';
    exec('git init -q --bare ' . escapeshellarg($elsewhere) . ' >/dev/null 2>&1');
    $home = _tickHomeWithRepo($repo);
    $policy = _tickPrintCmd($home)['git_gate']['policy_path'];

    $r = _tickGitGate($policy, $repo['repo'], 'push ' . escapeshellarg($elsewhere) . ' HEAD:refs/heads/backup');
    assert_eq(TICK_EXIT_GIT_REFUSED, $r['code'], $r['out']);
    assert_true(str_contains($r['out'], 'own origin'), $r['out']);
    assert_eq('', trim((string)shell_exec('git --git-dir=' . escapeshellarg($elsewhere) . ' show-ref 2>/dev/null')),
        'nothing may have landed in the other repository');
});

it('F3: the gate refuses every way of switching itself off, and still allows ordinary work', function () {
    $repo = _tickGitRepo();
    $home = _tickHomeWithRepo($repo);
    $policy = _tickPrintCmd($home)['git_gate']['policy_path'];

    $refusals = [
        // --no-verify would skip the hooks that back the shim up.
        'push --no-verify origin HEAD:refs/heads/x' => 'no-verify',
        // -c can re-point core.hooksPath.
        '-c core.hooksPath=/dev/null push origin HEAD:main' => 'global option',
        'tag v1'          => 'tags',
        'branch -D main'  => 'deletes, renames',
        'remote set-url origin /tmp/nope' => 'read-only',
        'config core.hooksPath /dev/null' => 'read-only',
        'push --force origin HEAD:refs/heads/otack/task-1' => 'rewrites history',
        'push --mirror origin' => 'delete or overwrite',
        'push origin :refs/heads/otack/task-1' => 'deletes a remote ref',
        'push origin +HEAD:refs/heads/otack/task-1' => 'force push',
    ];
    foreach ($refusals as $args => $needle) {
        $r = _tickGitGate($policy, $repo['repo'], $args);
        assert_eq(TICK_EXIT_GIT_REFUSED, $r['code'], "should have been refused: git $args\n" . $r['out']);
        assert_true(str_contains($r['out'], $needle), "wrong reason for `git $args`: " . $r['out']);
    }

    // Read-only inspection passes straight through...
    $ok = _tickGitGate($policy, $repo['repo'], 'status --short --branch');
    assert_eq(0, $ok['code'], $ok['out']);
    assert_true(str_contains($ok['out'], 'otack/task-1'), $ok['out']);

    // ...and so does the work the agent is actually meant to do.
    $push = _tickGitGate($policy, $repo['repo'], 'push origin HEAD:refs/heads/otack/task-1');
    assert_eq(0, $push['code'], 'a task-branch push must still work: ' . $push['out']);
    assert_true(
        str_contains((string)shell_exec('git --git-dir=' . escapeshellarg($repo['remote']) . ' show-ref 2>/dev/null'), 'otack/task-1'),
        'the task branch should now exist on the remote'
    );
});

it('F3: a git reached by absolute path (dodging the PATH shim) is still stopped by the injected hooks', function () {
    // The shim catches --no-verify; core.hooksPath catches a git the shim
    // never saw — e.g. one invoked with an absolute path from a Makefile.
    // Neither alone is enough, which is why both are installed.
    $repo = _tickGitRepo();
    $home = _tickHomeWithRepo($repo);
    $decoded = _tickPrintCmd($home);
    $gate = $decoded['git_gate'];
    $before = trim((string)shell_exec('git --git-dir=' . escapeshellarg($repo['remote']) . ' rev-parse main'));

    $gitBin = trim((string)shell_exec('command -v git 2>/dev/null')) ?: '/usr/bin/git';
    $cmd = 'cd ' . escapeshellarg($repo['repo'])
         . ' && OTACK_GIT_POLICY=' . escapeshellarg($gate['policy_path'])
         . ' GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=core.hooksPath GIT_CONFIG_VALUE_0=' . escapeshellarg($gate['hooks_dir'])
         . ' ' . escapeshellarg($gitBin) . ' push origin HEAD:main 2>&1';
    $out = []; $code = 0;
    exec($cmd, $out, $code);
    $text = implode("\n", $out);

    assert_true($code !== 0, 'the push must fail: ' . $text);
    assert_true(str_contains($text, 'pre-push') || str_contains($text, 'REFUSED'), $text);
    assert_eq($before, trim((string)shell_exec('git --git-dir=' . escapeshellarg($repo['remote']) . ' rev-parse main')),
        'the remote default branch must not have moved');
});

it('F3: the default branch is read from config, not hardcoded to main/master', function () {
    $repo = _tickGitRepo();
    exec('cd ' . escapeshellarg($repo['repo']) . ' && git checkout -q -b trunk main >/dev/null 2>&1');
    exec('cd ' . escapeshellarg($repo['repo']) . ' && git push -q origin trunk >/dev/null 2>&1');
    exec('cd ' . escapeshellarg($repo['repo']) . ' && git checkout -q otack/task-1 >/dev/null 2>&1');

    $home = _tickHome();
    _tickWriteProjects($home, ['1' => ['path' => $repo['repo'], 'write' => true, 'default_branch' => 'trunk']]);
    $decoded = _tickPrintCmd($home);
    assert_eq('trunk', $decoded['git_gate']['policy']['repos'][$repo['repo']]['default_branch']);

    $policy = $decoded['git_gate']['policy_path'];
    $r = _tickGitGate($policy, $repo['repo'], 'push origin HEAD:trunk');
    assert_eq(TICK_EXIT_GIT_REFUSED, $r['code'], 'a project whose default branch is "trunk" must be protected there: ' . $r['out']);
    assert_true(str_contains($r['out'], 'trunk'), $r['out']);
});

it('F3: a write-enabled repository with no discoverable default branch refuses to launch rather than guessing', function () {
    $base = sys_get_temp_dir() . '/otack-tick-nogit-' . bin2hex(random_bytes(4));
    mkdir($base, 0700, true);
    register_shutdown_function(fn() => _tickRrmdir($base));
    exec('cd ' . escapeshellarg($base) . ' && git init -q . >/dev/null 2>&1'); // no remote, no origin/HEAD

    $home = _tickHome();
    _tickWriteProjects($home, ['1' => ['path' => $base, 'write' => true]]);
    [$cmd] = _tick($home, '--selftest=print-cmd');
    exec($cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    assert_eq(TICK_EXIT_CONFIG_ERROR, $code, $text);
    assert_true(str_contains($text, 'cannot determine the default branch'), $text);
    assert_true(str_contains($text, 'default_branch'), 'the message must name the config key that fixes it: ' . $text);
});

// ---------------------------------------------------------------------
// F7 (fourth pass): the gate was right and the bot could not use git at
// all. The session's cwd is a neutral directory, not a project, so every
// git command takes the `git -C <repo> …` form — which matched none of the
// old `Bash(git …:*)` prefixes. Reproduced against a real `claude -p`
// child: `git -C <repo> checkout -B main` was refused by the permission
// layer before the branch-policy gate ever saw it, and so was
// `git -C <repo> remote get-url origin`, which SKILL.md requires before
// any work begins.
//
// The fix widened the allow-list; these tests assert both halves of that:
// the list now carries the `-C` forms (and NOT a blanket `git -C` grant),
// and the gate still refuses every exploit in the same `-C` spelling,
// checked against the remote's refs rather than any command's own output.
// ---------------------------------------------------------------------

/** Every allow rule for one repository path, as the tick builds them. */
function _tickGitAllowRule(string $repoPath, string $sub): string {
    return 'Bash(git -C ' . $repoPath . ' ' . $sub . ':*)';
}

it('F7: the allow-list carries the `git -C <repo> …` forms the session must use, one rule per verb', function () {
    $repo = _tickGitRepo();
    $home = _tickHomeWithRepo($repo);
    $decoded = _tickPrintCmd($home);
    $allowed = $decoded['allowed_tools'];
    $full = $decoded['cmd'];

    // The documented workflow, verb by verb. Dropping any one of these puts
    // the bot back to "safe and useless" for that step.
    foreach ([
        'status', 'add', 'commit', 'push', 'checkout', 'switch',
        'rev-parse',
        'branch --show-current', 'branch --list', 'remote get-url',
        'fetch', 'pull',
    ] as $sub) {
        $rule = _tickGitAllowRule($repo['repo'], $sub);
        assert_true(in_array($rule, $allowed, true), "missing allow rule: $rule\n" . implode("\n", $allowed));
        // …and on the actual command line, not merely in the array (F6's
        // lesson: the array and the flag are two different mutants).
        assert_true(str_contains($full, $rule), "allow rule missing from the constructed command: $rule");
    }

    // NOT a blanket `git -C` grant. That single rule is `git <anything>` in
    // disguise: the gate passes clone/archive/bundle/send-email/daemon/
    // worktree/update-ref through inside a policy repository, so granting
    // it would hand back network egress and writes outside every --add-dir.
    assert_true(!in_array('Bash(git -C:*)', $allowed, true), 'a blanket `git -C` grant is every git subcommand at once');
    foreach ($allowed as $rule) {
        assert_true(
            !preg_match('/^Bash\(git -C:?\*?\)$/', $rule),
            "a blanket `git -C` grant leaked in as: $rule"
        );
    }

    // Verbs deliberately absent, in the -C form as well as bare. Fix round
    // 4b: diff/log/show joined them.
    foreach (['diff', 'log', 'show', 'tag', 'clone', 'worktree', 'update-ref', 'send-email', 'remote set-url', 'config'] as $sub) {
        assert_true(
            !in_array(_tickGitAllowRule($repo['repo'], $sub), $allowed, true),
            "\"git $sub\" must not be grantable in the -C form either"
        );
    }

    // The bare forms are gone: cwd is not a repository and `cd` matches no
    // allow prefix, so they could only ever have granted a git run in the
    // neutral session cwd. Keeping them would read as though the workflow
    // happened inside the checkout.
    foreach ([
        'Bash(git add:*)', 'Bash(git commit:*)', 'Bash(git push:*)',
        'Bash(git status:*)', 'Bash(git diff:*)', 'Bash(git checkout:*)',
    ] as $dead) {
        assert_true(!in_array($dead, $allowed, true), "the bare form should have been dropped: $dead");
    }
});

it('F7: the deny rules exist in the -C spelling too, so the widened allow-list is still covered a second time', function () {
    $repo = _tickGitRepo();
    $home = _tickHomeWithRepo($repo);
    $decoded = _tickPrintCmd($home);
    $deny = $decoded['disallowed_tools'];
    $full = $decoded['cmd'];

    foreach ([
        'push --force', 'push -f', 'push --force-with-lease', 'push --delete',
        'push --mirror', 'push --tags', 'branch -D', 'branch --delete', 'tag',
        'remote set-url', 'config',
        // named from the policy's own default branch, never hardcoded
        'checkout main', 'switch main', 'push origin main',
    ] as $shape) {
        $rule = 'Bash(git -C ' . $repo['repo'] . ' ' . $shape . ':*)';
        assert_true(in_array($rule, $deny, true), "missing deny rule: $rule");
        assert_true(str_contains($full, $rule), "deny rule missing from the constructed command: $rule");
    }

    // A blanket `Bash(git -C:*)` DENY would be just as wrong as the blanket
    // allow, for the opposite reason: deny is a prefix rule and outranks
    // allow, so it would refuse `git -C <repo> status` along with the rest
    // and re-create the exact breakage this pass fixes.
    assert_true(!in_array('Bash(git -C:*)', $deny, true),
        'a blanket `git -C` deny would refuse every legitimate command too');
});

it('F7: the default branch in the -C deny rules comes from config, not from a hardcoded "main"', function () {
    $repo = _tickGitRepo();
    exec('cd ' . escapeshellarg($repo['repo']) . ' && git checkout -q -b trunk main >/dev/null 2>&1');
    exec('cd ' . escapeshellarg($repo['repo']) . ' && git push -q origin trunk >/dev/null 2>&1');
    exec('cd ' . escapeshellarg($repo['repo']) . ' && git checkout -q otack/task-1 >/dev/null 2>&1');

    $home = _tickHome();
    _tickWriteProjects($home, ['1' => ['path' => $repo['repo'], 'write' => true, 'default_branch' => 'trunk']]);
    $deny = _tickPrintCmd($home)['disallowed_tools'];

    assert_true(in_array('Bash(git -C ' . $repo['repo'] . ' push origin trunk:*)', $deny, true), implode("\n", $deny));
    assert_true(in_array('Bash(git -C ' . $repo['repo'] . ' checkout trunk:*)', $deny, true), implode("\n", $deny));
    assert_true(!in_array('Bash(git -C ' . $repo['repo'] . ' push origin main:*)', $deny, true),
        'a repository whose default branch is "trunk" must not be described as "main"');
});

it('F7: a project path that cannot appear in a permission rule gets no git rules and a loud note, not a rule that can never match', function () {
    // A space is harmless to the rule parser but fatal to matching: the
    // model must quote such a path, and `git -C "/a b" status` is not
    // prefixed by `git -C /a b status`. A rule that can never match is
    // worse than no rule, because it reads as a grant.
    $repo = _tickGitRepo();
    $spaced = dirname($repo['repo']) . '/dir with spaces';
    rename($repo['repo'], $spaced);
    $home = _tickHome();
    _tickWriteProjects($home, ['1' => ['path' => $spaced, 'write' => true, 'default_branch' => 'main']]);

    [$cmd] = _tick($home, '--selftest=print-cmd');
    exec($cmd . ' 2>&1', $both, $code);
    assert_eq(TICK_EXIT_OK, $code, implode("\n", $both));
    assert_true(str_contains(implode("\n", $both), 'cannot appear in a permission rule'),
        'the operator must be told why git will not work there: ' . implode("\n", $both));

    $decoded = _tickPrintCmd($home);
    foreach ($decoded['allowed_tools'] as $rule) {
        assert_true(!str_contains($rule, 'git -C'), "no git rule may be emitted for an unquotable path: $rule");
    }
    // …and the project is still a real --add-dir; only git is unavailable.
    assert_eq([realpath($spaced)], $decoded['add_dirs']);
});

it('F7: with the wider allow-list, the gate still refuses every exploit in the -C spelling, from the neutral session cwd', function () {
    // The session's cwd is NOT the repository (F5), so every command here
    // is run from an unrelated directory — exactly the shape the child
    // must use — and the gate has to reach the policy through `-C` alone.
    $repo = _tickGitRepo();
    $elsewhere = dirname($repo['repo']) . '/elsewhere.git';
    exec('git init -q --bare ' . escapeshellarg($elsewhere) . ' >/dev/null 2>&1');
    $home = _tickHomeWithRepo($repo);
    $policy = _tickPrintCmd($home)['git_gate']['policy_path'];
    $neutral = _tickTempProjectDir(); // stands in for ~/.cache/otack-agent/session-cwd
    $C = '-C ' . escapeshellarg($repo['repo']) . ' ';

    $mainBefore = trim((string)shell_exec('git --git-dir=' . escapeshellarg($repo['remote']) . ' rev-parse main'));

    $refusals = [
        $C . 'checkout -B main'                                  => 'default branch',
        $C . 'push origin HEAD:main'                             => 'default branch',
        $C . 'push ' . escapeshellarg($elsewhere) . ' HEAD:refs/heads/backup' => 'own origin',
        $C . 'push --force origin HEAD:refs/heads/otack/task-1'  => 'rewrites history',
        $C . 'tag v1'                                            => 'tags',
        $C . 'branch -D main'                                    => 'deletes, renames',
        $C . 'commit --no-verify --allow-empty -m x'             => 'no-verify',
        $C . 'push origin +HEAD:refs/heads/otack/task-1'         => 'force push',
        $C . 'push origin :refs/heads/otack/task-1'              => 'deletes a remote ref',
        '-c core.hooksPath=/dev/null ' . $C . 'push origin HEAD:main' => 'global option',
        $C . 'remote set-url origin /tmp/nope'                   => 'read-only',
    ];
    foreach ($refusals as $args => $needle) {
        $r = _tickGitGate($policy, $neutral, $args);
        assert_eq(TICK_EXIT_GIT_REFUSED, $r['code'], "should have been refused: git $args\n" . $r['out']);
        assert_true(str_contains($r['out'], $needle), "wrong reason for `git $args`: " . $r['out']);
    }

    // Ground truth, from outside every command above.
    assert_eq($mainBefore, trim((string)shell_exec('git --git-dir=' . escapeshellarg($repo['remote']) . ' rev-parse main')),
        'the remote default branch must not have moved');
    assert_eq('', trim((string)shell_exec('git --git-dir=' . escapeshellarg($elsewhere) . ' show-ref 2>/dev/null')),
        'nothing may have landed in the unrelated repository');
    assert_eq('', trim((string)shell_exec('git -C ' . escapeshellarg($repo['repo']) . ' tag')),
        'no tag may exist');
    assert_eq('otack/task-1', trim((string)shell_exec('git -C ' . escapeshellarg($repo['repo']) . ' symbolic-ref --short HEAD')),
        'the working tree must still be on its task branch');
    assert_true(
        str_contains((string)shell_exec('git -C ' . escapeshellarg($repo['repo']) . ' branch ' . escapeshellarg('--format=%(refname:short)')), 'main'),
        'the local default branch must not have been deleted'
    );
});

it('F7: `pull`, granted for the first time by this pass, is gated like the other history-writing verbs', function () {
    // `pull` is fetch+merge: it writes history into whatever branch is
    // checked out. A tick can find the checkout already sitting on the
    // default branch — that is how a human usually leaves it — so granting
    // `pull` without gating it would have been a way to move the default
    // branch locally, which no other newly-granted verb allows.
    $repo = _tickGitRepo();
    $home = _tickHomeWithRepo($repo);
    $policy = _tickPrintCmd($home)['git_gate']['policy_path'];
    $neutral = _tickTempProjectDir();
    $C = '-C ' . escapeshellarg($repo['repo']) . ' ';

    // On the task branch: allowed (nothing to pull, but the gate lets it by).
    $ok = _tickGitGate($policy, $neutral, $C . 'pull --ff-only origin otack/task-1');
    assert_true($ok['code'] !== TICK_EXIT_GIT_REFUSED, 'a pull on a task branch must not be refused: ' . $ok['out']);

    // On the default branch: refused.
    exec('git -C ' . escapeshellarg($repo['repo']) . ' checkout -q main >/dev/null 2>&1');
    $r = _tickGitGate($policy, $neutral, $C . 'pull origin main');
    assert_eq(TICK_EXIT_GIT_REFUSED, $r['code'], 'a pull while on the default branch must be refused: ' . $r['out']);
    assert_true(str_contains($r['out'], 'default branch'), $r['out']);

    // And outside every repository the policy knows, it fails closed.
    $stranger = _tickTempProjectDir();
    exec('cd ' . escapeshellarg($stranger) . ' && git init -q . >/dev/null 2>&1');
    $out = _tickGitGate($policy, $neutral, '-C ' . escapeshellarg($stranger) . ' pull origin main');
    assert_eq(TICK_EXIT_GIT_REFUSED, $out['code'], 'a pull in an unlisted repository must be refused: ' . $out['out']);
});

it('F7: and the work the widened list exists to allow does pass, from that same neutral cwd', function () {
    $repo = _tickGitRepo();
    $home = _tickHomeWithRepo($repo);
    $policy = _tickPrintCmd($home)['git_gate']['policy_path'];
    $neutral = _tickTempProjectDir();
    $C = '-C ' . escapeshellarg($repo['repo']) . ' ';

    // The first thing SKILL.md requires of every tick, before any change.
    $origin = _tickGitGate($policy, $neutral, $C . 'remote get-url origin');
    assert_eq(0, $origin['code'], $origin['out']);
    assert_eq($repo['remote'], trim($origin['out']));

    foreach (['status --short --branch', 'branch --show-current', 'rev-parse HEAD'] as $readonly) {
        $r = _tickGitGate($policy, $neutral, $C . $readonly);
        assert_eq(0, $r['code'], "read-only inspection must pass: git $readonly\n" . $r['out']);
    }

    $commit = _tickGitGate($policy, $neutral, $C . 'commit --allow-empty -m "legit work"');
    assert_eq(0, $commit['code'], 'a commit on the task branch must pass: ' . $commit['out']);

    $push = _tickGitGate($policy, $neutral, $C . 'push origin HEAD:refs/heads/otack/task-1');
    assert_eq(0, $push['code'], 'the task-branch push must pass: ' . $push['out']);
    assert_true(
        str_contains((string)shell_exec('git --git-dir=' . escapeshellarg($repo['remote']) . ' show-ref 2>/dev/null'), 'otack/task-1'),
        'the task branch should now exist on the remote'
    );
});

it('the isolated settings.json is never written through a symlink', function () {
    $home = _tickHomeWithRepo(_tickGitRepo());
    $claudeHome = $home . '/.claude/otack-agent/claude-home';
    mkdir($claudeHome, 0700, true);
    $decoy = $home . '/decoy-settings.json';
    file_put_contents($decoy, '{"permissions":{"allow":["Bash"]}}');
    symlink($decoy, $claudeHome . '/settings.json');

    [$cmd] = _tick($home, '--selftest=print-cmd');
    exec($cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    assert_eq(TICK_EXIT_CONFIG_ERROR, $code, $text);
    assert_true(str_contains($text, 'symlink'), $text);
    assert_eq('{"permissions":{"allow":["Bash"]}}', (string)file_get_contents($decoy),
        'the rewrite must not have followed the link');
});

it('an existing claude-home with loosened permissions is refused, not used as-is', function () {
    // It holds the bot's Claude Code login; 0700 used to be applied at
    // creation and never checked again.
    $home = _tickHomeWithRepo(_tickGitRepo());
    $claudeHome = $home . '/.claude/otack-agent/claude-home';
    mkdir($claudeHome, 0755, true);
    chmod($claudeHome, 0755);

    [$cmd] = _tick($home, '--selftest=print-cmd');
    exec($cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    assert_eq(TICK_EXIT_CONFIG_ERROR, $code, $text);
    assert_true(str_contains($text, 'chmod 700'), 'the message must say how to fix it: ' . $text);
});

it('claude is resolved to an absolute path, and a missing one is a clear error rather than "command not found"', function () {
    $home = _tickHomeWithRepo(_tickGitRepo());
    $decoded = _tickPrintCmd($home);
    assert_true(str_starts_with($decoded['claude_bin'], '/'), 'claude must be resolved absolutely: ' . $decoded['claude_bin']);
    assert_true(is_executable($decoded['claude_bin']), $decoded['claude_bin']);
    assert_true(str_contains($decoded['cmd'], escapeshellarg($decoded['claude_bin'])),
        'the absolute path must be what the command actually invokes: ' . $decoded['cmd']);

    // cron's PATH. Only assert the failure when this machine genuinely has
    // no claude in the fallback locations, so the test cannot be flaky.
    if (!is_executable('/usr/local/bin/claude') && !is_executable('/opt/homebrew/bin/claude')) {
        [$cmd] = _tick($home, '--selftest=print-cmd', ['PATH' => '/usr/bin:/bin']);
        exec($cmd . ' 2>&1', $out, $code);
        $text = implode("\n", $out);
        assert_eq(TICK_EXIT_CONFIG_ERROR, $code, $text);
        assert_true(str_contains($text, 'cannot find the `claude` executable'), $text);
        assert_true(str_contains($text, 'crontab'), 'and it must point at the fix: ' . $text);
    }
});

it('the session transcript goes to a 0600 log file, not to cron mail', function () {
    // The session has bare `Read` over the filesystem, so its transcript
    // can contain anything it read.
    $repo = _tickGitRepo();
    $home = _tickHomeWithRepo($repo);
    $binDir = _tickStubBin(0, 'echo SECRET-TRANSCRIPT-LINE');

    $bin = dirname(__DIR__, 2) . '/agent/bin/otack-tick';
    $cmd = 'HOME=' . escapeshellarg($home) . ' PATH=' . escapeshellarg($binDir . ':' . getenv('PATH')) . ' '
         . PHP_BINARY . ' ' . escapeshellarg($bin);
    $out = []; $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);

    assert_eq(TICK_EXIT_OK, $code, $text);
    assert_true(!str_contains($text, 'SECRET-TRANSCRIPT-LINE'),
        'the child transcript must not land on the wrapper\'s own stdout/stderr: ' . $text);
    $log = $home . '/.claude/otack-agent/tick-session.log';
    assert_true(is_file($log), 'the transcript must be written to the log');
    assert_true(str_contains((string)file_get_contents($log), 'SECRET-TRANSCRIPT-LINE'), 'and actually contain it');
    assert_eq('0600', substr(sprintf('%o', fileperms($log)), -4));
    assert_true(str_contains($text, 'session transcript'), 'the wrapper must say where it went: ' . $text);
});

// A minimal fixture server, same shape as _cliFixtureServer() in
// test_agent_cli.php but self-contained: this file must not depend on the
// load order of another test file.
function _tickFixtureServer(array $routes, callable $fn): void {
    $dir = sys_get_temp_dir() . '/otack-tick-fixture-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    file_put_contents($dir . '/routes.json', json_encode($routes));
    $router = $dir . '/router.php';
    file_put_contents($router, <<<'PHP'
<?php
$routes = json_decode((string)file_get_contents(__DIR__ . '/routes.json'), true) ?: [];
$key = $_SERVER['REQUEST_METHOD'] . ' ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$resp = $routes[$key] ?? null;
header('Content-Type: application/json');
if ($resp === null) { http_response_code(404); echo json_encode(['error' => 'no route: ' . $key]); exit; }
http_response_code($resp['status'] ?? 200);
echo $resp['body'] ?? '{}';
PHP
    );
    $port = random_int(45000, 49999);
    $pid = (int)trim((string)shell_exec(
        '/usr/bin/env php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router) . ' > /dev/null 2>&1 & echo $!'
    ));
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($s) { fclose($s); break; }
        usleep(50_000);
    }
    try {
        $fn($port);
    } finally {
        if ($pid > 0) {
            if (function_exists('posix_kill')) { @posix_kill($pid, 15); } else { @shell_exec('kill ' . $pid); }
        }
        _tickRrmdir($dir);
    }
}

it('M3: the stale-tick comment is refused by the projects.json gate, not merely lost to an unreachable API', function () {
    // Mutant: removing requireLocalProject()'s equivalent from the
    // stale-tick POST. The previous test asserted only "could not post the
    // stale-tick warning comment", which an unreachable API produces just
    // as well — so deleting the gate changed nothing. With a real fixture
    // server answering GET /tasks/55, the two are distinguishable: the
    // refusal must name the allow-list.
    _tickFixtureServer([
        'GET /api/v1/tasks/55' => ['status' => 200, 'body' => json_encode(['id' => 55, 'project_id' => 999])],
    ], function (int $port) {
        $repo = _tickGitRepo();
        $home = sys_get_temp_dir() . '/otack-tick-test-' . bin2hex(random_bytes(4));
        mkdir($home . '/.claude/otack-agent', 0700, true);
        register_shutdown_function(fn() => _tickRrmdir($home));
        file_put_contents($home . '/.claude/otack-agent/.env',
            "OTACK_API_URL=http://127.0.0.1:$port/api/v1\nOTACK_API_TOKEN=otk_test\n");
        chmod($home . '/.claude/otack-agent/.env', 0600);
        // Project 1 is allow-listed; the task the stale lock names lives in
        // project 999, which is not.
        _tickWriteProjects($home, ['1' => ['path' => $repo['repo'], 'write' => true, 'default_branch' => 'main']]);

        $staleStart = time() - (3 * 3600 + 600);
        [$hold] = _tick($home, '--selftest=hold:3 --selftest-task-id=55 --selftest-started-at=' . $staleStart);
        $p = proc_open($hold, [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        usleep(500_000);

        [$blocked] = _tick($home, '');
        exec($blocked . ' 2>&1', $out, $code);
        $text = implode("\n", $out);

        assert_eq(TICK_EXIT_BLOCKED, $code, $text);
        assert_true(
            str_contains($text, 'is not listed in ~/.claude/otack-agent/projects.json'),
            'the refusal must come from the allow-list gate, not from a network failure: ' . $text
        );

        fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    });
});

// ---------------------------------------------------------------------
// otack-manager skills, Task 1: the bot's CLI, the owner's token, the
// renamed skill, and the git version the hooks depend on.
// ---------------------------------------------------------------------

it('print-cmd: the child may run the installed agent CLI and nothing else of ours', function () {
    $home = _tickHomeWithRepo(_tickGitRepo());
    $cmd = _tickPrintCmd($home);
    $bin = $home . '/.claude/otack-agent/bin/otack';
    assert_true(in_array("Bash($bin:*)", $cmd['allowed_tools'], true), json_encode($cmd['allowed_tools']));
    assert_true(in_array("Bash({$home}/.claude/otack-agent/bin/otack-manager:*)", $cmd['disallowed_tools'], true));
});

it('print-cmd: the child may not read either token file', function () {
    $home = _tickHomeWithRepo(_tickGitRepo());
    $cmd = _tickPrintCmd($home);
    assert_true(in_array("Read(/{$home}/.claude/otack-agent/.env)", $cmd['disallowed_tools'], true));
    assert_true(in_array("Read(/{$home}/.claude/otack-manager/**)", $cmd['disallowed_tools'], true));
});

it('print-cmd: the prompt names the renamed skill', function () {
    $home = _tickHomeWithRepo(_tickGitRepo());
    $cmd = _tickPrintCmd($home);
    assert_true(str_contains($cmd['cmd'], 'otack-manager-agent skill'), $cmd['cmd']);
});

it('tick refuses to start without the installed agent wrapper', function () {
    $home = _tickHomeWithRepo(_tickGitRepo());
    unlink($home . '/.claude/otack-agent/bin/otack');
    $r = _tickRunSelftest($home, 'print-cmd');
    assert_eq(1, $r['code']);
    assert_true(str_contains($r['err'], 'make install-agent'), $r['err']);
});

it('tick refuses to start without the bot skill in claude-home', function () {
    $home = _tickHomeWithRepo(_tickGitRepo());
    unlink($home . '/.claude/otack-agent/claude-home/skills/otack-manager-agent/SKILL.md');
    $r = _tickRunSelftest($home, 'print-cmd');
    assert_eq(1, $r['code']);
    assert_true(str_contains($r['err'], 'otack-manager-agent'), $r['err']);
});

it('tick refuses to start with a git older than 2.31 (it ignores GIT_CONFIG_COUNT, so the hooks would be skipped)', function () {
    // Found in Task 0: git 2.28 silently ignored the injected core.hooksPath
    // and a push to main went through.
    $home = _tickHomeWithRepo(_tickGitRepo());
    $fakeDir = sys_get_temp_dir() . '/otack-tick-old-git-' . bin2hex(random_bytes(4));
    mkdir($fakeDir, 0700, true);
    register_shutdown_function(fn() => _tickRrmdir($fakeDir));
    file_put_contents($fakeDir . '/git',
        "#!/bin/sh\nif [ \"\$1\" = --version ]; then echo 'git version 2.28.0'; exit 0; fi\nexec /usr/bin/git \"\$@\"\n");
    chmod($fakeDir . '/git', 0755);
    $r = _tickRunSelftest($home, 'print-cmd', ['PATH' => $fakeDir . ':' . _tickDefaultStubBin() . ':' . getenv('PATH')]);
    assert_eq(1, $r['code'], $r['out'] . $r['err']);
    assert_true(str_contains($r['err'], 'older than 2.31'), $r['err']);
});

// ---------------------------------------------------------------------
// Task 1 review, fix round 1: the token files are denied to `Read`, but the
// allow-listed git verbs could read them anyway — `diff --no-index` prints
// any two files, and `commit -F/-t/--pathspec-from-file` loads one into a
// commit (then push) or into an error message. The gate refuses those
// spellings unless the file is inside the repository.
// ---------------------------------------------------------------------

/** A repo + policy + a canary "token file" outside the repository. */
function _tickGateFileFixture(): array {
    $repo = _tickGitRepo();
    $home = _tickHomeWithRepo($repo);
    $policy = _tickPrintCmd($home)['git_gate']['policy_path'];
    $secret = dirname($repo['repo']) . '/secret.env';
    file_put_contents($secret, "OTACK_API_TOKEN=otk_canary_do_not_leak\n");
    return [$repo, $policy, $secret];
}

it('fix1: the gate refuses every git spelling that reads a file outside the repository', function () {
    [$repo, $policy, $secret] = _tickGateFileFixture();
    $s = escapeshellarg($secret);
    $r = escapeshellarg($repo['repo']);
    $forms = [
        "-C $r diff --no-index /dev/null $s",
        "-C $r diff --no-in /dev/null $s",
        "-C $r log --no-index /dev/null $s",
        "-C $r show --no-index /dev/null $s",
        "-C $r commit -F $s",
        "-C $r commit -F$s",
        "-C $r commit -aF $s",
        "-C $r commit --file=$s",
        "-C $r commit --file $s",
        "-C $r commit --fil=$s",
        "-C $r commit -t $s",
        "-C $r commit -t$s",
        "-C $r commit --template=$s",
        "-C $r commit --template $s",
        "-C $r commit --pathspec-from-file=$s",
        "-C $r add --pathspec-from-file=$s",
        "-C $r add --pathspec-from-file $s",
        "-C $r merge -F $s otack/task-1",
    ];
    foreach ($forms as $args) {
        $res = _tickGitGate($policy, $repo['repo'], $args);
        assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], "should have been refused: git $args\n" . $res['out']);
        assert_true(!str_contains($res['out'], 'otk_canary'), "the file leaked: git $args\n" . $res['out']);
    }
    // A symlink inside the repository that points outside it is still outside.
    symlink($secret, $repo['repo'] . '/link.env');
    $res = _tickGitGate($policy, $repo['repo'], "-C $r commit -F " . escapeshellarg($repo['repo'] . '/link.env'));
    assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], $res['out']);
    unlink($repo['repo'] . '/link.env');
    // Outside any work tree, `git diff a b` is an implicit --no-index.
    $res = _tickGitGate($policy, dirname($repo['repo']), "diff /dev/null $s");
    assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], $res['out']);
    assert_true(!str_contains($res['out'], 'otk_canary'), $res['out']);
});

it('fix1: -m clusters still work; commit -F inside the repository and plain diff are refused since round 4b', function () {
    [$repo, $policy] = _tickGateFileFixture();
    $r = escapeshellarg($repo['repo']);
    file_put_contents($repo['repo'] . '/a.txt', "changed\n");
    file_put_contents($repo['repo'] . '/msg.txt', "commit from a file\n");
    $res = _tickGitGate($policy, $repo['repo'], "-C $r diff");
    assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], $res['out']);
    $res = _tickGitGate($policy, $repo['repo'], "-C $r commit -a -F msg.txt");
    assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], $res['out']);
    // `-am<msg>`: the "t" inside the message is not a -t option.
    file_put_contents($repo['repo'] . '/a.txt', "changed again\n");
    $res = _tickGitGate($policy, $repo['repo'], "-C $r commit -amfix-the-tests");
    assert_eq(0, $res['code'], $res['out']);
    assert_eq('fix-the-tests', trim((string)shell_exec('git -C ' . $r . ' log -1 --format=%s')));
});

it('fix1: print-cmd denies the file-reading git shapes in the -C spelling', function () {
    $repo = _tickGitRepo();
    $cmd = _tickPrintCmd(_tickHomeWithRepo($repo));
    foreach (['diff', 'log', 'show', 'commit -F', 'commit --file',
              'commit -t', 'commit --template', 'commit --pathspec-from-file', 'add --pathspec-from-file'] as $shape) {
        $rule = 'Bash(git -C ' . $repo['repo'] . ' ' . $shape . ':*)';
        assert_true(in_array($rule, $cmd['disallowed_tools'], true), "missing deny $rule");
    }
});

// ---------------------------------------------------------------------
// Task 1 review, fix round 2: options that make git run a command of the
// caller's choosing (upload-pack/receive-pack/exec, config overrides) and
// options that write a file of the caller's choosing (--output, -o).
// ---------------------------------------------------------------------

it('fix2: the gate refuses every option that makes git run a command of the caller\'s choosing', function () {
    [$repo, $policy] = _tickGateFileFixture();
    $r = escapeshellarg($repo['repo']);
    $marker = dirname($repo['repo']) . '/pwned';
    $c = escapeshellarg('touch ' . $marker . ';git-upload-pack');
    $rp = escapeshellarg('touch ' . $marker . ';git-receive-pack');
    $remote = escapeshellarg($repo['remote']);
    $dst = escapeshellarg(dirname($repo['repo']) . '/clone-dst');
    $forms = [
        "-C $r fetch --upload-pack=$c origin",
        "-C $r fetch --upload-pack $c origin",
        "-C $r fetch --upl=$c origin",
        "-C $r pull --upload-pack=$c origin otack/task-1",
        "-C $r push --receive-pack=$rp origin otack/task-1",
        "-C $r push --receive-pack $rp origin otack/task-1",
        "-C $r push --exec=$rp origin otack/task-1",
        "-C $r push --exec $rp origin otack/task-1",
        "-C $r ls-remote --upload-pack=$c origin",
        "-C $r ls-remote -u $c origin",
        "-C $r clone -u $c $remote $dst",
        "-C $r clone --upload-pack=$c $remote $dst",
        "-C $r clone -c core.sshCommand=x $remote $dst",
        "-C $r clone --config=core.sshCommand=x $remote $dst",
        "-C $r archive --exec=$c --remote=$remote HEAD",
        "-C $r -c core.sshCommand=x fetch origin",
        "-C $r --config-env=core.sshCommand=X fetch origin",
        "--upload-pack=$c -C $r fetch origin",
    ];
    foreach ($forms as $args) {
        $res = _tickGitGate($policy, $repo['repo'], $args);
        assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], "should have been refused: git $args\n" . $res['out']);
        assert_true(!file_exists($marker), "the command ran: git $args");
    }
});

it('fix2: the gate refuses --output / -o that writes outside the repository or into .git', function () {
    [$repo, $policy] = _tickGateFileFixture();
    $r = escapeshellarg($repo['repo']);
    $out = dirname($repo['repo']) . '/written-outside';
    $o = escapeshellarg($out);
    $forms = [
        "-C $r diff --output=$o HEAD~1",
        "-C $r diff --output $o HEAD~1",
        "-C $r log --output=$o",
        "-C $r show --outp=$o",
        "-C $r format-patch -o $o HEAD~1",
        "-C $r format-patch -o$o HEAD~1",
        "-C $r format-patch --output-directory=$o HEAD~1",
        "-C $r format-patch --output=$o HEAD~1",
        "-C $r archive --output=$o HEAD",
        "-C $r archive -o $o HEAD",
        "-C $r diff --output=.git/config HEAD~1",
    ];
    $configBefore = file_get_contents($repo['repo'] . '/.git/config');
    foreach ($forms as $args) {
        $res = _tickGitGate($policy, $repo['repo'], $args);
        assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], "should have been refused: git $args\n" . $res['out']);
        assert_true(!file_exists($out), "the file was written: git $args");
    }
    assert_eq($configBefore, file_get_contents($repo['repo'] . '/.git/config'), '.git/config must be untouched');
});

it('fix2: push -u, fetch and pull still work', function () {
    [$repo, $policy] = _tickGateFileFixture();
    $r = escapeshellarg($repo['repo']);
    $res = _tickGitGate($policy, $repo['repo'], "-C $r push -u origin otack/task-1");
    assert_eq(0, $res['code'], $res['out']);
    assert_true(trim((string)shell_exec('git --git-dir=' . escapeshellarg($repo['remote']) . ' rev-parse --verify -q refs/heads/otack/task-1')) !== '',
        'the task branch must have landed on the remote');
    $res = _tickGitGate($policy, $repo['repo'], "-C $r fetch origin");
    assert_eq(0, $res['code'], $res['out']);
    $res = _tickGitGate($policy, $repo['repo'], "-C $r pull");
    assert_eq(0, $res['code'], $res['out']);
    // Fix round 4b: `diff` is refused outright, --output inside the repo included.
    $res = _tickGitGate($policy, $repo['repo'], "-C $r diff --output=out.diff HEAD~1");
    assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], $res['out']);
    assert_true(!file_exists($repo['repo'] . '/out.diff'), 'nothing may be written');
});

it('fix2: print-cmd denies the command-running and file-writing git shapes in the -C spelling', function () {
    $repo = _tickGitRepo();
    $cmd = _tickPrintCmd(_tickHomeWithRepo($repo));
    foreach (['fetch --upload-pack', 'pull --upload-pack', 'push --receive-pack', 'push --exec'] as $shape) {
        $rule = 'Bash(git -C ' . $repo['repo'] . ' ' . $shape . ':*)';
        assert_true(in_array($rule, $cmd['disallowed_tools'], true), "missing deny $rule");
    }
});

it('fix3: print-cmd denies Edit and Write inside each write-enabled project\'s .git', function () {
    // .git/config can carry core.fsmonitor / core.sshCommand — a command
    // git runs on the next invocation, past the gate.
    $repo = _tickGitRepo();
    $cmd = _tickPrintCmd(_tickHomeWithRepo($repo));
    foreach (['Edit', 'Write'] as $tool) {
        // Fix round 4b: in every letter case (APFS is case-insensitive).
        foreach (['.git', '.GIT', '.Git', '.gIT'] as $name) {
            $rule = $tool . '(/' . $repo['repo'] . '/' . $name . '/**)';
            assert_true(in_array($rule, $cmd['disallowed_tools'], true), "missing deny $rule");
        }
    }
});

// ---------------------------------------------------------------------
// Fix round 4b: the gate is narrowed — no diff/log/show at all, and no
// file-naming option on any allow-listed verb. These tests pin only that
// the argv is refused; nothing is read, written or executed.
// ---------------------------------------------------------------------

/** A repo + its policy file, nothing else. */
function _tickGate4bFixture(): array {
    $repo = _tickGitRepo();
    $policy = _tickPrintCmd(_tickHomeWithRepo($repo))['git_gate']['policy_path'];
    return [$repo, $policy, escapeshellarg($repo['repo'])];
}

it('fix4b: diff, log and show are refused in any form', function () {
    [$repo, $policy, $r] = _tickGate4bFixture();
    foreach (['diff', 'diff HEAD~1', 'diff --stat', 'log', 'log --oneline -1', 'show', 'show HEAD'] as $args) {
        $res = _tickGitGate($policy, $repo['repo'], "-C $r $args");
        assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], "should have been refused: git $args\n" . $res['out']);
        assert_true(str_contains($res['out'], 'REFUSED'), $res['out']);
    }
    // Outside every repository too.
    $res = _tickGitGate($policy, _tickTempProjectDir(), 'log');
    assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], $res['out']);
});

it('fix4b: file-naming options are refused for every allow-listed verb, in every spelling', function () {
    [$repo, $policy, $r] = _tickGate4bFixture();
    $forms = [
        'commit -F x', 'commit -Fx', 'commit -aF x', 'commit -F -', 'commit --file=x', 'commit --file x',
        'commit --fil=x', 'commit --file=-', 'commit -t x', 'commit -at x', 'commit --template=x',
        'commit --templ x', 'commit -o a.txt -m x', 'commit --pathspec-from-file=x',
        'add --pathspec-from-file=x', 'add --pathspec-from-file -', 'add --pathspec x',
        'checkout -t origin/main', 'checkout -bfoo -t origin/main', 'checkout --pathspec-from-file=x',
        'switch -t origin/main', 'branch --list -t', 'status --output=x', 'status -O x',
        'fetch -t origin', 'fetch -o x origin', 'pull -t', 'pull --output x',
        'push -o x origin otack/task-1', 'push --output-directory=x', 'push --output-dir x',
        'rev-parse -O x', 'remote -o x', 'remote --file=x',
    ];
    foreach ($forms as $args) {
        $res = _tickGitGate($policy, $repo['repo'], "-C $r $args");
        assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], "should have been refused: git $args\n" . $res['out']);
        assert_true(str_contains($res['out'], 'REFUSED'), $res['out']);
    }
});

it('fix4b: an allowed command still passes, and a value-taking cluster is not misread', function () {
    [$repo, $policy, $r] = _tickGate4bFixture();
    $res = _tickGitGate($policy, $repo['repo'], "-C $r status --short");
    assert_eq(0, $res['code'], $res['out']);
    // `-mFix-the-tests`: F and t live in the message, not in the options.
    $res = _tickGitGate($policy, $repo['repo'], "-C $r commit --allow-empty -mFix-the-tests");
    assert_eq(0, $res['code'], $res['out']);
});

it('fix4b: a write into .git is refused whatever its letter case', function () {
    [$repo, $policy, $r] = _tickGate4bFixture();
    foreach (['.git/x.patch', '.GIT/x.patch', '.Git/x.patch'] as $target) {
        $res = _tickGitGate($policy, $repo['repo'], "-C $r format-patch -o " . escapeshellarg($target) . ' HEAD~1');
        assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], "should have been refused: format-patch -o $target\n" . $res['out']);
    }
});

// ---------------------------------------------------------------------
// Fix round 5: fetch/pull read only from origin, and push --repo is
// refused in the shim. Refusal-only: the refused argv never reaches git, so
// nothing is contacted or read.
// ---------------------------------------------------------------------

it('fix5: fetch and pull refuse any source but origin', function () {
    [$repo, $policy, $r] = _tickGate4bFixture();
    $forms = [
        'fetch https://example.invalid/x.git', 'fetch git@example.invalid:x.git',
        'fetch ../other', 'fetch /tmp/other.git', 'fetch file:///tmp/other.git', 'fetch upstream',
        'fetch --all', 'fetch --multiple origin upstream', 'fetch --repo=https://example.invalid/x.git',
        'fetch --depth 1 origin',
        'pull https://example.invalid/x.git main', 'pull ../other main', 'pull --all', 'pull --repo=x',
    ];
    foreach ($forms as $args) {
        $res = _tickGitGate($policy, $repo['repo'], "-C $r $args");
        assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], "should have been refused: git $args\n" . $res['out']);
        assert_true(str_contains($res['out'], 'REFUSED'), $res['out']);
    }
    // Outside every policy repository the name check still holds.
    $res = _tickGitGate($policy, _tickTempProjectDir(), 'fetch https://example.invalid/x.git');
    assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], $res['out']);
});

it('fix5: push --repo is refused in the shim, in every spelling', function () {
    [$repo, $policy, $r] = _tickGate4bFixture();
    foreach (['push --repo=x', 'push --repo x', 'push --rep=x origin otack/task-1'] as $args) {
        $res = _tickGitGate($policy, $repo['repo'], "-C $r $args");
        assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], "should have been refused: git $args\n" . $res['out']);
        assert_true(str_contains($res['out'], 'REFUSED'), $res['out']);
    }
});

it('fix5: fetch/pull from origin still pass', function () {
    [$repo, $policy, $r] = _tickGate4bFixture();
    foreach (['fetch', 'fetch origin', 'fetch origin main', 'fetch --depth=1 origin main', 'pull origin main'] as $args) {
        $res = _tickGitGate($policy, $repo['repo'], "-C $r $args");
        assert_eq(0, $res['code'], "should have passed: git $args\n" . $res['out']);
    }
});

it('fix5: fetch and pull refuse refspecs with a colon or a leading +', function () {
    [$repo, $policy, $r] = _tickGate4bFixture();
    foreach ([
        'fetch origin main:main', 'fetch origin +main:main', 'fetch origin +main', 'fetch origin :x',
        'fetch origin refs/heads/x:refs/heads/main', 'pull origin main:main', 'pull origin +main:x',
    ] as $args) {
        $res = _tickGitGate($policy, $repo['repo'], "-C $r $args");
        assert_eq(TICK_EXIT_GIT_REFUSED, $res['code'], "should have been refused: git $args\n" . $res['out']);
        assert_true(str_contains($res['out'], 'REFUSED'), $res['out']);
    }
});

// ---------------------------------------------------------------------
// otack-manager skills, Task 10: the Telegram approval pass in the tick.
// ---------------------------------------------------------------------
require_once __DIR__ . '/agent_fixture_server.php';

const _TICK_TG = '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ';

/** A print-cmd-ready tick home in approval.mode=telegram, API pointed at the fixture server on $port. */
function _tickTgHome(int $port): string {
    $home = _tickHomeWithRepo(_tickGitRepo());
    register_shutdown_function(fn() => _tickRrmdir($home));
    file_put_contents($home . '/.claude/otack-agent/.env',
        "OTACK_API_URL=http://127.0.0.1:$port/api/v1\nOTACK_API_TOKEN=otk_test\n"
        . 'OTACK_TG_BOT_TOKEN=' . _TICK_TG . "\nOTACK_TG_CHAT_ID=42\n");
    chmod($home . '/.claude/otack-agent/.env', 0600);
    $p = json_decode((string)file_get_contents($home . '/.claude/otack-agent/projects.json'), true);
    $p['approval'] = ['mode' => 'telegram'];
    file_put_contents($home . '/.claude/otack-agent/projects.json', json_encode($p));
    return $home;
}

function _tickTgRoutes(bool $withTelegram): array {
    $routes = [
        'GET /api/v1/me'                => [['status' => 200, 'body' => json_encode(['id' => 7])]],
        'GET /api/v1/projects/1'        => [['status' => 200, 'body' => json_encode(['id' => 1, 'name' => 'Repo', 'columns' => [['id' => 2]]])]],
        'GET /api/v1/projects/1/tags'   => [['status' => 200, 'body' => json_encode(['items' => []])]],
        'GET /api/v1/projects/1/tasks'  => [['status' => 200, 'body' => json_encode(['items' => [
            ['id' => 31, 'project_id' => 1, 'column_id' => 2, 'priority' => 'high', 'assignee_id' => null, 'agent_state' => null, 'title' => 'Do it'],
        ], 'next_cursor' => null])]],
    ];
    if ($withTelegram) {
        $routes['POST /bot' . _TICK_TG . '/getUpdates']  = [['status' => 200, 'body' => json_encode(['ok' => true, 'result' => []])]];
        $routes['POST /bot' . _TICK_TG . '/sendMessage'] = [['status' => 200, 'body' => json_encode(['ok' => true, 'result' => ['message_id' => 9]])]];
    }
    return $routes;
}

it('telegram mode: the tick runs the approval pass and sends one digest', function () {
    _cliFixtureServer(_tickTgRoutes(true), function (int $port, string $log) {
        $home = _tickTgHome($port);
        $r = _tickRunSelftest($home, 'print-cmd', ['OTACK_TG_API_BASE' => "http://127.0.0.1:$port"]);
        assert_eq(TICK_EXIT_OK, $r['code'], $r['err']);
        $sends = array_filter(_requestLog($log), fn($e) => str_ends_with($e['path'], '/sendMessage'));
        assert_eq(1, count($sends), $r['err']);
        $sel = $home . '/.claude/otack-agent/selection.json';
        assert_true(is_file($sel));
        assert_eq('600', sprintf('%o', fileperms($sel) & 0777));
        assert_true(!file_exists($home . '/.claude/otack-agent/approval-closed'), 'a good pass leaves the gate open');
        assert_true(!str_contains($r['err'] . $r['out'], _TICK_TG), 'the Telegram token must never be printed');
    });
});

it('telegram mode: a Telegram failure is logged, the tick goes on, and the gate is closed for this tick', function () {
    _cliFixtureServer(_tickTgRoutes(false), function (int $port) {
        $home = _tickTgHome($port);
        $r = _tickRunSelftest($home, 'print-cmd', ['OTACK_TG_API_BASE' => "http://127.0.0.1:$port"]);
        assert_eq(TICK_EXIT_OK, $r['code'], $r['err']);
        assert_true(str_contains($r['err'], 'telegram selection skipped'), $r['err']);
        assert_true(file_exists($home . '/.claude/otack-agent/approval-closed'), 'a failed pass must close the gate for `next`');
        assert_true(!str_contains($r['err'] . $r['out'], _TICK_TG), 'the Telegram token must never be printed');
    });
});
