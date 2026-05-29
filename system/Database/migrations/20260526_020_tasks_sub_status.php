<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $cols = $pdo->query('PRAGMA table_info(tasks)')->fetchAll(\PDO::FETCH_ASSOC);
    $has = false;
    foreach ($cols as $c) { if ($c['name'] === 'sub_status') { $has = true; break; } }
    if (!$has) {
        $pdo->query("ALTER TABLE tasks ADD COLUMN sub_status TEXT");
    }
};
