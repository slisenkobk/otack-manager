<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll(\PDO::FETCH_ASSOC);
    $has = false;
    foreach ($cols as $c) { if ($c['name'] === 'avatar') { $has = true; break; } }
    if (!$has) {
        $pdo->exec('ALTER TABLE users ADD COLUMN avatar TEXT');
    }
};
