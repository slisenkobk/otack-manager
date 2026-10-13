<?php
$__lib = dirname(__DIR__, 2) . '/agent/lib/Approval.php';
if (!is_file($__lib)) { it('agent/lib/Approval.php exists', fn() => assert_true(false, 'not implemented yet')); return; }
foreach (['Config', 'Client', 'Triage', 'Rank', 'Board', 'Telegram', 'Selection', 'Approval'] as $__c) {
    require_once dirname(__DIR__, 2) . "/agent/lib/$__c.php";
}
require_once __DIR__ . '/agent_fixture_server.php';
use App\Agent\{Approval, Client, Config, Selection, Telegram};

const _AP_TG = '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ';

/** Agent-scope home with project 42 listed, telegram mode on, API + Telegram on one fixture port. */
function _apHome(int $port): string {
    $home = sys_get_temp_dir() . '/otack-ap-' . bin2hex(random_bytes(4));
    mkdir("$home/.claude/otack-agent", 0700, true);
    mkdir("$home/p42", 0700);
    file_put_contents("$home/.claude/otack-agent/.env",
        "OTACK_API_URL=http://127.0.0.1:$port/api/v1\nOTACK_API_TOKEN=otk_bot\nOTACK_TG_BOT_TOKEN=" . _AP_TG . "\nOTACK_TG_CHAT_ID=42\n");
    chmod("$home/.claude/otack-agent/.env", 0600);
    file_put_contents("$home/.claude/otack-agent/projects.json", json_encode([
        'approval' => ['mode' => 'telegram', 'digest_size' => 2, 'critical_tags' => []],
        'projects' => ['42' => ['path' => "$home/p42", 'write' => true]],
    ]));
    chmod("$home/.claude/otack-agent/projects.json", 0600);
    return $home;
}

function _apRoutes(array $extra = []): array {
    return array_merge([
        'GET /api/v1/me'             => [['status' => 200, 'body' => json_encode(['id' => 7])]],
        'GET /api/v1/projects/42'    => [['status' => 200, 'body' => json_encode(['id' => 42, 'name' => 'Otack CMS', 'columns' => [['id' => 2], ['id' => 3, 'is_done' => true]]])]],
        'GET /api/v1/projects/42/tasks' => [['status' => 200, 'body' => json_encode(['items' => [
            ['id' => 11, 'project_id' => 42, 'column_id' => 2, 'priority' => 'low', 'assignee_id' => null, 'agent_state' => null, 'title' => 'Low'],
            ['id' => 12, 'project_id' => 42, 'column_id' => 2, 'priority' => 'urgent', 'assignee_id' => 7, 'agent_state' => null, 'title' => 'Urgent'],
            ['id' => 13, 'project_id' => 42, 'column_id' => 2, 'priority' => 'high', 'assignee_id' => 99, 'agent_state' => null, 'title' => 'Someone else\'s'],
            ['id' => 14, 'project_id' => 42, 'column_id' => 2, 'priority' => 'high', 'assignee_id' => 7, 'agent_state' => 'implementing', 'title' => 'Started'],
            ['id' => 15, 'project_id' => 42, 'column_id' => 3, 'priority' => 'urgent', 'assignee_id' => null, 'agent_state' => null, 'title' => 'Done'],
            ['id' => 16, 'project_id' => 42, 'column_id' => 2, 'priority' => 'medium', 'assignee_id' => null, 'agent_state' => null, 'title' => 'Medium'],
        ], 'next_cursor' => null])]],
        'POST /bot' . _AP_TG . '/getUpdates'  => [['status' => 200, 'body' => json_encode(['ok' => true, 'result' => []])]],
        'POST /bot' . _AP_TG . '/sendMessage' => [['status' => 200, 'body' => json_encode(['ok' => true, 'result' => ['message_id' => 500]])]],
        'POST /bot' . _AP_TG . '/answerCallbackQuery' => [['status' => 200, 'body' => json_encode(['ok' => true, 'result' => true])]],
    ], $extra);
}

