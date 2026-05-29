<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS form_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            form_id INTEGER NOT NULL,
            data_json TEXT NOT NULL DEFAULT "{}",
            footer_json TEXT NOT NULL DEFAULT "{}",
            status TEXT NOT NULL DEFAULT "new",
            converted_task_id INTEGER NULL,
            converted_project_id INTEGER NULL,
            remote_ip TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_form ON form_submissions(form_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_status ON form_submissions(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_form_submissions_ip_time ON form_submissions(remote_ip, created_at)');
};
