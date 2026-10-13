<?php
// tests/run.php requires each test file with a bare top-level `require`
// (not require_once, no try/catch) — a require_once of a file that doesn't
// exist yet is a fatal error there, which aborts the whole suite before the
// ~400 pre-existing tests even run. This guard turns "file missing" into one
// ordinary failing assertion instead, so the red step stays inside a green
// run.
$__impl = dirname(__DIR__, 2) . '/agent/lib/Config.php';
if (!is_file($__impl)) {
    it('agent/lib/Config.php exists', fn() => assert_true(false, 'not implemented yet'));
    return;
}
require_once $__impl;

use App\Agent\Config;

// Recursively removes a temp directory tree. The repo convention for temp
// fixtures is `register_shutdown_function(fn() => @unlink($path))` (see
// test_config_store.php, test_activity_log_prune.php); this is the
// directory-tree equivalent so the six-plus trees this file creates per run
// don't accumulate under sys_get_temp_dir().
function _agentConfigRrmdir(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        _agentConfigRrmdir($path . '/' . $item);
    }
    @rmdir($path);
}

function _agentConfigTempHome(): string {
    $home = sys_get_temp_dir() . '/otack-agent-test-' . bin2hex(random_bytes(4));
    register_shutdown_function(fn() => _agentConfigRrmdir($home));
    return $home;
}

function _agentHome(array $env, ?array $projects, int $envMode = 0600): string {
    $home = _agentConfigTempHome();
    mkdir($home . '/.claude/otack-agent', 0700, true);
    $lines = '';
    foreach ($env as $k => $v) { $lines .= "$k=$v\n"; }
    file_put_contents($home . '/.claude/otack-agent/.env', $lines);
    chmod($home . '/.claude/otack-agent/.env', $envMode);
    if ($projects !== null) {
        file_put_contents($home . '/.claude/otack-agent/projects.json', json_encode($projects));
    }
    return $home;
}

// Like _agentHome but writes a raw string body for projects.json instead of
// json_encode-ing a PHP array, so tests can produce shapes PHP arrays can't
// represent directly (malformed JSON, a bare object without the "projects"
// wrapper key, a top-level JSON array, etc).
function _agentHomeRawProjects(array $env, string $projectsBody, int $envMode = 0600): string {
    $home = _agentConfigTempHome();
    mkdir($home . '/.claude/otack-agent', 0700, true);
    $lines = '';
    foreach ($env as $k => $v) { $lines .= "$k=$v\n"; }
    file_put_contents($home . '/.claude/otack-agent/.env', $lines);
    chmod($home . '/.claude/otack-agent/.env', $envMode);
    file_put_contents($home . '/.claude/otack-agent/projects.json', $projectsBody);
    return $home;
}

const _AGENT_ENV_OK = ['OTACK_API_URL' => 'https://x.example/api/v1', 'OTACK_API_TOKEN' => 'otk_abc'];

function _agentAssertThrows(callable $fn, string $needle, string $message): void {
    $threw = false;
    try { $fn(); } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), $needle);
        if (!$threw) {
            $message .= ' (got: ' . $e->getMessage() . ')';
        }
    }
    assert_true($threw, $message);
}

it('Config::load reads instance, token and projects', function () {
    $home = _agentHome(
        _AGENT_ENV_OK,
        ['instance' => 'ignored', 'projects' => ['42' => ['path' => '/tmp/p42', 'write' => true, 'test_cmd' => 'make test']]]
    );
    mkdir('/tmp/p42');
    $c = Config::load($home);
    assert_eq('https://x.example/api/v1', $c->instance);
    assert_eq('otk_abc', $c->token);
    // M4: projectPath() canonicalises with realpath(), so on systems where
    // /tmp is itself a symlink (e.g. macOS -> /private/tmp) the stored path
    // is the resolved one, not the literal string from projects.json.
    assert_eq(realpath('/tmp/p42'), $c->projectPath(42)['path']);
    assert_eq(true, $c->projectPath(42)['write']);
    assert_eq('make test', $c->projectPath(42)['test_cmd']);
    _agentConfigRrmdir('/tmp/p42');
});

it('Config::projectPath returns null for a project not in the file', function () {
    mkdir('/tmp/p42-b');
    $home = _agentHome(
        _AGENT_ENV_OK,
        ['projects' => ['42' => ['path' => '/tmp/p42-b', 'write' => true]]]
    );
    $c = Config::load($home);
    assert_eq(null, $c->projectPath(99));
    _agentConfigRrmdir('/tmp/p42-b');
});

