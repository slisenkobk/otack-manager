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

it('TaskRepository::listForProjectAfterId paginates by id and applies filters', function () {
    [$pdo, $db, $uid, $pid, $cols] = _new_task_setup();
    $repo = new TaskRepository($pdo);
    $c0 = (int)$cols[0]['id']; $c1 = (int)$cols[1]['id'];
    $ids = [];
    for ($i = 0; $i < 6; $i++) {
        $ids[] = $repo->create($pid, $i % 2 === 0 ? $c0 : $c1, "T$i", $uid);
    }
    $page1 = $repo->listForProjectAfterId($pid, [], 0, 3);
    assert_eq(3, count($page1));
    assert_eq($ids[0], (int)$page1[0]['id']);
    $page2 = $repo->listForProjectAfterId($pid, [], (int)end($page1)['id'], 3);
    assert_eq(3, count($page2));
    assert_eq($ids[3], (int)$page2[0]['id']);

    // column_id filter
    $colFiltered = $repo->listForProjectAfterId($pid, ['column_id' => $c0], 0, 100);
    assert_eq(3, count($colFiltered));
    foreach ($colFiltered as $r) {
        assert_eq($c0, (int)$r['column_id']);
    }

    // search filter
    $found = $repo->listForProjectAfterId($pid, ['search' => 'T4'], 0, 100);
    assert_eq(1, count($found));
    assert_eq('T4', $found[0]['title']);
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

it('update() persists agent_state and agent_state_at', function () {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    $base = dirname(__DIR__, 2) . '/system/Database/migrations/';
    apply_migration($pdo, $base . '20260522_000_users.php');
    apply_migration($pdo, $base . '20260522_010_projects.php');
    apply_migration($pdo, $base . '20260522_030_task_columns.php');
    apply_migration($pdo, $base . '20260525_010_task_columns_backlog.php');
    apply_migration($pdo, $base . '20260522_040_tasks.php');
    apply_migration($pdo, $base . '20260526_020_tasks_sub_status.php');
    apply_migration($pdo, $base . '20260526_030_tasks_priority.php');
    apply_migration($pdo, $base . '20260827_000_tasks_agent_state.php');

    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1,'U','u@x','x','admin','approved','2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO projects (id, name, slug, status, created_by, created_at, updated_at) VALUES (1,'P','p','active',1,'2026-01-01 00:00:00','2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO task_columns (id, project_id, name, position, is_done, is_backlog) VALUES (1,1,'To Do',0,0,0)");
    $pdo->exec("INSERT INTO tasks (id, project_id, column_id, title, position, created_by, created_at, updated_at) VALUES (1,1,1,'T',1024,1,'2026-01-01 00:00:00','2026-01-01 00:00:00')");

    $repo = new \App\Repository\TaskRepository($pdo);
    $repo->update(1, ['agent_state' => 'researching', 'agent_state_at' => '2026-08-27 10:00:00']);

    $row = $pdo->query('SELECT agent_state, agent_state_at FROM tasks WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
    assert_eq('researching', $row['agent_state']);
    assert_eq('2026-08-27 10:00:00', $row['agent_state_at']);
});

it('update() ignores unknown columns', function () {
    // Guards the $allowed allowlist: a typo'd field must not reach SQL.
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    $base = dirname(__DIR__, 2) . '/system/Database/migrations/';
    apply_migration($pdo, $base . '20260522_000_users.php');
    apply_migration($pdo, $base . '20260522_010_projects.php');
    apply_migration($pdo, $base . '20260522_030_task_columns.php');
    apply_migration($pdo, $base . '20260525_010_task_columns_backlog.php');
    apply_migration($pdo, $base . '20260522_040_tasks.php');
    apply_migration($pdo, $base . '20260827_000_tasks_agent_state.php');
    $pdo->exec("INSERT INTO users (id, name, email, password_hash, role, status, created_at) VALUES (1,'U','u@x','x','admin','approved','2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO projects (id, name, slug, status, created_by, created_at, updated_at) VALUES (1,'P','p','active',1,'2026-01-01 00:00:00','2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO task_columns (id, project_id, name, position, is_done, is_backlog) VALUES (1,1,'To Do',0,0,0)");
    $pdo->exec("INSERT INTO tasks (id, project_id, column_id, title, position, created_by, created_at, updated_at) VALUES (1,1,1,'T',1024,1,'2026-01-01 00:00:00','2026-01-01 00:00:00')");

    $repo = new \App\Repository\TaskRepository($pdo);
    $repo->update(1, ['agent_stat' => 'typo']);   // no exception, no write
    $row = $pdo->query('SELECT agent_state FROM tasks WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
    assert_eq(null, $row['agent_state']);
});
