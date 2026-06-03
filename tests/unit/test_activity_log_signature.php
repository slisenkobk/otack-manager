<?php
use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Repository\ActivityLogRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskColumnRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;

/**
 * Returns [PDO, userId, projectId, taskId] — fully wired so activity_log FKs
 * (actor_id → users, project_id → projects, task_id → tasks) are satisfied.
 *
 * @return array{0:\PDO,1:int,2:int,3:int}
 */
function _activityFixture(): array {
    $db = sys_get_temp_dir() . '/otack-activity-' . uniqid() . '.sqlite';
    $pdo = Connection::open($db);
    Migrations::run(new SchemaBootstrap($pdo));
    register_shutdown_function(static function () use ($db) { @unlink($db); });

    $users    = new UserRepository($pdo);
    $projects = new ProjectRepository($pdo);
    $cols     = new TaskColumnRepository($pdo);
    $tasks    = new TaskRepository($pdo);

    $userId    = $users->create('alog@x', 'h', 'A');
    $projectId = $projects->create('AlogProj', null, $userId);
    $cols->seedDefaults($projectId);
    $backlog   = $cols->findBacklog($projectId);
    $taskId    = $tasks->create($projectId, (int)$backlog['id'], 'task-fixture', $userId);

    return [$pdo, $userId, $projectId, $taskId];
}

it('log() accepts assoc-array signature equivalently to positional', function () {
    [$pdo, $uid, $pid, $tid] = _activityFixture();
    $repo = new ActivityLogRepository($pdo);

    $idPositional = $repo->log('task.created', $uid, $pid, $tid, 'positional', ['k' => 'v']);
    $idAssoc      = $repo->log([
        'event'      => 'task.created',
        'actor_id'   => $uid,
        'project_id' => $pid,
        'task_id'    => $tid,
        'summary'    => 'assoc',
        'meta'       => ['k' => 'v'],
    ]);

    assert_true($idPositional > 0);
    assert_true($idAssoc > 0);

    $rows = $pdo->query('SELECT event, summary FROM activity_log ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    assert_eq(2, count($rows));
    assert_eq('positional', $rows[0]['summary']);
    assert_eq('assoc', $rows[1]['summary']);
    assert_eq('task.created', $rows[0]['event']);
    assert_eq('task.created', $rows[1]['event']);
});

it('log() assoc-array allows partial fields (project_id null)', function () {
    [$pdo, $uid] = _activityFixture();
    $repo = new ActivityLogRepository($pdo);

    $id = $repo->log([
        'event'    => 'user.registered',
        'actor_id' => $uid,
        'summary'  => 'no-project',
    ]);
    assert_true($id > 0);

    $stmt = $pdo->prepare('SELECT event, actor_id, project_id, task_id, summary FROM activity_log WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    assert_eq('user.registered', $row['event']);
    assert_eq($uid, (int)$row['actor_id']);
    assert_true($row['project_id'] === null);
    assert_true($row['task_id'] === null);
    assert_eq('no-project', $row['summary']);
});
