<?php
$__lib = dirname(__DIR__, 2) . '/agent/lib/Telegram.php';
if (!is_file($__lib)) { it('agent/lib/Telegram.php exists', fn() => assert_true(false, 'not implemented yet')); return; }
require_once $__lib;
require_once __DIR__ . '/agent_fixture_server.php';
use App\Agent\Telegram;

const _TG_TOKEN = '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ';

it('Telegram posts JSON to /bot<token>/<method> and returns result', function () {
    _cliFixtureServer([
        'POST /bot' . _TG_TOKEN . '/sendMessage' => [['status' => 200, 'body' => json_encode(['ok' => true, 'result' => ['message_id' => 77]])]],
    ], function (int $port, string $log) {
        $tg = new Telegram(_TG_TOKEN, "http://127.0.0.1:$port");
        assert_eq(['message_id' => 77], $tg->call('sendMessage', ['chat_id' => '5', 'text' => 'Привет']));
        assert_eq(['chat_id' => '5', 'text' => 'Привет'], _requestLog($log)[0]['body']);
    });
});

it('Telegram error carries the description and never the token', function () {
    _cliFixtureServer([
        'POST /bot' . _TG_TOKEN . '/sendMessage' => [['status' => 400, 'body' => json_encode(['ok' => false, 'description' => 'Bad Request: chat not found for ' . _TG_TOKEN])]],
    ], function (int $port) {
        try { (new Telegram(_TG_TOKEN, "http://127.0.0.1:$port"))->call('sendMessage', []); assert_true(false, 'expected throw'); }
        catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), 'chat not found'), $e->getMessage());
            assert_true(!str_contains($e->getMessage(), 'ABCDEFGHIJ'), 'token leaked');
        }
    });
});

it('Telegram refuses a non-https remote base, a malformed token and a bad method name', function () {
    foreach ([fn() => new Telegram(_TG_TOKEN, 'http://api.telegram.org'), fn() => new Telegram('not-a-token')] as $f) {
        try { $f(); assert_true(false, 'expected throw'); } catch (InvalidArgumentException $e) { assert_true(true); }
    }
    try { (new Telegram(_TG_TOKEN))->call('../x', []); assert_true(false, 'expected throw'); } catch (InvalidArgumentException $e) { assert_true(true); }
});

it('Telegram reports an unreachable endpoint without the token', function () {
    error_clear_last();
    try { (new Telegram(_TG_TOKEN, 'http://127.0.0.1:1', 2))->call('getUpdates', []); assert_true(false, 'expected throw'); }
    catch (RuntimeException $e) { assert_true(!str_contains($e->getMessage(), 'ABCDEFGHIJ'), $e->getMessage()); }
    assert_true(!str_contains((string)(error_get_last()['message'] ?? ''), 'ABCDEFGHIJ'), 'token left in error_get_last()');
});