it('Config::projectPath defaults write to false and test_cmd to null', function () {
    mkdir('/tmp/p51');
    $home = _agentHome(
        _AGENT_ENV_OK,
        ['projects' => ['51' => ['path' => '/tmp/p51']]]
    );
    $c = Config::load($home);
    assert_eq(false, $c->projectPath(51)['write']);
    assert_eq(null, $c->projectPath(51)['test_cmd']);
    _agentConfigRrmdir('/tmp/p51');
});

it('Config::load refuses a token file readable beyond the owner', function () {
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => []], 0644);
    _agentAssertThrows(fn() => Config::load($home), '0600', 'expected a RuntimeException naming 0600');
});

// M5: mode 0644 (world-readable) trips both a `& 0077` check and a
// group-blind `& 0007` check, so it cannot tell them apart. A mutation test
// proved that replacing `& 0077` with `& 0007` in the implementation left
// the suite green. Mode 0640 (group-readable, world has no bits) only trips
// the correct `& 0077` check, so this case fails against a group-blind
// implementation.
it('Config::load refuses a token file readable by the group (mode 0640)', function () {
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => []], 0640);
    _agentAssertThrows(fn() => Config::load($home), '0600', 'expected a RuntimeException naming 0600 for mode 0640');
});

it('Config::load fails with a clear message when the token is missing', function () {
    $home = _agentHome(['OTACK_API_URL' => 'https://x.example/api/v1'], ['projects' => []]);
    _agentAssertThrows(fn() => Config::load($home), 'OTACK_API_TOKEN', 'expected a RuntimeException naming OTACK_API_TOKEN');
});

it('Config::load treats a missing projects.json as "no project is allowed"', function () {
    $home = _agentHome(_AGENT_ENV_OK, null);
    $c = Config::load($home);
    assert_eq(null, $c->projectPath(42));
});

// M6: a missing .env must be a loud, fail-closed error — not an empty
// config that lets the caller believe there is simply no work to do.
it('Config::load throws (does not return an empty config) when .env is entirely missing', function () {
    $home = _agentConfigTempHome();
    mkdir($home . '/.claude/otack-agent', 0700, true);
    // No .env written at all.
    _agentAssertThrows(fn() => Config::load($home), 'Agent config missing', 'expected a RuntimeException for a missing .env, not a silently empty config');
});

// m4: EACCES on .env must be diagnosed as a permission problem, not
// misreported later as "OTACK_API_URL is not set".
it('Config::load reports an unreadable .env as a read failure, not a missing key', function () {
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => []]);
    chmod($home . '/.claude/otack-agent/.env', 0000);
    try {
        _agentAssertThrows(fn() => Config::load($home), 'cannot read', 'expected a RuntimeException naming "cannot read" for an unreadable .env');
    } finally {
        chmod($home . '/.claude/otack-agent/.env', 0600); // let cleanup unlink it
    }
});

// --- C1: write must be a real JSON boolean, never coerced from a string ---

it('Config::load rejects a quoted-string "write" value instead of silently truthy-coercing it', function () {
    mkdir('/tmp/p-c1');
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['7' => ['path' => '/tmp/p-c1', 'write' => 'false']]]);
    _agentAssertThrows(
        fn() => Config::load($home),
        '7',
        'expected a RuntimeException naming project id "7" for a non-boolean "write" value'
    );
    _agentConfigRrmdir('/tmp/p-c1');
});

it('Config::load accepts a real JSON boolean "write": true', function () {
    mkdir('/tmp/p-c1b');
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['8' => ['path' => '/tmp/p-c1b', 'write' => true]]]);
    $c = Config::load($home);
    assert_eq(true, $c->projectPath(8)['write']);
    _agentConfigRrmdir('/tmp/p-c1b');
});

// --- M1: projects.json and its containing directory must not be group/world-writable ---

it('Config::load refuses a group-writable projects.json', function () {
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => []]);
    chmod($home . '/.claude/otack-agent/projects.json', 0664);
    _agentAssertThrows(fn() => Config::load($home), 'writable', 'expected a RuntimeException naming a writable projects.json');
});

