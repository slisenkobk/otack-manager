<?php
declare(strict_types=1);

// API handlers and web controllers must produce the same domain side effects
// (EventBus event → Telegram listeners, activity_log row), each exactly once.
// Runs both paths in-process against a temp SQLite DB with a recording
// listener on the EventBus — no network, no php -S.

use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Http\Request;
use App\Repository\{ActivityLogRepository, AttachmentRepository, CommentRepository,
    FormSubmissionRepository, PollRepository, ProjectMemberRepository, ProjectRepository,
    TagRepository, TaskColumnRepository, TaskLinkRepository, TaskRepository, UserRepository};
use App\Service\{DomainEvents, EntityLifecycle, EventBus};

/**
 * Serves a fixed body for php://input so web controllers (Request::jsonBody)
 * and API handlers (BaseHandler::readBody) both see the test payload.
 */
final class _DeInputStream
{
    public static string $body = '';
    public $context;
    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        $this->pos = 0;
        return $path === 'php://input';
    }
    public function stream_read(int $count): string|false
    {
        $chunk = substr(self::$body, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
    }
    public function stream_eof(): bool { return $this->pos >= strlen(self::$body); }
    public function stream_stat(): array { return ['size' => strlen(self::$body)]; }
}

/** Run $fn with php://input = json($body); returns [result, echoed output]. */
function _de_with_body(array $body, callable $fn): array
{
    _DeInputStream::$body = json_encode($body);
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', _DeInputStream::class);
    // Response::json() calls header()/http_response_code(); the runner has
    // already printed, so CLI warns "headers already sent". Swallow only that.
    set_error_handler(fn(int $no, string $msg) => str_contains($msg, 'headers already sent'), E_WARNING);
    ob_start();
    try {
        $result = $fn();
    } finally {
        $out = (string)ob_get_clean();
        restore_error_handler();
        stream_wrapper_restore('php');
    }
    return [$result, $out];
}

/**
 * Fresh DB + the whole object graph both paths need.
 * @return array<string,mixed>
 */
