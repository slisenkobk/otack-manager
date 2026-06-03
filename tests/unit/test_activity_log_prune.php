<?php
declare(strict_types=1);

use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;

/**
 * Smoke-tests `ActivityLogRepository::pruneBefore()` — the operator action
 * wired into the Compass cache tab (O-5). The fixture writes rows with
 * explicit `created_at` so we can prove the cutoff is exclusive.
 */
it('pruneBefore deletes rows older than cutoff and leaves newer rows', function () {
    $db = sys_get_temp_dir() . '/otack-activity-prune-' . uniqid() . '.sqlite';
    $pdo = Connection::open($db);
    Migrations::run(new SchemaBootstrap($pdo));
    register_shutdown_function(static function () use ($db) { @unlink($db); });

    $users = new UserRepository($pdo);
    $uid = $users->create('prune@x', 'h', 'P');

    // Hand-insert with explicit created_at to control time. We bypass
    // ::log() because it stamps `now()`.
    $stmt = $pdo->prepare(
        'INSERT INTO activity_log (event, actor_id, summary, created_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute(['e.old1',  $uid, 'old1',  '2020-01-01 00:00:00']);
    $stmt->execute(['e.old2',  $uid, 'old2',  '2024-01-01 00:00:00']);
    $stmt->execute(['e.edge',  $uid, 'edge',  '2025-06-01 12:00:00']);
    $stmt->execute(['e.new1',  $uid, 'new1',  '2026-01-01 00:00:00']);

    $repo = new ActivityLogRepository($pdo);
    $deleted = $repo->pruneBefore('2025-06-01 12:00:00');

    // `<` is strict, so the row exactly at the cutoff survives.
    assert_eq(2, $deleted);

    $rows = $pdo->query('SELECT summary FROM activity_log ORDER BY created_at')->fetchAll(\PDO::FETCH_ASSOC);
    assert_eq(2, count($rows));
    assert_eq('edge', $rows[0]['summary']);
    assert_eq('new1', $rows[1]['summary']);
});

it('pruneBefore returns 0 when no rows match', function () {
    $db = sys_get_temp_dir() . '/otack-activity-prune-empty-' . uniqid() . '.sqlite';
    $pdo = Connection::open($db);
    Migrations::run(new SchemaBootstrap($pdo));
    register_shutdown_function(static function () use ($db) { @unlink($db); });

    $repo = new ActivityLogRepository($pdo);
    assert_eq(0, $repo->pruneBefore('2000-01-01 00:00:00'));
});