function _apRun(string $home, int $port, int $ts = 2_000_000): array {
    $cfg = Config::load($home, 'agent');
    return Approval::run($cfg, new Client($cfg->instance, $cfg->token), new Telegram(_AP_TG, "http://127.0.0.1:$port"),
        "$home/.claude/otack-agent/selection.json", new DateTimeImmutable("@$ts"));
}

it('first pass sends a ranked digest of unstarted, unassigned-or-mine, open tasks', function () {
    _cliFixtureServer(_apRoutes(), function (int $port, string $log) {
        $home = _apHome($port);
        $res = _apRun($home, $port);
        $send = array_values(array_filter(_requestLog($log), fn($e) => str_ends_with($e['path'], '/sendMessage')))[0]['body'];
        assert_eq('42', (string)$send['chat_id']);
        assert_eq('HTML', $send['parse_mode']);
        assert_true(str_contains($send['text'], '1. #12 Urgent'), $send['text']);
        assert_true(str_contains($send['text'], '2. #16 Medium'), $send['text']);
        assert_true(str_contains($send['text'], '2 из 3'), $send['text']);
        $sel = Selection::load("$home/.claude/otack-agent/selection.json");
        assert_eq([12, 16], $sel['digest']['offered']);
        assert_eq(500, $sel['digest']['message_id']);
        assert_eq([], $res['approved']);
    });
});

it('a tap assigns an unassigned task to the bot, comments, acks, and suppresses the next digest', function () {
    _cliFixtureServer(_apRoutes([
        'POST /bot' . _AP_TG . '/getUpdates' => [['status' => 200, 'body' => json_encode(['ok' => true, 'result' => [
            ['update_id' => 900, 'callback_query' => ['id' => 'c1', 'from' => ['id' => 42], 'data' => 'take:16', 'message' => ['message_id' => 500, 'chat' => ['id' => 42]]]],
        ]])]],
        'GET /api/v1/tasks/16'   => [['status' => 200, 'body' => json_encode(['id' => 16, 'project_id' => 42, 'column_id' => 2, 'assignee_id' => null, 'agent_state' => null, 'from_form' => false])]],
        'PATCH /api/v1/tasks/16' => [['status' => 200, 'body' => json_encode(['id' => 16])]],
        'POST /api/v1/comments'  => [['status' => 201, 'body' => json_encode(['id' => 1])]],
    ]), function (int $port, string $log) {
        $home = _apHome($port);
        $s = Selection::empty();
        $s['digest'] = ['message_id' => 500, 'sent_at' => 1_999_000, 'offered' => [12, 16], 'answered' => false];
        Selection::save("$home/.claude/otack-agent/selection.json", $s);
        $res = _apRun($home, $port);
        assert_eq([16], $res['approved']);
        $entries = _requestLog($log);
        $patch = array_values(array_filter($entries, fn($e) => $e['method'] === 'PATCH'))[0];
        assert_eq(['assignee_id' => 7], $patch['body']);
        assert_eq(1, count(array_filter($entries, fn($e) => $e['path'] === '/api/v1/comments')));
        assert_eq(1, count(array_filter($entries, fn($e) => str_ends_with($e['path'], '/answerCallbackQuery'))));
        assert_eq(0, count(array_filter($entries, fn($e) => str_ends_with($e['path'], '/sendMessage'))), 'no digest while approvals are queued');
        assert_eq(901, Selection::load("$home/.claude/otack-agent/selection.json")['offset']);
    });
});

