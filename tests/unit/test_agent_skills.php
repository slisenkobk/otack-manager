<?php
// Every CLI command a skill tells the model to run must exist in the CLI's
// usage text — a renamed or removed command must break this test, not a
// live session.
$__root = dirname(__DIR__, 2);
if (!is_file("$__root/agent/skills/otack-manager/references/setup-project.md")) {
    it('otack-manager skill references exist', fn() => assert_true(false, 'not implemented yet'));
    return;
}

function _skillText(string $dir): string {
    $text = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (str_ends_with((string)$f, '.md')) { $text .= file_get_contents((string)$f) . "\n"; }
    }
    return $text;
}

function _cliUsage(): string {
    $p = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/agent/bin/otack', '--help'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($p);
    return $err;
}

it('otack-manager skill: frontmatter name and trigger words', function () use ($__root) {
    $s = file_get_contents("$__root/agent/skills/otack-manager/SKILL.md");
    assert_true(str_starts_with($s, "---\nname: otack-manager\n"), 'frontmatter name');
    foreach (['otack-manager.neoweblab.eu', 'todo', 'project'] as $w) { assert_true(str_contains($s, $w), "description/body mentions $w"); }
});

it('every command the skills name exists in the CLI usage', function () use ($__root) {
    $usage = _cliUsage();
    foreach (['otack-manager' => '{{OTACK_MANAGER}}', 'otack-manager-agent' => '{{OTACK}}'] as $skill => $ph) {
        preg_match_all('/' . preg_quote($ph, '/') . ' ([a-z-]+)/', _skillText("$__root/agent/skills/$skill"), $m);
        assert_true($m[1] !== [], "$skill names no commands");
        foreach (array_unique($m[1]) as $cmd) {
            assert_true((bool)preg_match('/^\s+' . preg_quote($cmd, '/') . '\b/m', $usage), "$skill uses unknown command \"$cmd\"");
        }
    }
});

it('the agent skill never mentions the manager CLI, and vice versa', function () use ($__root) {
    assert_true(!str_contains(_skillText("$__root/agent/skills/otack-manager-agent"), '{{OTACK_MANAGER}}'));
    assert_true(!preg_match('/\{\{OTACK\}\}/', _skillText("$__root/agent/skills/otack-manager")));
});
