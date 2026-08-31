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

function _agentHome(array $env, ?array $projects, int $envMode = 0600): string {
    $home = sys_get_temp_dir() . '/otack-agent-test-' . bin2hex(random_bytes(4));
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

it('Config::load reads instance, token and projects', function () {
    $home = _agentHome(
        ['OTACK_API_URL' => 'https://x.example/api/v1', 'OTACK_API_TOKEN' => 'otk_abc'],
        ['instance' => 'ignored', 'projects' => ['42' => ['path' => '/tmp/p42', 'write' => true, 'test_cmd' => 'make test']]]
    );
    $c = Config::load($home);
    assert_eq('https://x.example/api/v1', $c->instance);
    assert_eq('otk_abc', $c->token);
    assert_eq('/tmp/p42', $c->projectPath(42)['path']);
    assert_eq(true, $c->projectPath(42)['write']);
    assert_eq('make test', $c->projectPath(42)['test_cmd']);
});

it('Config::projectPath returns null for a project not in the file', function () {
    $home = _agentHome(
        ['OTACK_API_URL' => 'https://x.example/api/v1', 'OTACK_API_TOKEN' => 'otk_abc'],
        ['projects' => ['42' => ['path' => '/tmp/p42', 'write' => true]]]
    );
    $c = Config::load($home);
    assert_eq(null, $c->projectPath(99));
});

it('Config::projectPath defaults write to false and test_cmd to null', function () {
    $home = _agentHome(
        ['OTACK_API_URL' => 'https://x.example/api/v1', 'OTACK_API_TOKEN' => 'otk_abc'],
        ['projects' => ['51' => ['path' => '/tmp/p51']]]
    );
    $c = Config::load($home);
    assert_eq(false, $c->projectPath(51)['write']);
    assert_eq(null, $c->projectPath(51)['test_cmd']);
});

it('Config::load refuses a token file readable beyond the owner', function () {
    $home = _agentHome(
        ['OTACK_API_URL' => 'https://x.example/api/v1', 'OTACK_API_TOKEN' => 'otk_abc'],
        ['projects' => []],
        0644
    );
    $threw = false;
    try { Config::load($home); } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), '0600');
    }
    assert_true($threw, 'expected a RuntimeException naming 0600');
});

it('Config::load fails with a clear message when the token is missing', function () {
    $home = _agentHome(['OTACK_API_URL' => 'https://x.example/api/v1'], ['projects' => []]);
    $threw = false;
    try { Config::load($home); } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'OTACK_API_TOKEN');
    }
    assert_true($threw, 'expected a RuntimeException naming OTACK_API_TOKEN');
});

it('Config::load treats a missing projects.json as "no project is allowed"', function () {
    $home = _agentHome(['OTACK_API_URL' => 'https://x.example/api/v1', 'OTACK_API_TOKEN' => 'otk_abc'], null);
    $c = Config::load($home);
    assert_eq(null, $c->projectPath(42));
});
