<?php
declare(strict_types=1);

// Seeds the default admin user from SEED_DEFAULT_ADMIN_* env vars. When the
// env vars are unset, the migration is a no-op but is still recorded as
// applied — that's intentional, so subsequent boots don't keep probing.
return function (\PDO $pdo) {
    $email = \App\App::env('SEED_DEFAULT_ADMIN_EMAIL');
    if ($email === '') return;
    $hash  = \App\App::env('SEED_DEFAULT_ADMIN_PASSWORD_HASH');
    $name  = \App\App::env('SEED_DEFAULT_ADMIN_NAME', 'Admin');
    if ($hash === '') return;
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) return;
    $pdo->prepare(
        "INSERT INTO users (email, password_hash, name, role, status, created_at)
         VALUES (?, ?, ?, 'admin', 'approved', datetime('now'))"
    )->execute([$email, $hash, $name]);
};
