<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $cols = $pdo->query('PRAGMA table_info(projects)')->fetchAll(\PDO::FETCH_ASSOC);
    $has = false;
    foreach ($cols as $c) { if ($c['name'] === 'pinned_at') { $has = true; break; } }
    if (!$has) {
        $pdo->exec('ALTER TABLE projects ADD COLUMN pinned_at TEXT NULL');
    }
};
