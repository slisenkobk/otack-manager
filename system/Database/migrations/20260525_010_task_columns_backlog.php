<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->alterTable('task_columns', function (Blueprint $t) {
        $t->boolean('is_backlog')->default(false);
    });

    // Seed a Backlog column for each project that doesn't have one.
    // (The schema_migrations table guarantees this runs at most once.)
    $pdo = $schema->pdo();
    $projects = $pdo->query('SELECT id FROM projects')->fetchAll(\PDO::FETCH_COLUMN);
    $check = $pdo->prepare('SELECT 1 FROM task_columns WHERE project_id = ? AND is_backlog = 1');
    $shift = $pdo->prepare('UPDATE task_columns SET position = position + 1 WHERE project_id = ?');
    $insert = $pdo->prepare(
        "INSERT INTO task_columns (project_id, name, color, position, is_done, is_backlog)
         VALUES (?, 'Backlog', '#8B7C68', 0, 0, 1)"
    );
    foreach ($projects as $pid) {
        $check->execute([(int)$pid]);
        if ($check->fetchColumn()) continue;
        $shift->execute([(int)$pid]);
        $insert->execute([(int)$pid]);
    }
};
