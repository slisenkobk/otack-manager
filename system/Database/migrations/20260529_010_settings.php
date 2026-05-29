<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ""
        )'
    );
};