it('an approved task now assigned to a human, or created from a public form, is dropped untouched', function () {
    _cliFixtureServer(_apRoutes([
        'POST /bot' . _AP_TG . '/getUpdates' => [['status' => 200, 'body' => json_encode(['ok' => true, 'result' => [
            ['update_id' => 1, 'callback_query' => ['id' => 'c1', 'from' => ['id' => 42], 'data' => 'take:16', 'message' => ['message_id' => 500, 'chat' => ['id' => 42]]]],
            ['update_id' => 2, 'callback_query' => ['id' => 'c2', 'from' => ['id' => 42], 'data' => 'take:12', 'message' => ['message_id' => 500, 'chat' => ['id' => 42]]]],
        ]])]],
        'GET /api/v1/tasks/16' => [['status' => 200, 'body' => json_encode(['id' => 16, 'project_id' => 42, 'column_id' => 2, 'assignee_id' => 99, 'agent_state' => null])]],
        'GET /api/v1/tasks/12' => [['status' => 200, 'body' => json_encode(['id' => 12, 'project_id' => 42, 'column_id' => 2, 'assignee_id' => 7, 'agent_state' => null, 'from_form' => true])]],
    ]), function (int $port, string $log) {
        $home = _apHome($port);
        $s = Selection::empty();
        $s['digest'] = ['message_id' => 500, 'sent_at' => 1_999_000, 'offered' => [12, 16], 'answered' => false];
        Selection::save("$home/.claude/otack-agent/selection.json", $s);
        $res = _apRun($home, $port);
        assert_eq([], $res['approved']);
        assert_eq(0, count(array_filter(_requestLog($log), fn($e) => in_array($e['method'], ['PATCH'], true) || $e['path'] === '/api/v1/comments')));
        assert_eq(2, count($res['notes']));
    });
});

it('approved tasks the bot has started are pruned', function () {
    _cliFixtureServer(_apRoutes([
        'GET /api/v1/tasks/16' => [['status' => 200, 'body' => json_encode(['id' => 16, 'project_id' => 42, 'column_id' => 2, 'assignee_id' => 7, 'agent_state' => 'researching'])]],
    ]), function (int $port) {
        $home = _apHome($port);
        $s = Selection::empty();
        $s['approved'] = [16];
        $s['digest'] = ['message_id' => 500, 'sent_at' => 1_999_000, 'offered' => [16], 'answered' => true];
        Selection::save("$home/.claude/otack-agent/selection.json", $s);
        assert_eq([], _apRun($home, $port)['approved']);
    });
});

it('an approval whose assignment never happened (earlier pass died after the tap) is assigned on the next pass', function () {
    // The tap's offset is saved before any API side effect, so a pass that
    // dies between the tap and the PATCH leaves an approved id that the bot
    // does not own. `next` only sees the bot's own tasks, so without this
    // the id would sit in `approved` forever and suppress every digest.
    _cliFixtureServer(_apRoutes([
        'GET /api/v1/tasks/16'   => [['status' => 200, 'body' => json_encode(['id' => 16, 'project_id' => 42, 'column_id' => 2, 'assignee_id' => null, 'agent_state' => null])]],
        'PATCH /api/v1/tasks/16' => [['status' => 200, 'body' => json_encode(['id' => 16])]],
        'POST /api/v1/comments'  => [['status' => 201, 'body' => json_encode(['id' => 1])]],
    ]), function (int $port, string $log) {
        $home = _apHome($port);
        $s = Selection::empty();
        $s['approved'] = [16];
        $s['digest'] = ['message_id' => 500, 'sent_at' => 1_999_000, 'offered' => [16], 'answered' => true];
        Selection::save("$home/.claude/otack-agent/selection.json", $s);
        assert_eq([16], _apRun($home, $port)['approved']);
        $entries = _requestLog($log);
        assert_eq(1, count(array_filter($entries, fn($e) => $e['method'] === 'PATCH')));
        assert_eq(1, count(array_filter($entries, fn($e) => $e['path'] === '/api/v1/comments')));
    });
});

