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

it('Client::getAll stops at maxPages even if next_cursor keeps coming', function () {
    $c = new FakeClient('https://x.example/api/v1', 'otk_abc');
    $c->queue[] = ['status' => 200, 'body' => json_encode(['items' => [['id' => 1]], 'next_cursor' => 1])];
    $c->queue[] = ['status' => 200, 'body' => json_encode(['items' => [['id' => 2]], 'next_cursor' => 2])];
    $items = $c->getAll('/tasks/7/comments', [], 2);
    assert_eq([1, 2], array_column($items, 'id'));
    assert_eq(2, count($c->calls), 'expected the loop to stop at maxPages');
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
