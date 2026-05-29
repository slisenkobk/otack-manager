<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $cols = $pdo->query('PRAGMA table_info(task_columns)')->fetchAll(\PDO::FETCH_ASSOC);
    $has = false;
    foreach ($cols as $c) { if ($c['name'] === 'is_backlog') { $has = true; break; } }
    if (!$has) {
        $pdo->exec('ALTER TABLE task_columns ADD COLUMN is_backlog INTEGER NOT NULL DEFAULT 0');
    }
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
