<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $pdo->query("CREATE TABLE notifications_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event TEXT NOT NULL,
        payload TEXT NOT NULL,
        ok INTEGER NOT NULL,
        error TEXT,
        sent_at TEXT NOT NULL
    )");
};