it('an approved task whose project left projects.json is dropped', function () {
    _cliFixtureServer(_apRoutes([
        'GET /api/v1/tasks/16' => [['status' => 200, 'body' => json_encode(['id' => 16, 'project_id' => 77, 'assignee_id' => 7, 'agent_state' => null])]],
    ]), function (int $port, string $log) {
        $home = _apHome($port);
        $s = Selection::empty();
        $s['approved'] = [16];
        $s['digest'] = ['message_id' => 500, 'sent_at' => 1_999_000, 'offered' => [16], 'answered' => true];
        Selection::save("$home/.claude/otack-agent/selection.json", $s);
        $res = _apRun($home, $port);
        assert_eq([], $res['approved']);
        assert_eq(0, count(array_filter(_requestLog($log), fn($e) => $e['method'] === 'PATCH')));
    });
});

it('a broken selection.json is quarantined, nothing is approved, and the owner hears about it once', function () {
    _cliFixtureServer(_apRoutes(), function (int $port, string $log) {
        $home = _apHome($port);
        $path = "$home/.claude/otack-agent/selection.json";
        file_put_contents($path, '{"approved": [16], broken');
        $res = _apRun($home, $port);
        assert_eq([], $res['approved']);
        assert_true(!is_file($path), 'the broken file must be moved aside');
        assert_eq(1, count(glob("$path.broken-*")), 'the broken file is kept for inspection');
        $entries = _requestLog($log);
        assert_eq(0, count(array_filter($entries, fn($e) => str_ends_with($e['path'], '/getUpdates'))), 'no taps read from a broken state');
        assert_eq(0, count(array_filter($entries, fn($e) => $e['method'] === 'PATCH' || $e['path'] === '/api/v1/comments')));
        $sends = array_values(array_filter($entries, fn($e) => str_ends_with($e['path'], '/sendMessage')));
        assert_eq(1, count($sends));
        assert_true(str_contains($sends[0]['body']['text'], 'selection.json'), $sends[0]['body']['text']);
        assert_true(str_contains(implode("\n", $res['notes']), 'selection.json'), implode("\n", $res['notes']));
        // The next pass starts clean: a fresh digest, still nothing approved.
        $res2 = _apRun($home, $port, 2_000_100);
        assert_eq([], $res2['approved']);
        assert_true(is_array(Selection::load($path)['digest']));
    });
});

it('a failing getUpdates throws with the Telegram token masked, and approves nothing', function () {
    _cliFixtureServer(_apRoutes([
        'POST /bot' . _AP_TG . '/getUpdates' => [['status' => 409, 'body' => json_encode(['ok' => false, 'description' => 'Conflict for ' . _AP_TG])]],
    ]), function (int $port) {
        $home = _apHome($port);
        $msg = '';
        try { _apRun($home, $port); } catch (\RuntimeException $e) { $msg = $e->getMessage(); }
        assert_true($msg !== '', 'expected a RuntimeException');
        assert_true(!str_contains($msg, _AP_TG), $msg);
    });
});

it('an approved task moved to a done or backlog column is dropped, and the next digest goes out', function () {
    _cliFixtureServer(_apRoutes([
        'GET /api/v1/projects/42' => [['status' => 200, 'body' => json_encode(['id' => 42, 'name' => 'Otack CMS', 'columns' => [
            ['id' => 2], ['id' => 3, 'is_done' => true], ['id' => 4, 'is_backlog' => true]]])]],
        'GET /api/v1/tasks/16' => [['status' => 200, 'body' => json_encode(['id' => 16, 'project_id' => 42, 'column_id' => 3, 'assignee_id' => 7, 'agent_state' => null])]],
        'GET /api/v1/tasks/17' => [['status' => 200, 'body' => json_encode(['id' => 17, 'project_id' => 42, 'column_id' => 4, 'assignee_id' => 7, 'agent_state' => null])]],
    ]), function (int $port, string $log) {
        $home = _apHome($port);
        $s = Selection::empty();
        $s['approved'] = [16, 17];
        $s['digest'] = ['message_id' => 500, 'sent_at' => 1_000_000, 'offered' => [16, 17], 'answered' => true];
        Selection::save("$home/.claude/otack-agent/selection.json", $s);
        $res = _apRun($home, $port);
        assert_eq([], $res['approved']);
        $notes = implode("\n", $res['notes']);
        assert_true(str_contains($notes, '#16') && str_contains($notes, '#17'), $notes);
        $entries = _requestLog($log);
        assert_eq(1, count(array_filter($entries, fn($e) => str_ends_with($e['path'], '/sendMessage'))), 'the digest resumes once the queue is clear');
        assert_eq(0, count(array_filter($entries, fn($e) => $e['method'] === 'PATCH')));
    });
});

