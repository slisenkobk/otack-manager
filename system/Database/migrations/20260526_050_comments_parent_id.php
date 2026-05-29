<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $cols = $pdo->query('PRAGMA table_info(comments)')->fetchAll(\PDO::FETCH_ASSOC);
    $has = false;
    foreach ($cols as $c) { if ($c['name'] === 'parent_id') { $has = true; break; } }
    if (!$has) {
        $pdo->exec('ALTER TABLE comments ADD COLUMN parent_id INTEGER NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_parent ON comments(parent_id)');
    }
};
