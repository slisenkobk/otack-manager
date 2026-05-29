<?php
use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Repository\UserRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskColumnRepository;
use App\Repository\TaskRepository;

function _new_task_setup(): array {
    $db = sys_get_temp_dir() . '/otack-task-' . uniqid() . '.sqlite';
    $pdo = Connection::open($db);
    Migrations::run(new SchemaBootstrap($pdo));
    $users = new UserRepository($pdo);
    $u = $users->create('a@x', 'h', 'A');
    $proj = new ProjectRepository($pdo);
    $pid = $proj->create('P', null, $u);
    $cols = new TaskColumnRepository($pdo);
    $cols->seedDefaults($pid);
    $colsList = $cols->listForProject($pid);
    return [$pdo, $db, $u, $pid, $colsList];
}

it('TaskRepository::create returns id and positions at bottom', function () {
    [$pdo, $db, $uid, $pid, $cols] = _new_task_setup();
    $repo = new TaskRepository($pdo);
    $colId = (int)$cols[0]['id'];
    $id1 = $repo->create($pid, $colId, 'First', $uid);
    $id2 = $repo->create($pid, $colId, 'Second', $uid);
    $t1 = $repo->findById($id1);
    $t2 = $repo->findById($id2);
    assert_true($t2['position'] > $t1['position']);
    @unlink($db);
});

it('TaskRepository::listForProject groups by column', function () {
    [$pdo, $db, $uid, $pid, $cols] = _new_task_setup();
    $repo = new TaskRepository($pdo);
    $c0 = (int)$cols[0]['id']; $c1 = (int)$cols[1]['id'];
    $repo->create($pid, $c0, 'A', $uid);
    $repo->create($pid, $c1, 'B', $uid);
    $repo->create($pid, $c0, 'C', $uid);
    $grouped = $repo->listForProject($pid);
    assert_eq(2, count($grouped[$c0]));
    assert_eq(1, count($grouped[$c1]));
    @unlink($db);
});

it('TaskRepository::move updates column_id, position, updated_at', function () {
    [$pdo, $db, $uid, $pid, $cols] = _new_task_setup();
    $repo = new TaskRepository($pdo);
    $c0 = (int)$cols[0]['id']; $c2 = (int)$cols[2]['id'];
    $id = $repo->create($pid, $c0, 'X', $uid);
    $before = $repo->findById($id);
    usleep(10000);
    $repo->move($id, $c2, 5000.0);
    $after = $repo->findById($id);
    assert_eq($c2, (int)$after['column_id']);
    assert_eq(5000.0, (float)$after['position']);
    assert_true($after['updated_at'] > $before['updated_at']);
    @unlink($db);
});
