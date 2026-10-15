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
use App\Service\{DomainEvents, EventBus};

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

    $services = [
        'projects'         => $projects,
        'members'          => $members,
        'columns'          => $columns,
        'tasks'            => $tasks,
        'task_links'       => new TaskLinkRepository($pdo),
        'comments'         => $comments,
        'attachments'      => new AttachmentRepository($pdo),
        'tags'             => new TagRepository($pdo),
        'form_submissions' => new FormSubmissionRepository($pdo),
        'polls'            => new PollRepository($pdo),
        'users'            => $users,
        'domain_events'    => $de,
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
        $x['users'], $x['comments'], $x['attachments'], $x['form_submissions'], $x['polls'],
        $s['de'], new \App\Http\Csrf($store), $s['pdo']);
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
