<?php
// The tick wrapper's contract is about process lifetime, so these tests
// drive real subprocesses. `--selftest=hold:<n>` runs the lock logic and
// then sleeps instead of launching a session, so the test can observe a
// held lock. `--selftest-task-id=<id>` and `--selftest-started-at=<epoch>`
// (test-only, undocumented outside this file) let a test fabricate "a tick
// that has been stuck on task <id> since <epoch>" without literally waiting
// three hours for STALE_AFTER_SECONDS to elapse.
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

function _tickHome(): string {
    $home = sys_get_temp_dir() . '/otack-tick-test-' . bin2hex(random_bytes(4));
    mkdir($home . '/.claude/otack-agent', 0700, true);
    file_put_contents($home . '/.claude/otack-agent/.env',
        "OTACK_API_URL=http://localhost:8771/api/v1\nOTACK_API_TOKEN=otk_test\n");
    chmod($home . '/.claude/otack-agent/.env', 0600);
    return $home;
}

function _tick(string $home, string $args): array {
    $bin = dirname(__DIR__, 2) . '/agent/bin/otack-tick';
    $cmd = 'HOME=' . escapeshellarg($home) . ' ' . PHP_BINARY . ' ' . escapeshellarg($bin) . ' ' . $args;
    return [$cmd];
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

    // A second tick must refuse immediately rather than queue.
    [$cmd2] = _tick($home, '--selftest=hold:1');
    $t0 = microtime(true);
    exec($cmd2 . ' 2>&1', $out2, $code2);
    $elapsed = microtime(true) - $t0;
    assert_eq(0, $code2, 'a blocked tick exits 0 — it is a no-op, not a failure');
    assert_true($elapsed < 1.0, 'a blocked tick must return immediately, took ' . round($elapsed, 2) . 's');
    assert_true(str_contains(implode("\n", $out2), 'already running'), 'should say why it did nothing');

    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
});

it('the lock is released when the holding process dies', function () {
    $home = _tickHome();
    [$cmd] = _tick($home, '--selftest=hold:1');
    exec($cmd . ' 2>&1', $o1, $c1);          // runs and exits
    // A second run must now succeed, proving the kernel released the lock.
    exec($cmd . ' 2>&1', $o2, $c2);
    assert_eq(0, $c2);
    assert_true(!str_contains(implode("\n", $o2), 'already running'), 'lock should be free after the holder exits');
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
    assert_eq(0, $code1);
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
    assert_eq(0, $code2);
    assert_true(str_contains(implode("\n", $out2), 'WARNING'), 'should still warn a human every tick');
    assert_true(
        !str_contains(implode("\n", $out2), 'could not post the stale-tick warning comment'),
        'must not re-attempt the post for a lock generation already marked warned'
    );

    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
});
