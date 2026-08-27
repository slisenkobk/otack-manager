<?php
use App\Service\RolePolicy;

it('canDeleteComment admin always', function () {
    $u = ['id' => 1, 'role' => 'admin'];
    $c = ['user_id' => 99];
    assert_true(RolePolicy::canDeleteComment($u, $c));
});

it('canDeleteComment author', function () {
    $u = ['id' => 7, 'role' => 'employee'];
    $c = ['user_id' => 7];
    assert_true(RolePolicy::canDeleteComment($u, $c));
});

it('canDeleteComment not author and not admin → false', function () {
    $u = ['id' => 7, 'role' => 'employee'];
    $c = ['user_id' => 8];
    assert_true(!RolePolicy::canDeleteComment($u, $c));
});

// ─── Role probes ──────────────────────────────────────────────────────────
it('isAdmin/Manager/Employee predicates', function () {
    assert_true( RolePolicy::isAdmin(['role' => 'admin']));
    assert_true(!RolePolicy::isAdmin(['role' => 'manager']));
    assert_true( RolePolicy::isManager(['role' => 'manager']));
    assert_true(!RolePolicy::isManager(['role' => 'admin']));
    assert_true( RolePolicy::isEmployee(['role' => 'employee']));
});

// ─── canCreateProject ────────────────────────────────────────────────────
it('canCreateProject: admin yes', function () {
    assert_true(RolePolicy::canCreateProject(['role' => 'admin']));
});
it('canCreateProject: manager yes', function () {
    assert_true(RolePolicy::canCreateProject(['role' => 'manager']));
});
it('canCreateProject: employee no', function () {
    assert_true(!RolePolicy::canCreateProject(['role' => 'employee']));
});

// ─── canEditProject (membership-aware) ───────────────────────────────────
function _rpPdo(): \PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_000_users.php');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_010_projects.php');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260522_020_project_members.php');
    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, status, created_at) VALUES (10, 'A', 'a@x', 'x', 'admin',    'approved', '2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, status, created_at) VALUES (11, 'M', 'm@x', 'x', 'manager',  'approved', '2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, status, created_at) VALUES (12, 'E', 'e@x', 'x', 'employee', 'approved', '2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO projects (id, name, slug, status, created_by, created_at, updated_at) VALUES (100, 'P', 'p', 'active', 11, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
    // The manager that created the project is recorded as its owner.
    $pdo->exec("INSERT INTO project_members (project_id, user_id, role) VALUES (100, 11, 'owner')");
    return $pdo;
}

it('canEditProject: admin always', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $project = ['id' => 100, 'created_by' => 11];
    assert_true(RolePolicy::canEditProject(['id'=>10,'role'=>'admin'], $project, $members));
});

it('canEditProject: owner manager yes', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $project = ['id' => 100, 'created_by' => 11];
    assert_true(RolePolicy::canEditProject(['id'=>11,'role'=>'manager'], $project, $members));
});

it('canEditProject: employee no', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $project = ['id' => 100, 'created_by' => 11];
    assert_true(!RolePolicy::canEditProject(['id'=>12,'role'=>'employee'], $project, $members));
});

it('canEditProject: non-owner manager no', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $project = ['id' => 100, 'created_by' => 11];
    // user 13 is a manager but never recorded as owner of project 100
    assert_true(!RolePolicy::canEditProject(['id'=>13,'role'=>'manager'], $project, $members));
});

// ─── canManage* family ────────────────────────────────────────────────────
it('canManageForms: admin yes, manager yes, employee no', function () {
    assert_true( RolePolicy::canManageForms(['role'=>'admin']));
    assert_true( RolePolicy::canManageForms(['role'=>'manager']));
    assert_true(!RolePolicy::canManageForms(['role'=>'employee']));
});
it('canManagePolls: admin yes, manager yes, employee no', function () {
    assert_true( RolePolicy::canManagePolls(['role'=>'admin']));
    assert_true( RolePolicy::canManagePolls(['role'=>'manager']));
    assert_true(!RolePolicy::canManagePolls(['role'=>'employee']));
});
it('canManageLinks: same matrix', function () {
    assert_true( RolePolicy::canManageLinks(['role'=>'admin']));
    assert_true( RolePolicy::canManageLinks(['role'=>'manager']));
    assert_true(!RolePolicy::canManageLinks(['role'=>'employee']));
});
it('canViewFormsData: admin yes, manager yes, employee no', function () {
    assert_true( RolePolicy::canViewFormsData(['role'=>'admin']));
    assert_true( RolePolicy::canViewFormsData(['role'=>'manager']));
    assert_true(!RolePolicy::canViewFormsData(['role'=>'employee']));
});
it('canManageSettings: admin only', function () {
    assert_true( RolePolicy::canManageSettings(['role'=>'admin']));
    assert_true(!RolePolicy::canManageSettings(['role'=>'manager']));
    assert_true(!RolePolicy::canManageSettings(['role'=>'employee']));
});
it('canPromoteTaskToProject: admin yes, manager yes, employee no', function () {
    assert_true( RolePolicy::canPromoteTaskToProject(['role'=>'admin']));
    assert_true( RolePolicy::canPromoteTaskToProject(['role'=>'manager']));
    assert_true(!RolePolicy::canPromoteTaskToProject(['role'=>'employee']));
});

// ─── canEditTask ──────────────────────────────────────────────────────────
it('canEditTask: admin always', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $task = ['id' => 1, 'project_id' => 100, 'created_by' => 12];
    assert_true(RolePolicy::canEditTask(['id'=>10,'role'=>'admin'], $task, $members));
});
it('canEditTask: author yes (employee on own task)', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $task = ['id' => 1, 'project_id' => 100, 'created_by' => 12];
    assert_true(RolePolicy::canEditTask(['id'=>12,'role'=>'employee'], $task, $members));
});
it('canEditTask: manager-owner can edit any task in their project', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $task = ['id' => 1, 'project_id' => 100, 'created_by' => 12];
    assert_true(RolePolicy::canEditTask(['id'=>11,'role'=>'manager'], $task, $members));
});
it('canEditTask: non-author employee no', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $task = ['id' => 1, 'project_id' => 100, 'created_by' => 99];
    assert_true(!RolePolicy::canEditTask(['id'=>12,'role'=>'employee'], $task, $members));
});

it('canEditTask: assignee can edit a task they did not create', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $task = ['id' => 1, 'project_id' => 100, 'created_by' => 11, 'assignee_id' => 12];
    assert_true(RolePolicy::canEditTask(['id' => 12, 'role' => 'employee'], $task, $members));
});

it('canEditTask: employee assigned to someone else still cannot edit', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $task = ['id' => 1, 'project_id' => 100, 'created_by' => 11, 'assignee_id' => 99];
    assert_true(!RolePolicy::canEditTask(['id' => 12, 'role' => 'employee'], $task, $members));
});

it('canEditTask: unassigned task gives no one extra rights', function () {
    $pdo = _rpPdo();
    $members = new \App\Repository\ProjectMemberRepository($pdo);
    $task = ['id' => 1, 'project_id' => 100, 'created_by' => 11, 'assignee_id' => null];
    assert_true(!RolePolicy::canEditTask(['id' => 12, 'role' => 'employee'], $task, $members));
});
