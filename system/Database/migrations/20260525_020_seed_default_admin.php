<?php
declare(strict_types=1);

use App\Database\Schema\Schema;

// Seeds the default admin user from SEED_DEFAULT_ADMIN_* env vars. When the
// env vars are unset, the migration is a no-op but is still recorded as
// applied — that's intentional, so subsequent boots don't keep probing.
return function (Schema $schema): void {
    $email = \App\App::env('SEED_DEFAULT_ADMIN_EMAIL');
    if ($email === '') return;
    $hash  = \App\App::env('SEED_DEFAULT_ADMIN_PASSWORD_HASH');
    $name  = \App\App::env('SEED_DEFAULT_ADMIN_NAME', 'Admin');
    if ($hash === '') return;
    $pdo = $schema->pdo();
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) return;
    // Use a PHP-side ISO timestamp so the SQL is portable across drivers
    // (SQLite has datetime('now'), MySQL has NOW() — neither is portable).
    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s');
    $pdo->prepare(
        "INSERT INTO users (email, password_hash, name, role, status, created_at)
         VALUES (?, ?, ?, 'admin', 'approved', ?)"
    )->execute([$email, $hash, $name, $now]);
};
