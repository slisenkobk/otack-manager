<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS forms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hash TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            description TEXT,
            fields_json TEXT NOT NULL DEFAULT "[]",
            footer_json TEXT NOT NULL DEFAULT "{}",
            status TEXT NOT NULL DEFAULT "published",
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_forms_status ON forms(status)');
};