it('Config::load refuses a world-writable containing directory', function () {
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => []]);
    chmod($home . '/.claude/otack-agent', 0777);
    try {
        _agentAssertThrows(fn() => Config::load($home), 'writable', 'expected a RuntimeException naming a writable otack-agent directory');
    } finally {
        chmod($home . '/.claude/otack-agent', 0700); // let cleanup traverse it
    }
});

// --- M2: a wrong-shaped projects.json must throw, never silently become "nothing allowed" ---

it('Config::load throws when projects.json exists but has no "projects" key', function () {
    $home = _agentHomeRawProjects(_AGENT_ENV_OK, json_encode(['instance' => 'x']));
    _agentAssertThrows(fn() => Config::load($home), 'projects.example.json', 'expected a RuntimeException pointing at agent/projects.example.json');
});

it('Config::load throws when projects.json is a top-level JSON array', function () {
    $home = _agentHomeRawProjects(_AGENT_ENV_OK, json_encode([1, 2, 3]));
    _agentAssertThrows(fn() => Config::load($home), 'projects.example.json', 'expected a RuntimeException for a top-level array');
});

it('Config::load throws when "projects" is a bare object missing the wrapper (top-level {"42": {...}})', function () {
    $home = _agentHomeRawProjects(_AGENT_ENV_OK, json_encode(['42' => ['path' => '/tmp/x']]));
    _agentAssertThrows(fn() => Config::load($home), 'projects.example.json', 'expected a RuntimeException for a missing "projects" wrapper');
});

it('Config::load throws when "projects" is present but not an array/object', function () {
    $home = _agentHomeRawProjects(_AGENT_ENV_OK, json_encode(['projects' => 'oops']));
    _agentAssertThrows(fn() => Config::load($home), 'projects.example.json', 'expected a RuntimeException when "projects" is not an object');
});

it('Config::load throws when projects.json path exists but is a directory, not a file', function () {
    $home = _agentHome(_AGENT_ENV_OK, null);
    mkdir($home . '/.claude/otack-agent/projects.json');
    _agentAssertThrows(fn() => Config::load($home), 'projects.json', 'expected a RuntimeException when projects.json is a directory');
});

// M6: the "missing projects.json -> nothing allowed" test above is
// indistinguishable from a swallow-everything implementation unless this
// corrupt-file counterpart sits beside it and throws.
it('Config::load throws on a corrupt (invalid JSON) projects.json, never swallows it', function () {
    $home = _agentHomeRawProjects(_AGENT_ENV_OK, '{not valid json');
    _agentAssertThrows(fn() => Config::load($home), 'projects.json', 'expected a RuntimeException for corrupt JSON, not a swallowed error');
});

// --- M3: a malformed project entry must throw, never be dropped silently ---

it('Config::load throws when a project entry is missing "path" (e.g. typo "paht")', function () {
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['9' => ['paht' => '/tmp/x']]]);
    _agentAssertThrows(fn() => Config::load($home), '9', 'expected a RuntimeException naming project id "9" for a missing path');
});

it('Config::load throws when a project entry has an empty "path"', function () {
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['10' => ['path' => '']]]);
    _agentAssertThrows(fn() => Config::load($home), '10', 'expected a RuntimeException naming project id "10" for an empty path');
});

it('Config::load throws when a project entry is not an object at all', function () {
    $home = _agentHomeRawProjects(_AGENT_ENV_OK, json_encode(['projects' => ['11' => 'not-an-object']]));
    _agentAssertThrows(fn() => Config::load($home), '11', 'expected a RuntimeException naming project id "11" for a non-object entry');
});

// --- M4: projectPath()'s path must be validated and canonicalised ---

it('Config::load throws on a "~"-relative path, naming the tilde mistake', function () {
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['12' => ['path' => '~/Work/x']]]);
    _agentAssertThrows(fn() => Config::load($home), '~', 'expected a RuntimeException mentioning "~" for a tilde path');
});

it('Config::load throws on a relative (non-absolute) path', function () {
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['13' => ['path' => 'relative/dir']]]);
    _agentAssertThrows(fn() => Config::load($home), '13', 'expected a RuntimeException naming project id "13" for a relative path');
});

it('Config::load throws on a path that does not exist', function () {
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['14' => ['path' => '/definitely/does/not/exist/xyz-otack']]]);
    _agentAssertThrows(fn() => Config::load($home), '14', 'expected a RuntimeException naming project id "14" for a nonexistent path');
});

