<?php
// See test_agent_config.php for why this guard exists: tests/run.php's bare
// top-level `require` turns a missing-file require_once into a fatal that
// aborts the whole suite. This keeps the red step inside a green run.
$__impl = dirname(__DIR__, 2) . '/agent/lib/Client.php';
if (!is_file($__impl)) {
    it('agent/lib/Client.php exists', fn() => assert_true(false, 'not implemented yet'));
    return;
}
require_once $__impl;

use App\Agent\Client;

/** Test double: records the request and replays a canned response. */
final class FakeClient extends Client {
    public array $calls = [];
    /** @var list<array{status:int, body:string, headers?:array<string,string>}> */
    public array $queue = [];
    /** @var list<int> seconds passed to sleep(), recorded instead of actually sleeping */
    public array $slept = [];
    protected function rawRequest(string $method, string $url, array $headers, ?string $body): array {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $r = array_shift($this->queue) ?? ['status' => 200, 'body' => '{}'];
        $r['headers'] = $r['headers'] ?? [];
        return $r;
    }
    protected function sleep(int $seconds): void {
        $this->slept[] = $seconds;
    }
    /**
     * Test-only passthrough: parseResponseHeaders() is `protected static` on
     * Client, on purpose — FakeClient overrides rawRequest() wholesale, so
     * without this no test can ever reach the real header/status parsing
     * logic at all. This is the seam the adversarial review's six survived
     * mutants lived in.
     */
    public static function parseHeaders(array $raw): array {
        return self::parseResponseHeaders($raw);
    }
}

/**
 * Spin up PHP's built-in server for exactly one test — proving the real
 * rawRequest() reads a non-2xx response body instead of treating it as a
 * connection failure, which is what an `ignore_errors => false` regression
 * would cause. Mirrors tests/api/run.php's php-S-in-the-background pattern,
 * scoped to a single throwaway static response instead of the whole app.
 */
function agent_client_with_fixture_server(int $status, string $body, callable $fn): void
{
    $dir = sys_get_temp_dir() . '/otack-agent-client-fixture-' . getmypid();
    @mkdir($dir);
    $router = $dir . '/router.php';
    file_put_contents(
        $router,
        '<?php http_response_code(' . $status . '); '
        . "header('Content-Type: application/json'); echo " . var_export($body, true) . ';'
    );
    $port = random_int(40000, 44999);
    $cmd = '/usr/bin/env php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router)
        . ' > /dev/null 2>&1 & echo $!';
    $pid = (int)trim((string)shell_exec($cmd));
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($s) { fclose($s); break; }
        usleep(50_000);
    }
    try {
        $fn($port);
    } finally {
        if ($pid > 0) {
            if (function_exists('posix_kill')) { @posix_kill($pid, 15); }
            else { @shell_exec('kill ' . $pid . ' 2>/dev/null'); }
        }
        @unlink($router);
        @rmdir($dir);
    }
}

it('Client::get builds the URL with a query string and sends the bearer token', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 200, 'body' => '{"items":[]}'];
    $c->get('/tasks', ['agent_state' => 'review', 'limit' => 50]);
    assert_eq('https://x.example/api/v1/tasks?agent_state=review&limit=50', $c->calls[0]['url']);
    assert_true(in_array('Authorization: Bearer otk_abc', $c->calls[0]['headers'], true), 'bearer header missing');
});

it('Client::get omits the question mark when there is no query', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->get('/me');
    assert_eq('https://x.example/api/v1/me', $c->calls[0]['url']);
});

it('Client::post sends a JSON body and the content-type header', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->post('/comments', ['entity' => 'task', 'entity_id' => 7, 'body' => 'hi']);
    assert_eq('POST', $c->calls[0]['method']);
    assert_eq('{"entity":"task","entity_id":7,"body":"hi"}', $c->calls[0]['body']);
    assert_true(in_array('Content-Type: application/json', $c->calls[0]['headers'], true), 'content-type missing');
});

it('Client decodes the JSON body and returns the status', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 201, 'body' => '{"id":9}'];
    $r = $c->post('/comments', []);
    assert_eq(201, $r['status']);
    assert_eq(9, $r['json']['id']);
});

