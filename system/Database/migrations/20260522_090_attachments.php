<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $pdo->query("CREATE TABLE attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entity_type TEXT NOT NULL,
        entity_id INTEGER NOT NULL,
        filename TEXT NOT NULL,
        original_name TEXT NOT NULL,
        mime TEXT NOT NULL,
        size INTEGER NOT NULL,
        is_image INTEGER NOT NULL DEFAULT 0,
        uploaded_by INTEGER NOT NULL REFERENCES users(id),
        created_at TEXT NOT NULL
    )");
    $pdo->query("CREATE INDEX attachments_entity_idx ON attachments(entity_type, entity_id)");
};