it('Config::load throws on a path that is a file, not a directory', function () {
    $file = sys_get_temp_dir() . '/otack-agent-not-a-dir-' . bin2hex(random_bytes(4));
    file_put_contents($file, 'x');
    register_shutdown_function(fn() => @unlink($file));
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['15' => ['path' => $file]]]);
    _agentAssertThrows(fn() => Config::load($home), '15', 'expected a RuntimeException naming project id "15" for a file path');
});

it('Config::load throws on a non-string "path" (e.g. a JSON array) instead of casting it to "Array"', function () {
    $home = _agentHomeRawProjects(_AGENT_ENV_OK, json_encode(['projects' => ['16' => ['path' => ['/tmp/x']]]]));
    _agentAssertThrows(fn() => Config::load($home), '16', 'expected a RuntimeException naming project id "16" for a non-string path');
});

it('Config::load canonicalises a valid absolute path with realpath()', function () {
    $dir = sys_get_temp_dir() . '/otack-agent-real-' . bin2hex(random_bytes(4));
    mkdir($dir . '/sub', 0700, true);
    register_shutdown_function(fn() => _agentConfigRrmdir($dir));
    $withDotDot = $dir . '/sub/../sub';
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['17' => ['path' => $withDotDot]]]);
    $c = Config::load($home);
    assert_eq(realpath($dir . '/sub'), $c->projectPath(17)['path']);
});

// --- m3: numeric project ids must be canonical, or the lookup silently misses ---

it('Config::load throws on a non-canonical numeric project id like "042"', function () {
    $home = _agentHomeRawProjects(_AGENT_ENV_OK, json_encode(['projects' => ['042' => ['path' => '/tmp']]]));
    _agentAssertThrows(fn() => Config::load($home), '042', 'expected a RuntimeException naming the non-canonical id "042"');
});

// --- in_progress_column / review_column: machine-local board-column config ---
//
// The board's "in progress"/"review" columns are free-text and can be named
// in any language (Russian/Polish teams), so the phase machine cannot guess
// them from the server's is_done/is_backlog flags. These two optional keys
// let the operator name the columns explicitly, either by id (int) or by
// exact column name (string). Absent means null; any other JSON type must
// throw naming the project and the offending key — same fail-closed spirit
// as the "write" boolean check above (C1).

it('Config::projectPath returns null for in_progress_column and review_column when both are absent', function () {
    mkdir('/tmp/p-col-absent');
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['60' => ['path' => '/tmp/p-col-absent']]]);
    $c = Config::load($home);
    assert_eq(null, $c->projectPath(60)['in_progress_column']);
    assert_eq(null, $c->projectPath(60)['review_column']);
    _agentConfigRrmdir('/tmp/p-col-absent');
});

it('Config::projectPath accepts in_progress_column and review_column given as integer column ids', function () {
    mkdir('/tmp/p-col-int');
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['61' => [
        'path' => '/tmp/p-col-int',
        'in_progress_column' => 3,
        'review_column' => 5,
    ]]]);
    $c = Config::load($home);
    assert_eq(3, $c->projectPath(61)['in_progress_column']);
    assert_eq(5, $c->projectPath(61)['review_column']);
    _agentConfigRrmdir('/tmp/p-col-int');
});

it('Config::projectPath accepts in_progress_column and review_column given as exact column-name strings', function () {
    mkdir('/tmp/p-col-str');
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['62' => [
        'path' => '/tmp/p-col-str',
        'in_progress_column' => 'В работе',
        'review_column' => 'Na przeglądzie',
    ]]]);
    $c = Config::load($home);
    assert_eq('В работе', $c->projectPath(62)['in_progress_column']);
    assert_eq('Na przeglądzie', $c->projectPath(62)['review_column']);
    _agentConfigRrmdir('/tmp/p-col-str');
});

it('Config::load throws when in_progress_column is a float, naming the project and the key', function () {
    mkdir('/tmp/p-col-float');
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['63' => [
        'path' => '/tmp/p-col-float',
        'in_progress_column' => 3.5,
    ]]]);
    _agentAssertThrows(
        fn() => Config::load($home),
        '63',
        'expected a RuntimeException naming project id "63" for a float in_progress_column'
    );
    _agentAssertThrows(
        fn() => Config::load($home),
        'in_progress_column',
        'expected a RuntimeException naming the key "in_progress_column" for a float value'
    );
    _agentConfigRrmdir('/tmp/p-col-float');
});

