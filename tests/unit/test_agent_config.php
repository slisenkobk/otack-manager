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