it('a transient GET failure keeps the approval; only 404/403 drop it', function () {
    _cliFixtureServer(_apRoutes([
        'GET /api/v1/tasks/16' => [['status' => 500, 'body' => json_encode(['error' => 'boom'])]],
        'GET /api/v1/tasks/17' => [['status' => 404, 'body' => json_encode(['error' => 'gone'])]],
        'GET /api/v1/tasks/18' => [['status' => 403, 'body' => json_encode(['error' => 'forbidden'])]],
    ]), function (int $port, string $log) {
        $home = _apHome($port);
        $s = Selection::empty();
        $s['approved'] = [16, 17, 18];
        $s['digest'] = ['message_id' => 500, 'sent_at' => 1_999_000, 'offered' => [16, 17, 18], 'answered' => true];
        Selection::save("$home/.claude/otack-agent/selection.json", $s);
        $res = _apRun($home, $port);
        assert_eq([16], $res['approved']);
        assert_eq([16], Selection::load("$home/.claude/otack-agent/selection.json")['approved']);
        assert_eq(3, count($res['notes']), implode("\n", $res['notes']));
    });
});

it('a transient project read failure keeps the approval', function () {
    _cliFixtureServer(_apRoutes([
        'GET /api/v1/projects/42' => [['status' => 502, 'body' => '{}']],
        'GET /api/v1/tasks/16' => [['status' => 200, 'body' => json_encode(['id' => 16, 'project_id' => 42, 'column_id' => 2, 'assignee_id' => 7, 'agent_state' => null])]],
    ]), function (int $port) {
        $home = _apHome($port);
        $s = Selection::empty();
        $s['approved'] = [16];
        $s['digest'] = ['message_id' => 500, 'sent_at' => 1_999_000, 'offered' => [16], 'answered' => true];
        Selection::save("$home/.claude/otack-agent/selection.json", $s);
        assert_eq([16], _apRun($home, $port)['approved']);
    });
});

it('a project that answers 403/404/5xx is skipped with a note; the others still produce a digest', function () {
    _cliFixtureServer(_apRoutes([
        'GET /api/v1/projects/43' => [['status' => 403, 'body' => '{}']],
        'GET /api/v1/projects/44' => [['status' => 404, 'body' => '{}']],
        'GET /api/v1/projects/45' => [['status' => 500, 'body' => '{}']],
    ]), function (int $port, string $log) {
        $home = _apHome($port);
        $file = "$home/.claude/otack-agent/projects.json";
        $raw = json_decode(file_get_contents($file), true);
        foreach ([43, 44, 45] as $pid) { $raw['projects'][(string)$pid] = ['path' => "$home/p42"]; }
        file_put_contents($file, json_encode($raw));
        $res = _apRun($home, $port);
        foreach ([43 => '403', 44 => '404', 45 => '500'] as $pid => $code) {
            $hit = array_filter($res['notes'], fn($n) => str_starts_with($n, "project $pid skipped: ") && str_contains($n, $code));
            assert_eq(1, count($hit), "note for $pid: " . json_encode($res['notes']));
        }
        $send = array_values(array_filter(_requestLog($log), fn($e) => str_ends_with($e['path'], '/sendMessage')));
        assert_eq(1, count($send), 'the digest still goes out');
        assert_true(str_contains($send[0]['body']['text'], '#12 Urgent'), $send[0]['body']['text']);
    });
});
