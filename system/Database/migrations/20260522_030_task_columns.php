<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $pdo->query("CREATE TABLE task_columns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        color TEXT NOT NULL DEFAULT '#8B7C68',
        position INTEGER NOT NULL,
        is_done INTEGER NOT NULL DEFAULT 0
    )");
};