it('Client throws a named error on 401 so a bad token is unmistakable', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_bad');
    $c->queue[] = ['status' => 401, 'body' => '{"error":"unauthorized"}'];
    $threw = false;
    try { $c->get('/me'); } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'token');
    }
    assert_true($threw, 'expected a RuntimeException mentioning the token');
});

it('Client retries once after a 429 and then succeeds', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc', 15, 0);
    $c->queue[] = ['status' => 429, 'body' => '{"error":"rate_limited"}'];
    $c->queue[] = ['status' => 200, 'body' => '{"ok":true}'];
    $r = $c->get('/ping');
    assert_eq(200, $r['status']);
    assert_eq(2, count($c->calls), 'expected exactly one retry');
});

it('Client gives up after a second 429 rather than looping', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc', 15, 0);
    $c->queue[] = ['status' => 429, 'body' => '{}'];
    $c->queue[] = ['status' => 429, 'body' => '{}'];
    $r = $c->get('/ping');
    assert_eq(429, $r['status']);
    assert_eq(2, count($c->calls));
});

it('Client sleeps for the server-given Retry-After, not the flat default', function () {
    // Constructed with the default retrySleepSeconds (1) on purpose: if the
    // client ignored Retry-After and fell back to the flat default, this
    // test would observe a sleep of 1, not 3.
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 429, 'body' => '{}', 'headers' => ['retry-after' => '3']];
    $c->queue[] = ['status' => 200, 'body' => '{"ok":true}'];
    $r = $c->get('/ping');
    assert_eq(200, $r['status']);
    assert_eq([3], $c->slept, 'expected a single sleep of 3s, taken from Retry-After');
});

it('Client clamps an out-of-range Retry-After into 1..65 seconds', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 429, 'body' => '{}', 'headers' => ['retry-after' => '600']];
    $c->queue[] = ['status' => 200, 'body' => '{}'];
    $c->get('/ping');
    assert_eq([65], $c->slept, 'expected the 600s Retry-After clamped down to 65');
});

it('Client::getAll follows next_cursor and merges items across pages', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 200, 'body' => json_encode(['items' => [['id' => 1], ['id' => 2]], 'next_cursor' => 2])];
    $c->queue[] = ['status' => 200, 'body' => json_encode(['items' => [['id' => 3]], 'next_cursor' => null])];
    $items = $c->getAll('/tasks/7/comments', ['limit' => 2]);
    assert_eq([1, 2, 3], array_column($items, 'id'));
    assert_eq(2, count($c->calls), 'expected exactly two page requests');
    assert_true(str_contains($c->calls[1]['url'], 'after=2'), 'second page should carry the cursor as after=');
});

it('Client::getAll throws on hitting maxPages with a cursor still pending, instead of truncating silently', function () {
    // Was: "stops at maxPages even if next_cursor keeps coming", asserting a
    // silent 2-item return — that return is indistinguishable from a
    // genuinely complete 2-item list, which is exactly the defect (J4).
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 200, 'body' => json_encode(['items' => [['id' => 1]], 'next_cursor' => 1])];
    $c->queue[] = ['status' => 200, 'body' => json_encode(['items' => [['id' => 2]], 'next_cursor' => 2])];
    $threw = false;
    try {
        $c->getAll('/tasks/7/comments', [], 2);
    } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'maxPages') && str_contains($e->getMessage(), '2');
    }
    assert_true($threw, 'expected a RuntimeException naming maxPages, not a silently truncated list');
    assert_eq(2, count($c->calls), 'both pages should still have been fetched before the throw');
});

it('Client::getAll throws instead of returning a partial list on a bad page', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 404, 'body' => '{"error":"not_found"}'];
    $threw = false;
    try { $c->getAll('/tasks/999/comments'); } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), '404');
    }
    assert_true($threw, 'expected a RuntimeException naming the 404, not a silently empty list');
});

// --- C1: a non-JSON body must not read as an empty success -----------------

it('Client throws when the response body is not valid JSON, instead of reading it as empty', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 200, 'body' => '<html>Access Denied</html>'];
    $threw = false;
    try {
        $c->get('/tasks');
    } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
        $threw = str_contains($msg, 'GET')
            && str_contains($msg, '/tasks')
            && str_contains($msg, '200')
            && str_contains($msg, 'Access Denied');
    }
    assert_true($threw, 'expected a RuntimeException naming method/url/status/body, not a silent []');
});

