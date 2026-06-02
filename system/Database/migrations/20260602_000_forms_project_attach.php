<?php
declare(strict_types=1);

// Attach forms to a project + optional auto-create-task on submission.
//   project_id           — when set, the form is owned by that project's flow
//   auto_create_task     — boolean; when 1, every new submission spawns a task
//   task_title_template  — template string with {formName} / {field_key} placeholders
//                          (empty falls back to form.title + " #" + submission.id)
return function (\PDO $pdo) {
    $cols = $pdo->query('PRAGMA table_info(forms)')->fetchAll(\PDO::FETCH_ASSOC);
    $have = [];
    foreach ($cols as $c) { $have[$c['name']] = true; }
    if (!isset($have['project_id'])) {
        $pdo->exec('ALTER TABLE forms ADD COLUMN project_id INTEGER NULL');
    }
    if (!isset($have['auto_create_task'])) {
        $pdo->exec('ALTER TABLE forms ADD COLUMN auto_create_task INTEGER NOT NULL DEFAULT 0');
    }
    if (!isset($have['task_title_template'])) {
        $pdo->exec('ALTER TABLE forms ADD COLUMN task_title_template TEXT NULL');
    }
};
