<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $pdo->query("CREATE TABLE tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        column_id INTEGER NOT NULL REFERENCES task_columns(id),
        title TEXT NOT NULL,
        description TEXT,
        position REAL NOT NULL,
        assignee_id INTEGER REFERENCES users(id),
        due_date TEXT,
        created_by INTEGER NOT NULL REFERENCES users(id),
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
};
