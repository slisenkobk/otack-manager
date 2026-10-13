<?php
// Drives agent/bin/install.php against a throwaway HOME. See
// test_agent_config.php for why the file opens with an existence guard.
$__installer = dirname(__DIR__, 2) . '/agent/bin/install.php';
if (!is_file($__installer)) {
    it('agent/bin/install.php exists', fn() => assert_true(false, 'not implemented yet'));
    return;
}

function _installRun(string $home): array {
    $repo = dirname(__DIR__, 2);
    $cmd = 'HOME=' . escapeshellarg($home) . ' ' . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($repo . '/agent/bin/install.php') . ' '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
}

function _installHome(): string {
    $home = sys_get_temp_dir() . '/otack-install-test-' . bin2hex(random_bytes(4));
    mkdir($home, 0700, true);
    register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($home)));
    return $home;
}

it('install.php lays out runtime, wrappers and both skills outside the repo', function () {
    $home = _installHome();
    $r = _installRun($home);
    assert_eq(0, $r['code'], $r['err']);
    $a = "$home/.claude/otack-agent";
    foreach (['app/bin/otack', 'app/bin/otack-tick', 'app/lib/Config.php', 'app/lib/Client.php', 'app/lib/Triage.php'] as $f) {
        assert_true(is_file("$a/$f"), "missing $f");
    }
    foreach (['otack', 'otack-manager', 'otack-tick'] as $w) {
        assert_true(is_executable("$a/bin/$w"), "wrapper $w not executable");
    }
    assert_true(str_contains(file_get_contents("$a/bin/otack"), 'OTACK_SCOPE=agent'));
    assert_true(str_contains(file_get_contents("$a/bin/otack-manager"), 'OTACK_SCOPE=manager'));
    assert_true(!str_contains(file_get_contents("$a/bin/otack-tick"), 'OTACK_SCOPE'));
    assert_true(is_file("$home/.claude/skills/otack-manager/SKILL.md"));
    assert_true(is_file("$a/claude-home/skills/otack-manager-agent/SKILL.md"));
    assert_true(!file_exists("$home/.claude/skills/otack-manager-agent"), 'bot skill must not be global');
});

it('install.php renders absolute CLI paths into the skills and leaves no placeholder', function () {
    $home = _installHome();
    assert_eq(0, _installRun($home)['code']);
    $agentSkill = file_get_contents("$home/.claude/otack-agent/claude-home/skills/otack-manager-agent/SKILL.md");
    $managerSkill = file_get_contents("$home/.claude/skills/otack-manager/SKILL.md");
    assert_true(str_contains($agentSkill, "$home/.claude/otack-agent/bin/otack "));
    assert_true(str_contains($managerSkill, "$home/.claude/otack-agent/bin/otack-manager"));
    $all = shell_exec('grep -rl "{{OTACK" ' . escapeshellarg("$home/.claude") . ' 2>/dev/null');
    assert_eq('', trim((string)$all), 'placeholder left in: ' . $all);
});

it('install.php removes the legacy otack-agent skill symlink', function () {
    $home = _installHome();
    mkdir("$home/.claude/skills", 0700, true);
    symlink('/nonexistent/agent/skill', "$home/.claude/skills/otack-agent");
    assert_eq(0, _installRun($home)['code']);
    assert_true(!is_link("$home/.claude/skills/otack-agent") && !file_exists("$home/.claude/skills/otack-agent"));
});

it('install.php creates both .env files 0600 and never overwrites a filled one', function () {
    $home = _installHome();
    assert_eq(0, _installRun($home)['code']);
    $agentEnv = "$home/.claude/otack-agent/.env";
    $managerEnv = "$home/.claude/otack-manager/.env";
    assert_eq(0600, fileperms($agentEnv) & 0777);
    assert_eq(0600, fileperms($managerEnv) & 0777);
    assert_eq(0700, fileperms("$home/.claude/otack-manager") & 0777);
    file_put_contents($managerEnv, "OTACK_API_URL=https://x/api/v1\nOTACK_API_TOKEN=otk_keepme\n");
    assert_eq(0, _installRun($home)['code']);
    assert_true(str_contains(file_get_contents($managerEnv), 'otk_keepme'));
});

it('the installed agent wrapper runs the CLI', function () {
    $home = _installHome();
    assert_eq(0, _installRun($home)['code']);
    $p = proc_open('HOME=' . escapeshellarg($home) . ' ' . escapeshellarg("$home/.claude/otack-agent/bin/otack") . ' --help',
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    assert_eq(2, proc_close($p));
    assert_true(str_contains($err, 'otack'), $err);
});
