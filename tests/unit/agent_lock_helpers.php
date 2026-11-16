<?php
// Shared helpers for test_agent_lock.php (split out to keep that file from growing).
// Not named test_*.php, so tests/run.php does not pick it up on its own.
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

/**
 * Blocks until a holder process really owns the lock and has written its
 * payload (flock taken AND file non-empty), or fails after $timeoutMs. A fixed
 * usleep() after proc_open() raced against PHP start-up time and flaked
 * whenever the machine was busy.
 */
function _tickWaitHeld(string $path, int $timeoutMs = 15000): void {
    $deadline = microtime(true) + $timeoutMs / 1000;
    while (microtime(true) < $deadline) {
        if (is_file($path) && filesize($path) > 0 && !_tickFlockIsFree($path)) { return; }
        usleep(20_000);
    }
    throw new RuntimeException('the lock holder never took the lock within ' . $timeoutMs . ' ms');
}

/** Decodes print-cmd's JSON stdout, failing the test with the raw output on parse failure. */
function _tickDecodePrintCmd(string $raw): array {
    $decoded = json_decode(trim($raw), true);
    assert_true(is_array($decoded), 'print-cmd output was not valid JSON: ' . $raw);
    return $decoded;
}
