<?php
use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Repository\UserRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\TaskColumnRepository;

function _new_project_setup(): array {
    $db = sys_get_temp_dir() . '/otack-proj-' . uniqid() . '.sqlite';
    $pdo = Connection::open($db);
    Migrations::run(new SchemaBootstrap($pdo));
    return [$pdo, $db];
}

it('ProjectRepository::create returns id and seeds slug', function () {
    [$pdo, $db] = _new_project_setup();
    $users = new UserRepository($pdo);
    $admin = $users->create('a@x', 'h', 'A');
    $projects = new ProjectRepository($pdo);
    $id = $projects->create('My Project', 'desc', $admin);
    assert_true($id > 0);
    $p = $projects->findById($id);
    assert_eq('my-project', $p['slug']);
    @unlink($db);
});

it('ProjectRepository::slugify ensures uniqueness via -2/-3 suffix', function () {
    [$pdo, $db] = _new_project_setup();
    $users = new UserRepository($pdo);
    $admin = $users->create('a@x', 'h', 'A');
    $projects = new ProjectRepository($pdo);
    $id1 = $projects->create('Same Name', null, $admin);
    $id2 = $projects->create('Same Name', null, $admin);
    $id3 = $projects->create('Same Name', null, $admin);
    assert_eq('same-name', $projects->findById($id1)['slug']);
    assert_eq('same-name-2', $projects->findById($id2)['slug']);
    assert_eq('same-name-3', $projects->findById($id3)['slug']);
    @unlink($db);
});

it('ProjectMemberRepository add/list/remove/isMember/isOwner', function () {
    [$pdo, $db] = _new_project_setup();
    $users = new UserRepository($pdo);
    $u1 = $users->create('a@x', 'h', 'A');
    $u2 = $users->create('b@x', 'h', 'B');
    $projects = new ProjectRepository($pdo);
    $pid = $projects->create('P', null, $u1);
    $members = new ProjectMemberRepository($pdo);
    $members->add($pid, $u1, 'owner');
    $members->add($pid, $u2);
    assert_true($members->isMember($pid, $u1));
    assert_true($members->isMember($pid, $u2));
    assert_true($members->isOwner($pid, $u1));
    assert_true(!$members->isOwner($pid, $u2));
    $list = $members->list($pid);
    assert_eq(2, count($list));
    $members->remove($pid, $u2);
    assert_true(!$members->isMember($pid, $u2));
    @unlink($db);
});

it('TaskColumnRepository seedDefaults creates 3 columns in correct order', function () {
    [$pdo, $db] = _new_project_setup();
    $users = new UserRepository($pdo);
    $u1 = $users->create('a@x', 'h', 'A');
    $projects = new ProjectRepository($pdo);
    $pid = $projects->create('P', null, $u1);
    $columns = new TaskColumnRepository($pdo);
    $columns->seedDefaults($pid);
    $cols = $columns->listForProject($pid);
    assert_eq(4, count($cols));
    assert_eq('Backlog', $cols[0]['name']);
    assert_eq('To Do', $cols[1]['name']);
    assert_eq('In Progress', $cols[2]['name']);
    assert_eq('Done', $cols[3]['name']);
    assert_eq(1, (int)$cols[0]['is_backlog']);
    assert_eq(1, (int)$cols[3]['is_done']);
    @unlink($db);
});