function _de_setup(): array
{
    $file = sys_get_temp_dir() . '/otack-de-' . uniqid() . '.sqlite';
    $pdo  = Connection::open($file);
    Migrations::run(new SchemaBootstrap($pdo));

    $users = new UserRepository($pdo);
    $adminId = $users->create('admin@x', 'h', 'Owner');
    $bobId   = $users->create('bob@x', 'h', 'Bob');
    $pdo->prepare("UPDATE users SET role = 'admin', status = 'approved' WHERE id = ?")->execute([$adminId]);
    $pdo->prepare("UPDATE users SET status = 'approved' WHERE id = ?")->execute([$bobId]);
    $admin = $users->findById($adminId);

    $projects = new ProjectRepository($pdo);
    $members  = new ProjectMemberRepository($pdo);
    $columns  = new TaskColumnRepository($pdo);
    $tasks    = new TaskRepository($pdo);
    $comments = new CommentRepository($pdo);
    $activity = new ActivityLogRepository($pdo);

    $pid = $projects->create('Board', null, $adminId);
    $members->add($pid, $adminId, 'owner');
    $columns->seedDefaults($pid);
    $cols = $columns->listForProject($pid);
    $board = array_values(array_filter($cols, fn($c) => (int)($c['is_backlog'] ?? 0) === 0));
    usort($board, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
    $todo = $board[0];
    $next = $board[1];
    $taskId = $tasks->create($pid, (int)$todo['id'], 'Ship it', $adminId);

    $bus = new EventBus();
    $recorded = new \ArrayObject();
    foreach (['comment.created', 'comment.deleted', 'task.created', 'task.deleted',
              'task.status_changed', 'task.assignee_changed', 'task.linked', 'task.unlinked',
              'project.created', 'project.updated', 'project.deleted'] as $ev) {
        $bus->on($ev, function (array $p) use ($recorded, $ev) { $recorded[] = [$ev, $p]; });
    }
    $de = new DomainEvents($bus, $activity, $projects, $tasks, $users, $comments);

    $taskLinks = new TaskLinkRepository($pdo);
    $formSubs  = new FormSubmissionRepository($pdo);
    $polls     = new PollRepository($pdo);
    $services = [
        'projects'         => $projects,
        'members'          => $members,
        'columns'          => $columns,
        'tasks'            => $tasks,
        'task_links'       => $taskLinks,
        'comments'         => $comments,
        'attachments'      => new AttachmentRepository($pdo),
        'tags'             => new TagRepository($pdo),
        'form_submissions' => $formSubs,
        'polls'            => $polls,
        'users'            => $users,
        'domain_events'    => $de,
        'entity_lifecycle' => new EntityLifecycle($tasks, $projects, $columns, $taskLinks, $formSubs, $polls),
    ];
    return compact('file', 'pdo', 'admin', 'bobId', 'pid', 'todo', 'next', 'taskId', 'recorded', 'de', 'services');
}

function _de_api(array $s, string $class): object
{
    $fq = 'App\\Api\\V1\\Handlers\\' . $class;
    return new $fq($s['pdo'], $s['services'], ['user' => $s['admin'], 'token' => ['id' => 1]]);
}

function _de_view(): \App\View\Renderer
{
    return new \App\View\Renderer(APP_ROOT . '/views');
}

function _de_web_comment(array $s): \App\Controller\CommentController
{
    $x = $s['services'];
    return new \App\Controller\CommentController(_de_view(), $s['admin'],
        $x['comments'], $x['members'], $x['tasks'], $x['projects'], $x['users'], $s['de']);
}

function _de_web_task(array $s): \App\Controller\TaskController
{
    $x = $s['services'];
    $store = [];
    return new \App\Controller\TaskController(_de_view(), $s['admin'],
        $x['tasks'], $x['projects'], $x['members'], $x['columns'], $x['task_links'], $x['tags'],
        $x['users'], $x['comments'], $x['attachments'], $x['entity_lifecycle'],
        $s['de'], new \App\Http\Csrf($store), $s['pdo']);
}

function _de_web_project(array $s): \App\Controller\ProjectController
{
    $x = $s['services'];
    return new \App\Controller\ProjectController(_de_view(), $s['admin'],
        $x['projects'], $x['members'], $x['columns'], $x['tasks'], $x['tags'], $x['users'],
        $x['comments'], $x['attachments'], $x['entity_lifecycle'], $s['de'], $s['pdo']);
}

function _de_req(string $method, string $path): Request
{
    return new Request($method, $path, [], [], [], [], []);
}

/** @return list<array> payloads recorded for $event */
function _de_fired(array $s, string $event): array
{
    return array_values(array_map(fn($r) => $r[1],
        array_filter($s['recorded']->getArrayCopy(), fn($r) => $r[0] === $event)));
}

function _de_activity(array $s, string $event): array
{
    $st = $s['pdo']->prepare('SELECT * FROM activity_log WHERE event = ? ORDER BY id');
    $st->execute([$event]);
    return $st->fetchAll(\PDO::FETCH_ASSOC);
}

function _de_cleanup(array $s): void
{
    @unlink($s['file']); @unlink($s['file'] . '-wal'); @unlink($s['file'] . '-shm');
}

// ─── comment.created ────────────────────────────────────────────────────────

it('API POST /comments fires comment.created once with the web payload shape', function () {
    $s = _de_setup();
    [$resp] = _de_with_body(
        ['entity' => 'task', 'entity_id' => $s['taskId'], 'body' => 'Looks **good**'],
        fn() => _de_api($s, 'CommentsHandler')->create(_de_req('POST', '/api/v1/comments'))
    );
    assert_eq(201, $resp['status']);
    $fired = _de_fired($s, 'comment.created');
    assert_eq(1, count($fired), 'comment.created must fire exactly once');
    assert_eq([
        'comment_id'      => (int)json_decode($resp['body'], true)['id'],
        'entity_type'     => 'task',
        'entity_id'       => $s['taskId'],
        'entity_label'    => 'task',
        'target_name'     => 'Ship it',
        'author'          => 'Owner',
        'body_text'       => 'Looks **good**',
        'url'             => \abs_url('/tasks/' . $s['taskId']),
        'reply_to_author' => null,
    ], $fired[0]);
    $rows = _de_activity($s, 'comment.created');
    assert_eq(1, count($rows), 'one comment.created activity row');
    assert_eq($s['pid'], (int)$rows[0]['project_id']);
    assert_eq($s['taskId'], (int)$rows[0]['task_id']);
    assert_eq((int)$s['admin']['id'], (int)$rows[0]['actor_id'], 'actor is the token user');
    _de_cleanup($s);
});

it('API reply comment carries reply_to_author like the web path', function () {
    $s = _de_setup();
    $rootId = $s['services']['comments']->create('task', $s['taskId'], $s['bobId'], 'root', null);
    _de_with_body(
        ['entity' => 'task', 'entity_id' => $s['taskId'], 'body' => 'reply', 'parent_id' => $rootId],
        fn() => _de_api($s, 'CommentsHandler')->create(_de_req('POST', '/api/v1/comments'))
    );
    $fired = _de_fired($s, 'comment.created');
    assert_eq(1, count($fired));
    assert_eq('Bob', $fired[0]['reply_to_author']);
    _de_cleanup($s);
});

it('web POST /comments still fires comment.created once with the same payload keys', function () {
    $s = _de_setup();
    [, $out] = _de_with_body(
        ['entity_type' => 'task', 'entity_id' => $s['taskId'], 'body' => 'From the browser'],
        fn() => _de_web_comment($s)->create(_de_req('POST', '/comments'))
    );
    $json = json_decode($out, true);
    assert_true(!empty($json['ok']), 'web create ok: ' . $out);
    $fired = _de_fired($s, 'comment.created');
    assert_eq(1, count($fired), 'web comment.created must fire exactly once');
    assert_eq((int)$json['comment']['id'], $fired[0]['comment_id']);
    assert_eq('Owner', $fired[0]['author']);
    assert_eq(\abs_url('/tasks/' . $s['taskId']), $fired[0]['url']);
    assert_eq(['comment_id', 'entity_type', 'entity_id', 'entity_label', 'target_name', 'author',
               'body_text', 'url', 'reply_to_author'], array_keys($fired[0]));
    assert_eq(1, count(_de_activity($s, 'comment.created')));
    _de_cleanup($s);
});

it('API DELETE /comments fires comment.deleted once', function () {
    $s = _de_setup();
    $cid = $s['services']['comments']->create('task', $s['taskId'], (int)$s['admin']['id'], 'bye', null);
    $resp = _de_api($s, 'CommentsHandler')->destroy(_de_req('DELETE', '/api/v1/comments/' . $cid), ['id' => $cid]);
    assert_eq(204, $resp['status']);
    $fired = _de_fired($s, 'comment.deleted');
    assert_eq(1, count($fired));
    assert_eq($cid, $fired[0]['comment_id']);
    assert_eq('Owner', $fired[0]['actor_name']);
    assert_eq('Ship it', $fired[0]['target_name']);
    assert_eq(1, count(_de_activity($s, 'comment.deleted')));
    _de_cleanup($s);
});

// ─── task.status_changed ────────────────────────────────────────────────────

it('API POST /tasks/{id}/move fires task.status_changed once with column name', function () {
    $s = _de_setup();
    $colId = (int)$s['next']['id'];
    [$resp] = _de_with_body(
        ['column_id' => $colId, 'position' => 1],
        fn() => _de_api($s, 'TasksHandler')->move(_de_req('POST', '/api/v1/tasks/x/move'), ['id' => $s['taskId']])
    );
    assert_eq(200, $resp['status']);
    $fired = _de_fired($s, 'task.status_changed');
    assert_eq(1, count($fired), 'task.status_changed must fire exactly once');
    assert_eq([
        'task_id'    => $s['taskId'],
        'title'      => 'Ship it',
        'new_column' => (string)$s['next']['name'],
        'actor_name' => 'Owner',
        'url'        => \abs_url('/tasks/' . $s['taskId']),
    ], $fired[0]);
    $rows = _de_activity($s, 'task.status_changed');
    assert_eq(1, count($rows));
    assert_eq(['column_id' => $colId, 'new_column' => (string)$s['next']['name']], json_decode($rows[0]['meta'], true));
    _de_cleanup($s);
});

it('API PATCH column_id fires task.status_changed once, unchanged column fires nothing', function () {
    $s = _de_setup();
    $h = fn() => _de_api($s, 'TasksHandler')->update(_de_req('PATCH', '/api/v1/tasks/x'), ['id' => $s['taskId']]);
    _de_with_body(['column_id' => (int)$s['next']['id']], $h);
    assert_eq(1, count(_de_fired($s, 'task.status_changed')));
    _de_with_body(['column_id' => (int)$s['next']['id']], $h);
    assert_eq(1, count(_de_fired($s, 'task.status_changed')), 're-sending the same column must stay silent');
    _de_cleanup($s);
});

it('web POST /tasks/{id}/move still fires task.status_changed once', function () {
    $s = _de_setup();
    _de_with_body(
        ['column_id' => (int)$s['next']['id'], 'position' => 1],
        fn() => _de_web_task($s)->move(_de_req('POST', '/tasks/x/move'), ['id' => $s['taskId']])
    );
    $fired = _de_fired($s, 'task.status_changed');
    assert_eq(1, count($fired));
    assert_eq((string)$s['next']['name'], $fired[0]['new_column']);
    assert_eq(['task_id', 'title', 'new_column', 'actor_name', 'url'], array_keys($fired[0]));
    assert_eq(1, count(_de_activity($s, 'task.status_changed')));
    _de_cleanup($s);
});

// ─── task.assignee_changed ──────────────────────────────────────────────────

it('API PATCH assignee_id fires task.assignee_changed once, same assignee again is silent', function () {
    $s = _de_setup();
    $h = fn() => _de_api($s, 'TasksHandler')->update(_de_req('PATCH', '/api/v1/tasks/x'), ['id' => $s['taskId']]);
    [$resp] = _de_with_body(['assignee_id' => $s['bobId']], $h);
    assert_eq(200, $resp['status']);
    $fired = _de_fired($s, 'task.assignee_changed');
    assert_eq(1, count($fired), 'task.assignee_changed must fire exactly once');
    assert_eq([
        'task_id'       => $s['taskId'],
        'title'         => 'Ship it',
        'assignee_name' => 'Bob',
        'actor_name'    => 'Owner',
        'url'           => \abs_url('/tasks/' . $s['taskId']),
    ], $fired[0]);
    _de_with_body(['assignee_id' => $s['bobId']], $h);
    assert_eq(1, count(_de_fired($s, 'task.assignee_changed')));
    _de_with_body(['assignee_id' => null], $h);
    $fired = _de_fired($s, 'task.assignee_changed');
    assert_eq(2, count($fired));
    assert_eq('Unassigned', $fired[1]['assignee_name']);
    _de_cleanup($s);
});

it('web PATCH assignee_id still fires task.assignee_changed once', function () {
    $s = _de_setup();
    _de_with_body(
        ['assignee_id' => $s['bobId']],
        fn() => _de_web_task($s)->update(_de_req('PATCH', '/tasks/x'), ['id' => $s['taskId']])
    );
    $fired = _de_fired($s, 'task.assignee_changed');
    assert_eq(1, count($fired));
    assert_eq('Bob', $fired[0]['assignee_name']);
    assert_eq(['task_id', 'title', 'assignee_name', 'actor_name', 'url'], array_keys($fired[0]));
    _de_cleanup($s);
});

// ─── the rest of the API surface, one event each ────────────────────────────

it('API task create / link / unlink / delete each fire their event once', function () {
    $s = _de_setup();
    [$resp] = _de_with_body(['title' => 'Second'],
        fn() => _de_api($s, 'TasksHandler')->createInProject(_de_req('POST', '/api/v1/projects/x/tasks'), ['id' => $s['pid']]));
    assert_eq(201, $resp['status']);
    $newId = (int)json_decode($resp['body'], true)['id'];
    assert_eq(1, count(_de_fired($s, 'task.created')));
    assert_eq('Board', _de_fired($s, 'task.created')[0]['project_name']);
    assert_eq(1, count(_de_activity($s, 'task.created')));

    $api = _de_api($s, 'TasksHandler');
    _de_with_body(['other_id' => $newId],
        fn() => $api->link(_de_req('POST', '/api/v1/tasks/x/links'), ['id' => $s['taskId']]));
    // A duplicate link is a no-op in the repo — must not notify again.
    _de_with_body(['other_id' => $newId],
        fn() => $api->link(_de_req('POST', '/api/v1/tasks/x/links'), ['id' => $s['taskId']]));
    assert_eq(1, count(_de_fired($s, 'task.linked')));
    assert_eq('Second', _de_fired($s, 'task.linked')[0]['linked_title']);

    $api->unlink(_de_req('DELETE', '/api/v1/tasks/x/links/y'), ['id' => $s['taskId'], 'otherId' => $newId]);
    assert_eq(1, count(_de_fired($s, 'task.unlinked')));

    $resp = $api->destroy(_de_req('DELETE', '/api/v1/tasks/x'), ['id' => $newId]);
    assert_eq(204, $resp['status']);
    assert_eq(1, count(_de_fired($s, 'task.deleted')));
    assert_eq('Second', _de_fired($s, 'task.deleted')[0]['title']);
    _de_cleanup($s);
});

it('API project create / update / delete each fire their event once', function () {
    $s = _de_setup();
    $api = _de_api($s, 'ProjectsHandler');
    [$resp] = _de_with_body(['name' => 'From bot'], fn() => $api->create(_de_req('POST', '/api/v1/projects')));
    assert_eq(201, $resp['status']);
    $id = (int)json_decode($resp['body'], true)['id'];
    assert_eq(1, count(_de_fired($s, 'project.created')));
    assert_eq('From bot', _de_fired($s, 'project.created')[0]['name']);

    _de_with_body(['name' => 'Renamed'], fn() => $api->update(_de_req('PATCH', '/api/v1/projects/x'), ['id' => $id]));
    assert_eq(1, count(_de_fired($s, 'project.updated')));
    assert_eq('Renamed', _de_fired($s, 'project.updated')[0]['name']);

    $api->destroy(_de_req('DELETE', '/api/v1/projects/x'), ['id' => $id]);
    assert_eq(1, count(_de_fired($s, 'project.deleted')));
    assert_eq(1, count(_de_activity($s, 'project.deleted')));
    _de_cleanup($s);
});

it('API promote-to-project fires project.created once with the spin-off activity row', function () {
    $s = _de_setup();
    $resp = _de_api($s, 'TasksHandler')->promoteToProject(_de_req('POST', '/api/v1/tasks/x/promote-to-project'), ['id' => $s['taskId']]);
    assert_eq(200, $resp['status']);
    assert_eq(1, count(_de_fired($s, 'project.created')));
    $rows = _de_activity($s, 'project.created');
    assert_eq(1, count($rows));
    assert_eq($s['taskId'], json_decode($rows[0]['meta'], true)['from_task_id']);
    _de_cleanup($s);
});

// ─── data parity: delete detaches, move writes sub_status ───────────────────
// Same in-process harness, but asserting the rows each path leaves behind
// rather than the events it fires. Both surfaces go through EntityLifecycle.

/** Form submission converted into $taskId/$projectId + a poll summarised into $taskId. */
function _de_dependents(array $s, ?int $taskId, ?int $projectId): array
{
    $x = $s['services'];
    $formId = (new \App\Repository\FormRepository($s['pdo']))
        ->create('Lead form', null, [], [], (int)$s['admin']['id']);
    $subId = $x['form_submissions']->create($formId, ['name' => 'Ann'], []);
    $x['form_submissions']->setStatus($subId, $taskId !== null ? 'converted_task' : 'converted_project', $taskId, $projectId);
    $pollId = null;
    if ($taskId !== null) {
        $pollId = $x['polls']->create('Lunch?', null, [], [], (int)$s['admin']['id']);
        $x['polls']->attachSummaryTask($pollId, $taskId);
    }
    return ['sub' => $subId, 'poll' => $pollId];
}

function _de_row(array $s, string $table, int $id): array
{
    $st = $s['pdo']->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
}

function _de_col(array $s, string $name): int
{
    foreach ($s['services']['columns']->listForProject($s['pid']) as $c) {
        if ($c['name'] === $name) return (int)$c['id'];
    }
    throw new RuntimeException("no column $name");
}

it('API DELETE /tasks/{id} detaches submissions, poll summary and links like the web delete', function () {
    foreach (['api', 'web'] as $path) {
        $s = _de_setup();
        $other = $s['services']['tasks']->create($s['pid'], (int)$s['todo']['id'], 'Other', (int)$s['admin']['id']);
        $s['services']['task_links']->link($s['taskId'], $other, (int)$s['admin']['id']);
        $dep = _de_dependents($s, $s['taskId'], null);
        if ($path === 'api') {
            $resp = _de_api($s, 'TasksHandler')->destroy(_de_req('DELETE', '/api/v1/tasks/x'), ['id' => $s['taskId']]);
            assert_eq(204, $resp['status']);
        } else {
            [, $out] = _de_with_body([], fn() => _de_web_task($s)->delete(_de_req('DELETE', '/tasks/x'), ['id' => $s['taskId']]));
            assert_true(!empty(json_decode($out, true)['ok']), "web delete ok: $out");
        }
        $sub = _de_row($s, 'form_submissions', $dep['sub']);
        assert_eq(null, $sub['converted_task_id'], "$path: converted_task_id detached");
        assert_eq('new', $sub['status'], "$path: submission back to new");
        assert_eq(null, _de_row($s, 'polls', $dep['poll'])['summary_task_id'], "$path: poll summary detached");
        assert_eq([], $s['services']['task_links']->linkedIds($other), "$path: links removed");
        assert_eq(1, count(_de_fired($s, 'task.deleted')), "$path: task.deleted once");
        _de_cleanup($s);
    }
});

it('API DELETE /projects/{id} detaches converted submissions like the web delete', function () {
    foreach (['api', 'web'] as $path) {
        $s = _de_setup();
        $dep = _de_dependents($s, null, $s['pid']);
        if ($path === 'api') {
            $resp = _de_api($s, 'ProjectsHandler')->destroy(_de_req('DELETE', '/api/v1/projects/x'), ['id' => $s['pid']]);
            assert_eq(204, $resp['status']);
        } else {
            [, $out] = _de_with_body([], fn() => _de_web_project($s)->delete(_de_req('DELETE', '/projects/x'), ['id' => $s['pid']]));
            assert_true(!empty(json_decode($out, true)['ok']), "web delete ok: $out");
        }
        assert_eq(null, $s['services']['projects']->findById($s['pid']), "$path: project gone");
        $sub = _de_row($s, 'form_submissions', $dep['sub']);
        assert_eq(null, $sub['converted_project_id'], "$path: converted_project_id detached");
        assert_eq('new', $sub['status'], "$path: submission back to new");
        assert_eq(1, count(_de_fired($s, 'project.deleted')), "$path: project.deleted once");
        _de_cleanup($s);
    }
});

it('API move / PATCH column_id set the same sub_status as the web move', function () {
    // [from column, to column, expected sub_status after the move]
    $steps = [
        ['To Do', 'Done', null],
        ['Done', 'To Do', 'reopened'],
        ['To Do', 'In Progress', null],
        ['In Progress', 'To Do', 'returned'],
        ['To Do', 'Backlog', null],
        ['Backlog', 'To Do', null],
    ];
    foreach (['web-move', 'api-move', 'api-patch'] as $path) {
        $s = _de_setup();
        $tid = $s['taskId'];
        foreach ($steps as [$from, $to, $want]) {
            $col = _de_col($s, $to);
            $body = ['column_id' => $col, 'position' => 1];
            if ($path === 'web-move') {
                [, $out] = _de_with_body($body, fn() => _de_web_task($s)->move(_de_req('POST', '/tasks/x/move'), ['id' => $tid]));
                $json = json_decode($out, true);
                assert_true(array_key_exists('sub_status', $json), "web move returns sub_status: $out");
                $got = $json['sub_status'];
            } else {
                $h = _de_api($s, 'TasksHandler');
                [$resp] = _de_with_body($body, fn() => $path === 'api-move'
                    ? $h->move(_de_req('POST', '/api/v1/tasks/x/move'), ['id' => $tid])
                    : $h->update(_de_req('PATCH', '/api/v1/tasks/x'), ['id' => $tid]));
                assert_eq(200, $resp['status'], "$path {$from}→{$to}");
                $got = json_decode($resp['body'], true)['sub_status'] ?? null;
            }
            assert_eq($want, $got, "$path {$from}→{$to} response sub_status");
            assert_eq($want, $s['services']['tasks']->findById($tid)['sub_status'], "$path {$from}→{$to} stored sub_status");
            assert_eq($col, (int)$s['services']['tasks']->findById($tid)['column_id'], "$path {$from}→{$to} column");
        }
        _de_cleanup($s);
    }
});

it('API PATCH: explicit sub_status in the body wins over the column rule; same column keeps it', function () {
    $s = _de_setup();
    $tid = $s['taskId'];
    $h = fn() => _de_api($s, 'TasksHandler')->update(_de_req('PATCH', '/api/v1/tasks/x'), ['id' => $tid]);
    _de_with_body(['column_id' => _de_col($s, 'Done')], $h);
    [$resp] = _de_with_body(['column_id' => (int)$s['todo']['id'], 'sub_status' => 'custom'], $h);
    assert_eq('custom', json_decode($resp['body'], true)['sub_status']);
    // Re-sending the current column is not a column change — badge untouched.
    [$resp] = _de_with_body(['column_id' => (int)$s['todo']['id']], $h);
    assert_eq('custom', json_decode($resp['body'], true)['sub_status']);
    _de_cleanup($s);
});

it('web and API move refuse a column from another project without writing', function () {
    $s = _de_setup();
    $x = $s['services'];
    $otherPid = $x['projects']->create('Other', null, (int)$s['admin']['id']);
    $x['columns']->seedDefaults($otherPid);
    $foreign = (int)$x['columns']->listForProject($otherPid)[0]['id'];
    $before = (int)$x['tasks']->findById($s['taskId'])['column_id'];

    [$resp] = _de_with_body(['column_id' => $foreign],
        fn() => _de_api($s, 'TasksHandler')->move(_de_req('POST', '/api/v1/tasks/x/move'), ['id' => $s['taskId']]));
    assert_eq(422, $resp['status']);
    [, $out] = _de_with_body(['column_id' => $foreign],
        fn() => _de_web_task($s)->move(_de_req('POST', '/tasks/x/move'), ['id' => $s['taskId']]));
    assert_true(isset(json_decode($out, true)['error']), "web move must refuse: $out");
    assert_eq($before, (int)$x['tasks']->findById($s['taskId'])['column_id'], 'column unchanged');
    assert_eq(0, count(_de_fired($s, 'task.status_changed')), 'nothing notified');
    _de_cleanup($s);
});

it('project delete (API and web) detaches what pointed at its tasks before the cascade', function () {
    foreach (['api', 'web'] as $path) {
        $s = _de_setup();
        $x = $s['services'];
        $otherPid = $x['projects']->create('Elsewhere', null, (int)$s['admin']['id']);
        $x['columns']->seedDefaults($otherPid);
        $outside = $x['tasks']->create($otherPid, (int)$x['columns']->listForProject($otherPid)[1]['id'], 'Outside', (int)$s['admin']['id']);
        $x['task_links']->link($outside, $s['taskId'], (int)$s['admin']['id']);
        $dep = _de_dependents($s, $s['taskId'], null);
        if ($path === 'api') {
            $resp = _de_api($s, 'ProjectsHandler')->destroy(_de_req('DELETE', '/api/v1/projects/x'), ['id' => $s['pid']]);
            assert_eq(204, $resp['status']);
        } else {
            [, $out] = _de_with_body([], fn() => _de_web_project($s)->delete(_de_req('DELETE', '/projects/x'), ['id' => $s['pid']]));
            assert_true(!empty(json_decode($out, true)['ok']), "web delete ok: $out");
        }
        assert_eq(null, $x['tasks']->findById($s['taskId']), "$path: task gone");
        $sub = _de_row($s, 'form_submissions', $dep['sub']);
        assert_eq(null, $sub['converted_task_id'], "$path: converted_task_id detached");
        assert_eq('new', $sub['status'], "$path: submission back to new");
        assert_eq(null, _de_row($s, 'polls', $dep['poll'])['summary_task_id'], "$path: poll summary detached");
        assert_eq([], $x['task_links']->linkedIds($outside), "$path: cross-project link removed");
        _de_cleanup($s);
    }
});