// --- C2: parseResponseHeaders(), the seam FakeClient can never reach -------

it('Client::parseResponseHeaders lower-cases a header seen in its original mixed case', function () {
    // Regression for a mutant that dropped strtolower(): FakeClient's own
    // fixtures always hand the client an already-lower-cased key, so the
    // *test double* can never catch a missing strtolower() — only calling
    // the real parser with a realistically-cased header line can.
    $r = FakeClient::parseHeaders(['HTTP/1.1 429 Too Many Requests', 'Retry-After: 42']);
    assert_eq(429, $r['status']);
    assert_eq('42', $r['headers']['retry-after']);
});

it('Client::parseResponseHeaders returns an empty header map when there are no header lines', function () {
    $r = FakeClient::parseHeaders(['HTTP/1.1 204 No Content']);
    assert_eq(204, $r['status']);
    assert_eq([], $r['headers']);
});

it('Client::parseResponseHeaders extracts the status from an unusual but valid status line', function () {
    $r = FakeClient::parseHeaders(['HTTP/2 404']);
    assert_eq(404, $r['status']);
});

it('Client::parseResponseHeaders skips a line with no colon instead of choking on it', function () {
    $r = FakeClient::parseHeaders([
        'HTTP/1.1 200 OK',
        'not-a-header-line-no-colon',
        'Content-Type: application/json',
    ]);
    assert_eq(200, $r['status']);
    assert_eq(['content-type' => 'application/json'], $r['headers']);
});

it('Client::parseResponseHeaders keeps colons inside a header value intact', function () {
    $r = FakeClient::parseHeaders(['HTTP/1.1 200 OK', 'Date: Wed, 21 Oct 2026 07:28:00 GMT']);
    assert_eq('Wed, 21 Oct 2026 07:28:00 GMT', $r['headers']['date']);
});

it('Client::parseResponseHeaders lets a repeated header win with its last value', function () {
    $r = FakeClient::parseHeaders([
        'HTTP/1.1 200 OK',
        'Set-Cookie: a=1',
        'Set-Cookie: b=2',
    ]);
    assert_eq('b=2', $r['headers']['set-cookie']);
});

it("Client::parseResponseHeaders resets the header map on a redirect's second status line", function () {
    // Two hops in one $http_response_header array, as PHP's http wrapper
    // emits even for the single hop follow_location=>0 still allows: a
    // Retry-After from the 302 must not leak into the map describing the
    // 200 the caller actually received (J1).
    $r = FakeClient::parseHeaders([
        'HTTP/1.1 302 Found',
        'Location: https://x.example/elsewhere',
        'Retry-After: 999',
        'HTTP/1.1 200 OK',
        'Content-Type: application/json',
    ]);
    assert_eq(200, $r['status']);
    assert_eq(['content-type' => 'application/json'], $r['headers']);
});

// --- C2: the real rawRequest() path, exercised over a real socket ----------

it('Client (real HTTP) throws on a connection failure instead of faking a 200', function () {
    $c = new Client('http://127.0.0.1:1', 'otk_abc', 2);
    $threw = false;
    try {
        $c->get('/x');
    } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'HTTP request failed');
    }
    assert_true($threw, 'expected a closed port to throw, not return a fake 200');
});

it('Client (real HTTP) reads a non-2xx body instead of treating it as a connection failure', function () {
    // Regression for ignore_errors => false: PHP's http stream wrapper
    // returns false from file_get_contents() on a 4xx/5xx status unless
    // ignore_errors is set — which would make rawRequest() throw "HTTP
    // request failed" for a perfectly well-formed error response.
    agent_client_with_fixture_server(404, '{"error":"not_found"}', function (int $port) {
        $c = new Client('http://127.0.0.1:' . $port, 'otk_abc', 5);
        $r = $c->get('/missing');
        assert_eq(404, $r['status']);
        assert_eq('not_found', $r['json']['error']);
    });
});

// --- J2: redirects must not be followed (token leak) ------------------------