it('Config::load throws when review_column is an array, naming the project and the key', function () {
    mkdir('/tmp/p-col-array');
    $home = _agentHomeRawProjects(_AGENT_ENV_OK, json_encode(['projects' => ['64' => [
        'path' => '/tmp/p-col-array',
        'review_column' => ['not', 'scalar'],
    ]]]));
    _agentAssertThrows(
        fn() => Config::load($home),
        '64',
        'expected a RuntimeException naming project id "64" for an array review_column'
    );
    _agentAssertThrows(
        fn() => Config::load($home),
        'review_column',
        'expected a RuntimeException naming the key "review_column" for an array value'
    );
    _agentConfigRrmdir('/tmp/p-col-array');
});

it('Config::load throws when in_progress_column is a bool, naming the project and the key', function () {
    mkdir('/tmp/p-col-bool');
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['65' => [
        'path' => '/tmp/p-col-bool',
        'in_progress_column' => true,
    ]]]);
    _agentAssertThrows(
        fn() => Config::load($home),
        '65',
        'expected a RuntimeException naming project id "65" for a boolean in_progress_column'
    );
    _agentAssertThrows(
        fn() => Config::load($home),
        'in_progress_column',
        'expected a RuntimeException naming the key "in_progress_column" for a boolean value'
    );
    _agentConfigRrmdir('/tmp/p-col-bool');
});

it('Config::load throws when review_column is a bool, naming the project and the key', function () {
    mkdir('/tmp/p-col-bool2');
    $home = _agentHome(_AGENT_ENV_OK, ['projects' => ['66' => [
        'path' => '/tmp/p-col-bool2',
        'review_column' => false,
    ]]]);
    _agentAssertThrows(
        fn() => Config::load($home),
        '66',
        'expected a RuntimeException naming project id "66" for a boolean review_column'
    );
    _agentAssertThrows(
        fn() => Config::load($home),
        'review_column',
        'expected a RuntimeException naming the key "review_column" for a boolean value'
    );
    _agentConfigRrmdir('/tmp/p-col-bool2');
});

// --- Task 2: scopes, roots, approval, default_branch, writeProjectEntry ---

function _cfg2Home(array $projectsJson, ?string $managerEnv = "OTACK_API_URL=https://m.example/api/v1\nOTACK_API_TOKEN=otk_owner\n", string $agentEnvExtra = ''): string {
    $home = sys_get_temp_dir() . '/otack-cfg2-' . bin2hex(random_bytes(4));
    register_shutdown_function(fn() => _agentConfigRrmdir($home));
    mkdir("$home/.claude/otack-agent", 0700, true);
    file_put_contents("$home/.claude/otack-agent/.env", "OTACK_API_URL=https://a.example/api/v1\nOTACK_API_TOKEN=otk_bot\n" . $agentEnvExtra);
    chmod("$home/.claude/otack-agent/.env", 0600);
    if ($managerEnv !== null) {
        mkdir("$home/.claude/otack-manager", 0700, true);
        file_put_contents("$home/.claude/otack-manager/.env", $managerEnv);
        chmod("$home/.claude/otack-manager/.env", 0600);
    }
    file_put_contents("$home/.claude/otack-agent/projects.json", json_encode($projectsJson));
    chmod("$home/.claude/otack-agent/projects.json", 0600);
    return $home;
}

it('Config manager scope reads the owner token, agent scope the bot token, same projects', function () {
    $dir = sys_get_temp_dir() . '/otack-cfg2-p-' . bin2hex(random_bytes(4)); mkdir($dir);
    $home = _cfg2Home(['projects' => ['7' => ['path' => $dir]]]);
    $a = App\Agent\Config::load($home, 'agent');
    $m = App\Agent\Config::load($home, 'manager');
    assert_eq('otk_bot', $a->token);
    assert_eq('otk_owner', $m->token);
    assert_eq('manager', $m->scope);
    assert_eq(realpath($dir), $m->projectPath(7)['path']);
});

it('Config manager scope fails loudly without ~/.claude/otack-manager/.env', function () {
    $home = _cfg2Home(['projects' => []], null);
    try { App\Agent\Config::load($home, 'manager'); assert_true(false, 'expected throw'); }
    catch (RuntimeException $e) { assert_true(str_contains($e->getMessage(), 'otack-manager'), $e->getMessage()); }
});

