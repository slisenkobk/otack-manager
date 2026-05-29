<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS task_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            linked_task_id INTEGER NOT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(task_id, linked_task_id)
        )"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_links_task ON task_links(task_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_links_linked ON task_links(linked_task_id)');
};