it('Client refuses to follow a redirect and names the status and Location instead', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 302, 'body' => '', 'headers' => ['location' => 'https://evil.example/steal']];
    $threw = false;
    try {
        $c->get('/tasks');
    } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), '302') && str_contains($e->getMessage(), 'https://evil.example/steal');
    }
    assert_true($threw, 'expected a RuntimeException naming the redirect status and Location');
});

it('Client constructor rejects a non-https, non-loopback base URL', function () {
    $threw = false;
    try {
        new Client('http://api.example.com/v1', 'otk_abc');
    } catch (\InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'https');
    }
    assert_true($threw, 'expected a plain-http base URL to be rejected as unsafe for a bearer token');
});

it('Client constructor allows a plain http loopback base URL for local development', function () {
    $c = new Client('http://localhost:8080/api/v1', 'otk_abc');
    assert_true($c instanceof Client, 'expected loopback http to be exempt from the https requirement');
});

// --- J3: the Retry-After fallback clamp must apply on every path -----------

it('Client clamps the fallback retry delay to at least 1s when Retry-After is absent', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc', 15, 0);
    $c->queue[] = ['status' => 429, 'body' => '{}'];
    $c->queue[] = ['status' => 200, 'body' => '{}'];
    $c->get('/ping');
    assert_eq([1], $c->slept, 'a retrySleepSeconds of 0 must still be clamped up to 1');
});

it('Client clamps the fallback retry delay when Retry-After is an HTTP-date, not a number', function () {
    // RFC 7231 allows Retry-After to be an HTTP-date instead of a delta.
    // is_numeric() on that string is false, so this takes the fallback
    // path — which must be clamped exactly like the numeric path already was.
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc', 15, 0);
    $c->queue[] = ['status' => 429, 'body' => '{}', 'headers' => ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT']];
    $c->queue[] = ['status' => 200, 'body' => '{}'];
    $c->get('/ping');
    assert_eq([1], $c->slept, 'an HTTP-date Retry-After must fall back to a clamped delay, not sleep 0s');
});

it('Client clamps a Retry-After of 0 up to the 1s minimum', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 429, 'body' => '{}', 'headers' => ['retry-after' => '0']];
    $c->queue[] = ['status' => 200, 'body' => '{}'];
    $c->get('/ping');
    assert_eq([1], $c->slept, 'a Retry-After of 0 must not produce a hot retry');
});

it('Client clamps a negative Retry-After up to the 1s minimum', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 429, 'body' => '{}', 'headers' => ['retry-after' => '-5']];
    $c->queue[] = ['status' => 200, 'body' => '{}'];
    $c->get('/ping');
    assert_eq([1], $c->slept, 'a negative Retry-After must not produce a negative or zero sleep');
});

// --- J4: getAll must not go silent on a looping cursor ----------------------

it('Client::getAll throws when next_cursor repeats instead of looping on duplicate pages', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 200, 'body' => json_encode(['items' => [['id' => 1]], 'next_cursor' => 5])];
    $c->queue[] = ['status' => 200, 'body' => json_encode(['items' => [['id' => 1]], 'next_cursor' => 5])];
    $threw = false;
    try {
        $c->getAll('/tasks/7/comments');
    } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'next_cursor') && str_contains($e->getMessage(), '5');
    }
    assert_true($threw, 'expected a RuntimeException naming the cursor that failed to advance');
});

// --- Minor guards ------------------------------------------------------------

it("Client::getAll throws when 'items' is not an array, instead of merging garbage in", function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 200, 'body' => json_encode(['items' => 'oops', 'next_cursor' => null])];
    $threw = false;
    try {
        $c->getAll('/tasks/7/comments');
    } catch (\RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'items');
    }
    assert_true($threw, "expected a RuntimeException, not a silently merged ['oops']");
});

it('Client::post serialises an empty body as {} not [], so the server sees an object', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->post('/comments', []);
    assert_eq('{}', $c->calls[0]['body']);
});

it('Client normalises a trailing slash on the base URL so paths never get a double slash', function () {
    $c = new FakeClient('https://x.example/api/v1/', 'otk_abc');
    $c->get('/tasks');
    assert_eq('https://x.example/api/v1/tasks', $c->calls[0]['url']);
});