it('Config rejects an unknown scope', function () {
    $home = _cfg2Home(['projects' => []]);
    try { App\Agent\Config::load($home, 'root'); assert_true(false, 'expected throw'); }
    catch (InvalidArgumentException $e) { assert_true(true); }
});

it('Config parses roots and rejects a relative or missing root', function () {
    $r = sys_get_temp_dir() . '/otack-root-' . bin2hex(random_bytes(4)); mkdir($r);
    $home = _cfg2Home(['projects' => [], 'roots' => ['otack' => $r]]);
    assert_eq(['otack' => realpath($r)], App\Agent\Config::load($home, 'manager')->roots);
    foreach ([['otack' => 'rel/path'], ['otack' => '/nonexistent-' . bin2hex(random_bytes(3))], ['Bad Key' => $r]] as $bad) {
        $home = _cfg2Home(['projects' => [], 'roots' => $bad]);
        try { App\Agent\Config::load($home, 'manager'); assert_true(false, 'expected throw for ' . json_encode($bad)); }
        catch (RuntimeException $e) { assert_true(str_contains($e->getMessage(), 'roots'), $e->getMessage()); }
    }
});

it('Config approval defaults to none and validates telegram settings in agent scope', function () {
    $home = _cfg2Home(['projects' => []]);
    $c = App\Agent\Config::load($home, 'agent');
    assert_eq('none', $c->approval['mode']);
    assert_eq(10, $c->approval['digest_size']);
    assert_eq(null, $c->telegram);

    $home = _cfg2Home(['projects' => [], 'approval' => ['mode' => 'telegram']]);
    try { App\Agent\Config::load($home, 'agent'); assert_true(false, 'expected throw'); }
    catch (RuntimeException $e) { assert_true(str_contains($e->getMessage(), 'OTACK_TG_BOT_TOKEN'), $e->getMessage()); }

    $home = _cfg2Home(['projects' => [], 'approval' => ['mode' => 'telegram', 'digest_size' => 5, 'critical_tags' => ['Bug']]],
        "OTACK_API_URL=https://m.example/api/v1\nOTACK_API_TOKEN=otk_owner\n",
        "OTACK_TG_BOT_TOKEN=123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ\nOTACK_TG_CHAT_ID=987654\n");
    $c = App\Agent\Config::load($home, 'agent');
    assert_eq(['token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'chat_id' => '987654'], $c->telegram);
    assert_eq(5, $c->approval['digest_size']);
    assert_eq(['bug'], $c->approval['critical_tags']);
    assert_eq(null, App\Agent\Config::load($home, 'manager')->telegram, 'manager scope never carries the bot\'s Telegram token');

    foreach ([['mode' => 'email'], ['digest_size' => 0], ['digest_size' => '5'], ['digest_every_hours' => 1000], ['critical_tags' => 'bug']] as $bad) {
        $home = _cfg2Home(['projects' => [], 'approval' => $bad]);
        try { App\Agent\Config::load($home, 'agent'); assert_true(false, 'expected throw for ' . json_encode($bad)); }
        catch (RuntimeException $e) { assert_true(str_contains($e->getMessage(), 'approval'), $e->getMessage()); }
    }
});

it('Config validates default_branch and exposes it', function () {
    $dir = sys_get_temp_dir() . '/otack-cfg2-p-' . bin2hex(random_bytes(4)); mkdir($dir);
    $home = _cfg2Home(['projects' => ['7' => ['path' => $dir, 'default_branch' => 'main']]]);
    assert_eq('main', App\Agent\Config::load($home)->projectPath(7)['default_branch']);
    $home = _cfg2Home(['projects' => ['7' => ['path' => $dir, 'default_branch' => 'main; rm -rf /']]]);
    try { App\Agent\Config::load($home); assert_true(false, 'expected throw'); }
    catch (RuntimeException $e) { assert_true(str_contains($e->getMessage(), 'default_branch'), $e->getMessage()); }
});

it('writeProjectEntry adds an entry, keeps every other key, stays 0600', function () {
    $d1 = sys_get_temp_dir() . '/otack-cfg2-p-' . bin2hex(random_bytes(4)); mkdir($d1);
    $d2 = sys_get_temp_dir() . '/otack-cfg2-p-' . bin2hex(random_bytes(4)); mkdir($d2);
    $r  = sys_get_temp_dir() . '/otack-root-' . bin2hex(random_bytes(4)); mkdir($r);
    $home = _cfg2Home(['_comment' => 'keep me', 'roots' => ['otack' => $r], 'approval' => ['mode' => 'none'],
        'projects' => ['7' => ['path' => $d1, 'write' => false]]]);
    $c = App\Agent\Config::load($home, 'manager');
    $parsed = $c->writeProjectEntry(9, ['path' => $d2, 'write' => true, 'test_cmd' => 'make test', 'review_column' => 'Review']);
    assert_eq(realpath($d2), $parsed['path']);
    $file = "$home/.claude/otack-agent/projects.json";
    $raw = json_decode(file_get_contents($file), true);
    assert_eq('keep me', $raw['_comment']);
    assert_eq(['otack' => $r], $raw['roots']);
    assert_eq(['mode' => 'none'], $raw['approval']);
    assert_eq($d1, $raw['projects']['7']['path']);
    assert_eq(true, $raw['projects']['9']['write']);
    assert_eq(0600, fileperms($file) & 0777);
    assert_eq([], glob("$home/.claude/otack-agent/projects.json.tmp-*"));
    assert_eq(realpath($d2), App\Agent\Config::load($home, 'agent')->projectPath(9)['path']);
});

it('writeProjectEntry refuses invalid entries and leaves the file untouched', function () {
    $home = _cfg2Home(['projects' => []]);
    $file = "$home/.claude/otack-agent/projects.json";
    $before = file_get_contents($file);
    $c = App\Agent\Config::load($home, 'manager');
    foreach ([['path' => 'relative'], ['path' => '/nonexistent-' . bin2hex(random_bytes(3))], ['path' => sys_get_temp_dir(), 'write' => 'true'],
              ['path' => sys_get_temp_dir(), 'test_cmd' => 'make test; curl x'], ['path' => sys_get_temp_dir(), 'review_column' => 1.5]] as $bad) {
        try { $c->writeProjectEntry(9, $bad); assert_true(false, 'expected throw for ' . json_encode($bad)); }
        catch (RuntimeException $e) { assert_true(true); }
    }
    assert_eq($before, file_get_contents($file));
});

it('writeProjectEntry is refused in agent scope', function () {
    $home = _cfg2Home(['projects' => []]);
    try { App\Agent\Config::load($home, 'agent')->writeProjectEntry(9, ['path' => sys_get_temp_dir()]); assert_true(false, 'expected throw'); }
    catch (RuntimeException $e) { assert_true(str_contains($e->getMessage(), 'manager'), $e->getMessage()); }
});

it('Config::isSafeBranchName agrees with otack-tick on a table of names, and load() applies it', function () {
    $tick = (string)file_get_contents(dirname(__DIR__, 2) . '/agent/bin/otack-tick');
    assert_true(preg_match('/^function isSafeBranchName\(string \$name\): bool\n\{.*?\n\}\n/ms', $tick, $m) === 1, 'tick function not found');
    if (!function_exists('_tickIsSafeBranchName')) {
        eval(str_replace('function isSafeBranchName(', 'function _tickIsSafeBranchName(', $m[0]));
    }
    $table = ['-x' => false, 'a..b' => false, '.foo' => false, '' => false, 'main;rm' => false,
              'main' => true, 'release/1.2' => true, 'feat/x-y' => true];
    foreach ($table as $name => $ok) {
        $name = (string)$name;
        assert_eq($ok, _tickIsSafeBranchName($name), "tick: '$name'");
        assert_eq($ok, App\Agent\Config::isSafeBranchName($name), "Config: '$name'");
    }
    $dir = sys_get_temp_dir() . '/otack-cfg2-p-' . bin2hex(random_bytes(4)); mkdir($dir);
    foreach (['-x', 'a..b', '.foo'] as $bad) {
        $home = _cfg2Home(['projects' => ['7' => ['path' => $dir, 'default_branch' => $bad]]]);
        try { App\Agent\Config::load($home); assert_true(false, "expected throw for '$bad'"); }
        catch (RuntimeException $e) { assert_true(str_contains($e->getMessage(), 'default_branch'), $e->getMessage()); }
    }
});
