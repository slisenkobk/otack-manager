<?php
$__lib = dirname(__DIR__, 2) . '/agent/lib/Board.php';
if (!is_file($__lib)) { it('agent/lib/Board.php exists', fn() => assert_true(false, 'not implemented yet')); return; }
require_once dirname(__DIR__, 2) . '/agent/lib/Client.php';
require_once $__lib;
require_once __DIR__ . '/agent_fixture_server.php';
use App\Agent\{Board, Client};

$__project = ['id' => 42, 'name' => 'P', 'columns' => [
    ['id' => 1, 'name' => 'Backlog', 'is_backlog' => true, 'is_done' => false],
    ['id' => 2, 'name' => 'To Do', 'is_backlog' => false, 'is_done' => false],
    ['id' => 3, 'name' => 'Done', 'is_backlog' => false, 'is_done' => true],
]];

it('Board::workableColumnIds drops backlog and done', function () use ($__project) {
    assert_eq([2], Board::workableColumnIds($__project));
});

it('Board::openTasks keeps only tasks in workable columns', function () use ($__project) {
    _cliFixtureServer([
        'GET /api/v1/projects/42/tasks' => [['status' => 200, 'body' => json_encode(['items' => [
            ['id' => 10, 'column_id' => 1], ['id' => 11, 'column_id' => 2], ['id' => 12, 'column_id' => 3],
        ], 'next_cursor' => null])]],
    ], function (int $port) use ($__project) {
        $c = new Client("http://127.0.0.1:$port/api/v1", 'otk_test');
        assert_eq([11], array_map(fn($t) => $t['id'], Board::openTasks($c, $__project)));
    });
});

it('Board::project fails loudly on a non-200 or a detail without columns', function () {
    _cliFixtureServer([
        'GET /api/v1/projects/42' => [['status' => 403, 'body' => '{}']],
        'GET /api/v1/projects/43' => [['status' => 200, 'body' => json_encode(['id' => 43])]],
    ], function (int $port) {
        $c = new Client("http://127.0.0.1:$port/api/v1", 'otk_test');
        foreach ([42, 43] as $pid) {
            try { Board::project($c, $pid); assert_true(false, "expected throw for $pid"); }
            catch (RuntimeException $e) { assert_true(str_contains($e->getMessage(), "/projects/$pid"), $e->getMessage()); }
        }
    });
});

it('Board::criticalTaskIds matches project tags case-insensitively and collects their tasks', function () {
    _cliFixtureServer([
        'GET /api/v1/projects/42/tags'  => [['status' => 200, 'body' => json_encode(['items' => [
            ['id' => 5, 'name' => 'Bug'], ['id' => 6, 'name' => 'docs'],
        ]])]],
        'GET /api/v1/projects/42/tasks' => [['status' => 200, 'body' => json_encode(['items' => [['id' => 11], ['id' => 14]], 'next_cursor' => null])]],
    ], function (int $port, string $log) {
        $c = new Client("http://127.0.0.1:$port/api/v1", 'otk_test');
        assert_eq([11 => true, 14 => true], Board::criticalTaskIds($c, 42, ['bug']));
        $tasksCalls = array_values(array_filter(_requestLog($log), fn($e) => $e['path'] === '/api/v1/projects/42/tasks'));
        assert_eq(1, count($tasksCalls), 'one tasks query per matching tag');
        assert_eq('5', (string)$tasksCalls[0]['query']['tag_id']);
    });
});

it('Board::criticalTaskIds makes no request when no tag names are configured', function () {
    _cliFixtureServer([], function (int $port, string $log) {
        $c = new Client("http://127.0.0.1:$port/api/v1", 'otk_test');
        assert_eq([], Board::criticalTaskIds($c, 42, []));
        assert_eq([], _requestLog($log));
    });
});
