<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $cols = $pdo->query('PRAGMA table_info(projects)')->fetchAll(\PDO::FETCH_ASSOC);
    $has = false;
    foreach ($cols as $c) { if ($c['name'] === 'color') { $has = true; break; } }
    if (!$has) {
        $pdo->query("ALTER TABLE projects ADD COLUMN color TEXT NOT NULL DEFAULT '#1A1612'");
    }
};
