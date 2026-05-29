<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $pdo->query("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        name TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'member',
        status TEXT NOT NULL DEFAULT 'pending',
        telegram_chat_id TEXT,
        created_at TEXT NOT NULL,
        last_login_at TEXT
    )");
};
