<?php
declare(strict_types=1);
namespace App\Database;

final class Connection
{
    public static function open(string $path): \PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        foreach (['PRAGMA foreign_keys = ON', 'PRAGMA journal_mode = WAL', 'PRAGMA busy_timeout = 5000'] as $p) {
            $pdo->query($p);
        }
        return $pdo;
    }
}
