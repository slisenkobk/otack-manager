#!/usr/bin/env php
<?php
declare(strict_types=1);

// Installs the agent bridge into ~/.claude, OUTSIDE the repository working
// tree, so switching branches in otack-tasks never breaks a running bot or
// an interactive session:
//
//   ~/.claude/otack-agent/app/{bin,lib}        copy of agent/bin + agent/lib
//   ~/.claude/otack-agent/bin/otack            wrapper, OTACK_SCOPE=agent   (the bot's CLI)
//   ~/.claude/otack-agent/bin/otack-manager    wrapper, OTACK_SCOPE=manager (owner's token)
//   ~/.claude/otack-agent/bin/otack-tick       wrapper for cron
//   ~/.claude/skills/otack-manager/            interactive skill, global
//   ~/.claude/otack-agent/claude-home/skills/otack-manager-agent/
//                                              bot skill, visible only to the tick's
//                                              isolated CLAUDE_CONFIG_DIR
//
// The scope is exported by the wrapper itself, so a caller cannot pick it:
// the bot may run only the `otack` wrapper (otack-tick's allow-list), and
// the manager wrapper is denied to it outright.
//
// Usage: php agent/bin/install.php <php-binary> <repo-root>   (HOME from env)

ini_set('display_errors', 'stderr');

$php  = $argv[1] ?? null;
$repo = $argv[2] ?? null;
$home = rtrim((string)getenv('HOME'), '/');
if ($home === '' || $php === null || $repo === null) {
    fwrite(STDERR, "usage: install.php <php-binary> <repo-root>\n");
    exit(2);
}
$php  = realpath($php);
$repo = realpath($repo);
if ($php === false || !is_executable($php)) {
    fwrite(STDERR, "php binary is not executable: {$argv[1]}\n");
    exit(1);
}
if ($repo === false || !is_file("$repo/agent/bin/otack")) {
    fwrite(STDERR, "not an otack-tasks checkout: {$argv[2]}\n");
    exit(1);
}

$agentHome   = "$home/.claude/otack-agent";
$managerHome = "$home/.claude/otack-manager";
$app         = "$agentHome/app";
$bin         = "$agentHome/bin";
$claudeHome  = "$agentHome/claude-home";

function rrmdir(string $p): void {
    if (is_link($p) || is_file($p)) { unlink($p); return; }
    if (!is_dir($p)) { return; }
    foreach (scandir($p) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        rrmdir("$p/$e");
    }
    rmdir($p);
}

/** Copies a tree; *.md files get the placeholder map applied; $skip names top-level entries to leave out. */
function copyTree(string $src, string $dst, array $render, array $skip = []): void {
    if (!is_dir($dst) && !mkdir($dst, 0755, true)) {
        throw new RuntimeException("cannot create $dst");
    }
    foreach (scandir($src) ?: [] as $e) {
        if ($e === '.' || $e === '..' || $e === '.DS_Store' || in_array($e, $skip, true)) { continue; }
        $from = "$src/$e";
        $to   = "$dst/$e";
        if (is_dir($from)) { copyTree($from, $to, $render); continue; }
        $data = file_get_contents($from);
        if ($data === false) { throw new RuntimeException("cannot read $from"); }
        if (str_ends_with($e, '.md')) { $data = strtr($data, $render); }
        if (file_put_contents($to, $data) === false) { throw new RuntimeException("cannot write $to"); }
        chmod($to, is_executable($from) ? 0755 : 0644);
    }
}

function writeFile(string $path, string $data, int $mode): void {
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $data) === false) { throw new RuntimeException("cannot write $tmp"); }
    chmod($tmp, $mode);
    if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException("cannot replace $path"); }
}

try {
    foreach ([$agentHome, $managerHome, $claudeHome] as $d) {
        if (!is_dir($d) && !mkdir($d, 0700, true)) { throw new RuntimeException("cannot create $d"); }
        chmod($d, 0700);
    }
    foreach ([$bin, "$home/.claude/skills", "$claudeHome/skills"] as $d) {
        if (!is_dir($d) && !mkdir($d, 0755, true)) { throw new RuntimeException("cannot create $d"); }
    }

    // Runtime: a fresh copy every time — never a symlink into the checkout.
    rrmdir($app);
    // The installer itself is not runtime, and its placeholder map would
    // otherwise be the one "{{OTACK" left in the installed tree.
    copyTree("$repo/agent/bin", "$app/bin", [], ['install.php']);
    copyTree("$repo/agent/lib", "$app/lib", []);

    $wrapper = function (string $target, ?string $scope) use ($php, $app): string {
        return "#!/bin/sh\n"
            . ($scope !== null ? "OTACK_SCOPE=$scope\nexport OTACK_SCOPE\n" : '')
            . 'exec ' . escapeshellarg($php) . ' ' . escapeshellarg("$app/bin/$target") . " \"\$@\"\n";
    };
    writeFile("$bin/otack", $wrapper('otack', 'agent'), 0755);
    writeFile("$bin/otack-manager", $wrapper('otack', 'manager'), 0755);
    writeFile("$bin/otack-tick", $wrapper('otack-tick', null), 0755);

    $render = ['{{OTACK}}' => "$bin/otack", '{{OTACK_MANAGER}}' => "$bin/otack-manager"];
    rrmdir("$home/.claude/skills/otack-manager");
    copyTree("$repo/agent/skills/otack-manager", "$home/.claude/skills/otack-manager", $render);
    rrmdir("$claudeHome/skills/otack-manager-agent");
    copyTree("$repo/agent/skills/otack-manager-agent", "$claudeHome/skills/otack-manager-agent", $render);

    // Legacy: the pre-rename skill was a symlink into the checkout.
    if (is_link("$home/.claude/skills/otack-agent") || file_exists("$home/.claude/skills/otack-agent")) {
        rrmdir("$home/.claude/skills/otack-agent");
    }

    $url = "OTACK_API_URL=https://otack-manager.neoweblab.eu/api/v1\n";
    if (!is_file("$agentHome/.env")) {
        writeFile("$agentHome/.env",
            $url . "OTACK_API_TOKEN=\n"
            . "# Telegram selection (approval.mode = \"telegram\" in projects.json):\n"
            . "# OTACK_TG_BOT_TOKEN=\n# OTACK_TG_CHAT_ID=\n", 0600);
        echo "Created $agentHome/.env — put the BOT user's token in it\n";
    }
    if (!is_file("$managerHome/.env")) {
        writeFile("$managerHome/.env", $url . "OTACK_API_TOKEN=\n", 0600);
        echo "Created $managerHome/.env — put YOUR personal token in it\n";
    }
    if (!is_file("$agentHome/projects.json")) {
        writeFile("$agentHome/projects.json", (string)file_get_contents("$repo/agent/projects.example.json"), 0600);
        echo "Created $agentHome/projects.json from the example — replace the example projects\n";
    }

    $commit = trim((string)shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse --short HEAD 2>/dev/null'));
    writeFile("$app/INSTALLED.json", json_encode([
        'source' => $repo, 'commit' => $commit, 'installed_at' => gmdate('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0644);

    echo "Installed ($commit).\n"
        . "  interactive CLI: $bin/otack-manager whoami\n"
        . "  bot CLI:         $bin/otack whoami\n"
        . "  cron:            */30 * * * * $bin/otack-tick >> $agentHome/tick.log 2>&1\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
